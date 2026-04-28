# 导航网站 Docker 部署文档

> 适用：任何支持 Docker 的 Linux / macOS / Windows（WSL2）环境。
> 镜像内置 Nginx + PHP 8.2-fpm + Supervisor，**宿主机无需安装任何 PHP 或 Nginx**。
> 镜像标签统一使用 `latest`，示例镜像名为 `codingriver/simple-homepage:latest`。
> 
> 本文档面向中高级用户，需要深度定制时参考。新手请直接看项目根目录 [README.md](../README.md)。

---

## 零、前置依赖与环境要求

### 0.1 宿主机必须安装

| 依赖 | 最低版本 | 推荐版本 | 安装命令（Linux）|
|---|---|---|---|
| **Docker Engine** | 20.10 | 24.x+ | `curl -fsSL https://get.docker.com \| sh` |
| **Docker Compose** | v2.0（插件）或 v1.29（独立）| v2.20+ | Docker 20.10+ 已内置 Compose v2 |

> ✅ **宿主机不需要安装**：PHP、Nginx、php-fpm、任何 PHP 扩展。全部由镜像内置。

**验证安装：**

```bash
docker --version          # Docker version 24.x.x
docker compose version    # Docker Compose version v2.x.x
```

### 0.2 宿主机硬件要求

| 资源 | 最低 | 推荐 | 说明 |
|---|---|---|---|
| CPU | 1 核 | 2 核 | PHP-FPM 进程池默认 4 个工作进程 |
| 内存 | 64MB | 128MB+ | Alpine 单容器方案，极致轻量 |
| 磁盘 | 300MB | 500MB+ | 镜像约 150MB + 数据目录 |
| 端口 | 任意空闲端口 | 58080（默认）| 可通过 `NAV_PORT` 修改 |

### 0.3 操作系统支持

| 系统 | 支持情况 | 备注 |
|---|---|---|
| Ubuntu 20.04+ / Debian 11+ | ✅ 推荐 | Docker 宿主机最佳兼容性 |
| CentOS 7/8 / RHEL | ✅ 支持 | 需手动安装 Docker |
| Alpine Linux | ✅ 镜像本身基于 Alpine | 宿主机为 Alpine 时同样兼容 |
| macOS（Apple Silicon / Intel）| ✅ 支持 | 需安装 Docker Desktop |
| Windows 10/11（WSL2）| ✅ 支持 | 需安装 Docker Desktop + WSL2 |
| 宝塔面板服务器 | ✅ 支持 | 宝塔软件商店可直接安装 Docker |
| NAS（群晖/威联通等）| ✅ 支持 | 通过 Container Manager / Docker 部署 |

### 0.4 部署前确认清单

```
必须（部署前）：
□ 宿主机已安装 Docker Engine 20.10+
□ 宿主机已安装 Docker Compose v2.0+
□ 目标端口（默认 58080）未被占用
□ 已创建数据持久化目录

可选（部署前）：
□ 确认时区设置（默认 Asia/Shanghai）
□ 如需域名访问：域名已解析到服务器 IP
□ 如需 HTTPS：准备 SSL 证书或配置前置 Nginx
□ 如需无人值守安装：准备 ADMIN/PASSWORD 环境变量

运行后（首次访问）：
□ 浏览器打开 http://服务器IP:端口，完成安装向导
□ 设置管理员账号和密码
□ 配置站点名称和导航域名
```

---

## 一、镜像内置环境详解

### 1.1 完整环境清单

| 层级 | 组件 | 版本 | 来源 | 说明 |
|---|---|---|---|---|
| **基础系统** | Alpine Linux | 3.19+（随 php:8.2-fpm-alpine）| Docker 官方镜像 | 极致轻量，支持 amd64/arm64 |
| **运行时** | PHP | 8.2-fpm | `php:8.2-fpm-alpine` | 当前默认版本，musl libc |
| **Web 服务器** | Nginx | Alpine 仓库稳定版 | `apk add nginx` | 处理静态文件和 PHP 转发 |
| **进程管理** | Supervisor | Alpine 仓库稳定版 | `apk add supervisor` | 管理 Nginx + PHP-FPM + Cron 生命周期 |
| **PHP 扩展** | `fileinfo` | 随 PHP 编译 | `docker-php-ext-install` | 背景图 MIME 检测（可选增强）|
| **PHP 扩展** | `session` | PHP 核心内置 | 内置 | Cookie/Session 管理 |
| **PHP 扩展** | `json` | PHP 7.1+ 永久内置 | 内置 | 数据文件读写 |
| **PHP 扩展** | `hash` | PHP 7.4+ 永久内置 | 内置 | Token 签名和比对 |
| **PHP 扩展** | `pcre` | PHP 4+ 内置 | 内置 | 正则校验 |
| **工具** | curl | Alpine 仓库 | `apk add curl` | 健康检查探针 |
| **工具** | tzdata | Alpine 仓库 | `apk add tzdata` | 时区数据库 |
| **工具** | bash | Alpine 仓库 | `apk add bash` | 启动脚本与任务脚本依赖 |
| **工具** | dcron | Alpine 仓库 | `apk add dcron` | 计划任务调度，兼容标准 crontab |
| **工具** | openssh-client | Alpine 仓库 | `apk add openssh-client` | SSH 连接与密钥管理 |

### 1.2 内置配置文件

| 配置文件（容器内路径）| 来源 | 作用 |
|---|---|---|
| `/etc/nginx/nginx.conf` | `docker/nginx.conf` | Nginx 主配置（worker、日志、MIME、**全局 proxy 参数**）|
| `/etc/nginx/http.d/nav.conf` | `docker/nginx-site.conf` | 站点配置（auth_request、PHP 转发、安全规则、**反代 location 内置完整参数**）|
| `/etc/nginx/conf.d/nav-proxy.conf` | 容器启动时自动创建（空文件）| 反代配置（由后台自动管理，include 到 server 块内）|
| `/etc/nginx/http.d/nav-proxy-domains.conf` | 容器启动时自动创建（空文件）| 子域反代配置（由后台自动管理）|
| `/usr/local/etc/php-fpm.d/nav.conf` | `docker/php-fpm.conf` | PHP-FPM 进程池配置 |
| `/usr/local/etc/php/conf.d/99-nav-custom.ini` | `docker/php-custom.ini` | PHP 自定义配置（display_errors 等）|
| `/etc/supervisord.conf` | `docker/supervisord.conf` | Supervisor 进程管理配置 |
| `/entrypoint.sh` | `docker/entrypoint.sh` | 容器启动入口脚本 |

### 1.3 PHP-FPM 进程池配置（默认值）

| 参数 | 默认值 | 说明 |
|---|---|---|
| 运行用户 | `navwww`（默认 uid 1000，自动按 data owner 对齐）| 非 root，安全隔离 |
| Socket | `/run/php-fpm.sock` | Nginx 与 PHP-FPM 通信 |
| 进程模式 | `dynamic` | 动态伸缩 |
| 最大子进程 | 20 | 并发上限 |
| 初始进程数 | 4 | 启动时创建 |
| `upload_max_filesize` | 32MB | 背景图上传限制 |
| `post_max_size` | 32MB | POST 数据上限 |
| `memory_limit` | 128MB | 单个 PHP 进程内存上限 |
| `max_execution_time` | 30s | 单次请求超时 |
| `session.save_path` | `/tmp` | Session 存储路径 |

### 1.4 Nginx 内置 Proxy 参数说明

`nginx.conf` 在 `http {}` 块内统一设置全局反代参数：`proxy_connect_timeout 10s`、`proxy_send_timeout 60s`、`proxy_read_timeout 60s`、`proxy_buffering on` 等。

`nginx-site.conf` 的每个 proxy `location` 块自动继承，无需重复配置。同时通过 `$connection_upgrade` map 自动支持 WebSocket。

> ⚠️ **权限说明**：`/var/lib/nginx/tmp/` 在 Dockerfile 构建时已 `chown -R navwww:navwww`。若出现文件上传 500 错误，请检查该目录归属是否为 `navwww`。

### 1.5 目录结构（容器内）

```text
/var/www/nav/                ← 项目根目录
├── public/                  ← Nginx Web 根目录
├── admin/                   ← 后台管理
├── shared/                  ← 核心认证库
├── cli/                     ← 命令行工具与守护进程
├── python/                  ← Python 辅助脚本
├── nginx-conf/              ← Nginx 配置模板（供参考）
└── data/                    ← 挂载点（持久化到宿主机）
    ├── users.json
    ├── config.json
    ├── sites.json
    ├── .installed
    ├── backups/
    ├── logs/
    ├── favicon_cache/
    ├── bg/
    ├── tasks/
    └── nginx/

/etc/nginx/                  ← Nginx 配置
/var/log/nginx/              ← Nginx 日志
/var/log/php-fpm/            ← PHP-FPM 日志
/run/php-fpm.sock            ← PHP-FPM Unix Socket
/run/nginx/                  ← Nginx PID 文件
```

### 1.6 容器启动流程

```text
docker compose up
    ↓
entrypoint.sh
    ├── 环境变量注入（NAV_PORT → Nginx 配置）
    ├── 时区设置（TZ 环境变量）
    ├── Linux bind mount 权限对齐（按 data owner 自动调整 navwww UID/GID）
    ├── 数据子目录创建（backups/logs/favicon_cache/bg/nginx）
    ├── Nginx 上传临时目录权限修正
    ├── 开发模式标记（NAV_DEV_MODE）
    ├── 无人值守安装准备（ADMIN 环境变量）
    ├── 反代配置文件初始化
    ├── 根据持久化数据预生成反代配置
    └── 删除默认站点配置
    ↓
Supervisord（PID 1，守护进程）
    ├── PHP-FPM（navwww 用户，监听 /run/php-fpm.sock）
    ├── Nginx（navwww 用户，监听 NAV_PORT，默认 58080）
    │     ├── 静态文件直接返回
    │     ├── .php → PHP-FPM（Unix Socket）
    │     ├── /admin/ → auth_request 鉴权 + PHP-FPM
    │     ├── /p/{slug}/ → proxy_pass 内网服务（含完整 proxy 参数 + WebSocket）
    │     └── /auth/verify.php → internal（仅内部调用）
    └── Cron（计划任务调度器）
```

---

## 二、快速部署

### 方式一：手动 Compose（推荐）

```bash
# 1. 创建目录
mkdir -p ~/simple-homepage/data
cd ~/simple-homepage

# 2. 创建 docker-compose.yml（复制根目录模板或 README 中的内容）

# 3. 启动
docker compose up -d

# 4. 查看状态
docker compose ps
docker compose logs -f
```

### 方式二：显式指定 UID/GID

如果自动检测不符合预期：

```bash
PUID=$(id -u) PGID=$(id -g) docker compose up -d
```

### 方式三：纯 docker run（不推荐长期使用）

```bash
docker run -d \
  --name simple-homepage \
  --restart unless-stopped \
  -p 58080:58080 \
  -v $(pwd)/data:/var/www/nav/data \
  -e PUID=$(id -u) \
  -e PGID=$(id -g) \
  -e TZ=Asia/Shanghai \
  codingriver/simple-homepage:latest
```

---

## 三、环境变量配置

| 变量 | 默认值 | 说明 |
|---|---|---|
| `NAV_PORT` | `58080` | 容器内监听端口 |
| `TZ` | `Asia/Shanghai` | 时区 |
| `PUID` | 空 | 可选；显式指定容器内运行用户 UID。留空时自动按 `data` 目录 owner UID 对齐 |
| `PGID` | 空 | 可选；显式指定容器内运行用户 GID。留空时自动按 `data` 目录 owner GID 对齐 |
| `ADMIN` | 空 | 无人值守安装：管理员用户名 |
| `PASSWORD` | 空 | 无人值守安装：管理员密码 |
| `NAME` | `导航中心` | 无人值守安装：站点名称 |
| `DOMAIN` | 空 | 无人值守安装：导航站域名 |
| `NAV_DEV_MODE` | 空 | 开发模式，启用内置测试管理员 `qatest` |

| `AUTH_SECRET_KEY` | 空 | 可选，显式指定认证密钥 |
| `NAV_REQUEST_TIMING` | `1` | 请求耗时日志开关，设为 `0` 关闭 |

> 默认留空即可。容器启动时会自动按宿主机数据目录 owner 对齐 UID/GID；若自动检测到 `0:0`，会回退到镜像默认的 `1000:1000`，避免自动提权。

---

## 四、持久化存储配置

### 4.1 Bind Mount（推荐，默认）

```yaml
volumes:
  - ./data:/var/www/nav/data
```

数据存储在宿主机 `./data/` 目录，直接可见、可备份、可迁移。

### 4.2 Named Volume（适合纯容器环境）

```yaml
services:
  simple-homepage:
    volumes:
      - nav-data:/var/www/nav/data

volumes:
  nav-data:
    driver: local
```

查看数据位置：`docker volume inspect simple-homepage_nav-data`

### 4.3 备份数据

```bash
# 备份
cd ~/simple-homepage
tar -czf nav-backup-$(date +%Y%m%d).tar.gz ./data

# 恢复
tar -xzf nav-backup-20260101.tar.gz
```

### 4.4 完整重置并重新进入安装向导

```bash
docker exec simple-homepage php /var/www/nav/manage_users.php reset
```

`reset` 会同时清空：用户数据、安装锁 `.installed`、登录日志、站点与分组配置、备份文件、反代配置、IP 锁定记录，并自动生成新 `AUTH_SECRET_KEY`。

---

## 五、升级流程

```bash
# 1. 备份数据（重要！）
cd ~/simple-homepage
tar -czf nav-backup-$(date +%Y%m%d).tar.gz ./data

# 2. 拉取最新镜像
docker compose pull

# 3. 重建容器（数据目录不受影响）
docker compose up -d

# 4. 验证
docker compose ps
curl -sf http://localhost:58080/login.php && echo "OK"
```

---

## 六、Nginx 反代功能（Docker 环境）

### 问题
Docker 容器内的 Nginx 无法直接 `sudo nginx -s reload` 宿主机 Nginx。

### 方案A：容器内反代（路径模式 + 子域模式，推荐）

反代目标为宿主机内网其他服务，在后台「系统设置 → Nginx 反代管理」中配置：

- 路径模式配置写入容器内 `/etc/nginx/conf.d/nav-proxy.conf`
- 子域模式配置写入容器内 `/etc/nginx/http.d/nav-proxy-domains.conf`
- 容器内 Nginx reload 自动生效（无需 sudo，PHP-FPM 以 navwww 用户运行，对反代文件有写权限）

```bash
# 验证容器内 Nginx reload 是否正常
docker exec simple-homepage nginx -t
```

### 方案B：挂载宿主机 nginx 配置（高级）

若需要修改宿主机 Nginx 配置：

```yaml
volumes:
  - /etc/nginx/conf.d/nav-proxy.conf:/etc/nginx/conf.d/nav-proxy.conf
```

并在宿主机配置对应 sudoers，容器内通过 `docker exec` 触发宿主机 reload。

---

## 七、反向代理部署（Nginx/Traefik 前置）

### 前置 Nginx 配置

适用场景：宿主机已有 Nginx，需要通过域名访问容器内的导航站。

```nginx
server {
    listen 80;
    server_name nav.yourdomain.com;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl http2;
    server_name nav.yourdomain.com;

    ssl_certificate     /etc/letsencrypt/live/nav.yourdomain.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/nav.yourdomain.com/privkey.pem;

    location / {
        proxy_pass         http://127.0.0.1:58080;
        proxy_set_header   Host $host;
        proxy_set_header   X-Real-IP $remote_addr;
        proxy_set_header   X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header   X-Forwarded-Proto $scheme;
    }
}
```

> ⚠️ 前置 Nginx 反代后，容器内 Cookie `secure=auto` 模式通过 `X-Forwarded-Proto: https` 自动识别 HTTPS，无需额外配置。

---

## 八、常用运维命令

```bash
# 查看运行状态
docker compose ps
docker stats simple-homepage

# 查看日志
docker compose logs -f                    # 容器日志
docker exec simple-homepage tail -f /var/log/nginx/nav.access.log
docker exec simple-homepage tail -f /var/log/php-fpm/error.log

# 进入容器
docker exec -it simple-homepage sh

# 执行 CLI 工具
docker exec simple-homepage php /var/www/nav/manage_users.php list
docker exec simple-homepage php /var/www/nav/manage_users.php reset

# 手动 reload Nginx（容器内）
docker exec simple-homepage nginx -s reload

# 重启服务
docker compose restart

# 停止并删除容器（数据不受影响）
docker compose down

# 停止并删除容器+数据卷（危险！）
docker compose down -v
```

---

## 九、安全配置清单（Docker 环境）

```
□ 安装向导已完成（data/.installed 存在）
□ AUTH_SECRET_KEY 已由安装向导自动生成
□ data/ 目录已挂载到宿主机持久化目录
□ NAV_PORT 未使用敏感端口（避免 22/3306/6379 等）
□ 生产环境建议前置 Nginx + SSL，不直接暴露容器端口
□ .env 文件已加入 .gitignore，不提交到版本控制
□ 定期备份 data/ 目录
□ 容器内 Web / PHP-FPM / Nginx 进程以 navwww 身份运行
□ docker.sock 仅在需要时临时挂载
□ Cookie Secure 模式已按实际访问协议配置
```

---

*文档版本：v2.0 Docker 版 | 适用于当前仓库的 `Dockerfile` / `docker-compose.yml`*
