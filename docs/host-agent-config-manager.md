# Host-Agent 配置管理模块

## 功能概述

配置管理模块是 Host-Agent 的 Phase 2 功能，用于安全地编辑和管理宿主机上的常用服务配置文件。核心特点是**原子操作**：每次修改前自动备份，校验失败时自动回滚，确保系统配置始终可恢复。

## 支持的配置项

| 配置项 | 图标 | 配置文件路径（示例） | 格式 | 校验命令 | 重载命令 |
|--------|------|---------------------|------|---------|---------|
| `nginx` | 🌐 | `/etc/nginx/nginx.conf` | nginx | `nginx -t` | `nginx -s reload` |
| `php-fpm` | 🐘 | `/etc/php/8.2/fpm/php.ini` | ini | — | — |
| `redis` | 🔴 | `/etc/redis/redis.conf` | redis_conf | — | — |
| `ssh` | 🔐 | `/etc/ssh/sshd_config` | ssh_config | — | — |
| `mysql` | 🐬 | `/etc/mysql/my.cnf` | ini | — | — |
| `postgresql` | 🐘 | `/etc/postgresql/16/main/postgresql.conf` | postgresql_conf | — | — |

> 不同发行版的实际路径可能不同，Host-Agent 会根据检测到的包管理器自动选择对应路径。macOS (Homebrew) 路径使用 `/opt/homebrew/etc/` 前缀。

## 原子操作工作流

配置应用遵循以下原子流程：

```
┌─────────┐    ┌─────────┐    ┌─────────┐    ┌─────────┐    ┌─────────┐
│  读取   │ → │  备份   │ → │  写入   │ → │  校验   │ → │  重载   │
│ 当前配置 │    │ 原文件   │    │ 新内容   │    │ validate │    │ reload  │
└─────────┘    └─────────┘    └─────────┘    └────┬────┘    └────┬────┘
                                                   │              │
                                              失败 │         失败 │
                                                   ↓              ↓
                                              ┌─────────┐    ┌─────────┐
                                              │  自动回滚 │    │  自动回滚 │
                                              │ 恢复备份  │    │ 恢复备份  │
                                              └─────────┘    └─────────┘
```

### 回滚机制

- 每次 `config_apply` 前，原配置文件会被复制到 `/tmp/host-agent-config-backup-{configId}-{timestamp}`
- 若校验命令返回非零退出码，自动将备份文件恢复回原路径
- 若重载命令返回非零退出码，同样自动回滚
- 回滚成功后返回错误信息，系统配置保持修改前的状态

## API 接口说明

所有接口通过 `admin/host_api.php` 暴露，请求方式为 POST。

### 1. 获取配置定义列表

```
POST admin/host_api.php
action=config_definitions
```

返回：
```json
{
  "ok": true,
  "definitions": {
    "nginx": {
      "label": "Nginx",
      "icon": "🌐",
      "path": "/etc/nginx/nginx.conf",
      "format": "nginx",
      "validate_cmd": "nginx -t",
      "reload_cmd": "nginx -s reload",
      "sections": ["main", "events", "http", "server", "location"]
    }
  }
}
```

### 2. 读取配置内容

```
POST admin/host_api.php
action=config_read&config_id=nginx
```

返回：
```json
{
  "ok": true,
  "content": "user www-data;\nworker_processes auto;\n...",
  "path": "/etc/nginx/nginx.conf",
  "format": "nginx"
}
```

### 3. 应用配置（含校验）

```
POST admin/host_api.php
action=config_apply&config_id=nginx&content=...&validate_only=0
```

`validate_only=1` 时仅执行校验和预写入，不真正写入文件（用于预览）。

返回：
```json
{
  "ok": true,
  "msg": "配置已应用",
  "backup_path": "/tmp/host-agent-config-backup-nginx-20260101120000",
  "validated": true,
  "reloaded": true
}
```

### 4. 校验配置（不写入）

```
POST admin/host_api.php
action=config_validate&config_id=nginx&content=...
```

### 5. 查询备份历史

```
POST admin/host_api.php
action=config_history&config_id=nginx&limit=10
```

返回：
```json
{
  "ok": true,
  "history": [
    {
      "backup_path": "/tmp/host-agent-config-backup-nginx-...",
      "timestamp": "2026-01-01 12:00:00",
      "size": 2048
    }
  ]
}
```

### 6. 恢复备份

```
POST admin/host_api.php
action=config_restore&config_id=nginx&backup_path=/tmp/...
```

恢复操作同样遵循原子流程：先备份当前文件，再恢复指定备份，最后执行校验和重载。

## 权限要求

| 操作 | 所需权限 |
|------|---------|
| 所有读操作（definitions/read/history） | `ssh.view` 或 `ssh.manage` |
| 所有写操作（apply/validate/restore） | `ssh.config.manage` 或 `ssh.manage` |

菜单入口显示条件：`ssh.config.manage || ssh.manage`。

## 审计日志

`config_apply` 和 `config_restore` 操作会记录审计日志，包含配置项 ID、备份路径、执行结果。

## 管理页面

前端管理页面位于 `admin/configs.php`，功能包括：
- 配置卡片列表（显示图标、路径、格式）
- 代码编辑器（支持语法高亮）
- 校验按钮（先校验再应用）
- 应用按钮（备份→写入→校验→重载）
- 备份历史列表与一键恢复
