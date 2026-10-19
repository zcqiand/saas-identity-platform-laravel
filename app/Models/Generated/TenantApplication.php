<?php

declare(strict_types=1);

// DB-First scaffold：tenant_application（家族 DB-First 口径；schema SSOT = shared drizzle public.tenant_application，
// 本仓禁 migrate/seed）。由 scripts/scaffold-models.mjs 从 saas_dev 真库 information_schema 反推生成——
// 禁手改，L4.codegen.idempotent strict 复跑字节比对会暴露手改。手写业务逻辑（常量 / 关系 / 领域方法）
// 写在 app/Models/TenantApplication.php extends Generated\TenantApplication。
//
// 字段含义见 saas-identity-platform-shared/src/db/schema.ts。

namespace App\Models\Generated;

use Illuminate\Database\Eloquent\Model;

/**
 * DB-First scaffold：tenant_application。
 */
class TenantApplication extends Model
{
    protected $table = 'tenant_application';

    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = [
        'id',
        'tenant_id',
        'client_id',
        'status',
        'expire_time',
        'created_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'id' => 'string',
        'tenant_id' => 'string',
        'client_id' => 'string',
        'status' => 'int',
        'expire_time' => 'datetime',
        'created_at' => 'datetime',
    ];
}
