#!/bin/bash
# ============================================================
# 项目打包脚本
# 用法：bash pack.sh
# 输出：nav-portal-v2.1-YYYYMMDD.tar.gz
# ============================================================
set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"
NAV_DIR="${SCRIPT_DIR}"
DATE=$(date +%Y%m%d)
OUT="${PROJECT_ROOT}/nav-portal-v2.1-${DATE}.tar.gz"

echo "[pack] 打包导航网站..."

tar -czf "$OUT" \
    -C "$(dirname "$NAV_DIR")" \
    --exclude='nav-portal/data/users.json' \
    --exclude='nav-portal/data/config.json' \
    --exclude='nav-portal/data/sites.json' \
    --exclude='nav-portal/data/ip_locks.json' \
    --exclude='nav-portal/data/.installed' \
    --exclude='nav-portal/data/backups' \
    --exclude='nav-portal/data/logs' \
    --exclude='nav-portal/data/favicon_cache' \
    --exclude='nav-portal/data/bg' \
    --exclude='nav-portal/.env' \
    --exclude='nav-portal/nav-data' \
    --exclude='nav-portal/.git' \
    nav-portal

echo "[pack] 完成：$OUT"
ls -lh "$OUT"
