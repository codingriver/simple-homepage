# AI 开发指令规范

> 使用此规范，让 AI 完成「需求→开发→测试→验收」全自动闭环。
> 适用于已配置 nav-portal MCP Server 的环境。

---

## 核心原则：指令三要素

**需求 + 验收标准 + 执行规则**

缺少任何一个：
- 没有需求 → AI 不知道做什么
- 没有验收标准 → AI 不知道什么时候算完成
- 没有执行规则 → AI 不会自动测试，等你手动验证

---

## 模板一：功能需求

```
## 需求
在备份页面每条记录上显示文件大小（KB）

## 验收
- MCP run_test 22/22 全通过
- 备份页面能看到文件大小列

## 执行规则
修改→sync_file→php_lint→run_test，失败继续修复直到通过
```

---

## 模板二：Bug 修复

```
## Bug
登录后跳转 index.php 页面空白

## 复现
1. 访问 /login.php
2. 输入正确账号密码
3. 跳转后空白

## 验收
- run_test 中「正确密码登录」和「控制台」均通过
- MCP http_request GET /index.php 返回 HTTP 200

## 执行规则
1. MCP read_log 读 nginx_error 定位问题
2. 修复→sync_file→php_lint→run_test
3. 失败继续，直到全部通过
```

---

## 模板三：安全检查

```
## 需求
检查所有 PHP 文件的安全问题并修复：
1. 缺少 CSRF 保护的表单
2. 未 htmlspecialchars 转义的输出
3. 文件路径未过滤的参数

## 执行规则
1. 逐个读取 PHP 文件，发现问题立即修复
2. sync_file 同步，php_lint 检查
3. run_test 确保功能不受影响
4. 汇总修复清单

## 验收
- run_test 22/22 通过
- php_lint 无语法错误
```

---

## 模板四：新页面

```
## 需求
后台添加「系统信息」页面：PHP版本、Nginx版本、磁盘使用、容器运行时间

## 技术约束
- 新建 admin/sysinfo.php
- 使用现有 header.php/footer.php
- 在侧边栏添加导航入口

## 执行规则
1. 读 header.php 了解导航结构
2. 创建 sysinfo.php，修改 header.php
3. sync_file 同步所有修改文件
4. php_lint 检查
5. MCP http_request GET /admin/sysinfo.php 验证
6. run_test 确保原有功能不受影响

## 验收
- /admin/sysinfo.php HTTP 200
- run_test 22/22 通过
```

---

## 让 AI 自动化的关键词

| 关键词 | 效果 |
|--------|------|
| `失败则继续修复` | AI 不会在测试失败时停下来问你 |
| `直到全部通过` | AI 循环修复直到满足验收标准 |
| `不需要我确认` | AI 跳过中间确认步骤 |
| `自动完成整个流程` | AI 一次性完成所有步骤 |
| `汇报最终结果` | AI 只在完成后告诉你结果 |

---

## 指令反模式（避免）

| 错误指令 | 问题 | 正确指令 |
|---------|------|----------|
| `帮我修改 login.php` | 无验收标准，改完就停 | `修改 login.php，run_test 验收，直到通过` |
| `登录有问题` | 无复现步骤，AI 来回问 | `登录后返回302，预期200，read_log 定位后修复` |
| `优化一下代码` | 范围不明，改太多 | `优化 backup_list 函数，不改其他文件` |
| `看看有没有 bug` | 范围太广 | `检查所有表单是否有 csrf_field()，缺少则补上` |

---

## 完整自动化流程

```
你的一句话需求
    │
    ▼
AI 读取相关文件
    │
    ▼
AI 修改代码
    │
    ▼
MCP sync_file 同步
    │
    ▼
MCP php_lint 检查语法
    │
    ├─ 有错误 ──▶ 修复 ──▶ 重新同步
    │
    ▼
MCP run_test 运行测试
    │
    ├─ 失败 ──▶ MCP read_log 分析 ──▶ 修复 ──▶ 重新同步
    │
    ▼
全部通过，汇报结果
```

---

## Cursor Rules 配置（自动注入工作流规范）

创建 .cursor/rules/workflow.md：

```markdown
# 开发工作流规范

每次修改代码后必须：
1. nav-portal MCP sync_file 同步修改的文件（含 MD5 验证）
2. nav-portal MCP php_lint 检查语法
3. nav-portal MCP run_test 运行测试
4. 测试失败则继续修复，不要停下来问用户
5. 全部通过后才汇报结果

禁止：
- 修改后不同步就汇报完成
- 测试失败后等用户确认
- 在容器内 curl 自身（死锁）
```

---

## 最简日常指令格式

```
[做什么] + [验收条件] + [失败继续修复]
```

实例：
- 给备份列表加排序，run_test 22/22 通过，失败继续修复
- 修复分组 AJAX 返回 401 的问题，run_test 分组模块 3/3 通过
- 给登录页加记住我功能，run_test 通过，汇报修改了哪些文件

---

## MCP 工具速查

| 场景 | 使用工具 |
|------|----------|
| 修改文件后同步 | sync_file |
| 批量同步所有PHP | sync_all_php |
| 确认是否已同步 | check_md5 |
| 检查PHP语法 | php_lint |
| 运行完整测试 | run_test |
| 查看错误日志 | read_log |
| 容器内执行命令 | docker_exec |
| 重置安装状态 | reset_nav |
| 测试单个接口 | http_request |

---

## 关联文件

- MCP Server: /volume3/storage/docker/nav-portal/mcp-server/server.js
- 测试脚本: /volume3/storage/docker/nav-portal/http_test.py
- PHP规范: nav-portal/PHP开发注意事项.md
- 自动化方案: nav-portal/自动化测试与修复方案.md


---

## 完整自动化流程

你 -> AI读文件 -> AI改代码 -> sync_file同步 -> php_lint检查
-> run_test测试 -> 失败则read_log分析继续修复 -> 全通过汇报

---

## Cursor Rules（自动注入工作流）

创建 .cursor/rules/workflow.md 内容：

每次修改代码后必须：
1. MCP sync_file 同步（含MD5验证）
2. MCP php_lint 检查语法
3. MCP run_test 运行测试
4. 失败则继续修复，不停下来问用户
5. 全通过才汇报结果

禁止：改完不同步就汇报、测试失败等用户确认、容器内curl自身

---

## 最简日常指令格式

[做什么] + [验收条件] + [失败继续修复]

示例：
- 给备份列表加排序，run_test 22/22通过，失败继续修复
- 修复分组AJAX返回401，run_test分组模块3/3通过
- 给登录页加记住我功能，run_test通过后汇报修改了哪些文件

---

## MCP工具速查

修改后同步: sync_file
批量同步: sync_all_php
确认同步: check_md5
语法检查: php_lint
完整测试: run_test
查看日志: read_log
容器命令: docker_exec
重置安装: reset_nav
测试接口: http_request

---

## 关联文件

MCP Server: /volume3/storage/docker/nav-portal/mcp-server/server.js
测试脚本: /volume3/storage/docker/nav-portal/http_test.py
PHP规范: nav-portal/PHP开发注意事项.md
自动化方案: nav-portal/自动化测试与修复方案.md
