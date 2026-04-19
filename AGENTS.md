<!-- AGENTS.md — 供 AI Coding Agent 阅读的项目全景说明 -->

> 本文件供 AI Coding Agent 阅读。项目主要文档和注释使用中文，因此本文件以中文撰写。
> 若你修改了本文件提及的任何架构、构建流程、测试策略或安全机制，必须同步更新本文件。

---

## 项目概述

**Simple Homepage**（私有导航首页）是一个面向个人、家庭网络、NAS、软路由、小型 VPS 的自托管导航面板。它不只是书签页，还集成了站点/分组管理、反向代理入口、DNS 管理、DDNS 动态解析、计划任务、配置备份与恢复、Host-Agent 宿主机运维桥接、Docker 管理、WebDAV、Webhook 通知等能力。

- **GitHub**: https://github.com/codingriver/simple-homepage
- **Docker Hub**: https://hub.docker.com/r/codingriver/simple-homepage

---

## 技术栈

| 层级 | 技术 |
|------|------|
| **后端** | PHP >= 8.2，无主流框架，纯原生 PHP 开发 |
| **数据存储** | JSON 文件（`data/` 目录），不依赖 MySQL/Redis |
| **前端** | 原生 HTML/CSS/JS（无 React/Vue 等现代前端框架） |
| **Web 服务器** | Nginx + PHP-FPM（Unix socket） |
| **进程管理** | Supervisor（容器内同时管理 Nginx、PHP-FPM、Cron） |
| **容器化** | Docker，基于 `php:8.2-fpm-bookworm`（Debian 系），支持 `linux/amd64` 和 `linux/arm64` |
| **测试** | Playwright（E2E）、PHPUnit（单元）、Lighthouse CI（性能） |
| **包管理** | Composer（PHP）、npm（仅开发依赖） |

---

## 代码组织

```text
public/          # 前台入口：首页、登录、安装向导、WebDAV、favicon 代理等
  api/           # 公开 API（sites.php、dns.php）
  auth/          # Nginx auth_request 鉴权端点（verify.php）
admin/           # 后台管理页面（*.php）和 AJAX API（*_ajax.php、*_api.php）
  shared/        # 后台共享库：functions.php、header.php、footer.php、host_agent_lib.php 等
  api/           # 后台专用 API（task_status.php、task_log.php）
shared/          # 核心共享库：auth.php（认证/用户/配置）、notify_runtime.php、request_timing.php、http_client.php
cli/             # CLI 脚本：计划任务执行、DDNS 同步、Host-Agent 服务端、健康检查、用户管理等
python/          # Python 辅助脚本（dns_core.py）
docker/          # Docker 构建相关配置：nginx.conf、nginx-site.conf、php-fpm.conf、entrypoint.sh、supervisord.conf 等
nginx-conf/      # Nginx 配置模板（proxy-params-simple/full.conf、subsite.conf 等）
data/            # 持久化数据目录（必须挂载到宿主机）
tests/
  e2e/full/      # Playwright E2E 测试用例（150+ spec 文件）
  phpunit/       # PHPUnit 单元测试（Shared、Admin、Subsite 三个套件）
  helpers/       # 测试辅助函数（auth.ts、cli.ts、data.ts、fixtures.ts）
  fixtures/      # 测试固件（import-valid.json、import-invalid.json）
local/           # 本地开发用的 docker-compose、.env.example、docker-build.sh、php-dev.ini
```

### 关键文件职责

| 文件/目录 | 职责 |
|-----------|------|
| `shared/auth.php` | 核心认证库：JWT-like Token（HMAC-SHA256）、Cookie 管理、用户加载、IP 锁定、CSRF、会话撤销、权限系统 |
| `shared/http_client.php` | 带 SSRF 防护的 HTTP 客户端 |
| `admin/shared/functions.php` | 后台主函数库：站点/配置读写、备份恢复、审计日志、Nginx 代理待生效检测等 |
| `admin/shared/header.php` / `footer.php` | 后台页面模板（侧边栏导航、HTML 骨架） |
| `admin/shared/host_agent_lib.php` | Host-Agent HTTP API 客户端 |
| `cli/host_agent.php` | Host-Agent 服务端（本机文件系统、SSH、进程/服务/网络/Docker 等能力的最终执行层） |
| `cli/manage_users.php` | 用户管理 CLI（list / info / add / passwd / del / reset） |
| `cli/run_scheduled_task.php` | 计划任务执行器 |
| `public/auth/verify.php` | Nginx `auth_request` 鉴权端点（返回 200/401，设置 `X-Auth-User`/`X-Auth-Role` 头部） |

### 页面与 API 的两种模式

- **后台页面**（`admin/*.php`）：遵循 `require shared/functions.php` → `POST 处理（在 header.php 之前）` → `require shared/header.php` → HTML → `require shared/footer.php`。
- **AJAX 端点**（`*_ajax.php`、`*_api.php`）：以 `declare(strict_types=1);` 开头，校验 `HTTP_X_REQUESTED_WITH === 'XMLHttpRequest'`，权限检查后按 `action` 路由，返回 JSON（`['ok' => bool, 'msg' => string, 'data' => ...]`）。

---

## 构建与运行命令

### 生产部署（基于 Docker Hub 镜像）

```bash
# 1. 准备数据目录
mkdir -p ~/simple-homepage/data

# 2. 使用项目根目录的 docker-compose.yml 启动
docker compose up -d

# 默认端口 58080；数据挂载在 ./data:/var/www/nav/data
```

### 本地开发（源码热更新 + 内置测试账号）

```bash
cp local/.env.example local/.env
# 按需编辑 local/.env

# 启动开发环境（自动挂载源码、启用 NAV_DEV_MODE、临时挂载 docker.sock）
bash local/docker-build.sh dev

# 其他常用命令
bash local/docker-build.sh dev start    # 仅重启/拉起容器
bash local/docker-build.sh dev logs -f  # 跟踪日志
bash local/docker-build.sh dev down     # 停止
bash local/docker-build.sh dev rebuild  # 强制重建开发镜像
```

开发模式默认启用内置测试管理员：`qatest / qatest2026`。
开发环境默认 `HOST_AGENT_INSTALL_MODE=simulate`，不会修改真实宿主机。

### 测试命令

**Playwright E2E（需先启动开发容器）**

```bash
# 安装依赖
npm install

# 本地运行（默认 baseURL: http://127.0.0.1:58080）
npm run test:e2e:full:chromium          # 桌面端
npm run test:e2e:full:mobile-chrome     # 移动端
npm run test:e2e:headed                 # headed 模式调试

# Docker 环境中运行（推荐，避免本地环境差异）
docker compose -f local/docker-compose.yml -f local/docker-compose.dev.yml -f local/docker-compose.test.yml run --rm playwright-full
docker compose -f local/docker-compose.yml -f local/docker-compose.dev.yml -f local/docker-compose.test.yml run --rm playwright-mobile
```

**PHPUnit 单元测试**

```bash
vendor/bin/phpunit
```

**Lighthouse 性能测试**

```bash
npm run test:perf
# 或在 Docker 中运行
docker compose -f local/docker-compose.yml -f local/docker-compose.dev.yml -f local/docker-compose.test.yml run --rm lighthouse
```

---

## 核心开发规范

### 1. HTTP Header 输出顺序（最高频 Bug）

- **`session_start()`、`header()`、`setcookie()`、`http_response_code()` 必须在任何 HTML/文本输出之前执行。**
- 所有 `POST` 处理逻辑必须放在 `require 'header.php'`（或任何输出 HTML 的文件）之前。
- 采用 **PRG 模式**（Post-Redirect-Get）：POST 处理后立即 `header('Location: ...')` + `exit`。

### 2. Session 初始化

```php
<?php
if (session_status() === PHP_SESSION_NONE) session_start();
// ... 后续逻辑
```

### 3. CSRF 防护

- 所有修改数据的操作（包括登录、退出、安装向导）都必须验证 CSRF Token。
- 表单中调用 `csrf_field()`，POST 处理中调用 `csrf_check()`。
- AJAX 鉴权失败返回 JSON（401），不能 302 重定向。

### 4. XSS 防护

- 所有输出到 HTML 的用户数据必须转义：
  ```php
  echo htmlspecialchars($var, ENT_QUOTES, 'UTF-8');
  ```
- JS 中的 JSON 输出使用 `JSON_HEX_TAG | JSON_HEX_AMP`。

### 5. 文件路径安全

- 禁止直接使用用户输入拼接路径。
- 优先使用 `basename()` 或正则白名单；必要时用 `realpath()` 验证在允许目录内。

### 6. JSON 数据存储规范

- 写入：`file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX)`
- 读取：`json_decode(file_exists($f) ? file_get_contents($f) : '{}', true) ?? []`

### 7. 密码与 Token 安全

- 密码：`password_hash($p, PASSWORD_BCRYPT, ['cost' => 10])` / `password_verify()`
- Token 比较：`hash_equals($expected, $actual)`

### 8. Nginx + PHP-FPM 死锁规避

- 在 `admin/` 等使用 `auth_request` 的 location 中，PHP 子 location 必须加 `auth_request off;`，由 PHP 自行鉴权。

### 9. 计划任务健壮性

- 任务执行必须设置**硬超时**（默认 3600s），防止用户脚本死循环挂起 PHP 进程。
- 任务结果写入 `scheduled_tasks.json` 时必须使用 **`flock(LOCK_EX)`** 保护，防止并发结果覆盖。
- 任务执行锁文件必须记录 PID，并支持**僵尸锁自动清理**（OOM/SIGKILL 场景）。

---

## 测试策略

### Playwright E2E

- **测试目录**: `tests/e2e/full/`
- **项目配置**: `playwright.config.ts`
  - `workers: 1`，`fullyParallel: false`（串行执行）
  - 默认 projects: `chromium`（桌面端）、`mobile-chrome`（移动端 Pixel 7）
  - 失败时保留 `trace`，失败时截图，视频默认关闭
  - CI 环境下 `retries: 1`
- **测试规范**: 必须遵守 `docs/测试用例编写规范.md` 中的维度清单（权限、异常、边界、状态、响应式、数据一致性等）。
- **数据隔离**: 创建型数据需使用唯一值（时间戳），修改全局配置/文件后需在 `try/finally` 中回滚，禁止测试间残留数据依赖。
- **定位策略**: 优先使用 `getByRole` / `getByLabel`，其次稳定 `id/name`，禁止把 `waitForTimeout` 当作主要同步手段。
- **认证辅助**: `tests/helpers/auth.ts` 提供 `loginAsDevAdmin(page)`，自动尝试 `qatest/qatest2026` 或 `admin/Admin@test2026`。
- **环境隔离**: `tests/helpers/fixtures.ts` 扩展 Playwright base test，在每个测试前自动调用 `resetVolatileAppData()` 覆写 JSON 为安全默认值，确保测试间无数据污染。

### PHPUnit

- **配置文件**: `phpunit.xml`
- **测试套件**: `Shared`、`Admin`、`Subsite`
- **包含源码**: `shared/`、`admin/shared/`
- **Bootstrap**: `tests/phpunit/bootstrap.php`（创建临时 `DATA_DIR`，测试结束后自动清理）
- **隔离方式**: 每个测试类的 `setUp()` 中手动 `unlink()` 相关 JSON 文件，确保零残留。

### Lighthouse 性能测试

- **配置**: `lighthouserc.json`
- **检测 URL**: `login.php`、`index.php`
- **阈值**: Performance >= 0.6（warn），Accessibility >= 0.85（warn），Best-practices >= 0.85（warn），FCP <= 3000ms，LCP <= 4000ms，CLS <= 0.15。

---

## 部署流程

### CI/CD

- **GitHub Actions 工作流**: `.github/workflows/docker-publish.yml`
  - 触发条件：`push` 到 `main`/`master` 分支，或推送 `v*` 标签，或手动触发 `workflow_dispatch`。
  - 构建多架构镜像（`linux/amd64`, `linux/arm64`）并推送到 Docker Hub `codingriver/simple-homepage`。
  - 自动更新 Docker Hub 描述（从 `README.md` 同步）。
- **手动推送工作流**: `.github/workflows/manual-push.yml`
  - 支持选择是否跳过 `arm64` 以加速构建。
  - 支持额外指定版本标签。

### 数据目录（必须挂载）

容器内路径：`/var/www/nav/data`

常见文件：
- `config.json` — 系统配置
- `sites.json` — 站点与分组
- `users.json` — 用户数据
- `scheduled_tasks.json` — 计划任务
- `dns_config.json` — DNS 配置
- `ddns_tasks.json` — DDNS 任务
- `notifications.json` — 通知渠道
- `ip_locks.json` — IP 登录失败锁定
- `sessions.json` — 会话撤销记录
- `auth_secret.key` — 认证密钥（权限 600）
- `backups/` — 备份快照
- `logs/` — 各类日志
- `tasks/` — 计划任务脚本（`*.sh`）和日志（`*.log`）共享目录
- `favicon_cache/` — 自动抓取的 favicon 缓存
- `bg/` — 背景图上传目录
- `nginx/` — Nginx 代理参数模板

### 环境变量

| 变量 | 说明 |
|------|------|
| `NAV_PORT` | 容器内监听端口，默认 `58080` |
| `TZ` | 时区，默认 `Asia/Shanghai` |
| `PUID` / `PGID` | 可选，显式指定运行用户 UID/GID；留空时自动按 `data` 目录 owner 对齐 |
| `ADMIN` / `PASSWORD` / `NAME` / `DOMAIN` | 无人值守首次安装参数 |
| `NAV_DEV_MODE` | 开发模式，启用内置测试管理员 `qatest / qatest2026` |
| `HOST_AGENT_INSTALL_MODE` | `host`（真实宿主机）或 `simulate`（模拟，推荐开发/测试） |
| `AUTH_SECRET_KEY` | 可选，显式指定认证密钥 |
| `NAV_REQUEST_TIMING` | 设为 `0` 关闭请求耗时日志 |

---

## 安全注意事项

1. **认证机制**: 使用 JWT-like Token（HMAC-SHA256），Cookie `HttpOnly` + `SameSite=Lax`，支持会话级撤销（`data/sessions.json`）。
2. **IP 锁定**: 登录失败超过限制后自动锁定 IP（默认 5 次失败锁定 15 分钟）。
3. **Host-Agent 模式**: 开发/测试环境强烈建议使用 `simulate` 模式，避免误改宿主机 SSH 配置和系统文件。
4. **docker.sock 挂载**: 仅在一键安装/升级 `host-agent` 时临时挂载，完成后建议移除。
5. **密钥管理**: `AUTH_SECRET_KEY` 优先从环境变量读取，否则自动生成并保存到 `data/auth_secret.key`（权限 600）。
6. **SSRF 防护**: 所有根据用户输入发起外部 HTTP 请求的代码必须经过目标地址安全校验（禁止内网、回环地址）。
7. **Cookie 安全降级**: 代码内置自动降级逻辑——用 IP 访问时自动设置 `secure=false, domain=空`，保证内网 IP 访问始终可登录。
8. **无人值守安装安全**: 使用 `ADMIN`/`PASSWORD` 环境变量完成首次安装后，应用会自动删除 `data/.initial_admin.json`。生产环境不应长期保留明文密码。

---

## 关键调用链（Host-Agent / 文件系统 / SSH / Docker）

本项目的“本机文件系统”和“本机 SSH 管理”均不直接由 Web 页面操作，而是统一走 `host-agent` 桥接：

```text
admin/files.php
  -> admin/file_api.php
  -> admin/shared/file_manager_lib.php
  -> admin/shared/host_agent_lib.php
  -> host-agent HTTP API
  -> cli/host_agent.php
```

Docker 管理也走同一条 host-agent 桥接：

```text
admin/docker_hosts.php
  -> admin/docker_api.php
  -> admin/shared/host_agent_lib.php
  -> host-agent HTTP API
  -> cli/host_agent_docker_proxy.php（或 cli/host_agent.php 中的 Docker 逻辑）
```

`host-agent` 有两种运行模式：
- **simulate**: 操作落在 `data/host-agent-sim-root/` 下的模拟目录，不会修改真实宿主机。开发/测试默认使用此模式。
- **host**: 以特权容器运行，挂载宿主机根目录到 `/hostfs`，直接操作宿主机文件和 SSH 服务。

---

## 已知问题与设计缺陷（必读）

以下问题已在 `docs/项目问题分析与设计缺陷.md` 中记录，修改相关代码时需特别注意：

- **P0**: `subsite-middleware/auth_check.php` 中 `_nav_token` Cookie 写入/URL 清理逻辑位于 `exit` 之后，正常流程下不可达。
- **P1**: `public/index.php` 体积过大，承担职责过多；`admin/shared/functions.php` 中的 `admin_run_command()` 缺少超时控制。
- **P1**: Webhook HTTP 请求逻辑存在重复代码，未统一收敛到 `shared/http_client.php`。
- **P2**: 缺少统一异常处理层；配置读取缺少统一抽象；权限粒度较粗；测试层对 `shared/auth.php`、`shared/notify_runtime.php`、`shared/request_timing.php` 缺少底层单元测试。

---

## AI Coding Agent 协作规范

### 一、基础工作原则

1. **没有证据时，不下结论**
   - 优先说：`不确定，需要先检查`
   - 禁止把推测说成事实

2. **没验证前，不宣布完成**
   - 必须严格区分：`已修改` / `已验证` / `阻塞项 / 未验证部分`
   - 禁止把"已修改"说成"已完成"
   - 禁止未复查就说"已全部处理"

3. **遇到外部系统，先查前置条件**
   - 网络是否可达
   - 登录状态是否有效
   - 权限是否具备
   - 目标资源是否存在
   - 外部条件未确认前，不得推进发布/部署结论

4. **全量任务必须：扫描 → 列清单 → 修改 → 复扫**
   - 未做复扫前不得说"全部完成"

5. **回复尽量按以下结构输出**
   - 目标
   - 前置检查
   - 执行内容
   - 验证结果
   - 剩余风险 / 阻塞项

### 二、代码修复规范

1. **先定位根因，再修改**
2. **如果用户提到具体文件，必须先阅读该文件**
3. **修改后必须做实际验证**
   - 能跑测试就跑测试
   - 能走真实链路就走真实链路
   - 能做命令验证就做命令验证
4. **没有完成实际验证前，不得说"问题已解决"**

回复时必须明确写清：根因判断、修改内容、已验证内容、尚未验证内容、风险点。

### 三、文档更新规范

出现`所有文档`/`全部更新`/`全量同步`/`清理残留`/`统一改掉`/`全文替换`等需求时：

1. 先扫描全部相关文档
2. 列出命中文件清单
3. 再逐个修改
4. 修改后再次扫描确认无残留
5. 回复里给出：命中文件 / 已修改文件 / 是否确认无残留 / 残留位置

强制要求：
- 未复扫前，不得说"所有文档已更新"
- 未列清单前，不得说"已经全部处理"
- 不允许只改几个明显文件就结束

### 四、发布部署规范

涉及 Docker Hub、镜像发布、远程 tag 清理、部署、远端操作时：

1. 先检查前置条件并汇报
2. 再执行发布 / 推送 / 删除动作
3. 最后复查远端状态
4. **只有远端复查通过，才能说完成**

强制要求：
- 外部条件未确认前，不得说"可以直接发布"
- 未验证远端状态前，不得说"已成功发布"
- 涉及"删除所有 tag / 只保留 latest"时，必须：先列远端现状 → 再执行删除 → 再复查

### 五、一句话总结

> 不要提前宣布完成；没有验证证据，就只能说"已修改，待验证"。
