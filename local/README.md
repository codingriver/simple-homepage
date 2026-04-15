# 本地开发与进阶说明

这个文件收纳根目录 README 不再展开的内容：

- 本地开发
- 自动化测试
- 高级环境变量
- 数据目录说明
- CLI 管理命令
- 开发用 compose 组合方式

如果你只是第一次部署项目，先看根目录的 [README.md](../README.md) 就够了。

当前镜像基础已切换到 Debian 系 `php:8.2-fpm-bookworm`。相比 Alpine，镜像体积会更大，但计划任务里执行常见第三方 Linux 二进制的兼容性更好。

## 1. 生产部署的进阶参数

根目录 README 使用的是最简单的部署方式。下面这些内容适合需要进一步定制的人。

### 环境变量

| 变量 | 默认值 | 说明 |
| --- | --- | --- |
| `NAV_PORT` | `58080` | 容器内监听端口 |
| `TZ` | `Asia/Shanghai` | 容器时区 |
| `PUID` | 空 | 可选；显式指定容器运行用户 UID |
| `PGID` | 空 | 可选；显式指定容器运行用户 GID |
| `ADMIN` | 空 | 首次启动时自动创建管理员用户名 |
| `PASSWORD` | 空 | 首次启动时自动创建管理员密码 |
| `NAME` | `导航中心` | 首次启动时站点名称 |
| `DOMAIN` | 空 | 首次启动时导航站域名 |
| `NAV_DEV_MODE` | 空 | 开发模式，会启用内置测试管理员 |
| `NAV_REQUEST_TIMING` | `1` | 设为 `0` 可关闭请求耗时日志 |
| `AUTH_SECRET_KEY` | 空 | 可显式指定认证密钥 |
| `HOST_AGENT_INSTALL_MODE` | `host` | `host` 为真实宿主机模式；开发/测试建议用 `simulate` |

### 数据目录

必须挂载：

```text
/var/www/nav/data
```

常见文件和目录：

```text
data/
├── .installed
├── auth_secret.key
├── config.json
├── sites.json
├── users.json
├── scheduled_tasks.json
├── dns_config.json
├── ip_locks.json
├── backups/
├── logs/
├── tasks/
├── bg/
└── favicon_cache/
```

说明：

- `users.json` 保存用户
- `sites.json` 保存站点和分组
- `scheduled_tasks.json` 保存计划任务定义和运行结果
- `logs/` 保存各类日志
- `tasks/` 是计划任务共享工作目录，任务脚本会持久保存为 `data/tasks/<脚本文件名>.sh`
- 计划任务脚本和运行日志都位于 `data/tasks/`
  - 脚本文件为 `xxx.sh`
  - 对应日志文件为同名 `xxx.log`
- `backups/` 保存备份快照

## 2. 容器内 CLI 管理命令

查看用户列表：

```bash
docker exec simple-homepage php /var/www/nav/manage_users.php list
```

查看用户信息：

```bash
docker exec simple-homepage php /var/www/nav/manage_users.php info admin
```

新增管理员：

```bash
docker exec simple-homepage php /var/www/nav/manage_users.php add admin 新密码
```

修改密码：

```bash
docker exec simple-homepage php /var/www/nav/manage_users.php passwd admin 新密码
```

删除用户：

```bash
docker exec simple-homepage php /var/www/nav/manage_users.php del admin
```

重置安装状态：

```bash
docker exec simple-homepage php /var/www/nav/manage_users.php reset
```

`reset` 会清空安装状态、站点配置、登录锁定和反代配置，并重新进入安装向导。备份文件会保留。

## 3. 本地 Docker 开发

### 一次性准备

```bash
cp local/.env.example local/.env
```

然后按需修改 `local/.env`。

数据目录默认是项目根目录下的 `data/`。

Linux bind mount 默认会在容器启动时自动按 `data` 目录 owner 对齐 `PUID` / `PGID`；如果自动检测到 `0:0`，会回退到镜像默认用户 `1000:1000`，避免自动提权。只有自动检测结果不符合预期时，才需要在 `local/.env` 里显式覆盖。

### 推荐命令

| 场景 | 命令 |
| --- | --- |
| 启动开发环境 | `bash local/docker-build.sh dev` |
| 强制重建开发镜像 | `bash local/docker-build.sh dev rebuild` |
| 仅重启开发容器 | `bash local/docker-build.sh dev start` |
| 查看日志 | `bash local/docker-build.sh dev logs -f` |
| 停止开发环境 | `bash local/docker-build.sh dev down` |

开发模式会：

- 挂载源码目录
- 启用 `NAV_DEV_MODE`
- 加载 `local/php-dev.ini`
- 提供内置测试管理员 `qatest / qatest2026`
- 在 `local/docker-compose.dev.yml` 中默认临时挂载 `docker.sock`，方便验证后台一键安装 `host-agent`
- 同时默认设置 `HOST_AGENT_INSTALL_MODE=simulate`，避免开发测试时真的改宿主机

### Host-Agent 开发说明

开发环境里这套能力默认按“安全模拟”运行：

- 后台允许检测 `docker.sock` 并演示一键安装
- 安装出的 `host-agent` 容器会以 `simulate` 模式启动
- `simulate` 模式下依然会复用当前应用的 `data/` 挂载，所以 SSH 配置与状态文件会持久保存在共享数据目录
- 这种模式不会真正修改宿主机 SSH、服务或系统文件

如果你要验证真实宿主机模式，需要你自己明确切到：

```yaml
HOST_AGENT_INSTALL_MODE: "host"
```

同时保留：

```yaml
- /var/run/docker.sock:/var/run/docker.sock
```

验证完成后，仍建议把 `docker.sock` 挂载移除，避免长期暴露 Docker API。

### 本机文件系统实现原理

当前项目里的“本机文件系统”并不是页面直接读写磁盘，而是统一经由 `host-agent` 执行。

调用链：

```text
admin/files.php
  -> admin/file_api.php
  -> admin/shared/file_manager_lib.php
  -> admin/shared/host_agent_lib.php
  -> host-agent API
  -> cli/host_agent.php
```

关键点：

- `admin/files.php` 只负责界面和交互
- `admin/file_api.php` 负责权限校验、CSRF、审计记录、参数整理
- `file_manager_lib.php` 负责“本机 / 远程主机”目标抽象、收藏目录、最近访问等附加能力
- 真正的文件读写、列目录、重命名、复制、权限修改、压缩解压，都由 `host_agent_fs_*` 系列接口完成
- `admin/shared/host_agent_lib.php` 不直接操作文件，它只是把请求转发给 `host-agent`
- `cli/host_agent.php` 才是最终执行层

这意味着“本机”的真实含义取决于 `host-agent` 模式：

- `simulate` 模式：
  - 本机文件操作落到 `data/host-agent-sim-root/` 下的模拟根目录
  - 适合开发和自动化测试
  - 不会真实修改宿主机文件
- `host` 模式：
  - `host-agent` 容器以特权方式运行，并挂载宿主机根目录到 `/hostfs`
  - 文件 API 由 `host-agent` 在宿主机视角执行
  - 这时后台本机文件管理才是真正意义上的宿主机文件管理

如果当前只是普通应用容器，没有启用 `host-agent host` 模式，那么页面里“本机”默认看到的是容器视角文件系统；只有挂载卷对应的路径，改动才会映射到宿主机。

### 本机 SSH 配置实现原理

本机 SSH 管理也统一走 `host-agent`，不是 `hosts.php` 里直接跑 `systemctl` 或直接改 `/etc/ssh/sshd_config`。

调用链：

```text
admin/hosts.php
  -> admin/host_api.php
  -> admin/shared/host_agent_lib.php
  -> host-agent API
  -> cli/host_agent.php
```

对应能力包括：

- 读取 SSH 服务状态
- 读取原始 `sshd_config`
- 结构化修改常见指令
- `sshd -t` 配置校验
- 保存前自动备份
- 失败后恢复最近备份
- 启动 / 停止 / 重载 / 重启
- 开机启动开关
- 自动安装 `openssh-server`

真实 `host` 模式下的底层做法：

- 配置校验：写临时文件，再调用宿主机 `sshd -t -f 临时文件`
- 配置保存：先备份正式配置，再写回
- 服务控制：优先走 `systemctl`，退化走 `service`
- 自启控制：优先走 `systemctl enable/disable`，退化走 `update-rc.d` 或 `chkconfig`
- 安装 SSH：按包管理器选择 `apt / dnf / yum / apk`

`simulate` 模式下的做法：

- 配置文件写入模拟根目录里的 `etc/ssh/sshd_config`
- 运行中 / 已安装 / 开机启动状态写入 `data/host-agent-sim-root/var/lib/host-agent/` 下的状态文件
- 页面交互、接口返回、审计流程和真实模式保持一致
- 但不会真的修改宿主机 SSH

这也是为什么开发环境可以完整测试 SSH 页面，而不需要真的去重启你本机的 SSH 服务。

## 4. Compose 文件说明

仓库里常见的 compose 文件有：

- [docker-compose.yml](../docker-compose.yml)：给新手的生产部署模板
- [local/docker-compose.yml](./docker-compose.yml)：本地开发基础 compose
- [local/docker-compose.dev.yml](./docker-compose.dev.yml)：开发增强覆盖
- [local/docker-compose.test.yml](./docker-compose.test.yml)：测试服务覆盖

## 5. 自动化测试

### Playwright E2E

先启动开发容器：

```bash
bash local/docker-build.sh dev
```

运行桌面端全量测试：

```bash
docker compose -f local/docker-compose.yml -f local/docker-compose.dev.yml -f local/docker-compose.test.yml run --rm playwright-full
```

运行移动端测试：

```bash
docker compose -f local/docker-compose.yml -f local/docker-compose.dev.yml -f local/docker-compose.test.yml run --rm playwright-mobile
```

查看当前测试服务数量：

```bash
npm run test:running
```

测试产物目录：

- `playwright-report/`
- `test-results/`

### Lighthouse

```bash
docker compose -f local/docker-compose.yml -f local/docker-compose.dev.yml -f local/docker-compose.test.yml run --rm lighthouse
```

产物目录：

- `lighthouse-report/`

## 6. 无人值守安装

如果想在开发或测试环境里跳过安装向导，可以在 `local/.env` 或 compose 环境变量中设置：

| 变量 | 说明 |
| --- | --- |
| `ADMIN` | 管理员用户名 |
| `PASSWORD` | 管理员密码，可留空但不建议 |
| `NAME` | 站点名称 |
| `DOMAIN` | 导航站域名 |

首次访问时会自动创建账户、配置与 `.installed`。

安装成功后，应用会删除 `data/.initial_admin.json`。不要在生产环境长期保留明文密码。

## 7. 帮助

查看开发脚本帮助：

```bash
bash local/docker-build.sh help
```

更多历史文档可继续查看 `docs/` 目录。
