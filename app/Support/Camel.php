<?php

namespace App\Support;

// snake_case → lowerCamel 深变换——rails ApiSupport::JsonCamelizeKey 的 Laravel 镜像
// （ApplicationController render_camel 底座，Task 5-10 渲染层专用）。
// 家族契约：@Nullable 字段序列化后 null 被 normalize 剔除——null 值键直接丢弃，
// 镜像 springboot 响应 normalize（可选字段缺失即不出现在 JSON 里，不是 "field": null）。
final class Camel
{
    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public static function deep(array $data): array
    {
        // rails JsonCamelizeKey Array 分支镜像：list 逐元素递归、原样保序保 null——
        // null 剔除只作用于 map 的键值，不波及列表元素；也避免 map 分支剔键后
        // 非连续数字键让 json_encode 把数组退化成对象（{"0":..,"2":..}）。
        if (array_is_list($data)) {
            return array_map(fn ($value) => is_array($value) ? self::deep($value) : $value, $data);
        }

        $out = [];
        foreach ($data as $key => $value) {
            if ($value === null) {
                continue;
            }
            $out[self::camelize(is_string($key) ? $key : (string) $key)] =
                is_array($value) ? self::deep($value) : $value;
        }

        return $out;
    }

    public static function camelize(string $key): string
    {
        // rails String#camelize(:lower) 语义：snake_case → lowerCamel；已是 camel/plain 的 key 原样。
        return lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $key))));
    }
}
