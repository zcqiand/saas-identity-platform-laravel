<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\OauthClient;
use App\Models\OauthRefreshToken;
use App\Models\SysMenu;
use App\Models\SysRole;
use App\Models\SysUser;
use App\Models\Tenant;
use App\Models\TenantMember;
use App\Services\Auth\JwtService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\IntegrationSupport;
use Tests\TestCase;

// me tag + Family 服务件集成测试（真库 saas_test；rails test/integration/admin_me_test.rb
// me 段全用例镜像 + brief 增量：menus 树 clientId 分组/根零值 UUID/孤儿提升、
// switch 临时 refresh 形状 saas-rt-{userId}-{ts}-{rand} 不落库）。
final class MeFlowTest extends TestCase
{
    use DatabaseTransactions;
    use IntegrationSupport;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        // 种子探针 fail-fast（只读）：测试链种子缺失即失败并指路，禁静默 skip（ADR-0020）。
        if (! SysUser::query()->where('username', self::DEV_USER)->exists()) {
            self::fail('saas_test 缺 alice 种子——先跑 saas-identity-platform-shared 的 seed-db.mjs');
        }

        $body = $this->loginBody($this->login());
        $this->token = (string) $body['accessToken'];
    }

    // rails 'me whoami returns memberships and resolved tenant' 1:1
    #[Test]
    public function whoami_returns_memberships_and_resolved_tenant(): void
    {
        $response = $this->getJson('/api/v1/me', $this->authHeader($this->token));

        self::assertSame(200, $response->status());
        $body = $response->json();
        self::assertSame(SysUser::query()->where('username', self::DEV_USER)->firstOrFail()->id, $body['id']);
        self::assertIsArray($body['memberships']);
        self::assertIsArray($body['memberships'][0]['roleIds']);
        // resolved tenant：JWT tenant_id claim 优先（alice 登录 token 必带，禁 null 兜底）
        self::assertNotEmpty($body['currentTenantId']);
        self::assertSame($this->aliceTenantId(), $body['currentTenantId']);
    }

    // rails MeController#whoami 防御分支镜像：user 查无 → { memberships: [] }
    #[Test]
    public function whoami_with_unknown_sub_returns_empty_memberships(): void
    {
        $token = (new JwtService)->encode([
            'sub' => (string) Str::uuid(),
            'tenant_id' => $this->aliceTenantId(),
        ]);

        $response = $this->getJson('/api/v1/me', $this->authHeader($token));

        self::assertSame(200, $response->status());
        self::assertSame(['memberships' => []], $response->json());
    }

    // rails 'me menus returns client-grouped tree with nulls stripped' + brief 增量：
    // clientId 分组 / 根 parentId 零值 UUID → 剔除 / 孤儿（parent 不在授权集）提升 root / 仅根层按 sortOrder。
    #[Test]
    public function me_menus_returns_client_grouped_tree_with_root_zero_uuid_and_orphan_promotion(): void
    {
        $this->grantMenusToAlice();

        $response = $this->getJson('/api/v1/me/menus', $this->authHeader($this->token));

        self::assertSame(200, $response->status());
        // rails 渲染 Hash → JSON 对象：raw body 解码必须是 object（assertIsArray 对 [] 也
        // 放行是弱化点——空集对象性由下一个专用用例钉死，此处断非空 map 的对象形状）
        self::assertIsObject(json_decode((string) $response->getContent()));
        $body = $response->json();
        // clientId 分组：本测试造的两个 client 键必须在（种子若另有授权不碍事）
        self::assertArrayHasKey('tree-client-a', $body);
        self::assertArrayHasKey('tree-client-b', $body);

        $roots = $body['tree-client-a'];
        // 根层按 sortOrder 升序（孤儿 sort_order null → 0 排最前）
        self::assertSame(['Orphan-Menu', 'A-Menu', 'B-Menu'], array_column($roots, 'title'));
        // 根零值 UUID → parentId 剔除（null 剔除家族契约：键不出现在 JSON）；孤儿保留原 parentId
        foreach ([$roots[1], $roots[2]] as $root) {
            self::assertArrayNotHasKey('parentId', $root);
            self::assertArrayHasKey('children', $root);
            // null 剔除：component/perms/icon 造数为 null → 键整体不出现
            self::assertArrayNotHasKey('component', $root);
            self::assertArrayNotHasKey('perms', $root);
            self::assertArrayNotHasKey('icon', $root);
        }
        // 孤儿提升：parent 不在授权集 → 提为 root（不丢失），parentId 保留悬挂原值
        $orphan = $roots[0];
        self::assertSame('button', $orphan['type']);
        self::assertArrayHasKey('parentId', $orphan);
        self::assertNotSame(SysMenu::ROOT_MENU_ID, $orphan['parentId']);
        // 子层挂在授权 parent 下，parentId 保留真实值；子层不再排序
        $aMenu = $roots[1];
        self::assertSame(['C-Menu'], array_column($aMenu['children'], 'title'));
        self::assertSame($aMenu['id'], $aMenu['children'][0]['parentId']);

        // client-b 组只有一根
        self::assertSame(['D-Menu'], array_column($body['tree-client-b'], 'title'));
    }

    // I-1 钉子：rails MenuTree 三处 return {} —— 无授权成员 /me/menus 渲染 JSON 对象 {}
    // 非 []（json_encode 空 PHP 数组退化为 []，须显式对象渲染）。
    #[Test]
    public function me_menus_without_grants_renders_empty_json_object_not_array(): void
    {
        SysUser::query()->create([
            'username' => 'menuless',
            'password' => 'plain:pw',
            'email' => 'menuless@x',
            'status' => 1,
            'failed_attempts' => 0,
        ]);
        $userId = (string) SysUser::query()->where('username', 'menuless')->firstOrFail()->id;
        $this->makeTenantWithMember($userId, 1);

        $body = $this->loginBody($this->login(username: 'menuless', password: 'pw'));
        $response = $this->getJson('/api/v1/me/menus', $this->authHeader((string) $body['accessToken']));

        self::assertSame(200, $response->status());
        $decoded = json_decode((string) $response->getContent());
        self::assertIsObject($decoded);
        self::assertSame([], (array) $decoded);
    }

    // rails 'me tenants returns bare active membership array' 1:1
    #[Test]
    public function me_tenants_returns_bare_active_membership_array(): void
    {
        $response = $this->getJson('/api/v1/me/tenants', $this->authHeader($this->token));

        self::assertSame(200, $response->status());
        $body = $response->json();
        self::assertIsArray($body);
        self::assertNotSame([], $body);
        self::assertSame('active', $body[0]['status']);
        self::assertSame(['id', 'userId', 'tenantId', 'roleIds', 'status', 'joinedAt'], array_keys($body[0]));
    }

    // switch 成功：新 JWT 换 tenant_id + 临时 refresh 形状 saas-rt-{userId}-{ts}-{rand}，不落库。
    #[Test]
    public function switch_tenant_returns_new_token_pair_with_ephemeral_refresh_without_persistence(): void
    {
        $userId = SysUser::query()->where('username', self::DEV_USER)->firstOrFail()->id;
        // alice 第二个 active membership 的租户（acme+globex，Task 5 实测种子两租）
        $otherTenantId = (string) Tenant::query()
            ->where('id', '!=', $this->aliceTenantId())
            ->where('status', 1)
            ->whereHas('tenantMembers', fn ($q) => $q->where('user_id', $userId)->where('status', 1))
            ->firstOrFail()->id;

        $before = OauthRefreshToken::query()->count();

        $response = $this->postJson("/api/v1/me/tenants/{$otherTenantId}/switch", [], $this->authHeader($this->token));

        self::assertSame(200, $response->status());
        $body = $response->json();
        self::assertSame($otherTenantId, $body['tenantId']);
        self::assertNotEmpty($body['accessToken']);
        // 新 access token 换了 tenant_id claim（切换语义本体）
        $claims = (new JwtService)->verify((string) $body['accessToken']);
        self::assertSame($userId, $claims['sub']);
        self::assertSame($otherTenantId, $claims['tenant_id']);
        // 临时 refresh 形状：saas-rt-{userId}-{ts-ms}-{rand}，rand 为 base64url 32 字符
        self::assertMatchesRegularExpression(
            '/^saas-rt-'.preg_quote($userId, '/').'-\d{13}-[A-Za-z0-9_-]{32}$/',
            (string) $body['refreshToken']
        );
        self::assertNotEmpty($body['expiresAt']);
        // 不落库：oauth_refresh_token 无任何 saas-rt 行（rails RefreshTokens.ephemeral 镜像）
        self::assertSame($before, OauthRefreshToken::query()->count());
        self::assertSame(0, OauthRefreshToken::query()->where('refresh_token', 'like', 'saas-rt-%')->count());
    }

    // rails switch 分支镜像：member.status 非零可切（invited/suspended 不挡），status=0 → 404。
    #[Test]
    public function switch_invited_membership_is_allowed(): void
    {
        $userId = SysUser::query()->where('username', self::DEV_USER)->firstOrFail()->id;
        $tenantId = $this->makeTenantWithMember($userId, 2);

        $response = $this->postJson("/api/v1/me/tenants/{$tenantId}/switch", [], $this->authHeader($this->token));

        self::assertSame(200, $response->status());
        self::assertSame($tenantId, $response->json('tenantId'));
    }

    #[Test]
    public function switch_disabled_membership_is_404(): void
    {
        $userId = SysUser::query()->where('username', self::DEV_USER)->firstOrFail()->id;
        $tenantId = $this->makeTenantWithMember($userId, 0);

        $response = $this->postJson("/api/v1/me/tenants/{$tenantId}/switch", [], $this->authHeader($this->token));

        self::assertSame(404, $response->status());
        self::assertSame('NOT_FOUND', $response->json('code'));
    }

    // rails 'membership not found' 分支：tenant 存在但 alice 无 member 行 → 404。
    #[Test]
    public function switch_tenant_without_membership_is_404(): void
    {
        $tenantId = (string) Tenant::query()
            ->whereDoesntHave('tenantMembers', fn ($q) => $q->where('user_id', SysUser::query()->where('username', self::DEV_USER)->firstOrFail()->id))
            ->firstOrFail()->id;

        $response = $this->postJson("/api/v1/me/tenants/{$tenantId}/switch", [], $this->authHeader($this->token));

        self::assertSame(404, $response->status());
        self::assertSame('NOT_FOUND', $response->json('code'));
    }

    // rails 'tenant not found' 分支：合法 uuid 查无 → 404（NOT_FOUND）。
    #[Test]
    public function switch_unknown_tenant_is_404(): void
    {
        $response = $this->postJson('/api/v1/me/tenants/'.Str::uuid().'/switch', [], $this->authHeader($this->token));

        self::assertSame(404, $response->status());
        self::assertSame('NOT_FOUND', $response->json('code'));
    }

    // ---- 数据工厂（事务内，回滚不留痕） ----

    /** alice 首 active member 行（种子保证存在） */
    private function aliceMember(): TenantMember
    {
        return TenantMember::query()
            ->where('user_id', SysUser::query()->where('username', self::DEV_USER)->firstOrFail()->id)
            ->where('status', 1)
            ->orderBy('created_at')
            ->firstOrFail();
    }

    /** 造 tenant + 指定 status 的 alice membership 行，回 tenant id */
    private function makeTenantWithMember(string $userId, int $status): string
    {
        $now = Date::now();
        $tenantId = (string) Str::uuid();
        Tenant::query()->create([
            'id' => $tenantId,
            'tenant_key' => 'me-switch-'.Str::random(6),
            'name' => 'me-switch tenant',
            'status' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        TenantMember::query()->create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'status' => $status,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $tenantId;
    }

    /**
     * 给 alice 首 active member 授一个角色 + 两 client 五条菜单：
     * tree-client-a：A-Menu/B-Menu（根，sortOrder 1/2）、C-Menu（A 的子，sortOrder 0）、
     * Orphan-Menu（parent 指向未授权菜单 → 孤儿提升 root）；tree-client-b：D-Menu（根）。
     * client_id 列有 FK → oauth_client 两行先行（事务内回滚不留痕）。
     */
    private function grantMenusToAlice(): void
    {
        $member = $this->aliceMember();
        $now = Date::now();
        foreach (['tree-client-a', 'tree-client-b'] as $clientId) {
            OauthClient::query()->create([
                'id' => (string) Str::uuid(),
                'client_id' => $clientId,
                'client_secret' => 's',
                'client_name' => $clientId,
                'grant_types' => 'authorization_code',
                'redirect_uris' => 'http://localhost/cb',
                'status' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        $roleId = (string) Str::uuid();
        SysRole::query()->create([
            'id' => $roleId,
            'tenant_id' => (string) $member->tenant_id,
            'client_id' => 'tree-client-a',
            'role_code' => 'me_flow_role',
            'role_name' => 'me flow role',
            'is_preset' => false,
            'status' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        // 复合主键表（Generated 契约：写操作一律 where 两列显式定位，create() 的
        // returning id 语义在 PG 上即 42703）——走 query builder 插入
        DB::table('tenant_member_role')->insert(['member_id' => (string) $member->id, 'role_id' => $roleId]);

        $menu = function (string $clientId, string $parentId, string $title, int $type, int $sortOrder) use ($now): SysMenu {
            $row = new SysMenu([
                'id' => (string) Str::uuid(),
                'client_id' => $clientId,
                'parent_id' => $parentId,
                'title' => $title,
                'type' => $type,
                'path' => "/{$title}",
                'component' => null,
                'perms' => null,
                'icon' => null,
                'sort_order' => $sortOrder,
                'status' => 1,
                'created_at' => $now,
            ]);
            $row->save();

            return $row;
        };

        $aMenu = $menu('tree-client-a', SysMenu::ROOT_MENU_ID, 'A-Menu', 1, 1);
        $bMenu = $menu('tree-client-a', SysMenu::ROOT_MENU_ID, 'B-Menu', 1, 2);
        $cMenu = $menu('tree-client-a', $aMenu->id, 'C-Menu', 2, 0);
        // 孤儿：parent 指向不在授权集的菜单 id（sort_order 0 → 提升后排根层最前）
        $orphan = $menu('tree-client-a', (string) Str::uuid(), 'Orphan-Menu', 3, 0);
        $dMenu = $menu('tree-client-b', SysMenu::ROOT_MENU_ID, 'D-Menu', 1, 1);

        // 授权集 = 五条全授（孤儿本体在授权集、其 parent 不在——提升语义前提）
        foreach ([$aMenu, $bMenu, $cMenu, $orphan, $dMenu] as $granted) {
            DB::table('sys_role_menu')->insert(['role_id' => $roleId, 'menu_id' => (string) $granted->id]);
        }
    }
}
