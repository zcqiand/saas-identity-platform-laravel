<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\OauthAccessToken;
use App\Models\OauthClient;
use App\Models\OauthRefreshToken;
use App\Models\SysUser;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\IntegrationSupport;
use Tests\TestCase;

// M04.F03 OAuth 双 grant 集成测试（真库 saas_test；rails test/integration/oauth_flow_test.rb
// 全用例集镜像 + brief 边界钉）：code 一次性/重放 400、redirect 白名单精确相等或前缀+`?`
// （边界子路径不算）、refresh rotate 旧行 revoked + scope 继承 + tenantId 沿用、state 回传、
// 错误形状 {code,message} 400、authorize 手动 decode_bearer! 无 Bearer 401。
final class OauthFlowTest extends TestCase
{
    use DatabaseTransactions;
    use IntegrationSupport;

    private OauthClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        // 种子探针 fail-fast（只读）：测试链种子缺失即失败并指路，禁静默 skip（ADR-0020）。
        if (! SysUser::query()->where('username', self::DEV_USER)->exists()) {
            self::fail('saas_test 缺 alice 种子——先跑 saas-identity-platform-shared 的 seed-db.mjs');
        }
        $this->client = OauthClient::query()->where('client_id', self::DEV_CLIENT)->firstOrFail();
    }

    // rails 用例 1：authorize 发一次性 code（state 回传）→ 交换 200 → 重放 400 INVALID_GRANT
    #[Test]
    public function authorize_issues_a_one_time_code_bound_to_redirect_whitelist(): void
    {
        $token = $this->loginBody($this->login())['accessToken'];
        $response = $this->postJson('/api/v1/oauth/authorize', $this->authorizeParams($this->firstRedirect()), $this->authHeader((string) $token));
        self::assertSame(200, $response->status());
        $code = $this->body($response);
        self::assertStringStartsWith('ac_', (string) $code['code']);
        self::assertSame('xyz', $code['state']);

        // 交换
        $response = $this->exchange((string) $code['code']);
        self::assertSame(200, $response->status());
        $granted = $this->body($response);
        self::assertNotEmpty($granted['accessToken']);
        self::assertSame(self::DEV_CLIENT, $granted['clientId']);
        self::assertSame('Bearer', $granted['tokenType']);

        // 重放 = 一次性消费
        $response = $this->exchange((string) $code['code']);
        self::assertSame(400, $response->status());
        self::assertSame('INVALID_GRANT', $this->body($response)['code']);
    }

    // rails 用例 2：白名单外 redirect 400，INVALID_REDIRECT_URI 在 message（code 走 IAE 处理器的 BAD_REQUEST）
    #[Test]
    public function redirect_outside_whitelist_is_400(): void
    {
        $token = $this->loginBody($this->login())['accessToken'];
        $response = $this->postJson('/api/v1/oauth/authorize', $this->authorizeParams('http://evil.example/cb'), $this->authHeader((string) $token));

        self::assertSame(400, $response->status());
        self::assertStringContainsString('INVALID_REDIRECT_URI', (string) $response->getContent());
        // 错误形状 {code,message}（基座 IAE 映射）
        self::assertSame('BAD_REQUEST', $response->json('code'));
        self::assertNotEmpty($response->json('message'));
    }

    // brief 边界钉：白名单「精确相等 或 前缀+`?`」——子路径前缀不算（rails redirect_allowed? 语义）
    #[Test]
    public function redirect_prefix_with_query_is_allowed_but_subpath_is_not(): void
    {
        $token = $this->loginBody($this->login())['accessToken'];

        // 前缀 + ?query → 放行（白名单项本身也精确相等放行，用例 1 已钉）
        $response = $this->postJson(
            '/api/v1/oauth/authorize',
            $this->authorizeParams($this->firstRedirect().'?extra=1'),
            $this->authHeader((string) $token),
        );
        self::assertSame(200, $response->status());

        // 前缀 + 子路径（无 ?）→ 拒
        $response = $this->postJson(
            '/api/v1/oauth/authorize',
            $this->authorizeParams($this->firstRedirect().'/evil'),
            $this->authHeader((string) $token),
        );
        self::assertSame(400, $response->status());
        self::assertStringContainsString('INVALID_REDIRECT_URI', (string) $response->getContent());
    }

    // rails 用例 3：login 链 refresh rotate——旧 token 二次用 400，响应带 tenantId
    // （M96.F02.I28 四方比对口径：login persistTokenPair 落行带 tenantId，rotate 继承旧行）
    #[Test]
    public function refresh_rotation_revokes_the_old_token(): void
    {
        $loginBody = $this->body($this->login());
        $response = $this->postJson('/api/v1/oauth/token', [
            'grantType' => 'refresh_token',
            'clientId' => self::DEV_CLIENT,
            'refreshToken' => $loginBody['refreshToken'],
        ]);
        self::assertSame(200, $response->status());
        $rotated = $this->body($response);
        self::assertNotEmpty($rotated['tenantId'], 'refresh response missing tenantId');
        self::assertNotEmpty($rotated['accessToken']);

        $response = $this->postJson('/api/v1/oauth/token', [
            'grantType' => 'refresh_token',
            'clientId' => self::DEV_CLIENT,
            'refreshToken' => $loginBody['refreshToken'],
        ]);
        self::assertSame(400, $response->status());
        self::assertSame('INVALID_GRANT', $this->body($response)['code']);
    }

    // rails 用例 4（live M96.F02.I28 分叉根因钉）：exchange 落行的 refresh 行带 tenant_id，
    // rotate 继承旧行 → 响应必有 tenantId（login 链断言覆盖不到这条链）
    #[Test]
    public function refresh_of_exchange_issued_token_carries_tenant_id(): void
    {
        $token = $this->loginBody($this->login())['accessToken'];
        $response = $this->postJson('/api/v1/oauth/authorize', $this->authorizeParams($this->firstRedirect()), $this->authHeader((string) $token));
        $code = $this->body($response)['code'];

        $granted = $this->body($this->exchange((string) $code));
        $response = $this->postJson('/api/v1/oauth/token', [
            'grantType' => 'refresh_token',
            'clientId' => self::DEV_CLIENT,
            'refreshToken' => $granted['refreshToken'],
        ]);

        self::assertSame(200, $response->status());
        $rotated = $this->body($response);
        self::assertNotEmpty($rotated['tenantId'], 'exchange-issued refresh response missing tenantId');
    }

    // brief 钉：rotate 旧行标 revoked（DB 面）+ scope 继承 access token 行 + client_id 沿用旧行
    #[Test]
    public function refresh_rotate_marks_old_row_revoked_and_inherits_scope(): void
    {
        $token = $this->loginBody($this->login())['accessToken'];
        // authorize 带 scope → code 行落 scope → exchange 继承 → rotate 继承 access 行 scope
        $params = $this->authorizeParams($this->firstRedirect());
        $params['scope'] = 'read,write';
        $code = $this->body($this->postJson('/api/v1/oauth/authorize', $params, $this->authHeader((string) $token)))['code'];

        $granted = $this->body($this->exchange((string) $code));
        /** @var OauthRefreshToken $oldRow 旧 refresh 行（rotate 前） */
        $oldRow = OauthRefreshToken::query()->where('refresh_token', $granted['refreshToken'])->firstOrFail();
        self::assertSame(self::DEV_CLIENT, (string) $oldRow->client_id);

        $rotateResponse = $this->postJson('/api/v1/oauth/token', [
            'grantType' => 'refresh_token',
            'clientId' => self::DEV_CLIENT,
            'refreshToken' => $granted['refreshToken'],
        ]);
        self::assertSame(200, $rotateResponse->status());
        $rotated = $this->body($rotateResponse);
        self::assertSame('read write', $rotated['scope'], 'rotate 应继承 access token 行 scope（逗号 → 空格）');
        self::assertSame(self::DEV_CLIENT, $rotated['clientId'], 'rotate 新行 client_id 沿用旧行');
        self::assertNotSame($granted['refreshToken'], $rotated['refreshToken'], 'rotate 必须发新 refresh token');
        self::assertTrue((bool) $oldRow->refresh()->revoked, '旧行必须标 revoked');
        $newRow = OauthRefreshToken::query()->where('refresh_token', $rotated['refreshToken'])->firstOrFail();
        self::assertFalse((bool) $newRow->revoked);
        self::assertSame((string) $oldRow->tenant_id, (string) $newRow->tenant_id, '新行 tenantId 沿用旧行');
        // access token 行也落库（token_id at_ 前缀、access_token='n/a' 两表语义与 login 链同）
        $accessRow = OauthAccessToken::query()->find((string) $newRow->access_token_id);
        self::assertNotNull($accessRow);
        self::assertStringStartsWith('at_', (string) $accessRow->token_id);
        self::assertSame('n/a', (string) $accessRow->access_token);
        self::assertSame('read,write', (string) $accessRow->scope);
    }

    // 错误形状钉：UNSUPPORTED_GRANT_TYPE → 400 {code,message}（TokenError 链，非基座 IAE 形状）
    #[Test]
    public function unsupported_grant_type_is_400_token_error_shape(): void
    {
        $response = $this->postJson('/api/v1/oauth/token', [
            'grantType' => 'password',
            'clientId' => self::DEV_CLIENT,
        ]);

        self::assertSame(400, $response->status());
        self::assertSame('UNSUPPORTED_GRANT_TYPE', $response->json('code'));
        self::assertNotEmpty($response->json('message'));
    }

    // rails 用例 5：authorize 无 Bearer（或坏 token）→ 401（手动 decode_bearer!，无 TenantGuard 链）
    #[Test]
    public function authorize_without_bearer_or_with_bad_token_is_401(): void
    {
        $response = $this->postJson('/api/v1/oauth/authorize', $this->authorizeParams($this->firstRedirect()));
        self::assertSame(401, $response->status());

        $response = $this->postJson(
            '/api/v1/oauth/authorize',
            $this->authorizeParams($this->firstRedirect()),
            $this->authHeader('not-a-jwt'),
        );
        self::assertSame(401, $response->status());
        self::assertSame('INVALID_CREDENTIALS', $response->json('code'));
    }

    /**
     * rails #authorize_params 镜像（camelCase 键）。
     *
     * @return array<string, string>
     */
    private function authorizeParams(string $redirectUri): array
    {
        return [
            'clientId' => self::DEV_CLIENT,
            'redirectUri' => $redirectUri,
            'responseType' => 'code',
            'state' => 'xyz',
        ];
    }

    private function firstRedirect(): string
    {
        $uris = (string) $this->client->redirect_uris;

        return trim(explode(',', $uris)[0]);
    }

    /**
     * rails #exchange 镜像：authorization_code grant POST（token 端点匿名）。
     */
    private function exchange(string $code): TestResponse
    {
        return $this->postJson('/api/v1/oauth/token', [
            'grantType' => 'authorization_code',
            'clientId' => self::DEV_CLIENT,
            'code' => $code,
            'redirectUri' => $this->firstRedirect(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function body(TestResponse $response): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
