<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Exceptions\AccessDenied;
use App\Exceptions\InvalidCredentials;
use App\Exceptions\Lockout;
use App\Models\OauthAccessToken;
use App\Models\OauthClient;
use App\Models\OauthRefreshToken;
use App\Models\SysUser;
use App\Models\Tenant;
use App\Models\TenantApplication;
use App\Models\TenantMember;
use App\Services\Family\MemberViews;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use InvalidArgumentException;

// 登录语义（rails app/services/auth/login_service.rb 全文镜像，springboot AuthController.login
// 同源，M01.F04）：
// - 未知用户与错密码同 401 INVALID_CREDENTIALS（不泄露存在性）
// - 密码校验：DB 值 "plain:{password}" 前缀直等，否则 BCrypt（SysUser::passwordMatches）
// - 连续失败 LOCKOUT_THRESHOLD 次 → locked_until = now + LOCKOUT_MINUTES → 423（空响应体）
// - tenantId = 首个 active(status=1) membership 且 tenant 本身 active，否则 403
// - availableTenants：active memberships；clientId 有订阅集则过滤到订阅内，订阅集空则不过滤
final class LoginService
{
    private string $refreshToken = '';

    public function __construct(
        private readonly string $username,
        private readonly string $password,
        private readonly string $clientId,
    ) {}

    // @impl M01.F04.I01 (book anchor xr-know-012)
    public function call(): array
    {
        $user = $this->verifyCredentials();
        $user->fill(['failed_attempts' => 0, 'locked_until' => null])->save();
        // 租户先解析（镜像 springboot AuthController.login 顺序：tenant check → persist 内验 clientId）
        $tenantId = $this->currentTenantId($user);

        if (! OauthClient::query()->where('client_id', $this->clientId)->exists()) {
            throw new InvalidArgumentException('unknown clientId');
        }

        $this->persistTokenPair($user, $tenantId);

        return $this->loginPayload($user, $tenantId);
    }

    // 423 空响应体由 App\Exceptions\Lockout 经基座映射（rails LockoutError / springboot body(null)）

    private function verifyCredentials(): SysUser
    {
        $user = SysUser::query()->where('username', $this->username)->first();
        if ($user === null) {
            throw new InvalidCredentials('invalid credentials');
        }

        $lockedUntil = $user->locked_until;
        if ($lockedUntil !== null && $lockedUntil->isFuture()) {
            throw new Lockout;
        }

        if (! $user->passwordMatches($this->password)) {
            $this->registerFailure($user);
            throw new InvalidCredentials('invalid credentials');
        }

        return $user;
    }

    private function loginPayload(SysUser $user, string $tenantId): array
    {
        $jwt = new JwtService;

        return [
            'user' => ['id' => $user->id, 'username' => $user->username, 'email' => $user->email],
            'user_id' => $user->id,
            'current_tenant_id' => $tenantId,
            'access_token' => $jwt->encode(['sub' => $user->id, 'tenant_id' => $tenantId]),
            'refresh_token' => $this->refreshToken,
            'token_type' => 'Bearer',
            'expires_in' => $jwt->ttl(),
            'client_id' => $this->clientId,
            'available_tenants' => $this->availableTenants($user),
        ];
    }

    // 失败锁定（rails 旧锚 M01.F04.I02 已废弃，锁定语义并入 I01——树内现行有效口径）
    private function registerFailure(SysUser $user): void
    {
        $attempts = (int) $user->failed_attempts + 1;
        $locked = $attempts >= SysUser::LOCKOUT_THRESHOLD;
        $user->fill([
            'failed_attempts' => $attempts,
            'locked_until' => $locked ? Date::now()->addMinutes(SysUser::LOCKOUT_MINUTES) : null,
        ])->save();
    }

    private function currentTenantId(SysUser $user): string
    {
        $member = TenantMember::query()
            ->where('user_id', $user->id)
            ->where('status', 1)
            ->orderBy('created_at')
            ->first();
        // 家族真源：无 active membership → 403（springboot AuthController AccessDeniedException），
        // 禁止 return null 兜底（runtime-identity-fallback 禁令 / ADR-0019）。
        if ($member === null) {
            throw new AccessDenied('no active tenant membership');
        }

        $tenant = Tenant::query()->where('id', $member->tenant_id)->first();
        if ($tenant === null || (int) $tenant->status !== 1) {
            throw new AccessDenied('no active tenant');
        }

        return (string) $member->tenant_id;
    }

    private function availableTenants(SysUser $user): array
    {
        $members = TenantMember::query()
            ->where('user_id', $user->id)
            ->where('status', 1);
        $subscribed = TenantApplication::query()
            ->where('client_id', $this->clientId)
            ->pluck('tenant_id')
            ->all();
        if ($subscribed !== []) {
            $members->whereIn('tenant_id', $subscribed);
        }

        return $members->orderBy('created_at')->get()
            ->map(fn (TenantMember $member): array => MemberViews::membership($member))
            ->all();
    }

    // 落库 token 对（镜像 springboot persistTokenPair）：access 行 access_token="n/a"，
    // refresh 行 "rt_<uuid>" 30 天，两行都带 tenantId（refresh rotate 继承 → oauth 响应
    // 必有 tenantId；漏写 = normalize 剔 nil 后四方比对分叉）。oauth Task 9 消费 token 表语义。
    private function persistTokenPair(SysUser $user, string $tenantId): void
    {
        $now = Date::now();
        try {
            $token = new OauthAccessToken([
                'id' => (string) Str::uuid(),
                'token_id' => 'at_'.Str::uuid(),
                'access_token' => 'n/a',
                'client_id' => $this->clientId,
                'user_id' => $user->id,
                'tenant_id' => $tenantId,
                'expires_at' => $now->copy()->addHour(),
                'revoked' => false,
                'created_at' => $now,
            ]);
            $token->save();

            $this->refreshToken = 'rt_'.Str::uuid();
            (new OauthRefreshToken([
                'id' => (string) Str::uuid(),
                'refresh_token' => $this->refreshToken,
                'access_token_id' => $token->id,
                'client_id' => $this->clientId,
                'user_id' => (string) $token->user_id,
                'tenant_id' => $tenantId,
                'expires_at' => $now->copy()->addDays(30),
                'revoked' => false,
                'created_at' => $now,
            ]))->save();
        } catch (QueryException $e) {
            // FK 违例（clientId 不在 oauth_client）→ ArgumentError 'unknown clientId'（rails
            // rescue ActiveRecord::InvalidForeignKey 镜像）；其余 QueryException 原样上抛
            if (preg_match('/SQLSTATE\[23503\]/', $e->getMessage()) === 1) {
                throw new InvalidArgumentException('unknown clientId');
            }

            throw $e;
        }
    }
}
