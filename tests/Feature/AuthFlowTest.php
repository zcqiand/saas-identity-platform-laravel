<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\SysUser;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Env;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\IntegrationSupport;
use Tests\TestCase;

// M01.F04 登录语义集成测试（真库 saas_test；rails test/integration/auth_flow_test.rb 全用例集镜像）：
// family 响应形状、错密码/未知用户同 401 INVALID_CREDENTIALS、5 连错 423 空体、
// 未知 clientId 400、无 active membership 403、tenant mismatch 403、logout 204 + 保护路由 JWT 拦截。
final class AuthFlowTest extends TestCase
{
    use DatabaseTransactions;
    use IntegrationSupport;

    protected function setUp(): void
    {
        parent::setUp();

        // 种子探针 fail-fast（只读）：测试链种子缺失即失败并指路，禁静默 skip（ADR-0020）。
        if (! SysUser::query()->where('username', self::DEV_USER)->exists()) {
            self::fail('saas_test 缺 alice 种子——先跑 saas-identity-platform-shared 的 seed-db.mjs');
        }
    }

    #[Test]
    public function login_returns_family_response_shape(): void
    {
        $response = $this->login();
        $body = $this->loginBody($response);

        self::assertSame(200, $response->status());
        self::assertSame(self::DEV_USER, $body['user']['username']);
        self::assertSame('Bearer', $body['tokenType']);
        self::assertNotEmpty($body['accessToken']);
        self::assertNotEmpty($body['refreshToken']);
        self::assertSame((int) Env::get('JWT_TTL_SECONDS'), $body['expiresIn']);
        self::assertIsArray($body['availableTenants']);
        self::assertNotEmpty($body['availableTenants'][0]['tenantId']);
    }

    #[Test]
    public function wrong_password_and_unknown_user_share_401_semantics(): void
    {
        // 错密码与未知用户同 401 INVALID_CREDENTIALS（不泄露存在性）
        $response = $this->login(password: 'nope');
        self::assertSame(401, $response->status());
        self::assertSame('INVALID_CREDENTIALS', $this->loginBody($response)['code']);

        $response = $this->login(username: 'ghost');
        self::assertSame(401, $response->status());
        self::assertSame('INVALID_CREDENTIALS', $this->loginBody($response)['code']);
    }

    #[Test]
    public function unknown_client_id_is_400(): void
    {
        $response = $this->login(clientId: 'ghost-app');

        self::assertSame(400, $response->status());
    }

    #[Test]
    public function five_consecutive_failures_lock_the_account_with_423_empty_body(): void
    {
        SysUser::query()->create([
            'username' => 'lockme',
            'password' => 'plain:pw',
            'email' => 'lockme@x',
            'status' => 1,
            'failed_attempts' => 0,
        ]);
        $user = SysUser::query()->where('username', 'lockme')->firstOrFail();

        for ($i = 0; $i < 5; $i++) {
            $response = $this->login(username: 'lockme', password: 'wrong');
            self::assertSame(401, $response->status(), "failure {$i} should be 401");
        }

        // 第 6 次（密码正确）也被锁定挡下：423 空响应体（空体即契约形状）。
        // 空体是裸 Symfony Response——TestResponse::status() 会炸，走 getStatusCode()。
        $response = $this->login(username: 'lockme', password: 'pw');
        self::assertSame(423, $response->getStatusCode());
        self::assertSame('', $response->getContent());
        self::assertTrue($user->refresh()->locked_until->isFuture());
    }

    #[Test]
    public function logout_is_204_and_protected_routes_require_jwt(): void
    {
        $response = $this->postJson('/api/v1/auth/logout');
        self::assertSame(204, $response->status());

        $response = $this->getJson('/api/v1/me');
        self::assertSame(401, $response->status());
        self::assertSame('INVALID_CREDENTIALS', $response->json('code'));
    }

    #[Test]
    public function tenant_mismatch_on_tenant_scoped_route_is_403(): void
    {
        $body = $this->loginBody($this->login());

        /** @var Tenant $other alice 之外任一租户（种子 3 租户；缺则真库链已破） */
        $other = Tenant::query()->where('id', '!=', $this->aliceTenantId())->firstOrFail();
        $response = $this->getJson("/api/v1/tenants/{$other->id}/members", $this->authHeader((string) $body['accessToken']));

        self::assertSame(403, $response->status());
        self::assertSame('FORBIDDEN', $response->json('code'));
    }

    // 家族真源：springboot AuthController 无 active membership 时抛 AccessDeniedException → 403
    // （2026-09-29 review Critical：return nil 曾让 login 拿 200 + tenant_id: null）。
    #[Test]
    public function login_without_active_membership_is_403_not_200_with_null_tenant(): void
    {
        SysUser::query()->create([
            'username' => 'noMember',
            'password' => 'plain:pw',
            'email' => 'noMember@x',
            'status' => 1,
            'failed_attempts' => 0,
        ]);

        $response = $this->login(username: 'noMember', password: 'pw');

        self::assertSame(403, $response->status());
        self::assertSame('FORBIDDEN', $this->loginBody($response)['code']);
    }
}
