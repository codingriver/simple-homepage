# Simple Homepage

一个适合新手的、自托管导航网站镜像，开箱即用，支持 Docker 快速部署。

- GitHub 仓库：[https://github.com/codingriver/simple-homepage](https://github.com/codingriver/simple-homepage)
- Docker Hub：[https://hub.docker.com/r/codingriver/simple-homepage](https://hub.docker.com/r/codingriver/simple-homepage)

---

## 镜像核心信息（先看这里）

- 镜像：`codingriver/simple-homepage:latest`
- 容器内默认端口：`58080`（已改，避免 host 网络模式下与系统 80 端口冲突）
- 数据目录：`/var/www/nav/data`（必须挂载）
- 首次启动：自动进入安装向导
- 后台入口：`/admin/`

---

## 功能总览（镜像已支持）

### 导航与后台
- 分组管理、站点管理（普通链接 + 反向代理类型）
- 后台权限控制（管理员）
- 首次安装初始化管理员账号

### 反向代理（Proxy）
- 支持「路径代理」与「子域名代理」两种模式
- 后台一键生成 `nav-proxy.conf`
- 后台一键 Reload Nginx（容器内）
- 内置常用代理参数：
  - `proxy_http_version 1.1`
  - WebSocket `Upgrade/Connection`
  - `Host / X-Real-IP / X-Forwarded-For / X-Forwarded-Proto`
  - 连接/发送/读取超时

### 安全与鉴权
- 后台使用 `auth_request` 鉴权
- CSRF 防护
- 登录失败次数限制 + IP 锁定
- Cookie 策略（`off/auto/on`）与 `cookie_domain` 配置

### 运维与可观测
- 后台可查看 Nginx/PHP-FPM 日志并支持清空
- 支持手动备份、自动备份、导入导出配置
- 支持 display_errors 开关（调试用）
- 健康检查已内置

### 数据与资源
- 背景图上传
- favicon 缓存
- 站点卡片布局/尺寸/方向配置

---

## 1 分钟快速开始

### 方式一：`docker run`

```bash
docker run -d \
  --name simple-homepage \
  -p 58080:58080 \
  -v $(pwd)/data:/var/www/nav/data \
  --restart unless-stopped \
  codingriver/simple-homepage:latest
```

访问：
- `http://你的服务器IP:58080`

---

### 方式二：`docker compose`

新建 `docker-compose.yml`：

```yaml
services:
  simple-homepage:
    image: codingriver/simple-homepage:latest
    container_name: simple-homepage
    restart: unless-stopped
    ports:
      - "58080:58080"
    volumes:
      - ./data:/var/www/nav/data
```

启动：

```bash
docker compose up -d
```

---

### 方式三：Host 网络模式（更简单）

如果你想少配端口映射，可直接 host 模式：

```bash
docker run -d \
  --name simple-homepage \
  --network host \
  -v $(pwd)/data:/var/www/nav/data \
  --restart unless-stopped \
  codingriver/simple-homepage:latest
```

访问：
- `http://你的服务器IP:58080`

> 说明：镜像内默认监听 58080，所以 host 模式下不会默认抢占系统 80 端口。

---

## 数据目录说明（必须挂载）

容器内固定目录：

```bash
/var/www/nav/data
```

未挂载会导致重建容器后丢失：
- 用户与权限数据
- 站点与分组配置
- 背景图、favicon 缓存
- 登录日志、备份文件

---

## 反向代理（Proxy）使用说明

### 镜像是否自带 `nginx_params_full.conf`？

- **不需要该文件**。
- 当前镜像采用的是 `include /etc/nginx/conf.d/nav-proxy.conf` 机制。
- 容器启动时会自动创建空文件：`/etc/nginx/conf.d/nav-proxy.conf`。
- 你在后台新增代理站点后，点击「生成配置并 Reload Nginx」，系统会自动写入该文件并生效。

### 小白建议流程

1. 先把导航站基础功能跑通
2. 进入后台添加代理站点
3. 在后台「Nginx 反代管理」点击生成并 reload
4. 用浏览器直接验证代理路径是否可访问

---

## 常用运维命令

### 查看日志

```bash
docker logs -f simple-homepage
```

### 停止 / 启动 / 删除

```bash
docker stop simple-homepage
docker start simple-homepage
docker rm -f simple-homepage
```

### 更新镜像

```bash
docker pull codingriver/simple-homepage:latest
docker rm -f simple-homepage
docker run -d \
  --name simple-homepage \
  -p 58080:58080 \
  -v $(pwd)/data:/var/www/nav/data \
  --restart unless-stopped \
  codingriver/simple-homepage:latest
```

### 完整重置并回到安装向导

```bash
docker exec simple-homepage php /var/www/nav/manage_users.php reset
```

---

## 本地构建（仓库维护者）

```bash
cp local/.env.example local/.env
bash local/docker-build.sh
```

---

## 标签策略说明（Docker Hub）

- 常规分支构建统一推送：`latest`
- 只有你打 `v*` git tag 时，才会额外推送版本标签（如 `v1.2.0`）

这样可以避免 tag 混乱，方便新手直接使用 `latest`。
