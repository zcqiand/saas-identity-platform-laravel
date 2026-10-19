<?php

declare(strict_types=1);

// DB-First scaffold：oauth_client（家族 DB-First 口径；schema SSOT = shared drizzle public.oauth_client，
// 本仓禁 migrate/seed）。由 scripts/scaffold-models.mjs 从 saas_dev 真库 information_schema 反推生成——
// 禁手改，L4.codegen.idempotent strict 复跑字节比对会暴露手改。手写业务逻辑（常量 / 关系 / 领域方法）
// 写在 app/Models/OauthClient.php extends Generated\OauthClient。
//
// 字段含义见 saas-identity-platform-shared/src/db/schema.ts。

namespace App\Models\Generated;

use Illuminate\Database\Eloquent\Model;

/**
 * DB-First scaffold：oauth_client。
 */
class OauthClient extends Model
{
    protected $table = 'oauth_client';

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
        'client_secret',
        'client_name',
        'grant_types',
        'redirect_uris',
        'scopes',
        'access_token_validity',
        'refresh_token_validity',
        'auto_approve',
        'status',
        'created_at',
        'updated_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'id' => 'string',
        'client_id' => 'string',
        'client_secret' => 'string',
        'client_name' => 'string',
        'grant_types' => 'string',
        'redirect_uris' => 'string',
        'scopes' => 'string',
        'access_token_validity' => 'int',
        'refresh_token_validity' => 'int',
        'auto_approve' => 'bool',
        'status' => 'int',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
