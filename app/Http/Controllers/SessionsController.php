<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Services\Auth\LoginService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

// tag: auth —— public（/auth/** permitAll 镜像），无 TenantGuard。
final class SessionsController extends Controller
{
    // @impl M01.F04.I01 (book anchor xr-know-012)
    public function login(LoginRequest $request): JsonResponse
    {
        $payload = (new LoginService(
            username: (string) $request->input('username'),
            password: (string) $request->input('password'),
            clientId: (string) $request->input('clientId'),
        ))->call();

        return $this->renderCamel($payload);
    }

    // 204 恒定，无任何 token 撤销副作用（springboot logout 镜像）
    // @impl M01.F04.I06 (book anchor xr-know-012)
    public function logout(): Response
    {
        return response()->noContent();
    }
}
