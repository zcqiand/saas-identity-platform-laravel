<?php

namespace App\Http\Controllers;

use App\Models\SysMenu;
use App\Services\Family\Dicts;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

// tag: client-menus —— protected（非白名单路径）但无租户缝校验（rails
// client_menus_controller.rb 全文镜像：include TenantGuard、无 verify_path_tenant——
// 路径无 tenantId 段，JWT 鉴权在 tenant.guard 中间件面）。get/update/delete 不校验
// menu.client_id == path clientId（仅 findById，镜像 quirk 忠实保留）。
final class ClientMenusController extends Controller
{
    // 扁平列表（非树），parentId 零值 UUID 不转 null，无 updatedAt（镜像 DTO）
    // @impl M04.F04.I01 (book anchor xr-know-012)
    public function listSysMenus(Request $request): JsonResponse
    {
        $menus = SysMenu::query()
            ->where('client_id', (string) $request->route('clientId'))
            ->orderBy('sort_order')
            ->get();

        return $this->renderCamel($menus->map(fn (SysMenu $menu): array => $this->menuDto($menu))->all());
    }

    // @impl M04.F04.I02 (book anchor xr-know-012)
    public function createSysMenu(Request $request): JsonResponse
    {
        $title = $request->input('title');
        if (! is_string($title) || trim($title) === '') {
            throw new InvalidArgumentException('title: is required');
        }

        $type = $request->input('type');
        $typeCode = (is_string($type) && $type !== '') ? Dicts::menuTypeCode($type) : 1;
        $parentId = $request->input('parentId');

        $menu = new SysMenu([
            'id' => (string) Str::uuid(),
            'client_id' => (string) $request->route('clientId'),
            'parent_id' => (is_string($parentId) && $parentId !== '') ? $parentId : SysMenu::ROOT_MENU_ID,
            'title' => $title,
            'type' => $typeCode,
            'path' => $request->input('path'),
            'component' => $request->input('component'),
            'perms' => $request->input('perms'),
            'icon' => $request->input('icon'),
            'sort_order' => (int) $request->input('sortOrder'),
            'status' => 1,
            'created_at' => Date::now(),
        ]);
        $menu->save();

        return $this->renderCamel($this->menuDto($menu));
    }

    // @impl M04.F04.I03 (book anchor xr-know-012)
    public function getSysMenu(Request $request): JsonResponse
    {
        return $this->renderCamel($this->menuDto($this->locateMenu($request)));
    }

    // 镜像 springboot：只应用 title/path/component/perms/icon/sortOrder（忽略 parentId/type/status）
    // @impl M04.F04.I04 (book anchor xr-know-012)
    public function updateSysMenu(Request $request): JsonResponse
    {
        $menu = $this->locateMenu($request);

        $updates = [];
        foreach (['title', 'path', 'component', 'perms', 'icon'] as $key) {
            $value = $request->input($key);
            if ($value !== null) {
                $updates[$key] = $value;
            }
        }
        $sortOrder = $request->input('sortOrder');
        if ($sortOrder !== null) {
            $updates['sort_order'] = (int) $sortOrder;
        }
        if ($updates !== []) {
            $menu->fill($updates);
            $menu->save();
        }

        return $this->renderCamel($this->menuDto($menu));
    }

    // body { parentId } 非空则改挂；200 = 菜单 DTO；不存在 404
    // @impl M04.F04.I07 (book anchor xr-know-012)
    public function moveSysMenu(Request $request): JsonResponse
    {
        $menu = $this->locateMenu($request);
        $parentId = $request->input('parentId');
        if (is_string($parentId) && $parentId !== '') {
            $menu->parent_id = $parentId;
            $menu->save();
        }

        return $this->renderCamel($this->menuDto($menu));
    }

    // body { orderedMenuIds }：取 indexOf(menuId)，>=0 写 sortOrder=idx（只改当前菜单一条）；
    // 响应 = 该 clientId 全量扁平列表；菜单不存在 404
    // @impl M04.F04.I06 (book anchor xr-know-012)
    public function reorderSysMenus(Request $request): JsonResponse
    {
        $menu = $this->locateMenu($request);
        $ordered = $request->input('orderedMenuIds');
        if (! is_array($ordered)) {
            throw new InvalidArgumentException('orderedMenuIds: is required');
        }

        $idx = array_search((string) $menu->id, array_map(strval(...), $ordered), true);
        if ($idx !== false) {
            $menu->sort_order = $idx;
            $menu->save();
        }

        $menus = SysMenu::query()
            ->where('client_id', (string) $request->route('clientId'))
            ->orderBy('sort_order')
            ->get();

        return $this->renderCamel($menus->map(fn (SysMenu $row): array => $this->menuDto($row))->all());
    }

    // deleteById 语义：不预查，恒 204。非法 menuId 前置甄别（I-1）：rails AR cast 吞错
    // → 恒 204，PG uuid 列直查会 22P02 → 500 分叉；delete 无 404/400 面，甄别命中直接 204
    // @impl M04.F04.I05 (book anchor xr-know-012)
    public function deleteSysMenu(Request $request): Response
    {
        if (self::badUuidParam($request) !== null) {
            return response()->noContent();
        }

        SysMenu::query()->where('id', (string) $request->route('menuId'))->delete();

        return response()->noContent();
    }

    // rails SysMenu.find 镜像：查无 → ModelNotFound → 基座映射（合法 uuid 404 / 格式非法 400）。
    // 先走基座 UUID 甄别再查询——PG uuid 列直接吃非法串会 22P02 QueryException（500 分叉），
    // rails AR 类型 cast 先转 RecordNotFound，此处镜像该语义（甄别表与基座渲染扫描表同源）
    private function locateMenu(Request $request): SysMenu
    {
        $bad = self::badUuidParam($request);
        if ($bad !== null) {
            throw (new ModelNotFoundException)->setModel(SysMenu::class, [$bad]);
        }

        $menuId = (string) $request->route('menuId');
        $menu = SysMenu::query()->where('id', $menuId)->first();
        if ($menu === null) {
            throw (new ModelNotFoundException)->setModel(SysMenu::class, [$menuId]);
        }

        return $menu;
    }

    // rails menu_dto 镜像：零值 UUID 的 parentId 是真值原样渲染（Camel 渲染只剔 null）；
    // 无 updatedAt 键
    private function menuDto(SysMenu $menu): array
    {
        return [
            'id' => (string) $menu->id,
            'client_id' => (string) $menu->client_id,
            'parent_id' => (string) $menu->parent_id,
            'title' => (string) $menu->title,
            'type' => Dicts::menuType((int) $menu->type),
            'path' => $menu->path,
            'component' => $menu->component,
            'perms' => $menu->perms,
            'icon' => $menu->icon,
            'sort_order' => (int) $menu->sort_order,
            'status' => (int) $menu->status,
            'created_at' => $menu->created_at,
        ];
    }
}
