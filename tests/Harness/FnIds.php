<?php

namespace Harness;

/**
 * 声明该测试直接验证的功能子项 ID（rails fn.rb / java-spring @Fn 的 PHP 同源移植）。
 *
 * 契约（荣誉制，机器管不了的部分靠自觉）：
 *  - 只在测试直接验证该子项可观察行为时挂 ID；间接受益与工程设施测试不挂。
 *  - 一个测试 >3 个 ID = 测得太宽，拆。
 *  - ID 必须已登记在项目 docs/functions/function-tree.md，否则 L5 报悬空引用。
 *  - 被 skip 的测试其 fns 由 TraceCollector 在源头清空——「声明覆盖但不执行」物理上不可能。
 *
 * 命名注记：rails/kotlin 侧叫 fn(...)，PHP 侧 fn 是 7.4+ 保留字，pint（L1 门）的
 * 解析器拒绝 `function fn(` 与 `use Fn;` 两种写法，故 trait=FnIds、方法=fnId。
 * trace 契约不受影响——.state/trace.json 里字段仍叫 fns。
 *
 * 用法（在测试方法体内）：$this->fnId('M01.F01.I01', 'M01.F01.I02');
 */
trait FnIds
{
    protected function fnId(string ...$ids): void
    {
        $caller = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1] ?? null;

        if ($caller !== null) {
            FnRegistry::register(static::class, $caller['function'], array_values($ids), $caller['file'] ?? null);
        }
    }
}
