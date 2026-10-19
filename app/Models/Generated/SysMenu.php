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
