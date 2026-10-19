<?php

declare(strict_types=1);

// 全局自然人用户（DB-First：schema SSOT = shared drizzle public.sys_user，禁 migrate）。
// 镜像 rails app/models/sys_user.rb。

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $password
 */
class SysUser extends Generated\SysUser
{
    /**
     * M01.F04 失败锁定常量（家族口径：连续 5 次锁 15 分钟；rails SysUser 同源）。
     * 注意：不沿用 rails 旧锚 M01.F04.I02——该 I 已废弃（合并入 I01）。
     */
    public const LOCKOUT_THRESHOLD = 5;

    public const LOCKOUT_MINUTES = 15;

    public function tenantMembers(): HasMany
    {
        return $this->hasMany(TenantMember::class, 'user_id');
    }

    /**
     * 密码校验：家族 dev 种子约定 "plain:{password}" 前缀直等 + bcrypt 兜底
     * （三后端同语义，否则同一份种子登录行为分叉——springboot AuthController 对齐）。
     * InvalidHash → false（镜像 rails BCrypt::Errors::InvalidHash rescue 语义）。
     */
    public function passwordMatches(string $raw): bool
    {
        $stored = (string) $this->password;
        if ($stored === 'plain:'.$raw) {
            return true;
        }

        // bcrypt 兜底：非 $2x$ 前缀视为 InvalidHash（password_verify 本身对非法
        // hash 恒 false，这里显式前置判定以忠实镜像 rails 的 rescue 分支）。
        if (! str_starts_with($stored, '$2y$') && ! str_starts_with($stored, '$2a$') && ! str_starts_with($stored, '$2b$')) {
            return false;
        }

        return password_verify($raw, $stored);
    }
}
