<?php

declare(strict_types=1);

// OAuth access token 持久行（public.oauth_access_token）。镜像 rails app/models/oauth_access_token.rb。

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class OauthAccessToken extends Generated\OauthAccessToken
{
    public function oauthRefreshTokens(): HasMany
    {
        return $this->hasMany(OauthRefreshToken::class, 'access_token_id');
    }
}
