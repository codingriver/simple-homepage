# 测试 TODO

> 用途：记录当前已识别但暂未进入默认回归基线、需要后续开发或补测的测试项

---

## 1. 移动端测试待办

当前状态：

- 已有移动端专项用例：[mobile-homepage.spec.ts](/Users/mrwang/project/simple-homepage/tests/e2e/full/mobile-homepage.spec.ts)
- 已补首页搜索面板打开/关闭、无结果态、分组切换、卡片点击热区，以及后台侧边栏导航、分组/站点 modal、设置页长表单保存等移动端高风险场景
- 已执行 `npx playwright test tests/e2e/full/mobile-homepage.spec.ts --project=mobile-chrome` 并通过
- 当前默认回归基线仍未执行 `mobile-chrome`

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

- 已新增 [cli-tools.spec.ts](/Users/mrwang/project/simple-homepage/tests/e2e/full/cli-tools.spec.ts)
- 已纳入默认 `chromium` 基线
- 当前已覆盖 `manage_users.php`、`cli/alidns_sync.php`、`cli/ddns_sync.php`、`cli/run_scheduled_task.php` 的主要回归

剩余 CLI 待补项如下：

| 优先级 | 脚本 | 待补测试点 | 说明 |
|---|---|---|---|
| P3 | `cli/alidns_sync.php` | 上游错误消息的精细映射是否需要固定到更细粒度文案 | 当前已覆盖失败退出码与成功落地，剩余是错误文案颗粒度 |
| P3 | `cli/ddns_sync.php` | `skip-unchanged` 场景的稳定锁定方式 | 当前已覆盖 all-success / repeated-success / mixed / all-fail，剩余是外部一致性较强的跳过分支 |

---

## 3. 后续维护规则

- 新发现但暂不开发的测试项，优先补到本文件，而不是散落在聊天记录里
- 一旦某项进入开发并落地到自动化测试，应同时更新：
  - [项目测试规划.md](/Users/mrwang/project/simple-homepage/docs/项目测试规划.md)
  - 本 TODO 文档对应状态
