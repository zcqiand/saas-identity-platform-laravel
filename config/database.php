<?php

// PG 连接映射（DB-First：schema SSOT = shared drizzle public.*，禁 migrate）。
// 键名全家族统一 PG_* 五件套（rails .env.example 同源口径）；env() 一律不带
// 默认值兜底（suite-hard-rules §1）——缺键时连库即炸，不碰库的测试
// （parity/health/smoke）走 Laravel 惰性连接，天然零 PG 依赖。
return [
    'default' => env('DB_CONNECTION'),

    'connections' => [
        'pgsql' => [
            'driver' => 'pgsql',
            'host' => env('PG_HOST'),
            'port' => env('PG_PORT'),
            'database' => env('PG_DATABASE'),
            'username' => env('PG_USER'),
            'password' => env('PG_PASSWORD'),
            'charset' => 'utf8',
            'prefix' => '',
            'search_path' => 'public',
            'sslmode' => 'prefer',
        ],
    ],
];
