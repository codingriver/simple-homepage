#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
ROOT_DIR="$(dirname "$SCRIPT_DIR")"

if docker compose version >/dev/null 2>&1; then
  COMPOSE_CMD=(docker compose)
else
  COMPOSE_CMD=(docker-compose)
fi

COMPOSE_FILES=(
  -f "$ROOT_DIR/local/docker-compose.yml"
  -f "$ROOT_DIR/local/docker-compose.dev.yml"
  -f "$ROOT_DIR/local/docker-compose.test.yml"
)

RUNNING_SERVICES="$("${COMPOSE_CMD[@]}" "${COMPOSE_FILES[@]}" ps --services --status running || true)"
TEST_SERVICES="$(printf '%s\n' "$RUNNING_SERVICES" | grep -E '^(playwright-full|playwright-mobile|lighthouse)$' || true)"
PLAYWRIGHT_SERVICES="$(printf '%s\n' "$TEST_SERVICES" | grep -E '^playwright-' || true)"
LIGHTHOUSE_SERVICES="$(printf '%s\n' "$TEST_SERVICES" | grep -E '^lighthouse$' || true)"

count_lines() {
  local text="$1"
  if [ -z "$text" ]; then
    echo 0
  else
    printf '%s\n' "$text" | wc -l | tr -d ' '
  fi
}

TOTAL_COUNT="$(count_lines "$TEST_SERVICES")"
PLAYWRIGHT_COUNT="$(count_lines "$PLAYWRIGHT_SERVICES")"
LIGHTHOUSE_COUNT="$(count_lines "$LIGHTHOUSE_SERVICES")"

echo "Running test services: $TOTAL_COUNT"
echo "Playwright services: $PLAYWRIGHT_COUNT"
echo "Lighthouse services: $LIGHTHOUSE_COUNT"

if [ "$TOTAL_COUNT" -eq 0 ]; then
  echo
  echo "(none)"
  exit 0
fi

echo
printf '%s\n' "$TEST_SERVICES"
