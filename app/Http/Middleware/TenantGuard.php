<?php

namespace App\Http\Middleware;

use App\Exceptions\AccessDenied;
use App\Exceptions\Jwt\TokenExpired;
use App\Exceptions\Jwt\TokenInvalid;
use App\Services\Auth\JwtService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// 租户缝守卫 —— rails TenantGuard concern 的 Laravel 镜像（springboot TenantGuard 同族）。
//
// 家族语义（suite-hard-rules §3）：
// - 保护路由挂本中间件（alias 'tenant.guard'，bootstrap/app.php 注册；Task 5-10 保护面
//   控制器一律挂，跳过 TenantGuard 禁止）
// - Authorization 缺失/非 Bearer → 401；验签失败/expired → 401（不吞异常，转结构化响应）
// - 验签后把身份合并进 request attributes 供租户过滤（见 handle 内注释）；业务身份字段
//   缺失必须 401/403，禁 demo 字面量兜底（ADR-0019）
final class TenantGuard
{
    public function __construct(private readonly JwtService $jwt) {}

    public function handle(Request $request, Closure $next): Response
    {
        $header = (string) $request->headers->get('Authorization', '');
        $token = str_starts_with($header, 'Bearer ') ? trim(substr($header, 7)) : $header;
        if ($token === '' || $token === $header) {
            return self::unauthorized('missing bearer token');
        }

        try {
            $payload = $this->jwt->verify($token);
        } catch (TokenExpired) {
            return self::unauthorized('token expired');
        } catch (TokenInvalid $e) {
            return self::unauthorized("invalid token: {$e->getMessage()}");
        }

        // 暴露缝（Task 5-10 消费约定）：控制器经
        //   $request->attributes->get('currentUserId')   ← JWT sub（sys_user.id）
        //   $request->attributes->get('currentTenantId') ← JWT tenant_id claim
        // 读取；查询必须用它过滤（where(tenant_id: currentTenantId)），禁全表泄露。
        $request->attributes->set('currentUserId', $payload['sub'] ?? null);
        $request->attributes->set('currentTenantId', $payload['tenant_id'] ?? null);

        return $next($request);
    }

    /**
     * 租户缝校验（rails verify_path_tenant! / springboot verifyPathTenant 镜像）：
     * path {tenantId} 与 JWT tenant_id claim 不等（或缺失）→ AccessDenied('tenant mismatch')
     * → 基座映射 403 FORBIDDEN（不泄露跨租户存在性）。
     * 前置：本中间件已过（attributes 已带 currentTenantId）。
     */
    public static function verifyPathTenant(Request $request): void
    {
        $pathTenantId = $request->route('tenantId');
        $currentTenantId = $request->attributes->get('currentTenantId');
        if (is_string($pathTenantId) && $pathTenantId !== '' && $pathTenantId === $currentTenantId) {
            return;
        }

        throw new AccessDenied('tenant mismatch');
    }

    private static function unauthorized(string $message): JsonResponse
    {
        return response()
            ->json(['code' => 'INVALID_CREDENTIALS', 'message' => $message], 401)
            ->header('WWW-Authenticate', 'Bearer realm="api"');
    }
}
