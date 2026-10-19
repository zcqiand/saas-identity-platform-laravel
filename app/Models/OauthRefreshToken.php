<?php

declare(strict_types=1);

// OAuth refresh token（public.oauth_refresh_token）。镜像 rails app/models/oauth_refresh_token.rb。

namespace App\Models;

use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $refresh_token
 * @property string $access_token_id
 * @property string $client_id
 * @property string|null $user_id
 * @property string|null $tenant_id
 * @property Carbon $expires_at
 * @property bool $revoked
 * @property Carbon $created_at
 */
class OauthRefreshToken extends Generated\OauthRefreshToken {}
