# REQ-2026-001 laravel 部署链建设（Octane 容器 + deploy 物料 + CI deploy job）

| 项 | 值 |
|---|---|
| 提出人 | zcqiand |
| 提出日期 | 2026-10-25 |
| 优先级 | P1 |
| 状态 | 已上线（2026-10-25 T-1～T-4 全闭环；首航 v0.1.4-20261025，prod `https://saas-laravel.xiangru.uk/health` 200 `{"ok":true,"mode":"laravel"}` 实证） |
| 关联 ADR | [ADR-0018 host=container 端口简化](../../../../docs/adr/0018-host-equals-container-port-simplification.md)、[ADR-0027 consumer strict subset](../../../../docs/adr/0027-consumer-strict-subset.md) |

## 1. 需求描述

用户原话照抄（2026-10-24/25，两次）：

> 把 saas+lab laravel进 deploy 链立成一项需求排期

> 参照 saas-flutter 先例，laravel 进 deploy 链要做的：api 容器（php-fpm/octane + nginx）→ host 5110/5210、deploy 脚本、vhost、https://\<family\>-laravel.xiangru.uk 证书、探活——以及部署机 PHP ≥ 8.3.36（php2shell 前置检查已落总账）

理解：saas-laravel（X10 槽，5110）2026-10-19 骨架进家族，家族档案明记「未进 deploy 链」。本需求把本仓补齐到与 fastapi/rails/springboot/aspnetcore 同构的部署能力：Dockerfile + deploy 物料 + CI deploy job + vhost + 域名证书 + 探活。先例基线取 fastapi 部署链 twin REQ（saas 仓 REQ-2026-008 / lab 仓 REQ-2026-009，2026-10-02 已验收实航）；用户点名的 saas-flutter 先例只取其物料清单形状（五件套 + 域名证书 + 探活双条），不取其静态双层形态——分叉经澄清人裁，见下。

### 澄清记录

| 疑问 | 澄清结论 | 澄清人 | 日期 |
|---|---|---|---|
| 用户原话「php-fpm/octane + nginx」与「参照 saas-flutter 先例」（静态容器双层 ：80→host）是两种形态；fastapi/rails 后端先例是单层 host=container | 人裁**引入 laravel/octane**：单容器单进程，octane 钉 CMD 端口 host=container 5110（ADR-0018 单层姿态），不采用 php-fpm+nginx 双层。代价：composer 新增依赖，须同步钉版（本仓无 version-lock.json，钉 composer.lock + CLAUDE.md §3 技术栈表）；Octane server 选型（frankenphp 优先，免编译扩展）实施时定 | zcqiand | 2026-10-25 |
| 「立成一项需求」vs fastapi 先例每仓一份 twin REQ | 人裁双仓各一份 twin REQ（本仓 REQ-2026-001 + lab-laravel REQ-2026-001，内容镜像、各归各仓门禁） | zcqiand | 2026-10-25 |
| 部署链是否同批把 laravel 纳入 contract-test live 比对 | 人裁**不同步 CT**（fastapi 先例：部署链不碰 API 面，硬规则 §2 不触发）；laravel 进 CT CONTRACT_TARGETS 另立需求 | zcqiand | 2026-10-25 |

### 首航取证（2026-10-25，v0.1.2→v0.1.4 三轮）

| # | 指纹 | 修正 |
|---|---|---|
| 1 | octane adminPort 推算公式 `2019+(port-8000)` 对 5110 算出负数抛 `Unable to determine admin port` | `--admin-port=2019` 显式进 Dockerfile CMD + ci.yml 冒烟双处 |
| 2 | frankenphp v1.13.3 **静态二进制**内嵌 PHP 8.5.12 与栈钉 8.3 drift；其 `php-cli` 子命令要求内嵌 ≥8.6 且报错仍 exit 0（假成功） | 弃静态路线，换官方镜像 `dunglas/frankenphp:1.13.3-php8.3.36-bookworm` |
| 3 | 官方镜像缺 pdo_pgsql / pcntl（ZTS，octane:start 缺 pcntl 抛 `Undefined constant SIGINT`）/ zip（composer --prefer-dist 报 ZipDownloader missing）/ composer | build 期 `install-php-extensions pdo_pgsql pcntl zip` + `COPY --from=composer:2` |
| 4 | `storage/framework/octane` 空目录 git 不跟踪 → octane 写 server-state 前探 `is_writable(dirname)` 抛 `Unable to write to process ID file` | 镜像内 mkdir 预建 |
| 5 | **APP_KEY 是 octane worker boot 期硬依赖**（缺则 `MissingAppKeyException`；worker 把异常写不可见 STDERR，探活超时是唯一症状）——推翻「API-only 无 APP_KEY 依赖」立项假设 | APP_KEY 进 env 契约（11→12 key），deploy 脚本随机生成持久化；`.env.example`/`.env.test` 补键（key 集合 11→12，早期文档「11 key」为漏计） |
| 6 | `COPY database/`：DB-First 无 migrations，目录 git 内不存在 → build checksum 失败 | 删行 |

### 需求边界

| 项 | 决定 |
|---|---|
| 范围 | 本仓 + lab-management-system-laravel 两仓，各一套部署链（lab 侧见其仓 REQ-2026-001） |
| 运行形态 | laravel/octane 单容器单进程；octane:start 钉 CMD 端口 5110（家族端口契约，不走 env 兜底） |
| 端口 | X10 段 saas=5110（multi-repo-family.md §6，host=container 单层） |
| deploy 物料 | Dockerfile + deploy/deploy 脚本 + deploy/nginx-vps.conf.example + ci.yml deploy job（tag 触发；build&push latest+tag 双份；appleboy/ssh-action 调 VPS 脚本） |
| 域名/证书 | `https://saas-laravel.xiangru.uk`（nginx 反代 `127.0.0.1:5110`）；泛域证书 NGINX_CERT_BASENAME=xiangru-uk |
| 探活 | `GET /health` → 200 `{"ok":true,"mode":"laravel"}`（家族形状，bootstrap/app.php 已注册，不进契约面）；CI 探活 120s |
| 前置 | 部署机 PHP ≥ 8.3.36（php2shell 前置检查已落总账 laravel-stack-ledger） |
| 库 | saas_prod（prod PG 100.79.128.25:5432；DB-First 无迁移，直连既有 schema） |
| env 契约 | 本仓是 IdP 本体栈：无 LAB_*/服务账号 env；JWT/密钥沿 saas-rails 先例缺失时随机生成持久化 |
| CT | 不同步 contract-test（见澄清记录） |

## 2. 验收标准

| 编号 | 场景（给定） | 操作（当） | 预期（则） |
|---|---|---|---|
| AC-1 | 本仓 CI 就绪 | push `v*` tag | deploy job 触发：镜像 build&push（latest+tag 双份）→ VPS 起容器，host 5110 监听 |
| AC-2 | 容器已起 | `GET /health` | 200 `{"ok":true,"mode":"laravel"}`；CI 探活 120s 内过 |
| AC-3 | vhost + 证书已渲染 | 访问 `https://saas-laravel.xiangru.uk/health` | 200（nginx -t 过、泛域证书生效） |
| AC-4 | 线上异常 | VPS 上以旧 tag 重跑 deploy 脚本 | 服务恢复到旧版本（vhost 渲染幂等） |
| AC-5 | 引入 octane | composer require + 钉版 | composer.lock 更新且 CLAUDE.md §3 技术栈表同步；全量门（L1/L2/L4）绿 |

## 3. 任务拆解

| 任务 ID | 任务描述 | 类型 | 负责人 | 预估 | 状态 |
|---|---|---|---|---|---|
| T-1 | composer 新增 laravel/octane ^2.22（v2.22.0；composer.lock + CLAUDE.md §3 同步）+ Dockerfile（octane:start --server=frankenphp 钉 CMD 5110 + --admin-port=2019 显式；dunglas/frankenphp:1.13.3-php8.3.36-bookworm 官方镜像三钉 + install-php-extensions pdo_pgsql/pcntl/zip + storage/framework/octane 预建 + package:discover；静态二进制路线首航取证后弃） | 实现 | — | — | 已完成（2026-10-25；全门绿 L0/L0.5/L1/L2/L4 151 tests/L5） |
| T-2 | deploy/ 三件（deploy 脚本 + nginx-vps.conf.example + setup-vps.sh，镜像 fastapi 生产验证版机制；cert 归一化 sed 链、env 契约 = .env.example 全集 12 key（含 APP_KEY）、APP_KEY/JWT 随机生成持久化、setup 内置部署机 PHP ≥ 8.3.36 前置检查） | 实现 | — | — | 已完成（2026-10-25；`sh -n` 过） |
| T-3 | ci.yml（test job：pint + larastan + octane 镜像装配冒烟 = docker build + run + 探 /health，prod 同款镜像验证；deploy job：tag 触发 `tags: ["v*"]` + latest/tag 双镜像 + ssh-action 调 VPS 脚本） | 实现 | — | — | 已完成（2026-10-25） |
| T-4 | prod 上线（外向动作）：GH secrets 核对（DOCKER_USERNAME/DOCKER_PASSWORD/VPS_HOST/VPS_USER/VPS_SSH_KEY/PG_HOST/PG_PASSWORD/JWT_SIGNING_KEY；vars NGINX_DOMAIN/NGINX_CERT_BASENAME）+ 部署机 PHP ≥ 8.3.36 前置 + 首个 tag push 首航——2026-10-25 人批「都由你来执行」 | 运维 | — | — | 已完成（2026-10-25；secrets 同源配置 + v0.1.2→v0.1.4 三轮首航取证（见首航取证节）→ prod /health 200 实证） |

## 4. 功能影响（需求与功能对齐的唯一位置）

无——**影响功能数 0，不登记功能树**。部署链不是 API 端点/业务能力；依据 fastapi 部署链 twin REQ（saas REQ-2026-008 §4 / lab REQ-2026-009 §4）同款三重依据：ADR-0027 subset invariant / 交付列收口 / infra 专属模块段全家族退役先例。`docs/functions/function-tree.md` 本次零改动。

## 5. 风险与回滚

- 首次 tag-deploy 前必须确认 GitHub 仓库 Secrets（DOCKER_USERNAME/DOCKER_PASSWORD/VPS_HOST/VPS_USER/VPS_SSH_KEY）与 vars（NGINX_DOMAIN/NGINX_CERT_BASENAME）已配置——缺则 deploy job fail-fast（脚本自检）。
- 部署机 PHP ≥ 8.3.36 前置检查（php2shell；指纹已落总账 laravel-stack-ledger）。
- 本机无 docker：~~镜像构建验证发生在 GH Actions 首航~~ 已闭（2026-10-25 ci.yml 冒烟改 docker build+run+探 /health，prod 同款镜像每 push 都验）；脚本须过 `sh -n` 并与 fastapi/rails 生产验证版逐段镜像。
- Octane 长进程形态与 fpm 的差异（静态资源、超时、worker 数）实施时按家族 SSE/探活指纹核对（deploy-chain-fingerprints）。
- 回滚：VPS 上 `docker run` 旧 tag 重跑 deploy 脚本；nginx vhost 渲染幂等。
