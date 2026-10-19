<?php

declare(strict_types=1);

namespace App\Services\OAuth;

use RuntimeException;

// rails Oauth::TokenService::TokenError 镜像（PHP 无嵌套类，独立文件同 namespace）：
// token 端点错误码走 body {code: INVALID_*}（springboot 直接 ResponseEntity 的镜像），
// OauthController rescue 成 400 {code,message}——不进基座映射表（controller 局部 rescue）。
final class TokenError extends RuntimeException
{
    public function __construct(
        private readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }
}
