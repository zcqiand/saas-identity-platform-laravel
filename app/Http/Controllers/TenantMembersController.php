<?php

namespace App\Http\Controllers;

use App\Http\Middleware\TenantGuard;
use Illuminate\Http\Request;

// tag: tenant-members（manifest 8 op）。壳阶段 501 占位——业务在后续任务按 tag 实装。
final class TenantMembersController extends Controller
{
    // 租户缝先行（rails tenant_members_controller before_action :verify_path_tenant! 镜像）：
    // 业务实装在 tenant-members 任务；本壳先保证 auth tag 集成链（AuthFlowTest tenant mismatch → 403）。
    public function listTenantUsers(Request $request): void
    {
        TenantGuard::verifyPathTenant($request);
        abort(501);
    }

    public function createTenantUser(): void
    {
        abort(501);
    }

    public function inviteTenantUser(): void
    {
        abort(501);
    }

    public function getTenantUser(): void
    {
        abort(501);
    }

    public function updateTenantUser(): void
    {
        abort(501);
    }

    public function changeTenantUserStatus(): void
    {
        abort(501);
    }

    public function assignTenantMemberRoles(): void
    {
        abort(501);
    }

    public function deleteTenantUser(): void
    {
        abort(501);
    }
}
