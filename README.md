# RiverOps（轻量自托管运维控制台）

RiverOps 是一个轻量、自托管的 **运维控制台**，集中管理 DNS / DDNS / 域名有效期 / 计划任务 / 运行环境 / 备份 / 用户 / API Token。
适合个人、家庭网络、NAS、软路由、小型 VPS 使用。

公共入口仅保留安装与登录流程，根路径 `/` 自动跳转至 `/admin/index.php`。

主要能力：

- 🔐 登录保护 + 多用户管理 + IP 锁定
- 🌐 DNS 管理（阿里云 / Cloudflare）
- 🔄 DDNS 动态解析（多 IP 来源 + fallback）
- 📅 域名有效期监控（RDAP 查询 + 本地缓存）
- ⏰ 计划任务（带日志、定时执行、模板）
- 🛠️ 运行配置查看（Nginx / PHP-FPM / PHP 只读查看 + 语法检测）
- 💾 配置备份与恢复 + 自动备份
- 🔑 API Token 管理（外部调用）
- 🔗 Webhook 通知（Telegram / 飞书 / 钉钉 / 自定义）
- 🔧 调试工具（日志、PHP 错误开关、会话管理、审计日志）

项目地址：

- GitHub: <https://github.com/codingriver/riverops>
- Docker Hub: <https://hub.docker.com/r/codingriver/riverops>

镜像支持多架构：

- `linux/amd64`
- `linux/arm64`

---

## 目录

- [适合谁](#适合谁)
- [主要功能](#主要功能)
- [部署前准备](#部署前准备)
- [小白部署流程（推荐）](#小白部署流程推荐)
- [快速上手指南](#快速上手指南)
- [自动创建管理员](#自动创建管理员)
- [升级方法](#升级方法)
- [常用命令](#常用命令)
- [数据保存在哪里](#数据保存在哪里)
- [默认端口修改](#默认端口修改)
- [反向代理配置示例](#反向代理配置示例)
- [常见问题与修复](#常见问题与修复)
- [进阶内容](#进阶内容)

---

## 适合谁

如果你希望把这些东西集中到一个网页后台里，这个项目就适合你：

- 有一台 VPS / NAS / 软路由，想统一管理 DNS / DDNS / Nginx / 计划任务
- 不想装 MySQL、Redis，只想用 Docker 跑起来
- 希望数据都保存在本地目录里，重建容器也不丢
- 需要 API Token 让外部系统调用本机配置接口

---

## 主要功能

### 后台管理

- **用户管理**：管理员/普通用户、密码修改、角色切换
- **DNS 管理**：阿里云 / Cloudflare 解析记录增删改查
- **DDNS 管理**：动态解析任务、自动同步、多 IP 源
- **域名有效期**：自动收集 DNS Zone / DDNS 根域名，也可手动添加，定时刷新注册到期时间
- **计划任务**：脚本编辑、定时执行、运行日志、模板
- **运行配置查看**：Nginx 主配置 / HTTP 模块 / PHP-FPM / PHP 自定义参数只读查看
- **备份与恢复**：手动 / 自动备份、导出导入、保留策略
- **API Token**：发放、撤销、权限范围
- **系统设置**：站点名称、Cookie 策略、Webhook、安全项
- **调试工具**：日志查看、审计日志、会话管理、PHP 错误开关、Cookie 清理

### 运维相关

- DNS 解析管理（阿里云 DNS、Cloudflare）
- DDNS 动态解析，支持多 IP 来源与 fallback
- 域名有效期监控，支持 RDAP 查询、本地缓存与计划任务刷新
- 计划任务管理，支持 Shell / PHP / Python / Node.js / 自定义脚本、立即执行、依赖安装、持续写日志、日志查看
- 运行环境管理，支持后台检测 Node.js/npm、通过 Alpine apk 安装系统版本、安装/切换/卸载 musl 多版本 Node.js，并显示下载进度、安装进度与实时日志
- 数字 ID 计划任务使用独立目录 `data/tasks/task_<id>/`
  - Shell 入口：`run.sh`
  - PHP 入口：`main.php`
  - Python 入口：`main.py`
  - Node.js 入口：`main.mjs`
  - 日志：`run.log`
  - 依赖：Node.js 保留 `node_modules/`，Python 保留 `.venv/`

---

## 部署前准备

你只需要准备：

1. 一台装好 Docker 和 Docker Compose 的 Linux 主机、NAS，或者支持 Docker 的设备
2. 一个准备存放数据的目录
3. 一个浏览器

先确认 Docker 可用：

```bash
docker -v
docker compose version
```

如果这两个命令都能正常输出版本号，就可以继续。

---

## 小白部署流程（推荐）

下面是最简单、最推荐的部署方式：直接从 Docker Hub 拉镜像，用 `docker compose` 启动。

### 1. 创建部署目录

```bash
mkdir -p ~/riverops
cd ~/riverops
mkdir -p data
```

`data` 目录很重要，网站配置、用户、日志、备份都放在这里。**这个目录必须持久化，否则重建容器后数据会丢失。**

### 2. 新建 `docker-compose.yml`

在当前目录创建一个文件：`docker-compose.yml`

内容直接复制下面这一份：

```yaml
# ============================================================
# RiverOps 官方一键部署（推荐新手）
# 启动: docker compose up -d
# 停止: docker compose down
# 日志: docker compose logs -f
# ============================================================

services:
  riverops:
    image: codingriver/riverops:latest
    container_name: riverops
    restart: unless-stopped

    ports:
      - "58080:58080"

    environment:
      # 容器内默认端口（与端口映射保持一致）
      RIVEROPS_PORT: "58080"
      TZ: "Asia/Shanghai"
      # 可选：显式覆盖运行用户 UID/GID；留空时容器启动后自动按 data 目录 owner 对齐
      PUID: "${PUID:-}"
      PGID: "${PGID:-}"

    volumes:
      # 必须挂载：用户、配置、日志、备份都在这里
      - ./data:/var/www/riverops/data
    healthcheck:
      disable: true
```

### 3. 拉取镜像并启动

```bash
docker compose pull
docker compose up -d
```

查看是否启动成功：

```bash
docker ps
```

如果你看到容器名 `riverops` 在运行，就说明启动成功了。

### 4. 打开网页

浏览器访问：

```text
http://你的服务器IP:58080
```

例如：

```text
http://192.168.1.10:58080
```

第一次打开会进入安装向导。

### 5. 完成首次安装

按页面提示设置：

- 管理员用户名（2-32 位，字母数字下划线横杠）
- 管理员密码（至少 8 位）
- 确认密码
- 网站名称
- 网站域名（可以先不填完整正式域名，后面再改）

安装完成后，会跳转到登录页。用刚才设置的账号密码登录即可。

---

## 快速上手指南

部署完成并登录后台后，可以按需配置以下能力。

### 系统设置

进入 **系统设置**，配置站点名称、Cookie 策略、Webhook 等核心选项；保存后立即生效。

### 用户管理

进入 **用户管理**，添加普通用户/管理员，控制角色和密码。建议尽快关闭安装向导默认账号并使用强密码。

### DNS / DDNS

- **DNS 管理**：录入阿里云或 Cloudflare 凭据后，可在后台直接增删改解析记录。
- **DDNS 管理**：创建动态解析任务，自动按周期检测公网 IP 并更新解析；Cloudflare 优选 IP 来源支持 vps789、4ce、wetest、uouin、090227、164746 等多源 fallback。
- **域名有效期**：从 DNS Zone、DDNS 目标域名和手动列表收集域名，查询并缓存注册到期时间。

### 计划任务

进入 **计划任务**：

1. 新建任务，选择 Shell / PHP / Python / Node.js / 自定义脚本
2. 设置 cron 表达式
3. 任务脚本会写入 `data/tasks/task_<id>/`，对应日志为 `data/tasks/task_<id>/run.log`
4. 可勾选执行前安装依赖：Node.js 使用当前任务目录的 `package.json`，Python 使用当前任务目录的 `requirements.txt`
5. 支持手动 **立即执行**，并实时查看输出

### 运行配置查看

进入 **运行配置**：

- 可查看 Nginx 主配置 / HTTP 模块 / PHP-FPM 池 / PHP 自定义 ini
- 页面会展示 `nginx -t` 与 `php-fpm -t` 的当前检测结果
- 后台不支持在线保存或 Reload；修改配置后请重启 Docker 容器生效

### 备份与恢复

进入 **备份**：

1. 打开 **自动备份**，设置保留份数
2. 系统定期在 `data/backups/` 下生成快照
3. 支持手动 **创建**、**下载**、**恢复**、**删除**

### API Token

进入 **API Token 管理**，为外部系统颁发用于调用本机管理接口的 Token；可随时撤销。

---

## 自动创建管理员

如果你不想手动走安装向导，可以在 `docker-compose.yml` 里增加这些环境变量：

```yaml
environment:
  RIVEROPS_PORT: "58080"
  TZ: "Asia/Shanghai"
  PUID: "${PUID:-}"
  PGID: "${PGID:-}"
  ADMIN: "admin"
  PASSWORD: "ChangeMe123!"
  NAME: "RiverOps"
  DOMAIN: "panel.example.com"
```

然后重新启动：

```bash
docker compose up -d
```

首次启动时会自动创建管理员账号，并生成 `.installed` 安装锁。浏览器访问即可直接登录。

> ⚠️ **安全提醒**：生产环境建议部署完成后把明文密码从 compose 文件里删掉。

---

## 升级方法

后续更新很简单：

```bash
cd ~/riverops
docker compose pull
docker compose up -d
```

因为数据挂载在 `./data` 目录，所以更新镜像不会清空你的配置。

> 如果升级后出现异常，可以先查看日志：
> ```bash
> docker compose logs -f
> ```

---

---

## 常用命令

```bash
# 查看日志
docker compose logs -f

# 重启
docker compose restart

# 停止
docker compose down

# 进入容器
docker exec -it riverops sh

# 查看用户列表
docker exec riverops php /var/www/riverops/manage_users.php list

# 修改密码
docker exec riverops php /var/www/riverops/manage_users.php passwd admin 新密码

# 重置安装状态（清空配置，重新进入安装向导）
docker exec riverops php /var/www/riverops/manage_users.php reset
```

---

## 数据保存在哪里

你本机部署目录下的：

```text
./data
```

容器里的对应路径是：

```text
/var/www/riverops/data
```

常见文件说明：

```text
data/
├── .installed              # 安装完成锁
├── auth_secret.key         # 认证密钥（权限 600）
├── config.json             # 系统配置
├── users.json              # 用户数据
├── api_tokens.json         # API Token
├── scheduled_tasks.json    # 计划任务
├── dns_config.json         # DNS 配置
├── ddns_tasks.json         # DDNS 任务
├── domain_expiry.json      # 域名有效期缓存与手动列表
├── domain_expiry_rdap_bootstrap.json # RDAP bootstrap 缓存
├── notifications.json      # Webhook 通知配置
├── ip_locks.json           # IP 登录失败锁定
├── sessions.json           # 会话撤销记录
├── backups/                # 备份快照
├── logs/                   # 各类日志
├── tasks/                  # 计划任务脚本和日志
├── nginx/                  # Nginx 持久化配置（修改后重启容器生效）
├── php-fpm/                # PHP-FPM 池配置落地
├── php/                    # PHP 自定义参数落地
└── trash/                  # 回收站
```

其中计划任务统一使用的工作目录是：

```text
./data/tasks
```

数字 ID 任务会在该目录下生成独立子目录，例如 `task_1/`。后台「运行环境」安装的 Node.js/npm 保存在：

```text
./data/runtime/node
```

---

## 默认端口修改

如果你不想用 `58080`，把 compose 里的端口映射改掉就行：

```yaml
ports:
  - "8080:58080"
```

或同时改容器内端口：

```yaml
ports:
  - "8080:8080"
environment:
  RIVEROPS_PORT: "8080"
```

改完重新执行：

```bash
docker compose up -d
```

然后访问 `http://你的服务器IP:8080`。

---

## 反向代理配置示例

如果你前面还有一层 Nginx/Caddy/Traefik，可以参考以下配置。

### Nginx 前置反代

```nginx
server {
    listen 80;
    server_name riverops.example.com;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl http2;
    server_name riverops.example.com;

    ssl_certificate     /etc/letsencrypt/live/riverops.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/riverops.example.com/privkey.pem;

    location / {
        proxy_pass         http://127.0.0.1:58080;
        proxy_set_header   Host $host;
        proxy_set_header   X-Real-IP $remote_addr;
        proxy_set_header   X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header   X-Forwarded-Proto $scheme;
    }
}
```

> 前置 Nginx 反代后，容器内 Cookie `secure=auto` 模式通过 `X-Forwarded-Proto: https` 自动识别 HTTPS，无需额外配置。

### Caddy

```
riverops.example.com {
    reverse_proxy 127.0.0.1:58080
}
```

### Traefik

使用 Docker 标签即可，无需额外写配置文件。

---

## 常见问题与修复

### Q1: 打不开页面

**排查步骤：**

1. 确认容器在运行：
   ```bash
   docker ps | grep riverops
   ```

2. 查看日志：
   ```bash
   docker compose logs -f
   ```

3. 确认服务器防火墙或路由器没有拦截映射出来的端口：
   ```bash
   # Linux 检查防火墙
   sudo ufw status
   sudo iptables -L -n | grep 58080
   ```

4. 如果是云服务器，检查安全组/网络 ACL 是否放行了对应端口。

5. 本地测试容器内服务是否正常：
   ```bash
   docker exec riverops curl -s http://localhost:58080/login.php
   ```

---

### Q2: 重建容器后数据没了

**原因：** 通常是因为没有挂载 `./data:/var/www/riverops/data`，或者挂载路径写错了。

**修复：** 检查 `docker-compose.yml` 中的 `volumes` 部分，确保有：

```yaml
volumes:
  - ./data:/var/www/riverops/data
```

如果数据已经丢失且没有备份，无法恢复。**请务必定期备份 `data` 目录。**

---

### Q3: 没有权限写入 `data`

**现象：** 页面提示"无法保存"、日志为空、上传失败。

**排查：**

```bash
ls -la ./data
```

**修复方法 1：** 确保目录存在：
```bash
mkdir -p data
```

**修复方法 2：** 在 Linux bind mount 场景下，显式指定 PUID/PGID：
```bash
export PUID=$(id -u)
export PGID=$(id -g)
docker compose up -d
```

**修复方法 3：** 如果目录已创建但权限不对，修正宿主目录权限：
```bash
sudo chown -R $(id -u):$(id -g) ./data
```

---

### Q4: 登录后立即跳回登录页（Cookie 无效）

**常见原因 1：** 后台设置了 `Cookie Secure 模式 = on`，但你在用 HTTP 访问。

**修复：** 进入后台 → 系统设置 → Cookie Secure 模式，改为 `off` 或 `auto`。

**常见原因 2：** Cookie Domain 填了域名，但你在用 IP 访问。

**修复：** 清空 Cookie Domain，或改用域名访问。

**常见原因 3：** 容器时间不对导致 Token 提前过期。

**修复：** 确保 `TZ` 环境变量正确，或同步宿主机时间。

> 代码已内置自动降级：用 IP 访问时会自动设置 `secure=false, domain=空`，保证内网 IP 访问始终可登录。

---

### Q5: 忘记管理员密码 / 无法登录

**方法 1：** 通过命令行修改密码（不需要知道原密码）：
```bash
docker exec riverops php /var/www/riverops/manage_users.php passwd admin 新密码
```

**方法 2：** 如果不知道用户名，先查看用户列表：
```bash
docker exec riverops php /var/www/riverops/manage_users.php list
```

**方法 3：** 如果完全无法恢复，可以重置整个系统（会清空所有配置，但备份文件保留）：
```bash
docker exec riverops php /var/www/riverops/manage_users.php reset
```
执行后刷新浏览器，会重新进入安装向导。

---

### Q6: 端口 58080 被占用

**现象：** `docker compose up -d` 后容器启动失败，日志显示 `bind: address already in use`。

**修复：** 修改 `docker-compose.yml` 中的端口映射，换一个未被占用的端口：
```yaml
ports:
  - "58081:58080"
```
然后访问 `http://IP:58081`。

---

### Q7: 升级后页面空白或 502

**排查：**

1. 查看日志：
   ```bash
   docker compose logs -f
   ```

2. 可能是 Nginx 配置格式问题。进入容器检查：
   ```bash
   docker exec riverops nginx -t
   ```

3. 如果是持久化配置损坏，请修改 `data/nginx`、`data/php-fpm` 或 `data/php` 下的配置文件，然后重启 Docker 容器。

4. 极端情况下可以重置：
   ```bash
   docker exec riverops php /var/www/riverops/manage_users.php reset
   ```

---

### Q9: 从 IP 访问迁移到域名 + HTTPS

**步骤：**

1. 配置好域名解析和前置 Nginx + SSL 证书
2. 用域名访问后台，登录
3. 进入 **系统设置**：
   - `Cookie Secure 模式` 改为 `auto`（推荐）或 `on`
   - `Cookie Domain` 填写 `.yourdomain.com`（前面带点，用于跨子域 SSO）
4. 退出并重新登录
5. 测试子站 SSO（如有）

---

### Q10: 如何备份和迁移数据

**备份：**

```bash
cd ~/riverops
tar -czf riverops-backup-$(date +%Y%m%d).tar.gz ./data
```

**迁移到新机器：**

1. 在新机器上按小白部署流程创建目录和 `docker-compose.yml`
2. 复制备份包到新机器的 `~/riverops/` 目录
3. 解压：
   ```bash
   tar -xzf riverops-backup-20260101.tar.gz
   ```
4. 启动容器：
   ```bash
   docker compose up -d
   ```

> 无需复制任何程序文件，因为程序都在 Docker 镜像里。

---

### Q11: 计划任务不执行

**排查：**

1. 确认任务状态是"启用"
2. 查看任务日志：`data/tasks/任务名.log`
3. 确认容器内 crond 在运行：
   ```bash
   docker exec riverops ps aux | grep cron
   ```
4. 检查任务命令是否正确，可以在容器内手动测试：
   ```bash
   docker exec riverops sh /var/www/riverops/data/tasks/你的任务.sh
   ```

---

## 进阶内容

根目录这个 README 只保留新手部署和使用说明。

这些进阶内容已经整理到 [local/README.md](local/README.md) 和 `docs/` 目录：

- 本地开发环境搭建
- 测试命令（Playwright E2E / PHPUnit / Lighthouse）
- 高级环境变量说明
- 数据目录详细说明
- CLI 管理命令
- 多个 compose 组合方式
- 技术架构与实现原理

如果你只是想把项目跑起来，用这个 README 就够了。
