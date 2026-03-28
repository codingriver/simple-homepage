#!/usr/bin/env bash
# 自动冒烟测试 + 结果输出到文件
set -ue
cd /Users/mrwang/project/simple-homepage

REPORT="smoke-report-local.txt"
IMAGE="local/smoke:test"

echo "[auto-smoke] 开始冒烟测试 $(date)"
bash .github/scripts/smoke-test.sh "$REPORT" "$IMAGE" 2>&1
echo "[auto-smoke] 完成 $(date)"
echo "=== REPORT ==="
cat "$REPORT"
