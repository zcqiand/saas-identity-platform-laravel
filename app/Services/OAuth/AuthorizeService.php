<?php

declare(strict_types=1);

namespace App\Services\OAuth;

use App\Models\OauthClient;
use App\Models\OauthCode;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use InvalidArgumentException;

// OAuth authorize（rails app/services/oauth/authorize_service.rb 全文镜像，springboot
// OauthController.authorize 同源，M04.F03.I01）：
// - Bearer 由 controller 手动 decode 后传 payload（该链无 Jwt principal），无 tenant_id claim
//   在 decode_bearer! 即 401
// - responseType 必须 "code"；client 不存在 -> 400 INVALID_CLIENT（ArgumentError 链 → 基座）
// - redirect 白名单：精确相等 或 前缀+`?` 边界（子路径不算）
// - code = "ac_<uuid>"，5 分钟过期，落 oauth_code（含 user_id/tenant_id，双链 tenantId 沿用根）
final class AuthorizeService
{
    // params 走 camelCase 键（与 TokenService 同款直读 controller params 形态）
    public function __construct(
        private readonly array $params,
        private readonly array $payload,
    ) {}

    // @impl M04.F03.I01 (book anchor xr-know-012)
    public function call(): array
    {
        if ($this->param('responseType') !== 'code') {
            throw new InvalidArgumentException('responseType: must be "code"');
        }

        $clientId = $this->param('clientId');
        /** @var OauthClient|null $client */
        $client = OauthClient::query()->where('client_id', $clientId)->first();
        if ($client === null) {
            throw new InvalidArgumentException("INVALID_CLIENT: unknown clientId {$clientId}");
        }

        $redirectUri = $this->param('redirectUri');
        if (! $this->redirectAllowed($client, $redirectUri)) {
            throw new InvalidArgumentException('INVALID_REDIRECT_URI: '.($redirectUri ?? ''));
        }

        $code = 'ac_'.Str::uuid();
        (new OauthCode([
            'id' => (string) Str::uuid(),
            'code' => $code,
            'client_id' => $clientId,
            'user_id' => (string) $this->payload['sub'],
            'tenant_id' => (string) $this->payload['tenant_id'],
            'redirect_uri' => $redirectUri,
            'scope' => $this->param('scope'),
            'expires_at' => Date::now()->addMinutes(5),
            'created_at' => Date::now(),
        ]))->save();

        return ['code' => $code, 'state' => $this->param('state')];
    }

    private function redirectAllowed(OauthClient $client, ?string $redirectUri): bool
    {
        if ($redirectUri === null || $redirectUri === '') {
            return false;
        }

        foreach (array_map('trim', explode(',', (string) $client->redirect_uris)) as $allowed) {
            if ($allowed === '') {
                continue;
            }
            if ($redirectUri === $allowed) {
                return true;
            }
            // 前缀+`?` 边界（ruby @redirect_uri[allowed.length] == '?' 镜像）：子路径前缀不算
            if (str_starts_with($redirectUri, $allowed)
                && isset($redirectUri[strlen($allowed)])
                && $redirectUri[strlen($allowed)] === '?') {
                return true;
            }
        }

        return false;
    }

    // rails params 直读镜像：标量 → string（表单形态恒 string；JSON 标量容忍），
    // 数组/缺失 → null
    private function param(string $key): ?string
    {
        $value = $this->params[$key] ?? null;

        return is_scalar($value) ? (string) $value : null;
    }
}
