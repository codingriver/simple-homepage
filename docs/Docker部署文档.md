# RiverOps Docker 部署文档

RiverOps 官方镜像为 `codingriver/riverops:latest`，支持 `linux/amd64` 和 `linux/arm64`。

## 1. 环境要求

- Docker Engine 20.10+
- Docker Compose v2
- 至少 1 核 CPU、128MB 可用内存
- 一个可写的持久化数据目录

## 2. Compose 部署

```yaml
services:
  riverops:
    image: codingriver/riverops:latest
    container_name: riverops
    restart: unless-stopped
    ports:
      - "58080:58080"
    environment:
      RIVEROPS_PORT: "58080"
      TZ: "Asia/Shanghai"
      PUID: "${PUID:-}"
      PGID: "${PGID:-}"
    volumes:
      - ./data:/var/www/riverops/data
```

```bash
mkdir -p ~/riverops/data
cd ~/riverops
docker compose up -d
```

浏览器访问 `http://服务器IP:58080` 完成首次安装。

## 3. 无人值守安装

| 变量 | 说明 |
|---|---|
| `ADMIN` | 管理员用户名 |
| `PASSWORD` | 管理员密码，至少 8 位 |
| `NAME` | 控制台名称，默认 `RiverOps` |
| `DOMAIN` | 面板域名，可留空 |

这些变量只用于生成首次安装数据。安装完成后不应长期保留明文密码。

## 4. RiverOps 环境变量

| 变量 | 默认值 | 说明 |
|---|---|---|
| `RIVEROPS_PORT` | `58080` | 容器内监听端口 |
| `RIVEROPS_DEV_MODE` | 空 | 开发模式 |
| `RIVEROPS_REQUEST_TIMING` | `1` | 设为 `0` 关闭请求耗时日志 |
| `RIVEROPS_REQUEST_TIMING_CLI` | `0` | 设为 `1` 记录 CLI 请求耗时 |
| `TZ` | `Asia/Shanghai` | 时区 |
| `PUID` / `PGID` | 自动检测 | 运行用户 UID/GID |
| `AUTH_SECRET_KEY` | 自动生成 | 可选认证密钥 |

旧品牌环境变量不受支持。

## 5. 容器目录与用户

- 项目目录：`/var/www/riverops`
- 数据目录：`/var/www/riverops/data`
- 用户和组：`riverops`
- HOME：`/home/riverops`
- PHP-FPM：`/usr/local/etc/php-fpm.d/riverops.conf`
- PHP 配置：`/usr/local/etc/php/conf.d/99-riverops-custom.ini`
- Nginx 站点：`/etc/nginx/http.d/riverops.conf`
- Nginx 日志：`/var/log/nginx/riverops.access.log`、`riverops.error.log`

## 6. 持久化配置

首次启动会在数据目录生成：

```text
data/nginx/nginx.conf
data/nginx/http.d/riverops.conf
data/php-fpm/riverops.conf
data/php/custom.ini
```

后台仅提供查看能力。修改这些文件后，需要重启容器：

```bash
docker restart riverops
```

## 7. 常用命令

```bash
docker compose pull
docker compose up -d
docker compose logs -f
docker exec -it riverops sh
docker exec riverops nginx -t
docker exec riverops php /var/www/riverops/manage_users.php list
docker exec riverops php /var/www/riverops/manage_users.php passwd admin 新密码
```

## 8. 备份与恢复

```bash
cd ~/riverops
tar -czf riverops-backup-$(date +%Y%m%d).tar.gz ./data
tar -xzf riverops-backup-20260716.tar.gz
```

本次品牌重命名为破坏性升级，旧环境变量、旧 Cookie、旧容器路径、旧持久化服务配置和旧备份不属于支持范围。推荐使用全新的数据目录部署。

## 9. 排障

```bash
docker ps --filter name=riverops
docker logs riverops
docker exec riverops id riverops
docker exec riverops ls -la /var/www/riverops/data
docker exec riverops nginx -t
docker exec riverops /usr/local/sbin/php-fpm -t --fpm-config /usr/local/etc/php-fpm.d/riverops.conf
```

若数据目录不可写，确保宿主机目录 owner 与 `PUID`/`PGID` 一致。Named Volume 首次使用时也必须允许 UID/GID 1000 写入。
