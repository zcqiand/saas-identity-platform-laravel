<?php

declare(strict_types=1);

// DB-First scaffold：oauth_code（家族 DB-First 口径；schema SSOT = shared drizzle public.oauth_code，
// 本仓禁 migrate/seed）。由 scripts/scaffold-models.mjs 从 saas_dev 真库 information_schema 反推生成——
// 禁手改，L4.codegen.idempotent strict 复跑字节比对会暴露手改。手写业务逻辑（常量 / 关系 / 领域方法）
// 写在 app/Models/OauthCode.php extends Generated\OauthCode。
//
// 字段含义见 saas-identity-platform-shared/src/db/schema.ts。

namespace App\Models\Generated;

use Illuminate\Database\Eloquent\Model;

/**
 * DB-First scaffold：oauth_code。
 */
class OauthCode extends Model
{
    protected $table = 'oauth_code';

    // Eloquent 默认 $timestamps=true 且不查列存在性——saas_dev 实测 7/12 表无 updated_at
    // （首写即 SQLSTATE 42703），12 表全量关自动时间戳（评审 I-1）。时间戳由业务代码
    // 显式写列（DB-First：created_at/updated_at 是普通列）。
    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = [
        'id',
        'code',
        'client_id',
        'user_id',
        'tenant_id',
        'redirect_uri',
        'scope',
        'code_challenge',
        'code_challenge_method',
        'expires_at',
        'created_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'id' => 'string',
        'code' => 'string',
        'client_id' => 'string',
        'user_id' => 'string',
        'tenant_id' => 'string',
        'redirect_uri' => 'string',
        'scope' => 'string',
        'code_challenge' => 'string',
        'code_challenge_method' => 'string',
        'expires_at' => 'datetime',
        'created_at' => 'datetime',
    ];
}
