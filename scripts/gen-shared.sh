#!/usr/bin/env bash
# scripts/gen-shared.sh — 从家族 shared 契约生成 api_manifest.json（ADR-0050 manifest 路线）
# 生成物 generated/api_manifest.json 每次 regen 先删后写——手改会被机械抹掉。
# Swagger 文档面真源（REQ-2026-002，ktor REQ-2026-003 同款）：shared emit 的 openapi.yaml
# 原样落 public/api-docs.yaml（文件，非目录），应用 /api-docs/openapi.yaml 路由透传——
# 零手写注解，契约漂移在 regen 即同步。禁落 public/api-docs/ 目录：与壳页路由 /api-docs
# 同名互相遮蔽（v0.2.0 首航红指纹：静态层目录无 index → cli-server 404 / frankenphp 500）。
# 用法：bash scripts/gen-shared.sh   （无 DB 依赖；L4.codegen.idempotent strict 复放本脚本）
set -euo pipefail
cd "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/.."
SHARED_DIR="$(cd ../saas-identity-platform-shared && pwd)"
( cd "$SHARED_DIR" && npm run emit:openapi )
rm -f generated/api_manifest.json
php scripts/gen-manifest.php "$SHARED_DIR/generated/openapi/openapi.yaml"
cp "$SHARED_DIR/generated/openapi/openapi.yaml" public/api-docs.yaml
