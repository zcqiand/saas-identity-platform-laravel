<?php

namespace App\Http\Controllers;

use App\Models\OauthClient;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// tag: clients —— 匿名可读（/api/v1/clients/单段 在 SecurityConfig 白名单的镜像；
// 路由在 tenant.guard 组外，无鉴权）。只渲染白名单三键，不泄露 secret/redirect 等。
final class ClientsController extends Controller
{
    // @impl M04.F01.I06 (book anchor xr-know-012)
    public function getClient(Request $request): JsonResponse
    {
        $clientId = (string) $request->route('clientId');
        $client = OauthClient::query()->where('client_id', $clientId)->first();
        if ($client === null) {
            throw (new ModelNotFoundException)->setModel(OauthClient::class, [$clientId]);
        }

        return $this->renderCamel([
            'client_id' => (string) $client->client_id,
            'client_name' => (string) $client->client_name,
            'status' => (int) $client->status,
        ]);
    }
}
