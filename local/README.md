# 本地 Docker 开发

## 一次性准备

```bash
cp local/.env.example local/.env   # 按需改端口、DATA_DIR 等
```

数据目录默认 `../data`（相对项目根），首次启动会自动创建。

### 无人值守安装（跳过安装向导）

在 `local/.env` 或 Compose `environment` 中设置：

| 变量 | 说明 |
|------|------|
| `ADMIN` | 管理员用户名（必填，2–32 位字母数字下划线横杠）；若变量存在但为空或非法，将**不**执行无人值守，强制打开安装向导 |
| `PASSWORD` | 可选，可留空（无密码登录）；安装向导仍要求≥8 位 |
| `NAME` | 可选，站点名称，默认「导航中心」 |
| `DOMAIN` | 可选，导航站域名 |

首次访问站点将自动创建账户、配置与 `.installed`，直接进入登录。**安装成功后**应用会删除 `data/.initial_admin.json`；请勿在生产环境长期把明文密码留在环境变量中（可用 Docker Secrets 或部署后清空）。

## 推荐命令（在项目根目录执行）

| 场景 | 命令 |
|------|------|
| 首次或改了 `Dockerfile` / 基础镜像依赖 | `bash local/docker-build.sh dev` |
| 日常改 PHP/CSS/JS，只重启容器、不重建镜像 | `bash local/docker-build.sh dev start` |
| 看日志 | `bash local/docker-build.sh dev logs -f` |
| 停止 | `bash local/docker-build.sh dev down` |

`dev` 会使用 `docker-compose.yml` + `docker-compose.dev.yml`：挂载源码、启用 `NAV_DEV_MODE`、加载 `php-dev.ini`（显示错误等）。登录页可用内置管理员 **qatest / qatest2026**（详见 `shared/auth.php` 与登录页说明）。

## 与「非 dev」模式的区别

- `bash local/docker-build.sh`（无 `dev`）：`--no-cache` 全量构建，**不**挂载源码，适合验证生产镜像行为。

## 帮助

```bash
bash local/docker-build.sh help
```
