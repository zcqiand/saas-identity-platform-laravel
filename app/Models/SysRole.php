<?php

declare(strict_types=1);

// 租户×client 作用域角色（public.sys_role）。镜像 rails app/models/sys_role.rb。

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class SysRole extends Generated\SysRole
{
    public function sysRoleMenus(): HasMany
    {
        return $this->hasMany(SysRoleMenu::class, 'role_id');
    }

    public function tenantMemberRoles(): HasMany
    {
        return $this->hasMany(TenantMemberRole::class, 'role_id');
    }
}
