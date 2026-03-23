#!/bin/bash
set -e

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$SCRIPT_DIR"

if [ -f .env ]; then
  set -a
  . ./.env
  set +a
fi

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
  $COMPOSE_CMD build --no-cache
else
  echo "[INFO] Build proxy disabled"
  env -u HTTP_PROXY -u HTTPS_PROXY -u http_proxy -u https_proxy \
      -u ALL_PROXY -u all_proxy -u NO_PROXY -u no_proxy \
      DOCKER_BUILDKIT=0 \
      $COMPOSE_CMD build --no-cache
fi

$COMPOSE_CMD up -d
