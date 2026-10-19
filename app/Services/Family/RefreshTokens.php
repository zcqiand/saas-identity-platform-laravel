<?php

declare(strict_types=1);

namespace App\Services\Family;

// switch 端点的临时 refresh 形状（rails app/services/family/refresh_tokens.rb 全文镜像，
// springboot JwtIssuer.generateRefreshToken 同族）。"saas-rt-{userId}-{ts-ms}-{rand}"；
// 不落库不持久化 refresh 行——与 login 的 rt_<uuid> 落库对（LoginService::persistTokenPair）分途。
final class RefreshTokens
{
    public static function ephemeral(string $userId): string
    {
        // rails SecureRandom.base64(24).tr('+/', '-_').delete('=') 镜像：32 字符 base64url
        $rand = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
        $tsMs = (int) floor(microtime(true) * 1000);

        return "saas-rt-{$userId}-{$tsMs}-{$rand}";
    }
}
