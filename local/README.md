# 本地开发与进阶说明

这个文件收纳根目录 README 不再展开的内容：

- 本地开发
- 自动化测试
- 高级环境变量
- 数据目录说明
- CLI 管理命令
- 开发用 compose 组合方式

如果你只是第一次部署项目，先看根目录的 [README.md](../README.md) 就够了。

当前镜像基础为 `php:8.2-fpm-alpine`（Alpine Linux，musl libc）。相比 Debian 系，Alpine 镜像体积更小（基础 ~80MB，最终 ~150MB），适合 NAS、软路由、VPS 等资源受限场景。支持 `linux/amd64` 和 `linux/arm64` 双架构。

> 注意：Alpine 使用 musl libc（而非 glibc），绝大多数 PHP 应用和常见 CLI 工具均可正常运行。如果在计划任务中执行第三方预编译二进制，请确认其支持 musl 或 Alpine 环境。

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
