<?php

namespace Tests\Contracts;

use Illuminate\Routing\Route;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Route as RouteFacade;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * D2 route-parity 契约测试（suite-hard-rules §4 落法，rails RouteParityTest 镜像）：
 * 读 generated/api_manifest.json（生成物，SSOT = shared 契约），逐条断言
 * (1) method+path 路由存在 (2) controller action 已定义 (3) 多出来的路由红
 * （GET /health allowlist）。PHP 无编译步，这是「缺端点红 / 私端点也红」的等价强制。
 */
final class RouteParityTest extends TestCase
{
    /** /health 是 contract-test healthcheck 探针，非 OpenAPI 契约面 —— 唯一合法私路由。 */
    private const EXTRA_ROUTE_ALLOWLIST = ['GET /health'];

    /** @var list<array{method: string, operationId: string, path: string}> */
    private array $operations = [];

    private RouteCollection $routes;

    protected function setUp(): void
    {
        parent::setUp();

        $manifest = json_decode((string) file_get_contents(base_path('generated/api_manifest.json')), true);
        self::assertIsArray($manifest);
        self::assertArrayHasKey('operations', $manifest);

        $operations = [];
        foreach (is_array($manifest['operations']) ? $manifest['operations'] : [] as $op) {
            self::assertIsArray($op);
            $operations[] = [
                'method' => (string) $op['method'],
                'operationId' => (string) $op['operationId'],
                'path' => (string) $op['path'],
            ];
        }
        $this->operations = $operations;
        /** @var RouteCollection $routes 实际运行时恒为 RouteCollection（facade 返回接口窄化） */
        $routes = RouteFacade::getRoutes();
        $this->routes = $routes;
    }

    #[Test]
    public function every_manifest_operation_has_a_route_and_a_defined_action(): void
    {
        foreach ($this->operations as $op) {
            $route = $this->findRoute($op['method'], ltrim($op['path'], '/'));

            self::assertNotNull(
                $route,
                sprintf(
                    'manifest %s %s (%s) has no matching Laravel route',
                    $op['method'],
                    $op['path'],
                    $op['operationId']
                )
            );

            $action = $route->getActionName();
            if (! str_contains($action, '@')) {
                self::fail(sprintf(
                    'route %s %s is not bound to a controller action (%s)',
                    $op['method'],
                    $op['path'],
                    $op['operationId']
                ));
            }

            $parts = explode('@', $action);
            $controllerClass = $parts[0];
            $actionMethod = $parts[1] ?? '';

            self::assertTrue(
                method_exists($controllerClass, $actionMethod),
                sprintf('%s@%s not defined (%s)', $controllerClass, $actionMethod, $op['operationId'])
            );
        }
    }

    #[Test]
    public function no_undeclared_routes_beyond_the_allowlist(): void
    {
        $declared = [];
        foreach ($this->operations as $op) {
            $declared[] = $op['method'].' '.$op['path'];
        }
        sort($declared);

        $actual = [];
        /** @var Route $route */
        foreach ($this->routes as $route) {
            foreach ($route->methods() as $verb) {
                // HEAD/OPTIONS 是框架自动派生方法，不参与对账（rails 同口径）。
                if (in_array($verb, ['HEAD', 'OPTIONS'], true)) {
                    continue;
                }
                $actual[] = $verb.' /'.$route->uri();
            }
        }
        $actual = array_values(array_unique($actual));
        sort($actual);

        $extras = array_values(array_diff($actual, $declared, self::EXTRA_ROUTE_ALLOWLIST));

        self::assertSame(
            [],
            $extras,
            'routes not in shared contract: '.implode(', ', $extras)
        );
    }

    private function findRoute(string $method, string $uri): ?Route
    {
        /** @var Route $route */
        foreach ($this->routes as $route) {
            if ($route->uri() !== $uri) {
                continue;
            }
            if (in_array($method, $route->methods(), true)) {
                return $route;
            }
        }

        return null;
    }
}
