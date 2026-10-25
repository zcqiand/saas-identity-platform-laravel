<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class DocSurfaceTest extends TestCase
{
    #[Test]
    public function root_redirects_to_api_docs(): void
    {
        // 根路径跳文档（springboot/aspnetcore/ktor 家族先例）；基础设施端点，不挂功能 ID。
        $this->get('/')->assertRedirect('/api-docs');
    }

    #[Test]
    public function api_docs_serves_swagger_shell(): void
    {
        $this->get('/api-docs')
            ->assertOk()
            ->assertSee('SwaggerUIBundle');
    }

    #[Test]
    public function openapi_yaml_serves_shared_contract(): void
    {
        // shared 契约生成物原样（gen-shared.sh 落盘），不是手写注解产物
        $response = $this->get('/api-docs/openapi.yaml');

        $response->assertOk();
        $this->assertSame('application/yaml', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('openapi:', $response->getContent());
    }
}
