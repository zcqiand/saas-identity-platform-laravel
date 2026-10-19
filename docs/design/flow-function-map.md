# Flow × Function 映射 — saas-shared

> 用途：跨仓运行时流程 ↔ function-tree 子项 ID 映射。本文件被 L5 alignment 脚本读取：
> 1. 「已上线但不在任何流程」告警需要本文件列出 flow ↔ ID 关联
> 2. `### 孤儿功能` 段是孤儿白名单（脚本 `orphan_whitelist()` 解析）
>
> **2026-09-07 模块重组**：本文件 §1 流程涉及 ID 全部按新结构更新（迁移表见
> [function-tree.md §0.x](../functions/function-tree.md#0x-模块重组迁移记录2026-09-07)）。
> 旧 ID 不再使用；新 ID 与 function-tree.md 一一对应。

## 1. 主要流程

### 1.1 OAuth 登录 + session 建立

触发：用户未登录访问业务前端 → 业务前端跳 saas SSO 登录页

| 步骤 | 端点 | 涉及 ID |
|---|---|---|
| 用户输密码 | `POST /auth/login` | **M01.F04.I01** |
| 业务后端 authorize 跳 saas | 302 → saas 登录页带 client_id | (业务仓；saas 仓只列下游)|
| saas authorize 检查 session | `POST /oauth/authorize` | **M04.F03.I01** |
| token 交换 + 刷新 | `POST /oauth/token`（双 grant）| **M04.F03.I02** |
| 业务后端验 JWT → 业务 API | — | (业务仓)|
| 登出（本地清理）| `POST /auth/logout` | **M01.F04.I06** |

详见 [oauth-architecture.md](oauth-architecture.md) + [REQ-2026-020](../requirements/REQ-2026-020-oauth-session-real-auth.md)。

### 1.2 me/menus 装配

触发：登录后用户进入业务首页，前端调 `GET /me/menus` 渲染侧边栏。

涉及 ID：**M04.F04.I08**（按新结构合并到一个 I，覆盖 session 校验 + 角色联表 + 父链补全 + client 分组的端到端逻辑）。

详见 [me-menus.md](me-menus.md)。

### 1.3 用户 / 成员 CRUD + 角色分配

触发：tenant admin 在用户管理页操作。

| 操作 | 端点 | 涉及 ID |
|---|---|---|
| 列表 | `GET /tenants/{t}/users` | **M00.F02.I01** |
| 创建（直接落地）| `POST /tenants/{t}/users` | **M00.F02.I02** |
| 详情 | `GET .../users/{u}` | **M00.F02.I03** |
| 更新 | `PATCH .../users/{u}` | **M00.F02.I04** |
| 删除 | `DELETE .../users/{u}` | **M00.F02.I05** |
| 邀请用户 | `POST /tenants/{t}/invitations` | **M00.F02.I06** |
| 接受邀请 | `POST /invitations/{token}/accept` | **M00.F02.I07** |
| 状态切换 | `PATCH .../users/{u}/status` | **M00.F02.I08** |
| 分配角色 | `PUT .../users/{u}/roles` | **M01.F02.I01** |

详见 [user-model.md](user-model.md) + [REQ-2026-024](../requirements/REQ-2026-024-tenant-switch-and-user-invite.md)。

### 1.4 API Key 生命周期（已废弃）

> **状态：已废弃** — 目标 DDL 不再包含 `api_keys`，保留历史编号供合同测试对齐。

### 1.5 审计日志查询 + 留存（已废弃）

> **状态：已废弃** — 目标 DDL 不再包含 `audit_events` / `audit_retention_policies`。

### 1.6 跨租户切换（当前用户视图）

触发：用户在多租户 UI 切换当前 tenant scope。

| 操作 | 端点 | 涉及 ID |
|---|---|---|
| whoami | `GET /me` | **M01.F01.I01** |
| 列我的租户 | `GET /me/tenants?clientId=...` | **M01.F03.I01** |
| 切换 | `POST /me/tenants/{tenantId}/switch` | **M01.F03.I02** |

详见 [REQ-2026-024](../requirements/REQ-2026-024-tenant-switch-and-user-invite.md)。

### 1.7 菜单结构维护

触发：app admin 在菜单管理页拖动 / 移动节点。

| 操作 | 端点 | 涉及 ID |
|---|---|---|
| 菜单 CRUD | `GET/POST/PATCH/DELETE /admin/clients/{c}/menus[/{m}]` | **M04.F04.I01-I05** |
| 同级排序 | `PUT /admin/clients/{c}/menus/{m}/reorder` | **M04.F04.I06** |
| 切父级 | `PATCH /admin/clients/{c}/menus/{m}/parent` | **M04.F04.I07** |

详见 [REQ-2026-025](../requirements/REQ-2026-025-menu-structure-move-reorder.md)。

### 1.8 租户应用订阅（tenant-admin 配置面）

触发：tenant admin 在租户应用页管理本租户订阅了哪些应用。

| 操作 | 端点 | 涉及 ID |
|---|---|---|
| 列出订阅 | `GET /tenants/{t}/applications` | **M00.F05.I01** |
| 订阅应用 | `POST /tenants/{t}/applications` | **M00.F05.I02** |
| 改订阅状态/到期时间 | `PATCH /tenants/{t}/applications/{clientId}` | **M00.F05.I03** |
| 取消订阅 | `DELETE /tenants/{t}/applications/{clientId}` | **M00.F05.I04** |

详见 [tenant-applications.md](tenant-applications.md)。

---

## 2. 孤儿功能

> 孤儿 = 已上线但未在上述任何流程里出现。如果某个已上线 ID 不在 §1 任何步骤表里，加到这里给 L5 白名单吃掉。

<!-- 当无孤儿时保留空段：L5 alignment 脚本识别 `### 孤儿功能` 这个段名作为锚点，删除本注释即可 -->

（当前无孤儿功能。所有新结构下已上线 ID 均映射到 §1 流程之一。）
