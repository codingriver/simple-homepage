# ============================================================
# 导航网站 Docker 镜像
# 基础：php:8.2-fpm-alpine + Nginx（单容器方案）
# PHP 版本：8.2（可替换为 8.1 / 8.0 / 7.4 / 7.3）
# ============================================================
FROM php:8.2-fpm-alpine

LABEL maintainer="nav-portal" \
      description="Nav Portal - PHP Navigation Site" \
      version="latest"

# ── 切换 Alpine 软件源（构建环境访问官方源不稳定时使用国内镜像）──
RUN printf '%s\n%s\n' \
    'https://mirrors.aliyun.com/alpine/v3.23/main' \
    'https://mirrors.aliyun.com/alpine/v3.23/community' \
    > /etc/apk/repositories

# ── 安装系统依赖 ──
RUN apk add --no-cache \
    nginx \
    supervisor \
    shadow \
    tzdata \
    sudo \
    # fileinfo 扩展依赖
    libmagic \
    file-dev \
    # 工具
    curl \
    bash

# ── 设置时区 ──
ARG TZ=Asia/Shanghai
RUN cp /usr/share/zoneinfo/${TZ} /etc/localtime && \
    echo "${TZ}" > /etc/timezone

# ── 安装 PHP 扩展 ──
# fileinfo（可选，背景图 MIME 检测增强）
RUN docker-php-ext-install fileinfo

# session 已内置，json/hash/pcre 均为核心内置，无需额外安装

# ── 创建运行用户（与 Nginx worker 统一）──
RUN addgroup -g 1000 navwww && \
    adduser -D -u 1000 -G navwww navwww

# ── 复制项目文件 ──
COPY --chown=navwww:navwww . /var/www/nav/

# ── 复制配置文件 ──
COPY docker/nginx.conf       /etc/nginx/nginx.conf
COPY docker/nginx-site.conf  /etc/nginx/http.d/nav.conf
COPY docker/php-fpm.conf     /usr/local/etc/php-fpm.d/nav.conf
COPY docker/php-custom.ini   /usr/local/etc/php/conf.d/99-nav-custom.ini
COPY docker/supervisord.conf /etc/supervisord.conf
COPY docker/entrypoint.sh    /entrypoint.sh

RUN chmod +x /entrypoint.sh

# ── 创建必要目录结构 ──
RUN mkdir -p \
    /var/www/nav/data/backups \
    /var/www/nav/data/logs \
    /var/www/nav/data/favicon_cache \
    /var/www/nav/data/bg \
    /var/log/nginx \
    /var/log/php-fpm \
    /run/nginx \
    /etc/nginx/conf.d && \
    # 删除 Alpine Nginx 自带的 default.conf（会拦截所有请求返回 404）
    rm -f /etc/nginx/http.d/default.conf && \
    # 配置 sudo 白名单，允许 navwww 执行 nginx -t 和 nginx -s reload
    echo 'navwww ALL=(ALL) NOPASSWD: /usr/sbin/nginx -t' > /etc/sudoers.d/nav-nginx && \
    echo 'navwww ALL=(ALL) NOPASSWD: /usr/sbin/nginx -s reload' >> /etc/sudoers.d/nav-nginx && \
    chmod 440 /etc/sudoers.d/nav-nginx && \
    # 创建空的反代配置文件（Alpine Nginx 用 http.d/，同时在 conf.d/ 放一份供后台写入）
    touch /etc/nginx/conf.d/nav-proxy.conf && \
    # Nginx 上传/代理临时目录（文件上传必须以 navwww 可写，否则 POST 返回 500）
    mkdir -p /var/lib/nginx/tmp/client_body \
             /var/lib/nginx/tmp/fastcgi \
             /var/lib/nginx/tmp/proxy \
             /var/lib/nginx/tmp/scgi \
             /var/lib/nginx/tmp/uwsgi && \
    chown -R navwww:navwww /var/lib/nginx && \
    chmod -R 755 /var/lib/nginx/tmp && \
    # 设置权限
    chown -R navwww:navwww /var/www/nav && \
    chmod 750 /var/www/nav/data && \
    chmod 755 /var/www/nav/data/bg \
              /var/www/nav/data/favicon_cache \
              /var/www/nav/data/backups \
              /var/www/nav/data/logs && \
    chown -R navwww:navwww /var/log/nginx /var/log/php-fpm /run/nginx && \
    chown navwww:navwww /etc/nginx/conf.d/nav-proxy.conf

# ── 挂载点（持久化数据目录）──
VOLUME ["/var/www/nav/data"]

# ── 暴露端口（默认 58080，可通过环境变量 NAV_PORT 覆盖）──
EXPOSE 58080

# ── 健康检查（默认端口 58080，支持 NAV_PORT 覆盖）──
HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
    CMD sh -c 'curl -sf "http://localhost:${NAV_PORT:-58080}/login.php" || exit 1'

ENTRYPOINT ["/entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisord.conf"]
