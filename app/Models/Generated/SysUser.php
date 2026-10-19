<?php

declare(strict_types=1);

// DB-First scaffold：sys_user（家族 DB-First 口径；schema SSOT = shared drizzle public.sys_user，
// 本仓禁 migrate/seed）。由 scripts/scaffold-models.mjs 从 saas_dev 真库 information_schema 反推生成——
// 禁手改，L4.codegen.idempotent strict 复跑字节比对会暴露手改。手写业务逻辑（常量 / 关系 / 领域方法）
// 写在 app/Models/SysUser.php extends Generated\SysUser。
//
// 字段含义见 saas-identity-platform-shared/src/db/schema.ts。

namespace App\Models\Generated;

use Illuminate\Database\Eloquent\Model;

/**
 * DB-First scaffold：sys_user。
 */
class SysUser extends Model
{
    protected $table = 'sys_user';

    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = [
        'id',
        'username',
        'password',
        'email',
        'mobile',
        'status',
        'created_at',
        'updated_at',
        'failed_attempts',
        'locked_until',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'id' => 'string',
        'username' => 'string',
        'password' => 'string',
        'email' => 'string',
        'mobile' => 'string',
        'status' => 'int',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'failed_attempts' => 'int',
        'locked_until' => 'datetime',
    ];
}
