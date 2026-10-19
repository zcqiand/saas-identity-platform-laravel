<?php

declare(strict_types=1);

namespace App\Services\OAuth;

use App\Models\OauthAccessToken;
use App\Models\OauthClient;
use App\Models\OauthCode;
use App\Models\OauthRefreshToken;
use App\Services\Auth\JwtService;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;

// OAuth token 双 grant（rails app/services/oauth/token_service.rb 全文镜像，springboot
// OauthController.token 同源，M04.F03.I02/I03）：
// - authorization_code：code 一次性消费（交换/过期/redirect 不匹配均删行，重放 400）；
//   code 绑 clientId；redirectUri 与 authorize 存库值逐字相等（secure compare）
// - refresh_token：revoked -> 400；rotate（旧行标 revoked，新行 client_id/tenantId 沿用
//   旧行，scope 继承 access token 行）
// - 错误走 TokenError -> controller 400 {code,message}（INVALID_CLIENT / INVALID_REQUEST /
//   INVALID_GRANT / UNSUPPORTED_GRANT_TYPE）
final class TokenService
{
    // params 走 camelCase 键（与 AuthorizeService 同款直读 controller params 形态）
    public function __construct(
        private readonly array $params,
    ) {}

    // @impl M04.F03.I02 (book anchor xr-know-012)
    public function call(): array
    {
        $clientId = $this->param('clientId');
        if (OauthClient::query()->where('client_id', $clientId)->first() === null) {
            throw new TokenError('INVALID_CLIENT', 'unknown clientId');
        }

        return match ($this->param('grantType')) {
            'authorization_code' => $this->exchangeCode(),
            'refresh_token' => $this->rotateRefresh(),
            default => throw new TokenError('UNSUPPORTED_GRANT_TYPE', 'unsupported grantType '.($this->param('grantType') ?? '')),
        };
    }

    private function exchangeCode(): array
    {
        $code = $this->param('code');
        if ($code === null || $code === '') {
            throw new TokenError('INVALID_REQUEST', 'code is required');
        }
        $redirectUri = $this->param('redirectUri');
        if ($redirectUri === null || $redirectUri === '') {
            throw new TokenError('INVALID_REQUEST', 'redirectUri is required');
        }

        $row = $this->consumeCodeRow($code, $redirectUri);

        return $this->issue((string) $row->client_id, (string) $row->user_id, (string) $row->tenant_id, $row->scope);
    }

    // code 一次性消费：过期/redirect 不匹配也删行（重放 400）
    private function consumeCodeRow(string $code, string $redirectUri): OauthCode
    {
        $row = OauthCode::query()->where('code', $code)->first();
        if ($row === null || (string) $row->client_id !== $this->param('clientId')) {
            throw new TokenError('INVALID_GRANT', 'code 不存在或已被使用');
        }

        if ($row->expires_at->lte(Date::now())) {
            $row->delete();
            throw new TokenError('INVALID_GRANT', 'expired code');
        }
        // ActiveSupport::SecurityUtils.secure_compare 镜像（时序安全逐字相等）
        if (! hash_equals((string) $row->redirect_uri, $redirectUri)) {
            $row->delete();
            throw new TokenError('INVALID_GRANT', 'redirectUri mismatch');
        }

        $row->delete();

        return $row;
    }

    // @impl M04.F03.I03 (book anchor xr-know-012)
    private function rotateRefresh(): array
    {
        $refreshToken = $this->param('refreshToken');
        if ($refreshToken === null || $refreshToken === '') {
            throw new TokenError('INVALID_REQUEST', 'refreshToken is required');
        }

        $row = OauthRefreshToken::query()->where('refresh_token', $refreshToken)->first();
        if ($row === null) {
            throw new TokenError('INVALID_GRANT', 'refresh token 不存在');
        }
        if ((bool) $row->revoked) {
            throw new TokenError('INVALID_GRANT', 'revoked refresh_token');
        }

        /** @var OauthAccessToken|null $access scope 继承 access token 行（查无 → null） */
        $access = OauthAccessToken::query()->find((string) $row->access_token_id);
        $row->fill(['revoked' => true])->save();

        return $this->issue((string) $row->client_id, (string) $row->user_id, (string) $row->tenant_id, $access?->scope);
    }

    // 新 refresh 落库（"rt_<uuid>" 30 天）+ HS256 access JWT（rails persist_token_pair 镜像；
    // 与 LoginService 同两表语义：access 行 access_token='n/a'、两行都带 tenantId）
    private function issue(string $clientId, string $userId, string $tenantId, ?string $scope): array
    {
        $refresh = $this->persistTokenPair($clientId, $userId, $tenantId, $scope);
        $jwt = new JwtService;

        return [
            'access_token' => $jwt->encode(['sub' => $userId, 'tenant_id' => $tenantId]),
            'refresh_token' => $refresh,
            'token_type' => 'Bearer',
            'expires_in' => $jwt->ttl(),
            // scope.to_s.split(',').join(' ') 镜像：null → ""、"read,write" → "read write"
            'scope' => implode(' ', explode(',', (string) $scope)),
            'user_id' => $userId,
            'client_id' => $clientId,
            'tenant_id' => $tenantId,
        ];
    }

    private function persistTokenPair(string $clientId, string $userId, string $tenantId, ?string $scope): string
    {
        $now = Date::now();
        $access = new OauthAccessToken([
            'id' => (string) Str::uuid(),
            'token_id' => 'at_'.Str::uuid(),
            'access_token' => 'n/a',
            'client_id' => $clientId,
            'user_id' => $userId,
            'tenant_id' => $tenantId,
            'scope' => $scope,
            'expires_at' => $now->copy()->addHour(),
            'revoked' => false,
            'created_at' => $now,
        ]);
        $access->save();

        $refresh = 'rt_'.Str::uuid();
        (new OauthRefreshToken([
            'id' => (string) Str::uuid(),
            'refresh_token' => $refresh,
            'access_token_id' => $access->id,
            'client_id' => $clientId,
            'user_id' => $userId,
            'tenant_id' => $tenantId,
            'expires_at' => $now->copy()->addDays(30),
            'revoked' => false,
            'created_at' => $now,
        ]))->save();

        return $refresh;
    }

    private function param(string $key): ?string
    {
        $value = $this->params[$key] ?? null;

        return is_scalar($value) ? (string) $value : null;
    }
}
