# Full 全量 E2E 测试规划

## 1. 目标

本项目只维护一套 `full` 全量 E2E 测试，不再单独拆分 `smoke`、`regression` 目录。

这套测试同时承担以下职责：

- 回归测试
- 全量 E2E 测试
- 发版前验证
- 关键功能改动后的完整校验

推荐原则：

- 使用 `Playwright`
- 默认串行执行，建议 `workers: 1`
- 所有测试统一放在 `tests/e2e/full`
- 每次核心功能改动后直接运行 full 测试集

补充参考：

- 页面级最终审计表见 `docs/full-e2e-coverage-audit.md`

---

## 2. 推荐目录结构

```txt
tests/
  e2e/
    full/
      auth-login.spec.ts
      auth-session.spec.ts
      auth-permission.spec.ts
      setup-install.spec.ts

      groups-crud.spec.ts
      groups-boundary.spec.ts
      groups-ordering.spec.ts

      sites-basic.spec.ts
      sites-validation.spec.ts
      sites-proxy.spec.ts
      sites-edit-delete.spec.ts

      settings-basic.spec.ts
      settings-persistence.spec.ts
      settings-import-export.spec.ts
      settings-import-export-roundtrip.spec.ts
      settings-backup-restore.spec.ts
      settings-cookie-policy.spec.ts
      settings-nginx.spec.ts

      navigation-flow.spec.ts
      homepage-render.spec.ts
      ui-dialogs.spec.ts
      mobile-homepage.spec.ts

  fixtures/
    import-valid.json
    import-invalid.json
    import-full-backup.json

  helpers/
    auth.ts
    data.ts
    assertions.ts
    cleanup.ts
```

---

## 3. 测试范围总览

| 模块 | 子项 | 当前状态 | 优先级 | 说明 |
|---|---|---:|---:|---|
| 安装初始化 | 首次安装流程 | 已覆盖 | P1 | `setup-install.spec.ts` |
| 认证登录 | 登录失败/成功/登出/同 IP 锁定 | 已覆盖 | P1 | `auth-login.spec.ts` |
| 权限控制 | 非管理员限制 | 已覆盖 | P1 | `auth-permission.spec.ts` |
| Session | 退出后失效跳转 | 已覆盖 | P1 | `auth-session.spec.ts` |
| 分组管理 | 新增/删除 | 已覆盖 | P1 | `groups-crud.spec.ts` |
| 分组边界 | 非法输入 / 重复 ID 覆盖行为 / 删除级联 | 已覆盖 | P1 | `groups-boundary.spec.ts` |
| 分组排序 | 排序生效 | 已覆盖 | P2 | `groups-ordering.spec.ts` |
| 站点管理 | 外链/代理新增 | 已覆盖 | P1 | `sites-basic.spec.ts` |
| 站点边界 | 非法代理目标 / 非法 ID / 空名称 / 未选分组 | 已覆盖 | P1 | `sites-validation.spec.ts` |
| Proxy 模式 | path / domain 两种模式 / 模式切换联动 / 待生效提示 | 已覆盖 | P2 | `sites-proxy.spec.ts` |
| 站点编辑删除 | 编辑 / 删除 | 已覆盖 | P1 | `sites-edit-delete.spec.ts` |
| 设置管理 | 保存设置 | 已覆盖 | P1 | `settings-basic.spec.ts` |
| 设置持久化 | 刷新 / 重登后仍生效 | 已覆盖 | P1 | `settings-persistence.spec.ts` |
| 导入导出 | 导出/导入基础 | 已覆盖 | P1 | `settings-import-export.spec.ts` |
| 导入导出一致性 | 导出再导入恢复 | 已覆盖 | P1 | `settings-import-export-roundtrip.spec.ts` |
| 备份恢复 | 手动备份 / 下载 / 恢复回滚 | 已覆盖 | P1 | `settings-backup-restore.spec.ts` |
| Cookie 策略 | secure/domain 保存与 IP 访问降级 | 已覆盖 | P1 | `settings-cookie-policy.spec.ts` |
| Webhook 通知 | 类型联动 / 配置保存 / 测试消息失败提示 | 已覆盖 | P2 | `settings-webhook.spec.ts` |
| Nginx 设置 | 下载配置 / 保存参数模式 / 待 reload 提示 | 已覆盖 | P2 | `settings-nginx.spec.ts` |
| 导航流转 | 前后台完整跳转 | 已覆盖 | P2 | `navigation-flow.spec.ts` |
| 首页渲染 | 分组/站点展示逻辑 | 已覆盖 | P2 | `homepage-render.spec.ts` |
| UI 行为 | confirm dialog / modal 背景关闭 / 备份恢复确认 | 已覆盖 | P2 | `ui-dialogs.spec.ts` |
| 移动端 | 首页可用性 | 部分覆盖 | P3 | `mobile-homepage.spec.ts` 已存在，但当前默认跳过 |
| 站点边界 | slug 为空 / 非法 / 重复、外链 URL 非法 | 已覆盖 | P1 | `sites-validation.spec.ts`、`sites-uniqueness.spec.ts`、`sites-advanced-boundary.spec.ts` |
| 分组边界 | 超长名称、删除特殊分组限制 | 已覆盖 | P2 | `groups-boundary.spec.ts`、`groups-advanced-boundary.spec.ts` |
| Nginx 设置 | reload 成功/失败提示 | 已覆盖 | P2 | `settings-nginx.spec.ts`、`nginx-editor-advanced.spec.ts` |

---

## 4. Full 用例清单

### 4.1 认证与安装

#### `setup-install.spec.ts`
- 首次安装流程可执行
- 安装后 setup 页面不可重复执行
- 初始管理员链路正确

#### `auth-login.spec.ts`
- 登录页打开
- 错误密码提示
- 管理员登录成功
- 进入后台首页
- 登出成功
- 登出后再次访问后台跳转登录
- 同 IP 连续登录失败触发锁定
- 锁定后正确密码仍被拦截
- 锁定阈值配置可通过设置页调整并恢复

#### `auth-session.spec.ts`
- session 失效后访问后台跳登录
- redirect 参数保留
- 退出后旧后台地址失效
- remember me / cookie 失效时行为正确

#### `auth-permission.spec.ts`
- 非管理员不能访问管理员页面
- 无权限时跳转或提示正确
- 未登录不能访问后台关键路径
- 非管理员看不到管理员功能入口

---

### 4.2 分组管理

#### `groups-crud.spec.ts`
- 新增分组
- 编辑分组
- 删除分组
- 可见范围保存
- 登录要求保存

#### `groups-boundary.spec.ts`
- 分组 ID 非法字符
- 分组 ID 重复
- 空名称
- 删除带关联站点的分组后站点同步删除
- 删除后首页同步消失
- 超长名称
- 删除特殊分组时的限制

#### `groups-ordering.spec.ts`
- 创建多个分组
- 设置不同排序值
- 首页展示顺序正确
- 修改排序后顺序变化正确

---

### 4.3 站点管理

#### `sites-basic.spec.ts`
- 新增外链站点
- 新增代理站点
- 分组归属正确
- 保存后后台列表显示正确

#### `sites-validation.spec.ts`
- 非法外链 URL
- 非法代理目标
- 非 RFC1918 内网地址
- slug 为空
- slug 非法
- slug 重复
- 站点 ID 非法
- 站点名为空
- 分组未选择

#### `sites-proxy.spec.ts`
- path 模式代理
- domain 模式代理
- 模式切换后字段联动正确
- 保存后待生效提示出现
- 设置页 proxy pending bar 可见
- slug/path 展示正确

#### `sites-edit-delete.spec.ts`
- 编辑站点名称
- 编辑 URL / target
- 编辑分组归属
- 删除站点
- 删除后后台消失
- 删除后首页消失

---

### 4.4 设置与配置

#### `settings-basic.spec.ts`
- 修改站点名称
- 修改背景色
- 修改卡片布局
- 修改方向
- 保存成功提示
- 首页标题同步变化

#### `settings-persistence.spec.ts`
- 保存后刷新仍保留
- 重开后台后仍保留
- 首页仍然正确显示
- 配置不是只改当前页面状态

#### `settings-import-export.spec.ts`
- 导出配置成功
- 非法 JSON 导入失败
- 非法结构导入失败
- 合法旧格式导入成功
- 合法完整格式导入成功
- 导入后去 `groups.php` / `index.php` 验证数据

#### `settings-import-export-roundtrip.spec.ts`
- 创建一批数据
- 导出配置
- 修改或清理数据
- 再导入配置
- 验证关键内容恢复

#### `settings-backup-restore.spec.ts`
- 创建手动备份
- 下载备份文件
- 修改已有数据
- 恢复指定备份
- 验证配置被成功回滚

#### `settings-cookie-policy.spec.ts`
- 保存 Cookie Secure 模式
- 保存 Cookie Domain
- 刷新后配置仍保留
- IP 访问时 session cookie 自动降级
- 结束后恢复原始配置

#### `settings-webhook.spec.ts`
- Webhook 类型切换时 Telegram Chat ID 显示联动正确
- Webhook 配置保存后刷新仍保留
- 未勾选任何事件时回退到默认订阅事件
- 测试消息在 URL 为空时给出明确提示
- 测试消息在目标地址不可达时给出明确失败提示

#### `settings-nginx.spec.ts`
- 下载配置成功
- 保存 proxy 参数模式
- 存在代理站点时出现待 reload 提示
- 触发生成配置按钮
- 错误时提示可见
- 成功时提示正确

---

### 4.5 页面流转与展示

#### `navigation-flow.spec.ts`
- 首页 -> 登录页
- 登录页 -> 后台控制台
- 控制台 -> 分组页
- 分组页 -> 站点页
- 站点页 -> 设置页
- 设置页 -> 返回首页

#### `homepage-render.spec.ts`
- 首页分组显示
- 首页站点显示
- 空分组显示
- 登录要求影响显示
- 设置更改影响首页标题/布局
- 删除站点后首页同步更新

#### `ui-dialogs.spec.ts`
- 删除确认 dialog
- 导入确认 dialog
- modal 打开关闭
- 点击背景关闭
- 备份删除确认取消路径
- 备份恢复确认取消路径
- 错误 alert / dialog 行为

#### `mobile-homepage.spec.ts`
- 首页在移动端打开正常
- 分组/卡片无明显错位
- 关键入口可点击

---

## 5. 当前落地状态

### 已落地 spec 列表

```txt
tests/e2e/full/
  auth-login.spec.ts
  auth-permission.spec.ts
  auth-session.spec.ts
  groups-boundary.spec.ts
  groups-crud.spec.ts
  groups-ordering.spec.ts
  homepage-render.spec.ts
  mobile-homepage.spec.ts
  navigation-flow.spec.ts
  settings-backup-restore.spec.ts
  settings-basic.spec.ts
  settings-cookie-policy.spec.ts
  settings-import-export-roundtrip.spec.ts
  settings-import-export.spec.ts
  settings-nginx.spec.ts
  settings-persistence.spec.ts
  setup-install.spec.ts
  sites-basic.spec.ts
  sites-edit-delete.spec.ts
  sites-proxy.spec.ts
  sites-validation.spec.ts
  ui-dialogs.spec.ts
```

### 当前剩余空位

1. `sites-validation.spec.ts` 继续补：
   - slug 为空
   - slug 非法字符
   - slug 重复
   - 外链 URL 非法

2. `groups-boundary.spec.ts` 继续补：
   - 超长名称
   - 特殊默认分组限制（若后续引入）

3. `settings-nginx.spec.ts` 继续补：
   - reload 成功提示
   - reload 失败提示
   - 依赖当前测试环境具备对应运行能力

---

## 6. 推荐落地顺序

### P1：已落地
1. `setup-install.spec.ts`
2. `auth-login.spec.ts`
3. `auth-session.spec.ts`
4. `auth-permission.spec.ts`
5. `groups-crud.spec.ts`
6. `groups-boundary.spec.ts`
7. `sites-basic.spec.ts`
8. `sites-validation.spec.ts`
9. `sites-edit-delete.spec.ts`
10. `settings-basic.spec.ts`
11. `settings-persistence.spec.ts`
12. `settings-import-export.spec.ts`
13. `settings-import-export-roundtrip.spec.ts`
14. `settings-backup-restore.spec.ts`
15. `settings-cookie-policy.spec.ts`

### P2：已落地
16. `groups-ordering.spec.ts`
17. `sites-proxy.spec.ts`
18. `settings-nginx.spec.ts`
19. `navigation-flow.spec.ts`
20. `homepage-render.spec.ts`
21. `ui-dialogs.spec.ts`

### P3：已落地
22. `mobile-homepage.spec.ts`

### 后续补强项
23. 细化 `sites-validation.spec.ts`
24. 细化 `groups-boundary.spec.ts`
25. 细化 `settings-nginx.spec.ts`

---

## 7. 推荐执行方式

### 开发时
改完核心逻辑后直接运行 full：

```bash
npm run test:e2e:full
```

### 提交前
统一运行 full：

```bash
npm run test:e2e:full
```

### 发布前
统一运行 full：

```bash
npm run test:e2e:full
```

---

## 8. 执行与维护建议

### 8.1 串行执行
本项目 full 测试涉及：
- 导入配置
- 修改设置
- 创建/删除数据

这些操作天然会互相影响，因此建议默认：

```ts
workers: 1
```

### 8.2 独立命名
测试数据统一使用唯一命名，避免冲突：
- `group-${Date.now()}`
- `site-${Date.now()}`
- `setting-${Date.now()}`

### 8.3 导入类测试要跳目标页验证
导入成功后不要只看提示文案，应显式跳转：
- `groups.php`
- `sites.php`
- `index.php`

验证真实结果。

### 8.4 减少脆弱断言
少用全局 `getByText('xxx')`，尽量使用：
- 容器级断言
- 行级定位
- URL / title / body 局部断言
- 与业务对象绑定的定位方式

---

## 9. 最终结论

本项目最终测试策略如下：

- 只维护 `tests/e2e/full`
- 使用 `Playwright`
- `full` 同时承担回归测试与全量 E2E 测试职责
- 默认串行执行
- 所有核心流程、边界校验、配置导入导出、页面流转、首页展示都纳入 full

这套 full 测试集将作为本项目唯一的长期回归标准。
