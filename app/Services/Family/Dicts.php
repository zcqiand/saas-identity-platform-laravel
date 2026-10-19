<?php

declare(strict_types=1);

namespace App\Services\Family;

// 家族字典：smallint 枚举 ↔ API 字符串（rails app/services/family/dicts.rb 镜像，
// 镜像 springboot 各 assembler 的映射分支）。
final class Dicts
{
    // tenant_member.status：1=active 2=invited 3=suspended 其余/null=disabled
    public static function memberStatus(int|string|null $value): string
    {
        return match ((int) $value) {
            1 => 'active',
            2 => 'invited',
            3 => 'suspended',
            default => 'disabled',
        };
    }

    // 反向字典（rails member_status_code 镜像）：未知字符串 → 0（成员 list status 过滤/
    // status 端点消费；0 不匹配任何行）
    public static function memberStatusCode(string $value): int
    {
        return match ($value) {
            'active' => 1,
            'invited' => 2,
            'suspended' => 3,
            default => 0,
        };
    }

    // sys_user.status（invitations / members 视图）：无 suspended 档
    public static function userStatus(int|string|null $value): string
    {
        return match ((int) $value) {
            1 => 'active',
            2 => 'invited',
            default => 'disabled',
        };
    }

    // sys_menu.type：1=directory 2=menu 3=button，未知→menu（MenuTree 节点装配消费）
    public static function menuType(int|string|null $value): string
    {
        return match ((int) $value) {
            1 => 'directory',
            2 => 'menu',
            3 => 'button',
            default => 'menu',
        };
    }
}
