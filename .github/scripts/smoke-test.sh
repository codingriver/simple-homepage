#!/usr/bin/env bash
# Nav Portal Full Smoke Test
set -ue
# 注意：不用 pipefail，避免管道中 grep/curl 返回非零时意外退出

echo "[smoke] START pid=$$ pwd=$(pwd)"
echo "[smoke] IMAGE=${2:-local/smoke:test}"

REPORT_PATH="${1:-smoke-report.txt}"
IMAGE_TAG="${2:-local/smoke:test}"
CONTAINER_NAME="nav-smoke-test"
HOST_PORT="58081"
BASE="http://127.0.0.1:${HOST_PORT}"

# 使用工作目录下的临时目录，避免 /tmp 在 GitHub Actions runner 上的权限问题
SMOKE_TMPDIR="$(pwd)/.smoke_tmp_$$"
CJ="${SMOKE_TMPDIR}/cj.txt"
DATA_DIR="${SMOKE_TMPDIR}/nav_data"
EXPORT_FILE="${SMOKE_TMPDIR}/export.json"

PASS=0; FAIL=0; SKIP=0
DETAILS=""

docker rm -f "$CONTAINER_NAME" >/dev/null 2>&1 || true
mkdir -p "${DATA_DIR}/logs" "${DATA_DIR}/favicon_cache" "${DATA_DIR}/bg" "${DATA_DIR}/backups"
# 确保容器内 navwww(uid=1000) 可写
chmod -R 777 "${DATA_DIR}"

printf '# Nav Portal Full Smoke Test Report\ntime: %s\nimage: %s\n' \
  "$(date -u +"%Y-%m-%dT%H:%M:%SZ")" "$IMAGE_TAG" > "$REPORT_PATH"

cleanup() {
  docker rm -f "$CONTAINER_NAME" >/dev/null 2>&1 || true
  # 容器内写入的文件归属容器用户，runner 无权直接删除，借助 docker 以 root 清理
  if [ -d "$SMOKE_TMPDIR" ]; then
    docker run --rm -v "${SMOKE_TMPDIR}:/cleanup" alpine sh -c 'rm -rf /cleanup/*' >/dev/null 2>&1 || true
    rm -rf "$SMOKE_TMPDIR" 2>/dev/null || true
  fi
}
trap cleanup EXIT

log_case() {
  local n="$1" r="$2" d="${3:-}"
  if   [ "$r" = "PASS" ]; then PASS=$((PASS+1)); printf '  [PASS] %s\n' "$n";        DETAILS="${DETAILS}PASS|${n}\n"
  elif [ "$r" = "SKIP" ]; then SKIP=$((SKIP+1)); printf '  [SKIP] %s\n' "$n";        DETAILS="${DETAILS}SKIP|${n}: ${d}\n"
  else                          FAIL=$((FAIL+1)); printf '  [FAIL] %s  %s\n' "$n" "$d"; DETAILS="${DETAILS}FAIL|${n}: ${d}\n"
  fi
}

hcode() { curl -sL -b "$CJ" -c "$CJ" --max-time 10 -o /dev/null -w '%{http_code}' "$1" 2>/dev/null || echo 000; }
hbody() { curl -sL -b "$CJ" -c "$CJ" --max-time 10 "$1" 2>/dev/null || true; }
get_csrf() { hbody "$1" | grep -oP '(?<=name="_csrf" value=")[^"]+' | head -1 || true; }

assert_code() { local g; g=$(hcode "$2"); [ "$g" = "$3" ] && log_case "$1" PASS || log_case "$1" FAIL "exp $3 got $g"; }
assert_body() { local b; b=$(hbody "$2"); echo "$b" | grep -qF "$3" && log_case "$1" PASS || log_case "$1" FAIL "'$3' not found"; }

post_ajax() {
  local n="$1" u="$2" ex="$3"; shift 3
  local r; r=$(curl -sL -b "$CJ" -c "$CJ" --max-time 15 -X POST "$u" \
    -H 'X-Requested-With: XMLHttpRequest' "$@" 2>/dev/null || true)
  echo "$r" | grep -qF "$ex" && log_case "$n" PASS || log_case "$n" FAIL "${r:0:100}"
}

# ------- 1. Container -------
echo ""; echo "[1/11] Container start..."
echo "[smoke] DATA_DIR=${DATA_DIR}"
echo "[smoke] SMOKE_TMPDIR=${SMOKE_TMPDIR}"
ls -la "${SMOKE_TMPDIR}" || true
ls -la "${DATA_DIR}" || true
echo "[smoke] Running docker run..."
docker run -d \
  --name "$CONTAINER_NAME" \
  -p "${HOST_PORT}:58080" \
  -e NAV_PORT=58080 -e TZ=Asia/Shanghai \
  -v "${DATA_DIR}:/var/www/nav/data" \
  "$IMAGE_TAG" || { echo "[smoke] docker run FAILED, exit=$?"; docker logs "$CONTAINER_NAME" 2>&1 || true; exit 1; }
echo "[smoke] docker run OK, waiting for service..."
# 等待服务就绪：只要 HTTP 有响应（任意状态码非000）即视为就绪
READY=0
for i in $(seq 1 60); do
  CODE=$(docker exec "$CONTAINER_NAME" curl -s -o /dev/null -w '%{http_code}' --max-time 3 "http://127.0.0.1:58080/setup.php" 2>/dev/null || echo 000)
  if [ "$CODE" != "000" ] && [ "$CODE" != "" ]; then
    echo "[smoke] Service ready (HTTP ${CODE})"
    READY=1
    break
  fi
  sleep 1
done
# 如果是 500，输出响应体帮助排查 PHP 错误
if [ "$READY" -eq 1 ]; then
  CODE=$(docker exec "$CONTAINER_NAME" curl -s -o /dev/null -w '%{http_code}' --max-time 3 "http://127.0.0.1:58080/setup.php" 2>/dev/null || echo 000)
  if [ "$CODE" = "500" ]; then
    echo "[smoke] WARNING: setup.php returns 500, response body:"
    docker exec "$CONTAINER_NAME" curl -s --max-time 5 "http://127.0.0.1:58080/setup.php" 2>/dev/null | head -20 || true
    echo "[smoke] PHP-FPM error log:"
    docker exec "$CONTAINER_NAME" tail -20 /var/log/php-fpm/error.log 2>/dev/null || true
    echo "[smoke] Nginx error log:"
    docker exec "$CONTAINER_NAME" tail -10 /var/log/nginx/nav.error.log 2>/dev/null || true
  fi
fi
if [ "$READY" -ne 1 ]; then
  printf 'result: FAIL\nreason: not ready after 60s\n' >> "$REPORT_PATH"
  echo "[smoke] Service not ready, dumping diagnostics:"
  echo "[smoke] --- docker ps ---"
  docker ps
  echo "[smoke] --- port check ---"
  ss -tlnp | grep 58081 || netstat -tlnp | grep 58081 || true
  echo "[smoke] --- curl from host ---"
  curl -v "${BASE}/setup.php" 2>&1 || true
  echo "[smoke] --- curl from container ---"
  docker exec "$CONTAINER_NAME" curl -v "http://127.0.0.1:58080/setup.php" 2>&1 || true
  echo "[smoke] --- container logs ---"
  docker logs "$CONTAINER_NAME" 2>&1 | tail -40
  docker logs "$CONTAINER_NAME" >> "$REPORT_PATH" 2>&1 || true
  exit 1
fi
log_case "container_start" PASS

# ------- 2. Setup -------
echo ""; echo "[2/11] Setup wizard..."
assert_code "setup_page_200"     "${BASE}/setup.php" "200"
assert_body "setup_has_username" "${BASE}/setup.php" "管理员用户名"
assert_body "setup_has_password" "${BASE}/setup.php" "至少 8 位"
CSRF=$(get_csrf "${BASE}/setup.php")
if [ -z "$CSRF" ]; then
  log_case "setup_submit" FAIL "no CSRF"
else
  SR=$(curl -sL -b "$CJ" -c "$CJ" --max-time 20 -X POST "${BASE}/setup.php" \
    -d "_csrf=${CSRF}" -d "username=admin" \
    --data-urlencode "password=Admin@smoke1" \
    --data-urlencode "password2=Admin@smoke1" \
    --data-urlencode "site_name=SmokeTest" \
    -d "nav_domain=smoke.test" 2>/dev/null || true)
  echo "$SR" | grep -qF "前往登录" && log_case "setup_submit" PASS || log_case "setup_submit" FAIL "no redirect link"
fi
assert_code "setup_locked_404" "${BASE}/setup.php" "404"

# ------- 3. Login/logout -------
echo ""; echo "[3/11] Login / logout..."
assert_code "login_page_200" "${BASE}/login.php" "200"
assert_body "login_has_form"  "${BASE}/login.php" "用户名"
CSRF=$(get_csrf "${BASE}/login.php")
if [ -n "$CSRF" ]; then
  WR=$(curl -sL -b "$CJ" -c "$CJ" --max-time 10 -X POST "${BASE}/login.php" \
    -d "_csrf=${CSRF}" -d "username=admin" -d "password=wrongpass" 2>/dev/null || true)
  echo "$WR" | grep -qF "用户名或密码错误" && log_case "login_wrong_pass" PASS || log_case "login_wrong_pass" FAIL "no error msg"
fi
CSRF=$(get_csrf "${BASE}/login.php")
if [ -z "$CSRF" ]; then
  log_case "login_success" FAIL "no CSRF"
else
  curl -sL -b "$CJ" -c "$CJ" --max-time 10 -X POST "${BASE}/login.php" \
    -d "_csrf=${CSRF}" -d "username=admin" --data-urlencode "password=Admin@smoke1" \
    -o /dev/null 2>/dev/null || true
  FRONT=$(hcode "${BASE}/index.php")
  [ "$FRONT" = "200" ] && log_case "login_success" PASS || log_case "login_success" FAIL "index=$FRONT"
fi
assert_code "auth_verify_200" "${BASE}/auth/verify.php" "200"
assert_code "admin_dash_200"  "${BASE}/admin/index.php" "200"
curl -sL -b "$CJ" -c "$CJ" --max-time 10 "${BASE}/logout.php" -o /dev/null 2>/dev/null || true
LGOUT=$(hcode "${BASE}/admin/index.php")
[ "$LGOUT" = "302" ] && log_case "logout_clears_session" PASS || log_case "logout_clears_session" FAIL "returns $LGOUT"
CSRF=$(get_csrf "${BASE}/login.php")
curl -sL -b "$CJ" -c "$CJ" --max-time 10 -X POST "${BASE}/login.php" \
  -d "_csrf=${CSRF}" -d "username=admin" --data-urlencode "password=Admin@smoke1" \
  -o /dev/null 2>/dev/null || true

# ------- 4. Admin pages -------
echo ""; echo "[4/11] Admin page GET checks..."
for P in index groups sites users backups settings; do
  assert_code "admin_${P}_200" "${BASE}/admin/${P}.php" "200"
done
assert_body "admin_index_stats"   "${BASE}/admin/index.php"    "站点数量"
assert_body "admin_settings_form" "${BASE}/admin/settings.php" "站点名称"
assert_body "admin_backups_btn"   "${BASE}/admin/backups.php"  "立即备份"
assert_body "admin_users_add"     "${BASE}/admin/users.php"    "添加用户"

# ------- 5. Group CRUD -------
echo ""; echo "[5/11] Group CRUD..."
CSRF=$(get_csrf "${BASE}/admin/groups.php")
if [ -n "$CSRF" ]; then
  post_ajax "group_create" "${BASE}/admin/groups.php" '"ok":true' \
    -F "_csrf=${CSRF}" -F "action=save" -F "gid=smoke-grp" \
    -F "name=SmokeGroup" -F "icon=X" -F "order=0" -F "visible_to=all" -F "auth_required=1"
  assert_body "group_in_list" "${BASE}/admin/groups.php" "smoke-grp"
  CSRF=$(get_csrf "${BASE}/admin/groups.php")
  post_ajax "group_edit" "${BASE}/admin/groups.php" '"ok":true' \
    -F "_csrf=${CSRF}" -F "action=save" -F "old_id=smoke-grp" -F "gid=smoke-grp" \
    -F "name=SmokeGroupEdited" -F "icon=X" -F "order=1" -F "visible_to=all" -F "auth_required=1"
  assert_body "group_edit_ok" "${BASE}/admin/groups.php" "SmokeGroupEdited"
else
  log_case "group_create" SKIP "no CSRF"
  log_case "group_edit"   SKIP "no CSRF"
fi

# ------- 6. Site CRUD -------
echo ""; echo "[6/11] Site CRUD..."
CSRF=$(get_csrf "${BASE}/admin/sites.php")
if [ -n "$CSRF" ]; then
  post_ajax "site_create" "${BASE}/admin/sites.php" '"ok":true' \
    -F "_csrf=${CSRF}" -F "action=save" -F "gid=smoke-grp" -F "sid=smoke-site" \
    -F "name=SmokeSite" -F "icon=L" -F "order=0" -F "type=external" -F "url=https://example.com"
  assert_body "site_in_list" "${BASE}/admin/sites.php" "smoke-site"
  CSRF=$(get_csrf "${BASE}/admin/sites.php")
  post_ajax "site_edit" "${BASE}/admin/sites.php" '"ok":true' \
    -F "_csrf=${CSRF}" -F "action=save" -F "old_gid=smoke-grp" -F "old_sid=smoke-site" \
    -F "gid=smoke-grp" -F "sid=smoke-site" -F "name=SmokeSiteEdited" \
    -F "icon=L" -F "order=1" -F "type=external" -F "url=https://example.com"
  assert_body "site_edit_ok" "${BASE}/admin/sites.php" "SmokeSiteEdited"
  CSRF=$(get_csrf "${BASE}/admin/sites.php")
  post_ajax "site_delete" "${BASE}/admin/sites.php" '"ok":true' \
    -F "_csrf=${CSRF}" -F "action=delete" -F "gid=smoke-grp" -F "sid=smoke-site"
else
  log_case "site_create" SKIP "no CSRF"
  log_case "site_edit"   SKIP "no CSRF"
  log_case "site_delete" SKIP "no CSRF"
fi
CSRF=$(get_csrf "${BASE}/admin/groups.php")
if [ -n "$CSRF" ]; then
  post_ajax "group_delete" "${BASE}/admin/groups.php" '"ok":true' \
    -F "_csrf=${CSRF}" -F "action=delete" -F "gid=smoke-grp"
else
  log_case "group_delete" SKIP "no CSRF"
fi

# ------- 7. User management -------
echo ""; echo "[7/11] User management..."
assert_code "users_add_page" "${BASE}/admin/users.php?action=add" "200"
CSRF=$(get_csrf "${BASE}/admin/users.php?action=add")
if [ -n "$CSRF" ]; then
  UR=$(curl -sL -b "$CJ" -c "$CJ" --max-time 10 \
    -X POST "${BASE}/admin/users.php" \
    -d "_csrf=${CSRF}" -d "act=save" -d "username=smokeuser" \
    -d "role=user" --data-urlencode "password=SmokePass1" 2>/dev/null || true)
  echo "$UR" | grep -qF "smokeuser" && log_case "user_create" PASS || log_case "user_create" FAIL "${UR:0:100}"
  CSRF=$(get_csrf "${BASE}/admin/users.php")
  DR=$(curl -sL -b "$CJ" -c "$CJ" --max-time 10 \
    -X POST "${BASE}/admin/users.php" \
    -d "_csrf=${CSRF}" -d "act=delete" -d "del_user=smokeuser" 2>/dev/null || true)
  echo "$DR" | grep -vqF "smokeuser" && log_case "user_delete" PASS || log_case "user_delete" FAIL "still visible"
else
  log_case "user_create" SKIP "no CSRF"
  log_case "user_delete" SKIP "no CSRF"
fi

# ------- 8. Backup -------
echo ""; echo "[8/11] Backup management..."
CSRF=$(get_csrf "${BASE}/admin/backups.php")
if [ -n "$CSRF" ]; then
  BR=$(curl -sL -b "$CJ" -c "$CJ" --max-time 15 \
    -X POST "${BASE}/admin/backups.php" \
    -d "_csrf=${CSRF}" -d "action=create" 2>/dev/null || true)
  echo "$BR" | grep -qF "备份已创建" && log_case "backup_create" PASS || log_case "backup_create" FAIL "${BR:0:80}"
  assert_body "backup_in_list" "${BASE}/admin/backups.php" "手动"
else
  log_case "backup_create" SKIP "no CSRF"
fi

# ------- 9. Settings / export / import / nginx -------
echo ""; echo "[9/11] Settings / export / import / nginx-gen..."
CSRF=$(get_csrf "${BASE}/admin/settings.php")
if [ -n "$CSRF" ]; then
  SSR=$(curl -sL -b "$CJ" -c "$CJ" --max-time 15 \
    -X POST "${BASE}/admin/settings.php" \
    -d "_csrf=${CSRF}" -d "action=save_settings" \
    -d "site_name=SmokeTest" -d "nav_domain=smoke.test" \
    -d "token_expire_hours=8" -d "remember_me_days=60" \
    -d "login_fail_limit=5" -d "login_lock_minutes=15" \
    -d "cookie_secure=off" -d "cookie_domain=" \
    -d "card_size=140" -d "card_height=0" \
    -d "card_show_desc=1" -d "card_layout=grid" -d "card_direction=col" 2>/dev/null || true)
  echo "$SSR" | grep -qF "设置已保存" && log_case "settings_save" PASS || log_case "settings_save" FAIL "${SSR:0:100}"
else
  log_case "settings_save" SKIP "no CSRF"
fi
CSRF=$(get_csrf "${BASE}/admin/settings.php")
if [ -n "$CSRF" ]; then
  EX=$(curl -s -b "$CJ" -c "$CJ" --max-time 10 \
    -X POST "${BASE}/admin/settings.php" \
    -d "_csrf=${CSRF}" -d "action=export_config" \
    -o "$EXPORT_FILE" -w "%{http_code}" 2>/dev/null || echo 000)
  [ "$EX" = "200" ] && grep -q '"groups"' "$EXPORT_FILE" 2>/dev/null \
    && log_case "config_export" PASS || log_case "config_export" FAIL "HTTP $EX"
else
  log_case "config_export" SKIP "no CSRF"
fi
if [ -f "$EXPORT_FILE" ]; then
  CSRF=$(get_csrf "${BASE}/admin/settings.php")
  IR=$(curl -sL -b "$CJ" -c "$CJ" --max-time 15 \
    -X POST "${BASE}/admin/settings.php" \
    -F "_csrf=${CSRF}" -F "action=import_config" \
    -F "import_file=@${EXPORT_FILE};type=application/json" 2>/dev/null || true)
  echo "$IR" | grep -qF "导入成功" && log_case "config_import" PASS || log_case "config_import" FAIL "${IR:0:100}"
else
  log_case "config_import" SKIP "export file missing"
fi
CSRF=$(get_csrf "${BASE}/admin/settings.php")
if [ -n "$CSRF" ]; then
  NGC=$(curl -s -b "$CJ" -c "$CJ" --max-time 10 \
    -X POST "${BASE}/admin/settings.php" \
    -d "_csrf=${CSRF}" -d "action=gen_nginx" \
    -o /dev/null -w "%{http_code}" 2>/dev/null || echo 000)
  [ "$NGC" = "200" ] && log_case "nginx_conf_download" PASS || log_case "nginx_conf_download" FAIL "HTTP $NGC"
else
  log_case "nginx_conf_download" SKIP "no CSRF"
fi
CSRF=$(get_csrf "${BASE}/admin/settings.php")
if [ -n "$CSRF" ]; then
  MBR=$(curl -sL -b "$CJ" -c "$CJ" --max-time 15 \
    -X POST "${BASE}/admin/settings.php" \
    -d "_csrf=${CSRF}" -d "action=manual_backup" 2>/dev/null || true)
  echo "$MBR" | grep -qF "备份已创建" && log_case "manual_backup_settings" PASS || log_case "manual_backup_settings" FAIL "${MBR:0:100}"
else
  log_case "manual_backup_settings" SKIP "no CSRF"
fi

# ------- 10. Log API / cookie clear -------
echo ""; echo "[10/11] Log API and cookie clear..."
for LTYPE in nginx_access nginx_error php_fpm; do
  LC=$(hcode "${BASE}/admin/settings.php?ajax=log&type=${LTYPE}&lines=10")
  [ "$LC" = "200" ] && log_case "log_api_${LTYPE}" PASS || log_case "log_api_${LTYPE}" FAIL "HTTP $LC"
done
CLR=$(curl -s -b "$CJ" -c "$CJ" --max-time 10 \
  -X GET "${BASE}/admin/settings.php?ajax=clear_log" \
  -o /dev/null -w "%{http_code}" 2>/dev/null || echo 000)
[ "$CLR" = "200" ] && log_case "log_clear_api" PASS || log_case "log_clear_api" FAIL "HTTP $CLR"
CSRF=$(get_csrf "${BASE}/admin/settings.php")
if [ -n "$CSRF" ]; then
  curl -sL -b "$CJ" -c "$CJ" --max-time 10 \
    -X POST "${BASE}/admin/settings.php" \
    -d "_csrf=${CSRF}" -d "action=clear_cookie" -o /dev/null 2>/dev/null || true
  log_case "cookie_clear" PASS
else
  log_case "cookie_clear" SKIP "no CSRF"
fi

# ------- 11. Health check -------
echo ""; echo "[11/11] Docker health check..."
sleep 35
HSTAT=$(docker inspect --format='{{.State.Health.Status}}' "$CONTAINER_NAME" 2>/dev/null || echo unknown)
[ "$HSTAT" = "healthy" ] && log_case "docker_healthcheck" PASS || log_case "docker_healthcheck" FAIL "status=$HSTAT"

# ------- Final report -------
TOTAL=$((PASS+FAIL+SKIP))
printf '\n## Summary\ntotal: %d\npass:  %d\nfail:  %d\nskip:  %d\n' "$TOTAL" "$PASS" "$FAIL" "$SKIP" >> "$REPORT_PATH"
printf '\n## Results\n' >> "$REPORT_PATH"
printf '%b' "$DETAILS" >> "$REPORT_PATH"
printf '\n## Docker Logs tail-60\n' >> "$REPORT_PATH"
docker logs "$CONTAINER_NAME" 2>&1 | tail -60 >> "$REPORT_PATH" || true

if [ "$FAIL" -ne 0 ]; then
  printf 'result: FAIL\n' >> "$REPORT_PATH"
  printf '\n[smoke] FAIL %d/%d\n' "$FAIL" "$TOTAL"
  exit 1
else
  printf 'result: PASS\n' >> "$REPORT_PATH"
  printf '\n[smoke] PASS %d/%d\n' "$PASS" "$TOTAL"
fi
  
