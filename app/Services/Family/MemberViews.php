<?php

declare(strict_types=1);

namespace App\Services\Family;

use App\Models\SysUser;
use App\Models\TenantMember;
use App\Models\TenantMemberRole;

// 会员视图装配（rails app/services/family/member_views.rb MemberViewAssembler 镜像，
// springboot MemberViewAssembler 同族）。
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

    // 扁平成员视图（TenantMemberUserView）：path {userId} = sys_user.id，视图 status 读 member 行
    // （rails flat_user_view 镜像；null 剔除在渲染层 Camel::deep 落地）
    public static function flatUserView(TenantMember $member, SysUser $user): array
    {
        return [
            'id' => $user->id,
            'tenant_id' => $member->tenant_id,
            'username' => $user->username,
            'email' => $user->email,
            'status' => Dicts::memberStatus($member->status),
            'role_ids' => self::roleIds((string) $member->id),
            'created_at' => $member->created_at,
            'updated_at' => $member->updated_at,
        ];
    }

    // 嵌套视图（invitations）：{ member, user, roles }（rails nested_view 镜像）
    public static function nestedView(TenantMember $member, SysUser $user): array
    {
        return [
            'member' => self::memberPart($member),
            'user' => self::userPart($user),
            'roles' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function memberPart(TenantMember $member): array
    {
        return [
            'id' => $member->id,
            'tenant_id' => $member->tenant_id,
            'user_id' => $member->user_id,
            'member_name' => $member->member_name,
            'is_owner' => $member->is_owner,
            'status' => Dicts::memberStatus($member->status),
            'created_at' => $member->created_at,
            'updated_at' => $member->updated_at,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function userPart(SysUser $user): array
    {
        return [
            'id' => $user->id,
            'username' => $user->username,
            'email' => $user->email,
            'mobile' => $user->mobile,
            'status' => Dicts::userStatus($user->status),
            'failed_attempts' => $user->failed_attempts,
            'locked_until' => $user->locked_until,
            'created_at' => $user->created_at,
            'updated_at' => $user->updated_at,
        ];
    }
}
