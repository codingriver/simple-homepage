# Host-Agent 软件包管理模块

## 功能概述

软件包管理模块是 Host-Agent 的 Phase 1 功能，用于在宿主机上统一管理各类 Linux/Unix 发行版的软件包。通过抽象层屏蔽不同包管理器的差异，提供统一的安装、卸载、搜索、查询、升级接口。

## 支持的包管理器

Host-Agent 自动检测宿主机上的包管理器，检测顺序如下：

| 包管理器 | 检测命令 | 典型发行版 |
|---------|---------|-----------|
| `brew` | `command -v brew` | macOS (Homebrew) |
| `port` | `command -v port` | macOS (MacPorts) |
| `apt` | `command -v apt-get` | Debian/Ubuntu |
| `dnf` | `command -v dnf` | Fedora/RHEL 8+ |
| `yum` | `command -v yum` | CentOS/RHEL 7 |
| `apk` | `command -v apk` | Alpine Linux |
| `pacman` | `command -v pacman` | Arch Linux |
| `zypper` | `command -v zypper` | openSUSE |
| `emerge` | `command -v emerge` | Gentoo |

> 若未检测到任何已知包管理器，返回 `unknown`，此时包管理功能不可用。

## 支持的服务管理器

服务启停操作自动检测以下服务管理器：

| 服务管理器 | 检测条件 | 典型发行版 |
|-----------|---------|-----------|
| `systemd` | `/run/systemd/system` 存在且 `systemctl` 可用 | 大多数现代 Linux |
| `openrc` | `rc-service` 和 `rc-update` 可用 | Alpine/Gentoo |
| `runit` | `sv` 可用且 `/var/service` 或 `/etc/service` 存在 | Void Linux |
| `sysvinit` | `service` 命令可用 | 旧版发行版 |
| `launchd` | `launchctl` 可用 | macOS |

## 包名映射机制

不同发行版对同一软件的包名可能不同。Host-Agent 内置 28 个常用软件的跨发行版包名映射：

| 通用名 | apt | dnf/yum | apk | pacman | zypper | brew |
|--------|-----|---------|-----|--------|--------|------|
| nginx | nginx | nginx | nginx | nginx | nginx | nginx |
| apache | apache2 | httpd | apache2 | apache | apache2 | httpd |
| mysql | mysql-server | mysql-server | mysql | mariadb | mysql | mysql |
| mariadb | mariadb-server | mariadb-server | mariadb | mariadb | mariadb | mariadb |
| postgresql | postgresql | postgresql-server | postgresql | postgresql | postgresql | postgresql |
| redis | redis-server | redis | redis | redis | redis | redis |
| memcached | memcached | memcached | memcached | memcached | memcached | memcached |
| mongodb | mongodb-org | mongodb-org | mongodb | mongodb | mongodb | mongodb-community |
| nodejs | nodejs | nodejs | nodejs | nodejs | nodejs | node |
| npm | npm | npm | npm | npm | npm | npm |
| python3 | python3 | python3 | python3 | python | python3 | python@3.11 |
| php | php | php | php82 | php | php | php |
| php-fpm | php-fpm | php-fpm | php82-fpm | php-fpm | php-fpm | php |
| docker | docker.io | docker | docker | docker | docker | docker |
| git | git | git | git | git | git | git |
| vim | vim | vim | vim | vim | vim | vim |
| htop | htop | htop | htop | htop | htop | htop |
| curl | curl | curl | curl | curl | curl | curl |
| wget | wget | wget | wget | wget | wget | wget |
| openssh-server | openssh-server | openssh-server | openssh-server | openssh | openssh | — |
| sudo | sudo | sudo | sudo | sudo | sudo | — |
| ufw | ufw | firewalld | ufw | ufw | firewalld | — |
| fail2ban | fail2ban | fail2ban | fail2ban | fail2ban | fail2ban | — |
| samba | samba | samba | samba | samba | samba | samba |
| nfs | nfs-kernel-server | nfs-utils | nfs-utils | nfs-utils | nfs-client | — |
| rsync | rsync | rsync | rsync | rsync | rsync | rsync |

调用 API 时传入通用名（如 `mysql`），Host-Agent 会自动解析为对应包管理器的实际包名。

## API 接口说明

所有接口通过 `admin/host_api.php` 暴露，请求方式为 POST。

### 1. 获取包管理器信息

```
POST admin/host_api.php
action=package_manager
```

返回：
```json
{
  "ok": true,
  "manager": "apt",
  "service_manager": "systemd"
}
```

### 2. 搜索软件包

```
POST admin/host_api.php
action=package_search&keyword=nginx&limit=50
```

返回：
```json
{
  "ok": true,
  "results": [
    {"name": "nginx", "description": "..."}
  ]
}
```

### 3. 查询软件包详情

```
POST admin/host_api.php
action=package_info&pkg=nginx
```

返回：
```json
{
  "ok": true,
  "pkg": "nginx",
  "installed": true,
  "info": "..."
}
```

### 4. 安装软件包

```
POST admin/host_api.php
action=package_install&pkg=nginx
```

返回：
```json
{
  "ok": true,
  "msg": "nginx installed successfully"
}
```

### 5. 卸载软件包

```
POST admin/host_api.php
action=package_remove&pkg=nginx&purge=0
```

`purge=1` 表示彻底删除（包括配置文件）。

### 6. 更新软件包

```
POST admin/host_api.php
action=package_update&pkg=nginx
```

### 7. 全系统升级

```
POST admin/host_api.php
action=package_upgrade_all
```

### 8. 列出已安装包

```
POST admin/host_api.php
action=package_list&limit=500
```

## 权限要求

| 操作 | 所需权限 |
|------|---------|
| 所有读操作（manager/search/info/list） | `ssh.view` 或 `ssh.manage` |
| 所有写操作（install/remove/update/upgrade_all） | `ssh.package.manage` 或 `ssh.manage` |

菜单入口显示条件：`ssh.package.manage || ssh.manage`。

## 审计日志

所有写操作都会通过 `ssh_manager_audit()` 记录审计日志，包含操作类型、包名、执行结果。

## 管理页面

前端管理页面位于 `admin/packages.php`，功能包括：
- 显示当前检测到的包管理器和服务管理器
- 搜索并安装软件包
- 常用软件一键安装（nginx/mysql/redis 等）
- 已安装包列表与版本信息
- 全系统升级按钮
