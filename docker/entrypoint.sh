#!/bin/sh
# ============================================================
# 容器启动入口脚本
# 负责：环境变量注入 Nginx 配置、目录初始化、权限修正
# ============================================================
set -e
umask 027

echo "[entrypoint] 导航网站容器启动..."

# ── 环境变量默认值 ──
export NAV_PORT=${NAV_PORT:-58080}
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

nav_run() {
    su navwww -s /bin/sh -c "$*"
}

nav_require_writable_dir() {
    path="$1"
    if ! nav_run "test -d '$path' && test -r '$path' && test -w '$path' && test -x '$path'"; then
        echo "[entrypoint][ERROR] $path 对运行用户 navwww 不可读写，请检查宿主机挂载目录权限，或设置 PUID/PGID 对齐。"
        exit 1
    fi
}

data_owner_uid() {
    stat -c '%u' /var/www/nav/data 2>/dev/null
}

data_owner_gid() {
    stat -c '%g' /var/www/nav/data 2>/dev/null
}

remap_nav_user() {
    current_uid="$(id -u navwww)"
    current_gid="$(id -g navwww)"
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
        echo "[entrypoint][WARN] 检测到 PUID/PGID 含 0；这表示容器内 navwww 将映射为 root 身份运行，不是自动取当前用户。"
    fi

    if [ "$target_gid" != "$current_gid" ]; then
        echo "[entrypoint] 调整 navwww GID: ${current_gid} -> ${target_gid}"
        groupmod -o -g "$target_gid" navwww
    fi

    if [ "$target_uid" != "$current_uid" ] || [ "$target_gid" != "$current_gid" ]; then
        echo "[entrypoint] 调整 navwww UID: ${current_uid} -> ${target_uid}"
        usermod -o -u "$target_uid" -g navwww navwww
    fi
}

# ── 时区设置 ──
if [ -f "/usr/share/zoneinfo/${TZ}" ]; then
    cp "/usr/share/zoneinfo/${TZ}" /etc/localtime
    echo "${TZ}" > /etc/timezone
fi

# ── Linux bind mount 权限对齐（支持自动检测）──
# 优先使用显式传入的 PUID/PGID；未传时自动按 /var/www/nav/data owner 对齐，避免递归 chown 挂载目录
remap_nav_user

echo "[entrypoint] Nginx 监听端口: ${NAV_PORT}"
echo "[entrypoint] 时区: ${TZ}"
echo "[entrypoint] 数据目录: /var/www/nav/data"

# ── 启动自检（面向小白）──
if awk '$2=="/var/www/nav/data"{found=1} END{exit !found}' /proc/mounts; then
    echo "[entrypoint] 数据目录挂载状态: OK（已检测到宿主机挂载）"
else
    echo "[entrypoint][WARN] 未检测到 /var/www/nav/data 宿主机挂载，重建容器会丢数据！"
    echo "[entrypoint][WARN] 建议使用：-v ./data:/var/www/nav/data"
fi

# ── 确保应用代码可读（开发模式会把宿主机整个项目挂进来，宿主机若是 700/600 权限会导致 PHP 直接 403）──
# 仅放宽代码目录读取权限；data 目录权限改为显式可写性检查，不再递归 chown/chmod
if [ -d /var/www/nav ]; then
    chmod 755 /var/www/nav || true
    for d in /var/www/nav/public /var/www/nav/shared /var/www/nav/admin /var/www/nav/cli /var/www/nav/docker /var/www/nav/python /var/www/nav/subsite-middleware /var/www/nav/nginx-conf; do
        [ -d "$d" ] && chmod -R a+rX "$d" || true
    done
fi

# ── 确保数据目录存在（持久化挂载后可能为空）──
mkdir -p /var/spool/cron/crontabs
if ! nav_run "mkdir -p /var/www/nav/data/backups /var/www/nav/data/logs /var/www/nav/data/favicon_cache /var/www/nav/data/bg /var/www/nav/data/nginx /var/www/nav/data/nginx/http.d /var/www/nav/data/php /var/www/nav/data/php-fpm"; then
    echo "[entrypoint][ERROR] 无法在 /var/www/nav/data 下创建运行目录，请检查宿主机挂载目录权限，或设置 PUID/PGID 对齐。"
    exit 1
fi
nav_require_writable_dir /var/www/nav/data
nav_require_writable_dir /var/www/nav/data/backups
nav_require_writable_dir /var/www/nav/data/logs
nav_require_writable_dir /var/www/nav/data/favicon_cache
nav_require_writable_dir /var/www/nav/data/bg
nav_require_writable_dir /var/www/nav/data/nginx
nav_require_writable_dir /var/www/nav/data/php
nav_require_writable_dir /var/www/nav/data/php-fpm

# ── 开发模式标记（PHP-FPM 子进程可能读不到容器环境变量，用文件供 auth_dev_mode_enabled() 检测）──
if [ "${NAV_DEV_MODE:-}" = "1" ] || [ "${NAV_DEV_MODE:-}" = "true" ]; then
    nav_run "touch /var/www/nav/data/.nav_dev_mode"
    echo "[entrypoint] NAV_DEV_MODE 已启用（内置测试管理员 qatest，见登录页）"
else
    rm -f /var/www/nav/data/.nav_dev_mode
fi

# ── 无人值守首次安装：仅需非空 ADMIN（PASSWORD 可空）；仅在尚未安装时写入 JSON ──
if [ -n "${ADMIN:-}" ]; then
    if [ ! -f /var/www/nav/data/.installed ] && { [ ! -f /var/www/nav/data/users.json ] || [ ! -s /var/www/nav/data/users.json ]; }; then
        export ADMIN
        export PASSWORD="${PASSWORD:-}"
        export NAME="${NAME:-导航中心}"
        export DOMAIN="${DOMAIN:-}"
        nav_run "php -r '\$d=\"/var/www/nav/data\"; if(!is_dir(\$d)) @mkdir(\$d,0750,true); \$j=[\"ADMIN\"=>(string)getenv(\"ADMIN\"),\"PASSWORD\"=>(string)getenv(\"PASSWORD\"),\"NAME\"=>(string)(getenv(\"NAME\")?:\"导航中心\"),\"DOMAIN\"=>(string)(getenv(\"DOMAIN\")?:\"\")]; file_put_contents(\$d.\"/.initial_admin.json\", json_encode(\$j, JSON_UNESCAPED_UNICODE));'"
        chmod 600 /var/www/nav/data/.initial_admin.json 2>/dev/null || true
        echo "[entrypoint] 已写入 .initial_admin.json（无人值守安装，首次访问即完成初始化）"
    fi
fi

# ── 确保反代配置文件存在 ──
mkdir -p /etc/nginx/conf.d /etc/nginx/http.d
touch /etc/nginx/conf.d/nav-proxy.conf
touch /etc/nginx/http.d/nav-proxy-domains.conf
chown navwww:navwww /etc/nginx/conf.d/nav-proxy.conf
chown navwww:navwww /etc/nginx/http.d/nav-proxy-domains.conf
chmod 664 /etc/nginx/conf.d/nav-proxy.conf
chmod 664 /etc/nginx/http.d/nav-proxy-domains.conf

# ── 系统配置文件持久化到 data 目录（容器重建后配置不丢失）──

# Nginx 主配置
if [ ! -f /var/www/nav/data/nginx/nginx.conf ]; then
    cp /var/www/nav/docker/nginx.conf /var/www/nav/data/nginx/nginx.conf
    echo "[entrypoint] Nginx 主配置已复制到 data/nginx/nginx.conf"
fi
if [ -f /etc/nginx/nginx.conf ] && [ ! -L /etc/nginx/nginx.conf ]; then
    rm -f /etc/nginx/nginx.conf.bak.default
    mv /etc/nginx/nginx.conf /etc/nginx/nginx.conf.bak.default
fi
ln -sf /var/www/nav/data/nginx/nginx.conf /etc/nginx/nginx.conf
chown root:navwww /var/www/nav/data/nginx/nginx.conf
chmod 664 /var/www/nav/data/nginx/nginx.conf

# Nginx 站点配置（首次复制到 data/ 时注入 NAV_PORT）
if [ ! -f /var/www/nav/data/nginx/http.d/nav.conf ]; then
    if command -v envsubst >/dev/null 2>&1; then
        envsubst '${NAV_PORT}' < /var/www/nav/nginx-conf/docker-site.conf > /var/www/nav/data/nginx/http.d/nav.conf
    else
        sed "s/\${NAV_PORT}/${NAV_PORT}/g" /var/www/nav/nginx-conf/docker-site.conf > /var/www/nav/data/nginx/http.d/nav.conf
    fi
    echo "[entrypoint] Nginx 站点配置已复制到 data/nginx/http.d/nav.conf（端口: ${NAV_PORT}）"
fi
if [ -f /etc/nginx/http.d/nav.conf ] && [ ! -L /etc/nginx/http.d/nav.conf ]; then
    rm -f /etc/nginx/http.d/nav.conf.bak.default
    mv /etc/nginx/http.d/nav.conf /etc/nginx/http.d/nav.conf.bak.default
fi
ln -sf /var/www/nav/data/nginx/http.d/nav.conf /etc/nginx/http.d/nav.conf
chown root:navwww /var/www/nav/data/nginx/http.d/nav.conf
chmod 664 /var/www/nav/data/nginx/http.d/nav.conf

# PHP 自定义配置
if [ ! -f /var/www/nav/data/php/custom.ini ]; then
    cp /var/www/nav/docker/php-custom.ini /var/www/nav/data/php/custom.ini
    echo "[entrypoint] PHP 自定义配置已复制到 data/php/custom.ini"
fi
if [ -f /usr/local/etc/php/conf.d/99-nav-custom.ini ] && [ ! -L /usr/local/etc/php/conf.d/99-nav-custom.ini ]; then
    rm -f /usr/local/etc/php/conf.d/99-nav-custom.ini.bak.default
    mv /usr/local/etc/php/conf.d/99-nav-custom.ini /usr/local/etc/php/conf.d/99-nav-custom.ini.bak.default
fi
ln -sf /var/www/nav/data/php/custom.ini /usr/local/etc/php/conf.d/99-nav-custom.ini
chown root:navwww /var/www/nav/data/php/custom.ini
chmod 664 /var/www/nav/data/php/custom.ini

# PHP-FPM 配置
if [ ! -f /var/www/nav/data/php-fpm/nav.conf ]; then
    cp /var/www/nav/docker/php-fpm.conf /var/www/nav/data/php-fpm/nav.conf
    echo "[entrypoint] PHP-FPM 配置已复制到 data/php-fpm/nav.conf"
fi
if [ -f /usr/local/etc/php-fpm.d/nav.conf ] && [ ! -L /usr/local/etc/php-fpm.d/nav.conf ]; then
    rm -f /usr/local/etc/php-fpm.d/nav.conf.bak.default
    mv /usr/local/etc/php-fpm.d/nav.conf /usr/local/etc/php-fpm.d/nav.conf.bak.default
fi
ln -sf /var/www/nav/data/php-fpm/nav.conf /usr/local/etc/php-fpm.d/nav.conf
chown root:navwww /var/www/nav/data/php-fpm/nav.conf
chmod 664 /var/www/nav/data/php-fpm/nav.conf
# ── 从镜像复制 Nginx 代理模板到数据目录（不存在/为空/不可读写时）──
for tmpl in proxy-params-simple.conf proxy-params-full.conf proxy-template-path.conf proxy-template-domain.conf; do
    src="/var/www/nav/nginx-conf/$tmpl"
    dst="/var/www/nav/data/nginx/$tmpl"
    need_copy=0
    if [ ! -f "$dst" ]; then
        need_copy=1
    elif [ ! -s "$dst" ]; then
        need_copy=1
    elif [ ! -r "$dst" ] || [ ! -w "$dst" ]; then
        need_copy=1
    fi
    if [ "$need_copy" = 1 ] && [ -f "$src" ] && [ -r "$src" ]; then
        cp "$src" "$dst"
        chown navwww:navwww "$dst"
        chmod 755 "$dst"
        echo "[entrypoint] 已从镜像复制 $tmpl 到 data/nginx/"
    fi
done

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

if /usr/local/sbin/php-fpm -t --fpm-config /usr/local/etc/php-fpm.d/nav.conf >/tmp/phpfpm-test.log 2>&1; then
    phpfpm_ok=1
else
    echo "[entrypoint][WARN] PHP-FPM data 配置校验失败"
    cat /tmp/phpfpm-test.log
fi

if [ "$nginx_ok" = 1 ] && [ "$phpfpm_ok" = 1 ]; then
    rm -f /var/www/nav/data/.compat_mode
    echo "[entrypoint] 系统配置校验全部通过"
else
    # 有配置失败，进入兼容模式
    mkdir -p /var/www/nav/data/logs
    if [ "$nginx_ok" = 0 ]; then
        cp /tmp/nginx-test.log /var/www/nav/data/logs/nginx_compat_error.log
    fi
    if [ "$phpfpm_ok" = 0 ]; then
        cp /tmp/phpfpm-test.log /var/www/nav/data/logs/phpfpm_compat_error.log
    fi
    touch /var/www/nav/data/.compat_mode

    # Nginx 回退
    if [ "$nginx_ok" = 0 ]; then
        echo "[entrypoint] 正在回退 Nginx 配置..."
        if [ -L /etc/nginx/nginx.conf ]; then
            mv /etc/nginx/nginx.conf /etc/nginx/nginx.conf.data
            cp /var/www/nav/docker/nginx.conf /etc/nginx/nginx.conf
        fi
        if [ -L /etc/nginx/http.d/nav.conf ]; then
            mv /etc/nginx/http.d/nav.conf /etc/nginx/http.d/nav.conf.data
            cp /var/www/nav/nginx-conf/docker-site.conf /etc/nginx/http.d/nav.conf
            envsubst '${NAV_PORT}' < /etc/nginx/http.d/nav.conf > /tmp/nav.conf.tmp
            mv /tmp/nav.conf.tmp /etc/nginx/http.d/nav.conf
        fi
    fi

    # PHP-FPM / PHP ini 回退
    if [ "$phpfpm_ok" = 0 ]; then
        echo "[entrypoint] 正在回退 PHP-FPM / PHP 配置..."
        if [ -L /usr/local/etc/php-fpm.d/nav.conf ]; then
            mv /usr/local/etc/php-fpm.d/nav.conf /usr/local/etc/php-fpm.d/nav.conf.data
            cp /var/www/nav/docker/php-fpm.conf /usr/local/etc/php-fpm.d/nav.conf
        fi
        if [ -L /usr/local/etc/php/conf.d/99-nav-custom.ini ]; then
            mv /usr/local/etc/php/conf.d/99-nav-custom.ini /usr/local/etc/php/conf.d/99-nav-custom.ini.data
            cp /var/www/nav/docker/php-custom.ini /usr/local/etc/php/conf.d/99-nav-custom.ini
        fi
    fi

    # 再次校验内置配置
    nginx_test_ok=0
    phpfpm_test_ok=0
    nginx -t >/tmp/nginx-test2.log 2>&1 && nginx_test_ok=1
    /usr/local/sbin/php-fpm -t --fpm-config /usr/local/etc/php-fpm.d/nav.conf >/tmp/phpfpm-test2.log 2>&1 && phpfpm_test_ok=1

    if [ "$nginx_test_ok" = 1 ] && [ "$phpfpm_test_ok" = 1 ]; then
        echo "[entrypoint] 已切换到内置默认配置，服务可正常启动"
    else
        echo "[entrypoint][ERROR] 内置默认配置也无法启动，容器无法启动"
        [ "$nginx_test_ok" = 1 ] || cat /tmp/nginx-test2.log
        [ "$phpfpm_test_ok" = 1 ] || cat /tmp/phpfpm-test2.log
        exit 1
    fi
fi

# ── 根据持久化数据预生成反代配置（容器重建后 /etc/nginx 下的动态配置会丢失）──
if [ -f /var/www/nav/data/sites.json ]; then
    su navwww -s /bin/sh -c 'php -r '\''require "/var/www/nav/admin/shared/functions.php"; $result = nginx_apply_proxy_conf(false); echo "[entrypoint] " . ($result["msg"] ?? "proxy config generate skipped") . PHP_EOL;'\''' || \
        echo "[entrypoint][WARN] 反代配置预生成失败，容器将继续启动，可稍后在后台手动 Reload Nginx"
fi

# ── 根据持久化数据预生成 crontab（容器重建后 /var/spool/cron/crontabs 下的配置会丢失）──
if [ -f /var/www/nav/data/scheduled_tasks.json ]; then
    su navwww -s /bin/sh -c 'php -r '\''require "/var/www/nav/admin/shared/cron_lib.php"; $result = cron_regenerate(); echo "[entrypoint] " . ($result["ok"] ? "crontab 已恢复" : "crontab 恢复失败：" . ($result["msg"] ?? "未知错误")) . PHP_EOL;'\''' || \
        echo "[entrypoint][WARN] crontab 恢复失败，容器将继续启动"
fi

# ── 删除默认站点配置（会拦截所有请求返回 404）──
rm -f /etc/nginx/http.d/default.conf
rm -f /etc/nginx/sites-enabled/default 2>/dev/null || true

# ── 创建 nginx 操作包装脚本（供 PHP 后台调用）──
printf '#!/bin/sh\nif [ ! -f /run/nginx/nginx.pid ]; then\n  echo "nginx pid not found"\n  exit 1\nfi\ntouch /tmp/nginx-reload-trigger\n' > /usr/local/bin/nginx-reload
chmod 755 /usr/local/bin/nginx-reload
printf '#!/bin/sh\nexec /usr/sbin/nginx -t\n' > /usr/local/bin/nginx-test
chmod 755 /usr/local/bin/nginx-test

cat >/usr/local/bin/php-fpm-reload <<'RELOAD_EOF'
#!/bin/sh
# 尝试 graceful reload PHP-FPM

# 方法1: supervisorctl signal (graceful, supervisor 4.0+)
if supervisorctl signal USR2 php-fpm >/dev/null 2>&1; then
    exit 0
fi

# 方法2: 通过 PID 文件发送 USR2
pidfile="/run/php-fpm.pid"
if [ -f "$pidfile" ]; then
    if kill -USR2 "$(cat "$pidfile")" 2>/dev/null; then
        exit 0
    fi
fi

# 方法3: 通过 /proc 查找 master PID
master_pid=""
for p in /proc/[0-9]*; do
    if [ -f "$p/cmdline" ] && grep -q "php-fpm: master process" "$p/cmdline" 2>/dev/null; then
        master_pid=$(basename "$p")
        break
    fi
done
if [ -n "$master_pid" ]; then
    if kill -USR2 "$master_pid" 2>/dev/null; then
        exit 0
    fi
fi

echo "php-fpm reload failed: unable to find process or send signal" >&2
exit 1
RELOAD_EOF
chmod 755 /usr/local/bin/php-fpm-reload
cat >/usr/local/bin/nav-task-compat <<'EOF'
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
    export NAV_TASK_LOCK_PATH="/var/www/nav/data/logs/cron_${task_id}.lock"
    php <<'PHP'
<?php
$path = (string)(getenv('NAV_TASK_LOCK_PATH') ?: '');
if ($path === '') {
    fwrite(STDERR, "missing lock path\n");
    exit(1);
}
if (!str_starts_with($path, '/var/www/nav/data/logs/cron_') || !str_ends_with($path, '.lock')) {
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
chmod 755 /usr/local/bin/nav-task-compat
cat >/etc/sudoers.d/nav-task-compat <<'EOF'
navwww ALL=(ALL) NOPASSWD: /usr/local/bin/nav-task-compat cfst
navwww ALL=(ALL) NOPASSWD: /usr/local/bin/nav-task-compat lock *
EOF
chmod 440 /etc/sudoers.d/nav-task-compat
rm -f /tmp/cfst.lock 2>/dev/null || true

# ── 运行时目录 ──
mkdir -p /run/nginx /var/log/nginx /var/log/php-fpm
chown -R navwww:navwww /run/nginx /var/log/nginx /var/log/php-fpm
mkdir -p /home/navwww
chown -R navwww:navwww /home/navwww /var/spool/cron/crontabs
# Nginx 上传临时目录（文件上传必须可写）
mkdir -p /var/lib/nginx/tmp/client_body /var/lib/nginx/tmp/fastcgi \
         /var/lib/nginx/tmp/proxy /var/lib/nginx/tmp/scgi /var/lib/nginx/tmp/uwsgi
chown -R navwww:navwww /var/lib/nginx
chmod -R 755 /var/lib/nginx/tmp
# 确保日志文件存在且可读
touch /var/log/nginx/nav.access.log /var/log/nginx/nav.error.log \
      /var/log/nginx/access.log /var/log/nginx/error.log \
      /var/log/php-fpm/error.log
chown navwww:navwww /var/log/nginx/nav.access.log /var/log/nginx/nav.error.log \
                  /var/log/nginx/access.log /var/log/nginx/error.log \
                  /var/log/php-fpm/error.log
chmod 664 /var/log/nginx/nav.access.log /var/log/nginx/nav.error.log \
          /var/log/nginx/access.log /var/log/nginx/error.log \
          /var/log/php-fpm/error.log

touch /var/log/crond.log
chown navwww:navwww /var/log/crond.log 2>/dev/null || true

echo "[entrypoint] 初始化完成，启动服务..."

exec "$@"
