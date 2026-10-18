<?php

// 骨架期无 web 路由：业务 API 一律等 shared 契约生成物（suite-hard-rules §4）。
// 探活端点 /health 在 bootstrap/app.php 的 withRouting(then:) 注册（不挂 web 中间件组）。
