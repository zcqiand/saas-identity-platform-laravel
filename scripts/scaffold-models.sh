#!/usr/bin/env bash
# scripts/scaffold-models.sh — information_schema 反推生成 Eloquent 桩（DB-First，家族 ADR-0025 口径）
# 生成区 = app/Models/Generated/ 整目录（禁手改）；手写子类在 app/Models/*.php extends
# Generated\<Model>，不归本脚本管。实际实现 = scripts/scaffold-models.mjs
# （pg driver 直读 information_schema，springboot scaffold-entities 同款）。
#
# DATABASE_URL 必填显式传入（CLAUDE.md §2 禁兜底）；生成只连本族 _dev 库——
# suite checker strict 会经 subprocess env 注入（门禁侧契约常量，非 env 兜底）。
#
# 用法：DATABASE_URL='postgresql://postgres:postgres@127.0.0.1:54321/saas_dev' \
#         bash scripts/scaffold-models.sh
# 前提：本机 PG 已起且带 -p 54321（conf 默认 5432 与家族契约不符）
#
# 退出码：
#   0 — scaffold OK（生成区与 git HEAD 一致）
#   1 — DB 不通 / 生成失败 / 生成区与 HEAD 漂移（含 untracked）

set -euo pipefail

# 不要用 `git rev-parse --show-toplevel` —— 本仓是 submodule，
# 该命令返回外层 xr-code-suite 根而不是本仓根，让生成区路径算错
# （springboot/kotlin 同款坑）。用脚本自身所在目录的父目录锚定到仓根。
cd "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/.."

ZONE=app/Models/Generated

echo "[scaffold-models] step 1/4 — node scripts/scaffold-models.mjs"
node scripts/scaffold-models.mjs

# step 2/4: scaffold 产出是裸 PHP，直接跑 pint 修复模式就地排版——
# 保证 gate L1（pint --test）干净 + 输出确定（字节幂等前提）。
echo "[scaffold-models] step 2/4 — pint app/Models/Generated（修复模式）"
php vendor/laravel/pint/builds/pint "$ZONE" --config pint.json

echo "[scaffold-models] step 3/4 — 漂移护栏（tracked diff ∪ untracked）"
if ! git diff --exit-code --quiet -- "$ZONE" 2>/dev/null \
  || [ -n "$(git ls-files --others --exclude-standard -- "$ZONE")" ]; then
  echo "[scaffold-models] FATAL: 生成区与 git HEAD 不一致（含 untracked 新文件）" >&2
  echo "[scaffold-models]        处理：确认库 schema 变更合法（shared 已 db:migrate 且连的是本族库）后" >&2
  echo "[scaffold-models]        git add $ZONE && git commit，再重跑本脚本核绿" >&2
  exit 1
fi
echo "[scaffold-models] step 4/4 — OK — 生成区已与 HEAD 同步（DB-First）"

# ADR-0026 §2: 写 last-gen-shared.json marker（DB 类别），失败不阻塞 scaffold。
ROOT="$(pwd)"
SHARED_DIR="$(cd "${ROOT}/../saas-identity-platform-shared" && pwd)"
SHARED_SHA=$(cd "$SHARED_DIR" && git rev-parse HEAD)
MARKER="$ROOT/.state/last-gen-shared.json"
mkdir -p "$ROOT/.state"

if python3 - "$MARKER" "$SHARED_SHA" "$(basename "$0")" "$(basename "$ROOT")" <<'PYEOF'
import datetime, json, sys

marker_path, shared_sha, cmd, repo = sys.argv[1:5]
try:
    with open(marker_path, encoding="utf-8") as f:
        marker = json.load(f)
except (FileNotFoundError, json.JSONDecodeError):
    marker = {}

# 5.77（2026-09-21 人裁立项）：同 sha 零写入——目标通道 synced_sha 与现存 marker 相同
# → 整个 marker 文件零写入（时间戳/mtime 保持原值，字节级幂等）；sha 真变才全量写
# （新 sha + 新时间戳）。判据只比 sha，时间戳不参与；JSON 形状/key 名一概不动。
if cmd.startswith("gen-shared"):
    channel = "api_synced"
elif cmd.startswith(("scaffold", "sync-db", "pull-schema")):
    channel = "db_synced"
else:
    channel = None

if channel is not None and marker.get(channel + "_sha") == shared_sha:
    print("[marker] %s_sha unchanged (%s...) - zero write, keep timestamp (5.77)"
          % (channel, shared_sha[:12]))
    sys.exit(3)

now = datetime.datetime.now(datetime.timezone.utc).isoformat()
if channel is not None:
    marker[channel + "_sha"] = shared_sha
    marker[channel + "_at"] = now
    marker[channel + "_cmd"] = cmd

# shared_sha 取「最近一次同步」对应的 sha：ISO-8601 UTC 时间戳字典序==时间序。
# 勿用 max(sha)——SHA 字典序不是 git 时间序（5.21 事故）。
entries = [
    (marker.get(k + "_at", ""), marker[k + "_sha"])
    for k in ("api_synced", "db_synced")
    if marker.get(k + "_sha")
]
marker["shared_sha"] = max(entries)[1] if entries else shared_sha
marker["consumer_repo"] = repo

with open(marker_path, "w", encoding="utf-8") as f:
    json.dump(marker, f, ensure_ascii=False, indent=2)
    f.write("\n")
PYEOF
then
  echo "[scaffold-models]    ADR-0026 marker 已落盘: $MARKER (shared HEAD ${SHARED_SHA:0:7})"
else
  rc=$?
  if [ "$rc" -eq 3 ]; then
    echo "[scaffold-models]    ADR-0026 marker sha 未变，零写入（5.77 同 sha 不刷时间戳）: $MARKER"
  else
    echo "[scaffold-models]    WARN: marker 写失败（python3 缺失？）—— staleness 将报 UNKNOWN" >&2
  fi
fi
