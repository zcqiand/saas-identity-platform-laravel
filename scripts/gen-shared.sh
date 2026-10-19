#!/usr/bin/env bash
# scripts/gen-shared.sh — 从家族 shared 契约生成 api_manifest.json（ADR-0050 manifest 路线）
# 生成物 generated/api_manifest.json 每次 regen 先删后写——手改会被机械抹掉。
# 用法：bash scripts/gen-shared.sh   （无 DB 依赖；L4.codegen.idempotent strict 复放本脚本）
set -euo pipefail
cd "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/.."
SHARED_DIR="$(cd ../saas-identity-platform-shared && pwd)"
( cd "$SHARED_DIR" && npm run emit:openapi )
rm -f generated/api_manifest.json
php scripts/gen-manifest.php "$SHARED_DIR/generated/openapi/openapi.yaml"
