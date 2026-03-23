#!/bin/bash
# ============================================================
# 本地构建脚本（仅用于本地开发构建，不用于 CI/CD）
# 使用方式：bash local/docker-build.sh
# 依赖：local/.env（从 local/.env.example 复制）
# ============================================================
set -e

# 脚本所在目录（local/）
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
# 项目根目录（local/ 的上级）
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"

# 切换到项目根目录（Dockerfile 和 docker/ 都在这里）
cd "$PROJECT_DIR"

# 加载 local/.env 配置
if [ -f "$SCRIPT_DIR/.env" ]; then
  set -a
  . "$SCRIPT_DIR/.env"
  set +a
  echo "[INFO] Loaded config from local/.env"
else
  echo "[WARN] local/.env not found, using defaults"
  echo "[HINT] Run: cp local/.env.example local/.env"
fi

# 检测 docker compose 命令
COMPOSE_CMD="docker-compose"
docker compose version >/dev/null 2>&1 && COMPOSE_CMD="docker compose"

BUILD_USE_PROXY="${BUILD_USE_PROXY:-0}"
BUILD_PROXY_URL="${BUILD_PROXY_URL:-http://192.168.2.2:7890}"

if [ "$BUILD_USE_PROXY" = "1" ]; then
  echo "[INFO] Build proxy enabled: $BUILD_PROXY_URL"
  HTTP_PROXY="$BUILD_PROXY_URL" \
  HTTPS_PROXY="$BUILD_PROXY_URL" \
  http_proxy="$BUILD_PROXY_URL" \
  https_proxy="$BUILD_PROXY_URL" \
  NO_PROXY="localhost,127.0.0.1,::1" \
  no_proxy="localhost,127.0.0.1,::1" \
  DOCKER_BUILDKIT=0 \
  $COMPOSE_CMD -f "$SCRIPT_DIR/docker-compose.yml" build --no-cache
else
  echo "[INFO] Build proxy disabled"
  env -u HTTP_PROXY -u HTTPS_PROXY -u http_proxy -u https_proxy \
      -u ALL_PROXY -u all_proxy -u NO_PROXY -u no_proxy \
      DOCKER_BUILDKIT=0 \
      $COMPOSE_CMD -f "$SCRIPT_DIR/docker-compose.yml" build --no-cache
fi

$COMPOSE_CMD -f "$SCRIPT_DIR/docker-compose.yml" up -d
