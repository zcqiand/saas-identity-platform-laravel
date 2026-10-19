<?php

namespace App\Exceptions\Jwt;

use RuntimeException;

// JWT 验签/形状无效（rails JWT::DecodeError 同语义：坏段数/alg 混淆/坏签名/坏 payload）
// → 调用方统一 401 "invalid token: {message}"。
final class TokenInvalid extends RuntimeException {}
