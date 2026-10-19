<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\SysUser;
use App\Models\TenantMember;
use Illuminate\Testing\TestResponse;
use JsonException;

// 集成测试共享助手：真库链（saas_test），事务回滚保种子
// （rails test/integration/support.rb IntegrationSupport 全文镜像）。
trait IntegrationSupport
{
    public const DEV_USER = 'alice';

    public const DEV_PASSWORD = 'dev123456';

    public const DEV_CLIENT = 'saas-console';

    /**
     * rails #login 镜像：POST /api/v1/auth/login，返回 TestResponse
     * （rails 的 response 全局 + 解析 body 拆成 PHP 显式两步）。
     *
     * @param  string|null  $username  null = DEV_USER
     * @param  string|null  $password  null = DEV_PASSWORD
     * @param  string|null  $clientId  null = DEV_CLIENT
     */
    protected function login(?string $username = null, ?string $password = null, ?string $clientId = null): TestResponse
    {
        return $this->postJson('/api/v1/auth/login', [
            'username' => $username ?? self::DEV_USER,
            'password' => $password ?? self::DEV_PASSWORD,
            'clientId' => $clientId ?? self::DEV_CLIENT,
        ]);
    }

    /**
     * rails IntegrationSupport#login 的 body 解析分支镜像：空体（423）回 []。
     *
     * @return array<string, mixed>
     */
    protected function loginBody(TestResponse $response): array
    {
        $raw = (string) $response->getContent();
        if ($raw === '') {
            return [];
        }

        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        return $decoded;
    }

    /**
     * @return array<string, string>
     */
    protected function authHeader(string $token): array
    {
        return ['Authorization' => "Bearer {$token}"];
    }

    // alice 首个 active membership 的 tenant_id（rails #alice_tenant_id 镜像：order(:created_at).first!）
    protected function aliceTenantId(): string
    {
        $user = SysUser::query()->where('username', self::DEV_USER)->firstOrFail();

        /** @var TenantMember $member 首个 active 行（种子保证存在；缺种子时 setUp 探针已 fail-fast） */
        $member = TenantMember::query()
            ->where('user_id', $user->id)
            ->where('status', 1)
            ->orderBy('created_at')
            ->firstOrFail();

        return (string) $member->tenant_id;
    }
}
