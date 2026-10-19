<?php

// local 磁盘关掉框架默认的文件服务（framework config/filesystems.php 里 local
// serve=true 会经 FilesystemServiceProvider::serveFiles 注册 GET/PUT /storage/{path}
// 两条契约外路由，RouteParityTest 判其为私路由红）。本 API 无上传/文件面；
// 后续任务若接文件服务，须连同 RouteParityTest 的 allowlist 一起人批。
// 注意：config 覆盖是逐盘替换不是深合并，local 盘必须带全键。
return [
    'disks' => [
        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => false,
            'throw' => false,
            'report' => false,
        ],
    ],
];
