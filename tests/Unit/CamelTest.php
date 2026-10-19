<?php

namespace Tests\Unit;

use App\Support\Camel;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

// Camel::deep 契约（Task 5-10 渲染消费面）：snake_case → lowerCamel 深变换，
// 镜像 rails ApiSupport::JsonCamelizeKey（render_camel 底座）——Hash/Array 递归、
// null 值键剔除（@Nullable normalize 语义：字段缺失即不出现在 JSON 里）。
final class CamelTest extends TestCase
{
    #[Test]
    public function snake_keys_become_lower_camel(): void
    {
        $this->assertSame(
            ['userId' => 1, 'createdAt' => '2026-10-19', 'clientId' => 'saas-console'],
            Camel::deep(['user_id' => 1, 'created_at' => '2026-10-19', 'client_id' => 'saas-console'])
        );
    }

    #[Test]
    public function nested_maps_and_lists_are_recursed(): void
    {
        $this->assertSame(
            [
                'tenantMember' => [
                    'roleIds' => ['r1', 'r2'],
                    'memberView' => ['joinedAt' => 't'],
                ],
                'items' => [
                    ['roleId' => 'r1'],
                    ['roleId' => 'r2'],
                ],
            ],
            Camel::deep([
                'tenant_member' => [
                    'role_ids' => ['r1', 'r2'],
                    'member_view' => ['joined_at' => 't'],
                ],
                'items' => [
                    ['role_id' => 'r1'],
                    ['role_id' => 'r2'],
                ],
            ])
        );
    }

    #[Test]
    public function null_values_are_dropped(): void
    {
        // 家族契约：可选字段缺失即不出现在 JSON 里，不是 "field": null（recon §2.2 rails 同款）。
        $this->assertSame(['b' => 2, 'c' => ['d' => 1]], Camel::deep(['a' => null, 'b' => 2, 'c' => ['d' => 1, 'e' => null]]));
    }

    #[Test]
    public function plain_camel_and_numeric_keys_are_unchanged(): void
    {
        $this->assertSame(
            ['id' => 1, 'clientId' => 'x', '0' => 'a', '1' => 'b'],
            Camel::deep(['id' => 1, 'clientId' => 'x', '0' => 'a', '1' => 'b'])
        );
    }

    #[Test]
    public function scalar_values_pass_through_untouched(): void
    {
        // 值本身不做变换（含值内的下划线字符串）——只转 key（key a_b → aB，值 c_d 原样）。
        $this->assertSame(['aB' => 'c_d', 'list' => ['a_b', 'c_d']], Camel::deep(['a_b' => 'c_d', 'list' => ['a_b', 'c_d']]));
    }

    #[Test]
    public function null_elements_in_lists_are_preserved(): void
    {
        // rails JsonCamelizeKey Array 分支镜像：list 逐元素递归、原样保序保 null——
        // null 剔除只作用于 map 的键值（Hash 分支），不波及列表元素（round 1 评审 I-1）。
        $this->assertSame(
            ['items' => [null, ['snakeKey' => 1], 'x']],
            Camel::deep(['items' => [null, ['snake_key' => 1], 'x']])
        );
    }
}
