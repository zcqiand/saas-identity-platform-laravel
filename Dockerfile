# saas-identity-platform-laravel — prod 容器（X10 段 5110，host=container）
#
# 家族 deploy 链同款（fastapi/rails/springboot/aspnetcore 先例）：
#   VPS nginx 终结 TLS → proxy_pass http://127.0.0.1:5110 → 容器 octane(frankenphp)。
#   CI deploy job build & push（latest + tag 双份）→ VPS deploy 脚本拉镜像起容器。
#
# 端口是家族契约（docs/conventions/multi-repo-family.md §6 saas X10=5110），钉死在
# CMD 里不走 env 兜底（suite 硬规则 §1：fail-fast，不留静默默认）。
#
# 运行形态（REQ-2026-001 人裁 2026-10-25）：laravel/octane ^2.22 + FrankenPHP
# v1.13.3 静态二进制（免编译扩展；单容器单进程）。octane:start 在 php CLI 下拉起
# frankenphp worker——worker 的 PHP 运行时与扩展由 frankenphp 静态构建自带
# （pdo_pgsql 在列），镜像内 php CLI 只承担 composer 安装与 artisan 启动命令；
# 下方 build 期断言防「静态构建缺扩展」漂到运行时才炸（fastapi 首航同款纪律）。
FROM php:8.3-cli

RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev git unzip \
    && docker-php-ext-install pdo_pgsql \
    && rm -rf /var/lib/apt/lists/*

# composer（官方 2.x 镜像拷贝，钉大版本）
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# FrankenPHP 静态二进制（版本钉死，≥ octane 要求 1.13.0；gnu 变体=Debian glibc 基底）
ADD https://github.com/php/frankenphp/releases/download/v1.13.3/frankenphp-linux-x86_64-gnu /usr/local/bin/frankenphp
RUN chmod 0755 /usr/local/bin/frankenphp \
    && frankenphp build-info > /dev/null \
    && frankenphp php-cli -m | grep -q pdo_pgsql \
    || { echo "FATAL: frankenphp static build lacks pdo_pgsql"; exit 1; }

WORKDIR /app

# 依赖层（composer.lock 钉版；--no-scripts 因 artisan/源码未就位，discover 在源码层后补）
COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress --no-scripts

# 应用源码（selective COPY：.env* / tests/ / docs/ / .state / .claude 不进镜像）
COPY app/ ./app/
COPY bootstrap/ ./bootstrap/
COPY config/ ./config/
COPY database/ ./database/
COPY public/ ./public/
COPY routes/ ./routes/
COPY artisan ./
RUN php artisan package:discover

# octane 运行期需要（CI git checkout 不带空目录；frankenphp worker 会写 views/cache 状态）
RUN mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/framework/logs storage/logs

EXPOSE 5110
# --admin-port 必须显式：octane 推算公式 2019+(port-8000)，端口 5110 会算出负数抛
# 「Unable to determine admin port」（CI 冒烟 2026-10-25 首航前逮住）。
CMD ["php", "artisan", "octane:start", "--server=frankenphp", "--host=0.0.0.0", "--port=5110", "--admin-port=2019"]
