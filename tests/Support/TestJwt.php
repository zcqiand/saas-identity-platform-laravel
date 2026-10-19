<?php

namespace Tests\Support;

use Illuminate\Support\Env;

// 测试链 JWT 手工签发助手：JwtService 只产好 token，坏形状（坏签名/alg 混淆/过期）
// 由本助手手工构造。与 app/Services/Auth/JwtService 的 wire 形状一致
// （header.payload.signature，base64url 无 padding）。
final class TestJwt
{
    /**
     * 手工签 HS256（或其他 alg 供混淆用例）。
     *
     * @param  array<string, mixed>  $payload
     */
    public static function sign(array $payload, ?string $key = null, string $alg = 'HS256'): string
    {
        $key ??= (string) Env::get('JWT_SIGNING_KEY');
        $head = self::b64((string) json_encode(['alg' => $alg, 'typ' => 'JWT'], JSON_UNESCAPED_SLASHES));
        $body = self::b64((string) json_encode($payload, JSON_UNESCAPED_SLASHES));
        $sig = self::b64(hash_hmac('sha256', $head.'.'.$body, $key, true));

        return $head.'.'.$body.'.'.$sig;
    }

    // 家族 payload 形状：{sub, tenant_id, iat, exp}；过期 1 小时前签发。
    public static function expired(): string
    {
        return self::sign(['sub' => 'user-1', 'tenant_id' => 'tenant-1', 'iat' => time() - 7200, 'exp' => time() - 3600]);
    }

    // alg=none 混淆形状：header 声明 none、签名段为空串（三段保持）。
    public static function algNone(): string
    {
        $head = self::b64((string) json_encode(['alg' => 'none', 'typ' => 'JWT'], JSON_UNESCAPED_SLASHES));
        $body = self::b64((string) json_encode(['sub' => 'user-1', 'tenant_id' => 'tenant-1'], JSON_UNESCAPED_SLASHES));

        return $head.'.'.$body.'.';
    }

    public static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
