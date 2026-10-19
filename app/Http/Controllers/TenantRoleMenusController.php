<?php

namespace App\Http\Controllers;

use App\Http\Middleware\TenantGuard;
use App\Models\SysRole;
use App\Models\SysRoleMenu;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

// tag: tenant-role-menus —— protected + TenantGuard（rails tenant_role_menus_controller.rb 镜像）。
// 聚合视图 RoleMenuGrant：{ roleId, tenantId, menuIds(升序), updatedAt(=sys_role.updated_at) }。
// clientId 参数被忽略（镜像）。
final class TenantRoleMenusController extends Controller
{
    // @impl M00.F04.I02 (book anchor xr-know-012)
    public function listSysRoleMenus(Request $request): JsonResponse
    {
        TenantGuard::verifyPathTenant($request);
        $role = $this->locateRole($request);

        return $this->renderCamel([
            'role_id' => (string) $role->id,
            'tenant_id' => (string) $request->route('tenantId'),
            'menu_ids' => SysRoleMenu::query()
                ->where('role_id', (string) $role->id)
                ->orderBy('menu_id')
                ->pluck('menu_id')
                ->all(),
            'updated_at' => $role->updated_at,
        ]);
    }

    // 全量替换（差量实现镜像）：锁 role 行 -> 删不在集合的 -> 插缺失（幂等）-> touch role。
    // 响应直接从请求集构造（去重排序），不回读。
    // 复合主键表禁 Model::create()（returning "id" 42703）——一律 DB::table()->insert()
    // @impl M00.F04.I03 (book anchor xr-know-012)
    public function setSysRoleMenus(Request $request): JsonResponse
    {
        TenantGuard::verifyPathTenant($request);
        $role = $this->locateRole($request);

        $raw = $request->input('menuIds');
        $requested = is_array($raw) ? array_values(array_unique(array_map(strval(...), $raw))) : [];
        sort($requested, SORT_STRING);

        $roleId = (string) $role->id;
        DB::transaction(function () use ($roleId, $requested): void {
            // rails SysRole.lock.find 镜像：行锁防并发替换交错
            SysRole::query()->where('id', $roleId)->lockForUpdate()->first();
            if ($requested === []) {
                SysRoleMenu::query()->where('role_id', $roleId)->delete();
            } else {
                SysRoleMenu::query()->where('role_id', $roleId)->whereNotIn('menu_id', $requested)->delete();
                foreach ($requested as $menuId) {
                    $exists = SysRoleMenu::query()->where('role_id', $roleId)->where('menu_id', $menuId)->exists();
                    if (! $exists) {
                        DB::table('sys_role_menu')->insert(['role_id' => $roleId, 'menu_id' => $menuId]);
                    }
                }
            }
            // rails locked.touch 镜像（timestamps=false：Model::touch() 是 no-op，显式写列）
            SysRole::query()->where('id', $roleId)->update(['updated_at' => Date::now()]);
        });

        $role->refresh();

        return $this->renderCamel([
            'role_id' => (string) $role->id,
            'tenant_id' => (string) $request->route('tenantId'),
            'menu_ids' => $requested,
            'updated_at' => $role->updated_at,
        ]);
    }

    // bulk 全删；roleId 不存在也 204（不预查，镜像）。格式非法 uuid 前置甄别（终审 I-1，
    // deleteTenant 同款）：rails 直接 delete_all，AR cast 吞错 → 0 行 204，PG uuid 列直查
    // 会 22P02 → 500 分叉；clear 无 404/400 面，甄别命中直接 204 不落查询
    // @impl M00.F04.I04 (book anchor xr-know-012)
    public function clearSysRoleMenus(Request $request): Response
    {
        TenantGuard::verifyPathTenant($request);

        if (self::badUuidParam($request) !== null) {
            return response()->noContent();
        }

        SysRoleMenu::query()->where('role_id', (string) $request->route('roleId'))->delete();

        return response()->noContent();
    }

    /**
     * rails #locate_role 镜像：查无或 tenant_id 不属本租户 → 404（不泄露跨租户存在性）。
     * 先走基座 UUID 甄别再查询（终审 I-1）：rails AR cast 吞错 → find_by! 转
     * RecordNotFound → 基座渲染映射 400 malformed uuid。
     */
    private function locateRole(Request $request): SysRole
    {
        $bad = self::badUuidParam($request);
        if ($bad !== null) {
            throw (new ModelNotFoundException)->setModel(SysRole::class, [$bad]);
        }

        $roleId = (string) $request->route('roleId');
        $role = SysRole::query()->where('id', $roleId)->first();
        if ($role === null || (string) $role->tenant_id !== (string) $request->route('tenantId')) {
            throw (new ModelNotFoundException)->setModel(SysRole::class, [$roleId]);
        }

        return $role;
    }
}
