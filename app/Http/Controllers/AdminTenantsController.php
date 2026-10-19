<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

// tag: admin-tenants —— JWT 必需、无租户校验（admin 面 platform_admin 语义照 rails：
// include TenantGuard 无 verify_path_tenant——路径无租户缝，401 即守卫全貌）。
// :id 是行 UUID——全部 5 op（含 delete）逐条过基座 badUuidParam 前置甄别
// （T8 教训：delete 漏甄别 → PG uuid 列 22P02 → 500 分叉）。
final class AdminTenantsController extends Controller
{
    // @impl M00.F01.I01 (book anchor xr-know-012)
    public function listTenants(): JsonResponse
    {
        // rails render_paginated(Tenant.all) 裸 scope 无显式序；分页切页前显式 orderBy
        // （家族分页纪律）——中性确定性键 id 升序，不引入 rails 没有的语义序
        $query = Tenant::query()->orderBy('id');

        return $this->renderPaginated($query, fn (Tenant $tenant): array => $this->tenantDto($tenant));
    }

    // @impl M00.F01.I02 (book anchor xr-know-012)
    public function createTenant(Request $request): JsonResponse
    {
        $tenantKey = $request->input('tenantKey');
        if ($tenantKey === null || (is_string($tenantKey) && trim($tenantKey) === '')) {
            throw new InvalidArgumentException('tenantKey: is required');
        }
        $name = $request->input('name');
        if ($name === null || (is_string($name) && trim($name) === '')) {
            throw new InvalidArgumentException('name: is required');
        }

        $now = Date::now();
        $tenant = new Tenant([
            'id' => (string) Str::uuid(),
            'tenant_key' => $tenantKey,
            'name' => $name,
            'status' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $tenant->save();

        return $this->renderCamel($this->tenantDto($tenant));
    }

    // @impl M00.F01.I03 (book anchor xr-know-012)
    public function getTenant(Request $request): JsonResponse
    {
        return $this->renderCamel($this->tenantDto($this->findTenant($request)));
    }

    // status 枚举 active|suspended -> 写库 1|0；读侧 status 恒 'active' 是
    // springboot toDto 的忠实镜像（无 0 分支，suspended 不可见 —— 已知 quirk，勿修）
    // @impl M00.F01.I04 (book anchor xr-know-012)
    public function updateTenant(Request $request): JsonResponse
    {
        $tenant = $this->findTenant($request);

        $updates = [];
        $name = $request->input('name');
        if ($name !== null && ! (is_string($name) && trim($name) === '')) {
            $updates['name'] = $name;
        }
        $status = $request->input('status');
        if ($status !== null && ! (is_string($status) && trim($status) === '')) {
            $updates['status'] = $status === 'active' ? 1 : 0;
        }
        // rails update! 会 touch updated_at（timestamps=false 故显式写列）
        if ($updates !== []) {
            $updates['updated_at'] = Date::now();
            $tenant->fill($updates);
            $tenant->save();
        }

        return $this->renderCamel($this->tenantDto($tenant));
    }

    // 行不存在也 204（不预查，镜像）。格式非法 uuid 前置甄别（T8 I-1 同款）：rails AR cast
    // 吞错 → 0 行 204，PG uuid 列直查会 22P02 → 500 分叉；delete 无 404/400 面，
    // 甄别命中直接 204 不落查询（5 op 甄别 checklist 见 tests/Feature/AdminFlowTest.php）
    // @impl M00.F01.I05 (book anchor xr-know-012)
    public function deleteTenant(Request $request): Response
    {
        if (self::badUuidParam($request) !== null) {
            return response()->noContent();
        }

        Tenant::query()->where('id', (string) $request->route('id'))->delete();

        return response()->noContent();
    }

    // rails find_by!(id:) 镜像：查询前先走基座 UUID 甄别再查询——PG uuid 列直接吃非法串会
    // 22P02 QueryException（500 分叉），rails AR 类型 cast 先转 RecordNotFound，此处镜像该
    // 语义（甄别命中 → ModelNotFound → 基座渲染映射：格式非法 400 / 合法查无 404）
    private function findTenant(Request $request): Tenant
    {
        $bad = self::badUuidParam($request);
        if ($bad !== null) {
            throw (new ModelNotFoundException)->setModel(Tenant::class, [$bad]);
        }

        $tenantId = (string) $request->route('id');
        $tenant = Tenant::query()->where('id', $tenantId)->first();
        if ($tenant === null) {
            throw (new ModelNotFoundException)->setModel(Tenant::class, [$tenantId]);
        }

        return $tenant;
    }

    // rails tenant_dto 镜像：status 恒 'active' 是 springboot toDto 的忠实镜像（无 0 分支，
    // suspended 不可见——已知 quirk，勿修；写侧 active->1 其余->0 在 updateTenant）
    private function tenantDto(Tenant $tenant): array
    {
        return [
            'id' => (string) $tenant->id,
            'tenant_key' => (string) $tenant->tenant_key,
            'name' => $tenant->name,
            'status' => 'active',
            'created_at' => $tenant->created_at,
            'updated_at' => $tenant->updated_at,
        ];
    }
}
