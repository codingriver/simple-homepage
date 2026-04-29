<!-- AGENTS.md — 供 AI Coding Agent 阅读的项目全景说明 -->

> 本文件供 AI Coding Agent 阅读。项目主要文档和注释使用中文，因此本文件以中文撰写。
> 若你修改了本文件提及的任何架构、构建流程、测试策略或安全机制，必须同步更新本文件。

---

## 项目概述

**Simple Homepage**（私有导航首页）是一个面向个人、家庭网络、NAS、软路由、小型 VPS 的自托管导航面板。它不只是书签页，还集成了站点/分组管理、反向代理入口、DNS 管理、DDNS 动态解析、计划任务、配置备份与恢复、Webhook 通知等能力。

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
| **辅助脚本** | Python 3（`python/dns_core.py`）、Bash（`docker/entrypoint.sh`、`local/docker-build.sh`） |

---

## 关键配置文件

| 文件 | 用途 |
|------|------|
| `composer.json` | PHP 依赖管理；PHP >= 8.2；PHPUnit ^11.0 为开发依赖；`shared/` 和 `admin/shared/` 加入 classmap autoload |
| `package.json` | npm 脚本定义 E2E/性能测试命令；开发依赖仅 `@playwright/test`、`@lhci/cli`、`typescript` |
| `playwright.config.ts` | Playwright 配置：`testDir: './tests/e2e/full'`，`workers: 1`，`fullyParallel: false`，Projects: `chromium`（桌面端）和 `mobile-chrome`（Pixel 7），CI 时 `retries: 1` |
| `tsconfig.json` | TypeScript 配置：`target: ES2022`，`module: commonjs`，`strict: true`，供 Playwright 测试和配置脚本使用 |
| `docker-compose.yml` | 生产环境一键部署 Compose：官方镜像 `codingriver/simple-homepage:latest`，端口 `58080`，挂载 `./data` |
| `phpunit.xml` | PHPUnit 配置：三个测试套件 `Shared` / `Admin` / `Subsite`，bootstrap 为 `tests/phpunit/bootstrap.php`，源码覆盖包含 `shared/` 和 `admin/shared/` |
| `lighthouserc.json` | Lighthouse CI 配置：检测 `login.php` 和 `index.php`，Performance >= 0.6（warn），Accessibility >= 0.85（warn），Best-practices >= 0.85（warn） |
| `Dockerfile` | 基于 `php:8.2-fpm-alpine` + Nginx + Supervisor + dcron；创建 `navwww` 用户（UID/GID 默认 1000，运行时按 data 目录 owner 对齐）；暴露 58080；Entrypoint 为 `/entrypoint.sh` |
| `docker/entrypoint.sh` | 容器启动入口：时区设置、PUID/PGID 动态对齐、NAV_PORT 注入 Nginx 配置、数据目录初始化、开发模式标记、无人值守安装（`.initial_admin.json`）、反代配置预生成、sudo 白名单设置 |
| `docker/supervisord.conf` | Supervisor 管理 4 个进程：`php-fpm`（priority 5）、`nginx`（priority 10）、`nginx-reload-watcher`（priority 15，监听 `/tmp/nginx-reload-trigger`）、`cron`（priority 20） |
| `docker/nginx.conf` / `nginx-site.conf` | Nginx 主配置和站点配置；站点配置含 `auth_request` 鉴权、PHP-FPM 反向代理、静态资源缓存 |
| `local/docker-compose.yml` | 本地构建专用 Compose；挂载 `data` 目录；默认端口 58080；支持代理环境变量透传 |
| `local/docker-compose.dev.yml` | 开发环境叠加配置：挂载源码实现热更新、启用 `NAV_DEV_MODE`、临时挂载 `docker.sock` |
| `local/docker-compose.test.yml` | 测试环境叠加配置：定义 `playwright-full`、`playwright-mobile`、`lighthouse` 服务 |
| `.github/workflows/docker-publish.yml` | CI 工作流：push 到 `main`/`master` 或 `v*` 标签时触发；多架构构建（`linux/amd64`, `linux/arm64`）并推送到 Docker Hub；同步 README 到 Docker Hub 描述 |
| `.github/workflows/manual-push.yml` | 手动推送工作流：支持跳过 `arm64`、支持额外指定版本标签 |

---

## 代码组织

```text
public/          # 前台入口
  index.php      # 首页：分组卡片、搜索过滤、Tab 切换、最近访问、Cmd+K 命令面板、PWA
  login.php      # 登录页：CSRF、IP 锁定、记住我、开发模式提示
  setup.php      # 安装向导：首次部署引导、生成 Nginx 配置示例、创建管理员
  logout.php     # 退出登录（清除 Cookie）
  bg.php         # 背景图安全输出（防路径遍历）
  favicon.php    # Favicon 代理抓取（SSRF 防护、缓存 7 天、魔数校验）

  sw.js          # Service Worker（PWA 缓存策略）
  gesture-guard.js # 移动端手势拦截（防止边缘滑动返回）
  manifest.webmanifest # PWA 清单
  api/           # 公开 API
    sites.php    # 返回站点分组数据（Bearer Token 或 URL Token 验证）
    dns.php      # DNS API（本机限 127.0.0.1，支持 query/update/batch_update）
  auth/
    verify.php   # Nginx auth_request 鉴权端点（返回 200 + X-Auth-User/X-Auth-Role 或 401）

admin/           # 后台管理页面和 AJAX API
  *.php          # 后台页面（共 39 个，按功能分组如下）
    站点与分组：sites.php、groups.php
    用户与认证：users.php、sessions.php、login_logs.php
    系统与设置：settings.php、configs.php
    网络与代理：nginx.php、dns.php、ddns.php
    宿主机与 Docker：host_runtime.php、docker_hosts.php、manifests.php、packages.php
    文件与审计：files.php、file_audit.php、share_service_audit.php
    任务与计划：scheduled_tasks.php、tasks.php、task_templates.php
    备份与日志：backups.php、logs.php、logs_api.php
    健康与证书：health_check.php
    调试：debug.php、index.php（后台首页）
  *_ajax.php / *_api.php  # AJAX 端点（ddns_ajax.php、settings_ajax.php、sessions_api.php 等）
  api/           # 后台专用 API（task_status.php、task_log.php）
  shared/        # 后台共享库
    functions.php      # 后台主函数库：站点/配置读写、CSRF、备份恢复、健康检查、Nginx 代理管理、审计日志、回收站
    header.php         # 后台页面模板头（权限验证、侧边栏导航、Flash Toast、待生效代理警告）
    footer.php         # 后台页面模板尾（关闭标签、暴露 window._csrf）
    admin.css          # 后台统一暗色主题（Obsidian Terminal 风格）
    dns_lib.php / dns_api_lib.php / ddns_lib.php / cron_lib.php 等 # 各业务领域函数库
  assets/        # 静态资源：Ace Editor（本地）、SortableJS（CDN）

shared/          # 核心共享库（前后台共用）
  auth.php           # 核心认证库：JWT-like Token、Cookie、用户管理、IP 锁定、CSRF、会话撤销、权限系统
  http_client.php    # 带 SSRF 防护的 HTTP 客户端（curl 优先，fallback 到 file_get_contents）
  request_timing.php # 请求耗时日志（recv/done 双阶段，自动轮转 10MB + gzip，7 天保留）
  request_timing.php # 请求耗时日志（recv/done 双阶段，自动轮转 10MB + gzip，7 天保留）

cli/             # CLI 脚本
  run_scheduled_task.php   # 计划任务执行器（硬超时 3600s、PID 锁、僵尸锁清理）
  ddns_sync.php            # DDNS 同步
  alidns_sync.php          # 阿里云 DNS 同步
  health_check_cron.php    # 健康检查定时任务
  manage_users.php         # 用户管理 CLI（list/info/add/passwd/del/reset）

python/          # Python 辅助脚本
  dns_core.py    # DNS 核心逻辑

docker/          # Docker 构建配置
  nginx.conf / nginx-site.conf / php-fpm.conf / php-custom.ini / supervisord.conf / entrypoint.sh

nginx-conf/      # Nginx 配置模板
  proxy-params-simple.conf / proxy-params-full.conf / subsite.conf / nav.conf

data/            # 持久化数据目录（必须挂载到宿主机）
  config.json / sites.json / users.json / scheduled_tasks.json / dns_config.json / ddns_tasks.json
  notifications.json / ip_locks.json / sessions.json / auth_secret.key
  backups/ / logs/ / tasks/ / favicon_cache/ / bg/ / nginx/

tests/
  e2e/full/      # Playwright E2E 测试（171 个 spec 文件，覆盖所有主要功能模块）
  phpunit/       # PHPUnit 单元测试（9 个测试类，Shared/Admin/Subsite 三个套件）
  helpers/       # auth.ts（登录/登出）、fixtures.ts（扩展 base test）、data.ts（resetVolatileAppData）、cli.ts（Docker CLI 封装）
  fixtures/      # 测试固件（import-valid.json、import-invalid.json）

local/           # 本地开发环境
  docker-compose.yml / docker-compose.dev.yml / docker-compose.test.yml
  docker-build.sh / .env.example / php-dev.ini / README.md

subsite-middleware/
  auth_check.php # 子站统一鉴权中间件（URL Token 传递 → Cookie → 用户信息暴露）
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
- **右下角按钮区域统一配置接口**：
  - 按钮区域分为 **左侧**（脏标记 + 辅助操作）和 **右侧**（主操作按钮），通过统一接口配置，禁止各页面硬编码按钮 HTML。
  - 按钮配置格式：
    ```javascript
    NavAceEditor.open({
      // ... 其他配置
      buttons: {
        left: [
          { type: 'dirty' },                       // 自动脏标记，无需手动管理
          { text: '检查语法', class: 'btn-secondary', action: 'syntax', visible: canSyntax }
        ],
        right: [
          { text: '关闭', class: 'btn-secondary', action: 'close' },
          { text: '保存', class: 'btn-primary', action: 'save' },
          { text: '保存并 Reload', class: 'btn-primary', action: 'save_reload' },
          { text: '删除', class: 'btn-danger', action: 'delete', visible: canDelete }
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
    3. **`class`**：按钮样式类，支持 `btn-primary`（主操作，蓝色）、`btn-secondary`（次要操作，灰色）、`btn-danger`（危险操作，红色）以及项目其他标准按钮类。
    4. **`action`**：按钮标识符，点击后触发 `onAction(action, currentValue)` 回调。保留关键字：`save`（Ctrl-S 自动绑定）、`close`（Esc/关闭弹窗时触发）。
    5. **`visible`**：布尔值或返回布尔值的函数，控制按钮是否渲染（用于权限控制，如「删除」仅管理员可见）。
    6. **`onAction`**：统一回调接口，所有按钮点击（含快捷键触发的保存）都通过此回调分发，页面在此处理业务逻辑（AJAX 提交、表单提交、关闭弹窗等）。
    7. **左侧按钮**：通常放置脏标记、语法检查、预览等辅助操作；右侧按钮放置保存、关闭、删除等主操作。两侧按钮各自按配置顺序从左到右排列。
  - **各页面典型按钮组合示例**：
    - **纯编辑保存**（如自定义 CSS）：左侧 `[dirty]`，右侧 `[关闭, 保存]`
    - **编辑 + 语法检查**（如 Nginx 配置）：左侧 `[dirty, 检查语法]`，右侧 `[关闭, 保存, 保存并 Reload]`
    - **文件管理**（如 files.php）：左侧 `[dirty]`，右侧 `[关闭, 下载, 删除, 保存]`
    - **只读查看**（如 logs.php）：左侧 `[]`，右侧 `[关闭]`
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
    left:  [],                  // 左侧按钮数组（辅助操作）
    right: []                   // 右侧按钮数组（主操作）
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
  class: 'btn-primary',         // 按钮样式类。支持：btn-primary / btn-secondary / btn-danger / btn-success / btn-warning 以及项目其他标准按钮类
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
| `class` | `string` | 否 | `btn-secondary` | 按钮样式类。常用：`btn-primary`（主操作，高亮）、`btn-secondary`（次要操作）、`btn-danger`（危险操作，如删除）。支持项目中任意有效的按钮 CSS 类。 |
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
