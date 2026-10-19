<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\OauthClient;
use App\Models\SysMenu;
use App\Models\SysRole;
use App\Models\SysRoleMenu;
use App\Models\SysUser;
use App\Models\Tenant;
use App\Models\TenantMember;
use App\Models\TenantMemberRole;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\IntegrationSupport;
use Tests\TestCase;

// tenant 面 16 op 集成测试（真库 saas_test）：rails 集成 tenant 段镜像 + brief 增量——
// 成员 CRUD / 状态过滤 DB 级 / 排序 createdAt DESC,id ASC / 跨租户 404 不泄露存在性 /
// 角色 clientId 参数被忽略 / RoleMenuGrant 聚合形状（menuIds 升序、updatedAt=sys_role.updated_at）。
final class TenantFlowTest extends TestCase
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

    // ---- tenant-members（8 op） ----

    // rails render_paginated 信封镜像：{ items, page, pageSize, total } camelCase
    #[Test]
    public function tenant_members_list_returns_family_pagination_envelope(): void
    {
        $response = $this->getJson(
            "/api/v1/tenants/{$this->tenantId}/members?page=0&pageSize=2",
            $this->authHeader($this->token)
        );

        self::assertSame(200, $response->status());
        $body = $response->json();
        self::assertSame(['items', 'page', 'pageSize', 'total'], array_keys($body));
        self::assertSame(0, $body['page']);
        self::assertSame(2, $body['pageSize']);
        self::assertIsArray($body['items']);
        self::assertCount(2, $body['items']);
        $first = $body['items'][0];
        foreach (['id', 'tenantId', 'username', 'email', 'status', 'roleIds', 'createdAt', 'updatedAt'] as $key) {
            self::assertArrayHasKey($key, $first);
        }
    }

    // rails 'tenant members list filters by status db-side' 镜像 + DB 级计数：
    // status 过滤在 SQL（total=过滤后计数），造一行 suspended 不出现在 active 集
    #[Test]
    public function tenant_members_list_filters_by_status_db_side(): void
    {
        $suspendedUserId = $this->makeUser('tf-suspended-'.Str::random(4), status: 3);
        $this->makeMember($this->tenantId, $suspendedUserId, 3);
        $activeBefore = TenantMember::query()
            ->where('tenant_id', $this->tenantId)->where('status', 1)->count();

        $response = $this->getJson(
            "/api/v1/tenants/{$this->tenantId}/members?status=active",
            $this->authHeader($this->token)
        );

        self::assertSame(200, $response->status(), $response->getContent());
        $body = $response->json();
        self::assertSame($activeBefore, $body['total'], $response->getContent());
        foreach ($body['items'] as $item) {
            self::assertSame('active', $item['status']);
        }
        $suspendedUsername = SysUser::query()->where('id', $suspendedUserId)->firstOrFail()->username;
        self::assertNotContains($suspendedUsername, array_column($body['items'], 'username'));

        // 反向过滤：status=suspended 含造出的那行且全为 suspended（种子里 bob 本就挂起，不断言精确 total）
        $suspended = $this->getJson(
            "/api/v1/tenants/{$this->tenantId}/members?status=suspended",
            $this->authHeader($this->token)
        )->json();
        self::assertContains($suspendedUserId, array_column($suspended['items'], 'id'));
        foreach ($suspended['items'] as $item) {
            self::assertSame('suspended', $item['status']);
        }
    }

    // rails filtered_member_scope order(created_at: :desc, id: :asc) 镜像：
    // 新在前；created_at 平局按 member.id 升序（视图 id=user id，排序锚是 member 行）
    #[Test]
    public function tenant_members_list_orders_created_at_desc_then_id_asc(): void
    {
        $older = $this->makeMemberWithUser('tf-old', Date::now()->subSeconds(30));
        $newer = $this->makeMemberWithUser('tf-new', Date::now()->subSeconds(5));
        $tieA = $this->makeMemberWithUser('tf-tie', Date::now()->subSeconds(10));
        $tieB = $this->makeMemberWithUser('tf-tie', Date::now()->subSeconds(10));
        $tieLow = strcmp($tieA['memberId'], $tieB['memberId']) < 0 ? $tieA : $tieB;
        $tieHigh = $tieLow === $tieA ? $tieB : $tieA;

        $response = $this->getJson(
            "/api/v1/tenants/{$this->tenantId}/members?pageSize=100",
            $this->authHeader($this->token)
        );
        self::assertSame(200, $response->status(), $response->getContent());
        $items = $response->json('items');
        self::assertIsArray($items);

        // 视图 id = sys_user.id；条目顺序由 member 行排序决定
        $ids = array_column($items, 'id');
        self::assertContains($older['userId'], $ids, $response->getContent());
        self::assertContains($newer['userId'], $ids, $response->getContent());
        self::assertLessThan(
            array_search($older['userId'], $ids, true),
            array_search($newer['userId'], $ids, true),
            $response->getContent()
        );
        // 平局组相邻且 id 升序
        $lowPos = array_search($tieLow['userId'], $ids, true);
        self::assertSame($lowPos + 1, array_search($tieHigh['userId'], $ids, true), $response->getContent());
    }

    // rails create_tenant_user 镜像：扁平视图 + sys_user(plain: 密码,status 1) + member(status 1)
    #[Test]
    public function create_tenant_user_returns_flat_view_and_persists_pair(): void
    {
        $username = 'tf-created-'.Str::random(4);
        $response = $this->postJson(
            "/api/v1/tenants/{$this->tenantId}/members",
            ['username' => $username, 'password' => 'password123', 'email' => "{$username}@x.io"],
            $this->authHeader($this->token)
        );

        self::assertSame(200, $response->status());
        $body = $response->json();
        $keys = array_keys($body);
        sort($keys);
        self::assertSame(
            ['createdAt', 'email', 'id', 'roleIds', 'status', 'tenantId', 'updatedAt', 'username'],
            $keys
        );
        self::assertSame($username, $body['username']);
        self::assertSame("{$username}@x.io", $body['email']);
        self::assertSame('active', $body['status']);
        self::assertSame([], $body['roleIds']);
        self::assertSame($this->tenantId, $body['tenantId']);

        $user = SysUser::query()->where('username', $username)->firstOrFail();
        self::assertSame('plain:password123', (string) $user->password);
        self::assertSame(1, (int) $user->status);
        $member = TenantMember::query()->where('user_id', (string) $user->id)->firstOrFail();
        self::assertSame($this->tenantId, (string) $member->tenant_id);
        self::assertSame(1, (int) $member->status);
        self::assertSame($username, (string) $member->member_name);
        self::assertFalse((bool) $member->is_owner);
    }

    // rails admin_me_test 'create tenant user with short password is rejected 400' 1:1
    #[Test]
    public function create_tenant_user_with_short_password_is_400_and_no_user_row(): void
    {
        $username = 'tf-shortpw-'.Str::random(4);
        $response = $this->postJson(
            "/api/v1/tenants/{$this->tenantId}/members",
            ['username' => $username, 'email' => "{$username}@x.io", 'password' => 'short7'],
            $this->authHeader($this->token)
        );

        self::assertSame(400, $response->status());
        self::assertSame('BAD_REQUEST', $response->json('code'));
        self::assertSame(0, SysUser::query()->where('username', $username)->count());
    }

    // rails %i[username password email].each blank? → ArgumentError 400 镜像
    #[Test]
    public function create_tenant_user_missing_required_field_is_400(): void
    {
        foreach (['username', 'password', 'email'] as $missing) {
            $payload = ['username' => 'u-'.Str::random(4), 'password' => 'password123', 'email' => 'a@x.io'];
            unset($payload[$missing]);

            $response = $this->postJson(
                "/api/v1/tenants/{$this->tenantId}/members",
                $payload,
                $this->authHeader($this->token)
            );

            self::assertSame(400, $response->status(), "missing {$missing} must 400");
            self::assertSame('BAD_REQUEST', $response->json('code'));
        }
    }

    // rails locate_member 镜像：path {userId} 经 (tenant_id,user_id) 解析 member 行；
    // 合法 uuid 查无 → 404（不泄露）；跨租户存在的 user 经本租户 path → 404 不泄露存在性
    #[Test]
    public function get_tenant_user_unknown_or_cross_tenant_is_404(): void
    {
        $response = $this->getJson(
            "/api/v1/tenants/{$this->tenantId}/members/".Str::uuid(),
            $this->authHeader($this->token)
        );
        self::assertSame(404, $response->status());
        self::assertSame('NOT_FOUND', $response->json('code'));

        // user 在别的租户有 membership，但不在本租户 → 404（非 403）
        $otherTenantId = $this->makeTenant('tf-get-404');
        $userId = $this->makeUser('tf-other-tenant-'.Str::random(4));
        $this->makeMember($otherTenantId, $userId, 1);

        $cross = $this->getJson(
            "/api/v1/tenants/{$this->tenantId}/members/{$userId}",
            $this->authHeader($this->token)
        );
        self::assertSame(404, $cross->status());
        self::assertSame('NOT_FOUND', $cross->json('code'));
    }

    // rails update_tenant_user 镜像：契约已删 status 字段，只更新 user 行 email/mobile
    #[Test]
    public function update_tenant_user_updates_email_mobile_and_ignores_status(): void
    {
        $userId = $this->makeUser('tf-update-'.Str::random(4), email: 'old@x.io');
        $memberId = $this->makeMember($this->tenantId, $userId, 1);

        $response = $this->patchJson(
            "/api/v1/tenants/{$this->tenantId}/members/{$userId}",
            ['email' => 'new@x.io', 'mobile' => '139'.Str::random(8), 'status' => 3],
            $this->authHeader($this->token)
        );

        self::assertSame(200, $response->status(), $response->getContent());
        $body = $response->json();
        self::assertSame('new@x.io', $body['email']);
        self::assertArrayNotHasKey('mobile', $body);
        // 扁平视图无 mobile 键（rails flat_user_view 同形）——mobile 落库效果查 DB
        $updated = SysUser::query()->where('id', $userId)->firstOrFail();
        self::assertNotNull($updated->mobile);
        // status 字段契约已删：body 里的 status 不生效，视图 status 读 member 行
        self::assertSame('active', $body['status']);
        self::assertSame(1, (int) $updated->status);
        self::assertSame($userId, $body['id']);
    }

    // rails change_tenant_user_status 镜像：member.status 与 sys_user.status 双写同值
    #[Test]
    public function change_tenant_user_status_dual_writes_member_and_user_rows(): void
    {
        $userId = $this->makeUser('tf-status-'.Str::random(4));
        $this->makeMember($this->tenantId, $userId, 1);

        $response = $this->patchJson(
            "/api/v1/tenants/{$this->tenantId}/members/{$userId}/status",
            ['status' => 'suspended'],
            $this->authHeader($this->token)
        );

        self::assertSame(200, $response->status());
        self::assertSame('suspended', $response->json('status'));
        self::assertSame(3, (int) TenantMember::query()->where('user_id', $userId)->firstOrFail()->status);
        self::assertSame(3, (int) SysUser::query()->where('id', $userId)->firstOrFail()->status);
    }

    // rails assign_tenant_member_roles 镜像：只接受本租户 sys_role 的 roleId，
    // 外来/未知静默忽略；全删再插（全量覆盖语义）
    #[Test]
    public function assign_roles_accepts_only_own_tenant_roles(): void
    {
        $userId = $this->makeUser('tf-assign-'.Str::random(4));
        $this->makeMember($this->tenantId, $userId, 1);
        $ownRoleId = $this->makeRole($this->tenantId);
        $otherRoleId = $this->makeRole($this->makeTenant('tf-assign-other'));

        $response = $this->putJson(
            "/api/v1/tenants/{$this->tenantId}/members/{$userId}/roles",
            ['roleIds' => [$ownRoleId, $otherRoleId, (string) Str::uuid()]],
            $this->authHeader($this->token)
        );

        self::assertSame(200, $response->status());
        self::assertSame([$ownRoleId], $response->json('roleIds'));
        self::assertSame(
            [$ownRoleId],
            TenantMemberRole::query()->where('member_id', TenantMember::query()->where('user_id', $userId)->firstOrFail()->id)
                ->pluck('role_id')->all()
        );
    }

    // rails delete_tenant_user 镜像：删 member_role 绑定 + member 行；user 行不删；204
    #[Test]
    public function delete_tenant_user_removes_bindings_and_member_but_keeps_user(): void
    {
        $userId = $this->makeUser('tf-del-'.Str::random(4));
        $memberId = $this->makeMember($this->tenantId, $userId, 1);
        $roleId = $this->makeRole($this->tenantId);
        DB::table('tenant_member_role')->insert(['member_id' => $memberId, 'role_id' => $roleId]);

        $response = $this->deleteJson(
            "/api/v1/tenants/{$this->tenantId}/members/{$userId}",
            [],
            $this->authHeader($this->token)
        );

        self::assertSame(204, $response->status(), $response->getContent());
        self::assertSame(0, TenantMember::query()->where('id', $memberId)->count());
        self::assertSame(0, TenantMemberRole::query()->where('member_id', $memberId)->count());
        self::assertSame(1, SysUser::query()->where('id', $userId)->count());

        $gone = $this->getJson(
            "/api/v1/tenants/{$this->tenantId}/members/{$userId}",
            $this->authHeader($this->token)
        );
        self::assertSame(404, $gone->status());
    }

    // rails invite_tenant_user 镜像：建真 sys_user(username=email,password 空,status=2)；
    // member status=1(active)；嵌套视图 {member,user,roles}
    #[Test]
    public function invite_tenant_user_creates_invited_user_and_active_member(): void
    {
        $email = 'tf-invite-'.Str::random(4).'@x.io';
        $response = $this->postJson(
            "/api/v1/tenants/{$this->tenantId}/members/invitations",
            ['email' => $email],
            $this->authHeader($this->token)
        );

        self::assertSame(200, $response->status());
        $body = $response->json();
        self::assertSame(['member', 'user', 'roles'], array_keys($body));
        self::assertSame([], $body['roles']);
        self::assertSame('active', $body['member']['status']);
        self::assertSame($this->tenantId, $body['member']['tenantId']);
        self::assertSame($email, $body['member']['memberName']);
        self::assertSame('invited', $body['user']['status']);
        self::assertSame($email, $body['user']['username']);
        self::assertSame($email, $body['user']['email']);

        $user = SysUser::query()->where('username', $email)->firstOrFail();
        self::assertSame('', (string) $user->password);
        self::assertSame(2, (int) $user->status);
    }

    // rails invite_tenant_user email 空 → ArgumentError 400 镜像
    #[Test]
    public function invite_tenant_user_without_email_is_400(): void
    {
        $response = $this->postJson(
            "/api/v1/tenants/{$this->tenantId}/members/invitations",
            ['email' => '   '],
            $this->authHeader($this->token)
        );

        self::assertSame(400, $response->status());
        self::assertSame('BAD_REQUEST', $response->json('code'));
    }

    // C-1 反向面（Round 1 评审）：JWT tenant claim ≠ path tenantId → guard 403 拒绝。
    // 8 op 全覆盖——guard 缺失时 create/invite 会 200 跨租户写、locate 组会 404 泄露语义漂移。
    #[Test]
    public function cross_tenant_token_is_403_on_all_member_ops(): void
    {
        $otherTenantId = $this->makeTenant('tf-guard');
        $userId = $this->makeUser('tf-guard-user-'.Str::random(4));
        $this->makeMember($otherTenantId, $userId, 1);
        $base = "/api/v1/tenants/{$otherTenantId}";
        $h = $this->authHeader($this->token);

        $responses = [
            'list' => $this->getJson("{$base}/members", $h),
            'create' => $this->postJson("{$base}/members", [
                'username' => 'tf-guard-new', 'password' => 'password123', 'email' => 'tf-guard-new@x.io',
            ], $h),
            'get' => $this->getJson("{$base}/members/{$userId}", $h),
            'update' => $this->patchJson("{$base}/members/{$userId}", ['email' => 'n@x.io'], $h),
            'delete' => $this->deleteJson("{$base}/members/{$userId}", [], $h),
            'status' => $this->patchJson("{$base}/members/{$userId}/status", ['status' => 'suspended'], $h),
            'assign' => $this->putJson("{$base}/members/{$userId}/roles", ['roleIds' => []], $h),
            'invite' => $this->postJson("{$base}/members/invitations", ['email' => 'tf-guard-inv@x.io'], $h),
        ];
        foreach ($responses as $op => $response) {
            self::assertSame(403, $response->status(), "{$op} must 403, got {$response->status()}");
        }
        self::assertSame('FORBIDDEN', $responses['list']->json('code'));

        // 守卫在先：跨租户行零改动（create/invite 未落行、status 双写未发生）
        self::assertSame(1, TenantMember::query()->where('tenant_id', $otherTenantId)->count());
        self::assertSame(0, SysUser::query()->where('username', 'tf-guard-new')->count());
        self::assertSame(1, (int) TenantMember::query()->where('user_id', $userId)->firstOrFail()->status);
    }

    // M-1（Round 1 评审）：缺/空白 status → 400（rails params.require(:status) 镜像），行不被改
    #[Test]
    public function change_tenant_user_status_missing_or_blank_is_400(): void
    {
        $userId = $this->makeUser('tf-status400-'.Str::random(4));
        $this->makeMember($this->tenantId, $userId, 1);
        $url = "/api/v1/tenants/{$this->tenantId}/members/{$userId}/status";
        $h = $this->authHeader($this->token);

        $missing = $this->patchJson($url, [], $h);
        self::assertSame(400, $missing->status());
        self::assertSame('BAD_REQUEST', $missing->json('code'));

        $blank = $this->patchJson($url, ['status' => '   '], $h);
        self::assertSame(400, $blank->status());
        self::assertSame('BAD_REQUEST', $blank->json('code'));

        // 400 路径不落库
        self::assertSame(1, (int) TenantMember::query()->where('user_id', $userId)->firstOrFail()->status);
        self::assertSame(1, (int) SysUser::query()->where('id', $userId)->firstOrFail()->status);
    }

    // ---- tenant-roles（5 op） ----

    // rails create_sys_role 镜像：DTO 形状 + isPreset 缺省 false + status 1
    #[Test]
    public function create_sys_role_returns_dto_and_persists(): void
    {
        // sys_role.client_id 有 FK → oauth_client：先造引用行（rails 同为 RecordInvalid/InvalidForeignKey
        // 400 组，缺行即 400 constraint violation，非本用例关注点）
        $this->ensureClient('tf-client');
        $response = $this->postJson(
            "/api/v1/tenants/{$this->tenantId}/roles",
            ['clientId' => 'tf-client', 'roleCode' => 'tf_role', 'roleName' => 'TF Role', 'description' => 'd'],
            $this->authHeader($this->token)
        );

        self::assertSame(200, $response->status());
        $body = $response->json();
        $keys = array_keys($body);
        sort($keys);
        self::assertSame(
            ['clientId', 'createdAt', 'description', 'id', 'isPreset', 'roleCode', 'roleName', 'status', 'tenantId', 'updatedAt'],
            $keys
        );
        self::assertSame($this->tenantId, $body['tenantId']);
        self::assertSame('tf-client', $body['clientId']);
        self::assertSame('tf_role', $body['roleCode']);
        self::assertFalse($body['isPreset']);
        self::assertSame(1, $body['status']);

        $role = SysRole::query()->where('id', $body['id'])->firstOrFail();
        self::assertSame('tf_role', (string) $role->role_code);
        self::assertSame(1, (int) $role->status);
    }

    // rails %i[clientId roleCode roleName] blank? → ArgumentError 400 镜像
    #[Test]
    public function create_sys_role_missing_required_field_is_400(): void
    {
        foreach (['clientId', 'roleCode', 'roleName'] as $missing) {
            $payload = ['clientId' => 'c', 'roleCode' => 'rc_'.Str::random(4), 'roleName' => 'r'];
            unset($payload[$missing]);

            $response = $this->postJson(
                "/api/v1/tenants/{$this->tenantId}/roles",
                $payload,
                $this->authHeader($this->token)
            );

            self::assertSame(400, $response->status(), "missing {$missing} must 400");
            self::assertSame('BAD_REQUEST', $response->json('code'));
        }
    }

    // rails 'clientId 参数被忽略（镜像）'：list 不按 query clientId 过滤
    #[Test]
    public function list_sys_roles_ignores_client_id_query_param(): void
    {
        $this->makeRole($this->tenantId, 'tf-roles-a');
        $this->makeRole($this->tenantId, 'tf-roles-b');
        $total = SysRole::query()->where('tenant_id', $this->tenantId)->count();

        $response = $this->getJson(
            "/api/v1/tenants/{$this->tenantId}/roles?clientId=tf-roles-a&pageSize=100",
            $this->authHeader($this->token)
        );

        self::assertSame(200, $response->status());
        $body = $response->json();
        self::assertSame($total, $body['total']);
        $clientIds = array_unique(array_column($body['items'], 'clientId'));
        self::assertContains('tf-roles-a', $clientIds);
        self::assertContains('tf-roles-b', $clientIds);
    }

    // rails locate_role 镜像：role.tenant_id != path tenantId → 404（不泄露跨租户存在性，非 403）
    #[Test]
    public function get_sys_role_from_other_tenant_is_404_not_403(): void
    {
        $otherRoleId = $this->makeRole($this->makeTenant('tf-role-404'));

        $response = $this->getJson(
            "/api/v1/tenants/{$this->tenantId}/roles/{$otherRoleId}",
            $this->authHeader($this->token)
        );

        self::assertSame(404, $response->status());
        self::assertSame('NOT_FOUND', $response->json('code'));
    }

    // rails update_sys_role 镜像：只应用 roleName/description（status/roleCode 不应用）
    #[Test]
    public function update_sys_role_applies_only_role_name_and_description(): void
    {
        $roleId = $this->makeRole($this->tenantId, description: 'old');

        $response = $this->patchJson(
            "/api/v1/tenants/{$this->tenantId}/roles/{$roleId}",
            ['roleName' => 'Renamed', 'description' => 'new-desc', 'roleCode' => 'hacked', 'status' => 0],
            $this->authHeader($this->token)
        );

        self::assertSame(200, $response->status());
        $body = $response->json();
        self::assertSame('Renamed', $body['roleName']);
        self::assertSame('new-desc', $body['description']);
        self::assertSame($this->roleCodeOf($roleId), $body['roleCode']);
        self::assertSame(1, $body['status']);
    }

    // rails delete_sys_role 镜像：删 role_menu + member_role 绑定 + role 行；204
    #[Test]
    public function delete_sys_role_cascades_grants_and_bindings(): void
    {
        $roleId = $this->makeRole($this->tenantId);
        $memberId = $this->aliceMemberId();
        // sys_role_menu.menu_id 有 FK → sys_menu：造真菜单行
        $this->ensureClient('tf-cascade-client');
        $menuId = (string) Str::uuid();
        (new SysMenu([
            'id' => $menuId,
            'client_id' => 'tf-cascade-client',
            'parent_id' => SysMenu::ROOT_MENU_ID,
            'title' => 'TF-Cascade',
            'type' => 1,
            'path' => '/tf-cascade',
            'component' => null,
            'perms' => null,
            'icon' => null,
            'sort_order' => 0,
            'status' => 1,
            'created_at' => Date::now(),
        ]))->save();
        DB::table('sys_role_menu')->insert(['role_id' => $roleId, 'menu_id' => $menuId]);
        DB::table('tenant_member_role')->insert(['member_id' => $memberId, 'role_id' => $roleId]);

        $response = $this->deleteJson(
            "/api/v1/tenants/{$this->tenantId}/roles/{$roleId}",
            [],
            $this->authHeader($this->token)
        );

        self::assertSame(204, $response->status(), $response->getContent());
        self::assertSame(0, SysRole::query()->where('id', $roleId)->count());
        self::assertSame(0, SysRoleMenu::query()->where('role_id', $roleId)->count());
        self::assertSame(0, TenantMemberRole::query()->where('role_id', $roleId)->count());
    }

    // C-1 反向面（Round 1 评审）：roles 5 op + role-menus 3 op 逐 op 守卫复核（回归面钉死）
    #[Test]
    public function cross_tenant_token_is_403_on_role_and_role_menu_ops(): void
    {
        $otherTenantId = $this->makeTenant('tf-role-guard');
        $roleId = $this->makeRole($otherTenantId);
        $base = "/api/v1/tenants/{$otherTenantId}";
        $h = $this->authHeader($this->token);

        $responses = [
            'roles.list' => $this->getJson("{$base}/roles", $h),
            'roles.create' => $this->postJson("{$base}/roles", [
                'clientId' => 'tf-guard-client', 'roleCode' => 'tf_guard', 'roleName' => 'Guard',
            ], $h),
            'roles.get' => $this->getJson("{$base}/roles/{$roleId}", $h),
            'roles.update' => $this->patchJson("{$base}/roles/{$roleId}", ['roleName' => 'Hacked'], $h),
            'roles.delete' => $this->deleteJson("{$base}/roles/{$roleId}", [], $h),
            'menus.list' => $this->getJson("{$base}/roles/{$roleId}/menus", $h),
            'menus.set' => $this->putJson("{$base}/roles/{$roleId}/menus", ['menuIds' => []], $h),
            'menus.clear' => $this->deleteJson("{$base}/roles/{$roleId}/menus", [], $h),
        ];
        foreach ($responses as $op => $response) {
            self::assertSame(403, $response->status(), "{$op} must 403, got {$response->status()}");
        }

        // 行零改动（roles.create 未落行、roles.update 未改名、menus.set 未动 grant）
        self::assertSame(1, SysRole::query()->where('id', $roleId)->count());
        self::assertSame('TF Role', (string) SysRole::query()->where('id', $roleId)->firstOrFail()->role_name);
        self::assertSame(0, SysRole::query()->where('role_code', 'tf_guard')->count());
    }

    // ---- tenant-role-menus（3 op） ----

    // rails list_sys_role_menus 镜像：RoleMenuGrant 聚合形状——menuIds 升序、updatedAt=sys_role.updated_at
    #[Test]
    public function list_role_menus_returns_aggregate_with_menu_ids_asc(): void
    {
        ['roleId' => $roleId] = $this->makeRoleWithGrants();

        $response = $this->getJson(
            "/api/v1/tenants/{$this->tenantId}/roles/{$roleId}/menus",
            $this->authHeader($this->token)
        );

        self::assertSame(200, $response->status());
        $body = $response->json();
        $keys = array_keys($body);
        sort($keys);
        self::assertSame(
            ['menuIds', 'roleId', 'tenantId', 'updatedAt'],
            $keys
        );
        self::assertSame($roleId, $body['roleId']);
        self::assertSame($this->tenantId, $body['tenantId']);
        $menuIds = SysRoleMenu::query()->where('role_id', $roleId)->pluck('menu_id')->all();
        $expected = $menuIds;
        sort($expected, SORT_STRING);
        self::assertSame($expected, $body['menuIds']);
        self::assertSame(
            SysRole::query()->where('id', $roleId)->firstOrFail()->updated_at->toISOString(),
            $body['updatedAt']
        );
    }

    // rails set_sys_role_menus 镜像：全量替换（差量实现）、请求集去重排序、
    // 响应直接从请求集构造（不回读）、touch role → updatedAt 刷新
    #[Test]
    public function set_role_menus_replaces_grants_dedups_sorts_and_touches_role(): void
    {
        ['roleId' => $roleId, 'menuIds' => [$m1, $m2, $m3]] = $this->makeRoleWithGrants();
        $before = SysRole::query()->where('id', $roleId)->firstOrFail()->updated_at;

        $response = $this->putJson(
            "/api/v1/tenants/{$this->tenantId}/roles/{$roleId}/menus",
            ['menuIds' => [$m2, $m1, $m2]],
            $this->authHeader($this->token)
        );

        self::assertSame(200, $response->status(), $response->getContent());
        $body = $response->json();
        self::assertSame([$m1, $m2], $body['menuIds'], $response->getContent());
        self::assertSame($roleId, $body['roleId']);
        self::assertSame($this->tenantId, $body['tenantId']);
        // 差量实现落库：m3 被删、m1/m2 在
        $rows = SysRoleMenu::query()->where('role_id', $roleId)->orderBy('menu_id')->pluck('menu_id')->all();
        self::assertSame([$m1, $m2], $rows);
        // touch role：updatedAt 刷新（sys_role.updated_at 是 timestamp(0) 秒级精度，
        // 同秒内操作 rails touch 同样不前进——断言不早于操作前 + 与库值逐字一致）
        $after = SysRole::query()->where('id', $roleId)->firstOrFail()->updated_at;
        self::assertTrue($after->greaterThanOrEqualTo($before), "before={$before} after={$after}");
        self::assertSame($after->toISOString(), $body['updatedAt'], $response->getContent());
    }

    // rails set 空集分支镜像：全删 + 响应 menuIds []
    #[Test]
    public function set_role_menus_with_empty_array_clears_grants(): void
    {
        ['roleId' => $roleId] = $this->makeRoleWithGrants();

        $response = $this->putJson(
            "/api/v1/tenants/{$this->tenantId}/roles/{$roleId}/menus",
            ['menuIds' => []],
            $this->authHeader($this->token)
        );

        self::assertSame(200, $response->status());
        self::assertSame([], $response->json('menuIds'));
        self::assertSame(0, SysRoleMenu::query()->where('role_id', $roleId)->count());
    }

    // rails clear_sys_role_menus 镜像：bulk 全删 204；roleId 不存在也 204（不预查）
    #[Test]
    public function clear_role_menus_is_204_even_for_unknown_role(): void
    {
        ['roleId' => $roleId] = $this->makeRoleWithGrants();

        $known = $this->deleteJson(
            "/api/v1/tenants/{$this->tenantId}/roles/{$roleId}/menus",
            [],
            $this->authHeader($this->token)
        );
        self::assertSame(204, $known->status(), $known->getContent());
        self::assertSame(0, SysRoleMenu::query()->where('role_id', $roleId)->count());

        $unknown = $this->deleteJson(
            "/api/v1/tenants/{$this->tenantId}/roles/".Str::uuid().'/menus',
            [],
            $this->authHeader($this->token)
        );
        self::assertSame(204, $unknown->status(), $unknown->getContent());
    }

    // ---- 数据工厂（事务内，回滚不留痕） ----

    private function aliceMemberId(): string
    {
        return (string) TenantMember::query()
            ->where('tenant_id', $this->tenantId)
            ->where('user_id', SysUser::query()->where('username', self::DEV_USER)->firstOrFail()->id)
            ->firstOrFail()->id;
    }

    private function makeUser(string $username, ?string $email = null, int $status = 1): string
    {
        $now = Date::now();
        $userId = (string) Str::uuid();
        SysUser::query()->create([
            'id' => $userId,
            'username' => $username,
            'password' => 'plain:password123',
            'email' => $email ?? "{$username}@x.io",
            'status' => $status,
            'failed_attempts' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $userId;
    }

    private function makeMember(string $tenantId, string $userId, int $status): string
    {
        $now = Date::now();
        $memberId = (string) Str::uuid();
        TenantMember::query()->create([
            'id' => $memberId,
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'member_name' => (string) SysUser::query()->where('id', $userId)->firstOrFail()->username,
            'is_owner' => false,
            'status' => $status,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $memberId;
    }

    /** member + user 一起造，created_at 可控（排序用例） */
    private function makeMemberWithUser(string $usernamePrefix, mixed $createdAt): array
    {
        $now = Date::now();
        $userId = (string) Str::uuid();
        $username = $usernamePrefix.'-'.Str::random(4);
        SysUser::query()->create([
            'id' => $userId,
            'username' => $username,
            'password' => 'plain:password123',
            'email' => "{$username}@x.io",
            'status' => 1,
            'failed_attempts' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $memberId = (string) Str::uuid();
        TenantMember::query()->create([
            'id' => $memberId,
            'tenant_id' => $this->tenantId,
            'user_id' => $userId,
            'member_name' => $username,
            'is_owner' => false,
            'status' => 1,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        return ['memberId' => $memberId, 'userId' => $userId];
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

    /** sys_role.client_id / sys_menu.client_id 有 FK → oauth_client——角色/菜单工厂前置（事务内回滚不留痕） */
    private function ensureClient(string $clientId): void
    {
        if (OauthClient::query()->where('client_id', $clientId)->exists()) {
            return;
        }
        $now = Date::now();
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

    private function makeRole(string $tenantId, string $clientId = 'tf-role-client', ?string $description = null): string
    {
        $this->ensureClient($clientId);
        $now = Date::now();
        $roleId = (string) Str::uuid();
        SysRole::query()->create([
            'id' => $roleId,
            'tenant_id' => $tenantId,
            'client_id' => $clientId,
            'role_code' => 'tf_role_'.Str::random(6),
            'role_name' => 'TF Role',
            'description' => $description,
            'is_preset' => false,
            'status' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $roleId;
    }

    private function roleCodeOf(string $roleId): string
    {
        return (string) SysRole::query()->where('id', $roleId)->firstOrFail()->role_code;
    }

    /** 角色 + 三条菜单 + 乱序 grant；回 roleId 与 menuIds（m1<m2<m3 为排序后顺序） */
    private function makeRoleWithGrants(): array
    {
        $now = Date::now();
        $clientId = 'tf-grant-'.Str::random(4);
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
        $roleId = $this->makeRole($this->tenantId, $clientId);

        $menuIds = [];
        foreach ([1, 2, 3] as $i) {
            $menuId = (string) Str::uuid();
            $menuIds[] = $menuId;
            $menu = new SysMenu([
                'id' => $menuId,
                'client_id' => $clientId,
                'parent_id' => SysMenu::ROOT_MENU_ID,
                'title' => "TF-Menu-{$i}",
                'type' => 1,
                'path' => "/tf-menu-{$i}",
                'component' => null,
                'perms' => null,
                'icon' => null,
                'sort_order' => $i,
                'status' => 1,
                'created_at' => $now,
            ]);
            $menu->save();
        }

        // 乱序插入（m3, m1, m2），list 端 orderBy(menu_id) 兜底升序
        foreach ([2, 0, 1] as $order) {
            DB::table('sys_role_menu')->insert(['role_id' => $roleId, 'menu_id' => $menuIds[$order]]);
        }

        $sorted = $menuIds;
        sort($sorted, SORT_STRING);

        return ['roleId' => $roleId, 'menuIds' => $sorted];
    }
}
