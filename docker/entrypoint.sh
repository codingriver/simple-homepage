#!/bin/sh
# ============================================================
# 容器启动入口脚本
# 负责：环境变量注入 Nginx 配置、目录初始化、权限修正
# ============================================================
set -e
umask 027

echo "[entrypoint] 导航网站容器启动..."

# ── 环境变量默认值 ──
NAV_PORT=${NAV_PORT:-58080}
TZ=${TZ:-Asia/Shanghai}

# ── 时区设置 ──
if [ -f "/usr/share/zoneinfo/${TZ}" ]; then
    cp "/usr/share/zoneinfo/${TZ}" /etc/localtime
    echo "${TZ}" > /etc/timezone
fi

# ── 将 NAV_PORT 注入 Nginx 站点配置 ──
# nginx-site.conf 中使用了 ${NAV_PORT} 占位，用 envsubst 替换
if command -v envsubst >/dev/null 2>&1; then
    envsubst '${NAV_PORT}' < /etc/nginx/http.d/nav.conf > /tmp/nav.conf.tmp
    mv /tmp/nav.conf.tmp /etc/nginx/http.d/nav.conf
else
    # Alpine 上 envsubst 在 gettext 包，降级处理
    sed -i "s/\${NAV_PORT}/${NAV_PORT}/g" /etc/nginx/http.d/nav.conf
fi

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
# 仅放宽代码目录读取权限；data 目录后面会再按更严格权限单独收口
if [ -d /var/www/nav ]; then
    chmod 755 /var/www/nav || true
    for d in /var/www/nav/public /var/www/nav/shared /var/www/nav/admin /var/www/nav/cli /var/www/nav/docker /var/www/nav/python /var/www/nav/subsite-middleware /var/www/nav/nginx-conf; do
        [ -d "$d" ] && chmod -R a+rX "$d" || true
    done
fi

# ── 确保数据目录存在（持久化挂载后可能为空）──
mkdir -p \
    /var/www/nav/data/backups \
    /var/www/nav/data/logs \
    /var/www/nav/data/favicon_cache \
    /var/www/nav/data/bg \
    /var/spool/cron/crontabs

# ── 修正权限（挂载外部卷时属主可能变化）──
chown -R navwww:navwww /var/www/nav/data
chmod 750 /var/www/nav/data
chmod 755 /var/www/nav/data/bg \
          /var/www/nav/data/favicon_cache \
          /var/www/nav/data/backups \
          /var/www/nav/data/logs
# 确保关键数据文件可被 navwww 读写（迁移文件可能 root 属主）
# ── 开发模式标记（PHP-FPM 子进程可能读不到容器环境变量，用文件供 auth_dev_mode_enabled() 检测）──
if [ "${NAV_DEV_MODE:-}" = "1" ] || [ "${NAV_DEV_MODE:-}" = "true" ]; then
    touch /var/www/nav/data/.nav_dev_mode
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
        php -r '$d="/var/www/nav/data"; if(!is_dir($d)) @mkdir($d,0750,true); $j=["ADMIN"=>(string)getenv("ADMIN"),"PASSWORD"=>(string)getenv("PASSWORD"),"NAME"=>(string)(getenv("NAME")?:"导航中心"),"DOMAIN"=>(string)(getenv("DOMAIN")?:"")]; file_put_contents($d."/.initial_admin.json", json_encode($j, JSON_UNESCAPED_UNICODE));'
        chown navwww:navwww /var/www/nav/data/.initial_admin.json 2>/dev/null || true
        chmod 600 /var/www/nav/data/.initial_admin.json 2>/dev/null || true
        echo "[entrypoint] 已写入 .initial_admin.json（无人值守安装，首次访问即完成初始化）"
    fi
fi

for f in /var/www/nav/data/users.json \
         /var/www/nav/data/config.json \
         /var/www/nav/data/sites.json \
         /var/www/nav/data/scheduled_tasks.json \
         /var/www/nav/data/dns_config.json \
         /var/www/nav/data/ip_locks.json \
         /var/www/nav/data/.installed \
         /var/www/nav/data/.nav_dev_mode \
         /var/www/nav/data/.initial_admin.json; do
    [ -f "$f" ] && chown navwww:navwww "$f" && chmod 644 "$f"
done

# ── 确保反代配置文件存在 ──
mkdir -p /etc/nginx/conf.d /etc/nginx/http.d /var/www/nav/data/nginx
touch /etc/nginx/conf.d/nav-proxy.conf
touch /etc/nginx/http.d/nav-proxy-domains.conf
touch /var/www/nav/data/nginx/proxy-params-simple.conf
touch /var/www/nav/data/nginx/proxy-params-full.conf
chown navwww:navwww /etc/nginx/conf.d/nav-proxy.conf
chown navwww:navwww /etc/nginx/http.d/nav-proxy-domains.conf
chown navwww:navwww /var/www/nav/data/nginx/proxy-params-simple.conf
chown navwww:navwww /var/www/nav/data/nginx/proxy-params-full.conf
chmod 664 /etc/nginx/conf.d/nav-proxy.conf
chmod 664 /etc/nginx/http.d/nav-proxy-domains.conf
chmod 664 /var/www/nav/data/nginx/proxy-params-simple.conf
chmod 664 /var/www/nav/data/nginx/proxy-params-full.conf

# ── 根据持久化数据预生成反代配置（容器重建后 /etc/nginx 下的动态配置会丢失）──
if [ -f /var/www/nav/data/sites.json ]; then
    su navwww -s /bin/sh -c 'php -r '\''require "/var/www/nav/admin/shared/functions.php"; $result = nginx_apply_proxy_conf(false); echo "[entrypoint] " . ($result["msg"] ?? "proxy config generate skipped") . PHP_EOL;'\''' || \
        echo "[entrypoint][WARN] 反代配置预生成失败，容器将继续启动，可稍后在后台手动 Reload Nginx"
fi

# ── 删除 Alpine Nginx 自带 default.conf（会拦截所有请求返回 404）──
rm -f /etc/nginx/http.d/default.conf

# ── 修正 PHP ini 文件权限（navwww 需要读写 display_errors 开关）──
if [ -f /usr/local/etc/php/conf.d/99-nav-custom.ini ]; then
    chown root:navwww /usr/local/etc/php/conf.d/99-nav-custom.ini
    chmod 664 /usr/local/etc/php/conf.d/99-nav-custom.ini
fi

# ── 创建 nginx 操作包装脚本（供 PHP 后台调用）──
printf '#!/bin/busybox sh\nif [ ! -f /run/nginx/nginx.pid ]; then\n  echo "nginx pid not found"\n  exit 1\nfi\ntouch /tmp/nginx-reload-trigger\n' > /usr/local/bin/nginx-reload
chmod 755 /usr/local/bin/nginx-reload
printf '#!/bin/busybox sh\nexec /usr/sbin/nginx -t\n' > /usr/local/bin/nginx-test
chmod 755 /usr/local/bin/nginx-test

# ── 运行时目录 ──
mkdir -p /run/nginx /var/log/nginx /var/log/php-fpm
chown -R navwww:navwww /run/nginx /var/log/nginx /var/log/php-fpm
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
