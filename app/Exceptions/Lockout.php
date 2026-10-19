<?php

namespace App\Exceptions;

use RuntimeException;

// Auth::LoginService::LockoutError 的 Laravel 镜像（M01.F04 失败锁定）：
// → 423 空响应体（rails head :locked / springboot body(null) 忠实镜像，空体即契约形状）。
final class Lockout extends RuntimeException {}
