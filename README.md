# Simple Homepage

一个适合个人、家庭网络、NAS、软路由、小型 VPS 使用的私有导航首页。

它不只是书签页，还集成了这些能力：

- 🔐 登录保护 + 多用户管理
- 📁 分组和站点管理
- 🔄 反向代理入口（Nginx 自动生成）
- 🌐 DNS 管理（阿里云 / Cloudflare）
- 🔄 DDNS 动态解析
- ⏰ 计划任务（带日志、定时执行）
- 💾 配置备份与恢复
- 🔧 调试工具（日志、PHP 错误开关）
- 🖥️ Host-Agent 一键安装入口
- 📂 文件系统管理
- 🐳 Docker 宿主管理
- 🌐 WebDAV 服务端
- 📢 Webhook 通知

项目地址：

- GitHub: <https://github.com/codingriver/simple-homepage>
- Docker Hub: <https://hub.docker.com/r/codingriver/simple-homepage>

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

- 家里有 NAS、路由器、下载器、影视服务、面板，想做统一入口
- 有一台 VPS，想把常用站点、反代入口、DDNS、DNS 管理放到一起
- 不想装 MySQL、Redis，只想用 Docker 跑起来
- 希望数据都保存在本地目录里，重建容器也不丢

---

## 主要功能

### 前台导航

- 分组展示站点卡片
- 搜索站点（支持 `/` 快捷键聚焦）
- 支持公开分组和登录后可见分组
- 支持背景图、背景色、卡片大小/布局/方向等设置
- 自动抓取 favicon
- 适配手机和桌面浏览器
- 站点健康状态指示（up/down/unknown）

### 后台管理

- **站点管理**：普通链接、内部直达、反向代理
- **分组管理**：增删改查、排序、权限范围、图标
- **用户管理**：管理员和普通用户、密码修改、角色切换
- **系统设置**：站点名称、域名、Cookie 策略、安全项、Webhook
- **Nginx 反代**：后台一键生成配置并 Reload
- **备份恢复**：导出、导入、手动备份、自动备份、恢复
- **调试工具**：日志查看、Cookie 清理、PHP 错误显示切换
- **文件系统**：本机 + 远程主机文件浏览、编辑、上传、下载、压缩解压
- **Docker 管理**：容器、镜像、卷、网络查看与操作
- **WebDAV**：Basic Auth + 配额 + 审计
- **通知中心**：Telegram、飞书、钉钉、企业微信、自定义 Webhook

### 运维相关

- DNS 解析管理（阿里云 DNS、Cloudflare）
- DDNS 动态解析，支持多 IP 来源与 fallback
- 计划任务管理，支持立即执行、持续写日志、日志查看
- 计划任务统一工作目录固定为 `data/tasks`
- 每个计划任务的脚本会落地保存为 `data/tasks/<脚本文件名>.sh`
- 计划任务脚本和运行日志都落地在 `data/tasks/`
  - 脚本文件为 `xxx.sh`
  - 对应日志文件为同名 `xxx.log`

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
mkdir -p ~/simple-homepage
cd ~/simple-homepage
mkdir -p data
```

`data` 目录很重要，网站配置、用户、日志、备份都放在这里。**这个目录必须持久化，否则重建容器后数据会丢失。**

### 2. 新建 `docker-compose.yml`

在当前目录创建一个文件：`docker-compose.yml`

内容直接复制下面这一份：

```yaml
# ============================================================
# Simple Homepage 官方一键部署（推荐新手）
# 启动: docker compose up -d
# 停止: docker compose down
# 日志: docker compose logs -f
# ============================================================

services:
  simple-homepage:
    image: codingriver/simple-homepage:latest
    container_name: simple-homepage
    restart: unless-stopped

    ports:
      - "58080:58080"

    environment:
      # 容器内默认端口（与端口映射保持一致）
      NAV_PORT: "58080"
      TZ: "Asia/Shanghai"
      # 可选：显式覆盖运行用户 UID/GID；留空时容器启动后自动按 data 目录 owner 对齐
      PUID: "${PUID:-}"
      PGID: "${PGID:-}"

    volumes:
      # 必须挂载：用户、配置、日志、备份都在这里
      - ./data:/var/www/nav/data
      # - /var/run/docker.sock:/var/run/docker.sock

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

如果你看到容器名 `simple-homepage` 在运行，就说明启动成功了。

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

部署完成并登录后台后，建议按下面的顺序走一遍，5 分钟后你就能拥有一个完整的导航首页。

### 第一步：创建一个分组

分组就是首页上的卡片区域，比如"常用工具"、"媒体服务"、"开发环境"。

1. 登录后台，点击左侧菜单 **分组管理**
2. 点击右上角 **新建分组**
3. 填写：
   - **分组名称**：如 `常用工具`
   - **图标**：可以填 Emoji（如 🔧）或图标 URL
   - **排序**：数字越小越靠前
   - **可见范围**：选`公开`（所有人可见）或`登录后可见`
4. 点击 **保存**

> 💡 建议先创建 2~3 个分组，比如 `常用工具`、`家庭影音`、`服务器管理`。

### 第二步：添加站点

站点就是首页上的每个卡片，点击后跳转。

1. 点击左侧菜单 **站点管理**
2. 点击 **新建站点**
3. 填写：
   - **站点名称**：如 `百度`
   - **站点 URL**：`https://www.baidu.com`
   - **所属分组**：选择刚才创建的 `常用工具`
   - **站点类型**：
     - `外部链接`：直接跳转到目标网址（最常用）
     - `内部直达`：跳转时自动带登录 Token（适合同域名下的子系统）
     - `反向代理`：通过本站的 Nginx 反代访问（适合内网服务）
4. 点击 **保存**

返回首页刷新，你就会看到新添加的卡片了。

### 第三步：把内网服务做成反向代理（进阶但非常有用）

如果你家里有 NAS、路由器管理页、下载器等内网服务，可以通过本站直接反代出去，实现"一个域名访问所有服务"。

以反代 `http://192.168.1.10:8096`（Emby）为例：

1. **站点管理** → **新建站点**
2. 填写：
   - 名称：`Emby`
   - 分组：`家庭影音`
   - 类型：`反向代理`
   - 代理目标：`http://192.168.1.10:8096`
   - 代理模式：
     - `路径模式`：通过 `http://你的IP:58080/p/emby/` 访问
     - `子域模式`：通过 `https://emby.yourdomain.com` 访问（需要配置域名）
3. 保存后回到首页，会看到卡片上显示 **代理未生效**
4. 进入 **系统设置 → Nginx 反代管理**
5. 点击 **生成配置并 Reload**
6. 成功后返回首页，卡片显示为正常状态，点击即可访问

> ⚠️ 反向代理目标地址不能是内网回环地址（如 `127.0.0.1`），必须是容器或 Nginx 能直接访问到的地址。

### 第四步：设置自动备份

所有数据都保存在 `data/` 目录，虽然容器重建不会丢，但硬盘损坏会丢。建议开启自动备份：

1. 进入 **系统设置 → 备份与恢复**
2. 打开 **自动备份** 开关
3. 设置保留数量（如保留最近 10 份）
4. 保存

系统会定期自动在 `data/backups/` 下生成备份文件。你可以随时下载或恢复。

### 第五步：个性化首页

1. 进入 **系统设置**
2. 可以修改：
   - **网站名称**：显示在浏览器标题和登录页
   - **背景图/背景色**：让首页更美观
   - **卡片样式**：大小、圆角、布局方向
   - **搜索框**：是否显示、默认搜索引擎
3. 保存后刷新首页即可看到效果

### 其他常用功能速览

| 功能 | 入口 | 一句话说明 |
|------|------|-----------|
| 添加其他用户 | 用户管理 | 给家人/同事开子账号，控制可见分组 |
| 健康检查 | 健康检测 | 自动定时 ping 所有站点，首页显示绿/红点 |
| DDNS | DDNS 管理 | 公网 IP 变动时自动更新域名解析 |
| DNS | DNS 管理 | 管理阿里云/Cloudflare 的解析记录 |
| 计划任务 | 计划任务 | 定时执行脚本，如自动清理、签到 |
| 文件管理 | 文件系统 | 浏览器里直接浏览/编辑/上传宿主机文件 |
| Docker 管理 | Docker 宿主 | 查看和管理本机容器、镜像、卷 |
| 通知 | 通知中心 | 登录、任务失败时推送到微信/钉钉/TG |

---

## 自动创建管理员

如果你不想手动走安装向导，可以在 `docker-compose.yml` 里增加这些环境变量：

```yaml
environment:
  NAV_PORT: "58080"
  TZ: "Asia/Shanghai"
  PUID: "${PUID:-}"
  PGID: "${PGID:-}"
  ADMIN: "admin"
  PASSWORD: "ChangeMe123!"
  NAME: "我的导航"
  DOMAIN: "nav.example.com"
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
cd ~/simple-homepage
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
docker exec -it simple-homepage sh

# 查看用户列表
docker exec simple-homepage php /var/www/nav/manage_users.php list

# 修改密码
docker exec simple-homepage php /var/www/nav/manage_users.php passwd admin 新密码

# 重置安装状态（清空配置，重新进入安装向导）
docker exec simple-homepage php /var/www/nav/manage_users.php reset
```

---

## 数据保存在哪里

你本机部署目录下的：

```text
./data
```

容器里的对应路径是：

```text
/var/www/nav/data
```

常见文件说明：

```text
data/
├── .installed              # 安装完成锁
├── auth_secret.key         # 认证密钥（权限 600）
├── config.json             # 系统配置
├── sites.json              # 站点与分组
├── users.json              # 用户数据
├── scheduled_tasks.json    # 计划任务
├── dns_config.json         # DNS 配置
├── ddns_tasks.json         # DDNS 任务
├── notifications.json      # 通知渠道
├── ip_locks.json           # IP 登录失败锁定
├── sessions.json           # 会话撤销记录
├── backups/                # 备份快照
├── logs/                   # 各类日志
├── tasks/                  # 计划任务脚本和日志
├── favicon_cache/          # 自动抓取的 favicon 缓存
├── bg/                     # 背景图上传目录
└── nginx/                  # Nginx 代理参数模板
```

其中计划任务统一使用的工作目录是：

```text
./data/tasks
```

所有计划任务共享这个目录。

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
  NAV_PORT: "8080"
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

> 前置 Nginx 反代后，容器内 Cookie `secure=auto` 模式通过 `X-Forwarded-Proto: https` 自动识别 HTTPS，无需额外配置。

### Caddy

```
nav.yourdomain.com {
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
   docker ps | grep simple-homepage
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
   docker exec simple-homepage curl -s http://localhost:58080/login.php
   ```

---

### Q2: 重建容器后数据没了

**原因：** 通常是因为没有挂载 `./data:/var/www/nav/data`，或者挂载路径写错了。

**修复：** 检查 `docker-compose.yml` 中的 `volumes` 部分，确保有：

```yaml
volumes:
  - ./data:/var/www/nav/data
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
docker exec simple-homepage php /var/www/nav/manage_users.php passwd admin 新密码
```

**方法 2：** 如果不知道用户名，先查看用户列表：
```bash
docker exec simple-homepage php /var/www/nav/manage_users.php list
```

**方法 3：** 如果完全无法恢复，可以重置整个系统（会清空所有配置，但备份文件保留）：
```bash
docker exec simple-homepage php /var/www/nav/manage_users.php reset
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
   docker exec simple-homepage nginx -t
   ```

3. 如果是反代配置损坏，可以尝试从后台重新生成：
   - 登录后台 → 系统设置 → Nginx 反代管理 → 生成配置并 Reload

4. 极端情况下可以重置：
   ```bash
   docker exec simple-homepage php /var/www/nav/manage_users.php reset
   ```

---

### Q8: Host-Agent 一键安装失败

**排查：**

1. 确认 `docker-compose.yml` 中挂载了 `docker.sock`：
   ```yaml
   - /var/run/docker.sock:/var/run/docker.sock
   ```

2. 确认容器内有权限访问 docker.sock：
   ```bash
   docker exec simple-homepage ls -la /var/run/docker.sock
   ```

3. 查看后台提示的具体错误信息，通常是网络问题或宿主机 Docker 版本不兼容。

4. 安装成功后，**建议移除 `docker.sock` 挂载**，避免长期暴露 Docker API。

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
cd ~/simple-homepage
tar -czf nav-backup-$(date +%Y%m%d).tar.gz ./data
```

**迁移到新机器：**

1. 在新机器上按小白部署流程创建目录和 `docker-compose.yml`
2. 复制备份包到新机器的 `~/simple-homepage/` 目录
3. 解压：
   ```bash
   tar -xzf nav-backup-20260101.tar.gz
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
   docker exec simple-homepage ps aux | grep cron
   ```
4. 检查任务命令是否正确，可以在容器内手动测试：
   ```bash
   docker exec simple-homepage sh /var/www/nav/data/tasks/你的任务.sh
   ```

---

### Q12: 站点 favicon 无法显示

**原因：** 部分内网站点或特殊域名无法被公网抓取到 favicon。

**修复：**
- 在后台站点编辑中手动设置图标（支持 Emoji）
- 或上传自定义图标到可访问的 URL

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
