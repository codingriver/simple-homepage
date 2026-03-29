# Simple Homepage 导航网站

> 一个开箱即用的个人私有导航站，支持 Docker 一键部署，带账户保护、反向代理、备份恢复等完整功能。

- **GitHub**：[https://github.com/codingriver/simple-homepage](https://github.com/codingriver/simple-homepage)
- **Docker Hub**：[https://hub.docker.com/r/codingriver/simple-homepage](https://hub.docker.com/r/codingriver/simple-homepage)
- **镜像大小**：约 52MB（网络传输），解压后约 152MB
- **支持平台**：`linux/amd64` · `linux/arm64`（NAS / VPS / 树莓派均可用）

---

## 目录

1. [5 分钟快速部署](#一5-分钟快速部署)
2. [首次安装向导](#二首次安装向导)
3. [功能总览](#三功能总览)
4. [环境变量说明](#四环境变量说明)
5. [数据目录说明](#五数据目录说明-必读)
6. [反向代理使用指南](#六反向代理使用指南)
7. [Nginx 反代管理](#七nginx-反代管理后台操作)
8. [常用运维命令](#八常用运维命令)
9. [故障排查（小白必看）](#九故障排查小白必看)
10. [安全建议](#十安全建议)
11. [升级镜像](#十一升级镜像)
12. [本地构建（开发者）](#十二本地构建开发者)

---

## 一、5 分钟快速部署

### 方式一：docker run（最简单，适合新手）

```bash
docker run -d \
  --name simple-homepage \
  -p 58080:58080 \
  -v $(pwd)/data:/var/www/nav/data \
  --restart unless-stopped \
  codingriver/simple-homepage:latest
```

启动后访问：`http://你的服务器IP:58080`

> **说明**：`$(pwd)/data` 会在当前目录下创建 `data` 文件夹存放所有数据，容器重建不会丢失。

---

### 方式二：docker compose（推荐，便于管理）

1. 新建一个目录并进入，同时创建 **`data`** 目录（用于挂载持久化数据，与下方 `volumes` 中的 `./data` 对应）：

```bash
mkdir simple-homepage && cd simple-homepage
mkdir -p data
```

2. 创建 `docker-compose.yml` 文件（内容如下）：

```yaml
services:
  simple-homepage:
    image: codingriver/simple-homepage:latest
    container_name: simple-homepage
    restart: unless-stopped
    ports:
      - "58080:58080"
    environment:
      NAV_PORT: "58080"
      TZ: "Asia/Shanghai"
    volumes:
      - ./data:/var/www/nav/data
    healthcheck:
      test: ["CMD-SHELL", "test -S /run/php-fpm.sock && curl -fsS http://127.0.0.1:$${NAV_PORT:-58080}/login.php >/dev/null || exit 1"]
      interval: 60s
      timeout: 5s
      retries: 3
      start_period: 20s
```

3. 启动（**Docker Compose V2** 与 **V1** 任选其一，能执行哪个用哪个）：

```bash
docker compose up -d
```

若提示 `docker compose` 不存在或报错，可改用旧式独立命令：

```bash
docker-compose up -d
```

> 也可一行尝试：`docker compose up -d || docker-compose up -d`（先执行前者，失败则执行后者）。

启动后访问：`http://你的服务器IP:58080`

---

### 方式三：Host 网络模式（Linux 专用，端口最简）

```bash
docker run -d \
  --name simple-homepage \
  --network host \
  -v $(pwd)/data:/var/www/nav/data \
  --restart unless-stopped \
  codingriver/simple-homepage:latest
```

访问：`http://你的服务器IP:58080`

> **注意**：host 模式只在 Linux 上有效，Mac/Windows Docker Desktop 不支持。

---

### 修改端口

默认端口是 `58080`，如需改为其他端口（比如 `8080`）：

**docker run 方式**：把 `-p 58080:58080` 改为 `-p 8080:58080`

**docker compose 方式**：把 `"58080:58080"` 改为 `"8080:58080"`

> 冒号左边是宿主机端口（你访问的端口），右边是容器内端口（保持 58080 不变）。

---

## 二、首次安装向导

首次访问会自动跳转到安装向导页面 `/setup.php`，按提示填写：

| 字段 | 说明 | 示例 |
|---|---|---|
| 管理员用户名 | 只允许字母、数字、下划线、横杠，2-32位 | `admin` |
| 密码 | 至少 8 位 | `MyPass@2026` |
| 确认密码 | 与密码一致 | |
| 站点名称 | 显示在导航页顶部 | `我的导航` |
| 导航站域名 | 选填，用于生成 Nginx 配置，可留空后台再改 | `nav.example.com` |

填写完成后点击「开始使用」，系统会：
- 自动生成随机安全密钥（`AUTH_SECRET_KEY`）
- 创建管理员账户
- 写入初始配置
- 跳转到登录页

> **安装完成后**，`/setup.php` 会自动返回 404，防止被重复访问。

---

## 三、功能总览

### 导航首页
- 分组 + 站点卡片网格，支持折叠/展开（状态持久化）
- 实时搜索（匹配名称和描述）
- 支持自定义背景色或背景图
- 站点卡片尺寸/布局/方向可配置
- 自动抓取站点 Favicon 并缓存
- 移动端响应式，手机访问友好

### 后台管理（`/admin/`，仅管理员可用）

| 页面 | 功能 |
|---|---|
| 站点管理 | 添加/编辑/删除站点，支持普通链接、反向代理两种类型 |
| 分组管理 | 添加/编辑/删除分组，设置可见范围、登录要求 |
| 系统设置 | 站点名称、域名、背景、Cookie 策略、登录锁定参数 |
| 备份与恢复 | 手动备份、自动备份、一键恢复、导入导出配置 |
| Nginx 反代管理 | 一键生成反代配置并 Reload Nginx |

### 安全功能
- 登录失败次数限制 + IP 锁定（默认：失败 5 次锁定 15 分钟）
- CSRF 防护（所有表单）
- 代理目标限制内网 IP，防 SSRF 攻击
- Cookie 策略三档可选（`off` / `auto` / `on`）
- IP 访问时自动降级 Cookie（`secure=false`），保证内网应急访问

---

## 四、环境变量说明

| 变量名 | 默认值 | 说明 |
|---|---|---|
| `NAV_PORT` | `58080` | 容器内监听端口，与端口映射左侧保持一致 |
| `TZ` | `Asia/Shanghai` | 时区，影响日志时间显示 |

> 修改端口时，`NAV_PORT` 环境变量和 `-p` 端口映射的**容器侧**需保持一致，否则健康检查会失败。

---

## 五、数据目录说明（必读）

容器内数据目录：`/var/www/nav/data`

**必须通过 `-v` 挂载到宿主机**，否则容器重建后数据全部丢失！

```
data/
├── .installed          # 安装锁（存在表示已安装）
├── config.json         # 系统配置（站点名、背景、Cookie参数等）
├── sites.json          # 站点与分组配置
├── users.json          # 用户账户数据（密码 bcrypt 加密）
├── ip_locks.json       # IP 登录失败锁定记录
├── backups/            # 自动/手动备份文件（最多保留 20 条）
├── logs/
│   └── auth.log        # 登录日志（SUCCESS/FAIL/IP_LOCKED）
├── favicon_cache/      # 站点图标缓存
└── bg/                 # 自定义背景图
```

---

## 六、反向代理使用指南

反向代理功能让你可以通过导航站统一访问内网服务（如 NAS 管理界面、内网应用等），并自动附带登录验证。

### 两种代理模式

| 模式 | 访问地址示例 | 适用场景 |
|---|---|---|
| **路径前缀模式** | `http://nav.example.com/p/nas/` | 单域名下代理多个服务 |
| **子域名模式** | `http://nas.example.com/` | 每个服务独立子域名 |

### 添加代理站点步骤

1. 后台 → 站点管理 → 添加站点
2. 类型选择「Proxy（反向代理）」
3. 填写字段：

| 字段 | 说明 | 示例 |
|---|---|---|
| 代理模式 | 路径前缀 或 子域名 | 路径前缀 |
| proxy_target | 内网服务地址（仅允许内网 IP） | `http://192.168.1.100:8080` |
| slug（路径模式） | URL 路径标识，小写字母数字横杠 | `nas` |
| proxy_domain（子域名模式） | 访问用的子域名 | `nas.example.com` |

4. 保存后进入「Nginx 反代管理」，点击「生成配置并 Reload Nginx」

### 反代参数模板

后台提供两套参数模板：

| 模板 | 适用场景 |
|---|---|
| ⚡ **精简模式**（默认推荐） | 普通 Web 应用，包含基础反代参数，超时 60s |
| 🔥 **完整模式** | 视频流、WebSocket、SSH、大文件、断点续传等复杂场景，超时 86400s |

---

## 七、Nginx 反代管理（后台操作）

后台 → 系统设置 → 滚动到「Nginx 反代管理」卡片。

### 操作按钮说明

| 按钮 | 说明 |
|---|---|
| 🔄 生成配置并 Reload Nginx | **最常用**：写入配置 → 语法检查 → reload，失败自动回滚 |
| 📝 仅生成配置文件 | 只写入配置，不 reload（用于预检） |
| ⬇ 下载配置文件 | 下载 `.conf` 文件，用于手动部署到外部 Nginx |

### sudo 白名单配置（首次使用需执行一次）

容器内已预配置，通常无需额外操作。如果后台提示「需要先配置sudo白名单」，在**宿主机**执行：

```bash
# 标准 Linux 环境
echo "navwww ALL=(ALL) NOPASSWD: /usr/sbin/nginx -t" > /etc/sudoers.d/nav-nginx
echo "navwww ALL=(ALL) NOPASSWD: /usr/sbin/nginx -s reload" >> /etc/sudoers.d/nav-nginx
chmod 440 /etc/sudoers.d/nav-nginx
```

> 容器内 Nginx 的 sudo 白名单已在镜像构建时预配置，后台页面会自动显示适合当前环境的命令。

---

## 八、常用运维命令

### 查看运行日志

```bash
docker logs -f simple-homepage
```

### 查看容器状态

```bash
docker ps | grep simple-homepage
```

### 停止 / 启动 / 重启

```bash
docker stop simple-homepage
docker start simple-homepage
docker restart simple-homepage
```

### 进入容器内部（排查问题）

```bash
docker exec -it simple-homepage sh
```

### 用户管理命令行工具

```bash
# 列出所有用户
docker exec simple-homepage php /var/www/nav/manage_users.php list

# 添加管理员账户
docker exec simple-homepage php /var/www/nav/manage_users.php add admin 新密码

# 修改密码
docker exec simple-homepage php /var/www/nav/manage_users.php passwd admin 新密码

# 完整重置（清空所有数据，重新触发安装向导）
docker exec simple-homepage php /var/www/nav/manage_users.php reset
```

---

## 九、故障排查（小白必看）

### ❶ 访问页面显示「无法连接」

**检查步骤：**

```bash
# 1. 确认容器在运行
docker ps | grep simple-homepage

# 2. 查看启动日志
docker logs simple-homepage

# 3. 检查端口是否被占用
netstat -tlnp | grep 58080
# 或
ss -tlnp | grep 58080
```

**常见原因：**
- 容器未启动：执行 `docker start simple-homepage`
- 端口冲突：换一个端口，如 `-p 58081:58080`
- 防火墙拦截：开放对应端口

---

### ❷ 页面打开但一直跳转到安装向导

**原因：** 数据目录未挂载或挂载路径不对，导致 `.installed` 文件不存在。

**检查：**

```bash
# 查看挂载情况
docker inspect simple-homepage | grep -A5 Mounts

# 确认 data 目录下有 .installed 文件
ls -la ./data/
```

**解决：** 确保启动命令包含 `-v $(pwd)/data:/var/www/nav/data`，且路径正确。

---

### ❸ 登录后反复跳回登录页

**原因 1：Cookie 问题（最常见）**

| 访问方式 | 推荐设置 |
|---|---|
| IP 直接访问（如 `192.168.1.1:58080`） | `cookie_secure=off`（默认），`cookie_domain` 留空 |
| 域名 HTTP 访问 | `cookie_secure=off` |
| 域名 HTTPS 访问 | `cookie_secure=auto` 或 `on` |
| 经过反代（有 X-Forwarded-Proto） | `cookie_secure=auto` |

后台 → 系统设置 → Cookie Secure 模式，选择对应选项。

**原因 2：IP 被锁定**

```bash
# 清除 IP 锁定
docker exec simple-homepage sh -c 'echo "{}" > /var/www/nav/data/ip_locks.json'
```

**原因 3：AUTH_SECRET_KEY 发生变化**

密钥变化后旧 Token 全部失效，重新登录即可。

---

### ❹ 重建容器后数据全部丢失

**原因：** 没有挂载数据目录。

**预防：** 启动命令必须包含：
```bash
-v $(pwd)/data:/var/www/nav/data
```

**已丢失的补救：**
- 如有备份文件，可在后台「备份与恢复」页面导入
- 如无备份，只能重新安装

---

### ❺ 后台 Proxy 反代不生效

**检查步骤：**
1. 确认站点类型是「Proxy」
2. 后台 → 系统设置 → Nginx 反代管理 → 点击「🔄 生成配置并 Reload Nginx」
3. 查看操作结果提示，如果有报错查看详情

**查看反代配置文件：**
```bash
docker exec simple-homepage cat /etc/nginx/conf.d/nav-proxy.conf
```

**如果 reload 失败：**
```bash
# 手动测试 Nginx 配置语法
docker exec simple-homepage nginx -t

# 查看 Nginx 错误日志
docker exec simple-homepage tail -50 /var/log/nginx/error.log
```

---

### ❻ 端口 58080 被占用

```bash
# 查看占用情况
netstat -tlnp | grep 58080

# 方法一：换一个宿主机端口（容器内不变）
docker run -d --name simple-homepage -p 8080:58080 ...

# 方法二：Host 网络模式下修改容器内端口
docker run -d --name simple-homepage --network host \
  -e NAV_PORT=8080 ...
```

---

### ❼ 上传背景图失败 / 文件操作 500 错误

**原因：** 数据目录权限不足。

```bash
# 修复数据目录权限
docker exec simple-homepage chown -R navwww:navwww /var/www/nav/data
docker exec simple-homepage chmod -R 755 /var/www/nav/data
```

---

### ❽ 完全重置，回到安装向导

```bash
docker exec simple-homepage php /var/www/nav/manage_users.php reset
```

执行后立即访问首页，完成安装向导，否则处于未保护状态。

---

### ❾ 忘记管理员密码

```bash
# 方法一：修改密码
docker exec simple-homepage php /var/www/nav/manage_users.php passwd admin 新密码

# 方法二：重新添加账户（先删除再添加）
docker exec simple-homepage php /var/www/nav/manage_users.php del admin
docker exec simple-homepage php /var/www/nav/manage_users.php add admin 新密码
```

---

### ❿ ARM 设备（树莓派/NAS）拉取失败

```bash
# 明确指定 arm64 平台
docker pull --platform linux/arm64 codingriver/simple-homepage:latest
```

---

## 十、安全建议

1. **强密码**：安装时使用大小写+数字+特殊字符，至少 12 位
2. **HTTPS**：通过反代配置 HTTPS，同时将 `cookie_secure` 改为 `auto`
3. **定期备份**：后台 → 备份与恢复 → 立即备份，并下载到本地保存
4. **不暴露后台**：避免将 `/admin/` 路径直接暴露到公网
5. **更新镜像**：定期执行 `docker pull` 获取安全修复
6. **Cookie Domain**：单域名访问保持留空，多子域 SSO 才填 `.yourdomain.com`

---

## 十一、升级镜像

```bash
# 1. 拉取最新镜像
docker pull codingriver/simple-homepage:latest

# 2. 停止并删除旧容器（数据已挂载，不会丢失）
docker stop simple-homepage
docker rm simple-homepage

# 3. 用相同参数重新启动
docker run -d \
  --name simple-homepage \
  -p 58080:58080 \
  -v $(pwd)/data:/var/www/nav/data \
  --restart unless-stopped \
  codingriver/simple-homepage:latest
```

**docker compose 方式升级：**

```bash
docker compose pull && docker compose up -d
```

若本机没有 `docker compose` 子命令，请改用：

```bash
docker-compose pull && docker-compose up -d
```

> **升级前建议**：先在后台手动备份一次，以防万一。

---

## 十二、本地构建（开发者）

```bash
# 克隆仓库
git clone https://github.com/codingriver/simple-homepage.git
cd simple-homepage

# 复制本地配置
cp local/.env.example local/.env

# 一键构建并启动
bash local/docker-build.sh
```

访问：`http://localhost:58080`

### 标签策略

- 日常提交推送到 `latest`
- 打 `v*` git tag 时额外推送版本标签（如 `v2.3.0`）
- 每次推送自动构建 `linux/amd64` + `linux/arm64` 多架构镜像

### CI 冒烟测试

每次触发工作流会：
1. 本地构建测试镜像
2. 启动容器，等待服务可用
3. 检查 `login.php` / `setup.php` 状态码
4. 读取 Docker Health 状态
5. 生成测试报告 artifact

冒烟测试通过后才推送到 Docker Hub。

---

## 附录：Cookie 配置速查

### Cookie Secure 三档模式

| 模式 | 行为 | 适用场景 |
|---|---|---|
| `off`（默认） | HTTP/HTTPS 均可发送 Cookie | 内网调试、IP 直接访问、首次部署 |
| `auto` | 自动检测协议（含 X-Forwarded-Proto） | VPS 反代、混合环境 |
| `on` | Cookie 仅 HTTPS 发送 | 生产环境全程 HTTPS |

### 部署方式与 Cookie 配置对照

| 部署方式 | Cookie Secure | Cookie Domain | 跨子域 SSO |
|---|---|---|---|
| IP 直接访问 | `off` | 留空 | ❌ |
| 单域名 HTTP | `off` | 留空或域名 | ❌ |
| 单域名 HTTPS | `auto` 或 `on` | 留空或域名 | ❌ |
| 多子域 HTTPS + SSO | `auto` 或 `on` | `.yourdomain.com` | ✅ |

> **IP 访问自动降级**：无论后台如何配置，用 IP 访问时代码自动将 `secure=false`、`domain=空`，保证内网应急访问始终可用。

---

*文档版本：v2.3 | 最后更新：2026-03-24* 