<?php

declare(strict_types=1);

// OAuth client = 应用本体（public.oauth_client）。寻址用 client_id 业务字符串，不是行 UUID。
// 镜像 rails app/models/oauth_client.rb。

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $client_id
 * @property string $client_secret
 * @property string $client_name
 * @property string $grant_types
 * @property string $redirect_uris
 * @property string|null $scopes
 * @property int $access_token_validity
 * @property int $refresh_token_validity
 * @property bool $auto_approve
 * @property int $status
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class OauthClient extends Generated\OauthClient
{
    public function tenantApplications(): HasMany
    {
        // fk 与本地键都走 client_id 业务串（rails foreign_key/primary_key 同款）
        return $this->hasMany(TenantApplication::class, 'client_id', 'client_id');
    }

    public function sysMenus(): HasMany
    {
        return $this->hasMany(SysMenu::class, 'client_id', 'client_id');
    }

    public function sysRoles(): HasMany
    {
        return $this->hasMany(SysRole::class, 'client_id', 'client_id');
    }
}
