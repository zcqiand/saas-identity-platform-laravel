<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\OauthClient;
use App\Models\SysMenu;
use App\Models\SysUser;
use App\Models\Tenant;
use App\Models\TenantApplication;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\IntegrationSupport;
use Tests\TestCase;

// client 面 12 op 集成测试（真库 saas_test）：ClientMenus 7 + Clients 匿名 1 + TenantApplications 4。
// rails client_menus_controller.rb / clients_controller.rb / tenant_applications_controller.rb
// 镜像用例集——菜单扁平列表 / parentId 零值 UUID 不转 null / reorder / 公共 client 元数据
// 匿名可读白名单形状 / 订阅 dup 预检 400 / 未知 clientId 404 先查 oauth_client / expireTime 落库。
final class ClientFlowTest extends TestCase
{
    use DatabaseTransactions;
    use IntegrationSupport;

    private string $token;

    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        // 种子探针 fail-fast（只读）：测试链种子缺失即失败并指路，禁静默 skip（ADR-0020）。
        if (! SysUser::query()->where('username', self::DEV_USER)->exists()) {
            self::fail('saas_test 缺 alice 种子——先跑 saas-identity-platform-shared 的 seed-db.mjs');
        }

        $body = $this->loginBody($this->login());
        $this->token = (string) $body['accessToken'];
        $this->tenantId = $this->aliceTenantId();
    }

    // ---- client-menus（7 op；rails：protected 无 TenantGuard 租户校验——无 tenantId 缝） ----

    // rails list_sys_menus 镜像：扁平列表（非树）order(sort_order)；DTO 无 updatedAt；
    // null 字段（component/icon/perms 等）渲染层剔除；parentId 零值 UUID 原样不转 null
    #[Test]
    public function client_menus_list_returns_flat_list_ordered_by_sort_order(): void
    {
        $clientId = $this->makeClient('tf-menu-list');
        $menuC = $this->makeMenu($clientId, 'TF-C', sortOrder: 2);
        $menuA = $this->makeMenu($clientId, 'TF-A', sortOrder: 0);
        $menuB = $this->makeMenu($clientId, 'TF-B', sortOrder: 1);

        $response = $this->getJson(
            "/api/v1/clients/{$clientId}/menus",
            $this->authHeader($this->token)
        );

        self::assertSame(200, $response->status(), $response->getContent());
        $body = $response->json();
        self::assertIsArray($body);
        self::assertSame([$menuA, $menuB, $menuC], array_column($body, 'id'), $response->getContent());
        $keys = array_keys($body[0]);
        sort($keys);
        self::assertSame(
            ['clientId', 'createdAt', 'icon', 'id', 'parentId', 'path', 'sortOrder', 'status', 'title', 'type'],
            $keys
        );
        self::assertSame($clientId, $body[0]['clientId']);
        self::assertSame(SysMenu::ROOT_MENU_ID, $body[0]['parentId']);
        self::assertSame('directory', $body[0]['type']);
        // rails render_paginated 不是信封——裸数组，无 items/page 键
        self::assertArrayNotHasKey('items', $body[0]);
    }

    // rails create_sys_menu 镜像：parentId 缺省 → ROOT_MENU_ID 零值 UUID（不转 null）；
    // type 缺省 1(directory)；sortOrder 缺省 0；status 1；DTO createdAt 无 updatedAt
    #[Test]
    public function create_sys_menu_defaults_parent_to_root_zero_uuid(): void
    {
        $clientId = $this->makeClient('tf-menu-create');

        $response = $this->postJson(
            "/api/v1/clients/{$clientId}/menus",
            ['title' => 'TF-New', 'type' => 'directory', 'path' => '/tf-new'],
            $this->authHeader($this->token)
        );

        self::assertSame(200, $response->status(), $response->getContent());
        $body = $response->json();
        // 关键镜像点：parentId 零值 UUID 原样出现，不是 null 也不是被剔除
        self::assertSame(SysMenu::ROOT_MENU_ID, $body['parentId']);
        self::assertSame('directory', $body['type']);
        self::assertSame(0, $body['sortOrder']);
        self::assertSame(1, $body['status']);
        self::assertSame('/tf-new', $body['path']);
        self::assertArrayHasKey('createdAt', $body);
        self::assertArrayNotHasKey('updatedAt', $body);

        $menu = SysMenu::query()->where('id', $body['id'])->firstOrFail();
        self::assertSame(SysMenu::ROOT_MENU_ID, (string) $menu->parent_id);
        self::assertSame(1, (int) $menu->type);
        self::assertSame($clientId, (string) $menu->client_id);
    }

    // rails 'title: is required' ArgumentError → 400 镜像
    #[Test]
    public function create_sys_menu_missing_title_is_400(): void
    {
        $clientId = $this->makeClient('tf-menu-notitle');

        $response = $this->postJson(
            "/api/v1/clients/{$clientId}/menus",
            ['type' => 'directory'],
            $this->authHeader($this->token)
        );

        self::assertSame(400, $response->status());
        self::assertSame('BAD_REQUEST', $response->json('code'));
        self::assertSame(0, SysMenu::query()->where('client_id', $clientId)->count());
    }

    // rails menu_type_code 未知字符串 raise ArgumentError → 400 镜像
    #[Test]
    public function create_sys_menu_unknown_type_is_400(): void
    {
        $clientId = $this->makeClient('tf-menu-badtype');

        $response = $this->postJson(
            "/api/v1/clients/{$clientId}/menus",
            ['title' => 'TF-Bad', 'type' => 'bogus'],
            $this->authHeader($this->token)
        );

        self::assertSame(400, $response->status());
        self::assertSame('BAD_REQUEST', $response->json('code'));
    }

    // rails SysMenu.find 查无 → 404（合法 uuid 不触发 400 甄别）
    #[Test]
    public function get_sys_menu_unknown_id_is_404(): void
    {
        $clientId = $this->makeClient('tf-menu-get404');

        $response = $this->getJson(
            "/api/v1/clients/{$clientId}/menus/".Str::uuid(),
            $this->authHeader($this->token)
        );

        self::assertSame(404, $response->status());
        self::assertSame('NOT_FOUND', $response->json('code'));
    }

    // 基座 UUID 甄别：声称 uuid 格式非法 → 400 malformed uuid（menuId 在扫描表）
    #[Test]
    public function get_sys_menu_malformed_uuid_is_400(): void
    {
        $clientId = $this->makeClient('tf-menu-baduuid');

        $response = $this->getJson(
            "/api/v1/clients/{$clientId}/menus/not-a-uuid",
            $this->authHeader($this->token)
        );

        self::assertSame(400, $response->status());
        self::assertSame('BAD_REQUEST', $response->json('code'));
    }

    // rails update_sys_menu 镜像：只应用 title/path/component/perms/icon/sortOrder；
    // parentId/type/status 改不动（get/update 不校验 menu.client_id == path clientId，镜像）
    #[Test]
    public function update_sys_menu_applies_only_whitelisted_fields(): void
    {
        $clientId = $this->makeClient('tf-menu-update');
        $menuId = $this->makeMenu($clientId, 'TF-Old', path: '/tf-old');

        $response = $this->patchJson(
            "/api/v1/clients/{$clientId}/menus/{$menuId}",
            [
                'title' => 'TF-Renamed',
                'path' => '/tf-renamed',
                'sortOrder' => 9,
                'parentId' => 'hacked-parent',
                'type' => 'button',
                'status' => 0,
            ],
            $this->authHeader($this->token)
        );

        self::assertSame(200, $response->status(), $response->getContent());
        $body = $response->json();
        self::assertSame('TF-Renamed', $body['title']);
        self::assertSame('/tf-renamed', $body['path']);
        self::assertSame(9, $body['sortOrder']);
        self::assertSame(SysMenu::ROOT_MENU_ID, $body['parentId']);
        self::assertSame('directory', $body['type']);
        self::assertSame(1, $body['status']);

        $menu = SysMenu::query()->where('id', $menuId)->firstOrFail();
        self::assertSame('TF-Renamed', (string) $menu->title);
        self::assertSame(9, (int) $menu->sort_order);
        self::assertSame(SysMenu::ROOT_MENU_ID, (string) $menu->parent_id);
        self::assertSame(1, (int) $menu->status);
    }

    // rails move_sys_menu 镜像：body { parentId } 非空则改挂；缺省/空不动；响应 DTO
    #[Test]
    public function move_sys_menu_updates_parent_only_when_present(): void
    {
        $clientId = $this->makeClient('tf-menu-move');
        $menuId = $this->makeMenu($clientId, 'TF-Child');
        $newParentId = $this->makeMenu($clientId, 'TF-NewParent');

        $moved = $this->patchJson(
            "/api/v1/clients/{$clientId}/menus/{$menuId}/parent",
            ['parentId' => $newParentId],
            $this->authHeader($this->token)
        );
        self::assertSame(200, $moved->status(), $moved->getContent());
        self::assertSame($newParentId, $moved->json('parentId'));
        self::assertSame($newParentId, (string) SysMenu::query()->where('id', $menuId)->firstOrFail()->parent_id);

        // 无 parentId → 不改挂，原样返回 DTO
        $noop = $this->patchJson(
            "/api/v1/clients/{$clientId}/menus/{$menuId}/parent",
            [],
            $this->authHeader($this->token)
        );
        self::assertSame(200, $noop->status());
        self::assertSame($newParentId, $noop->json('parentId'));
    }

    // rails reorder_sys_menus 镜像：取 indexOf(menuId) 写 sortOrder（只改当前菜单一条）；
    // 响应 = 该 clientId 全量扁平列表
    #[Test]
    public function reorder_sys_menus_updates_sort_order_and_returns_full_list(): void
    {
        $clientId = $this->makeClient('tf-menu-reorder');
        $menuA = $this->makeMenu($clientId, 'TF-A', sortOrder: 0);
        $menuB = $this->makeMenu($clientId, 'TF-B', sortOrder: 1);
        $menuC = $this->makeMenu($clientId, 'TF-C', sortOrder: 2);

        $response = $this->putJson(
            "/api/v1/clients/{$clientId}/menus/{$menuB}/reorder",
            ['orderedMenuIds' => [$menuC, $menuA, $menuB]],
            $this->authHeader($this->token)
        );

        self::assertSame(200, $response->status(), $response->getContent());
        $body = $response->json();
        self::assertIsArray($body);
        // 2026-10-21：并列 sortOrder 的次序两后端契约都未定义（list 只 orderBy('sort_order')
        // 无次级键；rails 实态靠 PG heap 更新行挪位偶得 [A,C,B]，laravel 同参 [A,B,C]）——
        // 原钉 [A,C,B] 钉的是未定义 DB 序，PG heap 态一变即翻红。钉确定性面：
        // A(sortOrder 0) 打头 + 尾组 {B,C} 集合相等；sortOrder 值仍逐行严断（下方）。
        $ids = array_column($body, 'id');
        self::assertSame($menuA, $ids[0], $response->getContent());
        self::assertCount(3, $ids, $response->getContent());
        self::assertEqualsCanonicalizing([$menuB, $menuC], array_slice($ids, 1), $response->getContent());
        $rowB = SysMenu::query()->where('id', $menuB)->firstOrFail();
        self::assertSame(2, (int) $rowB->sort_order);
        // 只改当前菜单一条：A/C 的 sortOrder 不动
        self::assertSame(0, (int) SysMenu::query()->where('id', $menuA)->firstOrFail()->sort_order);
        self::assertSame(2, (int) SysMenu::query()->where('id', $menuC)->firstOrFail()->sort_order);
    }

    // rails 镜像：menuId 不在 orderedMenuIds → idx nil → 不写，仍 200 回全量列表
    #[Test]
    public function reorder_sys_menus_menu_not_in_list_keeps_sort_order(): void
    {
        $clientId = $this->makeClient('tf-menu-reorder2');
        $menuA = $this->makeMenu($clientId, 'TF-A', sortOrder: 0);
        $menuB = $this->makeMenu($clientId, 'TF-B', sortOrder: 1);

        $response = $this->putJson(
            "/api/v1/clients/{$clientId}/menus/{$menuB}/reorder",
            ['orderedMenuIds' => [$menuA]],
            $this->authHeader($this->token)
        );

        self::assertSame(200, $response->status(), $response->getContent());
        self::assertSame(1, (int) SysMenu::query()->where('id', $menuB)->firstOrFail()->sort_order);
    }

    // rails 'orderedMenuIds: is required'（非 Array raise）→ 400 镜像
    #[Test]
    public function reorder_sys_menus_missing_ordered_menu_ids_is_400(): void
    {
        $clientId = $this->makeClient('tf-menu-reorder3');
        $menuId = $this->makeMenu($clientId, 'TF-A');

        $response = $this->putJson(
            "/api/v1/clients/{$clientId}/menus/{$menuId}/reorder",
            [],
            $this->authHeader($this->token)
        );

        self::assertSame(400, $response->status());
        self::assertSame('BAD_REQUEST', $response->json('code'));
    }

    // rails delete_sys_menu 镜像：deleteById 语义不预查，恒 204（未知 uuid 也 204）
    #[Test]
    public function delete_sys_menu_is_204_even_for_unknown(): void
    {
        $clientId = $this->makeClient('tf-menu-delete');
        $menuId = $this->makeMenu($clientId, 'TF-Doomed');

        $known = $this->deleteJson(
            "/api/v1/clients/{$clientId}/menus/{$menuId}",
            [],
            $this->authHeader($this->token)
        );
        self::assertSame(204, $known->status(), $known->getContent());
        self::assertSame(0, SysMenu::query()->where('id', $menuId)->count());

        $unknown = $this->deleteJson(
            "/api/v1/clients/{$clientId}/menus/".Str::uuid(),
            [],
            $this->authHeader($this->token)
        );
        self::assertSame(204, $unknown->status(), $unknown->getContent());
    }

    // I-1（Round 1 评审）：rails deleteById 对非法 menuId 由 AR cast 吞错 → 恒 204，
    // 不得 22P02 → 500 分叉（同 locateMenu 前置甄别，但 delete 语义无 404/400 面）
    #[Test]
    public function delete_sys_menu_malformed_uuid_is_204(): void
    {
        $clientId = $this->makeClient('tf-menu-del-bad');

        $response = $this->deleteJson(
            "/api/v1/clients/{$clientId}/menus/not-a-uuid",
            [],
            $this->authHeader($this->token)
        );

        self::assertSame(204, $response->status(), $response->getContent());
    }

    // M-1（Round 1 评审）：rails presence/present? 的 blank 语义——纯空白 parentId 等价缺省：
    // create 落 ROOT_MENU_ID、move 不改挂（均 200），不得 22P02 → 500
    #[Test]
    public function blank_parent_id_falls_back_to_root_and_move_is_noop(): void
    {
        $clientId = $this->makeClient('tf-menu-blank');

        $created = $this->postJson(
            "/api/v1/clients/{$clientId}/menus",
            ['title' => 'TF-Blank', 'parentId' => '   '],
            $this->authHeader($this->token)
        );
        self::assertSame(200, $created->status(), $created->getContent());
        self::assertSame(SysMenu::ROOT_MENU_ID, $created->json('parentId'));
        $menuId = (string) $created->json('id');

        $moved = $this->patchJson(
            "/api/v1/clients/{$clientId}/menus/{$menuId}/parent",
            ['parentId' => '   '],
            $this->authHeader($this->token)
        );
        self::assertSame(200, $moved->status(), $moved->getContent());
        self::assertSame(SysMenu::ROOT_MENU_ID, $moved->json('parentId'));
        self::assertSame(SysMenu::ROOT_MENU_ID, (string) SysMenu::query()->where('id', $menuId)->firstOrFail()->parent_id);
    }

    // rails include TenantGuard 镜像：client-menus 无 token → 401（无租户缝，无 403 面）
    #[Test]
    public function client_menus_requires_jwt(): void
    {
        self::assertSame(401, $this->getJson('/api/v1/clients/saas-console/menus')->status());
        self::assertSame(
            401,
            $this->postJson('/api/v1/clients/saas-console/menus', ['title' => 'x'])->status()
        );
    }

    // ---- clients（1 op；匿名可读公共元数据，白名单形状） ----

    // rails get_client 镜像：无 Authorization 头 200；白名单三键 {clientId, clientName, status}，
    // 不泄露 client_secret/redirect_uris 等
    #[Test]
    public function get_client_public_metadata_is_anonymous_whitelist_shape(): void
    {
        $clientId = 'tf-public-'.Str::random(4);
        $this->makeClient($clientId, clientName: 'TF Public');

        $response = $this->getJson("/api/v1/clients/{$clientId}");

        self::assertSame(200, $response->status(), $response->getContent());
        $body = $response->json();
        $keys = array_keys($body);
        sort($keys);
        self::assertSame(['clientId', 'clientName', 'status'], $keys);
        self::assertSame($clientId, $body['clientId']);
        self::assertSame('TF Public', $body['clientName']);
        self::assertSame(1, $body['status']);
        self::assertArrayNotHasKey('clientSecret', $body);
        self::assertArrayNotHasKey('redirectUris', $body);
    }

    // rails OauthClient.find_by! 查无 → 404
    #[Test]
    public function get_client_unknown_is_404(): void
    {
        $response = $this->getJson('/api/v1/clients/tf-ghost-client-'.Str::random(4));

        self::assertSame(404, $response->status());
        self::assertSame('NOT_FOUND', $response->json('code'));
    }

    // ---- tenant-applications（4 op；TenantGuard + verifyPathTenant） ----

    // rails list_tenant_applications 镜像：分页信封 + 应用 DTO 键集
    #[Test]
    public function tenant_applications_list_returns_envelope(): void
    {
        $clientId = $this->makeClient('tf-app-list');
        $appId = $this->makeApplication($this->tenantId, $clientId, expireTime: '2030-06-30T00:00:00Z');

        $response = $this->getJson(
            "/api/v1/tenants/{$this->tenantId}/applications?page=0&pageSize=5",
            $this->authHeader($this->token)
        );

        self::assertSame(200, $response->status(), $response->getContent());
        $body = $response->json();
        self::assertSame(['items', 'page', 'pageSize', 'total'], array_keys($body));
        self::assertSame(0, $body['page']);
        self::assertSame(5, $body['pageSize']);
        self::assertContains($appId, array_column($body['items'], 'id'));
        $row = $body['items'][array_search($appId, array_column($body['items'], 'id'), true)];
        $keys = array_keys($row);
        sort($keys);
        self::assertSame(['clientId', 'createdAt', 'expireTime', 'id', 'status', 'tenantId'], $keys);
        self::assertSame($clientId, $row['clientId']);
        self::assertSame($this->tenantId, $row['tenantId']);
        self::assertSame(1, $row['status']);
    }

    // rails subscribe_tenant_application 镜像：expireTime 落库（2026-10-03 追平）；
    // status 1；expireTime 缺省 → 行 expire_time null（响应键剔除）
    #[Test]
    public function subscribe_tenant_application_persists_with_expire_time(): void
    {
        $clientId = $this->makeClient('tf-app-sub');

        $response = $this->postJson(
            "/api/v1/tenants/{$this->tenantId}/applications",
            ['clientId' => $clientId, 'expireTime' => '2030-06-30T00:00:00Z'],
            $this->authHeader($this->token)
        );

        self::assertSame(200, $response->status(), $response->getContent());
        $body = $response->json();
        $keys = array_keys($body);
        sort($keys);
        self::assertSame(['clientId', 'createdAt', 'expireTime', 'id', 'status', 'tenantId'], $keys);
        self::assertSame($clientId, $body['clientId']);
        self::assertSame(1, $body['status']);

        $app = TenantApplication::query()
            ->where('tenant_id', $this->tenantId)->where('client_id', $clientId)->firstOrFail();
        self::assertSame(1, (int) $app->status);
        self::assertNotNull($app->expire_time);
        self::assertTrue($app->expire_time->equalTo('2030-06-30T00:00:00Z'));
        self::assertSame($app->expire_time->toISOString(), $body['expireTime']);

        // 缺省 expireTime 分支：行落库 null、响应无 expireTime 键
        $clientId2 = $this->makeClient('tf-app-sub2');
        $noExpire = $this->postJson(
            "/api/v1/tenants/{$this->tenantId}/applications",
            ['clientId' => $clientId2],
            $this->authHeader($this->token)
        );
        self::assertSame(200, $noExpire->status(), $noExpire->getContent());
        self::assertArrayNotHasKey('expireTime', $noExpire->json());
        $row2 = TenantApplication::query()
            ->where('tenant_id', $this->tenantId)->where('client_id', $clientId2)->firstOrFail();
        self::assertNull($row2->expire_time);
    }

    // M-2（Round 1 评审）：rails AR datetime cast 对垃圾串 → nil（行落 null、200），
    // 不得 Carbon cast 抛 → 500。subscribe 与 update 同语义（update 同样 null 化）
    #[Test]
    public function garbage_expire_time_casts_to_null(): void
    {
        $clientId = $this->makeClient('tf-app-garbage');

        $subscribed = $this->postJson(
            "/api/v1/tenants/{$this->tenantId}/applications",
            ['clientId' => $clientId, 'expireTime' => 'not-a-date'],
            $this->authHeader($this->token)
        );
        self::assertSame(200, $subscribed->status(), $subscribed->getContent());
        self::assertArrayNotHasKey('expireTime', $subscribed->json());
        $app = TenantApplication::query()
            ->where('tenant_id', $this->tenantId)->where('client_id', $clientId)->firstOrFail();
        self::assertNull($app->expire_time);

        $updated = $this->patchJson(
            "/api/v1/tenants/{$this->tenantId}/applications/{$clientId}",
            ['expireTime' => 'also-garbage'],
            $this->authHeader($this->token)
        );
        self::assertSame(200, $updated->status(), $updated->getContent());
        self::assertArrayNotHasKey('expireTime', $updated->json());
        self::assertNull($app->refresh()->expire_time);
    }

    // rails ensure_subscribable! 镜像：dup 预检 → 400 clean message（不盲插不泄漏驱动诊断）
    #[Test]
    public function subscribe_duplicate_client_id_is_400(): void
    {
        $clientId = $this->makeClient('tf-app-dup');
        $this->makeApplication($this->tenantId, $clientId);

        $response = $this->postJson(
            "/api/v1/tenants/{$this->tenantId}/applications",
            ['clientId' => $clientId],
            $this->authHeader($this->token)
        );

        self::assertSame(400, $response->status());
        self::assertSame('BAD_REQUEST', $response->json('code'));
        self::assertStringStartsWith('subscription already exists', (string) $response->json('message'));
        self::assertSame(1, TenantApplication::query()
            ->where('tenant_id', $this->tenantId)->where('client_id', $clientId)->count());
    }

    // rails 镜像：未知 clientId 先查 oauth_client（find_by! → 404），非盲插不撞 FK
    #[Test]
    public function subscribe_unknown_client_id_is_404_and_writes_nothing(): void
    {
        $before = TenantApplication::query()->where('tenant_id', $this->tenantId)->count();

        $response = $this->postJson(
            "/api/v1/tenants/{$this->tenantId}/applications",
            ['clientId' => 'tf-ghost-app-'.Str::random(4)],
            $this->authHeader($this->token)
        );

        self::assertSame(404, $response->status());
        self::assertSame('NOT_FOUND', $response->json('code'));
        self::assertSame($before, TenantApplication::query()->where('tenant_id', $this->tenantId)->count());
    }

    // rails update_tenant_application 镜像：status/expireTime present 才应用；响应 DTO
    #[Test]
    public function update_tenant_application_applies_status_and_expire_time(): void
    {
        $clientId = $this->makeClient('tf-app-update');
        $appId = $this->makeApplication($this->tenantId, $clientId, expireTime: '2030-01-01T00:00:00Z');

        $response = $this->patchJson(
            "/api/v1/tenants/{$this->tenantId}/applications/{$clientId}",
            ['status' => 2, 'expireTime' => '2031-12-31T00:00:00Z'],
            $this->authHeader($this->token)
        );

        self::assertSame(200, $response->status(), $response->getContent());
        $body = $response->json();
        self::assertSame($appId, $body['id']);
        self::assertSame(2, $body['status']);

        $app = TenantApplication::query()->where('id', $appId)->firstOrFail();
        self::assertSame(2, (int) $app->status);
        self::assertTrue($app->expire_time->equalTo('2031-12-31T00:00:00Z'));
    }

    // rails 镜像：blank status/expireTime present?=false 不应用（no-op update 仍 200 回 DTO）
    #[Test]
    public function update_tenant_application_blank_fields_are_ignored(): void
    {
        $clientId = $this->makeClient('tf-app-noop');
        $appId = $this->makeApplication($this->tenantId, $clientId);

        $response = $this->patchJson(
            "/api/v1/tenants/{$this->tenantId}/applications/{$clientId}",
            ['status' => '   ', 'expireTime' => null],
            $this->authHeader($this->token)
        );

        self::assertSame(200, $response->status(), $response->getContent());
        $app = TenantApplication::query()->where('id', $appId)->firstOrFail();
        self::assertSame(1, (int) $app->status);
        self::assertNull($app->expire_time);
    }

    // rails remove_tenant_application 镜像：ifPresent 静默，行不存在也 204
    #[Test]
    public function remove_tenant_application_is_204_even_when_missing(): void
    {
        $clientId = $this->makeClient('tf-app-remove');
        $appId = $this->makeApplication($this->tenantId, $clientId);

        $known = $this->deleteJson(
            "/api/v1/tenants/{$this->tenantId}/applications/{$clientId}",
            [],
            $this->authHeader($this->token)
        );
        self::assertSame(204, $known->status(), $known->getContent());
        self::assertSame(0, TenantApplication::query()->where('id', $appId)->count());

        $unknown = $this->deleteJson(
            "/api/v1/tenants/{$this->tenantId}/applications/tf-never-subscribed",
            [],
            $this->authHeader($this->token)
        );
        self::assertSame(204, $unknown->status(), $unknown->getContent());
    }

    // Task 7 定案反向面：JWT tenant claim ≠ path tenantId → 4 op 逐 op 403（guard 在先零改动）
    #[Test]
    public function cross_tenant_token_is_403_on_all_application_ops(): void
    {
        $otherTenantId = $this->makeTenant('tf-app-guard');
        $otherClientId = $this->makeClient('tf-app-guard-client');
        $this->makeApplication($otherTenantId, $otherClientId);
        $base = "/api/v1/tenants/{$otherTenantId}/applications";
        $h = $this->authHeader($this->token);

        $responses = [
            'list' => $this->getJson($base, $h),
            'subscribe' => $this->postJson($base, ['clientId' => 'tf-app-guard-new'], $h),
            'update' => $this->patchJson("{$base}/{$otherClientId}", ['status' => 0], $h),
            'remove' => $this->deleteJson("{$base}/{$otherClientId}", [], $h),
        ];
        foreach ($responses as $op => $response) {
            self::assertSame(403, $response->status(), "{$op} must 403, got {$response->status()}");
        }
        self::assertSame('FORBIDDEN', $responses['list']->json('code'));

        // 守卫在先：跨租户行零改动（subscribe 未落行、update 未改 status）
        self::assertSame(1, TenantApplication::query()->where('tenant_id', $otherTenantId)->count());
        self::assertSame(
            1,
            (int) TenantApplication::query()
                ->where('tenant_id', $otherTenantId)->where('client_id', $otherClientId)->firstOrFail()->status
        );
    }

    // ---- 数据工厂（事务内，回滚不留痕） ----

    /** sys_menu.client_id / tenant_application.client_id 有 FK → oauth_client（先行造引用行） */
    private function makeClient(string $clientId, ?string $clientName = null): string
    {
        $now = Date::now();
        OauthClient::query()->create([
            'id' => (string) Str::uuid(),
            'client_id' => $clientId,
            'client_secret' => 's',
            'client_name' => $clientName ?? $clientId,
            'grant_types' => 'authorization_code',
            'redirect_uris' => 'http://localhost/cb',
            'status' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $clientId;
    }

    private function makeMenu(string $clientId, string $title, int $sortOrder = 0, ?string $path = null): string
    {
        $menuId = (string) Str::uuid();
        (new SysMenu([
            'id' => $menuId,
            'client_id' => $clientId,
            'parent_id' => SysMenu::ROOT_MENU_ID,
            'title' => $title,
            'type' => 1,
            'path' => $path ?? "/tf-{$title}",
            'component' => null,
            'perms' => null,
            'icon' => "icon-{$title}",
            'sort_order' => $sortOrder,
            'status' => 1,
            'created_at' => Date::now(),
        ]))->save();

        return $menuId;
    }

    private function makeApplication(string $tenantId, string $clientId, ?string $expireTime = null): string
    {
        $appId = (string) Str::uuid();
        TenantApplication::query()->create([
            'id' => $appId,
            'tenant_id' => $tenantId,
            'client_id' => $clientId,
            'status' => 1,
            'expire_time' => $expireTime,
            'created_at' => Date::now(),
        ]);

        return $appId;
    }

    private function makeTenant(string $keyPrefix): string
    {
        $now = Date::now();
        $tenantId = (string) Str::uuid();
        Tenant::query()->create([
            'id' => $tenantId,
            'tenant_key' => $keyPrefix.'-'.Str::random(6),
            'name' => $keyPrefix.' tenant',
            'status' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $tenantId;
    }
}
