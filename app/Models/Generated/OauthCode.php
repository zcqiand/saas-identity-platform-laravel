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
