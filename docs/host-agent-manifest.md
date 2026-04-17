# Host-Agent 声明式管理模块

## 功能概述

声明式管理模块是 Host-Agent 的 Phase 3 功能，允许用户通过 JSON Manifest 描述期望的系统状态，Host-Agent 自动将实际状态对齐到期望状态。支持预演（dry-run）模式，在真正执行前预览所有变更。

## Manifest DSL 语法

Manifest 是一个 JSON 对象，包含以下可选顶级字段：

### 1. packages — 软件包状态

```json
{
  "packages": {
    "nginx": { "state": "installed" },
    "apache": { "state": "absent" },
    "mysql": { "state": "installed" }
  }
}
```

- `state`: `"installed"` 或 `"absent"`
- 包名使用通用名，Host-Agent 自动解析为当前包管理器的实际包名

### 2. services — 服务状态

```json
{
  "services": {
    "nginx": { "state": "running", "enabled": true },
    "mysql": { "state": "stopped", "enabled": false }
  }
}
```

- `state`: `"running"` 或 `"stopped"`，也可为空表示不管理运行状态
- `enabled`: `true` 或 `false`，控制开机自启

### 3. configs — 配置变更

```json
{
  "configs": {
    "nginx": {
      "sections": {
        "http": {
          "gzip": "on",
          "gzip_types": "text/plain text/css"
        }
      }
    }
  }
}
```

- 键名必须是 `host_agent_config_definitions()` 返回的有效配置项 ID
- 当前支持 `nginx`、`php-fpm`、`redis`、`ssh`、`mysql`、`postgresql`
- `sections` 下的键值对会被合并到对应配置区块

### 4. users — 用户状态

```json
{
  "users": {
    "deploy": { "state": "present" },
    "testuser": { "state": "absent" }
  }
}
```

- `state`: `"present"` 或 `"absent"`

### 完整示例

```json
{
  "packages": {
    "nginx": { "state": "installed" },
    "mysql": { "state": "installed" },
    "redis": { "state": "installed" }
  },
  "services": {
    "nginx": { "state": "running", "enabled": true },
    "mysql": { "state": "running", "enabled": true },
    "redis": { "state": "running", "enabled": true }
  },
  "configs": {
    "nginx": {
      "sections": {
        "http": {
          "gzip": "on"
        }
      }
    }
  }
}
```

## 执行流程

### validate — 校验

1. 校验 JSON 格式是否合法
2. 校验 Manifest Schema：
   - `packages.*.state` 必须是 `"installed"` 或 `"absent"`
   - `services.*.state` 必须是 `"running"` 或 `"stopped"`（或为空）
   - `services.*.enabled` 必须是布尔值
   - `configs` 的键必须是有效的配置项 ID
   - `users.*.state` 必须是 `"present"` 或 `"absent"`
3. 检查包名映射是否存在（当前包管理器下是否有对应的实际包名）

### dry-run — 预演

执行完整的对齐逻辑，但不实际修改系统：

1. 遍历 `packages`，对比期望状态与实际安装状态，输出需要 install/remove 的操作
2. 遍历 `services`，对比期望运行状态/自启状态与实际状态，输出需要 start/stop/enable/disable 的操作
3. 遍历 `configs`，对比期望配置与实际配置，输出需要修改的配置项

返回结果中包含 `dry_run: true` 标记的操作列表。

### apply — 应用

执行实际的状态对齐：

1. 先执行 schema 校验
2. 按顺序执行：packages → services → configs → users
3. 每个操作记录执行结果（成功/失败、错误信息）
4. 返回完整的变更列表

## API 接口说明

所有接口通过 `admin/host_api.php` 暴露，请求方式为 POST。

### 1. 校验 Manifest

```
POST admin/host_api.php
action=manifest_validate&manifest_json={...}
```

返回：
```json
{
  "ok": true,
  "valid": true
}
```

或：
```json
{
  "ok": false,
  "valid": false,
  "errors": ["packages.apache.state 必须是 \"installed\" 或 \"absent\""]
}
```

### 2. 预演（Dry-Run）

```
POST admin/host_api.php
action=manifest_dry_run&manifest_json={...}
```

返回：
```json
{
  "ok": true,
  "changed": true,
  "changes": [
    { "type": "package", "name": "nginx", "action": "install", "dry_run": true }
  ]
}
```

### 3. 应用

```
POST admin/host_api.php
action=manifest_apply&manifest_json={...}
```

返回：
```json
{
  "ok": true,
  "changed": true,
  "changes": [
    { "type": "package", "name": "nginx", "action": "install", "ok": true, "msg": "..." }
  ],
  "errors": []
}
```

## 权限要求

| 操作 | 所需权限 |
|------|---------|
| `manifest_validate` | `ssh.view` 或 `ssh.manage` |
| `manifest_dry_run` | `ssh.view` 或 `ssh.manage` |
| `manifest_apply` | `ssh.manage` |

菜单入口显示条件：`ssh.manage`。

## 审计日志

`manifest_apply` 操作会记录审计日志，包含执行结果和是否有变更。

## 管理页面

前端管理页面位于 `admin/manifests.php`，功能包括：
- JSON 编辑器（带语法校验）
- 校验按钮（验证 Manifest Schema）
- 预演按钮（预览变更，不实际执行）
- 应用按钮（真正执行状态对齐）
- 执行结果展示（变更列表、错误信息）
