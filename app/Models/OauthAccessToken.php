<?php

declare(strict_types=1);

// OAuth access token 持久行（public.oauth_access_token）。镜像 rails app/models/oauth_access_token.rb。

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $token_id
 * @property string $access_token
 * @property string $client_id
 * @property string|null $user_id
 * @property string|null $tenant_id
 * @property string|null $scope
 * @property string $token_type
 * @property Carbon $expires_at
 * @property bool $revoked
 * @property Carbon $created_at
 */
class OauthAccessToken extends Generated\OauthAccessToken
{
    public function oauthRefreshTokens(): HasMany
    {
        return $this->hasMany(OauthRefreshToken::class, 'access_token_id');
    }
}
