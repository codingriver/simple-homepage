# Simple Homepage

一个适合新手的、自托管的导航网站，支持 Docker 一键运行。

- GitHub 仓库：[https://github.com/codingriver/simple-homepage](https://github.com/codingriver/simple-homepage)
- Docker Hub：[https://hub.docker.com/r/codingriver/simple-homepage](https://hub.docker.com/r/codingriver/simple-homepage)

## 功能特点
- 简单易用的导航首页
- 首次访问自动进入安装向导
- 支持站点分组、站点管理、后台管理
- 支持数据持久化，重建容器不丢数据
- 支持 Docker / Docker Compose 部署

## 1 分钟快速开始

### 方式一：直接用 `docker run`

```bash
docker run -d \
  --name simple-homepage \
  -p 58080:80 \
  -v $(pwd)/data:/var/www/nav/data \
  --restart unless-stopped \
  codingriver/simple-homepage:latest
```

启动后访问：

- `http://你的服务器IP:58080`

首次访问会自动跳转到安装向导，按页面提示完成初始化即可。

---

### 方式二：使用 `docker compose`

新建 `docker-compose.yml`：

```yaml
services:
  simple-homepage:
    image: codingriver/simple-homepage:latest
    container_name: simple-homepage
    restart: unless-stopped
    ports:
      - "58080:80"
    volumes:
      - ./data:/var/www/nav/data
```

启动：

```bash
docker compose up -d
```

如果你的环境仍是旧版独立安装命令，也可以：

```bash
docker-compose up -d
```

---

## 数据目录说明
容器内数据目录固定为：

```bash
/var/www/nav/data
```

建议一定要挂载出来，否则重建容器后会丢失：
- 用户账号
- 站点配置
- 分组配置
- 背景图
- 日志
- 备份数据

例如宿主机挂载：

```bash
-v $(pwd)/data:/var/www/nav/data
```

---

## 常用操作

### 完整重置并重新进入安装向导

```bash
docker exec simple-homepage php /var/www/nav/manage_users.php reset
```

`reset` 会同时清空：
- 用户数据
- 安装锁 `.installed`
- `data/logs/auth.log` 登录日志
- 站点与分组配置
- 备份文件
- 反代配置
- IP 锁定记录

并自动重置 Cookie 相关配置、刷新 `AUTH_SECRET_KEY`、检查 `proxy_params_full`。

执行完成后直接打开：
- `http://你的服务器IP:58080/`

### 查看日志

```bash
docker logs -f simple-homepage
```

### 停止容器

```bash
docker stop simple-homepage
```

### 启动容器

```bash
docker start simple-homepage
```

### 删除容器

```bash
docker rm -f simple-homepage
```

### 更新到最新镜像

```bash
docker pull codingriver/simple-homepage:latest
docker rm -f simple-homepage
docker run -d \
  --name simple-homepage \
  -p 58080:80 \
  -v $(pwd)/data:/var/www/nav/data \
  --restart unless-stopped \
  codingriver/simple-homepage:latest
```

---

## 本地开发 / 本地构建

项目内已提供构建脚本（位于 `local/` 目录）：

```bash
# 1. 复制配置文件
cp local/.env.example local/.env
# 2. 按需修改 local/.env（端口、数据目录、代理等）
# 3. 一键构建并启动
bash local/docker-build.sh
```

支持通过 `local/.env` 控制构建时是否启用代理：

```bash
BUILD_USE_PROXY=0          # 禁用代理（默认）
BUILD_USE_PROXY=1          # 启用代理
BUILD_PROXY_URL=http://127.0.0.1:7890
```

---

## 相关链接

- GitHub 仓库：[https://github.com/codingriver/simple-homepage](https://github.com/codingriver/simple-homepage)
- Docker Hub：[https://hub.docker.com/r/codingriver/simple-homepage](https://hub.docker.com/r/codingriver/simple-homepage)

---

## 适合谁使用
如果你想要一个：
- 安装简单
- 配置直观
- 小白也能快速上手
- 用来收藏常用网站和服务入口

那这个镜像就是为你准备的。
