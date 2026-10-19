<?php

declare(strict_types=1);

// 租户（public.tenant）。镜像 rails app/models/tenant.rb。

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_key
 * @property string $name
 * @property int $status
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Tenant extends Generated\Tenant
{
    /** status 枚举（rails Tenant 同源）：1=active / 0=disabled。 */
    public const STATUS_ACTIVE = 1;

    public const STATUS_DISABLED = 0;

    public function tenantMembers(): HasMany
    {
        return $this->hasMany(TenantMember::class, 'tenant_id');
    }

    public function tenantApplications(): HasMany
    {
        return $this->hasMany(TenantApplication::class, 'tenant_id');
    }

    public function sysRoles(): HasMany
    {
        return $this->hasMany(SysRole::class, 'tenant_id');
    }
}
