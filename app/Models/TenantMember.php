<?php

declare(strict_types=1);

// 租户本地身份（public.tenant_member）。API 用 userId，内部按 (user_id, tenant_id) 解析。
// 镜像 rails app/models/tenant_member.rb。

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TenantMember extends Generated\TenantMember
{
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(SysUser::class, 'user_id');
    }

    public function tenantMemberRoles(): HasMany
    {
        return $this->hasMany(TenantMemberRole::class, 'member_id');
    }
}
