<?php

namespace App\Http\Controllers;

use App\Http\Middleware\TenantGuard;
use App\Models\OauthClient;
use App\Models\TenantApplication;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

// tag: tenant-applications —— protected + TenantGuard（租户缝）。rails
// tenant_applications_controller.rb 全文镜像：4 op 逐 op verifyPathTenant。
final class TenantApplicationsController extends Controller
{
    // @impl M00.F05.I01 (book anchor xr-know-012)
    public function listTenantApplications(Request $request): JsonResponse
    {
        TenantGuard::verifyPathTenant($request);

        // rails render_paginated 裸 scope 无显式序；分页切页前显式 orderBy（家族分页纪律）——
        // 取中性确定性键 id 升序，不引入 rails 没有的语义序
        $query = TenantApplication::query()
            ->where('tenant_id', (string) $request->route('tenantId'))
            ->orderBy('id');

        return $this->renderPaginated($query, fn (TenantApplication $app): array => $this->appDto($app));
    }

    // 未知 clientId -> 404（先查 oauth_client，非盲插）；expireTime 落库（2026-10-03 镜像追平
    // springboot 01704a2）；dup 预检 → 400 clean message（不泄漏驱动诊断）
    // @impl M00.F05.I02 (book anchor xr-know-012)
    public function subscribeTenantApplication(Request $request): JsonResponse
    {
        TenantGuard::verifyPathTenant($request);

        $clientId = (string) $request->input('clientId');
        $this->ensureSubscribable($request, $clientId);

        $app = new TenantApplication([
            'id' => (string) Str::uuid(),
            'tenant_id' => (string) $request->route('tenantId'),
            'client_id' => $clientId,
            'status' => 1,
            'expire_time' => $this->castExpireTime($request->input('expireTime')),
            'created_at' => Date::now(),
        ]);
        $app->save();

        return $this->renderCamel($this->appDto($app));
    }

    // @impl M00.F05.I03 (book anchor xr-know-012)
    public function updateTenantApplication(Request $request): JsonResponse
    {
        TenantGuard::verifyPathTenant($request);
        $app = $this->findApp($request);

        $updates = [];
        $status = $request->input('status');
        if ($status !== null && ! (is_string($status) && trim($status) === '')) {
            $updates['status'] = (int) $status;
        }
        $expireTime = $request->input('expireTime');
        if ($expireTime !== null && ! (is_string($expireTime) && trim($expireTime) === '')) {
            $updates['expire_time'] = $this->castExpireTime($expireTime);
        }
        if ($updates !== []) {
            $app->fill($updates);
            $app->save();
        }

        return $this->renderCamel($this->appDto($app));
    }

    // 行不存在也 204（ifPresent 静默，镜像）。204 端点返回类型钉 Response 非 JsonResponse
    // （response()->noContent() 返回 Illuminate\Http\Response——Task 7 指纹 §4.2）
    // @impl M00.F05.I04 (book anchor xr-know-012)
    public function removeTenantApplication(Request $request): Response
    {
        TenantGuard::verifyPathTenant($request);

        TenantApplication::query()
            ->where('tenant_id', (string) $request->route('tenantId'))
            ->where('client_id', (string) $request->route('clientId'))
            ->delete();

        return response()->noContent();
    }

    // rails ensure_subscribable! 镜像（逐行同序）：未知 clientId → 404（find_by! 在前），
    // 空 clientId → 400、dup 预检 → 400 clean message。
    // 注：rails find_by! 先于 blank 检查——空 clientId 走 404 分支（镜像 quirk 忠实保留）。
    private function ensureSubscribable(Request $request, string $clientId): void
    {
        $client = OauthClient::query()->where('client_id', $clientId)->first();
        if ($client === null) {
            throw (new ModelNotFoundException)->setModel(OauthClient::class, [$clientId]);
        }
        if ($clientId === '') {
            throw new InvalidArgumentException('clientId: is required');
        }

        $exists = TenantApplication::query()
            ->where('tenant_id', (string) $request->route('tenantId'))
            ->where('client_id', $clientId)
            ->exists();
        if ($exists) {
            throw new InvalidArgumentException(
                "subscription already exists: tenant={$request->route('tenantId')} client={$clientId}"
            );
        }
    }

    // rails find_app 镜像：(tenant_id, client_id) 定位，查无 404（租户 scope 内不泄露他租户订阅）
    private function findApp(Request $request): TenantApplication
    {
        $app = TenantApplication::query()
            ->where('tenant_id', (string) $request->route('tenantId'))
            ->where('client_id', (string) $request->route('clientId'))
            ->first();
        if ($app === null) {
            throw (new ModelNotFoundException)->setModel(TenantApplication::class, [
                (string) $request->route('clientId'),
            ]);
        }

        return $app;
    }

    // M-2（Round 1 评审）：rails AR datetime cast 语义——垃圾串 cast → nil 落库（200），
    // 不得 Eloquent datetime cast 抛 InvalidFormatException 分叉。可解析 → Carbon，垃圾/空 → null
    private function castExpireTime(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Date::parse($value);
        } catch (\Throwable) {
            // Date facade 的 @method 签名不带 @throws（phpstan catch.neverThrown），
            // 放宽到 Throwable——parse 失败族（InvalidFormatException 等）全归 nil
            return null;
        }
    }

    // rails app_dto 镜像：expireTime null → 渲染层剔键
    private function appDto(TenantApplication $app): array
    {
        return [
            'id' => (string) $app->id,
            'tenant_id' => (string) $app->tenant_id,
            'client_id' => (string) $app->client_id,
            'status' => (int) $app->status,
            'expire_time' => $app->expire_time,
            'created_at' => $app->created_at,
        ];
    }
}
