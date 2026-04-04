# ============================================================
# 导航网站 Docker 镜像
# 基础：php:8.2-fpm-alpine + Nginx（单容器方案）
# PHP 版本：8.2（可替换为 8.1 / 8.0 / 7.4 / 7.3）
# ============================================================
FROM php:8.2-fpm-alpine

# ── 构建时代理参数（由 docker-compose build args 传入，构建完成后不保留）──
# 让 apk / PHP 扩展安装等步骤能走代理
ARG HTTP_PROXY
ARG HTTPS_PROXY
ARG http_proxy
ARG https_proxy
ARG NO_PROXY
ARG no_proxy
ENV HTTP_PROXY=${HTTP_PROXY} \
    HTTPS_PROXY=${HTTPS_PROXY} \
    http_proxy=${http_proxy} \
    https_proxy=${https_proxy} \
    NO_PROXY=${NO_PROXY} \
    no_proxy=${no_proxy}

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
    dcron \
    python3 \
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

# ── 清除构建时代理环境变量（不让代理泄漏到最终镜像）──
ENV HTTP_PROXY= \
    HTTPS_PROXY= \
    http_proxy= \
    https_proxy= \
    NO_PROXY= \
    no_proxy=

# ── 创建运行用户（与 Nginx worker 统一）──
RUN addgroup -g 1000 navwww && \
    (getent group crontab >/dev/null || addgroup -S crontab) && \
    adduser -D -u 1000 -G navwww navwww && \
    adduser navwww crontab && \
    adduser navwww wheel

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

# ── 构建元数据（GitHub Actions 传入；本地 docker build 未传则为 unknown）──
ARG GIT_COMMIT=unknown
ARG GIT_REF=unknown
ARG BUILD_DATE=unknown
ARG GIT_REPO_URL=https://github.com/codingriver/simple-homepage

LABEL org.opencontainers.image.title="simple-homepage" \
      org.opencontainers.image.description="Simple Homepage - PHP navigation site" \
      org.opencontainers.image.version="latest" \
      org.opencontainers.image.revision="${GIT_COMMIT}" \
      org.opencontainers.image.created="${BUILD_DATE}" \
      org.opencontainers.image.source="${GIT_REPO_URL}" \
      org.opencontainers.image.url="${GIT_REPO_URL}" \
      maintainer="simple-homepage"

# 供后台调试页读取；路径在 public 外，避免命中 nginx 对 .json 的 deny
RUN echo "{\"git_commit\":\"${GIT_COMMIT}\",\"git_ref\":\"${GIT_REF}\",\"build_date\":\"${BUILD_DATE}\",\"source\":\"${GIT_REPO_URL}\"}" > /var/www/nav/.build-info.json \
    && chown navwww:navwww /var/www/nav/.build-info.json

# ── 创建必要目录结构 ──
RUN mkdir -p \
    /var/www/nav/data/backups \
    /var/www/nav/data/logs \
    /var/www/nav/data/favicon_cache \
    /var/www/nav/data/bg \
    /var/www/nav/data/nginx \
    /var/spool/cron/crontabs \
    /var/log/nginx \
    /var/log/php-fpm \
    /run/nginx \
    /etc/nginx/conf.d && \
    # 删除 Alpine Nginx 自带的 default.conf（会拦截所有请求返回 404）
    rm -f /etc/nginx/http.d/default.conf && \
    # 配置 sudo 白名单，允许 navwww 执行 nginx -t 和 nginx -s reload
    echo 'navwww ALL=(ALL) NOPASSWD: /usr/sbin/nginx -t' > /etc/sudoers.d/nav-nginx && \
    echo 'navwww ALL=(ALL) NOPASSWD: /usr/sbin/nginx -s reload' >> /etc/sudoers.d/nav-nginx && \
    echo 'navwww ALL=(ALL) NOPASSWD: /usr/bin/crontab' >> /etc/sudoers.d/nav-nginx && \
    chmod 440 /etc/sudoers.d/nav-nginx && \
    # 创建空的反代配置文件
    touch /etc/nginx/conf.d/nav-proxy.conf \
          /etc/nginx/http.d/nav-proxy-domains.conf \
          /var/www/nav/data/nginx/proxy-params-simple.conf \
          /var/www/nav/data/nginx/proxy-params-full.conf && \
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
    chown navwww:navwww /etc/nginx/conf.d/nav-proxy.conf \
                        /etc/nginx/http.d/nav-proxy-domains.conf \
                        /var/www/nav/data/nginx/proxy-params-simple.conf \
                        /var/www/nav/data/nginx/proxy-params-full.conf && \
    chmod 664 /etc/nginx/conf.d/nav-proxy.conf \
              /etc/nginx/http.d/nav-proxy-domains.conf \
              /var/www/nav/data/nginx/proxy-params-simple.conf \
              /var/www/nav/data/nginx/proxy-params-full.conf

# ── 挂载点（持久化数据目录）──
VOLUME ["/var/www/nav/data"]

# ── 暴露端口（默认 58080，可通过环境变量 NAV_PORT 覆盖）──
EXPOSE 58080

ENTRYPOINT ["/entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisord.conf"]
