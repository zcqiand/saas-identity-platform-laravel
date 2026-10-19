<?php

namespace Tests\Unit;

use App\Exceptions\Jwt\TokenExpired;
use App\Exceptions\Jwt\TokenInvalid;
use App\Services\Auth\JwtService;
use Illuminate\Support\Env;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Support\TestJwt;
use Tests\TestCase;

// JwtService 契约（Task 5-10 鉴权消费面）：HS256 手写验签，TTL 走 env JWT_TTL_SECONDS
// fail-fast；claims 含 sub/tenant_id（rails Auth::JwtVerifier 镜像语义）。
final class JwtTest extends TestCase
{
    #[Test]
    public function encode_verify_roundtrip_carries_claims_and_ttl(): void
    {
        $jwt = new JwtService;
        $token = $jwt->encode(['sub' => 'user-1', 'tenant_id' => 'tenant-1']);
        $payload = $jwt->verify($token);

        $this->assertSame('user-1', $payload['sub']);
        $this->assertSame('tenant-1', $payload['tenant_id']);
        $this->assertSame(3600, $payload['exp'] - $payload['iat']); // TTL = .env.test JWT_TTL_SECONDS
        $this->assertGreaterThanOrEqual(time(), $payload['exp']);
    }

    #[Test]
    public function expired_token_is_rejected(): void
    {
        $this->expectException(TokenExpired::class);

        (new JwtService)->verify(TestJwt::expired());
    }

    #[Test]
    public function wrong_signature_is_rejected(): void
    {
        $this->expectException(TokenInvalid::class);

        $token = TestJwt::sign(
            ['sub' => 'user-1', 'tenant_id' => 'tenant-1', 'iat' => time(), 'exp' => time() + 3600],
            'another-key-32-bytes-minimum-length!!'
        );
        (new JwtService)->verify($token);
    }

    #[Test]
    public function alg_none_confusion_is_rejected(): void
    {
        $this->expectException(TokenInvalid::class);

        (new JwtService)->verify(TestJwt::algNone());
    }

    #[Test]
    public function alg_hs512_confusion_is_rejected(): void
    {
        $this->expectException(TokenInvalid::class);

        // alg != HS256 即拒（header 混淆攻击面），即便签名本身算得对。
        $token = TestJwt::sign(
            ['sub' => 'user-1', 'tenant_id' => 'tenant-1', 'iat' => time(), 'exp' => time() + 3600],
            null,
            'HS512'
        );
        (new JwtService)->verify($token);
    }

    #[Test]
    public function missing_signing_key_throws_fail_fast(): void
    {
        $saved = Env::get('JWT_SIGNING_KEY');
        $this->removeEnv('JWT_SIGNING_KEY');
        try {
            new JwtService;
            $this->fail('缺 JWT_SIGNING_KEY 必须 throw（fail-fast，禁 env 默认值兜底——suite-hard-rules §1）');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('JWT_SIGNING_KEY', $e->getMessage());
        } finally {
            $this->restoreEnv('JWT_SIGNING_KEY', $saved);
        }
    }

    #[Test]
    public function missing_ttl_throws_fail_fast(): void
    {
        $saved = Env::get('JWT_TTL_SECONDS');
        $this->removeEnv('JWT_TTL_SECONDS');
        try {
            new JwtService;
            $this->fail('缺 JWT_TTL_SECONDS 必须 throw（fail-fast——suite-hard-rules §1）');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('JWT_TTL_SECONDS', $e->getMessage());
        } finally {
            $this->restoreEnv('JWT_TTL_SECONDS', $saved);
        }
    }

    #[Test]
    public function malformed_token_segments_are_rejected(): void
    {
        $this->expectException(TokenInvalid::class);

        (new JwtService)->verify('not-a-jwt');
    }

    // —— env 操盘助手：Laravel Env repository 直读超全局/live getenv，三处同撤同还 ——

    private function removeEnv(string $key): void
    {
        putenv($key);
        unset($_ENV[$key], $_SERVER[$key]);
    }

    private function restoreEnv(string $key, mixed $value): void
    {
        if ($value === null) {
            return;
        }
        putenv($key.'='.$value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}
