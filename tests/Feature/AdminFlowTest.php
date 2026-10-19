<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\OauthClient;
use App\Models\SysUser;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\IntegrationSupport;
use Tests\TestCase;

// admin 面 11 op 集成测试（真库 saas_test）：AdminClients 6 + AdminTenants 5。
// rails admin_clients_controller.rb / admin_tenants_controller.rb +
// test/integration/admin_me_test.rb admin 段镜像用例集——寻址用 oauth_client.client_id
// 业务串非 UUID / validity 缺省 3600/86400 / 创建返回 200 非 201 / 分页信封 /
// 驼峰渲染 null 剔除 / 租户 CRUD :id 是行 UUID（含 delete op 前置甄别，T8 教训）。
final class AdminFlowTest extends TestCase
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

    // ---- admin-clients（6 op；rails include TenantGuard 无租户校验——JWT 必需即守卫全貌） ----

    // rails 'admin clients list returns family pagination envelope in camelCase' 镜像：
    // 信封 {items,page,pageSize,total} + pageSize 生效 + items 键 camelCase
    #[Test]
    public function admin_clients_list_returns_family_pagination_envelope(): void
    {
        $a = $this->makeClient('tf-ac-list-a', scopes: 'read');
        $b = $this->makeClient('tf-ac-list-b');
        $this->makeClient('tf-ac-list-c');

        $response = $this->getJson(
            '/api/v1/admin/clients?page=0&pageSize=2',
            $this->authHeader($this->token)
        );

        self::assertSame(200, $response->status(), $response->getContent());
        $body = $response->json();
        self::assertSame(['items', 'page', 'pageSize', 'total'], array_keys($body));
        self::assertSame(0, $body['page']);
        self::assertSame(2, $body['pageSize']);
        // 种子 client（saas-console 等）+ 本测 3 行 → total >= 3；pageSize=2 切页生效
        self::assertGreaterThanOrEqual(3, $body['total']);
        self::assertCount(2, $body['items']);
        $all = $this->getJson('/api/v1/admin/clients?page=0&pageSize=100', $this->authHeader($this->token))
            ->json();
        $allIds = array_column($all['items'], 'id');
        foreach ([$a, $b, 'tf-ac-list-c'] as $cid) {
            $rowId = (string) OauthClient::query()->where('client_id', $cid)->value('id');
            self::assertContains($rowId, $allIds, $cid);
        }
        // DTO 键集（camelCase；scopes 造非 null 时全键在——null 剔除口径由 create 用例钉）
        $mine = $all['items'][array_search(
            (string) OauthClient::query()->where('client_id', $a)->value('id'),
            $allIds,
            true
        )];
        $keys = array_keys($mine);
        sort($keys);
        self::assertSame(
            ['accessTokenValidity', 'autoApprove', 'clientId', 'clientName', 'createdAt',
                'grantTypes', 'id', 'redirectUris', 'refreshTokenValidity', 'scopes', 'status', 'updatedAt'],
            $keys
        );
        // null 剔除口径：scopes 为 null 的行响应无 scopes 键
        $bRow = $all['items'][array_search(
            (string) OauthClient::query()->where('client_id', $b)->value('id'),
            $allIds,
            true
        )];
        self::assertArrayNotHasKey('scopes', $bRow);
    }

    // rails 'create admin client applies family defaults and returns 200' 镜像：
    // validity 缺省 3600/86400（应用层默认非 DB DEFAULT）、autoApprove false、status 1、200 非 201
    #[Test]
    public function create_admin_client_applies_family_defaults_and_returns_200(): void
    {
        $clientId = 'tf-ac-create-'.Str::random(4);

        $response = $this->postJson(
            '/api/v1/admin/clients',
            [
                'clientId' => $clientId,
                'clientName' => 'Spec',
                'clientSecret' => 's',
                'grantTypes' => 'authorization_code,refresh_token',
                'redirectUris' => 'http://x/cb',
            ],
            $this->authHeader($this->token)
        );

        self::assertSame(200, $response->status(), $response->getContent());
        $body = $response->json();
        self::assertSame($clientId, $body['clientId']);
        self::assertSame(3600, $body['accessTokenValidity']);
        self::assertSame(86400, $body['refreshTokenValidity']);
        self::assertFalse($body['autoApprove']);
        self::assertSame(1, $body['status']);
        self::assertArrayNotHasKey('scopes', $body);
        self::assertArrayHasKey('createdAt', $body);
        self::assertArrayHasKey('updatedAt', $body);

        $row = OauthClient::query()->where('client_id', $clientId)->firstOrFail();
        self::assertSame(1, (int) $row->status);
        self::assertSame(3600, (int) $row->access_token_validity);
        self::assertSame(86400, (int) $row->refresh_token_validity);
        self::assertFalse((bool) $row->auto_approve);
        self::assertNull($row->scopes);
    }

    // rails positive_or 镜像：validity 缺省/<=0/垃圾串 → to_i 非正 → 3600/86400；
    // 正数原样；autoApprove 仅字面 'true'/true 生效
    #[Test]
    public function create_admin_client_validity_positive_or_semantics(): void
    {
        $base = 'tf-ac-validity-'.Str::random(4);
        $cases = [
            'zero' => ['accessTokenValidity' => 0, 'refreshTokenValidity' => 0],
            'garbage' => ['accessTokenValidity' => 'abc', 'refreshTokenValidity' => 'xyz'],
            'negative' => ['accessTokenValidity' => -5, 'refreshTokenValidity' => -1],
        ];
        foreach ($cases as $suffix => $payload) {
            $response = $this->postJson(
                '/api/v1/admin/clients',
                [
                    'clientId' => "{$base}-{$suffix}",
                    'clientName' => 'V',
                    'clientSecret' => 's',
                    'grantTypes' => 'authorization_code',
                    'redirectUris' => 'http://x/cb',
                ] + $payload,
                $this->authHeader($this->token)
            );
            self::assertSame(200, $response->status(), $response->getContent());
            self::assertSame(3600, $response->json('accessTokenValidity'), $suffix);
            self::assertSame(86400, $response->json('refreshTokenValidity'), $suffix);
        }

        $positive = $this->postJson(
            '/api/v1/admin/clients',
            [
                'clientId' => "{$base}-pos",
                'clientName' => 'V',
                'clientSecret' => 's',
                'grantTypes' => 'authorization_code',
                'redirectUris' => 'http://x/cb',
                'accessTokenValidity' => 7200,
                'refreshTokenValidity' => 172800,
                'autoApprove' => true,
                'scopes' => 'read,write',
            ],
            $this->authHeader($this->token)
        );
        self::assertSame(200, $positive->status(), $positive->getContent());
        self::assertSame(7200, $positive->json('accessTokenValidity'));
        self::assertSame(172800, $positive->json('refreshTokenValidity'));
        self::assertTrue($positive->json('autoApprove'));
        self::assertSame('read,write', $positive->json('scopes'));
    }

    // rails 'admin clients requires JWT' 镜像 + 越权反向面：admin 面守卫全貌 = JWT 必需
    // （rails include TenantGuard 无租户校验，无 verify_path_tenant——无 403 面，见报告备案）
    #[Test]
    public function admin_clients_requires_jwt(): void
    {
        self::assertSame(401, $this->getJson('/api/v1/admin/clients')->status());
        self::assertSame(
            401,
            $this->getJson('/api/v1/admin/clients/saas-console', $this->authHeader(' bogus '))->status()
        );
        self::assertSame(
            401,
            $this->postJson('/api/v1/admin/clients', ['clientId' => 'x'])->status()
        );
    }

    // rails 'create client with empty body maps NOT NULL violation to 400' 镜像：
    // 空 body 走 NOT NULL 口子 → 基座 constraint violation 400（500 是分叉）
    #[Test]
    public function create_client_with_empty_body_maps_not_null_violation_to_400(): void
    {
        $response = $this->postJson('/api/v1/admin/clients', [], $this->authHeader($this->token));

        self::assertSame(400, $response->status());
        $body = $response->json();
        self::assertSame('BAD_REQUEST', $body['code']);
        self::assertStringStartsWith('constraint violation', (string) $body['message']);
    }

    // rails 'create client with duplicate clientId maps unique violation to 400' 镜像：
    // uk 撞键 PG 23505 → 400 constraint violation（无 dup 预检，直插镜像）
    #[Test]
    public function create_client_with_duplicate_client_id_maps_unique_violation_to_400(): void
    {
        $cid = 'tf-ac-dup-'.Str::random(4);
        $payload = [
            'clientId' => $cid,
            'clientName' => 'First',
            'clientSecret' => 's',
            'grantTypes' => 'authorization_code',
            'redirectUris' => 'http://x/cb',
        ];
        $first = $this->postJson('/api/v1/admin/clients', $payload, $this->authHeader($this->token));
        self::assertSame(200, $first->status(), $first->getContent());

        // 指纹（本任务新）：基座把 23505 渲染成 400 后 PG 事务已 aborted——savepoint
        // 先于失败语句建立，400 断言后 ROLLBACK TO SAVEPOINT 清 aborted 态再查行数
        // （外层 DatabaseTransactions 事务不动）
        DB::statement('SAVEPOINT tf_ac_dup');

        $second = $this->postJson(
            '/api/v1/admin/clients',
            ['clientName' => 'Dup'] + $payload,
            $this->authHeader($this->token)
        );
        self::assertSame(400, $second->status());
        $body = $second->json();
        self::assertSame('BAD_REQUEST', $body['code']);
        self::assertStringStartsWith('constraint violation', (string) $body['message']);

        DB::statement('ROLLBACK TO SAVEPOINT tf_ac_dup');
        self::assertSame(1, OauthClient::query()->where('client_id', $cid)->count());
    }

    // rails get_client 镜像：寻址 oauth_client.client_id 业务串（非 UUID，不走 400 甄别）；
    // 查无 → 404 NOT_FOUND
    #[Test]
    public function get_admin_client_by_business_client_id_and_unknown_is_404(): void
    {
        $clientId = $this->makeClient('tf-ac-get', clientName: 'TF Get', scopes: 'read');

        $found = $this->getJson("/api/v1/admin/clients/{$clientId}", $this->authHeader($this->token));
        self::assertSame(200, $found->status(), $found->getContent());
        self::assertSame($clientId, $found->json('clientId'));
        self::assertSame('TF Get', $found->json('clientName'));
        self::assertSame('read', $found->json('scopes'));

        $missing = $this->getJson('/api/v1/admin/clients/tf-ac-ghost-'.Str::random(4), $this->authHeader($this->token));
        self::assertSame(404, $missing->status());
        self::assertSame('NOT_FOUND', $missing->json('code'));
    }

    // rails update_client 镜像：只应用 clientName/redirectUris/scopes，其余字段改不动
    #[Test]
    public function update_admin_client_applies_only_whitelisted_fields(): void
    {
        $clientId = $this->makeClient('tf-ac-update', clientName: 'TF Old', scopes: 'old');
        // validity 未造值 → DB DEFAULT（非应用层 3600 缺省，镜像面）；断言「改不动」按原值
        $validityBefore = (int) OauthClient::query()->where('client_id', $clientId)->firstOrFail()
            ->access_token_validity;

        $response = $this->patchJson(
            "/api/v1/admin/clients/{$clientId}",
            [
                'clientName' => 'TF Renamed',
                'redirectUris' => 'http://x/cb2',
                'scopes' => 'read,write',
                'clientSecret' => 'hacked',
                'grantTypes' => 'password',
                'clientId' => 'hacked-id',
                'accessTokenValidity' => 1,
                'autoApprove' => true,
                'status' => 0,
            ],
            $this->authHeader($this->token)
        );

        self::assertSame(200, $response->status(), $response->getContent());
        $body = $response->json();
        self::assertSame('TF Renamed', $body['clientName']);
        self::assertSame('http://x/cb2', $body['redirectUris']);
        self::assertSame('read,write', $body['scopes']);
        self::assertSame($clientId, $body['clientId']);
        self::assertSame($validityBefore, $body['accessTokenValidity']);
        self::assertFalse($body['autoApprove']);
        self::assertSame(1, $body['status']);

        $row = OauthClient::query()->where('client_id', $clientId)->firstOrFail();
        self::assertSame('TF Renamed', (string) $row->client_name);
        self::assertSame('s', (string) $row->client_secret);
        self::assertSame('authorization_code', (string) $row->grant_types);
        self::assertSame($validityBefore, (int) $row->access_token_validity);
        self::assertSame(1, (int) $row->status);
    }

    // rails update_client 空白面：空 body → 不动（update! 分支不进），仍 200 回 DTO
    #[Test]
    public function update_admin_client_empty_body_is_noop_200(): void
    {
        $clientId = $this->makeClient('tf-ac-noop', clientName: 'TF Keep');

        $response = $this->patchJson(
            "/api/v1/admin/clients/{$clientId}",
            [],
            $this->authHeader($this->token)
        );

        self::assertSame(200, $response->status(), $response->getContent());
        self::assertSame('TF Keep', $response->json('clientName'));
    }

    // rails set_client_status 镜像：body {status} 可空——present 才应用（to_i 语义），
    // 缺省/null 不动；恒 200 回 DTO
    #[Test]
    public function set_client_status_applies_only_when_present(): void
    {
        $clientId = $this->makeClient('tf-ac-status');

        $applied = $this->patchJson(
            "/api/v1/admin/clients/{$clientId}/status",
            ['status' => 0],
            $this->authHeader($this->token)
        );
        self::assertSame(200, $applied->status(), $applied->getContent());
        self::assertSame(0, $applied->json('status'));
        self::assertSame(0, (int) OauthClient::query()->where('client_id', $clientId)->firstOrFail()->status);

        // 垃圾串 → to_i 0（rails to_i 语义，非校验拒绝）
        $garbage = $this->patchJson(
            "/api/v1/admin/clients/{$clientId}/status",
            ['status' => 'abc'],
            $this->authHeader($this->token)
        );
        self::assertSame(200, $garbage->status(), $garbage->getContent());
        self::assertSame(0, $garbage->json('status'));

        // 缺省 body → 不动，仍 200
        $noop = $this->patchJson(
            "/api/v1/admin/clients/{$clientId}/status",
            [],
            $this->authHeader($this->token)
        );
        self::assertSame(200, $noop->status(), $noop->getContent());
        self::assertSame(0, $noop->json('status'));
    }

    // rails delete_client 镜像：find_by! 预查——查无 → 404（与 admin-tenants delete 204 分叉）；
    // 已知 → 204 删净。clientId 是 varchar 业务串：非法 uuid 形状的串也是普通查无 → 404，
    // 不触发基座 uuid 甄别（clientId 不在扫描表）、不 22P02
    #[Test]
    public function delete_admin_client_known_204_unknown_404(): void
    {
        $clientId = $this->makeClient('tf-ac-delete');

        $known = $this->deleteJson("/api/v1/admin/clients/{$clientId}", [], $this->authHeader($this->token));
        self::assertSame(204, $known->status(), $known->getContent());
        self::assertSame(0, OauthClient::query()->where('client_id', $clientId)->count());

        $unknown = $this->deleteJson(
            '/api/v1/admin/clients/tf-ac-ghost-del-'.Str::random(4),
            [],
            $this->authHeader($this->token)
        );
        self::assertSame(404, $unknown->status());
        self::assertSame('NOT_FOUND', $unknown->json('code'));

        // 业务串不进 uuid 甄别表：uuid 形状非法的串 = 普通 varchar 查无 → 404 非 400
        $notUuid = $this->deleteJson('/api/v1/admin/clients/not-a-uuid', [], $this->authHeader($this->token));
        self::assertSame(404, $notUuid->status(), $notUuid->getContent());
    }

    // ---- admin-tenants（5 op；:id 是行 UUID，全部 5 op 逐条过 badUuidParam 前置甄别） ----

    // rails list_tenants 镜像：分页信封 + tenant DTO 键集 + status 恒 'active' 字面
    // （springboot toDto 忠实镜像 quirk——写侧 0 也读 'active'，勿修）
    #[Test]
    public function admin_tenants_list_returns_envelope_with_active_literal(): void
    {
        $tenantId = $this->makeTenant('tf-at-list');
        // quirk 钉子：DB status=0 的行响应仍 'active'
        Tenant::query()->where('id', $tenantId)->update(['status' => 0]);

        $response = $this->getJson('/api/v1/admin/tenants?page=0&pageSize=100', $this->authHeader($this->token));

        self::assertSame(200, $response->status(), $response->getContent());
        $body = $response->json();
        self::assertSame(['items', 'page', 'pageSize', 'total'], array_keys($body));
        self::assertSame(0, $body['page']);
        self::assertSame(100, $body['pageSize']);
        self::assertGreaterThanOrEqual(1, $body['total']);
        $row = null;
        foreach ($body['items'] as $item) {
            if ($item['id'] === $tenantId) {
                $row = $item;
            }
        }
        self::assertNotNull($row, 'created tenant must be listed');
        $keys = array_keys($row);
        sort($keys);
        self::assertSame(['createdAt', 'id', 'name', 'status', 'tenantKey', 'updatedAt'], $keys);
        self::assertSame('active', $row['status']);
    }

    // rails create_tenant 镜像：tenantKey/name required（blank → ArgumentError 400 带字段消息）；
    // 落库 status 1；200 非 201
    #[Test]
    public function create_tenant_returns_200_and_requires_key_and_name(): void
    {
        $key = 'tf-at-create-'.Str::random(4);
        $created = $this->postJson(
            '/api/v1/admin/tenants',
            ['tenantKey' => $key, 'name' => 'TF Tenant'],
            $this->authHeader($this->token)
        );
        self::assertSame(200, $created->status(), $created->getContent());
        $body = $created->json();
        self::assertSame($key, $body['tenantKey']);
        self::assertSame('TF Tenant', $body['name']);
        self::assertSame('active', $body['status']);
        self::assertArrayHasKey('id', $body);
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            (string) $body['id']
        );

        $row = Tenant::query()->where('id', $body['id'])->firstOrFail();
        self::assertSame($key, (string) $row->tenant_key);
        self::assertSame(1, (int) $row->status);

        $noKey = $this->postJson('/api/v1/admin/tenants', ['name' => 'X'], $this->authHeader($this->token));
        self::assertSame(400, $noKey->status());
        self::assertSame('BAD_REQUEST', $noKey->json('code'));
        self::assertSame('tenantKey: is required', $noKey->json('message'));

        $noName = $this->postJson('/api/v1/admin/tenants', ['tenantKey' => 'tf-at-noname-'.Str::random(4)], $this->authHeader($this->token));
        self::assertSame(400, $noName->status());
        self::assertSame('name: is required', $noName->json('message'));
        self::assertSame(0, Tenant::query()->where('tenant_key', 'like', 'tf-at-noname-%')->count());
    }

    // rails get_tenant 镜像：合法 uuid 查无 → 404；格式非法 → 400 malformed uuid
    // （基座甄别表含 :id——T8 I-1 同源防线，读侧命中走 ModelNotFound 渲染映射）
    #[Test]
    public function get_tenant_unknown_is_404_and_malformed_uuid_is_400(): void
    {
        $missing = $this->getJson('/api/v1/admin/tenants/'.Str::uuid(), $this->authHeader($this->token));
        self::assertSame(404, $missing->status());
        self::assertSame('NOT_FOUND', $missing->json('code'));

        $malformed = $this->getJson('/api/v1/admin/tenants/not-a-uuid', $this->authHeader($this->token));
        self::assertSame(400, $malformed->status());
        self::assertSame('BAD_REQUEST', $malformed->json('code'));
        self::assertStringStartsWith('malformed uuid', (string) $malformed->json('message'));
    }

    // rails update_tenant 镜像：name present 才改；status 'active'→1 其余→0（写侧），
    // 读侧仍恒 'active'（quirk 勿修）；空 body no-op 200
    #[Test]
    public function update_tenant_applies_name_and_status_write_side(): void
    {
        $tenantId = $this->makeTenant('tf-at-update');

        $applied = $this->patchJson(
            "/api/v1/admin/tenants/{$tenantId}",
            ['name' => 'TF Renamed', 'status' => 'active'],
            $this->authHeader($this->token)
        );
        self::assertSame(200, $applied->status(), $applied->getContent());
        $row = Tenant::query()->where('id', $tenantId)->firstOrFail();
        self::assertSame('TF Renamed', (string) $row->name);
        self::assertSame(1, (int) $row->status);
        self::assertSame('active', $applied->json('status'));

        $suspended = $this->patchJson(
            "/api/v1/admin/tenants/{$tenantId}",
            ['status' => 'suspended'],
            $this->authHeader($this->token)
        );
        self::assertSame(200, $suspended->status(), $suspended->getContent());
        $row = Tenant::query()->where('id', $tenantId)->firstOrFail();
        self::assertSame(0, (int) $row->status);
        // 读侧 quirk：DB 0 仍渲染 'active'
        self::assertSame('active', $suspended->json('status'));

        // 空白/缺省字段 no-op：name 不动
        $noop = $this->patchJson(
            "/api/v1/admin/tenants/{$tenantId}",
            ['tenantKey' => 'hacked-key'],
            $this->authHeader($this->token)
        );
        self::assertSame(200, $noop->status(), $noop->getContent());
        $row = Tenant::query()->where('id', $tenantId)->firstOrFail();
        self::assertStringStartsWith('tf-at-update-', (string) $row->tenant_key);
        self::assertSame('TF Renamed', (string) $row->name);
    }

    // rails delete_tenant 镜像：where(id:).delete_all 不预查——行不存在也 204；
    // 格式非法 uuid 前置甄别（T8 教训：delete 漏甄别 → 22P02 → 500 分叉，rails AR cast
    // 吞错 → 0 行 204）→ 甄别命中直接 204 不落查询
    #[Test]
    public function delete_tenant_is_204_including_unknown_and_malformed_uuid(): void
    {
        $tenantId = $this->makeTenant('tf-at-delete');

        $known = $this->deleteJson("/api/v1/admin/tenants/{$tenantId}", [], $this->authHeader($this->token));
        self::assertSame(204, $known->status(), $known->getContent());
        self::assertSame(0, Tenant::query()->where('id', $tenantId)->count());

        $unknown = $this->deleteJson('/api/v1/admin/tenants/'.Str::uuid(), [], $this->authHeader($this->token));
        self::assertSame(204, $unknown->status(), $unknown->getContent());

        $malformed = $this->deleteJson('/api/v1/admin/tenants/not-a-uuid', [], $this->authHeader($this->token));
        self::assertSame(204, $malformed->status(), $malformed->getContent());
    }

    // admin-tenants 守卫面：无 token → 401（同 admin-clients，JWT 必需即守卫全貌）
    #[Test]
    public function admin_tenants_requires_jwt(): void
    {
        self::assertSame(401, $this->getJson('/api/v1/admin/tenants')->status());
        self::assertSame(
            401,
            $this->postJson('/api/v1/admin/tenants', ['tenantKey' => 'x', 'name' => 'y'])->status()
        );
    }

    // ---- 数据工厂（事务内，回滚不留痕） ----

    private function makeClient(string $clientId, ?string $clientName = null, ?string $scopes = null): string
    {
        $now = Date::now();
        OauthClient::query()->create([
            'id' => (string) Str::uuid(),
            'client_id' => $clientId,
            'client_secret' => 's',
            'client_name' => $clientName ?? $clientId,
            'grant_types' => 'authorization_code',
            'redirect_uris' => 'http://localhost/cb',
            'scopes' => $scopes,
            'status' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $clientId;
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
