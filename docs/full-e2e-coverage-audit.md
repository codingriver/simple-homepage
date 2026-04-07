# Full E2E 覆盖审计表

> 审计日期：2026-04-07  
> 审计范围：`tests/e2e/full` 对当前项目页面/界面维度的覆盖情况  
> 最近一次完整验证：`npx playwright test --project=chromium`  
> 结果：`104 passed`，`1 skipped`，`0 failed`

---

## 1. 审计结论

当前项目按“页面/界面 -> 页面内功能 -> 交互/异常/边界/权限/状态/前后端一致性”的 full E2E 覆盖，主后台页面与公共页面已经形成可执行覆盖矩阵。

结论如下：

- `已覆盖`：核心公共页面、后台主页面、关键异步接口联动、权限/认证/CSRF/资源守卫均已有对应 full 用例。
- `部分覆盖`：移动端首页存在专门用例，但当前默认处于 `skip`，不计入本轮可执行覆盖。
- `不按页面单独审计`：`admin/shared/*`、`admin/assets/*`、`shared/*` 这类共享资源或静态资产，不单独按页面维度立项，按其被业务页面引用后的行为纳入对应界面测试。

---

## 2. 审计口径

状态定义：

- `已覆盖`：存在稳定可执行的 full spec，且本轮完整回归已通过。
- `部分覆盖`：存在 spec，但当前默认未纳入本轮可执行结果，或仅覆盖部分终端/场景。
- `不纳入页面审计`：该文件不是独立业务界面，而是共享资源、样式、静态脚本或由其他页面间接覆盖。

---

## 3. 页面覆盖矩阵

| 页面/入口 | 页面职责 | 对应 full spec | 状态 | 审计说明 |
|---|---|---|---|---|
| `public/setup.php` | 首次安装/初始化 | `setup-install.spec.ts`、`setup-validation.spec.ts` | 已覆盖 | 覆盖首次安装、重复安装封闭、输入校验 |
| `public/login.php` | 登录入口 | `auth-login.spec.ts`、`auth-session.spec.ts`、`auth-redirect-sanitize.spec.ts`、`public-login-rescue.spec.ts` | 已覆盖 | 覆盖成功/失败/锁定/重定向/救援提示 |
| `public/logout.php` | 登出 | `auth-login.spec.ts`、`auth-session.spec.ts`、`public-resource-guards.spec.ts` | 已覆盖 | 覆盖登出后会话失效与旧地址失效 |
| `public/auth/verify.php` | 认证探针接口 | `public-auth-verify.spec.ts` | 已覆盖 | 覆盖匿名拒绝与登录后响应头行为 |
| `public/index.php` | 前台首页 | `homepage-render.spec.ts`、`homepage-search-tabs.spec.ts`、`homepage-empty-state.spec.ts`、`homepage-interaction-advanced.spec.ts`、`frontend-backend-sync.spec.ts`、`large-dataset-ui.spec.ts`、`navigation-flow.spec.ts` | 已覆盖 | 覆盖展示、搜索、Tab、空态、权限可见性、联动一致性、大数据量可用性 |
| `public/api/dns.php` | 公共 DNS API | `public-dns-api.spec.ts`、`dns-ttl-skip-semantics.spec.ts` | 已覆盖 | 覆盖 query/update/batch_update、未知 action、防误更与 TTL/skip 语义 |
| `public/bg.php` | 背景资源接口 | `public-resource-guards.spec.ts` | 已覆盖 | 覆盖公共资源守卫路径 |
| `public/favicon.php` | favicon 抓取代理 | `public-resource-guards.spec.ts` | 已覆盖 | 覆盖资源守卫；外网抓取成功与否不作为稳定回归前置条件 |
| `admin/index.php` | 后台首页/导航起点 | `admin-dashboard.spec.ts`、`navigation-flow.spec.ts` | 已覆盖 | 覆盖统计展示、快捷入口、后台核心流转 |
| `admin/groups.php` | 分组管理 | `groups-crud.spec.ts`、`groups-boundary.spec.ts`、`groups-ordering.spec.ts`、`groups-advanced-boundary.spec.ts`、`groups-emoji-visibility-matrix.spec.ts` | 已覆盖 | 覆盖 CRUD、边界、排序、图标/可见性矩阵、删除级联 |
| `admin/sites.php` | 站点管理 | `sites-basic.spec.ts`、`sites-validation.spec.ts`、`sites-proxy.spec.ts`、`sites-edit-delete.spec.ts`、`sites-stale-field-switch.spec.ts`、`sites-uniqueness.spec.ts`、`sites-advanced-boundary.spec.ts` | 已覆盖 | 覆盖外链/代理、模式切换、唯一性、非法输入、删除编辑、级联一致性 |
| `admin/settings.php` | 系统设置主界面 | `settings-basic.spec.ts`、`settings-persistence.spec.ts`、`settings-full-fields.spec.ts`、`settings-advanced-linkage.spec.ts`、`settings-cookie-policy.spec.ts`、`settings-import-export.spec.ts`、`settings-import-export-roundtrip.spec.ts`、`settings-legacy-import.spec.ts`、`settings-backup-restore.spec.ts`、`settings-nginx.spec.ts`、`settings-webhook.spec.ts`、`settings-health-loginlogs-ui.spec.ts` | 已覆盖 | 覆盖基础设置、持久化、导入导出、恢复回滚、Cookie、Webhook、Nginx、健康检查/登录日志面板 |
| `admin/settings_ajax.php` | 设置页异步接口 | `admin-ajax-contract.spec.ts`、`health-check.spec.ts`、`settings-health-loginlogs-ui.spec.ts` | 已覆盖 | 覆盖健康检查、登录日志、调试数据等异步响应契约 |
| `admin/backups.php` | 备份管理 | `backups-page.spec.ts`、`backups-download-guards.spec.ts`、`backups-retention-auto-restore.spec.ts`、`settings-backup-restore.spec.ts` | 已覆盖 | 覆盖创建、下载、恢复、保留策略、非法下载保护 |
| `admin/dns.php` | DNS 账号/Zone/记录管理 | `dns-management.spec.ts`、`dns-management-advanced.spec.ts`、`dns-management-supplement.spec.ts` | 已覆盖 | 覆盖账号保存/校验/删除、hydrate、记录 CRUD、批量导入导出、批量删除、真实账号编辑保留密码 |
| `admin/ddns.php` | DDNS 任务管理 | `ddns-basic.spec.ts`、`ddns-validation.spec.ts`、`ddns-run-log.spec.ts`、`ddns-deep-runtime.spec.ts`、`ddns-fallback.spec.ts`、`ddns-schedule-integration.spec.ts` | 已覆盖 | 覆盖任务 CRUD、来源切换、执行、运行结果、日志分页搜索清理、调度联动 |
| `admin/ddns_ajax.php` | DDNS 异步接口 | `ddns-basic.spec.ts`、`ddns-run-log.spec.ts`、`ddns-deep-runtime.spec.ts`、`ddns-validation.spec.ts` | 已覆盖 | 覆盖 save/run/test_source/log/log_clear 的真实调用行为 |
| `admin/scheduled_tasks.php` | 计划任务管理 | `scheduled-tasks.spec.ts`、`scheduled-tasks-advanced.spec.ts`、`scheduled-tasks-log-pagination.spec.ts`、`scheduled-task-dispatcher-guard.spec.ts`、`ddns-schedule-integration.spec.ts` | 已覆盖 | 覆盖 CRUD、日志分页、调度分发器保护、DDNS 联动、工作目录模式 |
| `admin/api/task_log.php` | 任务日志 API | `task-log-api.spec.ts` | 已覆盖 | 覆盖鉴权、分页、执行后日志读取 |
| `admin/nginx.php` | Nginx 编辑器 | `nginx-editor.spec.ts`、`nginx-editor-advanced.spec.ts` | 已覆盖 | 覆盖切页、语法检测、保存、reload 分支、异常回退 |
| `admin/debug.php` | 调试工具 | `debug-tools.spec.ts`、`debug-advanced.spec.ts`、`debug-log-tabs.spec.ts`、`debug-empty-state.spec.ts` | 已覆盖 | 覆盖 display_errors、日志查看、清理 Cookie、空态与分页筛选 |
| `admin/health_check.php` | 健康检查页面 | `health-check.spec.ts`、`admin-ajax-contract.spec.ts`、`settings-health-loginlogs-ui.spec.ts` | 已覆盖 | 覆盖批量状态、单项检查、缓存数据显示 |
| `admin/login_logs.php` | 登录日志页面 | `login-logs.spec.ts`、`settings-health-loginlogs-ui.spec.ts` | 已覆盖 | 覆盖日志记录、鉴权与 UI 加载 |
| `admin/users.php` | 用户管理 | `users-management.spec.ts`、`users-boundary.spec.ts`、`users-edit-password-retain.spec.ts`、`users-last-admin-guard.spec.ts`、`auth-permission.spec.ts` | 已覆盖 | 覆盖 CRUD、角色切换、自删除保护、最后管理员保护、密码保留 |

---

## 4. 跨页面与全局约束覆盖

以下能力不属于单一页面，但已被 full 用例覆盖：

| 维度 | 对应 full spec | 状态 | 说明 |
|---|---|---|---|
| 后台页面导航流转 | `navigation-flow.spec.ts` | 已覆盖 | 覆盖核心后台页面互跳与回前台 |
| 管理端接口鉴权 | `admin-endpoint-auth-guards.spec.ts`、`auth-permission.spec.ts` | 已覆盖 | 覆盖游客/普通用户/管理员差异 |
| CSRF 防护 | `csrf-guards.spec.ts` | 已覆盖 | 覆盖关键写操作缺失 token 的拒绝行为 |
| 前后端数据一致性 | `frontend-backend-sync.spec.ts`、`system-roundtrip-regression.spec.ts` | 已覆盖 | 覆盖后台修改后前台同步、备份恢复后系统一致性 |
| 管理端异步接口契约 | `admin-ajax-contract.spec.ts` | 已覆盖 | 覆盖 settings/debug/login logs/health check 等契约 |
| 公共资源守卫 | `public-resource-guards.spec.ts` | 已覆盖 | 覆盖 favicon/bg/logout 等公共入口 |

---

## 5. 不纳入页面维度单独审计的文件

以下文件不作为独立业务页面立项，按其被页面引用后的行为间接覆盖：

- `admin/shared/*`
- `admin/assets/*`
- `shared/*`
- 纯样式/静态脚本/模板片段

原因：

- 它们不是用户直接访问的独立界面。
- 其实际风险应通过承载它们的业务页面 E2E 来验证，而不是拆成伪页面测试。

---

## 6. 剩余风险与待跟进项

- `tests/e2e/full/mobile-homepage.spec.ts` 当前仍为 `skip`，因此移动端界面属于“存在用例但未纳入本轮执行覆盖”。
- 外部依赖强的路径已尽量做成稳定断言，但仍受运行环境影响：
  - `public/favicon.php` 的外部抓取成功率不作为 full 稳定性前提。
  - `public/api/dns.php` 的公网/本机限制存在环境分支，测试已覆盖成功或明确拒绝两种合法结果。
  - 个别 Nginx / DNS / 第三方网络能力更适合做“契约正确 + 提示正确 + 环境允许时成功”的断言，而不是强制每次都走外部成功路径。

---

## 7. 最终结论

以桌面端 `chromium` full 回归为准，当前项目的页面级 E2E 覆盖已达到“可作为发版前全量回归基线”的状态。

当前唯一明确未纳入执行覆盖的页面维度项是：

- 移动端首页/后台可用性：已有 spec，但默认 `skip`

除该项外，当前项目主要页面、主要功能区块、核心交互、异常路径、权限/身份差异、关键异步接口与前后端一致性，均已进入可执行 full 覆盖。
