<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class HealthTest extends TestCase
{
    #[Test]
    public function health_endpoint_returns_family_probe_shape(): void
    {
        // 家族探活契约形状（ktor Application.kt 同款）；工程设施测试，不挂功能 ID。
        $this->getJson('/health')
            ->assertOk()
            ->assertExactJson(['ok' => true, 'mode' => 'laravel']);
    }
}
