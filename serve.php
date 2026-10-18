<?php

// 家族端口纪律：SERVER_PORT 全家族统一 key，缺失/非法 fail-fast 退出——
// 禁止默认值兜底（suite-hard-rules §1）。dev 值见 .env.example（saas=5110 / lab=5210）。
$port = getenv('SERVER_PORT');

if ($port === false || ! ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) {
    fwrite(STDERR, "SERVER_PORT 未设置或不是 1-65535 的整数。禁止默认值兜底（suite-hard-rules §1）；dev 值见 .env.example。\n");
    exit(1);
}

passthru(escapeshellarg(PHP_BINARY).' artisan serve --host=0.0.0.0 --port='.(int) $port, $code);
exit($code);
