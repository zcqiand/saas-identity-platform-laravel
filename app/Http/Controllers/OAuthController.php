<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\OAuth\AuthorizeService;
use App\Services\OAuth\TokenError;
use App\Services\OAuth\TokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// tag: oauth（manifest 2 op，operationId 前缀 OAuth）——rails OauthController 全文镜像：
// SecurityConfig 独立 permitAll 链：本 controller 不挂 TenantGuard 中间件（public 链），
// authorize 链内手动 decode_bearer!（基座 Controller），token 端点匿名；
// TokenError → 400 {code,message}（controller 局部 rescue，rails rescue_from 镜像）。
final class OAuthController extends Controller
{
    public function authorize(Request $request): JsonResponse
    {
        try {
            $payload = $this->decodeBearer($request);
            $result = (new AuthorizeService($request->input(), $payload))->call();
        } catch (TokenError $e) {
            return $this->renderError(400, $e->errorCode(), $e->getMessage());
        }

        return $this->renderCamel($result);
    }

    public function token(Request $request): JsonResponse
    {
        try {
            $result = (new TokenService($request->input()))->call();
        } catch (TokenError $e) {
            return $this->renderError(400, $e->errorCode(), $e->getMessage());
        }

        return $this->renderCamel($result);
    }
}
