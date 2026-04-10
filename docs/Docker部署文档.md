# 导航网站 Docker 部署文档

> 适用：任何支持 Docker 的 Linux / macOS / Windows 环境。
> 镜像内置 Nginx + PHP 8.2-fpm + Supervisor，**宿主机无需安装任何 PHP 或 Nginx**。
> 镜像标签统一使用 `latest`，示例镜像名为 `codingriver/simple-homepage:latest`。

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
# 或旧版：
docker-compose --version  # docker-compose version 1.29.x
```

### 0.2 宿主机硬件要求

| 资源 | 最低 | 推荐 | 说明 |
|---|---|---|---|
| CPU | 1 核 | 2 核 | PHP-FPM 进程池默认 4 个工作进程 |
| 内存 | 128MB | 256MB+ | Debian 单容器方案，兼容性优先 |
| 磁盘 | 500MB | 1GB+ | 镜像约 180MB + 数据目录 |
| 端口 | 任意空闲端口 | 8080（默认）| 可通过 `NAV_PORT` 修改 |

### 0.3 操作系统支持

| 系统 | 支持情况 | 备注 |
|---|---|---|
| Ubuntu 20.04+ / Debian 11+ | ✅ 推荐 | 最佳兼容性 |
| CentOS 7/8 / RHEL | ✅ 支持 | 需手动安装 Docker |
| Alpine Linux | ⚠️ 不再作为默认镜像基础 | 如需极小镜像需自行维护兼容性 |
| macOS（Apple Silicon / Intel）| ✅ 支持 | 需安装 Docker Desktop |
| Windows 10/11（WSL2）| ✅ 支持 | 需安装 Docker Desktop + WSL2 |
| 宝塔面板服务器 | ✅ 支持 | 宝塔软件商店可直接安装 Docker |

### 0.4 需要提前配置的内容

> 以下是部署前需要确认/配置的清单，**缺少任何一项都可能导致部署失败**。

```
必须（部署前）：
□ 宿主机已安装 Docker Engine 20.10+
□ 宿主机已安装 Docker Compose v2.0+
□ 目标端口（默认 8080）未被占用
□ 项目压缩包已解压到服务器

可选（部署前）：
□ 准备数据持久化目录路径（默认 ./nav-data，自动创建）
□ 确认时区设置（默认 Asia/Shanghai）
□ 如需域名访问：域名已解析到服务器 IP
□ 如需 HTTPS：准备 SSL 证书或配置前置 Nginx

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
| **基础系统** | Debian Bookworm | 12（随 php:8.2-fpm-bookworm）| Docker 官方镜像 | 兼容第三方 Linux 二进制更好 |
| **运行时** | PHP | 8.2-fpm | `php:8.2-fpm-bookworm` | 可替换为 8.1/8.0/7.4/7.3 |
| **Web 服务器** | Nginx | Debian 仓库稳定版 | `apt-get install nginx` | 处理静态文件和 PHP 转发 |
| **进程管理** | Supervisor | Debian 仓库稳定版 | `apt-get install supervisor` | 管理 Nginx + PHP-FPM 生命周期 |
| **PHP 扩展** | `fileinfo` | 随 PHP 编译 | `docker-php-ext-install` | 背景图 MIME 检测（可选增强）|
| **PHP 扩展** | `session` | PHP 核心内置 | 内置 | Cookie/Session 管理 |
| **PHP 扩展** | `json` | PHP 7.1+ 永久内置 | 内置 | 数据文件读写 |
| **PHP 扩展** | `hash` | PHP 7.4+ 永久内置 | 内置 | Token 签名和比对 |
| **PHP 扩展** | `pcre` | PHP 4+ 内置 | 内置 | 正则校验 |
| **工具** | curl | Debian 仓库 | `apt-get install curl` | 健康检查探针 |
| **工具** | tzdata | Debian 仓库 | `apt-get install tzdata` | 时区数据库 |
| **工具** | bash | Debian 仓库 | `apt-get install bash` | 启动脚本依赖 |

### 1.2 内置配置文件

| 配置文件（容器内路径）| 来源 | 作用 |
|---|---|---|
| `/etc/nginx/nginx.conf` | `docker/nginx.conf` | Nginx 主配置（worker、日志、MIME、**全局 proxy 参数**）|
| `/etc/nginx/http.d/nav.conf` | `docker/nginx-site.conf` | 站点配置（auth_request、PHP 转发、安全规则、**反代 location 内置完整参数**）|
| `/etc/nginx/conf.d/nav-proxy.conf` | 容器启动时自动创建（空文件）| 反代配置（由后台自动管理，include 到 server 块内）|
| `/usr/local/etc/php-fpm.d/nav.conf` | `docker/php-fpm.conf` | PHP-FPM 进程池配置 |
| `/etc/supervisord.conf` | `docker/supervisord.conf` | Supervisor 进程管理配置 |
| `/entrypoint.sh` | `docker/entrypoint.sh` | 容器启动入口脚本 |

### 1.3 PHP-FPM 进程池配置（默认值）

| 参数 | 默认值 | 说明 |
|---|---|---|
| 运行用户 | `navwww`（uid 1000）| 非 root，安全隔离 |
| Socket | `/run/php-fpm.sock` | Nginx 与 PHP-FPM 通信 |
| 进程模式 | `dynamic` | 动态伸缩 |
| 最大子进程 | 20 | 并发上限 |
| 初始进程数 | 4 | 启动时创建 |
| `upload_max_filesize` | 32MB | 背景图上传限制 |
| `post_max_size` | 32MB | POST 数据上限 |
| `memory_limit` | 128MB | 单个 PHP 进程内存上限 |
| `max_execution_time` | 30s | 单次请求超时 |
| `session.save_path` | `/tmp` | Session 存储路径 |

### 1.3-bis Nginx 内置 Proxy 参数说明

`nginx.conf` 在 `http {}` 块内统一设置全局反代参数，`nginx-site.conf` 的每个 proxy `location` 块自动继承，无需重复配置：

| 参数 | 值 | 说明 |
|---|---|---|
| `proxy_connect_timeout` | 10s | 与后端建立连接超时 |
| `proxy_send_timeout` | 60s | 向后端发送请求超时 |
| `proxy_read_timeout` | 60s | 从后端读取响应超时 |
| `proxy_buffering` | on | 启用缓冲，减少后端等待 |
| `proxy_buffer_size` | 8k | 响应头缓冲区大小 |
| `proxy_buffers` | 8 × 16k | 响应体缓冲区 |
| `proxy_busy_buffers_size` | 32k | 忙碌时可用缓冲 |
| `proxy_temp_path` | `/var/lib/nginx/tmp/proxy` | 大响应临时文件路径 |
| `client_body_temp_path` | `/var/lib/nginx/tmp/client_body` | 上传文件临时路径 |
| WebSocket | `Upgrade` / `Connection` | 通过 `$connection_upgrade` map 自动支持 |

> ⚠️ **权限说明**：`/var/lib/nginx/tmp/` 在 Dockerfile 构建时已 `chown -R navwww:navwww`，`entrypoint.sh` 启动时再次确认权限。若出现文件上传 500 错误，请检查该目录归属是否为 `navwww`。

### 1.4 目录结构（容器内）

```
/var/www/nav/                ← 项目根目录
├── public/                  ← Nginx Web 根目录
├── admin/                   ← 后台管理
├── shared/                  ← 核心认证库
├── data/                    ← 挂载点（持久化到宿主机）
│   ├── users.json
│   ├── config.json
│   ├── sites.json
│   ├── .installed
│   ├── backups/
│   ├── logs/
│   ├── favicon_cache/
│   └── bg/
└── nginx-conf/              ← Nginx 配置模板（供参考）

/etc/nginx/                  ← Nginx 配置
/var/log/nginx/              ← Nginx 日志
/var/log/php-fpm/            ← PHP-FPM 日志
/run/php-fpm.sock            ← PHP-FPM Unix Socket
/run/nginx/                  ← Nginx PID 文件
```

### 1.5 容器启动流程

```
docker compose up
    ↓
entrypoint.sh
    ├── 环境变量注入（NAV_PORT → Nginx 配置）
    ├── 时区设置（TZ 环境变量）
    ├── 数据子目录创建（backups/logs/favicon_cache/bg）
    ├── 权限修正（data/ → navwww:navwww）
    ├── Nginx 上传临时目录权限修正（/var/lib/nginx/tmp/ → navwww）  ← v2.3 新增
    └── 确保 nav-proxy.conf 存在
    ↓
Supervisord（PID 1，守护进程）
    ├── PHP-FPM（navwww 用户，监听 /run/php-fpm.sock）
    └── Nginx（navwww 用户，监听 NAV_PORT，默认 80）
            ├── 静态文件直接返回
            ├── .php → PHP-FPM（Unix Socket）
            ├── /admin/ → auth_request 鉴权 + PHP-FPM
            ├── /p/{slug}/ → proxy_pass 内网服务（含完整 proxy 参数 + WebSocket）
            └── /auth/verify.php → internal（仅内部调用）
```

---

## 二、方案架构深度分析

### 1.1 镜像设计选型

| 方案 | 基础镜像 | 体积 | 说明 |
|---|---|---|---|
| **当前采用（推荐）** | `php:8.2-fpm-bookworm` + Nginx | ~180MB | 单容器，Debian 兼容性优先，Supervisor 管理进程 |
| 方案B | `php:8.2-fpm-alpine` + Nginx | ~80MB | 镜像更小，但第三方二进制兼容性更差 |
| 方案C | 双容器（nginx + php-fpm） | ~120MB | 标准分离架构，复杂度高 |
| 方案D | `webdevops/php-nginx:8.2-alpine` | ~120MB | 第三方预制镜像，依赖外部维护 |

**当前选择单容器 Debian 方案的理由：**
- 导航站属于轻量级应用，无需微服务分离
- 对常见第三方 Linux 二进制兼容性更好
- Supervisor 统一管理 Nginx + PHP-FPM，进程崩溃自动重启
- 一个 `docker run` / `docker compose up` 搞定，运维简单

### 1.2 进程管理架构

```
容器启动
    ↓
entrypoint.sh（环境变量注入、目录初始化、权限修正）
    ↓
Supervisord（PID 1）
    ├── Nginx（监听 80 端口）
    │     └── 处理静态文件、PHP fastcgi 转发、auth_request
    └── PHP-FPM（unix socket: /run/php-fpm.sock）
          └── 处理 PHP 请求
```

### 1.3 持久化存储设计

```
宿主机 ./nav-data/          ←→   容器 /var/www/nav/data/
├── users.json                        用户账户
├── config.json                       系统配置
├── sites.json                        站点导航数据
├── ip_locks.json                     IP 锁定记录
├── .installed                        安装锁
├── backups/                          备份文件
├── logs/                             认证日志
├── favicon_cache/                    Favicon 缓存
└── bg/                               背景图
```

**只有 `data/` 需要持久化**，程序代码打入镜像，升级时重建镜像即可，数据不受影响。

### 1.4 网络与端口设计

```
宿主机:NAV_PORT（默认 8080）
    ↓ Docker 端口映射
容器:80（Nginx 监听）
    ↓ Unix Socket
PHP-FPM:/run/php-fpm.sock
```

默认使用 `8080` 端口（避免与宿主机现有 Nginx 冲突），可通过 `.env` 修改。

---

## 二、快速部署（推荐）

### 方式一：一键脚本（交互式，推荐）

```bash
# 解压项目包
unzip nav-portal.zip
cd nav-portal/nav-portal

# 运行一键部署脚本（自动检测 docker compose / docker-compose）
bash docker/docker-deploy.sh
```

脚本会交互式询问：
- **访问端口**（默认 8080）
- **数据持久化目录**（默认 `./nav-data`）
- **容器名称**（默认 `nav-portal`）
- **时区**（默认 `Asia/Shanghai`）

完成后自动构建镜像、启动容器，输出访问地址。

### 方式二：手动 Compose

```bash
# 1. 复制并编辑配置
cp .env.example .env
nano .env          # 按需修改端口等

# 2. 构建并启动（默认自动按数据目录 owner 对齐 UID/GID）
docker compose up -d --build
# 旧版独立安装命令：
# docker-compose up -d --build

# 2'. 如需显式覆盖自动检测结果
PUID=$(id -u) PGID=$(id -g) docker compose up -d --build
# 旧版独立安装命令：
# PUID=$(id -u) PGID=$(id -g) docker-compose up -d --build

# 3. 查看状态
docker compose ps
docker logs -f nav-portal
```

> **如何判断用哪个命令：**
> ```bash
> docker compose version    # 有输出 → 用 docker compose
> docker-compose version    # 有输出 → 用 docker-compose
> ```
> 两者功能完全相同，区别仅在于安装方式。`docker-compose`（连字符）是独立二进制，`docker compose`（空格）是 Docker CLI 插件。

### 方式三：纯 docker run（不推荐长期使用）

```bash
# 构建镜像
docker build -t codingriver/simple-homepage:latest .

# 运行容器
docker run -d \
  --name nav-portal \
  --restart unless-stopped \
  -p 8080:80 \
  -v $(pwd)/nav-data:/var/www/nav/data \
  -e PUID=$(id -u) \
  -e PGID=$(id -g) \
  -e TZ=Asia/Shanghai \
  codingriver/simple-homepage:latest
```

---

## 三、环境变量配置

| 变量 | 默认值 | 说明 |
|---|---|---|
| `NAV_PORT` | `8080` | 宿主机访问端口（映射到容器 80）|
| `CONTAINER_NAME` | `nav-portal` | 容器名称 |
| `DATA_DIR` | `./nav-data` | 宿主机数据持久化目录 |
| `TZ` | `Asia/Shanghai` | 时区 |
| `PUID` | 空 | 可选；显式指定容器内运行用户 UID。留空时容器启动后自动按 `nav-data` 目录 owner UID 对齐 |
| `PGID` | 空 | 可选；显式指定容器内运行用户 GID。留空时容器启动后自动按 `nav-data` 目录 owner GID 对齐 |

完整 `.env` 示例：

```env
CONTAINER_NAME=nav-portal
NAV_PORT=8080
TZ=Asia/Shanghai
DATA_DIR=./nav-data
```

> 默认留空即可。容器启动时会自动按宿主机数据目录 owner 对齐 UID/GID；若自动检测到 `0:0`，会回退到镜像默认的 `1000:1000`，避免自动提权。仅在需要覆盖自动检测结果时，再显式设置 `PUID` / `PGID`。

---

## 四、持久化存储配置

### 4.1 Bind Mount（推荐，默认）

```yaml
volumes:
  - ./nav-data:/var/www/nav/data
```

数据存储在宿主机 `./nav-data/` 目录，直接可见、可备份、可迁移。

### 4.4 完整重置并重新进入安装向导

如需把当前实例恢复到“未安装”状态，可执行：

```bash
docker exec nav-portal php /var/www/nav/manage_users.php reset
```

`reset` 会同时清空：
- 用户数据
- 安装锁 `.installed`
- 登录日志 `data/logs/auth.log`
- 站点与分组配置
- 备份文件
- 反代配置
- IP 锁定记录

并自动重置 Cookie 相关配置、刷新 `AUTH_SECRET_KEY`、检查并创建 `proxy_params_full`。

### 4.2 Named Volume（适合纯容器环境）

```yaml
services:
  nav:
    volumes:
      - nav-data:/var/www/nav/data

volumes:
  nav-data:
    driver: local
```

查看数据位置：`docker volume inspect nav_nav-data`

### 4.3 备份数据

```bash
# 备份
tar -czf nav-backup-$(date +%Y%m%d).tar.gz ./nav-data

# 恢复
tar -xzf nav-backup-20260101.tar.gz
```

---

## 五、升级流程

```bash
# 1. 备份数据（重要！）
tar -czf nav-backup-$(date +%Y%m%d).tar.gz ./nav-data

# 2. 拉取新版代码 / 解压新版压缩包

# 3. 重建镜像（数据目录不受影响）
docker compose down
docker compose up -d --build

# 4. 验证
docker compose ps
curl -sf http://localhost:8080/login.php && echo "OK"
```

---

## 六、Nginx 反代功能（Docker 环境）

### 问题
Docker 容器内的 Nginx 无法直接 `sudo nginx -s reload` 宿主机 Nginx。

### 方案A：容器内反代（路径模式，推荐）

反代目标为宿主机内网其他服务，在后台「Nginx 反代管理」中配置，配置文件写入容器内 `/etc/nginx/conf.d/nav-proxy.conf`，容器内 Nginx reload 自动生效（无需 sudo，PHP-FPM 以 navwww 用户运行，对该文件有写权限）。

```bash
# 验证容器内 Nginx reload 是否正常
docker exec nav-portal nginx -s reload
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
        proxy_pass         http://127.0.0.1:8080;  # Docker 容器端口
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
docker stats nav-portal

# 查看日志
docker logs -f nav-portal                        # 容器日志
docker exec nav-portal tail -f /var/log/nginx/nav.access.log
docker exec nav-portal tail -f /var/log/php-fpm/error.log

# 进入容器
docker exec -it nav-portal sh

# 执行 CLI 工具
docker exec nav-portal php /var/www/nav/manage_users.php list
docker exec nav-portal php /var/www/nav/manage_users.php reset

# 手动 reload Nginx（容器内）
docker exec nav-portal nginx -s reload

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
□ 定期备份 nav-data/ 目录
□ 容器内 Web / PHP-FPM 进程以 `navwww` 身份运行
```

---

## 十、目录结构（部署后）

```
nav-portal/                  ← 项目根目录
├── Dockerfile               ← 镜像构建文件
├── docker-compose.yml       ← Compose 编排配置
├── .env.example             ← 环境变量模板
├── .env                     ← 实际配置（gitignore）
├── .dockerignore            ← 构建排除规则
├── deploy.sh                ← 纯 Nginx 一键部署脚本
├── pack.sh                  ← 项目打包脚本
├── docker/
│   ├── docker-deploy.sh     ← Docker 一键部署脚本
│   ├── nginx.conf           ← 容器内 Nginx 主配置
│   ├── nginx-site.conf      ← 容器内站点配置
│   ├── php-fpm.conf         ← PHP-FPM 进程池配置
│   ├── supervisord.conf     ← Supervisor 进程管理
│   └── entrypoint.sh        ← 容器启动入口脚本
├── public/                  ← Web 根目录
├── admin/                   ← 后台管理
├── shared/                  ← 核心认证库
├── data/                    ← 运行时数据（挂载到宿主机）
└── nginx-conf/              ← Nginx 配置模板参考
```

宿主机部署后额外生成：

```
nav-data/                    ← 持久化数据目录（DATA_DIR）
├── users.json
├── config.json
├── sites.json
├── .installed
├── backups/
├── logs/
├── favicon_cache/
└── bg/
```

---

*文档版本：v1.1 Docker 版 | 适用于当前仓库的 `Dockerfile` / `docker-compose.yml`（镜像标签使用 `latest`）*                
