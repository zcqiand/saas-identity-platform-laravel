<?php

namespace Harness;

/**
 * 单次运行 trace 收集器，TestRunner ExecutionFinished 时一次性落盘。
 *
 * 契约（scripts/lib/harness.py load_trace）：{"schema":1,"tests":[{"test","fns","inert"}]}。
 * - report 阶段无条件落盘：测试全红/空跑也写（suite 校验存在性 + mtime 新鲜度）。
 * - inert 赢家制：Test\Skipped 先到 → 该行 inert 锁定为 true，其后的 Test\Finished
 *   不得翻回——「被 skip 仍声称覆盖」在源头物理不可能；suite 契约层会再次复核。
 * - PHPUnit 默认单进程，无 rails parallelize 竞写问题。
 */
final class TraceCollector
{
    /** @var array<string, array{class: string, method: string, inert: bool}> */
    private static array $rows = [];

    private static bool $active = false;

    private static string $root = '.';

    public static function activate(string $root): void
    {
        self::$active = true;
        self::$root = $root;
    }

    public static function record(string $class, string $method, bool $inert): void
    {
        if (! self::$active) {
            return;
        }

        $key = $class.'::'.$method;

        if (isset(self::$rows[$key]) && self::$rows[$key]['inert']) {
            return;
        }

        self::$rows[$key] = ['class' => $class, 'method' => $method, 'inert' => $inert];
    }

    public static function flush(): void
    {
        if (! self::$active) {
            return;
        }

        $tests = [];

        foreach (self::$rows as $row) {
            $tests[] = self::buildEntry($row);
        }

        $out = self::$root.'/.state/trace.json';
        $dir = dirname($out);

        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        file_put_contents(
            $out,
            json_encode(['schema' => 1, 'tests' => $tests], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
        );
    }

    /** @param array{class: string, method: string, inert: bool} $row */
    private static function buildEntry(array $row): array
    {
        $fns = $row['inert'] ? [] : FnRegistry::fnsFor($row['class'], $row['method']);

        return ['test' => self::locate($row), 'fns' => $fns, 'inert' => $row['inert']];
    }

    /** trace 条目按 suite 惯例用仓内相对路径：`tests/Feature/XxxTest.php::method`。 */
    private static function locate(array $row): string
    {
        $file = null;

        try {
            $ref = new \ReflectionMethod($row['class'], $row['method']);
            $file = $ref->getFileName() ?: null;
        } catch (\ReflectionException) {
        }

        if ($file === null) {
            return $row['class'].'::'.$row['method'];
        }

        $root = str_replace('\\', '/', self::$root);
        $file = str_replace('\\', '/', $file);

        if (str_starts_with($file, $root.'/')) {
            $file = substr($file, strlen($root) + 1);
        }

        return $file.'::'.$row['method'];
    }
}
