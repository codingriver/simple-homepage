#!/bin/sh
# ============================================================
# 容器启动入口脚本
# 负责：环境变量注入 Nginx 配置、目录初始化、权限修正
# ============================================================
set -e
umask 027

echo "[entrypoint] RiverOps 容器启动..."

# ── 环境变量默认值 ──
export RIVEROPS_PORT=${RIVEROPS_PORT:-58080}
export TZ=${TZ:-Asia/Shanghai}
PUID=${PUID:-}
PGID=${PGID:-}

is_uint() {
    case "$1" in
        ''|*[!0-9]*)
            return 1
            ;;
        *)
            return 0
            ;;
    esac
}

riverops_run() {
    su riverops -s /bin/sh -c "$*"
}

riverops_require_writable_dir() {
    path="$1"
    if ! riverops_run "test -d '$path' && test -r '$path' && test -w '$path' && test -x '$path'"; then
        echo "[entrypoint][ERROR] $path 对运行用户 riverops 不可读写，请检查宿主机挂载目录权限，或设置 PUID/PGID 对齐。"
        exit 1
    fi
}

data_owner_uid() {
    stat -c '%u' /var/www/riverops/data 2>/dev/null
}

data_owner_gid() {
    stat -c '%g' /var/www/riverops/data 2>/dev/null
}

remap_riverops_user() {
    current_uid="$(id -u riverops)"
    current_gid="$(id -g riverops)"
    target_uid="$current_uid"
    target_gid="$current_gid"
    detected_uid="$current_uid"
    detected_gid="$current_gid"

    if detected_uid_tmp="$(data_owner_uid)" && detected_gid_tmp="$(data_owner_gid)"; then
        if is_uint "$detected_uid_tmp" && is_uint "$detected_gid_tmp"; then
            detected_uid="$detected_uid_tmp"
            detected_gid="$detected_gid_tmp"
        fi
    fi

    if [ -n "$PUID" ]; then
        if ! is_uint "$PUID"; then
            echo "[entrypoint][ERROR] PUID 必须是纯数字，当前值: $PUID"
            exit 1
        fi
        target_uid="$PUID"
    else
        if [ "$detected_uid" = "0" ]; then
            echo "[entrypoint][WARN] 自动检测到 data 目录 owner UID 为 0；为避免自动提权，继续使用镜像默认 UID: ${target_uid}"
        else
            target_uid="$detected_uid"
            echo "[entrypoint] 未显式设置 PUID，自动使用 data 目录 owner UID: ${target_uid}"
        fi
    fi
    if [ -n "$PGID" ]; then
        if ! is_uint "$PGID"; then
            echo "[entrypoint][ERROR] PGID 必须是纯数字，当前值: $PGID"
            exit 1
        fi
        target_gid="$PGID"
    else
        if [ "$detected_gid" = "0" ]; then
            echo "[entrypoint][WARN] 自动检测到 data 目录 owner GID 为 0；为避免自动提权，继续使用镜像默认 GID: ${target_gid}"
        else
            target_gid="$detected_gid"
            echo "[entrypoint] 未显式设置 PGID，自动使用 data 目录 owner GID: ${target_gid}"
        fi
    fi

    if [ "$target_uid" = "0" ] || [ "$target_gid" = "0" ]; then
        echo "[entrypoint][WARN] 检测到 PUID/PGID 含 0；这表示容器内 riverops 将映射为 root 身份运行，不是自动取当前用户。"
    fi

    if [ "$target_gid" != "$current_gid" ]; then
        echo "[entrypoint] 调整 riverops GID: ${current_gid} -> ${target_gid}"
        groupmod -o -g "$target_gid" riverops
    fi

    if [ "$target_uid" != "$current_uid" ] || [ "$target_gid" != "$current_gid" ]; then
        echo "[entrypoint] 调整 riverops UID: ${current_uid} -> ${target_uid}"
        usermod -o -u "$target_uid" -g riverops riverops
    fi
}

# ── 时区设置 ──
if [ -f "/usr/share/zoneinfo/${TZ}" ]; then
    cp "/usr/share/zoneinfo/${TZ}" /etc/localtime
    echo "${TZ}" > /etc/timezone
fi

# ── Linux bind mount 权限对齐（支持自动检测）──
# 优先使用显式传入的 PUID/PGID；未传时自动按 /var/www/riverops/data owner 对齐，避免递归 chown 挂载目录
remap_riverops_user

echo "[entrypoint] Nginx 监听端口: ${RIVEROPS_PORT}"
echo "[entrypoint] 时区: ${TZ}"
echo "[entrypoint] 数据目录: /var/www/riverops/data"

# ── 启动自检（面向小白）──
if awk '$2=="/var/www/riverops/data"{found=1} END{exit !found}' /proc/mounts; then
    echo "[entrypoint] 数据目录挂载状态: OK（已检测到宿主机挂载）"
else
    echo "[entrypoint][WARN] 未检测到 /var/www/riverops/data 宿主机挂载，重建容器会丢数据！"
    echo "[entrypoint][WARN] 建议使用：-v ./data:/var/www/riverops/data"
fi

# ── 确保应用代码可读（开发模式会把宿主机整个项目挂进来，宿主机若是 700/600 权限会导致 PHP 直接 403）──
# 仅放宽代码目录读取权限；data 目录权限改为显式可写性检查，不再递归 chown/chmod
if [ -d /var/www/riverops ]; then
    chmod 755 /var/www/riverops 2>/dev/null || true
    for d in /var/www/riverops/public /var/www/riverops/shared /var/www/riverops/admin /var/www/riverops/cli /var/www/riverops/docker /var/www/riverops/python /var/www/riverops/nginx-conf; do
        [ -d "$d" ] && chmod -R a+rX "$d" 2>/dev/null || true
    done
fi

# ── 确保数据目录存在（持久化挂载后可能为空）──
mkdir -p /var/spool/cron/crontabs
if ! riverops_run "mkdir -p /var/www/riverops/data/backups /var/www/riverops/data/logs /var/www/riverops/data/nginx /var/www/riverops/data/nginx/http.d /var/www/riverops/data/php /var/www/riverops/data/php-fpm"; then
    echo "[entrypoint][ERROR] 无法在 /var/www/riverops/data 下创建运行目录，请检查宿主机挂载目录权限，或设置 PUID/PGID 对齐。"
    exit 1
fi
riverops_require_writable_dir /var/www/riverops/data
riverops_require_writable_dir /var/www/riverops/data/backups
riverops_require_writable_dir /var/www/riverops/data/logs
riverops_require_writable_dir /var/www/riverops/data/nginx
riverops_require_writable_dir /var/www/riverops/data/php
riverops_require_writable_dir /var/www/riverops/data/php-fpm

# ── 开发模式标记（PHP-FPM 子进程可能读不到容器环境变量，用文件供 auth_dev_mode_enabled() 检测）──
if [ "${RIVEROPS_DEV_MODE:-}" = "1" ] || [ "${RIVEROPS_DEV_MODE:-}" = "true" ]; then
    riverops_run "touch /var/www/riverops/data/.riverops_dev_mode"
    echo "[entrypoint] RIVEROPS_DEV_MODE 已启用（内置测试管理员 qatest，见登录页）"
else
    rm -f /var/www/riverops/data/.riverops_dev_mode
fi

# ── 无人值守首次安装：仅需非空 ADMIN（PASSWORD 可空）；仅在尚未安装时写入 JSON ──
if [ -n "${ADMIN:-}" ]; then
    if [ ! -f /var/www/riverops/data/.installed ] && { [ ! -f /var/www/riverops/data/users.json ] || [ ! -s /var/www/riverops/data/users.json ]; }; then
        export ADMIN
        export PASSWORD="${PASSWORD:-}"
        export NAME="${NAME:-RiverOps}"
        export DOMAIN="${DOMAIN:-}"
        riverops_run "php -r '\$d=\"/var/www/riverops/data\"; if(!is_dir(\$d)) @mkdir(\$d,0750,true); \$j=[\"ADMIN\"=>(string)getenv(\"ADMIN\"),\"PASSWORD\"=>(string)getenv(\"PASSWORD\"),\"NAME\"=>(string)(getenv(\"NAME\")?:\"RiverOps\"),\"DOMAIN\"=>(string)(getenv(\"DOMAIN\")?:\"\")]; file_put_contents(\$d.\"/.initial_admin.json\", json_encode(\$j, JSON_UNESCAPED_UNICODE));'"
        chmod 600 /var/www/riverops/data/.initial_admin.json 2>/dev/null || true
        echo "[entrypoint] 已写入 .initial_admin.json（无人值守安装，首次访问即完成初始化）"
    fi
fi

# ── 确保 nginx 运行时目录存在 ──
mkdir -p /etc/nginx/http.d

# ── 系统配置文件持久化到 data 目录（容器重建后配置不丢失）──

# Nginx 主配置
if [ ! -f /var/www/riverops/data/nginx/nginx.conf ]; then
    cp /var/www/riverops/docker/nginx.conf /var/www/riverops/data/nginx/nginx.conf
    echo "[entrypoint] Nginx 主配置已复制到 data/nginx/nginx.conf"
fi
if [ -f /etc/nginx/nginx.conf ] && [ ! -L /etc/nginx/nginx.conf ]; then
    rm -f /etc/nginx/nginx.conf.bak.default
    mv /etc/nginx/nginx.conf /etc/nginx/nginx.conf.bak.default
fi
ln -sf /var/www/riverops/data/nginx/nginx.conf /etc/nginx/nginx.conf
chown root:riverops /var/www/riverops/data/nginx/nginx.conf
chmod 664 /var/www/riverops/data/nginx/nginx.conf

# Nginx 站点配置（首次复制到 data/ 时注入 RIVEROPS_PORT）
if [ ! -f /var/www/riverops/data/nginx/http.d/riverops.conf ]; then
    if command -v envsubst >/dev/null 2>&1; then
        envsubst '${RIVEROPS_PORT}' < /var/www/riverops/nginx-conf/docker-site.conf > /var/www/riverops/data/nginx/http.d/riverops.conf
    else
        sed "s/\${RIVEROPS_PORT}/${RIVEROPS_PORT}/g" /var/www/riverops/nginx-conf/docker-site.conf > /var/www/riverops/data/nginx/http.d/riverops.conf
    fi
    echo "[entrypoint] Nginx 站点配置已复制到 data/nginx/http.d/riverops.conf（端口: ${RIVEROPS_PORT}）"
fi
if [ -f /etc/nginx/http.d/riverops.conf ] && [ ! -L /etc/nginx/http.d/riverops.conf ]; then
    rm -f /etc/nginx/http.d/riverops.conf.bak.default
    mv /etc/nginx/http.d/riverops.conf /etc/nginx/http.d/riverops.conf.bak.default
fi
ln -sf /var/www/riverops/data/nginx/http.d/riverops.conf /etc/nginx/http.d/riverops.conf
chown root:riverops /var/www/riverops/data/nginx/http.d/riverops.conf
chmod 664 /var/www/riverops/data/nginx/http.d/riverops.conf

nginx_access_log_enabled="$(php -r '$f="/var/www/riverops/data/config.json"; $j=is_file($f) ? (json_decode((string)file_get_contents($f), true) ?: []) : []; echo ((string)($j["nginx_access_log_enabled"] ?? "0") === "1") ? "1" : "0";' 2>/dev/null || echo 0)"
if [ "$nginx_access_log_enabled" = "1" ]; then
    sed -i -E 's#^    access_log (off|/var/log/nginx/access\.log main);#    access_log /var/log/nginx/access.log main;#' /var/www/riverops/data/nginx/nginx.conf
    sed -i -E 's#^    access_log (off|/var/log/nginx/riverops\.access\.log);#    access_log /var/log/nginx/riverops.access.log;#' /var/www/riverops/data/nginx/http.d/riverops.conf
    echo "[entrypoint] Nginx 访问日志: 开启"
else
    sed -i -E 's#^    access_log (off|/var/log/nginx/access\.log main);#    access_log off;#' /var/www/riverops/data/nginx/nginx.conf
    sed -i -E 's#^    access_log (off|/var/log/nginx/riverops\.access\.log);#    access_log off;#' /var/www/riverops/data/nginx/http.d/riverops.conf
    echo "[entrypoint] Nginx 访问日志: 关闭"
fi

# PHP 自定义配置
if [ ! -f /var/www/riverops/data/php/custom.ini ]; then
    cp /var/www/riverops/docker/php-custom.ini /var/www/riverops/data/php/custom.ini
    echo "[entrypoint] PHP 自定义配置已复制到 data/php/custom.ini"
fi
if [ -f /usr/local/etc/php/conf.d/99-riverops-custom.ini ] && [ ! -L /usr/local/etc/php/conf.d/99-riverops-custom.ini ]; then
    rm -f /usr/local/etc/php/conf.d/99-riverops-custom.ini.bak.default
    mv /usr/local/etc/php/conf.d/99-riverops-custom.ini /usr/local/etc/php/conf.d/99-riverops-custom.ini.bak.default
fi
ln -sf /var/www/riverops/data/php/custom.ini /usr/local/etc/php/conf.d/99-riverops-custom.ini
chown root:riverops /var/www/riverops/data/php/custom.ini
chmod 664 /var/www/riverops/data/php/custom.ini

# PHP-FPM 配置
if [ ! -f /var/www/riverops/data/php-fpm/riverops.conf ]; then
    cp /var/www/riverops/docker/php-fpm.conf /var/www/riverops/data/php-fpm/riverops.conf
    echo "[entrypoint] PHP-FPM 配置已复制到 data/php-fpm/riverops.conf"
fi
if [ -f /usr/local/etc/php-fpm.d/riverops.conf ] && [ ! -L /usr/local/etc/php-fpm.d/riverops.conf ]; then
    rm -f /usr/local/etc/php-fpm.d/riverops.conf.bak.default
    mv /usr/local/etc/php-fpm.d/riverops.conf /usr/local/etc/php-fpm.d/riverops.conf.bak.default
fi
ln -sf /var/www/riverops/data/php-fpm/riverops.conf /usr/local/etc/php-fpm.d/riverops.conf
chown root:riverops /var/www/riverops/data/php-fpm/riverops.conf
chmod 664 /var/www/riverops/data/php-fpm/riverops.conf

sed -i -E 's#^pm\.max_children[[:space:]]*=.*#pm.max_children         = 10#' /var/www/riverops/data/php-fpm/riverops.conf
# ── 系统配置启动前校验与兼容模式切换 ──
# 分别测试 Nginx 和 PHP-FPM（含 PHP ini）配置
nginx_ok=0
phpfpm_ok=0

if nginx -t >/tmp/nginx-test.log 2>&1; then
    nginx_ok=1
else
    echo "[entrypoint][WARN] Nginx data 配置校验失败"
    cat /tmp/nginx-test.log
fi

if /usr/local/sbin/php-fpm -t --fpm-config /usr/local/etc/php-fpm.d/riverops.conf >/tmp/phpfpm-test.log 2>&1; then
    phpfpm_ok=1
else
    echo "[entrypoint][WARN] PHP-FPM data 配置校验失败"
    cat /tmp/phpfpm-test.log
fi

if [ "$nginx_ok" = 1 ] && [ "$phpfpm_ok" = 1 ]; then
    rm -f /var/www/riverops/data/.compat_mode
    echo "[entrypoint] 系统配置校验全部通过"
else
    # 有配置失败，进入兼容模式
    mkdir -p /var/www/riverops/data/logs
    if [ "$nginx_ok" = 0 ]; then
        cp /tmp/nginx-test.log /var/www/riverops/data/logs/nginx_compat_error.log
    fi
    if [ "$phpfpm_ok" = 0 ]; then
        cp /tmp/phpfpm-test.log /var/www/riverops/data/logs/phpfpm_compat_error.log
    fi
    touch /var/www/riverops/data/.compat_mode

    # Nginx 回退
    if [ "$nginx_ok" = 0 ]; then
        echo "[entrypoint] 正在回退 Nginx 配置..."
        if [ -L /etc/nginx/nginx.conf ]; then
            mv /etc/nginx/nginx.conf /etc/nginx/nginx.conf.data
            cp /var/www/riverops/docker/nginx.conf /etc/nginx/nginx.conf
        fi
        if [ -L /etc/nginx/http.d/riverops.conf ]; then
            mv /etc/nginx/http.d/riverops.conf /etc/nginx/http.d/riverops.conf.data
            cp /var/www/riverops/nginx-conf/docker-site.conf /etc/nginx/http.d/riverops.conf
            envsubst '${RIVEROPS_PORT}' < /etc/nginx/http.d/riverops.conf > /tmp/riverops.conf.tmp
            mv /tmp/riverops.conf.tmp /etc/nginx/http.d/riverops.conf
        fi
    fi

    # PHP-FPM / PHP ini 回退
    if [ "$phpfpm_ok" = 0 ]; then
        echo "[entrypoint] 正在回退 PHP-FPM / PHP 配置..."
        if [ -L /usr/local/etc/php-fpm.d/riverops.conf ]; then
            mv /usr/local/etc/php-fpm.d/riverops.conf /usr/local/etc/php-fpm.d/riverops.conf.data
            cp /var/www/riverops/docker/php-fpm.conf /usr/local/etc/php-fpm.d/riverops.conf
        fi
        if [ -L /usr/local/etc/php/conf.d/99-riverops-custom.ini ]; then
            mv /usr/local/etc/php/conf.d/99-riverops-custom.ini /usr/local/etc/php/conf.d/99-riverops-custom.ini.data
            cp /var/www/riverops/docker/php-custom.ini /usr/local/etc/php/conf.d/99-riverops-custom.ini
        fi
    fi

    # 再次校验内置配置
    nginx_test_ok=0
    phpfpm_test_ok=0
    nginx -t >/tmp/nginx-test2.log 2>&1 && nginx_test_ok=1
    /usr/local/sbin/php-fpm -t --fpm-config /usr/local/etc/php-fpm.d/riverops.conf >/tmp/phpfpm-test2.log 2>&1 && phpfpm_test_ok=1

    if [ "$nginx_test_ok" = 1 ] && [ "$phpfpm_test_ok" = 1 ]; then
        echo "[entrypoint] 已切换到内置默认配置，服务可正常启动"
    else
        echo "[entrypoint][ERROR] 内置默认配置也无法启动，容器无法启动"
        [ "$nginx_test_ok" = 1 ] || cat /tmp/nginx-test2.log
        [ "$phpfpm_test_ok" = 1 ] || cat /tmp/phpfpm-test2.log
        exit 1
    fi
fi

# ── 根据持久化数据预生成 crontab（容器重建后 /var/spool/cron/crontabs 下的配置会丢失）──
if [ -f /var/www/riverops/data/scheduled_tasks.json ]; then
    su riverops -s /bin/sh -c 'php -r '\''require "/var/www/riverops/admin/shared/cron_lib.php"; $result = cron_regenerate(); echo "[entrypoint] " . ($result["ok"] ? "crontab 已恢复" : "crontab 恢复失败：" . ($result["msg"] ?? "未知错误")) . PHP_EOL;'\''' || \
        echo "[entrypoint][WARN] crontab 恢复失败，容器将继续启动"
fi

# ── 删除默认站点配置（会拦截所有请求返回 404）──
rm -f /etc/nginx/http.d/default.conf
rm -f /etc/nginx/sites-enabled/default 2>/dev/null || true

# ── 创建 nginx 语法检测包装脚本（供 PHP 后台只读检测）──
printf '#!/bin/sh\nexec /usr/sbin/nginx -t\n' > /usr/local/bin/nginx-test
chmod 755 /usr/local/bin/nginx-test
cat >/usr/local/bin/riverops-task-helper <<'EOF'
#!/bin/sh
set -eu

sanitize_task_id() {
    case "${1:-}" in
        ''|*[!A-Za-z0-9_-]*)
            return 1
            ;;
        *)
            printf '%s\n' "$1"
            ;;
    esac
}

case "${1:-}" in
  cfst)
    rm -f /tmp/cfst.lock
    ;;
  lock)
    task_id="$(sanitize_task_id "${2:-}")" || {
      echo "invalid task id" >&2
      exit 1
    }
    export RIVEROPS_TASK_LOCK_PATH="/var/www/riverops/data/logs/cron_${task_id}.lock"
    php <<'PHP'
<?php
$path = (string)(getenv('RIVEROPS_TASK_LOCK_PATH') ?: '');
if ($path === '') {
    fwrite(STDERR, "missing lock path\n");
    exit(1);
}
if (!str_starts_with($path, '/var/www/riverops/data/logs/cron_') || !str_ends_with($path, '.lock')) {
    fwrite(STDERR, "invalid lock path\n");
    exit(1);
}
if (!file_exists($path)) {
    exit(0);
}
$handle = @fopen($path, 'c+');
if (!is_resource($handle)) {
    fwrite(STDERR, "open failed\n");
    exit(1);
}
if (!@flock($handle, LOCK_EX | LOCK_NB)) {
    @fclose($handle);
    exit(2);
}
@unlink($path);
@flock($handle, LOCK_UN);
@fclose($handle);
exit(0);
PHP
    ;;
  *)
    echo "unsupported compat target" >&2
    exit 1
    ;;
esac
EOF
chmod 755 /usr/local/bin/riverops-task-helper
cat >/etc/sudoers.d/riverops-task-helper <<'EOF'
riverops ALL=(ALL) NOPASSWD: /usr/local/bin/riverops-task-helper cfst
riverops ALL=(ALL) NOPASSWD: /usr/local/bin/riverops-task-helper lock *
EOF
chmod 440 /etc/sudoers.d/riverops-task-helper

# 运行环境管理由后台触发安装/更新 Node.js 等工具。按产品要求，容器内
# riverops 允许免密执行所有 sudo 命令；错误由后台页面展示命令、退出码和日志。
cat >/etc/sudoers.d/riverops-runtime <<'EOF'
riverops ALL=(ALL) NOPASSWD: ALL
EOF
chmod 440 /etc/sudoers.d/riverops-runtime
rm -f /tmp/cfst.lock 2>/dev/null || true

# ── 运行时目录 ──
mkdir -p /run/nginx /var/log/nginx /var/log/php-fpm
chown -R riverops:riverops /run/nginx /var/log/nginx /var/log/php-fpm
mkdir -p /home/riverops
chown -R riverops:riverops /home/riverops /var/spool/cron/crontabs
# Nginx 上传临时目录（文件上传必须可写）
mkdir -p /var/lib/nginx/tmp/client_body /var/lib/nginx/tmp/fastcgi \
         /var/lib/nginx/tmp/scgi /var/lib/nginx/tmp/uwsgi
chown -R riverops:riverops /var/lib/nginx
chmod -R 755 /var/lib/nginx/tmp
# 确保日志文件存在且可读
touch /var/log/nginx/riverops.access.log /var/log/nginx/riverops.error.log \
      /var/log/nginx/access.log /var/log/nginx/error.log \
      /var/log/php-fpm/error.log
chown riverops:riverops /var/log/nginx/riverops.access.log /var/log/nginx/riverops.error.log \
                  /var/log/nginx/access.log /var/log/nginx/error.log \
                  /var/log/php-fpm/error.log
chmod 664 /var/log/nginx/riverops.access.log /var/log/nginx/riverops.error.log \
          /var/log/nginx/access.log /var/log/nginx/error.log \
          /var/log/php-fpm/error.log

touch /var/log/crond.log
chown riverops:riverops /var/log/crond.log 2>/dev/null || true

echo "[entrypoint] 初始化完成，启动服务..."

exec "$@"
