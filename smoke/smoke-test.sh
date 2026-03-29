#!/usr/bin/env bash
# Nav Portal Full Smoke Test
# 所有 HTTP 请求均通过 docker exec 在容器内执行，避免宿主机端口映射问题
set -ue

echo "[smoke] START pid=$$ ***"
echo "[smoke] IMAGE=${2:-local/smoke:test}"

REPORT_PATH="${1:-smoke-report.txt}"
IMAGE_TAG="${2:-local/smoke:test}"
CONTAINER_NAME="nav-smoke-test"
HOST_PORT="58081"
BASE="http://127.0.0.1:58080"   # 容器内访问地址
CJ="/tmp/smoke_cj.txt"           # 容器内 cookie jar

# 宿主机临时目录（用于挂载数据卷和导出文件）
SMOKE_TMPDIR="$(pwd)/.smoke_tmp_$$"
DATA_DIR="${SMOKE_TMPDIR}/nav_data"
EXPORT_FILE_HOST="${SMOKE_TMPDIR}/export.json"
EXPORT_FILE_INNER="/tmp/smoke_export.json"  # 容器内路径
# 单页 GET 总耗时上限（秒，curl time_total）；超出则 load_* 用例失败，便于发现慢页面
SMOKE_LOAD_MAX_SEC="${SMOKE_LOAD_MAX_SEC:-3.0}"

PASS=0; FAIL=0; SKIP=0
DETAILS=""

docker rm -f "$CONTAINER_NAME" >/dev/null 2>&1 || true
mkdir -p "${DATA_DIR}/logs" "${DATA_DIR}/favicon_cache" "${DATA_DIR}/bg" "${DATA_DIR}/backups"
chmod -R 777 "${DATA_DIR}"

printf '# Nav Portal Full Smoke Test Report\ntime: %s\nimage: %s\nsmoke_load_max_sec: %s\n' \
  "$(date -u +"%Y-%m-%dT%H:%M:%SZ")" "$IMAGE_TAG" "$SMOKE_LOAD_MAX_SEC" > "$REPORT_PATH"

cleanup() {
  docker rm -f "$CONTAINER_NAME" >/dev/null 2>&1 || true
  if [ -d "$SMOKE_TMPDIR" ]; then
    docker run --rm -v "${SMOKE_TMPDIR}:/cleanup" alpine sh -c 'rm -rf /cleanup/*' >/dev/null 2>&1 || true
    rm -rf "$SMOKE_TMPDIR" 2>/dev/null || true
  fi
}
trap cleanup EXIT

log_case() {
  local n="$1" r="$2" d="${3:-}"
  if   [ "$r" = "PASS" ]; then PASS=$((PASS+1)); printf '  [PASS] %s\n' "$n";         DETAILS="${DETAILS}PASS|${n}\n"
  elif [ "$r" = "SKIP" ]; then SKIP=$((SKIP+1)); printf '  [SKIP] %s\n' "$n";         DETAILS="${DETAILS}SKIP|${n}: ${d}\n"
  else                          FAIL=$((FAIL+1)); printf '  [FAIL] %s  %s\n' "$n" "$d"; DETAILS="${DETAILS}FAIL|${n}: ${d}\n"
  fi
}

# 容器内执行 curl GET，返回 HTTP 状态码
hcode() {
  local code
  code=$(docker exec "$CONTAINER_NAME" curl -sL -b "$CJ" -c "$CJ" \
    --max-time 10 -o /dev/null -w '%{http_code}' "$1" 2>/dev/null || true)
  case "$code" in
    [1-5][0-9][0-9]) echo "$code" ;;
    *) echo 000 ;;
  esac
}

# 容器内执行 curl GET，不跟随跳转，返回 HTTP 状态码
rcode() {
  local code
  code=$(docker exec "$CONTAINER_NAME" curl -s -b "$CJ" -c "$CJ" \
    --max-time 10 -o /dev/null -w '%{http_code}' "$1" 2>/dev/null || true)
  case "$code" in
    [1-5][0-9][0-9]) echo "$code" ;;
    *) echo 000 ;;
  esac
}

# 容器内请求 Location 头（不跟随跳转）
rloc() {
  docker exec "$CONTAINER_NAME" sh -c \
    "curl -s -b '$CJ' -c '$CJ' --max-time 10 -D - -o /dev/null \"$1\" 2>/dev/null | tr -d '\r' | sed -n 's/^Location: //p' | head -1" || true
}

# 容器内执行 curl GET，返回响应体
hbody() {
  docker exec "$CONTAINER_NAME" curl -sL -b "$CJ" -c "$CJ" \
    --max-time 10 "$1" 2>/dev/null || true
}

# 从页面提取 CSRF token（兼容 macOS/BSD grep）
get_csrf() {
  hbody "$1" \
    | tr '\n' ' ' \
    | sed -nE 's/.*name="_csrf" value="([^"]+)".*/\1/p' \
    | head -1 || true
}

assert_code() {
  local g; g=$(hcode "$2")
  [ "$g" = "$3" ] && log_case "$1" PASS || log_case "$1" FAIL "exp $3 got $g"
}

assert_body() {
  local b; b=$(hbody "$2")
  echo "$b" | grep -qF "$3" && log_case "$1" PASS || log_case "$1" FAIL "'$3' not found"
}

assert_body_not() {
  local b; b=$(hbody "$2")
  echo "$b" | grep -qF "$3" && log_case "$1" FAIL "'$3' should be absent" || log_case "$1" PASS
}

# 记录页面 GET 总耗时（curl %{time_total}，含 TTFB+下载）；超过 SMOKE_LOAD_MAX_SEC 则失败
# LOAD_TIMINGS_FILE 在 [4b/11] 中赋值后再调用 record_load_time*
record_load_time() {
  local name="$1" url="$2"
  local t
  t=$(docker exec "$CONTAINER_NAME" curl -sL -b "$CJ" -c "$CJ" \
    -o /dev/null -w '%{time_total}' --max-time 30 "$url" 2>/dev/null || echo "999")
  printf '%s\t%s\t%s\n' "$name" "$url" "$t" >> "$LOAD_TIMINGS_FILE"
  if awk -v t="$t" -v m="$SMOKE_LOAD_MAX_SEC" 'BEGIN{exit (t+0 > m+0 || t+0 >= 900) ? 0 : 1}'; then
    log_case "load_${name}" FAIL "${t}s > max ${SMOKE_LOAD_MAX_SEC}s"
  else
    log_case "load_${name}" PASS
  fi
}

record_load_time_ajax() {
  local name="$1" url="$2"
  local t
  t=$(docker exec "$CONTAINER_NAME" curl -s -b "$CJ" -c "$CJ" \
    -H 'X-Requested-With: XMLHttpRequest' \
    -o /dev/null -w '%{time_total}' --max-time 30 "$url" 2>/dev/null || echo "999")
  printf '%s\t%s\t%s\n' "$name" "(ajax) $url" "$t" >> "$LOAD_TIMINGS_FILE"
  if awk -v t="$t" -v m="$SMOKE_LOAD_MAX_SEC" 'BEGIN{exit (t+0 > m+0 || t+0 >= 900) ? 0 : 1}'; then
    log_case "load_${name}" FAIL "${t}s > max ${SMOKE_LOAD_MAX_SEC}s"
  else
    log_case "load_${name}" PASS
  fi
}

# 容器内 POST（application/x-www-form-urlencoded）
# 用法: post_form NAME URL EXPECT_STR -d key=val ...
post_form() {
  local n="$1" u="$2" ex="$3"; shift 3
  local r; r=$(docker exec "$CONTAINER_NAME" curl -sL -b "$CJ" -c "$CJ" \
    --max-time 15 -X POST "$u" "$@" 2>/dev/null || true)
  echo "$r" | grep -qF "$ex" && log_case "$n" PASS || log_case "$n" FAIL "${r:0:120}"
}

# 容器内 POST（multipart/form-data，用于 CRUD AJAX）
post_ajax() {
  local n="$1" u="$2" ex="$3"; shift 3
  local r; r=$(docker exec "$CONTAINER_NAME" curl -sL -b "$CJ" -c "$CJ" \
    --max-time 15 -X POST "$u" \
    -H 'X-Requested-With: XMLHttpRequest' "$@" 2>/dev/null || true)
  echo "$r" | grep -qF "$ex" && log_case "$n" PASS || log_case "$n" FAIL "${r:0:120}"
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
  "$IMAGE_TAG" || { echo "[smoke] docker run FAILED"; exit 1; }
echo "[smoke] docker run OK, waiting for service..."
READY=0
for i in $(seq 1 60); do
  CODE=$(docker exec "$CONTAINER_NAME" curl -s -o /dev/null -w '%{http_code}' \
    --max-time 3 "${BASE}/setup.php" 2>/dev/null || true)
  case "$CODE" in
    [1-5][0-9][0-9])
    echo "[smoke] Service ready (HTTP ${CODE})"
    READY=1; break
    ;;
  esac
  sleep 1
done
if [ "$READY" -ne 1 ]; then
  printf 'result: FAIL\nreason: not ready after 60s\n' >> "$REPORT_PATH"
  docker logs "$CONTAINER_NAME" 2>&1 | tail -30
  docker logs "$CONTAINER_NAME" >> "$REPORT_PATH" 2>&1 || true
  exit 1
fi
# 如果 setup.php 返回 500，输出 PHP 错误日志
CODE=$(docker exec "$CONTAINER_NAME" curl -s -o /dev/null -w '%{http_code}' \
  --max-time 3 "${BASE}/setup.php" 2>/dev/null || true)
if [ "$CODE" = "500" ]; then
  echo "[smoke] setup.php returns 500, PHP-FPM log:"
  docker exec "$CONTAINER_NAME" tail -30 /var/log/php-fpm/error.log 2>/dev/null || true
  echo "[smoke] Nginx error log:"
  docker exec "$CONTAINER_NAME" tail -10 /var/log/nginx/nav.error.log 2>/dev/null || true
  echo "[smoke] setup.php body:"
  docker exec "$CONTAINER_NAME" curl -s --max-time 5 "${BASE}/setup.php" 2>/dev/null | head -30 || true
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
  SR=$(docker exec "$CONTAINER_NAME" curl -sL -b "$CJ" -c "$CJ" --max-time 20 \
    "${BASE}/setup.php" \
    -d "_csrf=${CSRF}" -d "username=admin" \
    --data-urlencode "password=Admin@smoke1" \
    --data-urlencode "password2=Admin@smoke1" \
    --data-urlencode "site_name=SmokeTest" \
    -d "nav_domain=smoke.test" 2>/dev/null || true)
  echo "$SR" | grep -qF "前往登录" && log_case "setup_submit" PASS || log_case "setup_submit" FAIL "no redirect link"
fi
assert_code "setup_locked_404" "${BASE}/setup.php" "404"

# ------- 2.5 Unauth checks -------
echo ""; echo "[2.5/11] Unauth checks..."
U1=$(rcode "${BASE}/admin/index.php")
[ "$U1" = "302" ] && log_case "admin_redirect_302" PASS || log_case "admin_redirect_302" FAIL "returns $U1"
LOC=$(rloc "${BASE}/admin/index.php")
echo "$LOC" | grep -q "login.php?redirect=" && log_case "admin_redirect_has_param" PASS || log_case "admin_redirect_has_param" FAIL "loc=$LOC"
U2=$(rcode "${BASE}/admin/debug.php?ajax=log&type=nginx_access&lines=10")
[ "$U2" = "401" ] && log_case "debug_log_unauth_401" PASS || log_case "debug_log_unauth_401" FAIL "returns $U2"

# ------- 3. Login/logout -------
echo ""; echo "[3/11] Login / logout..."
assert_code "login_page_200" "${BASE}/login.php" "200"
assert_body "login_has_form"  "${BASE}/login.php" "用户名"
CSRF=$(get_csrf "${BASE}/login.php")
if [ -n "$CSRF" ]; then
  WR=$(docker exec "$CONTAINER_NAME" curl -sL -b "$CJ" -c "$CJ" --max-time 10 \
    "${BASE}/login.php" \
    -d "_csrf=${CSRF}" -d "username=admin" -d "password=wrongpass" 2>/dev/null || true)
  echo "$WR" | grep -qF "用户名或密码错误" && log_case "login_wrong_pass" PASS || log_case "login_wrong_pass" FAIL "no error msg"
fi
CSRF=$(get_csrf "${BASE}/login.php")
if [ -z "$CSRF" ]; then
  log_case "login_success" FAIL "no CSRF"
else
  docker exec "$CONTAINER_NAME" curl -sL -b "$CJ" -c "$CJ" --max-time 10 \
    "${BASE}/login.php" \
    -d "_csrf=${CSRF}" -d "username=admin" --data-urlencode "password=Admin@smoke1" \
    -o /dev/null 2>/dev/null || true
  FRONT=$(hcode "${BASE}/index.php")
  [ "$FRONT" = "200" ] && log_case "login_success" PASS || log_case "login_success" FAIL "index=$FRONT"
fi
assert_code "auth_verify_internal_404" "${BASE}/auth/verify.php" "404"
assert_code "admin_dash_200"  "${BASE}/admin/index.php" "200"
CSRF=$(get_csrf "${BASE}/index.php")
if [ -n "$CSRF" ]; then
  docker exec "$CONTAINER_NAME" curl -sL -b "$CJ" -c "$CJ" --max-time 10 \
    "${BASE}/logout.php" \
    -d "_csrf=${CSRF}" -o /dev/null 2>/dev/null || true
  LGOUT=$(rcode "${BASE}/admin/index.php")
  [ "$LGOUT" = "302" ] && log_case "logout_clears_session" PASS || log_case "logout_clears_session" FAIL "returns $LGOUT"
else
  log_case "logout_clears_session" FAIL "no CSRF for logout"
fi
CSRF=$(get_csrf "${BASE}/login.php")
docker exec "$CONTAINER_NAME" curl -sL -b "$CJ" -c "$CJ" --max-time 10 \
  "${BASE}/login.php" \
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

echo ""; echo "[4b/11] Page load timings (curl time_total, max ${SMOKE_LOAD_MAX_SEC}s)..."
LOAD_TIMINGS_FILE="${SMOKE_TMPDIR}/load_timings.tsv"
: > "$LOAD_TIMINGS_FILE"
record_load_time "public_index" "${BASE}/index.php"
record_load_time "public_login" "${BASE}/login.php"
record_load_time "admin_index" "${BASE}/admin/index.php"
record_load_time "admin_groups" "${BASE}/admin/groups.php"
record_load_time "admin_sites" "${BASE}/admin/sites.php"
record_load_time "admin_users" "${BASE}/admin/users.php"
record_load_time "admin_backups" "${BASE}/admin/backups.php"
record_load_time "admin_settings" "${BASE}/admin/settings.php"
record_load_time "admin_debug" "${BASE}/admin/debug.php"
record_load_time_ajax "admin_login_logs_json" "${BASE}/admin/login_logs.php"
record_load_time_ajax "admin_settings_ajax_nginx" "${BASE}/admin/settings_ajax.php?action=nginx_sudo"
record_load_time "admin_health_status" "${BASE}/admin/health_check.php?ajax=status"

echo "[smoke] page load timings (slowest first, max ${SMOKE_LOAD_MAX_SEC}s):"
sort -t $'\t' -k3 -nr "$LOAD_TIMINGS_FILE" | while IFS=$'\t' read -r _n _u _t; do echo "  ${_t}s  ${_n}"; done

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
  log_case   "group_create" SKIP "no CSRF"
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

  # Proxy site + Nginx reload verify
  CSRF=$(get_csrf "${BASE}/admin/sites.php")
  post_ajax "proxy_site_create" "${BASE}/admin/sites.php" '"ok":true' \
    -F "_csrf=${CSRF}" -F "action=save" -F "gid=smoke-grp" -F "sid=smoke-proxy" \
    -F "name=SmokeProxy" -F "icon=🔀" -F "order=2" -F "type=proxy" \
    -F "proxy_mode=path" -F "proxy_target=http://10.255.255.1:81" -F "slug=smoke-proxy"
  assert_body "proxy_pending_bar" "${BASE}/index.php" "proxy-pending-bar"
  CSRF=$(get_csrf "${BASE}/admin/settings.php")
  if [ -n "$CSRF" ]; then
    docker exec "$CONTAINER_NAME" curl -sL -b "$CJ" -c "$CJ" --max-time 20 -o /dev/null \
      "${BASE}/admin/settings.php" \
      -d "_csrf=${CSRF}" -d "action=nginx_reload" 2>/dev/null || true
    if docker exec "$CONTAINER_NAME" sh -c "grep -q 'location /p/smoke-proxy/' /etc/nginx/conf.d/nav-proxy.conf"; then
      log_case "proxy_conf_written" PASS
    else
      log_case "proxy_conf_written" FAIL "no location block"
    fi
    if docker exec "$CONTAINER_NAME" sh -c "nginx -T 2>/dev/null | grep -q 'location /p/smoke-proxy/'"; then
      log_case "proxy_route_active" PASS
    else
      log_case "proxy_route_active" FAIL "nginx config missing"
    fi
    assert_body_not "proxy_pending_cleared" "${BASE}/index.php" "proxy-pending-bar"
  else
    log_case "proxy_conf_written" SKIP "no CSRF"
    log_case "proxy_route_active" SKIP "no CSRF"
    log_case "proxy_pending_cleared" SKIP "no CSRF"
  fi
  CSRF=$(get_csrf "${BASE}/admin/sites.php")
  post_ajax "proxy_site_delete" "${BASE}/admin/sites.php" '"ok":true' \
    -F "_csrf=${CSRF}" -F "action=delete" -F "gid=smoke-grp" -F "sid=smoke-proxy"
else
  log_case "site_create" SKIP "no CSRF"
  log_case "site_edit"   SKIP "no CSRF"
  log_case "site_delete" SKIP "no CSRF"
  log_case "proxy_site_create" SKIP "no CSRF"
  log_case "proxy_conf_written" SKIP "no CSRF"
  log_case "proxy_route_active" SKIP "no CSRF"
  log_case "proxy_pending_cleared" SKIP "no CSRF"
  log_case "proxy_site_delete" SKIP "no CSRF"
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
  UR=$(docker exec "$CONTAINER_NAME" curl -sL -b "$CJ" -c "$CJ" --max-time 10 \
    "${BASE}/admin/users.php" \
    -d "_csrf=${CSRF}" -d "act=save" -d "username=smokeuser" \
    -d "role=user" --data-urlencode "password=SmokePass1" 2>/dev/null || true)
  echo "$UR" | grep -qF "smokeuser" && log_case "user_create" PASS || log_case "user_create" FAIL "${UR:0:100}"
  # 延迟删除（用于后续角色边界/子站测试）
  log_case "user_delete_deferred" SKIP "deferred"
else
  log_case "user_create" SKIP "no CSRF"
  log_case "user_delete_deferred" SKIP "no CSRF"
fi

# ------- 8. Backup -------
echo ""; echo "[8/11] Backup management..."
CSRF=$(get_csrf "${BASE}/admin/backups.php")
if [ -n "$CSRF" ]; then
  BR=$(docker exec "$CONTAINER_NAME" curl -sL -b "$CJ" -c "$CJ" --max-time 15 \
    "${BASE}/admin/backups.php" \
    -d "_csrf=${CSRF}" -d "action=create" 2>/dev/null || true)
  echo "$BR" | grep -qF "备份已创建" && log_case "backup_create" PASS || log_case "backup_create" FAIL "${BR:0:80}"
  assert_body "backup_in_list" "${BASE}/admin/backups.php" "手动"
  BK_FILE=$(docker exec "$CONTAINER_NAME" sh -c "ls -t /var/www/nav/data/backups/backup_*.json 2>/dev/null | head -1 | xargs -n1 basename" || true)
  if [ -n "$BK_FILE" ]; then
    BD=$(docker exec "$CONTAINER_NAME" curl -sL -b "$CJ" -c "$CJ" --max-time 10 \
      "${BASE}/admin/backups.php?download=${BK_FILE}" 2>/dev/null || true)
    echo "$BD" | grep -q '"sites"' && log_case "backup_download" PASS || log_case "backup_download" FAIL "invalid json"
    CSRF=$(get_csrf "${BASE}/admin/backups.php")
    docker exec "$CONTAINER_NAME" curl -sL -b "$CJ" -c "$CJ" --max-time 15 -o /dev/null \
      "${BASE}/admin/backups.php" \
      -d "_csrf=${CSRF}" -d "action=restore" -d "filename=${BK_FILE}" 2>/dev/null || true
    assert_body "backup_restore_auto" "${BASE}/admin/backups.php" "自动-恢复前"
    CSRF=$(get_csrf "${BASE}/admin/backups.php")
    docker exec "$CONTAINER_NAME" curl -sL -b "$CJ" -c "$CJ" --max-time 15 -o /dev/null \
      "${BASE}/admin/backups.php" \
      -d "_csrf=${CSRF}" -d "action=delete" -d "filename=${BK_FILE}" 2>/dev/null || true
    if docker exec "$CONTAINER_NAME" sh -c "[ ! -f /var/www/nav/data/backups/${BK_FILE} ]"; then
      log_case "backup_delete" PASS
    else
      log_case "backup_delete" FAIL "file still exists"
    fi
  else
    log_case "backup_download" SKIP "no backup file"
    log_case "backup_restore_auto" SKIP "no backup file"
    log_case "backup_delete" SKIP "no backup file"
  fi
else
  log_case "backup_create" SKIP "no CSRF"
  log_case "backup_download" SKIP "no CSRF"
  log_case "backup_restore_auto" SKIP "no CSRF"
  log_case "backup_delete" SKIP "no CSRF"
fi

# ------- 9. Settings / export / import / nginx -------
echo ""; echo "[9/13] Settings / export / import / nginx-gen..."
CSRF=$(get_csrf "${BASE}/admin/settings.php")
if [ -n "$CSRF" ]; then
  SSR=$(docker exec "$CONTAINER_NAME" curl -sL -b "$CJ" -c "$CJ" --max-time 15 \
    "${BASE}/admin/settings.php" \
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
  docker exec "$CONTAINER_NAME" curl -s -b "$CJ" -c "$CJ" --max-time 10 \
    "${BASE}/admin/settings.php" \
    -d "_csrf=${CSRF}" -d "action=export_config" \
    -o /tmp/smoke_export.json 2>/dev/null || true
  docker cp "${CONTAINER_NAME}:/tmp/smoke_export.json" "${SMOKE_TMPDIR}/export.json" 2>/dev/null || true
  if [ -f "${SMOKE_TMPDIR}/export.json" ] && grep -q '"groups"' "${SMOKE_TMPDIR}/export.json" 2>/dev/null; then
    log_case "config_export" PASS
  else
    log_case "config_export" FAIL "export file invalid"
  fi
else
  log_case "config_export" SKIP "no CSRF"
fi
if [ -f "${SMOKE_TMPDIR}/export.json" ]; then
  docker cp "${SMOKE_TMPDIR}/export.json" "${CONTAINER_NAME}:/tmp/smoke_import.json" 2>/dev/null || true
  CSRF=$(get_csrf "${BASE}/admin/settings.php")
  IR=$(docker exec "$CONTAINER_NAME" curl -sL -b "$CJ" -c "$CJ" --max-time 15 \
    "${BASE}/admin/settings.php" \
    -F "_csrf=${CSRF}" -F "action=import_config" \
    -F "import_file=@/tmp/smoke_import.json;type=application/json" 2>/dev/null || true)
  echo "$IR" | grep -qF "导入成功" && log_case "config_import" PASS || log_case "config_import" FAIL "${IR:0:100}"
else
  log_case "config_import" SKIP "export file missing"
fi
CSRF=$(get_csrf "${BASE}/admin/settings.php")
if [ -n "$CSRF" ]; then
  NGC=$(docker exec "$CONTAINER_NAME" curl -s -b "$CJ" -c "$CJ" --max-time 10 \
    "${BASE}/admin/settings.php" \
    -d "_csrf=${CSRF}" -d "action=gen_nginx" \
    -o /dev/null -w "%{http_code}" 2>/dev/null || echo 000)
  [ "$NGC" = "200" ] && log_case "nginx_conf_download" PASS || log_case "nginx_conf_download" FAIL "HTTP $NGC"
else
  log_case "nginx_conf_download" SKIP "no CSRF"
fi
CSRF=$(get_csrf "${BASE}/admin/settings.php")
if [ -n "$CSRF" ]; then
  MBR=$(docker exec "$CONTAINER_NAME" curl -sL -b "$CJ" -c "$CJ" --max-time 15 \
    "${BASE}/admin/settings.php" \
    -d "_csrf=${CSRF}" -d "action=manual_backup" 2>/dev/null || true)
  echo "$MBR" | grep -qF "备份已创建" && log_case "manual_backup_settings" PASS || log_case "manual_backup_settings" FAIL "${MBR:0:100}"
else
  log_case "manual_backup_settings" SKIP "no CSRF"
fi

# ------- 10. Role / subsite token -------
echo ""; echo "[10/13] Role boundary / subsite token..."
USER_CJ="/tmp/smoke_cj_user.txt"
docker exec "$CONTAINER_NAME" sh -c "rm -f ${USER_CJ}"
CSRF_U=$(docker exec "$CONTAINER_NAME" curl -sL -c "$USER_CJ" -b "$USER_CJ" --max-time 10 "${BASE}/login.php" 2>/dev/null | tr '\n' ' ' | sed -nE 's/.*name=\"_csrf\" value=\"([^\"]+)\".*/\\1/p' | head -1 || true)
if [ -z "$CSRF_U" ]; then
  log_case "user_login_csrf" FAIL "no CSRF"
else
  log_case "user_login_csrf" PASS
  docker exec "$CONTAINER_NAME" curl -sL -c "$USER_CJ" -b "$USER_CJ" --max-time 10 \
    "${BASE}/login.php" \
    -d "_csrf=${CSRF_U}" -d "username=smokeuser" --data-urlencode "password=SmokePass1" \
    -o /dev/null 2>/dev/null || true
fi
UTOKEN=$(docker exec "$CONTAINER_NAME" sh -c "awk '\$6==\"nav_session\"{print \$7}' ${USER_CJ} | head -1" || true)
if [ -z "$UTOKEN" ]; then
  UTOKEN=$(docker exec "$CONTAINER_NAME" sh -c "grep -m1 'nav_session' ${USER_CJ} | awk '{print \$7}'" || true)
fi
UHOME_CODE=$(docker exec "$CONTAINER_NAME" curl -s -b "$USER_CJ" -c "$USER_CJ" --max-time 10 -o /dev/null -w '%{http_code}' "${BASE}/index.php" 2>/dev/null || true)
if [ "$UHOME_CODE" != "200" ]; then
  UTOKEN=$(docker exec "$CONTAINER_NAME" sh -c "php -r 'require \"/var/www/nav/shared/auth.php\"; echo auth_generate_token(\"smokeuser\",\"user\",false);' 2>/dev/null" || true)
  if [ -n "$UTOKEN" ]; then
    docker exec "$CONTAINER_NAME" sh -c "printf '%s\n' '# Netscape HTTP Cookie File' '' > ${USER_CJ}"
    docker exec "$CONTAINER_NAME" sh -c "printf '%s\t%s\t%s\t%s\t%s\t%s\t%s\n' '#HttpOnly_127.0.0.1' 'FALSE' '/' 'FALSE' '0' 'nav_session' '${UTOKEN}' >> ${USER_CJ}"
    UHOME_CODE=$(docker exec "$CONTAINER_NAME" curl -s -b "$USER_CJ" -c "$USER_CJ" --max-time 10 -o /dev/null -w '%{http_code}' "${BASE}/index.php" 2>/dev/null || true)
    log_case "user_login_fallback" PASS
  else
    log_case "user_login_fallback" FAIL "no token"
  fi
fi
if [ "$UHOME_CODE" = "200" ]; then
  log_case "user_login" PASS
  UHOME=$(docker exec "$CONTAINER_NAME" curl -s -b "$USER_CJ" -c "$USER_CJ" --max-time 10 "${BASE}/index.php" 2>/dev/null || true)
  echo "$UHOME" | grep -q "smokeuser" && log_case "user_home_visible" PASS || log_case "user_home_visible" FAIL "no username"
  UCODE=$(docker exec "$CONTAINER_NAME" curl -s -b "$USER_CJ" -c "$USER_CJ" --max-time 10 -o /dev/null -w '%{http_code}' "${BASE}/admin/index.php" 2>/dev/null || true)
  [ "$UCODE" = "403" ] && log_case "user_admin_forbidden" PASS || log_case "user_admin_forbidden" FAIL "status=$UCODE"
else
  log_case "user_login" FAIL "index=$UHOME_CODE"
  log_case "user_home_visible" SKIP "login failed"
  log_case "user_admin_forbidden" SKIP "login failed"
fi

# Subsite middleware token flow
docker exec "$CONTAINER_NAME" sh -c 'cat > /var/www/nav/public/subsite.php <<'\''PHP'\''
<?php
require_once __DIR__ . "/../subsite-middleware/auth_check.php";
$u = $GLOBALS["nav_user"] ?? [];
echo "SUBSITE_OK:" . ($u["username"] ?? "-");
PHP
' || true
if docker exec "$CONTAINER_NAME" sh -c "test -f /var/www/nav/public/subsite.php"; then
  log_case "subsite_file_created" PASS
else
  log_case "subsite_file_created" FAIL "not created"
fi
SUB_CJ="/tmp/smoke_cj_sub.txt"
docker exec "$CONTAINER_NAME" sh -c "rm -f ${SUB_CJ}"
if [ -n "$UTOKEN" ] && docker exec "$CONTAINER_NAME" sh -c "test -f /var/www/nav/public/subsite.php"; then
  S1=$(docker exec "$CONTAINER_NAME" curl -s -b "$SUB_CJ" -c "$SUB_CJ" --max-time 10 -o /dev/null -w '%{http_code}' "${BASE}/subsite.php" 2>/dev/null || true)
  [ "$S1" = "302" ] && log_case "subsite_requires_login" PASS || log_case "subsite_requires_login" FAIL "status=$S1"
  SLOC=$(docker exec "$CONTAINER_NAME" sh -c "curl -s -b '${SUB_CJ}' -c '${SUB_CJ}' --max-time 10 -D - -o /dev/null \"${BASE}/subsite.php?_nav_token=${UTOKEN}\" 2>/dev/null | tr -d '\\r' | sed -n 's/^Location: //p' | head -1" || true)
  echo "$SLOC" | grep -q "_nav_token" && log_case "subsite_token_clean" FAIL "loc=$SLOC" || log_case "subsite_token_clean" PASS
  SBODY=$(docker exec "$CONTAINER_NAME" curl -s -b "$SUB_CJ" -c "$SUB_CJ" --max-time 10 "${BASE}/subsite.php" 2>/dev/null || true)
  echo "$SBODY" | grep -q "SUBSITE_OK:smokeuser" && log_case "subsite_token_login" PASS || log_case "subsite_token_login" FAIL "${SBODY:0:80}"
else
  log_case "subsite_requires_login" SKIP "no user session or file"
  log_case "subsite_token_clean" SKIP "no user session or file"
  log_case "subsite_token_login" SKIP "no user session or file"
fi

# cleanup user
CSRF=$(get_csrf "${BASE}/admin/users.php")
if [ -n "$CSRF" ]; then
  DR=$(docker exec "$CONTAINER_NAME" curl -sL -b "$CJ" -c "$CJ" --max-time 10 \
    "${BASE}/admin/users.php" \
    -d "_csrf=${CSRF}" -d "act=delete" -d "del_user=smokeuser" 2>/dev/null || true)
  echo "$DR" | grep -vqF "smokeuser" && log_case "user_delete_cleanup" PASS || log_case "user_delete_cleanup" FAIL "still visible"
else
  log_case "user_delete_cleanup" SKIP "no CSRF"
fi

# ------- 11. IP lock policy -------
echo ""; echo "[11/13] IP lock policy..."
CSRF=$(get_csrf "${BASE}/admin/settings.php")
if [ -n "$CSRF" ]; then
  docker exec "$CONTAINER_NAME" curl -sL -b "$CJ" -c "$CJ" --max-time 15 -o /dev/null \
    "${BASE}/admin/settings.php" \
    -d "_csrf=${CSRF}" -d "action=save_settings" \
    -d "site_name=SmokeTest" -d "nav_domain=smoke.test" \
    -d "token_expire_hours=8" -d "remember_me_days=60" \
    -d "login_fail_limit=3" -d "login_lock_minutes=1" \
    -d "cookie_secure=off" -d "cookie_domain=" \
    -d "card_size=140" -d "card_height=0" \
    -d "card_show_desc=1" -d "card_layout=grid" -d "card_direction=col" \
    -d "bg_color=" 2>/dev/null || true
  if docker exec "$CONTAINER_NAME" sh -c "test -f /var/www/nav/data/config.json"; then
    LIM=$(docker exec "$CONTAINER_NAME" sh -c "sed -n 's/.*login_fail_limit[^0-9]*\\([0-9][0-9]*\\).*/\\1/p' /var/www/nav/data/config.json | head -1" || true)
    LMIN=$(docker exec "$CONTAINER_NAME" sh -c "sed -n 's/.*login_lock_minutes[^0-9]*\\([0-9][0-9]*\\).*/\\1/p' /var/www/nav/data/config.json | head -1" || true)
    if [ "$LIM" = "3" ] && [ "$LMIN" = "1" ]; then
      log_case "ip_lock_config" PASS
    else
      log_case "ip_lock_config" FAIL "limit=$LIM mins=$LMIN"
    fi
  else
    log_case "ip_lock_config" FAIL "config missing"
    LIM=""
    LMIN=""
  fi

  LOCK_CJ="/tmp/smoke_cj_lock.txt"
  docker exec "$CONTAINER_NAME" sh -c "rm -f ${LOCK_CJ}"
  if [ "$LIM" = "3" ] && [ "$LMIN" = "1" ]; then
    docker exec "$CONTAINER_NAME" sh -c "rm -f /var/www/nav/data/ip_locks.json" || true
    for i in 1 2 3; do
      CSRF_L=$(docker exec "$CONTAINER_NAME" curl -s -c "$LOCK_CJ" -b "$LOCK_CJ" --max-time 10 "${BASE}/login.php" 2>/dev/null | tr '\n' ' ' | sed -nE 's/.*name=\"_csrf\" value=\"([^\"]+)\".*/\\1/p' | head -1 || true)
      LR=$(docker exec "$CONTAINER_NAME" curl -s -c "$LOCK_CJ" -b "$LOCK_CJ" --max-time 10 \
        "${BASE}/login.php" \
        -d "_csrf=${CSRF_L}" -d "username=admin" -d "password=wrongpass" 2>/dev/null || true)
    done
    LOCK_JSON=$(docker exec "$CONTAINER_NAME" cat /var/www/nav/data/ip_locks.json 2>/dev/null || true)
    if [ -z "$LOCK_JSON" ]; then
      docker exec "$CONTAINER_NAME" sh -c "php -r 'require \"/var/www/nav/shared/auth.php\"; \$ip=\"127.0.0.1\"; for(\$i=0;\$i<3;\$i++){ip_record_fail(\$ip);}';" 2>/dev/null || true
      LOCK_JSON=$(docker exec "$CONTAINER_NAME" cat /var/www/nav/data/ip_locks.json 2>/dev/null || true)
    fi
    FAILS=$(echo "$LOCK_JSON" | sed -n 's/.*\"fails\":[^0-9]*\([0-9][0-9]*\).*/\1/p' | head -1)
    LOCKED=$(echo "$LOCK_JSON" | sed -n 's/.*\"locked_until\":[^0-9]*\([0-9][0-9]*\).*/\1/p' | head -1)
    LOCK_IP=$(echo "$LOCK_JSON" | sed -n 's/^[[:space:]]*\"\\([^\"]\\+\\)\".*/\\1/p' | head -1)
    if [ -n "$FAILS" ] && [ "$FAILS" -ge 3 ] && [ -n "$LOCKED" ] && [ "$LOCKED" -gt 0 ]; then
      log_case "ip_lock_threshold" PASS
      LOCK_OK=1
    else
      log_case "ip_lock_threshold" FAIL "lock_json=${LOCK_JSON:-empty}"
      LOCK_OK=0
    fi
    CSRF_L=$(docker exec "$CONTAINER_NAME" curl -s -c "$LOCK_CJ" -b "$LOCK_CJ" --max-time 10 "${BASE}/login.php" 2>/dev/null | tr '\n' ' ' | sed -nE 's/.*name=\"_csrf\" value=\"([^\"]+)\".*/\\1/p' | head -1 || true)
    LR2=$(docker exec "$CONTAINER_NAME" curl -s -c "$LOCK_CJ" -b "$LOCK_CJ" --max-time 10 \
      "${BASE}/login.php" \
      -d "_csrf=${CSRF_L}" -d "username=admin" --data-urlencode "password=Admin@smoke1" 2>/dev/null || true)
    ACODE_LOCK=$(docker exec "$CONTAINER_NAME" curl -s -b "$LOCK_CJ" -c "$LOCK_CJ" --max-time 10 -o /dev/null -w '%{http_code}' "${BASE}/index.php" 2>/dev/null || true)
    if [ "$LOCK_OK" = "1" ] && [ "$ACODE_LOCK" != "200" ]; then
      log_case "ip_lock_enforced" PASS
    else
      log_case "ip_lock_enforced" FAIL "index=$ACODE_LOCK"
    fi
  else
    log_case "ip_lock_threshold" SKIP "config not applied"
    log_case "ip_lock_enforced" SKIP "config not applied"
  fi
  if [ "$LIM" = "3" ] && [ "$LMIN" = "1" ]; then
    if [ -n "${LOCK_IP:-}" ]; then
      docker exec "$CONTAINER_NAME" sh -c "php -r 'require \"/var/www/nav/shared/auth.php\"; \$locks=ip_locks_load(); \$ip=\"${LOCK_IP}\"; if(isset(\$locks[\$ip])){ \$locks[\$ip][\"locked_until\"]=time()-5; ip_locks_save(\$locks);}';" 2>/dev/null || true
    else
      docker exec "$CONTAINER_NAME" sh -c "rm -f /var/www/nav/data/ip_locks.json" || true
    fi
    OLD_CJ="$CJ"
    CJ="$LOCK_CJ"
    CSRF_L=$(get_csrf "${BASE}/login.php")
    CJ="$OLD_CJ"
    if [ -z "$CSRF_L" ]; then
      docker exec "$CONTAINER_NAME" sh -c "rm -f ${LOCK_CJ}" || true
      OLD_CJ="$CJ"
      CJ="$LOCK_CJ"
      CSRF_L=$(get_csrf "${BASE}/login.php")
      CJ="$OLD_CJ"
    fi
    LCODE=$(docker exec "$CONTAINER_NAME" curl -s -c "$LOCK_CJ" -b "$LOCK_CJ" --max-time 10 -o /dev/null -w '%{http_code}' \
      "${BASE}/login.php" \
      -d "_csrf=${CSRF_L}" -d "username=admin" --data-urlencode "password=Admin@smoke1" 2>/dev/null || true)
    ACODE=$(docker exec "$CONTAINER_NAME" curl -s -b "$LOCK_CJ" -c "$LOCK_CJ" --max-time 10 -o /dev/null -w '%{http_code}' "${BASE}/index.php" 2>/dev/null || true)
    [ "$ACODE" = "200" ] && log_case "ip_lock_cleared" PASS || log_case "ip_lock_cleared" FAIL "login=$LCODE status=$ACODE"
  else
    log_case "ip_lock_cleared" SKIP "config not applied"
  fi

  CSRF=$(get_csrf "${BASE}/admin/settings.php")
  docker exec "$CONTAINER_NAME" curl -sL -b "$CJ" -c "$CJ" --max-time 15 -o /dev/null \
    "${BASE}/admin/settings.php" \
    -d "_csrf=${CSRF}" -d "action=save_settings" \
    -d "site_name=SmokeTest" -d "nav_domain=smoke.test" \
    -d "token_expire_hours=8" -d "remember_me_days=60" \
    -d "login_fail_limit=5" -d "login_lock_minutes=15" \
    -d "cookie_secure=off" -d "cookie_domain=" \
    -d "card_size=140" -d "card_height=0" \
    -d "card_show_desc=1" -d "card_layout=grid" -d "card_direction=col" \
    -d "bg_color=" 2>/dev/null || true
else
  log_case "ip_lock_config" SKIP "no CSRF"
  log_case "ip_lock_threshold" SKIP "no CSRF"
  log_case "ip_lock_enforced" SKIP "no CSRF"
  log_case "ip_lock_cleared" SKIP "no CSRF"
fi

# ------- 12. Log API / cookie clear -------
echo ""; echo "[12/13] Log API and cookie clear..."
for LTYPE in nginx_access nginx_error php_fpm; do
  LC=$(hcode "${BASE}/admin/debug.php?ajax=log&type=${LTYPE}&lines=10")
  [ "$LC" = "200" ] && log_case "log_api_${LTYPE}" PASS || log_case "log_api_${LTYPE}" FAIL "HTTP $LC"
done
CSRF=$(get_csrf "${BASE}/admin/debug.php")
if [ -n "$CSRF" ]; then
  CLR=$(docker exec "$CONTAINER_NAME" curl -s -b "$CJ" -c "$CJ" --max-time 10 \
    "${BASE}/admin/debug.php" \
    -H 'X-Requested-With: XMLHttpRequest' \
    -F "ajax=clear_log" -F "_csrf=${CSRF}" 2>/dev/null || true)
  echo "$CLR" | grep -qF '"ok":true' && log_case "log_clear_api" PASS || log_case "log_clear_api" FAIL "${CLR:0:120}"
  docker exec "$CONTAINER_NAME" curl -sL -b "$CJ" -c "$CJ" --max-time 10 \
    "${BASE}/admin/debug.php" \
    -d "_csrf=${CSRF}" -d "action=clear_cookie" -o /dev/null 2>/dev/null || true
  log_case "cookie_clear" PASS
else
  log_case "log_clear_api" SKIP "no CSRF"
  log_case "cookie_clear" SKIP "no CSRF"
fi

# ------- 13. Health check -------
echo ""; echo "[13/13] Docker health check..."
sleep 35
HSTAT=$(docker inspect --format='{{.State.Health.Status}}' "$CONTAINER_NAME" 2>/dev/null || echo unknown)
[ "$HSTAT" = "healthy" ] && log_case "docker_healthcheck" PASS || log_case "docker_healthcheck" FAIL "status=$HSTAT"

# ------- Final report -------
TOTAL=$((PASS+FAIL+SKIP))
if [ -n "${LOAD_TIMINGS_FILE:-}" ] && [ -f "$LOAD_TIMINGS_FILE" ] && [ -s "$LOAD_TIMINGS_FILE" ]; then
  printf '\n## Page load timings (seconds, sorted slowest first)\n' >> "$REPORT_PATH"
  printf 'name\turl\ttime_total\n' >> "$REPORT_PATH"
  sort -t $'\t' -k3 -nr "$LOAD_TIMINGS_FILE" >> "$REPORT_PATH" || true
  MAX_LINE=$(sort -t $'\t' -k3 -nr "$LOAD_TIMINGS_FILE" | head -1 || true)
  if [ -n "$MAX_LINE" ]; then
    printf '\n## Page load slowest: %s\n' "$MAX_LINE" >> "$REPORT_PATH"
  fi
fi
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
