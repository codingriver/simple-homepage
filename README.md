# Simple Homepage

一个适合个人、家庭网络、NAS、软路由、小型 VPS 使用的私有导航首页。

它不只是书签页，还带这些能力：

- 登录保护
- 分组和站点管理
- 反向代理入口
- DNS 管理
- DDNS 动态解析
- 计划任务
- 配置备份与恢复
- 调试工具

项目地址：

- GitHub: <https://github.com/codingriver/simple-homepage>
- Docker Hub: <https://hub.docker.com/r/codingriver/simple-homepage>

镜像支持：

- `linux/amd64`
- `linux/arm64`

运行基础：

- Docker 镜像当前基于 Debian 系 `php:8.2-fpm-bookworm`
- 这样更适合计划任务里执行常见第三方 Linux 二进制，兼容性比 Alpine 更好

## 适合谁

如果你希望把这些东西集中到一个网页后台里，这个项目就适合你：

- 家里有 NAS、路由器、下载器、影视服务、面板，想做统一入口
- 有一台 VPS，想把常用站点、反代入口、DDNS、DNS 管理放到一起
- 不想装 MySQL、Redis，只想用 Docker 跑起来
- 希望数据都保存在本地目录里，重建容器也不丢

## 主要功能

### 前台导航

- 分组展示站点卡片
- 搜索站点
- 支持公开分组和登录后可见分组
- 支持背景图、背景色、卡片大小等设置
- 自动抓取 favicon
- 适配手机和桌面浏览器

### 后台管理

- 站点管理：普通链接、内部直达、反向代理
- 分组管理：增删改查、排序、权限范围
- 用户管理：管理员和普通用户
- 系统设置：站点名称、域名、Cookie、安全项、Webhook
- 备份恢复：导出、导入、恢复
- 调试工具：日志查看、Cookie 清理、错误显示切换

### 运维相关

- DNS 解析管理，目前支持阿里云 DNS、Cloudflare
- DDNS 动态解析
- 计划任务管理，支持立即执行、持续写日志、日志查看
- 计划任务统一工作目录固定为 `data/tasks`
- 每个计划任务的脚本会落地保存为 `data/tasks/<脚本文件名>.sh`
- 计划任务脚本和运行日志都落地在 `data/tasks/`
  - 脚本文件为 `xxx.sh`
  - 对应日志文件为同名 `xxx.log`

## 部署前准备

你只需要准备：

1. 一台装好 Docker 和 Docker Compose 的 Linux 主机、NAS，或者支持 Docker 的设备
2. 一个准备存放数据的目录
3. 一个浏览器

先确认 Docker 可用：

```bash
docker -v
docker compose version
```

如果这两个命令都能正常输出版本号，就可以继续。

## 小白部署流程

下面是最简单、最推荐的部署方式：直接从 Docker Hub 拉镜像，用 `docker compose` 启动。

### 1. 创建部署目录

```bash
mkdir -p ~/simple-homepage
cd ~/simple-homepage
mkdir -p data
```

`data` 目录很重要，网站配置、用户、日志、备份都放在这里。

### 2. 新建 `docker-compose.yml`

在当前目录创建一个文件：`docker-compose.yml`

内容直接复制下面这一份：

```yaml
# ============================================================
# 官方一键部署（推荐新手）
# 启动: docker compose up -d
# 停止: docker compose down
# 日志: docker compose logs -f
# ============================================================

services:
  simple-homepage:
    image: codingriver/simple-homepage:latest
    container_name: simple-homepage
    restart: unless-stopped

    ports:
      - "58080:58080"

    environment:
      # 容器内默认端口（与端口映射保持一致）
      NAV_PORT: "58080"
      TZ: "Asia/Shanghai"
      # 可选：显式覆盖运行用户 UID/GID；留空时容器启动后自动按 data 目录 owner 对齐
      PUID: "${PUID:-}"
      PGID: "${PGID:-}"

    volumes:
      # 必须挂载：用户、配置、日志、备份都在这里
      - ./data:/var/www/nav/data

    healthcheck:
      disable: true
```

### 3. 拉取镜像并启动

```bash
docker compose pull
docker compose up -d
```

查看是否启动成功：

```bash
docker ps
```

如果你看到容器名 `simple-homepage` 在运行，就说明启动成功了。

### 4. 打开网页

浏览器访问：

```text
http://你的服务器IP:58080
```

例如：

```text
http://192.168.1.10:58080
```

第一次打开会进入安装向导。

### 5. 完成首次安装

按页面提示设置：

- 管理员用户名
- 管理员密码
- 网站名称
- 网站域名（可以先不填完整正式域名，后面再改）

安装完成后，就可以登录后台了。

## 如果想自动创建管理员

如果你不想手动走安装向导，也可以在 `docker-compose.yml` 里增加这些环境变量：

```yaml
environment:
  NAV_PORT: "58080"
  TZ: "Asia/Shanghai"
  PUID: "${PUID:-}"
  PGID: "${PGID:-}"
  ADMIN: "admin"
  PASSWORD: "ChangeMe123!"
  NAME: "我的导航"
  DOMAIN: "nav.example.com"
```

然后重新启动：

```bash
docker compose up -d
```

首次启动时会自动创建管理员账号。

生产环境建议部署完成后把明文密码从 compose 文件里删掉。

## 升级方法

后续更新很简单：

```bash
cd ~/simple-homepage
docker compose pull
docker compose up -d
```

因为数据挂载在 `./data` 目录，所以更新镜像不会清空你的配置。

## 常用命令

查看日志：

```bash
docker compose logs -f
```

重启：

```bash
docker compose restart
```

停止：

```bash
docker compose down
```

进入容器：

```bash
docker exec -it simple-homepage sh
```

## 数据保存在哪里

你本机部署目录下的：

```text
./data
```

容器里的对应路径是：

```text
/var/www/nav/data
```

其中计划任务统一使用的工作目录是：

```text
./data/tasks
```

不是每个任务一个单独目录，而是所有计划任务共享这个目录。

## 默认端口能不能改

可以。

如果你不想用 `58080`，把 compose 里的这一行改掉就行：

```yaml
ports:
  - "58080:58080"
```

例如改成 `8080`：

```yaml
ports:
  - "8080:58080"
```

改完重新执行：

```bash
docker compose up -d
```

然后访问：

```text
http://你的服务器IP:8080
```

## 常见问题

### 打不开页面

先检查：

```bash
docker ps
docker compose logs -f
```

再确认服务器防火墙或路由器没有拦截你映射出来的端口。

### 重建容器后数据没了

通常是因为没有挂载 `./data:/var/www/nav/data`。

这个挂载一定不能删。

### 没有权限写入 `data`

先确保目录存在：

```bash
mkdir -p data
```

如果是 Linux bind mount 场景，还可以按需显式指定：

```bash
export PUID=$(id -u)
export PGID=$(id -g)
docker compose up -d
```

## 进阶内容去哪看

根目录这个 README 只保留新手部署和使用说明。

这些进阶内容已经整理到 [local/README.md](local/README.md)：

- 本地开发
- 测试命令
- 高级环境变量
- 数据目录说明
- CLI 管理命令
- 多个 compose 组合方式

如果你只是想把项目跑起来，用这个 README 就够了。
