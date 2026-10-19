<?php

namespace Tests\Feature;

use App\Exceptions\AccessDenied;
use App\Exceptions\InvalidCredentials;
use App\Exceptions\Jwt\TokenInvalid;
use App\Exceptions\Lockout;
use App\Http\Middleware\TenantGuard;
use App\Models\Generated\SysUser;
use App\Services\Auth\JwtService;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use PDOException;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Tests\Support\TestJwt;
use Tests\TestCase;

// TenantGuard + 基座错误形状（Task 5-10 消费契约，recon §2.1 rails 表逐行镜像）。
// 假 payload 直打中间件，不依赖真库登录链（真链路后续任务接入）。
final class GuardShapeTest extends TestCase
{
    // ---- TenantGuard：401 形状（缺头/坏头/过期/坏签名/alg 混淆） ----

    #[Test]
    public function missing_authorization_header_is_401_with_authenticate_challenge(): void
    {
        $response = $this->runGuard($this->requestWithoutToken());

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame(
            ['code' => 'INVALID_CREDENTIALS', 'message' => 'missing bearer token'],
            $this->bodyOf($response)
        );
        $this->assertSame('Bearer realm="api"', $response->headers->get('WWW-Authenticate'));
    }

    #[Test]
    public function header_without_bearer_prefix_is_401(): void
    {
        $request = $this->requestWithoutToken();
        $request->headers->set('Authorization', 'Basic dXNlcjpwYXNz');

        $response = $this->runGuard($request);

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('missing bearer token', $this->bodyOf($response)['message']);
    }

    #[Test]
    public function expired_token_is_401_token_expired(): void
    {
        $response = $this->runGuard($this->requestWithToken(TestJwt::expired()));

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame(
            ['code' => 'INVALID_CREDENTIALS', 'message' => 'token expired'],
            $this->bodyOf($response)
        );
        $this->assertSame('Bearer realm="api"', $response->headers->get('WWW-Authenticate'));
    }

    #[Test]
    public function wrong_signature_is_401_invalid_token(): void
    {
        $token = TestJwt::sign(
            ['sub' => 'user-1', 'tenant_id' => 'tenant-1', 'iat' => time(), 'exp' => time() + 3600],
            'another-key-32-bytes-minimum-length!!'
        );

        $response = $this->runGuard($this->requestWithToken($token));

        $this->assertSame(401, $response->getStatusCode());
        $body = $this->bodyOf($response);
        $this->assertSame('INVALID_CREDENTIALS', $body['code']);
        $this->assertStringStartsWith('invalid token: ', $body['message']);
    }

    #[Test]
    public function alg_none_confusion_is_401(): void
    {
        $response = $this->runGuard($this->requestWithToken(TestJwt::algNone()));

        $this->assertSame(401, $response->getStatusCode());
        $this->assertStringStartsWith('invalid token: ', $this->bodyOf($response)['message']);
    }

    #[Test]
    public function valid_token_passes_through_and_exposes_identity_attributes(): void
    {
        // Task 5-10 消费约定：Guard 把身份合并进 request attributes，
        // 控制器经 $request->attributes->get('currentUserId' / 'currentTenantId') 读取；
        // 查询必须用它过滤（where(tenant_id: currentTenantId)），禁全表泄露；
        // 身份字段缺失必须 401/403，禁 demo 字面量兜底（ADR-0019）。
        $token = (new JwtService)->encode(['sub' => 'user-1', 'tenant_id' => 'tenant-1']);
        $request = $this->requestWithToken($token);
        $seen = null;

        $guard = new TenantGuard(new JwtService);
        $response = $guard->handle($request, function (Request $r) use (&$seen): Response {
            $seen = [
                'userId' => $r->attributes->get('currentUserId'),
                'tenantId' => $r->attributes->get('currentTenantId'),
            ];

            return new Response('ok', 200);
        });

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['userId' => 'user-1', 'tenantId' => 'tenant-1'], $seen);
    }

    // ---- TenantGuard::verifyPathTenant：租户缝 403 ----

    #[Test]
    public function path_tenant_matching_jwt_claim_passes(): void
    {
        $request = $this->requestWithRouteParams(['tenantId' => 'tenant-1']);
        $request->attributes->set('currentTenantId', 'tenant-1');

        TenantGuard::verifyPathTenant($request);

        $this->addToAssertionCount(1); // 未抛即过
    }

    #[Test]
    public function path_tenant_mismatch_is_403_access_denied(): void
    {
        $request = $this->requestWithRouteParams(['tenantId' => 'tenant-other']);
        $request->attributes->set('currentTenantId', 'tenant-1');

        $this->expectException(AccessDenied::class);
        $this->expectExceptionMessage('tenant mismatch');

        TenantGuard::verifyPathTenant($request);
    }

    #[Test]
    public function missing_path_tenant_param_is_access_denied(): void
    {
        $request = $this->requestWithRouteParams([]);
        $request->attributes->set('currentTenantId', 'tenant-1');

        $this->expectException(AccessDenied::class);

        TenantGuard::verifyPathTenant($request);
    }

    // ---- 基座错误映射（bootstrap withExceptions，rails rescue_from 表逐行） ----

    #[Test]
    public function lockout_renders_423_with_empty_body(): void
    {
        $response = $this->renderException(new Lockout('locked'));

        // 423 空响应体（rails head :locked / springboot body(null) 忠实镜像，空体即契约形状）。
        $this->assertSame(423, $response->getStatusCode());
        $this->assertSame('', $response->getContent());
    }

    #[Test]
    public function invalid_credentials_renders_401_shape(): void
    {
        $response = $this->renderException(new InvalidCredentials('invalid credentials'));

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame(
            ['code' => 'INVALID_CREDENTIALS', 'message' => 'invalid credentials'],
            $this->bodyOf($response)
        );
    }

    #[Test]
    public function access_denied_renders_403_shape(): void
    {
        $response = $this->renderException(new AccessDenied('no active tenant membership'));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(
            ['code' => 'FORBIDDEN', 'message' => 'no active tenant membership'],
            $this->bodyOf($response)
        );
    }

    #[Test]
    public function argument_error_renders_400_shape(): void
    {
        $response = $this->renderException(new \InvalidArgumentException('page must be >= 0'));

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame(
            ['code' => 'BAD_REQUEST', 'message' => 'page must be >= 0'],
            $this->bodyOf($response)
        );
    }

    #[Test]
    public function model_not_found_with_malformed_uuid_param_is_400(): void
    {
        // rails UUID 甄别镜像：路径段声称 uuid 但格式非法 → 400（IAE 语义）。
        $request = $this->requestWithRouteParams(['id' => 'not-a-uuid']);
        $e = $this->notFound(['not-a-uuid']);

        $response = $this->renderException($e, $request);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame(
            ['code' => 'BAD_REQUEST', 'message' => 'malformed uuid: not-a-uuid'],
            $this->bodyOf($response)
        );
    }

    #[Test]
    public function model_not_found_with_valid_uuid_is_404(): void
    {
        // 合法格式但查无 → 404 NOT_FOUND。
        $uuid = '00000000-0000-0000-0000-000000000000';
        $request = $this->requestWithRouteParams(['id' => $uuid]);

        $response = $this->renderException($this->notFound([$uuid]), $request);

        $this->assertSame(404, $response->getStatusCode());
        $body = $this->bodyOf($response);
        $this->assertSame('NOT_FOUND', $body['code']);
        $this->assertStringContainsString('No query results', $body['message']);
    }

    #[Test]
    public function uuid_param_with_trailing_newline_is_400_not_404(): void
    {
        // rails UUID_RE \z 严格语义镜像：尾随 \n 不算合法 uuid（PHP $ 无 D 修饰符时
        // 匹配尾随换行前的位置——漏判会把坏 uuid 走 404「查无」口径，round 1 评审 M-1）。
        $uuid = "00000000-0000-0000-0000-000000000000\n";
        $request = $this->requestWithRouteParams(['id' => $uuid]);

        $response = $this->renderException($this->notFound([$uuid]), $request);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('BAD_REQUEST', $this->bodyOf($response)['code']);
    }

    #[Test]
    public function integrity_violation_renders_400_constraint_violation(): void
    {
        $e = new UniqueConstraintViolationException(
            'pgsql',
            'insert into sys_user ...',
            [],
            new PDOException('SQLSTATE[23505]: Unique violation: 7 ERROR: duplicate key value', 23505)
        );

        $response = $this->renderException($e);

        $this->assertSame(400, $response->getStatusCode());
        $body = $this->bodyOf($response);
        $this->assertSame('BAD_REQUEST', $body['code']);
        $this->assertStringStartsWith('constraint violation: ', $body['message']);
    }

    #[Test]
    public function malformed_body_renders_400_fixed_message(): void
    {
        // ParameterMissing / JSON.parse 失败镜像（HttpMessageNotReadable → 400）。
        $response = $this->renderException(new BadRequestHttpException('Invalid JSON body.'));

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame(
            ['code' => 'BAD_REQUEST', 'message' => 'malformed request body'],
            $this->bodyOf($response)
        );
    }

    #[Test]
    public function unmapped_exception_falls_through_to_default_handling(): void
    {
        // 映射表外的异常（如真 SQL 错误 42P01）不吞——走框架默认 500。
        $e = new QueryException('pgsql', 'select * from missing_table', [], new PDOException('SQLSTATE[42P01]: Undefined table', 42601));

        $response = $this->renderException($e);

        $this->assertSame(500, $response->getStatusCode());
    }

    #[Test]
    public function token_invalid_thrown_by_guard_maps_to_401_via_handler_too(): void
    {
        // decode_bearer!（oauth 链手动 decode）会走 handler：TokenInvalid → 401 INVALID_CREDENTIALS。
        $response = $this->renderException(new TokenInvalid('signature mismatch'));

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame(
            ['code' => 'INVALID_CREDENTIALS', 'message' => 'signature mismatch'],
            $this->bodyOf($response)
        );
    }

    // ---- 助手 ----

    private function runGuard(Request $request): Response
    {
        $guard = new TenantGuard(new JwtService);

        return $guard->handle($request, fn (): Response => new Response('ok', 200));
    }

    private function requestWithoutToken(): Request
    {
        return Request::create('/api/v1/me', 'GET');
    }

    private function requestWithToken(string $token): Request
    {
        $request = $this->requestWithoutToken();
        $request->headers->set('Authorization', 'Bearer '.$token);

        return $request;
    }

    private function requestWithRouteParams(array $params): Request
    {
        $request = Request::create('/api/v1/tenants/x/members', 'GET');
        $route = new Route('GET', '/api/v1/tenants/{tenantId}/members', []);
        $route->parameters = $params;
        $request->setRouteResolver(fn (): Route => $route);

        return $request;
    }

    private function notFound(array $ids): ModelNotFoundException
    {
        $e = new ModelNotFoundException;
        $e->setModel(SysUser::class, $ids);

        return $e;
    }

    private function renderException(\Throwable $e, ?Request $request = null): Response
    {
        /** @var ExceptionHandler $handler */
        $handler = $this->app->make(ExceptionHandler::class);

        return $handler->render($request ?? $this->requestWithoutToken(), $e);
    }

    // handler 命中映射表时回 JsonResponse、落空时回框架默认页——统一按 content 解码拿 body。
    private function bodyOf(Response $response): array
    {
        $decoded = json_decode((string) $response->getContent(), true);

        return is_array($decoded) ? $decoded : [];
    }
}
