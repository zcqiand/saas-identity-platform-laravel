<?php

declare(strict_types=1);

// DB-First scaffold：sys_role（家族 DB-First 口径；schema SSOT = shared drizzle public.sys_role，
// 本仓禁 migrate/seed）。由 scripts/scaffold-models.mjs 从 saas_dev 真库 information_schema 反推生成——
// 禁手改，L4.codegen.idempotent strict 复跑字节比对会暴露手改。手写业务逻辑（常量 / 关系 / 领域方法）
// 写在 app/Models/SysRole.php extends Generated\SysRole。
//
// 字段含义见 saas-identity-platform-shared/src/db/schema.ts。

namespace App\Models\Generated;

use Illuminate\Database\Eloquent\Model;

/**
 * DB-First scaffold：sys_role。
 */
class SysRole extends Model
{
    protected $table = 'sys_role';

    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = [
        'id',
        'tenant_id',
        'client_id',
        'role_code',
        'role_name',
        'description',
        'is_preset',
        'status',
        'created_at',
        'updated_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'id' => 'string',
        'tenant_id' => 'string',
        'client_id' => 'string',
        'role_code' => 'string',
        'role_name' => 'string',
        'description' => 'string',
        'is_preset' => 'bool',
        'status' => 'int',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
