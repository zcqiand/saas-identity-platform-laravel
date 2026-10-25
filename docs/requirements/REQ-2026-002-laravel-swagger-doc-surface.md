# REQ-2026-002 laravel 双仓补 Swagger 文档面（SSOT 派生）

| 项 | 值 |
|---|---|
| 提出人 | zcqiand |
| 提出日期 | 2026-10-25 |
| 优先级 | P1 |
| 状态 | 规划 |
| 关联 REQ | REQ-2026-003（kotlin 双仓同款先行落地，v0.2.1-20261025 prod 全绿）；REQ-2026-001（deploy 链） |

## 1. 需求描述

> 用户原话：「saas+lab laravel版增加swagger」。
>
> 背景：家族文档面现状（2026-10-25）——springboot（springdoc 根跳转）、aspnetcore
> （Swashbuckle `/swagger`）、fastapi（内置 `/docs`）、ktor 双仓（REQ-2026-003，
> shared openapi.yaml 派生）均有在线 Swagger；laravel 双仓根路径 404、composer 无任何
> swagger 依赖。本需求给 saas/lab 两 laravel 仓补 **SSOT 派生**的 Swagger 文档面：
> 不引入 l5-swagger/swagger-php 手写注解（手写 = 第二份契约面，必漂移，违反「API 面
> 只认 shared 契约生成物」suite-hard-rules §2/§4），文档内容直接来自 shared 仓
> openapi.yaml 生成物。

### 方案要点（SSOT 派生，零漂移；ktor REQ-2026-003 镜像）

1. `scripts/gen-shared.sh` 生成时把 shared 仓 `generated/openapi/openapi.yaml`
   原样落进 `public/api-docs.yaml`（**文件非目录**，生成物入库，随仓走；
   L4.codegen.idempotent strict 复放即覆盖幂等；目录形态 v0.2.0 首航翻车后改，见澄清 #6）
2. swagger-ui-dist **5.34.0** 静态件 vendor 进 `public/swagger-ui/5.34.0/`
   （css + bundle.js；laravel 无 webjar 机制、composer 无官方包——npmmirror 钉版
   下载后入库，三方 pinned 资产同字体先例，禁 CDN 运行时依赖）
3. 路由面（bootstrap `then:` 闭包与 /health 同批注册，**不挂 web 中间件组**，
   v0.2.0 首航翻车后从 routes/web.php 挪出，见澄清 #7）：
   `GET /` 302 → `/api-docs`；`GET /api-docs` 壳页（blade，引 pinned 资产 +
   喂 `/api-docs/openapi.yaml`）；`GET /api-docs/openapi.yaml` 契约原样
   （Content-Type: application/yaml）
4. prod 全开（人裁 2026-10-25 REQ-2026-003 先例照准，家族五栈先例一致）
5. 两仓镜像改动（同批），CI smoke 探针补 swagger 文档面三件 200

### 澄清记录

| 疑问 | 澄清结论 | 澄清人 | 日期 |
|---|---|---|---|
| l5-swagger（swagger-php 注解）还是喂 shared 契约？ | 喂 shared 契约生成物——手写注解=第二份契约面必漂移，违反细则 §2/§4；且路由面已是 manifest 1:1 镜像，注解是纯冗余 | REQ-2026-003 人裁先例镜像 | 2026-10-25 |
| prod 公网暴露文档面？ | prod 全开（REQ-2026-003 人裁「prod 全开」先例照准，springboot/aspnetcore/fastapi/ktor live 全开一致） | REQ-2026-003 先例镜像 | 2026-10-25 |
| 功能树要不要登记？ | 不登记。基础设施端点不进契约面/功能树（与 /health 同模式，aspnetcore/ktor 先例） | REQ-2026-003 先例镜像 | 2026-10-25 |
| 一个 REQ 还是两个？ | 一个 REQ 覆盖两仓镜像改动（REQ-2026-001/003 先例），两仓各落一份同文 REQ | 家族先例 | 2026-10-25 |
| swagger-ui 静态件从哪来？ | vendor 入库 `public/swagger-ui/5.34.0/`（css + bundle.js）——laravel 无 webjar、composer 无官方包；npmmirror（registry.npmmirror.com）钉版下载，三方 pinned 资产入库不违「可再生件不入库」（同字体先例）；bump 版本同 commit 改壳页引用与本 REQ 版本号 | Claude 提案（ktor webjar 同款自托管语义） | 2026-10-25 |

| 契约 yaml 落 public/api-docs/ 目录还是文件？ | 文件 `public/api-docs.yaml`。v0.2.0 首航红实锤：目录与壳页路由 `/api-docs` 同名互相遮蔽——静态层目录无 index，本地 cli-server 404 / frankenphp 500，而 `/api-docs/openapi.yaml` 被静态直服 200 掩盖问题 | 首航红修正（Claude 定位，人批重推） | 2026-10-25 |
| 文档路由挂 web 中间件组？ | 不挂。v0.2.0 首航红实锤：web 组 StartSession 走 database driver，无 DB 面（CI smoke 占位 env 连不上库）一律 500，/health 因在 then: 注册不受累。文档面照 /health 同模式进 bootstrap then: 闭包，零 session/DB 依赖 | 首航红修正（Claude 定位，人批重推） | 2026-10-25 |

## 2. 验收标准

| 编号 | 场景（给定） | 操作（当） | 预期（则） |
|---|---|---|---|
| AC-1 | 两仓本地起服务 | `GET /` | 302 → `/api-docs`；`/api-docs` 壳页 200；`/api-docs/openapi.yaml` 200 且为合法 OpenAPI YAML |
| AC-2 | 本地服务 openapi.yaml | 与 shared 仓契约生成物 diff | 内容一致（hash 相同）——SSOT 派生零漂移证据 |
| AC-3 | 两仓 gate 全量 | `python scripts/gate.py -p <仓>` | exit 0，L1-L5 全绿（无 skip 挂 ID） |
| AC-4 | CI tag run | smoke job | swagger 文档面三件探针（`/api-docs` + `/api-docs/openapi.yaml` + `/swagger-ui/5.34.0/swagger-ui.css`）200 进 smoke |
| AC-5 | 首航 tag 推送后 | prod 域名 `GET /` | 302 → `/api-docs` 200；`/api-docs/openapi.yaml` 200 |

## 3. 任务拆解

| 任务 ID | 任务描述 | 类型 | 负责人 | 预估 | 状态 |
|---|---|---|---|---|---|
| T-1 | 两仓 REQ-2026-002 立档（本文档镜像）+ README 台账 | 文档 | Claude | — | 已完成 |
| T-2 | saas 仓：gen-shared.sh 落 openapi.yaml + vendor swagger-ui + 路由/壳页 + 测试 + gate 全绿 | 代码 | Claude | — | 已完成 |
| T-3 | lab 仓镜像同构 + gate 全绿 | 代码 | Claude | — | 已完成 |
| T-4 | 两仓 CI smoke 补 swagger 文档面探针 | 代码 | Claude | — | 已完成 |
| T-5 | 首航：tag push（外发动作，逐项确认）→ prod 探活（AC-5） | 验证 | Claude | — | 待开始 |
| T-6 | 收账：家族档案（如需）、记忆指纹、suite gitlink | 文档 | Claude | — | 待开始 |

## 4. 功能影响（需求与功能对齐的唯一位置）

> 无功能树影响：文档面属基础设施端点（不进契约面/功能树，与 /health 同模式）。
> 不改任何业务端点行为（api.php 47/120 条契约路由零触碰）。

| 功能 ID | 功能名称 | 影响类型 | 说明 | 关联任务 |
|---|---|---|---|---|
| （无） | — | — | 基础设施端点，不进功能树 | — |

## 5. 流程影响

无（不动 docs/design/flow-function-map.md 任何流程步骤；文档面不在业务流程内）。

## 6. 风险与回滚

| 风险 | 影响面 | 缓解 | 回滚方式 |
|---|---|---|---|
| gen-shared.sh 变更触碰 manifest 生成（L4 门管辖） | 两仓生成物 | gen-manifest.php 不动，只加「copy openapi.yaml 到 public/api-docs.yaml」一步；L4.codegen.idempotent strict 复放核幂等 | git revert 生成脚本改动 + 重新生成 |
| web 路由与现有 route 前缀冲突 | 应用启动 | `/` 实测 404 无占用；`/api-docs` 非契约前缀（saas=`/api/v1/*`、lab=`/api/*`）；web.php 与 api.php 面隔离 | 路由代码单 commit 独立可 revert |
| openapi.yaml 入库后与 shared 漂移 | 文档正确性 | AC-2 hash 比对 + gen-shared.sh 每次生成强制刷新（不手编） | 重新跑 gen-shared.sh 即同步 |
| vendor 静态件引 supply-chain 疑虑 | 仓库完整性 | npmmirror 钉 exact 版本 + 入库后随 git 审计；禁 CDN/运行时拉取 | git rm 单 commit 可 revert |
| 首航 red | prod | 端口/域名不变，无 env 键增减（零 env 变化）；红则旧 tag 显式重跑（REQ-2026-001 演练过的回滚路径） | 显式 tag 重跑 deploy 脚本 |
