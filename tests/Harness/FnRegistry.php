<?php

namespace Harness;

/**
 * fn → trace.json 的登记表：键 = Class::method，值 = {fns, file}。
 * 与 rails FN_REGISTRY 同构；flush 阶段 fns 排序去重（rails sort.uniq 同款）。
 */
final class FnRegistry
{
    /** @var array<string, array{fns: list<string>, file: ?string}> */
    private static array $registry = [];

    /** @param list<string> $ids */
    public static function register(string $class, string $method, array $ids, ?string $file): void
    {
        self::$registry[$class.'::'.$method] = ['fns' => $ids, 'file' => $file];
    }

    /** @return list<string> */
    public static function fnsFor(string $class, string $method): array
    {
        $entry = self::$registry[$class.'::'.$method] ?? null;

        if ($entry === null) {
            return [];
        }

        $fns = array_values(array_unique($entry['fns']));
        sort($fns);

        return $fns;
    }
}
