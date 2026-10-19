<?php

namespace App\Exceptions;

use RuntimeException;

// ApplicationController::AccessDenied 的 Laravel 镜像（recon §2.1）：→ 403 FORBIDDEN。
// 典型来源：TenantGuard::verifyPathTenant 租户缝 / 登录链无 active membership。
final class AccessDenied extends RuntimeException {}
