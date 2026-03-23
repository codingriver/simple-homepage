#!/usr/bin/env bash
set -euo pipefail

REPORT_PATH="${1:-smoke-report.txt}"
IMAGE_TAG="${2:-local/smoke:test}"
CONTAINER_NAME="nav-smoke-test"
HOST_PORT="58080"

# 清理历史容器（避免重名冲突）
docker rm -f "$CONTAINER_NAME" >/dev/null 2>&1 || true
mkdir -p data/logs

echo "# Smoke Test Report" > "$REPORT_PATH"
echo "time: $(date -u +"%Y-%m-%dT%H:%M:%SZ")" >> "$REPORT_PATH"
echo "image: $IMAGE_TAG" >> "$REPORT_PATH"
echo "container: $CONTAINER_NAME" >> "$REPORT_PATH"

echo "[smoke] starting container..."
docker run -d \
  --name "$CONTAINER_NAME" \
  -p "${HOST_PORT}:58080" \
  -e NAV_PORT=58080 \
  -e TZ=Asia/Shanghai \
  -v "$(pwd)/data:/var/www/nav/data" \
  "$IMAGE_TAG" >/dev/null

cleanup() {
  docker rm -f "$CONTAINER_NAME" >/dev/null 2>&1 || true
}
trap cleanup EXIT

echo "[smoke] waiting for service readiness..."
READY=0
for i in $(seq 1 60); do
  if curl -fsS "http://127.0.0.1:${HOST_PORT}/login.php" >/dev/null 2>&1; then
    READY=1
    break
  fi
  sleep 1
done

if [ "$READY" -ne 1 ]; then
  echo "result: FAIL" >> "$REPORT_PATH"
  echo "reason: service not ready in 60s" >> "$REPORT_PATH"
  echo "\n## docker ps" >> "$REPORT_PATH"
  docker ps -a >> "$REPORT_PATH" 2>&1 || true
  echo "\n## docker logs" >> "$REPORT_PATH"
  docker logs "$CONTAINER_NAME" >> "$REPORT_PATH" 2>&1 || true
  exit 1
fi

LOGIN_CODE="$(curl -s -o /dev/null -w "%{http_code}" "http://127.0.0.1:${HOST_PORT}/login.php")"
SETUP_CODE="$(curl -s -o /dev/null -w "%{http_code}" "http://127.0.0.1:${HOST_PORT}/setup.php")"

HEALTH_STATUS="$(docker inspect --format='{{.State.Health.Status}}' "$CONTAINER_NAME" 2>/dev/null || echo "unknown")"

echo "result: PASS" >> "$REPORT_PATH"
echo "login.php_status: $LOGIN_CODE" >> "$REPORT_PATH"
echo "setup.php_status: $SETUP_CODE" >> "$REPORT_PATH"
echo "health_status: $HEALTH_STATUS" >> "$REPORT_PATH"

echo "\n## docker ps" >> "$REPORT_PATH"
docker ps --filter "name=$CONTAINER_NAME" >> "$REPORT_PATH" 2>&1 || true

echo "\n## tail logs" >> "$REPORT_PATH"
docker logs "$CONTAINER_NAME" | tail -n 80 >> "$REPORT_PATH" 2>&1 || true

echo "[smoke] PASS"