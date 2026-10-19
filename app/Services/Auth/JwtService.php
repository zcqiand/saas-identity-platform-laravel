<?php

namespace App\Services\Auth;

use App\Exceptions\Jwt\TokenExpired;
use App\Exceptions\Jwt\TokenInvalid;
use Illuminate\Support\Env;
use RuntimeException;

// 家族 JWT 验签（HS256）—— rails Auth::JwtVerifier 的 Laravel 镜像（M01.F04 链路基础件）。
// 零 composer 依赖：hash_hmac + base64url + json_encode 手写；同一 JWT_SIGNING_KEY，
// payload {sub, tenant_id, iat, exp}；TTL 走 env JWT_TTL_SECONDS。
// fail-fast：任一 env 缺失/非法，构造即 throw，禁默认兜底（suite-hard-rules §1）；
// 身份字段缺失必须 401/403，禁 demo 字面量兜底（ADR-0019 / suite-hard-rules §3）。
final class JwtService
{
    private const ALG = 'HS256';

    private string $signingKey;

    private int $ttl;

    public function __construct()
    {
        $key = Env::get('JWT_SIGNING_KEY');
        if (! is_string($key) || $key === '') {
            throw new RuntimeException('JWT_SIGNING_KEY 未设（fail-fast，禁 env 默认值兜底——suite-hard-rules §1）');
        }
        $ttl = Env::get('JWT_TTL_SECONDS');
        if (! is_string($ttl) || preg_match('/^\d+$/', $ttl) !== 1) {
            throw new RuntimeException('JWT_TTL_SECONDS 未设或非正整数（fail-fast，禁 env 默认值兜底——suite-hard-rules §1）');
        }
        $this->signingKey = $key;
        $this->ttl = (int) $ttl;
    }

    /**
     * 按家族 payload 形状签发 HS256 token（claims 含 sub/tenant_id，iat/exp 自动补）。
     *
     * @param  array<string, mixed>  $claims
     */
    public function encode(array $claims): string
    {
        $now = time();
        $payload = array_merge($claims, ['iat' => $now, 'exp' => $now + $this->ttl]);
        $head = $this->b64((string) json_encode(['alg' => self::ALG, 'typ' => 'JWT'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $body = $this->b64((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return $head.'.'.$body.'.'.$this->b64(hash_hmac('sha256', $head.'.'.$body, $this->signingKey, true));
    }

    /**
     * 验签 + exp 校验；header alg ≠ HS256 即拒（混淆攻击面）。无效抛 TokenExpired / TokenInvalid，
     * 由调用方（TenantGuard / decode_bearer!）统一转 401——禁 rescue 后吞异常。
     * 返回 payload（sub / tenant_id / ...）。
     *
     * @return array<string, mixed>
     */
    public function verify(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new TokenInvalid('wrong segment count');
        }
        [$head, $body, $sig] = $parts;

        $headerJson = $this->unb64($head);
        $header = is_string($headerJson) ? json_decode($headerJson, true) : null;
        if (! is_array($header) || ($header['alg'] ?? null) !== self::ALG) {
            throw new TokenInvalid('alg must be HS256');
        }

        $expected = hash_hmac('sha256', $head.'.'.$body, $this->signingKey, true);
        $actual = $this->unb64($sig);
        if (! is_string($actual) || ! hash_equals($expected, $actual)) {
            throw new TokenInvalid('signature mismatch');
        }

        $bodyJson = $this->unb64($body);
        $payload = is_string($bodyJson) ? json_decode($bodyJson, true) : null;
        if (! is_array($payload)) {
            throw new TokenInvalid('payload is not a JSON object');
        }
        if (! isset($payload['exp']) || ! is_int($payload['exp'])) {
            throw new TokenInvalid('missing exp claim');
        }
        if (time() >= $payload['exp']) {
            throw new TokenExpired('token expired');
        }

        return $payload;
    }

    private function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private function unb64(string $segment): string|false
    {
        $remainder = strlen($segment) % 4;
        if ($remainder > 0) {
            $segment .= str_repeat('=', 4 - $remainder);
        }

        return base64_decode(strtr($segment, '-_', '+/'), true);
    }
}
