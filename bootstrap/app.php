<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        then: function (): void {
            // 家族探活契约形状：GET /health → {"ok":true,"mode":"..."}（ktor Application.kt 同款）。
            // 经 then: 注册、不挂 web 中间件组——探活是基础设施，不走 session/cookie，无 APP_KEY 依赖。
            // 骨架只有这一个端点；业务 API 一律等 shared 契约生成物（suite-hard-rules §4）。
            Route::get('/health', fn (): JsonResponse => response()->json(['ok' => true, 'mode' => 'laravel']));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {})
    ->withExceptions(function (Exceptions $exceptions): void {})
    ->create();
