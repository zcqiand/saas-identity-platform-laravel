<?php

namespace App\Http\Controllers;

use App\Http\Middleware\TenantGuard;
use App\Models\SysRole;
use App\Models\SysRoleMenu;
use App\Models\TenantMemberRole;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

// tag: tenant-roles —— protected + TenantGuard（rails tenant_roles_controller.rb 全文镜像）。
// role.tenant_id != path tenantId -> 404（不泄露跨租户存在性）。
final class TenantRolesController extends Controller
{
    // clientId 参数被忽略（镜像）。rails render_paginated 裸 scope 无显式序；分页切页
    // 前必须显式 orderBy（家族分页纪律）——取中性确定性键 id 升序，不引入 rails 没有的语义序
    // @impl M00.F03.I01 (book anchor xr-know-012)
    public function listSysRoles(Request $request): JsonResponse
    {
        TenantGuard::verifyPathTenant($request);

        $query = SysRole::query()
            ->where('tenant_id', (string) $request->route('tenantId'))
            ->orderBy('id');

        return $this->renderPaginated($query, fn (SysRole $role): array => $this->roleDto($role));
    }

    // @impl M00.F03.I02 (book anchor xr-know-012)
    public function createSysRole(Request $request): JsonResponse
    {
        TenantGuard::verifyPathTenant($request);

        $body = [];
        foreach (['clientId', 'roleCode', 'roleName'] as $key) {
            $value = $request->input($key);
            if (! is_string($value) || trim($value) === '') {
                throw new InvalidArgumentException("{$key}: is required");
            }
            $body[$key] = $value;
        }

        $now = Date::now();
        $role = new SysRole([
            'id' => (string) Str::uuid(),
            'tenant_id' => (string) $request->route('tenantId'),
            'client_id' => $body['clientId'],
            'role_code' => $body['roleCode'],
            'role_name' => $body['roleName'],
            'description' => $request->input('description'),
            'is_preset' => $request->input('isPreset') === true || $request->input('isPreset') === 'true',
            'status' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $role->save();

        return $this->renderCamel($this->roleDto($role));
    }

    // @impl M00.F03.I03 (book anchor xr-know-012)
    public function getSysRole(Request $request): JsonResponse
    {
        TenantGuard::verifyPathTenant($request);

        return $this->renderCamel($this->roleDto($this->locateRole($request)));
    }

    // 镜像：只应用 roleName/description（status 字段可空不应用）。
    // rails update! 会 touch updated_at（timestamps=false 故显式写列）
    // @impl M00.F03.I04 (book anchor xr-know-012)
    public function updateSysRole(Request $request): JsonResponse
    {
        TenantGuard::verifyPathTenant($request);
        $role = $this->locateRole($request);

        $updates = [];
        foreach (['roleName' => 'role_name', 'description' => 'description'] as $input => $column) {
            $value = $request->input($input);
            if ($value !== null) {
                $updates[$column] = $value;
            }
        }
        if ($updates !== []) {
            $updates['updated_at'] = Date::now();
            SysRole::query()->where('id', (string) $role->id)->update($updates);
            $role->fill($updates);
        }

        return $this->renderCamel($this->roleDto($role));
    }

    // @impl M00.F03.I05 (book anchor xr-know-012)
    public function deleteSysRole(Request $request): Response
    {
        TenantGuard::verifyPathTenant($request);
        $role = $this->locateRole($request);

        SysRoleMenu::query()->where('role_id', (string) $role->id)->delete();
        TenantMemberRole::query()->where('role_id', (string) $role->id)->delete();
        $role->delete();

        return response()->noContent();
    }

    /**
     * rails #locate_role 镜像：查无或 tenant_id 不属本租户 → 404（不泄露跨租户存在性）。
     * 先走基座 UUID 甄别再查询（终审 I-1，findTenant 同款）：PG uuid 列直吃非法串会
     * 22P02 QueryException（500 分叉），rails AR cast 吞错 → find_by! 转 RecordNotFound
     * → 基座渲染映射 400 malformed uuid。3 个带 {roleId} 的 op（含 delete）全经此入口。
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

    /**
     * rails #role_dto 镜像（snake 键交渲染层转 lowerCamel；null 剔除在 Camel::deep）。
     *
     * @return array<string, mixed>
     */
    private function roleDto(SysRole $role): array
    {
        return [
            'id' => $role->id,
            'tenant_id' => $role->tenant_id,
            'client_id' => $role->client_id,
            'role_code' => $role->role_code,
            'role_name' => $role->role_name,
            'description' => $role->description,
            'is_preset' => $role->is_preset,
            'status' => $role->status,
            'created_at' => $role->created_at,
            'updated_at' => $role->updated_at,
        ];
    }
}
