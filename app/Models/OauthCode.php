<?php

declare(strict_types=1);

// OAuth 授权码（public.oauth_code）。一次性，过期作废。镜像 rails app/models/oauth_code.rb。

namespace App\Models;

use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $code
 * @property string $client_id
 * @property string $user_id
 * @property string $tenant_id
 * @property string|null $redirect_uri
 * @property string|null $scope
 * @property string|null $code_challenge
 * @property string|null $code_challenge_method
 * @property Carbon $expires_at
 * @property Carbon $created_at
 */
class OauthCode extends Generated\OauthCode {}
