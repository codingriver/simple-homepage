# Simple Homepage

一个面向个人 / 家庭实验室 / NAS / VPS 的私有导航与轻量运维门户。

它不只是“网址收藏页”，而是一个基于 `PHP 8.2 + Nginx + JSON` 的单容器应用：带登录保护、站点分组、反向代理、DNS 管理、DDNS、计划任务、备份恢复、调试工具和完整 E2E 测试。

- GitHub: <https://github.com/codingriver/simple-homepage>
- Docker Hub: <https://hub.docker.com/r/codingriver/simple-homepage>
- 镜像平台: `linux/amd64` / `linux/arm64`
- 运行方式: Docker 单容器，数据目录挂载即可持久化

## 项目特性

### 前台导航

- 分组 + 站点卡片展示，支持搜索、折叠状态持久化
- 支持公开分组和登录后可见分组
- 支持自定义背景图 / 背景色、卡片尺寸、布局和方向
- 自动抓取并缓存 favicon
- 移动端适配，手机访问可直接使用
- 已登录用户可看到站点健康状态缓存

### 后台管理

- 站点管理：普通链接、内部直达、反向代理三种站点类型
- 分组管理：可见范围、登录要求、排序与增删改查
- 用户管理：管理员账户维护、CLI 重置与改密
- 系统设置：站点名称、域名、Cookie 策略、背景、Webhook、登录安全参数
- 备份恢复：手动备份、恢复、导入导出
- Nginx 管理：配置编辑、语法检查、保存并 reload
- 调试工具：Nginx / PHP-FPM / DNS / 请求耗时日志查看与清理
- 计划任务：Web 界面维护 cron 任务，支持立即执行和日志分页查看

### 运维能力

- 内置路径前缀 / 子域名两种反向代理模式
- DNS 解析管理，当前支持 `Aliyun DNS`、`Cloudflare`
- DDNS 动态解析，支持多来源优选 IP / 本机公网 IP
- 自动生成 DDNS 调度器并接入计划任务系统
- JSON 文件存储，无需 MySQL / Redis
- Docker 镜像内置 `cron`、`python3`、`nginx`、`php-fpm`

### 安全与稳定性

- 首次安装向导，安装完成后 `setup.php` 自动失效
- CSRF 防护、登录失败次数限制、IP 锁定
- Cookie `off` / `auto` / `on` 三档策略
- IP 访问模式自动降级 Cookie，避免内网应急访问失效
- 重定向地址净化，避免开放跳转
- 反代目标限制内网地址，降低 SSRF 风险
- 备份恢复前自动备份当前状态
- 仓库包含大量 Playwright E2E 用例和 Lighthouse 基线检查

## 适用场景

- 家庭实验室导航页
- NAS / 路由器 / 内网服务统一入口
- 小型 VPS 运维入口
- 需要账号保护的自用导航站
- 希望把 DNS、DDNS、反代、任务调度集中到一个轻量后台里

## 快速开始

### 方式一：直接运行镜像

```bash
docker run -d \
  --name simple-homepage \
  -p 58080:58080 \
  -v $(pwd)/data:/var/www/nav/data \
  -e PUID=$(id -u) \
  -e PGID=$(id -g) \
  -e TZ=Asia/Shanghai \
  --restart unless-stopped \
  codingriver/simple-homepage:latest
```

访问：

```text
http://你的服务器IP:58080
```

### 方式二：使用仓库内置 `docker-compose.yml`

```bash
git clone https://github.com/codingriver/simple-homepage.git
cd simple-homepage
mkdir -p data
PUID=$(id -u) PGID=$(id -g) docker compose up -d
```

如果你的环境还是旧版命令，也可以用：

```bash
PUID=$(id -u) PGID=$(id -g) docker-compose up -d
```

### 首次安装

首次访问会进入安装向导，创建管理员账户并自动生成：

- `data/.installed`
- `data/auth_secret.key`
- `data/config.json`
- `data/users.json`

安装完成后，`/setup.php` 会返回 `404`，避免重复初始化。

## 无人值守安装

容器启动时支持通过环境变量自动写入初始管理员信息。适合首次部署脚本化、测试环境或 CI。

| 变量 | 必填 | 说明 |
| --- | --- | --- |
| `ADMIN` | 是 | 管理员用户名，2-32 位，字母/数字/下划线/横杠 |
| `PASSWORD` | 否 | 管理员密码，留空则允许无密码初始化，不建议生产使用 |
| `NAME` | 否 | 站点名称，默认 `导航中心` |
| `DOMAIN` | 否 | 导航站域名 |

示例：

```bash
docker run -d \
  --name simple-homepage \
  -p 58080:58080 \
  -v $(pwd)/data:/var/www/nav/data \
  -e ADMIN=admin \
  -e PASSWORD='ChangeMe123!' \
  -e NAME='我的导航' \
  -e DOMAIN='nav.example.com' \
  codingriver/simple-homepage:latest
```

## 环境变量

| 变量 | 默认值 | 说明 |
| --- | --- | --- |
| `NAV_PORT` | `58080` | 容器内监听端口 |
| `TZ` | `Asia/Shanghai` | 容器时区 |
| `PUID` | 空 | Linux bind mount 时可选；将容器内 `navwww` 的 UID 对齐到宿主机 `data` 目录 owner；不填时保持镜像默认 `1000` |
| `PGID` | 空 | Linux bind mount 时可选；将容器内 `navwww` 的 GID 对齐到宿主机 `data` 目录 owner group；不填时保持镜像默认 `1000` |
| `ADMIN` | 空 | 首次启动时无人值守安装用户名 |
| `PASSWORD` | 空 | 首次启动时无人值守安装密码 |
| `NAME` | `导航中心` | 首次启动时站点名称 |
| `DOMAIN` | 空 | 首次启动时导航域名 |
| `NAV_DEV_MODE` | 空 | 开发模式，启用内置测试管理员 |
| `NAV_REQUEST_TIMING` | `1` | 设为 `0` 可关闭请求耗时日志 |
| `AUTH_SECRET_KEY` | 空 | 可显式指定认证密钥；默认写入 `data/auth_secret.key` |

## 数据持久化

必须挂载：

```text
/var/www/nav/data
```

常见数据文件：

```text
data/
├── .installed
├── auth_secret.key
├── config.json
├── sites.json
├── users.json
├── scheduled_tasks.json
├── dns_config.json
├── ip_locks.json
├── backups/
├── logs/
├── bg/
└── favicon_cache/
```

说明：

- `users.json` 保存账户数据
- `sites.json` 保存分组和站点
- `scheduled_tasks.json` 保存计划任务定义
- `dns_config.json` 保存 DNS 账户与配置
- `backups/` 保存导出与恢复快照
- 容器重建后，未挂载 `data` 会导致所有配置丢失
- Linux 宿主机若使用 bind mount，建议先 `mkdir -p data`，并按需传入 `PUID/PGID` 与宿主机目录 owner 对齐；容器启动时不会再递归 `chown` 整个挂载目录
- 若未传 `PUID/PGID`，容器内运行用户默认是 `1000:1000`；仅在宿主机挂载目录权限本来就与该 UID/GID 匹配时才建议省略

## 常用命令

### 容器管理

```bash
docker logs -f simple-homepage
docker restart simple-homepage
docker exec -it simple-homepage sh
```

### CLI 用户管理

```bash
docker exec simple-homepage php /var/www/nav/manage_users.php list
docker exec simple-homepage php /var/www/nav/manage_users.php info admin
docker exec simple-homepage php /var/www/nav/manage_users.php add admin 新密码
docker exec simple-homepage php /var/www/nav/manage_users.php passwd admin 新密码
docker exec simple-homepage php /var/www/nav/manage_users.php del admin
docker exec simple-homepage php /var/www/nav/manage_users.php reset
```

`reset` 会清空安装状态、站点配置、登录锁定和反代配置，并重新进入安装向导；备份文件会保留。

## 开发与测试

### 本地开发容器

```bash
cp local/.env.example local/.env
bash local/docker-build.sh dev
```

开发模式会：

- 挂载源码目录
- 启用 `NAV_DEV_MODE`
- 加载 `local/php-dev.ini`
- 提供内置测试管理员 `qatest / qatest2026`

更多说明见 [local/README.md](local/README.md)。

### Playwright E2E

启动开发容器后，可直接运行仓库内置测试环境：

```bash
docker compose \
  -f local/docker-compose.yml \
  -f local/docker-compose.dev.yml \
  -f local/docker-compose.test.yml \
  run --rm playwright-full
```

移动端用例：

```bash
docker compose \
  -f local/docker-compose.yml \
  -f local/docker-compose.dev.yml \
  -f local/docker-compose.test.yml \
  run --rm playwright-mobile
```

也可以在本地安装依赖后直接执行：

```bash
npm install
BASE_URL=http://127.0.0.1:58080 npm run test:e2e:full:chromium
BASE_URL=http://127.0.0.1:58080 npm run test:e2e:full:mobile

# 单个文件 / 单条用例调试
BASE_URL=http://127.0.0.1:58080 npm run test:e2e:headed:chromium -- tests/e2e/full/csrf-guards.spec.ts:8
BASE_URL=http://127.0.0.1:58080 npm run test:e2e:headed:chromium -- -g "csrf guards reject admin mutations without valid token"
```

### Lighthouse

```bash
docker compose \
  -f local/docker-compose.yml \
  -f local/docker-compose.dev.yml \
  -f local/docker-compose.test.yml \
  run --rm lighthouse
```

## 文档索引

- [Docker 部署文档](docs/Docker部署文档.md)
- [导航网站部署文档](docs/导航网站部署文档.md)
- [VPS-A 网关部署文档](docs/VPS-A网关部署文档.md)
- [本地 Docker 开发说明](local/README.md)
- [Full E2E 测试教程 - Docker 环境](docs/Full-E2E测试教程-Docker环境.md)
- [Full E2E 测试教程 - 本地环境](docs/Full-E2E测试教程-本地环境.md)
- [测试规划](docs/项目测试规划.md)
- [测试 TODO](docs/测试TODO.md)

## 技术栈

- Backend: `PHP 8.2`
- Web: `Nginx`
- DNS CLI: `Python 3`
- Storage: `JSON files`
- Runtime: `Docker`, `supervisord`, `cron`
- Test: `Playwright`, `Lighthouse CI`

## 项目结构

```text
.
├── public/                 # 前台页面与安装 / 登录入口
├── admin/                  # 后台页面
├── shared/                 # 认证和公共逻辑
├── cli/                    # CLI 工具
├── docker/                 # 镜像与运行配置
├── local/                  # 本地开发和测试 compose
├── tests/e2e/full/         # Playwright E2E
├── docs/                   # 项目文档
└── manage_users.php        # 用户管理 CLI
```

## 安全建议

- 生产环境务必挂载 `data` 目录
- 首次安装后立即修改默认或弱密码
- 公网部署建议开启 HTTPS，并将 Cookie 策略调整为 `auto` 或 `on`
- 使用域名模式跨子域登录时，再配置 `cookie_domain`
- 在改动 Nginx 代理配置前，先执行手动备份
- 调试时临时开启 `display_errors`，结束后关闭

## 贡献

欢迎提交 Issue 和 PR。更新功能时，建议同步补充：

- 根目录 `README.md`
- `docs/` 下对应专题文档
- `tests/e2e/full/` 相关回归测试
