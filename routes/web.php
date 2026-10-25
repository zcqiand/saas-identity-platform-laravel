<?php

// web 面当前为空：基础设施端点（/health + Swagger 文档面三路由，REQ-2026-002）在
// bootstrap/app.php 的 then: 闭包注册——不挂 web 中间件组（web 组 StartSession 走
// database driver，无 DB 面一律 500，v0.2.0 首航红指纹）。业务 API 一律走
// routes/api.php（generated/api_manifest.json 1:1 镜像，suite-hard-rules §4）。
// 文档面 bump-sync 三处：bootstrap/app.php 注释 + resources/views/docs/swagger.blade.php
// + .github/workflows/ci.yml 探针版本引用。
