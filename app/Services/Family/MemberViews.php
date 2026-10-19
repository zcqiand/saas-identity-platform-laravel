<?php

declare(strict_types=1);

namespace App\Services\Family;

use App\Models\TenantMember;
use App\Models\TenantMemberRole;

// 会员视图装配（rails app/services/family/member_views.rb MemberViewAssembler 镜像，
// springboot MemberViewAssembler 同族）。本任务先落 membership/roleIds（login.availableTenants
// 消费）；flat_user_view/nested_view 等随 tenant-members 任务按需补齐。
//
// roleIds 三处同源（members 视图 / login.availableTenants / me）：member_role join 原样，
// 不按 sys_role.tenant_id 过滤——赋权端点负责只收本租户 role。
final class MemberViews
{
    /** @return list<string> */
    public static function roleIds(string $memberId): array
    {
        return TenantMemberRole::query()
            ->where('member_id', $memberId)
            ->orderBy('role_id')
            ->pluck('role_id')
            ->all();
    }

    // TenantMembership：login.availableTenants / me.tenants / me.memberships 通用项
    // （渲染层 renderCamel 统一转 lowerCamel：id/userId/tenantId/roleIds/status/joinedAt）
    public static function membership(TenantMember $member): array
    {
        return [
            'id' => $member->id,
            'user_id' => $member->user_id,
            'tenant_id' => $member->tenant_id,
            'role_ids' => self::roleIds((string) $member->id),
            'status' => Dicts::memberStatus($member->status),
            'joined_at' => $member->created_at,
        ];
    }

    /**
     * 全量 memberships（whoami 用，含非 active；rails MemberViews.memberships_for 镜像）。
     *
     * @return list<array<string, mixed>>
     */
    public static function membershipsFor(string $userId): array
    {
        return TenantMember::query()
            ->where('user_id', $userId)
            ->orderBy('created_at')
            ->get()
            ->map(fn (TenantMember $member): array => self::membership($member))
            ->all();
    }
}
