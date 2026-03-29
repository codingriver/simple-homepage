#!/usr/bin/env bash
# 自动冒烟测试 + 结果输出到文件（在项目根目录执行：bash smoke/run-smoke.sh）
# 可选：SMOKE_LOAD_MAX_SEC=3.0  单页 curl time_total 上限（秒），超出则 load_* 失败
set -ue
SMOKE_DIR="$(cd "$(dirname "$0")" && pwd)"
ROOT="$(cd "$SMOKE_DIR/.." && pwd)"
cd "$ROOT"

REPORT="${SMOKE_DIR}/smoke-report-local.txt"
IMAGE="local/smoke:test"
export SMOKE_LOAD_MAX_SEC="${SMOKE_LOAD_MAX_SEC:-3.0}"

echo "[auto-smoke] 开始冒烟测试 $(date)  (SMOKE_LOAD_MAX_SEC=${SMOKE_LOAD_MAX_SEC})"
bash "$SMOKE_DIR/smoke-test.sh" "$REPORT" "$IMAGE" 2>&1
echo "[auto-smoke] 完成 $(date)"
echo "=== REPORT ==="
cat "$REPORT"
