<?php

declare(strict_types=1);

// 租户应用订阅（public.tenant_application）。镜像 rails app/models/tenant_application.rb。

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantApplication extends Generated\TenantApplication
{
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }
}
