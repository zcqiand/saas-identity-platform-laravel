<?php

namespace Tests\Unit;

use App\Exceptions\Jwt\TokenExpired;
use App\Exceptions\Jwt\TokenInvalid;
use App\Services\Auth\JwtService;
use Illuminate\Support\Env;
use PHPUnit\Framework\AssertionFailedError;
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
            self::fail('缺 JWT_SIGNING_KEY 必须 throw（fail-fast，禁 env 默认值兜底——suite-hard-rules §1）');
        } catch (AssertionFailedError $e) {
            // fail() 抛的 AssertionFailedError 是 RuntimeException 子类，必须先接住重抛，
            // 否则构造器不 throw 时测试被自己的 catch 吞成假绿。
            throw $e;
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
            self::fail('缺 JWT_TTL_SECONDS 必须 throw（fail-fast——suite-hard-rules §1）');
        } catch (AssertionFailedError $e) {
            throw $e; // 同上：fail() 不得被下面的 RuntimeException catch 吞掉
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('JWT_TTL_SECONDS', $e->getMessage());
        } finally {
            $this->restoreEnv('JWT_TTL_SECONDS', $saved);
        }
    }

    #[Test]
    public function ttl_with_trailing_newline_throws_fail_fast(): void
    {
        // 与 UUID_RE 同族：regex $ 无 D 修饰符会放行尾随 \n（round 1 评审 M-2，无害方向
        // 但一处修完）——"3600\n" 不是合法正整数。
        $saved = Env::get('JWT_TTL_SECONDS');
        $_ENV['JWT_TTL_SECONDS'] = "3600\n";
        $_SERVER['JWT_TTL_SECONDS'] = "3600\n";
        try {
            new JwtService;
            self::fail('JWT_TTL_SECONDS 带尾随换行必须 throw（\z 严格语义）');
        } catch (AssertionFailedError $e) {
            throw $e; // 同上
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
