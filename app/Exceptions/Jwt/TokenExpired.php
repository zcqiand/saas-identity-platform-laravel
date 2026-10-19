<?php

namespace App\Exceptions\Jwt;

use RuntimeException;

// JWT 过期（rails JWT::ExpiredSignature 同语义）→ 调用方统一 401 'token expired'。
final class TokenExpired extends RuntimeException {}
