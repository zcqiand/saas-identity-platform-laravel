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
