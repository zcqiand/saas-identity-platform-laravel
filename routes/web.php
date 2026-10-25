<?php

// web 面 = 基础设施端点，不进契约面/功能树（与 /health 同模式）：业务 API 一律走
// routes/api.php（generated/api_manifest.json 1:1 镜像，suite-hard-rules §4）。
// Swagger 文档面（REQ-2026-002，ktor REQ-2026-003 镜像）：内容 = shared 契约生成物
// （public/api-docs/，gen-shared.sh 落盘）+ public/swagger-ui/5.34.0/ pinned 静态件
// （vendor 入库，npmmirror 钉版下载），零手写注解零漂移。根路径跳文档照
// springboot/aspnetcore/ktor「根路径跳 Swagger」家族先例（实测 / 原 404 无占用）。
// bump swagger-ui-dist 时同 commit 换 public/swagger-ui/<ver>/ 并改本注释、
// resources/views/docs/swagger.blade.php、ci.yml 探针三处版本引用。

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

Route::get('/', fn (): RedirectResponse => redirect('/api-docs'));

Route::view('/api-docs', 'docs.swagger');

Route::get('/api-docs/openapi.yaml', function (): SymfonyResponse {
    // shared 契约生成物原样透传（gen-shared.sh 落盘），禁手编——漂移在 regen 即同步
    $path = public_path('api-docs/openapi.yaml');
    abort_unless(is_file($path), 404);

    return response(file_get_contents($path), Response::HTTP_OK, ['Content-Type' => 'application/yaml']);
});
