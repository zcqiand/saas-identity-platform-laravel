<?php

declare(strict_types=1);

// 菜单（public.sys_menu）。根 parent_id = 全零 UUID。镜像 rails app/models/sys_menu.rb。
// 注意：type 列是业务枚举（1/2/3，见 shared Family::Dicts menu_type），Eloquent 无 STI
// 概念，$casts 已按 int 处理——不存在 rails inheritance_column 问题，勿把 type 当类 Discriminator。

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $client_id
 * @property string $parent_id
 * @property string $title
 * @property int $type
 * @property string|null $path
 * @property string|null $component
 * @property string|null $perms
 * @property string|null $icon
 * @property int $sort_order
 * @property int $status
 * @property Carbon $created_at
 */
class SysMenu extends Generated\SysMenu
{
    /** 根菜单哨兵：全零 UUID（rails SysMenu::ROOT_MENU_ID 同源）。 */
    public const ROOT_MENU_ID = '00000000-0000-0000-0000-000000000000';

    public function sysRoleMenus(): HasMany
    {
        return $this->hasMany(SysRoleMenu::class, 'menu_id');
    }
}
