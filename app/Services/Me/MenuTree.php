<?php

declare(strict_types=1);

namespace App\Services\Me;

use App\Models\SysMenu;
use App\Models\SysRoleMenu;
use App\Models\TenantMember;
use App\Models\TenantMemberRole;
use App\Services\Family\Dicts;
use Illuminate\Support\Collection;

// /me/menus 装配（rails app/services/me/menu_tree.rb 全文镜像，springboot
// MeController.getMyMenus 同族）：
// member(user 全量) -> member_role -> role_menu -> sys_menu -> 按 clientId 分组建树。
// 根 parentId 零值 UUID -> 剔除；孤儿（parent 不在授权集）提升为 root；
// 仅根层按 sortOrder 升序（null/缺省 → 0），子层不再排序。
final class MenuTree
{
    public function __construct(private readonly string $userId) {}

    // @impl M04.F04.I08 (book anchor xr-know-012)
    public function call(): array
    {
        $memberIds = TenantMember::query()->where('user_id', $this->userId)->pluck('id')->all();
        if ($memberIds === []) {
            return [];
        }

        $roleIds = TenantMemberRole::query()->whereIn('member_id', $memberIds)->pluck('role_id')->all();
        if ($roleIds === []) {
            return [];
        }

        $menuIds = SysRoleMenu::query()->whereIn('role_id', $roleIds)
            ->pluck('menu_id')
            ->unique()
            ->values()
            ->all();
        if ($menuIds === []) {
            return [];
        }

        $menus = SysMenu::query()->whereIn('id', $menuIds)->get();

        $out = [];
        foreach ($menus->groupBy('client_id') as $clientId => $rows) {
            $out[(string) $clientId] = $this->buildTree($rows);
        }

        return $out;
    }

    /**
     * rails build_tree 引用装配逐行镜像：根（零值 UUID 或 parent 不在授权集）收 roots，
     * 其余挂 parent 的 children；孤儿提升语义由此自然成立（parent 查无 → 当根）。
     * 环（parent 互指）无根即不出树——与 rails 同形。
     *
     * @param  Collection<int, SysMenu>  $rows
     * @return list<array<string, mixed>>
     */
    private function buildTree(Collection $rows): array
    {
        $byId = [];
        foreach ($rows as $menu) {
            $byId[(string) $menu->id] = $menu;
        }

        $nodes = [];
        foreach ($rows as $menu) {
            $nodes[(string) $menu->id] = $this->nodeFor($menu);
        }

        $roots = [];
        foreach ($rows as $menu) {
            $parentId = (string) $menu->parent_id;
            $parent = $parentId === SysMenu::ROOT_MENU_ID ? null : ($byId[$parentId] ?? null);
            if ($parent === null) {
                $roots[] = &$nodes[(string) $menu->id];
            } else {
                $nodes[(string) $parent->id]['children'][] = &$nodes[(string) $menu->id];
            }
        }
        unset($menu, $parent);

        usort($roots, fn (array $a, array $b): int => (($a['sort_order'] ?? 0) ?: 0) <=> (($b['sort_order'] ?? 0) ?: 0));

        return $roots;
    }

    /**
     * @return array<string, mixed>
     */
    private function nodeFor(SysMenu $menu): array
    {
        $isRoot = (string) $menu->parent_id === SysMenu::ROOT_MENU_ID;

        return [
            'id' => $menu->id,
            'client_id' => $menu->client_id,
            'parent_id' => $isRoot ? null : (string) $menu->parent_id,
            'title' => $menu->title,
            'type' => Dicts::menuType((int) $menu->type),
            'path' => $menu->path,
            'component' => $menu->component,
            'perms' => $menu->perms,
            'icon' => $menu->icon,
            'sort_order' => $menu->sort_order,
            'children' => [],
        ];
    }
}
