<?php

declare(strict_types=1);

// DB-First scaffold：tenant_member（家族 DB-First 口径；schema SSOT = shared drizzle public.tenant_member，
// 本仓禁 migrate/seed）。由 scripts/scaffold-models.mjs 从 saas_dev 真库 information_schema 反推生成——
// 禁手改，L4.codegen.idempotent strict 复跑字节比对会暴露手改。手写业务逻辑（常量 / 关系 / 领域方法）
// 写在 app/Models/TenantMember.php extends Generated\TenantMember。
//
// 字段含义见 saas-identity-platform-shared/src/db/schema.ts。

namespace App\Models\Generated;

use Illuminate\Database\Eloquent\Model;

/**
 * DB-First scaffold：tenant_member。
 */
class TenantMember extends Model
{
    protected $table = 'tenant_member';

    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = [
        'id',
        'tenant_id',
        'user_id',
        'member_name',
        'is_owner',
        'status',
        'created_at',
        'updated_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'id' => 'string',
        'tenant_id' => 'string',
        'user_id' => 'string',
        'member_name' => 'string',
        'is_owner' => 'bool',
        'status' => 'int',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
