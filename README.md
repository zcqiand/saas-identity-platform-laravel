# SaaS 身份平台 Laravel 端

SaaS 身份平台 Laravel 端（`saas-identity-platform-laravel`）——一句话系统定位（待补）。

本仓为《（书稿信息待补）》案例（待补）的可运行配套工程，是书稿代码块的 **source of truth**。

## 快速开始

```bash
# 前置：php 8.3+ 与 composer
composer install                                     # 安装依赖（composer.lock 钉版）
php vendor/laravel/pint/builds/pint --test           # L1 格式
php vendor/phpstan/phpstan/phpstan analyse --memory-limit=1G   # L2 静态（larastan）
php vendor/phpunit/phpunit/phpunit                   # L4 测试
php artisan octane:start --host=127.0.0.1 --port=5110   # 本地起服务（octane，.env SERVER_PORT=5110）
```

## 功能特性

按特性分点描述核心功能（见 `docs/functions/function-tree.md`）。

## 技术栈

| 技术 | 版本 |
| :--- | :--- |
| PHP / Laravel | ^8.3 / ^12.0（composer.lock 钉版，octane 承载） |

> 依赖版本与 `version-lock.json` 的 `version_lock` 一致，不引入 lock 外的库。

## 配套书籍及章节映射

| 章 | 主题 | 对应源文件 |
| :--- | :--- | :--- |
| （待补） | | |

## 快速链接

- [CLAUDE.md](CLAUDE.md) — 开发约定与编码规范
- [系统架构.md](docs/ARCHITECTURE.md) — 结构 / 边界 / 数据流 / 决策
- [功能规格.md](docs/functions/function-tree.md) — 功能名称、描述与验收标准
- [未来开发计划](PLAN.md) — 待办与迭代方向
- [更新日志](CHANGELOG.md) — 版本变更记录
