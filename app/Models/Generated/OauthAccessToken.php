<?php

declare(strict_types=1);

// DB-First scaffold：oauth_access_token（家族 DB-First 口径；schema SSOT = shared drizzle public.oauth_access_token，
// 本仓禁 migrate/seed）。由 scripts/scaffold-models.mjs 从 saas_dev 真库 information_schema 反推生成——
// 禁手改，L4.codegen.idempotent strict 复跑字节比对会暴露手改。手写业务逻辑（常量 / 关系 / 领域方法）
// 写在 app/Models/OauthAccessToken.php extends Generated\OauthAccessToken。
//
// 字段含义见 saas-identity-platform-shared/src/db/schema.ts。

namespace App\Models\Generated;

use Illuminate\Database\Eloquent\Model;

/**
 * DB-First scaffold：oauth_access_token。
 */
class OauthAccessToken extends Model
{
    protected $table = 'oauth_access_token';

    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = [
        'id',
        'token_id',
        'access_token',
        'client_id',
        'user_id',
        'tenant_id',
        'scope',
        'token_type',
        'expires_at',
        'revoked',
        'created_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'id' => 'string',
        'token_id' => 'string',
        'access_token' => 'string',
        'client_id' => 'string',
        'user_id' => 'string',
        'tenant_id' => 'string',
        'scope' => 'string',
        'token_type' => 'string',
        'expires_at' => 'datetime',
        'revoked' => 'bool',
        'created_at' => 'datetime',
    ];
}
