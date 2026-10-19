<?php

declare(strict_types=1);

// DB-First scaffold：sys_role_menu（家族 DB-First 口径；schema SSOT = shared drizzle public.sys_role_menu，
// 本仓禁 migrate/seed）。由 scripts/scaffold-models.mjs 从 saas_dev 真库 information_schema 反推生成——
// 禁手改，L4.codegen.idempotent strict 复跑字节比对会暴露手改。手写业务逻辑（常量 / 关系 / 领域方法）
// 写在 app/Models/SysRoleMenu.php extends Generated\SysRoleMenu。
//
// 字段含义见 saas-identity-platform-shared/src/db/schema.ts。

namespace App\Models\Generated;

use Illuminate\Database\Eloquent\Model;

/**
 * DB-First scaffold：sys_role_menu。
 *
 * 复合主键：role_id + menu_id（两列联合主键）——单键 find() 禁用，见 $primaryKey 注释。
 */
class SysRoleMenu extends Model
{
    protected $table = 'sys_role_menu';

    public $incrementing = false;

    protected $keyType = 'string';

    // 复合主键：role_id + menu_id（两列联合主键，真源=information_schema）。
    // Eloquent 不支持原生复合主键——置 null 禁用单键 find() 语义，
    // 写操作一律 where 两列显式定位；手写子类禁止覆写回单键。
    protected $primaryKey = null;

    /** @var list<string> */
    protected $fillable = [
        'role_id',
        'menu_id',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'role_id' => 'string',
        'menu_id' => 'string',
    ];
}
