<?php

namespace App\Http\Controllers;

use App\Models\SysUser;
use App\Models\Tenant;
use App\Models\TenantMember;
use App\Services\Auth\JwtService;
use App\Services\Family\MemberViews;
use App\Services\Family\RefreshTokens;
use App\Services\Me\MenuTree;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;

// tag: me —— protected（JWT，tenant.guard）。userId 取 JWT sub；防御分支无身份回空集（rails
// me_controller.rb 全文镜像）。clientId 参数被忽略（镜像）。
final class MeController extends Controller
{
    // @impl M01.F01.I01 (book anchor xr-know-012)
    public function whoami(Request $request): JsonResponse
    {
        $userId = (string) $request->attributes->get('currentUserId');
        $user = SysUser::query()->where('id', $userId)->first();
        if ($user === null) {
            return $this->renderCamel(['memberships' => []]);
        }

        return $this->renderCamel([
            'id' => $user->id,
            'email' => $user->email,
            'memberships' => MemberViews::membershipsFor((string) $user->id),
            'current_tenant_id' => $this->resolvedTenantId($request, $userId),
        ]);
    }

    // @impl M01.F03.I01 (book anchor xr-know-012)
    public function listMyTenants(Request $request): JsonResponse
    {
        // clientId 参数被忽略（镜像）；active memberships 裸数组
        $members = TenantMember::query()
            ->where('user_id', (string) $request->attributes->get('currentUserId'))
            ->where('status', 1)
            ->orderBy('created_at')
            ->get()
            ->map(fn (TenantMember $member): array => MemberViews::membership($member))
            ->all();

        return $this->renderCamel($members);
    }

    // @impl M04.F04.I08 (book anchor xr-know-012)
    public function getMyMenus(Request $request): JsonResponse
    {
        return $this->renderCamel(
            (new MenuTree((string) $request->attributes->get('currentUserId')))->call()
        );
    }

    // @impl M01.F03.I02 (book anchor xr-know-012)
    // switch：tenant 查无 → 404；member 查无或 status=0（disabled）→ 404
    // （invited/suspended 可切，镜像 member.status.zero? 分支）。
    // 新 JWT 换 tenant_id；refresh "saas-rt-..." 不落库（镜像 JwtIssuer.generateRefreshToken）。
    public function switchTenant(Request $request): JsonResponse
    {
        $userId = (string) $request->attributes->get('currentUserId');
        $tenantId = (string) $request->route('tenantId');

        $tenant = Tenant::query()->where('id', $tenantId)->first();
        if ($tenant === null) {
            throw (new ModelNotFoundException)->setModel(Tenant::class, [$tenantId]);
        }

        $member = TenantMember::query()
            ->where('user_id', $userId)
            ->where('tenant_id', (string) $tenant->id)
            ->first();
        if ($member === null || (int) $member->status === 0) {
            throw (new ModelNotFoundException)->setModel(TenantMember::class, [$tenantId]);
        }

        $jwt = new JwtService;

        return $this->renderCamel([
            'access_token' => $jwt->encode(['sub' => $userId, 'tenant_id' => (string) $tenant->id]),
            'refresh_token' => RefreshTokens::ephemeral($userId),
            'expires_at' => Date::now()->addSeconds($jwt->ttl()),
            'tenant_id' => (string) $tenant->id,
        ]);
    }

    // rails #resolved_tenant_id 镜像：JWT tenant_id claim 优先；缺失回首个 membership 的
    // tenant_id（DB 解析，非字面量兜底——ADR-0019 口径不适用于此镜像分支）；查无 → null 被
    // 渲染层剔除（rails first&.tenant_id 同形）。
    private function resolvedTenantId(Request $request, string $userId): ?string
    {
        $claim = $request->attributes->get('currentTenantId');
        if (is_string($claim) && $claim !== '') {
            return $claim;
        }

        $member = TenantMember::query()->where('user_id', $userId)->orderBy('created_at')->first();

        return $member === null ? null : (string) $member->tenant_id;
    }
}
