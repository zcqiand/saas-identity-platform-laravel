# saas-identity-platform-laravel — prod 容器（X10 段 5110，host=container）
#
# 家族 deploy 链同款（fastapi/rails/springboot/aspnetcore 先例）：
#   VPS nginx 终结 TLS → proxy_pass http://127.0.0.1:5110 → 容器 octane(frankenphp)。
#   CI deploy job build & push（latest + tag 双份）→ VPS deploy 脚本拉镜像起容器。
#
# 端口是家族契约（docs/conventions/multi-repo-family.md §6 saas X10=5110），钉死在
# CMD 里不走 env 兜底（suite 硬规则 §1：fail-fast，不留静默默认）。
#
# 运行形态（REQ-2026-001 人裁 2026-10-25；首航指纹修正同日）：laravel/octane ^2.22
# + FrankenPHP **官方镜像**（非静态二进制——v1.13.3 静态版内嵌 PHP 8.5 与栈钉 8.3
# drift，且其 php-cli 子命令要求内嵌 ≥8.6，CI 冒烟取证后弃）。镜像 tag 三钉：
# frankenphp 1.13.3（octane 要求 ≥1.13.0）+ PHP 8.3.36（php2shell 修复版，与家族
# 部署前置同版）+ bookworm（glibc）。镜像缺 pdo_pgsql 与 pcntl（octane:start 注册
# 信号用，缺则 SIGINT undefined 崩），build 期 install-php-extensions 补齐。
FROM dunglas/frankenphp:1.13.3-php8.3.36-bookworm

RUN install-php-extensions pdo_pgsql pcntl zip
# zip：composer --prefer-dist 需要（镜像 stripped 后无 zip 扩展也无 unzip/7z，
# 缺则 ZipDownloader 报 "zip extension and unzip/7z commands are both missing"）

# composer（官方 2.x 镜像拷贝，钉大版本；frankenphp 镜像不带）
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# 依赖层（composer.lock 钉版；--no-scripts 因 artisan/源码未就位，discover 在源码层后补）
COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress --no-scripts

# 应用源码（selective COPY：.env* / tests/ / docs/ / .state / .claude 不进镜像）
COPY app/ ./app/
COPY bootstrap/ ./bootstrap/
COPY config/ ./config/
COPY public/ ./public/
COPY routes/ ./routes/
COPY artisan ./
RUN php artisan package:discover

# octane 运行期需要（CI git checkout 不带空目录；frankenphp worker 会写 views/cache
# 状态；**storage/framework/octane 必须预建**——octane 写 server-state 前用
# is_writable(dirname) 探测，目录不存在直接抛 Unable to write to process ID file）
RUN mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views \
    storage/framework/logs storage/framework/octane storage/logs

EXPOSE 5110
# --admin-port 必须显式：octane 推算公式 2019+(port-8000)，端口 5110 会算出负数抛
# 「Unable to determine admin port」（CI 冒烟 2026-10-25 首航前逮住）。
# APP_KEY 由 env-file 显式提供（octane worker boot 期解析 encrypter，缺则
# MissingAppKeyException；deploy 脚本首启随机生成持久化，API 面不消费）。
CMD ["php", "artisan", "octane:start", "--server=frankenphp", "--host=0.0.0.0", "--port=5110", "--admin-port=2019"]
