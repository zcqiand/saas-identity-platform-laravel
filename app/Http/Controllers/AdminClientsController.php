<?php

namespace App\Http\Controllers;

use App\Models\OauthClient;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

// tag: admin-clients —— JWT 必需、无租户校验（admin 面 platform_admin 语义照 rails：
// include TenantGuard 无 verify_path_tenant——路径无 tenantId 段，无租户缝，401 即守卫全貌）。
// 寻址一律 oauth_client.client_id 业务字符串，非行 UUID（不进基座 uuid 甄别表）。
final class AdminClientsController extends Controller
{
    // @impl M04.F01.I01 (book anchor xr-know-012)
    public function listClients(): JsonResponse
    {
        // rails render_paginated(OauthClient.all) 裸 scope 无显式序；分页切页前显式 orderBy
        // （家族分页纪律）——中性确定性键 id 升序，不引入 rails 没有的语义序
        $query = OauthClient::query()->orderBy('id');

        return $this->renderPaginated($query, fn (OauthClient $client): array => $this->clientDto($client));
    }

    // 应用层默认：validity 缺省/<=0 -> 3600/86400（不是 DB DEFAULT 7200/2592000，镜像）；
    // 创建类端点 200 非 201（家族口径）
    // @impl M04.F01.I02 (book anchor xr-know-012)
    public function createClient(Request $request): JsonResponse
    {
        $attrs = $this->createAttrs($request);
        $now = Date::now();
        $client = new OauthClient([
            'id' => (string) Str::uuid(),
            'client_id' => $attrs['client_id'],
            'client_name' => $attrs['client_name'],
            'client_secret' => $attrs['client_secret'],
            'grant_types' => $attrs['grant_types'],
            'redirect_uris' => $attrs['redirect_uris'],
            'scopes' => $attrs['scopes'],
            'access_token_validity' => $this->positiveOr($attrs['access_token_validity'], 3600),
            'refresh_token_validity' => $this->positiveOr($attrs['refresh_token_validity'], 86400),
            'auto_approve' => $attrs['auto_approve'] === true || $attrs['auto_approve'] === 'true',
            'status' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        // 直插镜像（无 dup 预检）：uk 撞键 23505 / NOT NULL 23502 → 基座 constraint violation 400
        $client->save();

        return $this->renderCamel($this->clientDto($client));
    }

    // @impl M04.F01.I03 (book anchor xr-know-012)
    public function getClient(Request $request): JsonResponse
    {
        return $this->renderCamel($this->clientDto($this->findClient($request)));
    }

    // 镜像 springboot：只应用 clientName/redirectUris/scopes（其余字段忽略）
    // @impl M04.F01.I04 (book anchor xr-know-012)
    public function updateClient(Request $request): JsonResponse
    {
        $client = $this->findClient($request);

        $updates = [];
        foreach (['clientName' => 'client_name', 'redirectUris' => 'redirect_uris', 'scopes' => 'scopes'] as $key => $column) {
            $value = $request->input($key);
            if ($value !== null) {
                $updates[$column] = $value;
            }
        }
        // rails update! 会 touch updated_at（timestamps=false 故显式写列）
        if ($updates !== []) {
            $updates['updated_at'] = Date::now();
            $client->fill($updates);
            $client->save();
        }

        return $this->renderCamel($this->clientDto($client));
    }

    // body { status } 可空，null 则不变（镜像）；to_i 语义照 rails（垃圾串 → 0）
    // @impl M04.F02.I01 (book anchor xr-know-012)
    public function setClientStatus(Request $request): JsonResponse
    {
        $client = $this->findClient($request);

        $status = $request->input('status');
        if ($status !== null) {
            $client->status = (int) $status;
            // rails update! 会 touch updated_at（timestamps=false 故显式写列）
            $client->updated_at = Date::now();
            $client->save();
        }

        return $this->renderCamel($this->clientDto($client));
    }

    // 204 端点返回类型钉 Response 非 JsonResponse（response()->noContent() 返回
    // Illuminate\Http\Response——Task 7 指纹 §4.2）
    // @impl M04.F01.I05 (book anchor xr-know-012)
    public function deleteClient(Request $request): Response
    {
        $this->findClient($request)->delete();

        return response()->noContent();
    }

    // rails find_by!(client_id:) 镜像：clientId 是 varchar 业务串——uuid 形状非法的串也是
    // 普通查无 → 404（不进基座 uuid 甄别表，PG 22P02 不可达）
    private function findClient(Request $request): OauthClient
    {
        $clientId = (string) $request->route('clientId');
        $client = OauthClient::query()->where('client_id', $clientId)->first();
        if ($client === null) {
            throw (new ModelNotFoundException)->setModel(OauthClient::class, [$clientId]);
        }

        return $client;
    }

    // rails client_dto 镜像：scopes null → 渲染层剔键（Camel）
    private function clientDto(OauthClient $client): array
    {
        return [
            'id' => (string) $client->id,
            'client_id' => (string) $client->client_id,
            'client_name' => $client->client_name,
            'grant_types' => $client->grant_types,
            'redirect_uris' => $client->redirect_uris,
            'scopes' => $client->scopes,
            'access_token_validity' => (int) $client->access_token_validity,
            'refresh_token_validity' => (int) $client->refresh_token_validity,
            'auto_approve' => (bool) $client->auto_approve,
            'status' => (int) $client->status,
            'created_at' => $client->created_at,
            'updated_at' => $client->updated_at,
        ];
    }

    // rails create_attrs 镜像：permit 面键集（camelCase 入参 → snake 列）
    // @return array{client_id:mixed,client_name:mixed,client_secret:mixed,grant_types:mixed,redirect_uris:mixed,scopes:mixed,access_token_validity:mixed,refresh_token_validity:mixed,auto_approve:mixed}
    private function createAttrs(Request $request): array
    {
        return [
            'client_id' => $request->input('clientId'),
            'client_name' => $request->input('clientName'),
            'client_secret' => $request->input('clientSecret'),
            'grant_types' => $request->input('grantTypes'),
            'redirect_uris' => $request->input('redirectUris'),
            'scopes' => $request->input('scopes'),
            'access_token_validity' => $request->input('accessTokenValidity'),
            'refresh_token_validity' => $request->input('refreshTokenValidity'),
            'auto_approve' => $request->input('autoApprove'),
        ];
    }

    // rails positive_or 镜像：to_i 非正（缺省/垃圾串/<=0）→ default
    private function positiveOr(mixed $value, int $default): int
    {
        $v = (int) $value;

        return $v > 0 ? $v : $default;
    }
}
