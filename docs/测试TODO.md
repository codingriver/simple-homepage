# 测试 TODO

> 用途：记录当前已识别但暂未进入默认回归基线、需要后续开发或补测的测试项

---

## 1. 移动端测试待办

当前状态：

- 已有移动端专项用例：`mobile-homepage.spec.ts`
- 已补首页搜索面板打开/关闭、无结果态、分组切换、卡片点击热区，以及后台侧边栏导航、分组/站点 modal、设置页长表单保存等移动端高风险场景
- 已执行 `npx playwright test tests/e2e/full/mobile-homepage.spec.ts --project=mobile-chrome` 并通过
- **当前默认回归基线仍未执行 `mobile-chrome`**

后续开发/补测时，应优先补以下移动端场景：

| 优先级 | 页面/模块 | 待补测试点 | 说明 |
|---|---|---|---|
| P1 | `public/index.php` 首页 | 搜索面板打开/关闭/输入/清空/无结果态 | 当前仅覆盖基础搜索，不够完整 |
| P1 | `public/index.php` 首页 | 分组 Tab 切换、分组滚动、长列表可用性 | 需要验证移动端长内容交互 |
| P1 | `public/index.php` 首页 | 卡片点击热区、外链/代理卡片可点击性 | 需要验证手势/触屏点击 |
| P1 | `admin/index.php` 后台首页 | 侧边栏展开/收起、导航入口可点击性 | 当前只覆盖基础展示 |
| P1 | `admin/groups.php` | 移动端 modal 打开/关闭、表单提交、列表可读性 | 后台 CRUD 页尚未做移动端专项 |
| P1 | `admin/sites.php` | 类型切换、字段联动、modal 表单滚动 | 代理/外链表单较复杂，移动端风险高 |
| P1 | `admin/settings.php` | 长表单滚动、分区锚点跳转、保存按钮可达性 | 设置页是移动端高风险页面 |
| P2 | `admin/ddns.php` | 任务列表横向溢出、执行/日志 modal 可用性 | 表格与日志弹层需要专项验证 |
| P2 | `admin/dns.php` | 账号管理 modal、记录表格、批量操作可用性 | DNS 页面交互复杂，适合后补 |
| P2 | `admin/scheduled_tasks.php` | 表格分页、日志查看、表单编辑 | 任务页操作密集，移动端易出现遮挡 |
| P2 | `admin/nginx.php` | Ace 编辑器在移动端的基本可操作性 | 可至少验证加载、切页、只读/编辑可用 |
| P3 | 全局 | 横竖屏切换、软键盘弹出后布局稳定性 | 需要更细粒度移动端专项时再做 |

建议补测顺序：

1. 首页
2. 后台首页与侧边栏
3. 设置页
4. 分组/站点页
5. DNS/DDNS/计划任务/Nginx 编辑器

---

## 2. CLI 测试待办

当前状态：

- 已新增 `cli-tools.spec.ts`
- **已纳入默认 `chromium` 基线**
- 当前已覆盖 `manage_users.php`、`cli/alidns_sync.php`、`cli/ddns_sync.php`、`cli/run_scheduled_task.php` 的主要回归

剩余 CLI 待补项如下：

| 优先级 | 脚本 | 待补测试点 | 说明 |
|---|---|---|---|
| P3 | `cli/alidns_sync.php` | 上游错误消息的精细映射是否需要固定到更细粒度文案 | 当前已覆盖失败退出码与成功落地，剩余是错误文案颗粒度 |
| P3 | `cli/ddns_sync.php` | `skip-unchanged` 场景的稳定锁定方式 | 当前已覆盖 all-success / repeated-success / mixed / all-fail，剩余是外部一致性较强的跳过分支 |
| P3 | `cli/check_expiry.php` | `--notify` 分支与不同到期状态的输出 | 当前未覆盖 |
| P3 | `cli/host_agent.php` | 非 CLI 访问拒绝、基本 API 响应 | 当前未覆盖 |

---

## 3. Middleware / Shared 测试待办

当前状态：

- `subsite-middleware/auth_check.php`：**无专项自动化测试**
- `shared/auth.php`：仅被登录/权限 E2E **间接覆盖**
- `shared/notify_runtime.php`：仅被通知发送 E2E **间接覆盖**
- `shared/request_timing.php`：**未覆盖**

建议补测方向：

| 优先级 | 模块 | 测试目标 | 建议方式 |
|---|---|---|---|
| P2 | `subsite-middleware/auth_check.php` | 子站 require 后的鉴权行为、`_nav_token` 处理 | runDockerPhpInline 集成测试 |
| P2 | `shared/auth.php` | Token 生成/验证、会话撤销、IP 锁定、密码哈希 | PHPUnit 单元测试 |
| P2 | `shared/notify_runtime.php` | 渠道构造、冷却逻辑、事件匹配 | PHPUnit 单元测试 |
| P3 | `shared/request_timing.php` | 请求开始/结束时的日志写入 | runDockerPhpInline 集成测试 |

---

## 4. 新功能回归待办

以下新功能已开发完成，但部分细分场景未做 exhaustive 覆盖：

| 功能 | 已覆盖 | 待补 | 优先级 |
|---|---|---|---|
| 文件系统（本机） | 浏览、编辑、上传、下载、压缩解压 | 大文件限制、批量操作、权限修改的边界 | P2 |
| 文件系统（远程） | 远程主机浏览、编辑 | 远程上传下载、远程搜索 | P3 |
| Docker 管理 | 容器/镜像/卷/网络列表、启停删除 | 日志查看、批量操作、错误状态 | P3 |
| WebDAV | 配置、Basic Auth、DAV 操作、共享 | 配额边界、并发写入、大文件上传 | P3 |
| 通知中心 | 渠道 CRUD、测试发送、事件订阅 | 冷却逻辑边界、多渠道同时触发 | P3 |

---

## 5. 性能与稳定性待办

| 优先级 | 项目 | 目标 | 说明 |
|---|---|---|---|
| P3 | 移动端基线纳入 CI | 在 GitHub Actions 中增加 `mobile-chrome` 执行矩阵 | 目前 CI 只跑 `chromium` |
| P3 | Lighthouse 性能基线 | 建立首页和登录页的性能回归基线 | 已有脚本 `npm run test:perf`，未纳入 CI |
| P3 | 大数据量测试 | 100+ 站点、20+ 分组时的首页渲染性能 | 当前 `large-dataset-ui.spec.ts` 已覆盖基础可用性，未测性能指标 |

---

## 6. 后续维护规则

- 新发现但暂不开发的测试项，优先补到本文件，而不是散落在聊天记录里
- 一旦某项进入开发并落地到自动化测试，应同时更新：
  - `docs/项目测试规划.md`
  - 本 TODO 文档对应状态
- 定期（建议每月）回顾本 TODO，清理已完成的项

---

*文档结束*
