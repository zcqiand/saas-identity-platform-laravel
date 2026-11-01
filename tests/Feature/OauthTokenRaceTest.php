<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\OauthClient;
use App\Models\OauthCode;
use App\Models\OauthRefreshToken;
use App\Models\SysUser;
use App\Services\OAuth\TokenError;
use App\Services\OAuth\TokenService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\IntegrationSupport;
use Tests\TestCase;

// 5.123①② 竞态机制钉（rails test/integration/oauth_token_race_test.rb 同形镜像）：
// ① code claim 影响行数判定：并发输家（读到行删除前快照、行已被赢家物理删除 →
//   claim 删 0 行）必须 INVALID_GRANT 拒签且不签发新对；修前 $row->delete() 静默
//   删 0 → 照签 200（双花窗口）。读缝 findCodeRow 返回删除前快照 = 竞态窗口投影。
// ② rotate 必须原子：撤销旧行与签发新对同事务——签发段失败回滚恢复旧行，同一
//   refresh 可重试；修前 revoke 先行落库，签发段故障留「旧废新未落」半态。
final class OauthTokenRaceTest extends TestCase
{
    use DatabaseTransactions;
    use IntegrationSupport;

    private OauthClient $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = OauthClient::query()->where('client_id', self::DEV_CLIENT)->firstOrFail();
    }

    #[Test]
    public function code_claim_loser_gets_zero_rows_and_issues_nothing(): void
    {
        $this->fnId('M04.F03.I02');
        $userId = (string) SysUser::query()->where('username', self::DEV_USER)->firstOrFail()->id;
        $redirect = $this->firstRedirect();
        $stale = $this->seedCode('ac-race', $userId, $this->aliceTenantId(), $redirect);
        $pairsBefore = OauthRefreshToken::query()->count();

        // 并发赢家：物理删行（同事务内对输家的 claim 立即可见 → 删 0 行）
        OauthCode::query()->where('code', 'ac-race')->delete();

        // 输家：读命中删除前快照（竞态窗口投影）
        $loser = new class(['clientId' => self::DEV_CLIENT, 'grantType' => 'authorization_code', 'code' => 'ac-race', 'redirectUri' => $redirect]) extends TokenService
        {
            public ?OauthCode $stale = null;

            protected function findCodeRow(string $code): ?OauthCode
            {
                return $this->stale;
            }
        };
        $loser->stale = $stale;

        try {
            $loser->call();
            self::fail('claim 删 0 行的并发输家必须 INVALID_GRANT 拒签');
        } catch (TokenError $e) {
            self::assertSame('INVALID_GRANT', $e->errorCode());
        }
        self::assertSame($pairsBefore, OauthRefreshToken::query()->count(), '输家不得签发新 token 对');
    }

    #[Test]
    public function refresh_rotation_failure_restores_old_token(): void
    {
        $this->fnId('M04.F03.I02');
        $refreshToken = (string) $this->loginBody($this->login())['refreshToken'];
        $params = ['clientId' => self::DEV_CLIENT, 'grantType' => 'refresh_token', 'refreshToken' => $refreshToken];

        // 注错：签发段故障（真库真事务，仅 persistTokenPair 抛出）
        $failing = new class($params) extends TokenService
        {
            protected function persistTokenPair(string $clientId, string $userId, string $tenantId, ?string $scope): string
            {
                throw new TokenError('INVALID_GRANT', 'db fault mid-rotation');
            }
        };
        try {
            $failing->call();
            self::fail('签发段故障必须抛错');
        } catch (TokenError $e) {
            self::assertSame('INVALID_GRANT', $e->errorCode());
        }

        // 故障排除：同一 refresh 必须可重试（轮换段已回滚，旧行 revoked 已还原）
        $retry = (new TokenService($params))->call();
        self::assertArrayHasKey('refresh_token', $retry);
        self::assertNotSame($refreshToken, $retry['refresh_token'], '重试必须轮换出新 refresh token');
    }

    private function firstRedirect(): string
    {
        return trim(explode(',', (string) $this->client->redirect_uris)[0]);
    }

    private function seedCode(string $code, string $userId, string $tenantId, string $redirect): OauthCode
    {
        return OauthCode::query()->create([
            'id' => (string) Str::uuid(),
            'code' => $code,
            'client_id' => self::DEV_CLIENT,
            'user_id' => $userId,
            'tenant_id' => $tenantId,
            'redirect_uri' => $redirect,
            'scope' => 'read',
            'expires_at' => Date::now()->addMinutes(5),
            'created_at' => Date::now(),
        ]);
    }
}
