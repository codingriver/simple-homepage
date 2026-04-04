#!/usr/bin/env bash
set -euo pipefail

CONTAINER_NAME="${CONTAINER_NAME:-simple-homepage}"
HOST_PORT="${HOST_PORT:-58080}"
CHECK_HOSTS=("$@")

if [ ${#CHECK_HOSTS[@]} -eq 0 ]; then
  CHECK_HOSTS=(
    admin.303066.xyz
    nas.303066.xyz
    mp.303066.xyz
    gospeed.303066.xyz
    qb1.303066.xyz
  )
fi

if ! docker ps --format '{{.Names}}' | grep -qx "$CONTAINER_NAME"; then
  echo "[FAIL] container not running: $CONTAINER_NAME"
  exit 1
fi

echo "[INFO] container=$CONTAINER_NAME port=$HOST_PORT"
echo "[INFO] checking hosts: ${CHECK_HOSTS[*]}"

echo
echo "== nav default server config =="
docker exec "$CONTAINER_NAME" sh -lc "sed -n '1,8p' /etc/nginx/http.d/nav.conf"

echo
echo "== generated domain servers =="
docker exec "$CONTAINER_NAME" sh -lc "grep -n 'server_name .*303066.xyz' /etc/nginx/http.d/nav-proxy-domains.conf | sed -n '1,40p'"

echo
FAIL=0
for host in "${CHECK_HOSTS[@]}"; do
  echo "== $host =="
  out="$(docker exec "$CONTAINER_NAME" sh -lc "curl -I --max-time 5 -H 'Host: $host' http://127.0.0.1:${HOST_PORT}/ 2>/dev/null | tr -d '\r' | sed -n '1,10p'" || true)"
  echo "$out"
  loc="$(printf '%s\n' "$out" | sed -n 's/^Location: //p' | head -1)"
  expected="https://nav.303066.xyz/login.php?redirect=https://${host}/"
  if [ "$loc" = "$expected" ]; then
    echo "[PASS] redirect ok"
  else
    echo "[FAIL] redirect mismatch"
    echo "       expected: $expected"
    echo "       got     : $loc"
    FAIL=1
  fi
  echo
done

exit $FAIL
