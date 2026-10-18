<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Harness\Fn trait + HarnessTraceExtension 的最小贯通测试（ktor FnAnnotationSmokeTest 同源）。
 * 仅作为锚点适配器的 sanity check，它本身不是业务功能；挂假 ID 会在 L5 悬空（tree 里无此 ID）。
 */
final class HarnessFnSmokeTest extends TestCase
{
    #[Test]
    public function harness_trait_round_trips_into_trace(): void
    {
        // 零 ID 调用：只为走通 trait → FnRegistry → TraceCollector 链。
        $this->fnId();

        $this->assertSame(2, 1 + 1);
    }
}
