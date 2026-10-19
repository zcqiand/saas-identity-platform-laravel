<?php

namespace App\Services\Auth;

use App\Exceptions\Jwt\TokenExpired;
use App\Exceptions\Jwt\TokenInvalid;
use Illuminate\Support\Env;
use RuntimeException;

// 家族 JWT 验签（HS256）—— rails Auth::JwtVerifier 的 Laravel 镜像（M01.F04 链路基础件）。
// 零 composer 依赖：hash_hmac + base64url + json_encode 手写；同一 JWT_SIGNING_KEY /
// JWT_ISSUER / JWT_AUDIENCE，payload {sub, tenant_id, iss, aud, iat, exp}；TTL 走 env
// JWT_TTL_SECONDS（终审 I-2：iss/aud 接入，verify 开 verify_iss/verify_aud 同语义）。
// fail-fast：任一 env 缺失/非法，构造即 throw，禁默认兜底（suite-hard-rules §1）；
// 身份字段缺失必须 401/403，禁 demo 字面量兜底（ADR-0019 / suite-hard-rules §3）。
final class JwtService
{
    private const ALG = 'HS256';

    private string $signingKey;

    private string $issuer;

    private string $audience;

    private int $ttl;

    public function __construct()
    {
        $key = Env::get('JWT_SIGNING_KEY');
        if (! is_string($key) || $key === '') {
            throw new RuntimeException('JWT_SIGNING_KEY 未设（fail-fast，禁 env 默认值兜底——suite-hard-rules §1）');
        }
        $issuer = Env::get('JWT_ISSUER');
        if (! is_string($issuer) || $issuer === '') {
            throw new RuntimeException('JWT_ISSUER 未设（fail-fast，禁 env 默认值兜底——suite-hard-rules §1）');
        }
        $audience = Env::get('JWT_AUDIENCE');
        if (! is_string($audience) || $audience === '') {
            throw new RuntimeException('JWT_AUDIENCE 未设（fail-fast，禁 env 默认值兜底——suite-hard-rules §1）');
        }
        $ttl = Env::get('JWT_TTL_SECONDS');
        // D 修饰符与 UUID_RE 同族（round 1 M-2）：禁 $ 匹配尾随换行，"3600\n" 不放行。
        if (! is_string($ttl) || preg_match('/^\d+$/D', $ttl) !== 1) {
            throw new RuntimeException('JWT_TTL_SECONDS 未设或非正整数（fail-fast，禁 env 默认值兜底——suite-hard-rules §1）');
        }
        $this->signingKey = $key;
        $this->issuer = $issuer;
        $this->audience = $audience;
        $this->ttl = (int) $ttl;
    }

    // TTL 读取（login payload expires_in 用；rails ENV.fetch('JWT_TTL_SECONDS').to_i 同源，
    // 构造期已 fail-fast 校验，取值即合法）
    public function ttl(): int
    {
        return $this->ttl;
    }

    /**
     * 按家族 payload 形状签发 HS256 token（claims 含 sub/tenant_id，iat/exp/iss/aud 自动补
     * ——rails Auth::JwtVerifier.encode 镜像：iss/aud 恒取 env 家族值，不吃调用方覆盖）。
     *
     * @param  array<string, mixed>  $claims
     */
    public function encode(array $claims): string
    {
        $now = time();
        $payload = array_merge($claims, [
            'iat' => $now,
            'exp' => $now + $this->ttl,
            'iss' => $this->issuer,
            'aud' => $this->audience,
        ]);
        $head = $this->b64((string) json_encode(['alg' => self::ALG, 'typ' => 'JWT'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $body = $this->b64((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return $head.'.'.$body.'.'.$this->b64(hash_hmac('sha256', $head.'.'.$body, $this->signingKey, true));
    }

    /**
     * 验签 + 标准校验（exp/iss/aud，rails JWT.decode verify_expiration/verify_iss/
     * verify_aud 镜像）；header alg ≠ HS256 即拒（混淆攻击面）。无效抛 TokenExpired /
     * TokenInvalid，由调用方（TenantGuard / decode_bearer!）统一转 401——禁 rescue 后吞异常。
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

        // verify_iss 镜像：iss 严格相等（ruby-jwt InvalidIssuerError 同语义）。
        if (($payload['iss'] ?? null) !== $this->issuer) {
            throw new TokenInvalid('invalid issuer');
        }

        // verify_aud 镜像：aud string 严格相等，或 array 含期望值任一（ruby-jwt
        // Array wrap 语义；缺失/空集/不含 → InvalidAudError 同形）。
        if (! $this->audienceMatches($payload['aud'] ?? null)) {
            throw new TokenInvalid('invalid audience');
        }

        return $payload;
    }

    private function audienceMatches(mixed $aud): bool
    {
        if (is_string($aud)) {
            return $aud === $this->audience;
        }
        if (is_array($aud)) {
            return in_array($this->audience, $aud, true);
        }

        return false;
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
