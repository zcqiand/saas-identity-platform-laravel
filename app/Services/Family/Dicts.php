<?php

declare(strict_types=1);

namespace App\Services\Family;

// 家族字典：smallint 枚举 ↔ API 字符串（rails app/services/family/dicts.rb 镜像，
// 镜像 springboot 各 assembler 的映射分支）。本任务先落 memberStatus（login.availableTenants
// 消费）；其余字典随对应 tag 任务按需补齐。
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
}
