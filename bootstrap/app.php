<?php

use App\Exceptions\AccessDenied;
use App\Exceptions\InvalidCredentials;
use App\Exceptions\Jwt\TokenExpired;
use App\Exceptions\Jwt\TokenInvalid;
use App\Exceptions\Lockout;
use App\Http\Controllers\Controller;
use App\Http\Middleware\TenantGuard;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        // 契约路径自带 /api/v1 前缀（manifest 1:1），apiPrefix 留空防 /api/api/v1 叠加。
        apiPrefix: '',
        web: __DIR__.'/../routes/web.php',
        then: function (): void {
            // 家族探活契约形状：GET /health → {"ok":true,"mode":"..."}（ktor Application.kt 同款）。
            // 经 then: 注册、不挂 web 中间件组——探活是基础设施，不走 session/cookie，无 APP_KEY 依赖。
            // /health 不进契约面；业务 API 一律走 routes/api.php（suite-hard-rules §4）。
            Route::get('/health', fn (): JsonResponse => response()->json(['ok' => true, 'mode' => 'laravel']));

            // Swagger 文档面（REQ-2026-002，ktor REQ-2026-003 镜像）：/ 302 → 壳页 →
            // shared 契约生成物（public/api-docs.yaml，gen-shared.sh 落盘——文件非目录，
            // 防遮蔽 /api-docs 路由）+ public/swagger-ui/5.34.0/ pinned 静态件（vendor
            // 入库，npmmirror 钉版下载），零手写注解零漂移。与 /health 同模式经 then:
            // 注册、不挂 web 组：web 组 StartSession 走 database driver，无 DB 面一律
            // 500（v0.2.0 首航红指纹：CI smoke 占位 env 连不上库/本地无 sessions 表，
            // 表现为 /health 200 而 /api-docs 500）——文档面是基础设施端点，不依赖
            // session/DB。不进契约面/功能树（与 /health 同模式）。
            // bump swagger-ui-dist 时同 commit 换 public/swagger-ui/<ver>/ 并改本注释、
            // resources/views/docs/swagger.blade.php、ci.yml 探针三处版本引用。
            Route::get('/', fn (): RedirectResponse => redirect('/api-docs'));
            Route::view('/api-docs', 'docs.swagger');
            Route::get('/api-docs/openapi.yaml', function (): Response {
                // shared 契约生成物原样透传（gen-shared.sh 落盘），禁手编——漂移在 regen 即同步
                $path = public_path('api-docs.yaml');
                abort_unless(is_file($path), 404);

                return response(file_get_contents($path), Response::HTTP_OK, ['Content-Type' => 'application/yaml']);
            });
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // 租户缝守卫（rails TenantGuard concern 镜像）：保护面控制器 Task 5-10 一律挂。
        $middleware->alias(['tenant.guard' => TenantGuard::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // 错误体 {code,message} 映射——rails application_controller rescue_from 表逐行镜像
        // （镜像 springboot GlobalExceptionHandler，ADR-0020 错误契约；recon §2.1）。
        // 映射表外异常返回 null 走框架默认处理（不吞）。
        $exceptions->render(function (Throwable $e, Request $request): ?Response {
            // 423 空响应体（rails head :locked / springboot body(null) 忠实镜像，空体即契约形状）
            if ($e instanceof Lockout) {
                return new Response('', 423);
            }

            // oauth 链 decode_bearer! 镜像：无/坏 Bearer 或验签失败 → 401 INVALID_CREDENTIALS
            if ($e instanceof TokenExpired) {
                return response()->json(['code' => 'INVALID_CREDENTIALS', 'message' => 'token expired'], 401);
            }
            if ($e instanceof TokenInvalid || $e instanceof InvalidCredentials) {
                return response()->json(
                    ['code' => 'INVALID_CREDENTIALS', 'message' => $e->getMessage() ?: 'invalid credentials'],
                    401
                );
            }

            if ($e instanceof AccessDenied) {
                return response()->json(['code' => 'FORBIDDEN', 'message' => $e->getMessage() ?: 'access denied'], 403);
            }

            // UUID 参数甄别（镜像 springboot UUID.fromString）：路径段声称 uuid 但格式非法
            // → 400（IAE 语义）；合法格式但查无 → 404。
            // Laravel 在 render callbacks 之前经 prepareException 把 ModelNotFoundException
            // 包成 NotFoundHttpException，故此处认 previous（裸 NotFoundHttpException=路由
            // 404，不属本映射，走框架默认）。
            if ($e instanceof NotFoundHttpException && $e->getPrevious() instanceof ModelNotFoundException) {
                $bad = Controller::badUuidParam($request);
                if ($bad !== null) {
                    return response()->json(['code' => 'BAD_REQUEST', 'message' => "malformed uuid: {$bad}"], 400);
                }

                return response()->json(
                    ['code' => 'NOT_FOUND', 'message' => $e->getMessage() ?: 'resource not found'],
                    404
                );
            }

            // ArgumentError → 400（分页参数校验等）
            if ($e instanceof InvalidArgumentException) {
                return response()->json(
                    ['code' => 'BAD_REQUEST', 'message' => $e->getMessage() ?: 'invalid argument'],
                    400
                );
            }

            // 完整性违反（Unique 23505 / NotNull 23502 / FK 23503 / 23000）→ 400 "constraint violation"
            // （rails RecordInvalid/InvalidForeignKey/NotNullViolation/RecordNotUnique 同组镜像；
            // 其余 QueryException 不吞，走框架默认 500）
            if ($e instanceof QueryException
                && preg_match('/SQLSTATE\[(23000|23502|23503|23505)\]/', $e->getMessage()) === 1) {
                return response()->json(
                    ['code' => 'BAD_REQUEST', 'message' => "constraint violation: {$e->getMessage()}"],
                    400
                );
            }

            // ParameterMissing / JSON.parse 失败镜像（HttpMessageNotReadable → 400）
            if ($e instanceof BadRequestHttpException) {
                return response()->json(['code' => 'BAD_REQUEST', 'message' => 'malformed request body'], 400);
            }

            return null;
        });
    })
    ->create();
