<!-- AGENTS.md — 供 AI Coding Agent 阅读的项目全景说明 -->

> 本文件供 AI Coding Agent 阅读。项目主要文档和注释使用中文，因此本文件以中文撰写。
> 若你修改了本文件提及的任何架构、构建流程、测试策略或安全机制，必须同步更新本文件。

---

## 项目概述

**Simple Homepage**（后台管理面板）是一个面向个人、家庭网络、NAS、软路由、小型 VPS 的自托管 **后台管理面板**，提供 DNS / DDNS / 域名有效期 / 计划任务 / Nginx 在线编辑 / 备份 / 用户 / API Token / Webhook 等运维能力。

> 历史版本曾包含"导航首页"前台（站点 / 分组 / 反向代理生成 / favicon / 健康检查 / SSH / WebDAV 等模块），现已全部移除。当前仅保留登录页，根路径 `/` 自动跳转至 `/admin/index.php`。

- **GitHub**: https://github.com/codingriver/simple-homepage
- **Docker Hub**: https://hub.docker.com/r/codingriver/simple-homepage

---

## 技术栈

| 层级 | 技术 |
|------|------|
| **后端** | PHP >= 8.2，无主流框架，纯原生 PHP 开发（过程式 + 少量工具函数） |
| **数据存储** | JSON 文件（`data/` 目录），不依赖 MySQL/Redis |
| **前端** | 原生 HTML/CSS/JS（无 React/Vue/Angular/jQuery 等现代前端框架） |
| **Web 服务器** | Nginx + PHP-FPM（Unix socket `/run/nginx/php-fpm.sock`） |
| **进程管理** | Supervisor（容器内同时管理 Nginx、PHP-FPM、Cron、Nginx-Reload-Watcher） |
| **容器化** | Docker，基于 `php:8.2-fpm-alpine`（Alpine Linux，musl libc），支持 `linux/amd64` 和 `linux/arm64` |
| **测试** | Playwright 1.54.2（E2E）、PHPUnit 11（单元）、Lighthouse CI 0.15.1（性能） |
| **包管理** | Composer（PHP）、npm（仅开发依赖） |
| **辅助脚本** | Python 3（`python/dns_core.py`）、Bash（`docker/entrypoint.sh`、`local/docker-build.sh`）；后台「运行环境」可在 `data/runtime/` 中按需安装 Node.js/npm |

---

## 关键配置文件

| 文件 | 用途 |
|------|------|
| `composer.json` | PHP 依赖管理；PHP >= 8.2；PHPUnit ^11.0 为开发依赖；`shared/` 和 `admin/shared/` 加入 classmap autoload |
| `package.json` | npm 脚本定义 E2E/性能测试命令；开发依赖仅 `@playwright/test`、`@lhci/cli`、`typescript` |
| `playwright.config.ts` | Playwright 配置：`testDir: './tests/e2e/full'`，`workers: 1`，`fullyParallel: false`，Projects: `chromium`（桌面端）和 `mobile-chrome`（Pixel 7），CI 时 `retries: 1` |
| `tsconfig.json` | TypeScript 配置：`target: ES2022`，`module: commonjs`，`strict: true`，供 Playwright 测试和配置脚本使用 |
| `docker-compose.yml` | 生产环境一键部署 Compose：官方镜像 `codingriver/simple-homepage:latest`，端口 `58080`，挂载 `./data`；运行期可透传 `HTTP_PROXY` / `HTTPS_PROXY` / `NO_PROXY` 等出站代理变量 |
| `phpunit.xml` | PHPUnit 配置：三个测试套件 `Shared` / `Admin` / `Subsite`，bootstrap 为 `tests/phpunit/bootstrap.php`，源码覆盖包含 `shared/` 和 `admin/shared/` |
| `lighthouserc.json` | Lighthouse CI 配置：检测 `login.php` 和 `index.php`，Performance >= 0.6（warn），Accessibility >= 0.85（warn），Best-practices >= 0.85（warn） |
| `Dockerfile` | 基于 `php:8.2-fpm-alpine` + Nginx + Supervisor + dcron；创建 `navwww` 用户（UID/GID 默认 1000，运行时按 data 目录 owner 对齐）；暴露 58080；Entrypoint 为 `/entrypoint.sh` |
| `docker/entrypoint.sh` | 容器启动入口：时区设置、PUID/PGID 动态对齐、NAV_PORT 注入 Nginx 配置、数据目录初始化、开发模式标记、无人值守安装（`.initial_admin.json`）、反代配置预生成、sudo 白名单设置 |
| `docker/supervisord.conf` | Supervisor 管理 4 个进程：`php-fpm`（priority 5）、`nginx`（priority 10）、`nginx-reload-watcher`（priority 15，监听 `/tmp/nginx-reload-trigger`）、`cron`（priority 20） |
| `docker/nginx.conf` / `nginx-conf/docker-site.conf` | Nginx 主配置和站点配置；站点配置含 `auth_request` 鉴权、PHP-FPM 反向代理、静态资源缓存 |
| `local/docker-compose.yml` | 本地构建专用 Compose；挂载 `data` 目录；默认端口 58080；构建期和运行期均支持代理环境变量透传 |
| `local/docker-compose.dev.yml` | 开发环境叠加配置：挂载源码实现热更新、启用 `NAV_DEV_MODE`、临时挂载 `docker.sock` |
| `local/docker-compose.test.yml` | 测试环境叠加配置：定义 `playwright-full`、`playwright-mobile`、`lighthouse` 服务 |
| `.github/workflows/docker-publish.yml` | CI 工作流：push 到 `main`/`master` 或 `v*` 标签时触发；多架构构建（`linux/amd64`, `linux/arm64`）并推送到 Docker Hub；同步 README 到 Docker Hub 描述 |
| `.github/workflows/manual-push.yml` | 手动推送工作流：支持跳过 `arm64`、支持额外指定版本标签 |

---

## 代码组织

```text
public/          # 公共入口（最小化）
  index.php      # 直接 302 跳转到 /admin/index.php
  login.php      # 登录页：CSRF、IP 锁定、记住我、开发模式提示
  setup.php      # 安装向导：首次部署引导、创建管理员
  logout.php     # 退出登录（清除 Cookie）
  auth/
    verify.php   # Nginx auth_request 鉴权端点（返回 200 + X-Auth-User/X-Auth-Role 或 401）

admin/           # 后台管理页面和 AJAX API（核心模块）
  index.php            # 后台首页（管理员/用户/备份卡片 + 快捷入口）
  users.php            # 用户管理（含角色/权限/会话上限）
  sessions.php / sessions_api.php  # 会话管理
  api_tokens.php       # API Token 颁发与撤销
  settings.php / settings_ajax.php # 系统设置
  notifications.php    # Webhook 通知配置
  dns.php / ddns.php / ddns_ajax.php # DNS / DDNS
  domain_expiry.php / domain_expiry_ajax.php # 域名注册有效期监控（RDAP 查询 + 本地缓存）
  scheduled_tasks.php  # 计划任务（含日志）
  runtime_env.php / runtime_env_ajax.php # 运行环境管理（Node.js/npm 检测、apk 安装、musl 多版本安装/切换、实时进度轮询）
  nginx.php            # Nginx / PHP-FPM / PHP 自定义参数 在线编辑器（语法校验 + 兼容回滚）
  backups.php          # 备份创建 / 恢复 / 下载 / 删除
  logs.php / logs_api.php  # 日志中心
  debug.php            # 调试工具
  api/                 # 后台专用 API（task_status / task_log 等）
  shared/              # 后台共享库
    functions.php      # 主函数库：配置读写、CSRF、备份恢复、Nginx 在线编辑、审计日志、回收站、Webhook
    header.php         # 后台页面模板头（权限校验、侧边栏、Flash Toast）
    footer.php         # 页面模板尾
    admin.css          # 后台暗色主题
    dns_lib.php / dns_api_lib.php / ddns_lib.php / domain_expiry_lib.php / cron_lib.php / runtime_env_lib.php / alidns.php 等  # 各业务领域函数库
  assets/              # Ace Editor（本地）、SortableJS（CDN）

shared/          # 核心共享库
  auth.php           # 核心认证库：JWT-like Token、Cookie、用户管理、IP 锁定、CSRF、会话撤销、权限系统
  http_client.php    # 带 SSRF 防护的 HTTP 客户端（curl 优先，fallback 到 file_get_contents）
  request_timing.php # 请求耗时日志（recv/done 双阶段，自动轮转 10MB + gzip，7 天保留）

cli/             # CLI 脚本
  run_scheduled_task.php   # 计划任务执行器（硬超时 3600s、PID 锁、僵尸锁清理）
  ddns_sync.php            # DDNS 同步
  domain_expiry_sync.php   # 域名有效期同步（刷新 RDAP 缓存）
  alidns_sync.php          # 阿里云 DNS 同步
  manage_users.php         # 用户管理 CLI（list/info/add/passwd/del/reset）

python/          # Python 辅助脚本
  dns_core.py    # DNS 核心逻辑

docker/          # Docker 构建配置
  nginx.conf / docker-site.conf / php-fpm.conf / php-custom.ini / supervisord.conf / entrypoint.sh

nginx-conf/      # Nginx 站点配置（仅 admin 后台）
  docker-site.conf

data/            # 持久化数据目录（必须挂载到宿主机）
  config.json / users.json / api_tokens.json / scheduled_tasks.json
  dns_config.json / ddns_tasks.json / notifications.json
  domain_expiry.json / domain_expiry_rdap_bootstrap.json
  ip_locks.json / sessions.json / auth_secret.key
  backups/ / logs/ / tasks/ / nginx/ / php-fpm/ / php/ / trash/

  `users.json` 用户记录字段：
  - `password_hash`（string）：bcrypt 哈希
  - `role`（string）：admin / user
  - `permissions`（string[]）：权限列表，保存时自动按角色重置
  - `max_sessions`（int）：最大同时在线设备数，默认 3
  - `blocked_ips`（string[]）：屏蔽的 IP 列表，支持单个 IP 或 CIDR
  - `blocked_domains`（string[]）：屏蔽的域名列表，支持通配符
  - `created_at` / `updated_at`（string）
  - 开发模式虚拟用户 `qatest` 的屏蔽规则存储在 `config.json` 的 `dev_account_blocked_ips` / `dev_account_blocked_domains`

tests/
  e2e/full/      # Playwright E2E 测试（已大幅精简，仅保留与后台仍存模块对应的用例）
  phpunit/       # PHPUnit 单元测试，套件：Shared / Admin / Subsite / Public / Cli / Docker
  helpers/       # auth.ts（登录/登出）、fixtures.ts、data.ts（resetVolatileAppData）、cli.ts

local/           # 本地开发环境
  docker-compose.yml / docker-compose.dev.yml / docker-compose.test.yml
  docker-build.sh / .env.example / php-dev.ini / README.md

subsite-middleware/
  auth_check.php # 历史保留的子站统一鉴权中间件（独立可复用脚本；本后台已不再生成反代）
```

### 页面与 API 的两种模式

- **后台页面**（`admin/*.php`）：遵循 `require shared/functions.php` → `POST 处理（在 header.php 之前）` → `require shared/header.php` → HTML → `require shared/footer.php`。
  - 页面开头设置 `$page_title` 和 `$page_permission`（可选，默认要求 admin 角色）。
  - `header.php` 中先调用 `csrf_token()`，再 `session_write_close()` 释放会话锁，然后输出 HTML。
- **AJAX 端点**（`*_ajax.php`、`*_api.php`）：以 `declare(strict_types=1);` 开头，校验 `HTTP_X_REQUESTED_WITH === 'XMLHttpRequest'`，权限检查后按 `action` 路由，返回统一 JSON 格式 `['ok' => bool, 'msg' => string, 'data' => ...]`。
  - 读操作通常免 CSRF；写操作必须校验 `_csrf`。
  - 未知 action 返回 404 JSON。

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

# 本地 qB 子域名代理登录回归（默认跳过，需当前代理环境可用）
RUN_QB_PROXY_E2E=1 PLAYWRIGHT_REPORTER=line npx playwright test tests/e2e/full/qb-local-proxy-login-regression.spec.ts --project=chromium --headed

# 登录 / Cookie / Session 回归（默认环境可跑）
PLAYWRIGHT_REPORTER=line npx playwright test tests/e2e/full/auth-cookie-session-regression.spec.ts --project=chromium

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
- 登录达到 `max_sessions` 上限时，超限页应使用复选框允许多选旧设备，默认选择足够数量的最久未活跃设备；POST 可携带 `kick_oldest=1` 作为兜底，服务端必须只允许踢当前登录用户自己的 session，踢下线后再生成新的登录 Token。

### 8. Nginx + PHP-FPM 死锁规避

- 在 `admin/` 等使用 `auth_request` 的 location 中，PHP 子 location 必须加 `auth_request off;`，由 PHP 自行鉴权。
- 子域名代理的未登录回跳必须同时兼容外层 HTTPS 反代和内网 HTTP 调试：每个代理 `server` 块必须设置 `absolute_redirect off;`，未登录时跳转到 `/login.php?redirect=$nav_forwarded_proto://$http_host$request_uri`。`$nav_forwarded_proto` 由外层 `X-Forwarded-Proto` 决定，未设置时回退到 `$scheme`，因此外网 HTTPS 不会暴露 `:58080`，内网 `http://*.local.303066.xyz:58080/` 仍保留 HTTP 和端口。
- 子域名代理必须提供同源 `/login.php`、`/login.css`、`/gesture-guard.js` 入口，未登录时跳转到 `/login.php?redirect=$scheme://$http_host$request_uri`。不要统一跳到 `https://nav_domain/login.php`，否则 HTTPS 登录页下发的 Secure Cookie 无法回传到 HTTP 调试入口，导致重定向循环。
- qBittorrent WebUI 代理（当前 `192.168.2.2:9097`）需要向上游发送 `$proxy_host`，并清空 `Referer` / `Origin`，否则 qB API 登录会因 Host/来源校验返回 `401 Unauthorized`。
- 通用反代参数里的 WebSocket 头必须使用 `proxy_set_header Connection $connection_upgrade;`，禁止对所有请求强制 `"upgrade"`，否则普通 JS/CSS/字体资源在多层代理下可能长时间挂起。
- Homepage 自身的 PHP Session Cookie 使用专用名称 `nav_php_session`，不要再和后端常见的 `PHPSESSID` 混用，否则代理站点会污染登录页 CSRF 会话。
- 登录 Cookie `nav_session` 在配置了 `cookie_domain` 时，需要同时写入站群 Domain Cookie 和当前 Host 的 host-only Cookie；服务端读取 Cookie 时必须兼容同名 Cookie 多值，逐个验证 token，避免旧 Domain Cookie 遮住新的本域登录态。`public/auth/verify.php` 作为 Nginx `auth_request` 入口也必须走同一套多 Cookie 验证逻辑，不能直接读 `$_COOKIE[SESSION_COOKIE_NAME]`。
- 登录成功后先跳同源 `/login.php?complete=1&redirect=...`，由 200 HTML 完成页二次写入 `nav_session` 后再跳目标地址，避免部分浏览器在 302 后立刻进入代理鉴权时还未稳定带回新 Cookie。
- `auth_request` 失败必须写入 `AUTH_DENY` 登录日志并包含 `reason=...`，便于区分 `no_cookie`、`malformed`、`bad_signature`、`expired`、`session_missing`、`blocked_ip`、`blocked_domain` 等原因。
- `data/sessions.json` 是 `auth_request` 高频读写文件，所有读取必须使用共享锁，所有注册、撤销、清理、touch 必须在独占锁内完成，禁止无锁 `file_get_contents(SESSIONS_FILE)` / 快照写回；`last_active` 应限频更新，避免代理站点大量静态资源并发请求时把有效登录误判为 `session_missing`。
- 站点代理不再依赖一份“万能 full 参数”覆盖所有场景；`sites.json` 中的代理站点应支持 `proxy_profile`（如 `default` / `qbittorrent` / `spa` / `synology_dsm` / `media` / `websocket`），生成器需按 profile 注入专用头与静态资源 location。默认 profile 仍保持兼容旧站点的自动推断。
- 站点可在 `sites.json` 中保存测试凭据字段 `credential_username` / `credential_password` / `credential_note`，当前按产品要求明文保存，并随导入、导出、备份、恢复一起保留。后台页面可回显给管理员编辑；公共 `public/api/sites.php` 返回站点数据时必须调用 `sites_strip_credentials()` 移除这些字段，避免 API Token 消费端拿到明文密码。
- 真实浏览器回归时应同时验收：外网域名、内网直连目标、登录后页面、静态资源是否仍被误导到 `/login.php`、以及是否存在后端写死 `127.0.0.1` 之类的应用配置问题；这类问题要在诊断报告中与反代问题分开标记。
- 后台提供 `admin/proxy_diagnose.php` 作为单站点代理诊断接口，返回目标 URL、代理 URL、profile、资源采样和问题列表；同一接口支持 `action=browser` 触发真实浏览器诊断。若容器内没有 Node.js / Playwright，后台必须给出宿主机可执行命令，不能让诊断卡死或报含糊错误。本地浏览器级诊断脚本位于 `scripts/proxy_browser_diagnose.js`，用于捕获运行时 JS 请求失败、慢资源和控制台错误，并支持复用当前 `nav_session` Cookie。
- PHPUnit 测试 Nginx 配置生成时必须通过 `NAV_NGINX_CONF_D_DIR` / `NAV_NGINX_HTTP_D_DIR` 指向临时目录，禁止写入真实 `/etc/nginx/conf.d` 或 `/etc/nginx/http.d`。
- 反向代理生成配置以 `data/nginx` 为唯一持久化源：`data/nginx/conf.d/nav-proxy.conf` 和 `data/nginx/http.d/nav-proxy-domains.conf`。容器启动时将 `/etc/nginx/conf.d/nav-proxy.conf`、`/etc/nginx/http.d/nav-proxy-domains.conf` 软链到上述文件；禁止让 `/etc/nginx` 与 `data/nginx` 形成两份可写配置。

### 9. 计划任务健壮性

- 任务执行必须设置**硬超时**（默认 3600s），防止用户脚本死循环挂起 PHP 进程。
- 任务结果写入 `scheduled_tasks.json` 时必须使用 **`flock(LOCK_EX)`** 保护，防止并发结果覆盖。
- 任务执行锁文件必须记录 PID，并支持**僵尸锁自动清理**（OOM/SIGKILL 场景）。
- 手动计划任务支持 `shell` / `php` / `python` / `nodejs` / `custom` 运行类型；旧任务默认 `shell`，保持兼容。
- 数字 ID 任务使用独立目录 `data/tasks/task_{id}/`，入口文件按运行类型区分：`run.sh` / `main.php` / `main.py` / `main.mjs`；日志固定为 `run.log`。
- 启用「执行前安装依赖」时，Node.js 任务只在当前任务目录处理 `package.json`（`node_modules/`、`.npm-cache/`、`install.log`），Python 任务只在当前任务目录处理 `requirements.txt`（`.venv/`、`install.log`），禁止写入全局项目依赖。
- `runtime_env_lib.php` 管理 `data/runtime/node/versions/` 与 `data/runtime/node/current`，计划任务执行时会将当前 Node.js 版本的 `bin` 目录注入 `PATH`。
- Node.js 安装由 `cli/runtime_env_job.php` 后台执行，job 状态写入 `data/runtime/jobs/*.json`，日志写入 `data/runtime/jobs/*.log`；前端必须轮询 `runtime_env_ajax.php?action=job_status` 展示阶段、百分比、下载大小、安装日志和失败建议。
- Docker 容器按产品要求允许 `navwww` 免密 sudo 执行所有命令，用于后台安装运行环境；相关错误必须在页面展示命令、退出码、stdout/stderr 和建议。

### 10. Ace Editor 作为项目默认文本编辑器

- **Ace Editor 是项目默认的多行文本编辑器**。所有涉及多行文本输入/编辑的场景，**内容预期超过 5 行时**，必须使用 Ace Editor 弹窗打开；5 行及以内的短文本可直接使用原生 `<textarea>`，无需强制接入 Ace Editor。
- **统一入口：所有后台页面调用文本编辑器时，必须使用 `admin/shared/ace_editor_modal.php` 提供的 `NavAceEditor` 接口**。禁止各页面自行编写 Ace 初始化代码、弹窗 HTML、按钮 HTML。页面只需在加载 `ace.js` 和 `ext-searchbox.js` 后引入 `admin/shared/ace_editor_modal.php`，然后调用 `NavAceEditor.open({...})` 即可。
- **判断标准**：按字段的业务语义判断（如 Nginx 配置、计划任务脚本、JSON/YAML 导入等必然超过 5 行）；若无法确定，默认走 Ace Editor 弹窗。
- **统一资源引用**：`<script src="assets/ace/ace.js"></script>`，`admin/assets/ace/` 目录已包含 ace.js、ext-searchbox.js、mode-nginx.js、theme-tomorrow_night.js 等核心文件。
- **统一基础配置**：
  - 默认主题：`ace/theme/tomorrow_night`
  - Tab 大小：2，使用 soft tabs
  - 默认开启自动换行
  - 字号：13–14px
  - 关闭 print margin、autocompletion、snippets
- **弹窗交互规范**：
  - 点击「打开编辑器」按钮后弹窗展示 Ace Editor
  - 弹窗内提供语言模式切换、主题切换、字号调整、查找(Ctrl+F)、跳转行号(Ctrl+G)、自动换行开关
  - 支持沉浸模式（全屏编辑器）
  - 编辑完成后将内容同步回隐藏的 `<textarea>` 或表单字段，再由表单提交
- **工具栏按钮区域统一配置接口**：
  - **所有按钮统一渲染在弹窗顶部工具栏左侧**，禁止在工具栏右侧或底部操作区新增按钮。
  - 支持配置多个按钮，通过统一接口配置，禁止各页面硬编码按钮 HTML。
  - 按钮配置格式：
    ```javascript
    NavAceEditor.open({
      // ... 其他配置
      buttons: {
        left: [
          { type: 'dirty' },                       // 自动脏标记，无需手动管理
          { text: '检查语法', action: 'syntax', visible: canSyntax }
        ],
        right: [
          { text: '关闭', action: 'close' },
          { text: '保存', action: 'save' },
          { text: '保存并 Reload', action: 'save_reload' },
          { text: '删除', action: 'delete', visible: canDelete }
        ]
      },
      onAction: function(action, value) {
        if (action === 'save') { /* 处理保存 */ }
        if (action === 'close') { NavAceEditor.close(); }
      }
    });
    ```
  - **接口能力说明**：
    1. **`type: 'dirty'`**：自动监听编辑器内容变化，显示「未修改 / 有未保存修改」状态，无需页面手动实现。
    2. **`text`**：按钮显示文本。
    3. **`action`**：按钮标识符，点击后触发 `onAction(action, currentValue)` 回调。保留关键字：`save`（Ctrl-S 自动绑定）、`close`（Esc/关闭弹窗时触发）。
    4. **`visible`**：布尔值或返回布尔值的函数，控制按钮是否渲染（用于权限控制，如「删除」仅管理员可见）。
    5. **`disabled`**：布尔值或返回布尔值的函数，控制按钮是否禁用。
    6. **`onAction`**：统一回调接口，所有按钮点击（含快捷键触发的保存）都通过此回调分发，页面在此处理业务逻辑（AJAX 提交、表单提交、关闭弹窗等）。
    7. **`class`（向后兼容）**：保留 `btn-primary` / `btn-secondary` / `btn-danger` 等语义类，新写页面不建议继续使用。
    8. **`bgColor`**（推荐）：自定义按钮背景色，**新增按钮只允许通过 `bgColor` 自定义颜色，不允许自定义其他样式**。例如 `{ text: '自定义', action: 'custom', bgColor: '#4a9eff' }`。
  - **样式规则**：工具栏按钮统一使用 `.nav-ace-toolbar-btn` 基础样式（padding、字号、圆角、边框等完全一致），**只允许通过 `bgColor` 属性改变背景色，禁止通过 `class` 传入自定义样式类改变按钮外观**。
  - **各页面典型按钮组合示例**：
    - **纯编辑保存**（如自定义 CSS）：`[dirty, 关闭, 保存]`
    - **编辑 + 语法检查**（如 Nginx 配置）：`[dirty, 检查语法, 关闭, 保存, 保存并 Reload]`
    - **文件管理**（如 files.php）：`[dirty, 关闭, 下载, 删除, 保存]`
    - **只读查看**（如 logs.php）：`[关闭]`
- **参考实现**：`admin/files.php` 中的文件管理器弹窗编辑器（`fm-editor-modal`）和 `admin/nginx.php` 中的 Nginx 配置编辑器弹窗。
- **待改造清单**（当前仍使用原生 `<textarea>`，需逐步替换为 Ace Editor 弹窗）：
  - `scheduled_tasks.php`：计划任务命令脚本
  - `settings.php`：自定义 CSS、文件系统允许根目录
  - `dns.php`：DNS JSON 批量导入
  - `manifests.php`：Manifest YAML/JSON 编辑
  - `configs.php`：系统配置编辑
- **保持现状（≤5 行短文本，无需改造）**：
  - `sites.php`：站点备注（3 行）

#### 10.1 NavAceEditor 统一封装接口完整规范

**设计原则**：各页面接入 Ace Editor 时，**禁止自行编写 Ace 初始化代码、弹窗 HTML、按钮 HTML**。全部通过 `NavAceEditor` 全局对象调用统一接口完成。

---

##### 一、全局对象 `NavAceEditor`

```javascript
// 全局单例，所有页面共用同一套 Ace Editor 实例和弹窗 DOM
var NavAceEditor = {
  editor: null,      // ace.edit() 返回的编辑器实例
  modal: null,       // 弹窗 DOM 元素
  config: {},        // 当前打开时的配置快照
  initialValue: '',  // 打开时的初始内容（用于脏标记对比）
  dirty: false,      // 当前脏状态
};
```

---

##### 二、方法清单

| 方法 | 返回值 | 说明 |
|------|--------|------|
| `NavAceEditor.init(options?)` | `void` | **懒加载初始化**。首次调用时创建 Ace Editor 实例并渲染弹窗 DOM；重复调用无操作。通常在页面加载后静默调用，或在首次 `open()` 时自动触发。 |
| `NavAceEditor.open(options)` | `void` | **打开弹窗**。根据 `options` 配置渲染标题、内容、按钮、工具栏，然后显示弹窗并聚焦编辑器。 |
| `NavAceEditor.close()` | `void` | **关闭弹窗**。隐藏弹窗、退出沉浸模式、触发 `onClose` 回调。若内容已修改且未保存，弹出 `beforeunload` 风格确认框（可通过 `confirmOnClose: false` 关闭）。 |
| `NavAceEditor.getValue()` | `string` | 获取当前编辑器内容。 |
| `NavAceEditor.setValue(text, mode?)` | `void` | 设置编辑器内容，可选同时切换语言模式。自动重置脏标记为「未修改」。 |
| `NavAceEditor.isDirty()` | `boolean` | 判断当前内容是否与打开时的初始内容不同。 |
| `NavAceEditor.markClean()` | `void` | 将当前内容设为新的「基准内容」，脏标记重置为「未修改」。通常在保存成功后调用。 |
| `NavAceEditor.setMode(mode)` | `void` | 动态切换语言模式（如 `nginx`、`json`、`sh`、`php`、`yaml`、`css` 等）。 |
| `NavAceEditor.setTheme(theme)` | `void` | 动态切换主题，并持久化到 `localStorage`。 |
| `NavAceEditor.setFontSize(px)` | `void` | 动态切换字号（如 `13`、`14`、`16`），并持久化到 `localStorage`。 |
| `NavAceEditor.setWrapMode(on)` | `void` | 动态开关自动换行，并持久化到 `localStorage`。 |
| `NavAceEditor.focus()` | `void` | 将焦点移入编辑器。 |
| `NavAceEditor.resize()` | `void` | 触发编辑器重新计算尺寸（弹窗动画、窗口大小变化后自动调用，页面通常无需手动调用）。 |
| `NavAceEditor.setButtonDisabled(action, disabled)` | `void` | 动态启用/禁用指定 `action` 的按钮（如保存提交中禁用「保存」按钮防止重复提交）。 |
| `NavAceEditor.setButtonVisible(action, visible)` | `void` | 动态显示/隐藏指定 `action` 的按钮。 |

---

##### 三、`NavAceEditor.open(options)` 完整配置项

```javascript
NavAceEditor.open({
  // ━━ 弹窗基础 ━━
  title: '文本编辑器',           // 弹窗标题，显示在顶部标题栏
  value: '',                    // 编辑器初始内容（字符串）
  placeholder: '',              // 占位提示文本（编辑器为空时显示）
  readOnly: false,              // 是否只读。true 时隐藏保存按钮、禁用编辑、不显示脏标记
  confirmOnClose: true,         // 关闭弹窗时，若内容有未保存修改，是否弹出确认提示

  // ━━ 编辑器配置 ━━
  mode: 'text',                 // 语言模式：text / nginx / json / yaml / sh / php / css / javascript / markdown / xml / sql / ini
  theme: 'tomorrow_night',      // 主题：tomorrow_night / monokai / github_dark / dracula
  fontSize: 14,                 // 字号：12 ~ 20
  wrapMode: true,               // 是否自动换行
  tabSize: 2,                   // Tab 宽度（通常固定为 2，不建议页面覆盖）
  useSoftTabs: true,            // 是否使用空格代替 Tab（通常固定为 true）
  showPrintMargin: false,       // 是否显示打印边距线（通常固定为 false）
  useWorker: false,             // 是否启用 Ace Worker（通常固定为 false，避免大文件卡顿）

  // ━━ 按钮配置 ━━
  buttons: {
    left:  [],                  // 工具栏按钮数组（脏标记 + 辅助操作，渲染在工具栏左侧）
    right: []                   // 工具栏按钮数组（主操作按钮，同样渲染在工具栏左侧）
  },

  // ━━ 回调函数 ━━
  onAction: function(action, value) {},   // 按钮点击/快捷键统一回调
  onChange: function(value, dirty) {},    // 内容变化回调（每次输入触发）
  onClose: function() {},                 // 弹窗关闭回调（无论是否保存都触发）
  onInit: function(editor) {}             // 编辑器初始化完成回调（首次 init 时触发一次）
});
```

---

##### 四、按钮配置项（`buttons.left[i]` / `buttons.right[i]`）

```javascript
{
  // 方式一：特殊类型按钮（目前仅支持脏标记）
  type: 'dirty',                // 设置为脏标记后，其他字段无效

  // 方式二：普通操作按钮
  text: '保存',                 // 按钮显示文本
  action: 'save',               // 按钮动作标识符，点击后传递给 onAction(action, value)
  bgColor: '#4a9eff',           // 自定义背景色（推荐方式，新增按钮只允许改背景色）
  class: 'btn-primary',         // 向后兼容：语义样式类（btn-primary / btn-secondary / btn-danger）
  visible: true,                // 是否渲染。支持 boolean 或返回 boolean 的函数 () => canWrite
  disabled: false               // 是否禁用。支持 boolean 或返回 boolean 的函数 () => isSaving
}
```

**按钮配置项详细说明**：

| 字段 | 类型 | 必填 | 默认值 | 说明 |
|------|------|------|--------|------|
| `type` | `string` | 否 | — | 特殊类型。目前仅 `'dirty'`：自动监听内容变化，显示「未修改 / 有未保存修改」。设置 `type` 后忽略 `text`/`action`/`class` 等字段。 |
| `text` | `string` | 是* | — | 按钮显示文本（`*type='dirty' 时不需要`）。 |
| `action` | `string` | 是* | — | 按钮动作标识符（`*type='dirty' 时不需要`）。点击后调用 `onAction(action, NavAceEditor.getValue())`。保留关键字：`save`（自动绑定 Ctrl-S）、`close`（自动绑定 Esc 和弹窗关闭）。 |
| `bgColor` | `string` | 否 | — | **新增按钮推荐方式**。自定义按钮背景色（如 `#4a9eff`、`rgba(61,255,160,.2)`）。设置后按钮使用统一基础样式，仅背景色生效。 |
| `class` | `string` | 否 | `btn-secondary` | **向后兼容**。语义样式类：`btn-primary` / `btn-secondary` / `btn-danger` / `btn-success` / `btn-warning`。新写页面请优先使用 `bgColor`，避免传入自定义样式类。 |
| `visible` | `boolean \| function` | 否 | `true` | 控制按钮是否渲染。`false` 时不渲染该按钮；支持传入函数动态判断，如 `visible: () => canDelete`。 |
| `disabled` | `boolean \| function` | 否 | `false` | 控制按钮是否禁用（置灰不可点击）。支持布尔值或函数，如 `disabled: () => isSubmitting`。 |

---

##### 五、内置自动行为（页面无需手动处理）

| 行为 | 触发条件 | 说明 |
|------|---------|------|
| **Ctrl-S 保存** | 按下 Ctrl+S（Win）/ Cmd+S（Mac） | 自动触发 `onAction('save', value)`。若 `buttons.right` 中不存在 `action: 'save'` 的按钮，则该快捷键不生效。 |
| **Esc 关闭弹窗** | 按下 Esc 键 | 自动触发 `onAction('close', value)` 然后关闭弹窗。若 `buttons.right` 中不存在 `action: 'close'` 的按钮，则直接关闭弹窗。 |
| **Ctrl-F 查找** | 按下 Ctrl+F | Ace 内置查找框（需加载 `ext-searchbox.js`）。 |
| **Ctrl-G 跳转行号** | 按下 Ctrl+G | Ace 内置跳转到指定行（需加载 `ext-searchbox.js`）。 |
| **沉浸模式快捷键** | 无（通过工具栏 checkbox 切换） | 勾选「沉浸模式」后隐藏标题栏和工具栏，编辑器占满弹窗。显示「退出沉浸模式」按钮。 |
| **脏标记自动更新** | 编辑器内容变化时 | 若配置了 `{ type: 'dirty' }`，自动对比当前值与 `initialValue`，显示「未修改」或「有未保存修改」。 |
| **关闭前确认** | 点击关闭 / 按 Esc / 点击蒙层 | 若 `confirmOnClose: true` 且 `isDirty()` 为 true，弹出浏览器确认框防止误关。 |
| **localStorage 持久化** | 用户切换主题/字号/换行 | 自动将用户偏好写入 `localStorage`，下次打开弹窗时恢复。key 为 `nav-ace-theme`、`nav-ace-fontsize`、`nav-ace-wrap`。 |
| **弹窗打开时自动聚焦** | `open()` 被调用后 | 弹窗显示完成后自动将光标移入编辑器。 |
| **窗口大小变化自适应** | 浏览器 resize | 自动调用 `editor.resize()`，无需页面处理。 |

---

##### 六、生命周期

```
页面加载
  → NavAceEditor.init()         // 可选：页面可提前初始化，也可由首次 open() 自动触发
       → 创建 Ace 实例（若不存在）
       → 渲染弹窗 DOM（若不存在）
       → 绑定工具栏事件、快捷键、窗口 resize 监听
       → 从 localStorage 恢复用户偏好（主题、字号、换行）
       → 触发 onInit(editor)

用户点击「打开编辑器」
  → NavAceEditor.open(options)
       → 根据 options 渲染标题、按钮、工具栏状态
       → 设置编辑器内容、语言模式
       → 记录 initialValue（脏标记基准）
       → 显示弹窗
       → 聚焦编辑器

用户编辑内容
  → Ace 'change' 事件
       → 自动更新 dirty 状态
       → 触发 onChange(value, dirty)

用户点击按钮 / 快捷键
  → 识别 action
       → 触发 onAction(action, value)
       → 页面在回调中处理业务（AJAX / Form 提交 / 关闭弹窗等）

用户关闭弹窗
  → NavAceEditor.close()
       → 若有未保存修改且 confirmOnClose: true，弹出确认
       → 隐藏弹窗、退出沉浸模式
       → 触发 onClose()
```

---

##### 七、完整接入示例

**场景 1：Nginx 配置编辑（Form 桥接）**

```javascript
function openNginxEditor(targetContent) {
  NavAceEditor.open({
    title: '编辑 Nginx 配置',
    mode: 'nginx',
    value: targetContent,
    buttons: {
      left:  [
        { type: 'dirty' },
        { text: '检查语法', class: 'btn-secondary', action: 'syntax' }
      ],
      right: [
        { text: '关闭', class: 'btn-secondary', action: 'close' },
        { text: '保存', class: 'btn-secondary', action: 'save' },
        { text: '保存并 Reload', class: 'btn-primary', action: 'save_reload' }
      ]
    },
    onAction: function(action, value) {
      if (action === 'close') {
        NavAceEditor.close();
        return;
      }
      // 将内容同步回隐藏的 textarea，然后提交表单
      document.getElementById('nginx-editor-content').value = value;
      document.getElementById('nginx-editor-action').value = action;
      document.getElementById('nginx-editor-form').submit();
    }
  });
}
```

**场景 3：计划任务脚本（有权限控制 + 提交中禁用按钮）**

```javascript
function openTaskEditor(initialScript) {
  NavAceEditor.open({
    title: '编辑计划任务脚本',
    mode: 'sh',
    value: initialScript,
    buttons: {
      left:  [{ type: 'dirty' }],
      right: [
        { text: '关闭', class: 'btn-secondary', action: 'close' },
        { text: '保存', class: 'btn-primary', action: 'save', disabled: false }
      ]
    },
    onAction: function(action, value) {
      if (action === 'save') {
        NavAceEditor.setButtonDisabled('save', true);   // 禁用保存按钮防止重复提交
        fetch('/admin/scheduled_tasks_ajax.php', {
          method: 'POST',
          headers: { 'X-Requested-With': 'XMLHttpRequest' },
          body: new URLSearchParams({ action: 'update_command', command: value, _csrf: window._csrf })
        }).then(r => r.json()).then(data => {
          NavAceEditor.setButtonDisabled('save', false); // 恢复保存按钮
          if (data.ok) {
            NavAceEditor.markClean();
            showToast('脚本已保存', 'success');
          } else {
            showToast(data.msg || '保存失败', 'error');
          }
        });
      }
      if (action === 'close') {
        NavAceEditor.close();
      }
    }
  });
}
```

**场景 4：日志查看（只读）**

```javascript
function openLogViewer(logContent, logName) {
  NavAceEditor.open({
    title: '日志查看 · ' + logName,
    mode: 'text',
    value: logContent,
    readOnly: true,
    wrapMode: true,
    buttons: {
      left:  [],
      right: [{ text: '关闭', class: 'btn-secondary', action: 'close' }]
    },
    onAction: function(action) {
      if (action === 'close') NavAceEditor.close();
    }
  });
}
```

---

##### 八、与现有三个页面的对照

| 功能点 | files.php 现状 | nginx.php 现状 | logs.php 现状 | 统一接口后 |
|--------|---------------|---------------|--------------|-----------|
| 编辑器初始化 | 独立 20+ 行 | 独立 15+ 行 | 独立 15+ 行 | `NavAceEditor.init()` 一行 |
| 弹窗 open | `openFileEditor()` 内联 | `openEditorModal()` 内联 | 非弹窗 | `NavAceEditor.open({...})` |
| 弹窗 close | `closeFmEditorModal()` 内联 | `closeEditorModal()` 内联 | — | `NavAceEditor.close()` |
| 脏标记 | `syncAceDirty()` 内联 | `sync()` 内联 | 不需要 | `{ type: 'dirty' }` 自动 |
| 主题切换 | 独立事件监听 + localStorage | 独立事件监听 + localStorage | 无 | 工具栏自动处理 |
| 字号切换 | 独立事件监听 + localStorage | 独立事件监听 + localStorage | 无 | 工具栏自动处理 |
| 换行切换 | 独立事件监听 | 独立事件监听 | 无 | 工具栏自动处理 |
| 沉浸模式 | `applyFocusMode()` 内联 | `applyFocusMode()` 内联 | 无 | 工具栏自动处理 |
| 按钮 HTML | 硬编码在页面 | 硬编码在页面 | 无 | `buttons: { left, right }` 配置 |
| Ctrl-S | `saveFile()` 回调 | 模拟点击 Save 按钮 | 无 | `onAction('save', value)` 自动 |
| Esc | `closeFmEditorModal()` | `closeEditorModal()` | 无 | `onAction('close', value)` 自动 |
| beforeunload | ❌ | ✅ | ❌ | `confirmOnClose: true` 配置 |

---

##### 九、实现文件规划

| 文件 | 说明 |
|------|------|
| `admin/shared/ace_editor_modal.php` | 包含弹窗 HTML 模板 + `NavAceEditor` JS 实现。各页面通过 `require __DIR__ . '/shared/ace_editor_modal.php'` 引入。 |
| `admin/shared/admin.css` | 已包含 `.ngx-modal`、`.ngx-editor-*` 等样式，无需新增 CSS。`nginx.php` 中的内联重复 CSS 应删除。 |
| `admin/assets/ace/ace.js` | 已有本地资源。 |
| `admin/assets/ace/ext-searchbox.js` | 已有本地资源（查找/跳转功能依赖）。 |

---

##### 十、使用 NavAceEditor 作为日志查看器的规范

当把 `NavAceEditor` 用作**只读日志查看器**（如 `scheduled_tasks.php` 的运行日志、`logs.php` 的 Ace 弹窗模式）时，**禁止各页面自行编写分页逻辑或底部 HTML**，必须使用组件内置的 `pagination` 配置，确保所有页面的分页界面、交互行为完全一致：

1. **分页栏由组件自动渲染，页面只提供数据**
   - 配置 `pagination` 对象，组件自动在弹窗底部渲染分页栏（信息区、limit 选择器、翻页按钮、页码输入框、跳转按钮、刷新按钮）。
   - 页面**禁止**使用 `footerHtml`（该参数已移除），也禁止自行创建分页 DOM。

2. **`pagination` 配置必须包含的字段**
   ```javascript
   pagination: {
     page: 1,                        // 当前页码
     pages: 1,                       // 总页数
     limit: 100,                     // 每页行数
     limitOptions: [50, 100, 200],   // 可选的 limit 值
     totalLines: 0,                  // 总行数
     fetch: function(page, limit) {  // 返回 Promise，resolve { lines, page, pages, limit, totalLines }
       return fetch('/api/...?page=' + page + '&limit=' + limit)
         .then(r => r.json())
         .then(d => ({
           lines: d.lines || [],
           page: d.page || page,
           pages: d.total_pages || 1,
           limit: d.limit || limit,
           totalLines: d.total_lines || 0
         }));
     }
   }
   ```

3. **数据获取与状态更新由组件内部处理**
   - 组件调用 `fetch()` 后，自动执行 `editor.setValue()` 和 `updatePaginationState()`。
   - 页面无需手动更新页码标签、按钮禁用态或滚动位置——组件在刷新时会自动保存并恢复光标行号和滚动条位置。

4. **参考实现**
   - 以 `scheduled_tasks.php` 的 `openLogModal()` 和 `logs.php` 的 `openAceLogViewer()` 为模板。
   - 复制时只允许改 **API 地址** 和 **变量名**，不允许改 `pagination` 的结构、删字段、改交互逻辑。

5. **关闭弹窗统一使用标题栏 ×、蒙层点击或 Esc 键**
   - 不要在 `buttons` 或页面中额外实现关闭按钮，避免与公共组件的关闭逻辑冲突。

---

#### 10.2 改造优先级建议

1. **P0（先封装）**：实现 `admin/shared/ace_editor_modal.php` 统一接口，将 `files.php`、`nginx.php`、`logs.php` 迁移到统一接口，验证稳定性。
2. **P1（再迁移）**：改造 `scheduled_tasks.php`、`manifests.php`。
3. **P2（最后）**：改造 `settings.php`（2 处）、`dns.php`、`configs.php`。`sites.php` 保持 `<textarea>` 不变。

---

## 测试策略

### Playwright E2E

- **测试目录**: `tests/e2e/full/`
- **规模**: 171 个 spec 文件，覆盖所有主要功能模块
- **项目配置**: `playwright.config.ts`
  - `workers: 1`，`fullyParallel: false`（串行执行，避免状态冲突）
  - 默认 projects: `chromium`（桌面端）、`mobile-chrome`（移动端 Pixel 7）
  - 失败时保留 `trace`，失败时截图，视频默认关闭
  - CI 环境下 `retries: 1`
- **测试规范**: 必须遵守 `docs/测试用例编写规范.md` 中的维度清单（权限、异常、边界、状态、响应式、数据一致性等）。
- **数据隔离**:
  - `tests/helpers/fixtures.ts` 扩展 Playwright base test，在每个测试前自动调用 `resetVolatileAppData()`。
  - `auth-cookie-session-regression.spec.ts` 覆盖登录完成页、旧 Cookie、max session 多选下线、`kick_oldest` 兜底、`auth_request` 失败日志、服务端 session 被撤销后重新登录、刷新与新标签页。
  - `qb-local-proxy-login-regression.spec.ts` 是面向当前局域网 qB 代理的显式本地回归，默认跳过；仅在设置 `RUN_QB_PROXY_E2E=1` 或 `QB_PROXY_URL` 时运行，测试只清理 `sessions.json` / `ip_locks.json`，避免重置站点配置。
  - `resetVolatileAppData()` 保留 `config.json`、`users.json`、`.installed`，重置 `sites.json` 为空分组、清空日志和备份、重置各类任务/会话等 JSON。
  - 创建型数据需使用唯一值（`Date.now()` 时间戳），禁止测试间残留数据依赖。
  - 修改全局配置/文件后需在 `try/finally` 中回滚。
- **定位策略**: 优先使用 `getByRole` / `getByLabel`，其次稳定 `id/name`，禁止把 `waitForTimeout` 当作主要同步手段。
- **认证辅助**: `tests/helpers/auth.ts` 提供 `loginAsDevAdmin(page)`，自动尝试 `qatest/qatest2026` 或 `admin/Admin@test2026`。
- **CLI 辅助**: `tests/helpers/cli.ts` 提供 `runDockerPhp()`、`runDockerShell()`、`snapshotContainerFiles()` / `restoreContainerFiles()` 等封装。

### PHPUnit

- **配置文件**: `phpunit.xml`
- **测试套件**: `Shared`、`Admin`、`Subsite`
- **包含源码**: `shared/`、`admin/shared/`
- **Bootstrap**: `tests/phpunit/bootstrap.php`（创建临时 `DATA_DIR`，测试结束后自动清理）
- **隔离方式**: 每个测试类的 `setUp()` 中手动 `unlink()` 相关 JSON 文件，确保零残留。
- **当前覆盖**: 9 个测试类
  - `Shared` 套件（4 个）：`AuthTest`、`NotifyTest`、`RequestTimingTest`、`SessionManagementTest`
  - `Admin` 套件（5 个）：`ApiTokenTest`、`AuditLogTest`、`HealthCheckTest`、`SharedFunctionsTest`、`ThemeConfigTest`
  - `Subsite` 套件：当前暂无测试类
  - 涵盖范围：Token 生成验证、密码哈希、用户生命周期、IP 锁定、备份创建/恢复、Nginx 配置生成、API Token、审计日志、主题配置等。

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

- `ip_locks.json` — IP 登录失败锁定
- `sessions.json` — 会话撤销记录
- `auth_secret.key` — 认证密钥（权限 600）
- `backups/` — 备份快照
- `logs/` — 各类日志
- `tasks/` — 计划任务目录；数字 ID 任务在 `task_{id}/` 下保存入口脚本、`run.log`、依赖目录和 `install.log`
- `runtime/` — 后台安装的运行环境，例如 `runtime/node/versions/` 与 `runtime/node/current`
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
3. **docker.sock 挂载**: 仅在需要 Docker 管理功能时临时挂载，完成后建议移除。
5. **密钥管理**: `AUTH_SECRET_KEY` 优先从环境变量读取，否则自动生成并保存到 `data/auth_secret.key`（权限 600）。
6. **SSRF 防护**: 所有根据用户输入发起外部 HTTP 请求的代码必须经过目标地址安全校验（禁止内网、回环地址）。
7. **Cookie 安全降级**: 代码内置自动降级逻辑——用 IP 访问时自动设置 `secure=false, domain=空`，保证内网 IP 访问始终可登录。
8. **无人值守安装安全**: 使用 `ADMIN`/`PASSWORD` 环境变量完成首次安装后，应用会自动删除 `data/.initial_admin.json`。生产环境不应长期保留明文密码。

---

---

## 已知问题与设计缺陷（必读）

以下问题已在 `docs/项目问题分析与设计缺陷.md` 中记录，修改相关代码时需特别注意：

- **P0**: `subsite-middleware/auth_check.php` 中 `_nav_token` Cookie 写入/URL 清理逻辑位于 `exit` 之后，正常流程下不可达。
- **P1**: `public/index.php` 体积过大（880+ 行），承担职责过多；`admin/shared/functions.php` 中的 `admin_run_command()` 缺少超时控制。
- **P1**: Webhook HTTP 请求逻辑存在重复代码，未统一收敛到 `shared/http_client.php`。
- **P2**: 缺少统一异常处理层；配置读取缺少统一抽象；权限粒度较粗；测试层对 `shared/auth.php`、`shared/request_timing.php` 缺少底层单元测试。

---

## 参考文档

| 文档 | 位置 | 内容 |
|------|------|------|
| PHP 开发注意事项 | `docs/PHP开发注意事项.md` | HTTP Header 顺序、Session、CSRF、XSS、SSRF、文件安全、Nginx 死锁、JSON 存储、密码安全、PRG 模式等 |
| 测试用例编写规范 | `docs/测试用例编写规范.md` | 界面测试维度清单（A~R）、Playwright 约束、选择器稳定性、断言层次、数据隔离、spec 拆分标准 |
| 项目问题分析与设计缺陷 | `docs/项目问题分析与设计缺陷.md` | 已知 P0/P1/P2 级问题与风险点 |
| 技术架构与实现原理 | `docs/技术架构与实现原理.md` | 系统架构、数据流、模块关系 |
| Docker 部署文档 | `docs/Docker部署文档.md` | 生产部署步骤与参数说明 |
| Full-E2E 测试教程 | `docs/Full-E2E测试教程-本地环境.md` / `docs/Full-E2E测试教程-Docker环境.md` | E2E 测试环境搭建指南 |

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
