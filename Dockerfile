# ============================================================
# 导航网站 Docker 镜像
# 基础：php:8.2-fpm-alpine + Nginx（单容器方案）
# 支持架构：linux/amd64、linux/arm64
# PHP 版本：8.2（可替换为 8.1 / 8.0 / 7.4 / 7.3）
# ============================================================
FROM php:8.2-fpm-alpine

# ── 代理策略 ──
# 不在镜像中显式写入 HTTP(S)_PROXY/NO_PROXY。
# 构建期与运行期均跟随宿主机 / Docker 自身代理配置。

# ── 安装系统依赖 ──
# Alpine 使用 apk，--no-cache 避免残留索引
# 已移除生产不需要的包：vim、less、binutils、iproute2、net-tools
# fileinfo 扩展依赖 libmagic（file 提供运行时库 + file-dev 开发头文件）
RUN apk add --no-cache \
    nginx \
    supervisor \
    shadow \
    tzdata \
    sudo \
    dcron \
    python3 \
    file \
    file-dev \
    curl \
    bash \
    gettext \
    ca-certificates \
    icu-libs

# ── 设置时区 ──
ARG TZ=Asia/Shanghai
RUN cp /usr/share/zoneinfo/${TZ} /etc/localtime && \
    echo "${TZ}" > /etc/timezone

# ── 安装 PHP 扩展 ──
# fileinfo 在 php:8.2-fpm-alpine 中已默认内置，无需额外安装
# session/json/hash/pcre 均为核心内置

# ── 创建运行用户（与 Nginx worker 统一）──
RUN addgroup -g 1000 navwww && \
    adduser -u 1000 -G navwww -s /bin/sh -D navwww && \
    addgroup navwww wheel

# ── 复制项目文件 ──
COPY --chown=navwww:navwww . /var/www/nav/

# ── 预创建配置目录 ──
RUN mkdir -p /etc/nginx/http.d /etc/nginx/conf.d

# ── 复制配置文件 ──
COPY docker/nginx.conf       /etc/nginx/nginx.conf
COPY docker/nginx-site.conf  /etc/nginx/http.d/nav.conf
COPY docker/php-fpm.conf     /usr/local/etc/php-fpm.d/nav.conf
COPY docker/php-custom.ini   /usr/local/etc/php/conf.d/99-nav-custom.ini
COPY docker/supervisord.conf /etc/supervisord.conf
COPY docker/entrypoint.sh    /entrypoint.sh

RUN chmod +x /entrypoint.sh && \
    # Nginx 主配置需对运行用户可写（后台编辑需要）
    chown root:navwww /etc/nginx/nginx.conf && \
    chmod 664 /etc/nginx/nginx.conf

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
    # 删除默认站点配置（会拦截所有请求返回 404）
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
