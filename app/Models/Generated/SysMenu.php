<?php

declare(strict_types=1);

// DB-First scaffold：sys_menu（家族 DB-First 口径；schema SSOT = shared drizzle public.sys_menu，
// 本仓禁 migrate/seed）。由 scripts/scaffold-models.mjs 从 saas_dev 真库 information_schema 反推生成——
// 禁手改，L4.codegen.idempotent strict 复跑字节比对会暴露手改。手写业务逻辑（常量 / 关系 / 领域方法）
// 写在 app/Models/SysMenu.php extends Generated\SysMenu。
//
// 字段含义见 saas-identity-platform-shared/src/db/schema.ts。

namespace App\Models\Generated;

use Illuminate\Database\Eloquent\Model;

/**
 * DB-First scaffold：sys_menu。
 */
class SysMenu extends Model
{
    protected $table = 'sys_menu';

    // Eloquent 默认 $timestamps=true 且不查列存在性——saas_dev 实测 7/12 表无 updated_at
    // （首写即 SQLSTATE 42703），12 表全量关自动时间戳（评审 I-1）。时间戳由业务代码
    // 显式写列（DB-First：created_at/updated_at 是普通列）。
    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = [
        'id',
        'client_id',
        'parent_id',
        'title',
        'type',
        'path',
        'component',
        'perms',
        'icon',
        'sort_order',
        'status',
        'created_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'id' => 'string',
        'client_id' => 'string',
        'parent_id' => 'string',
        'title' => 'string',
        'type' => 'int',
        'path' => 'string',
        'component' => 'string',
        'perms' => 'string',
        'icon' => 'string',
        'sort_order' => 'int',
        'status' => 'int',
        'created_at' => 'datetime',
    ];
}
