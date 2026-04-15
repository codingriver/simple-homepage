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
- Host-Agent 一键安装入口

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
- 后台支持检测并一键安装 `host-agent`
- 主机管理页可直接查看和管理本机 SSH 服务
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
      # 可选：只在后台一键安装 / 升级 host-agent 时临时挂载
      # 确认 host-agent 正常后建议移除
      # - /var/run/docker.sock:/var/run/docker.sock

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

## Host-Agent 一键安装说明

如果你后面要在后台里使用“宿主机能力桥接”相关功能，可以在 `系统设置 -> Host-Agent` 中执行一键安装。

这个功能默认不强制开启，选择权在你手里：

- 没挂载 `docker.sock`：后台会明确提示需要挂载，且不会执行安装
- 挂载了 `docker.sock`：后台才允许一键安装 `host-agent`
- 安装完成并确认功能正常后：建议把 `docker.sock` 挂载移除

推荐做法：

1. 先按上面的 compose 注释，临时取消这一行注释：

```yaml
- /var/run/docker.sock:/var/run/docker.sock
```

2. 执行：

```bash
docker compose up -d
```

3. 进入后台 `系统设置 -> Host-Agent`，点击一键安装

4. 确认 `host-agent` 状态正常后，再把这行挂载删掉，并重新执行：

```bash
docker compose up -d
```

这样平时运行更干净，只有安装、升级、重装 `host-agent` 时才需要重新挂回。

安装完成后，后台里会新增“主机管理”能力，可用于：

- 查看本机 SSH 服务状态
- 启动、停止、重载、重启 SSH 服务
- 在线编辑 `sshd_config`

开发/测试环境默认使用 `simulate` 模式，不会真的修改宿主机 SSH。

## 本机文件系统和本机 SSH 是怎么实现的

这两个能力都不是直接让 Web 页面去改系统文件，而是统一走一层 `host-agent`：

- 后台页面发起 AJAX 请求
- PHP 后台把请求转成 `host-agent` API 调用
- `host-agent` 再在目标环境里执行文件或 SSH 操作

这样做的原因是：

- 前台页面和实际系统操作解耦
- 本机和远程主机可以共用一套目标抽象
- 可以区分 `host` 模式和 `simulate` 模式
- 审计、权限判断、失败回滚更容易统一

### 1. 本机文件系统实现原理

调用链大致是：

```text
admin/files.php
  -> admin/file_api.php
  -> admin/shared/file_manager_lib.php
  -> admin/shared/host_agent_lib.php
  -> host-agent HTTP API
  -> cli/host_agent.php
```

实际特点：

- 文件系统页面本机目标不是直接用 PHP 的 `file_get_contents()` 在页面里读写
- 页面操作会调用 `file_api.php`
- `file_api.php` 再通过 `host_agent_fs_list/read/write/delete/...` 这些接口转发给 `host-agent`
- `host-agent` 统一执行目录浏览、读写、重命名、复制、权限修改、压缩解压等操作

你在后台看到的“本机文件系统”，默认是当前运行环境可见的文件系统视角：

- 普通 Docker 部署下，默认看到的是应用容器内的文件系统
- 如果某些目录是宿主机挂载进容器的卷，那么你改到的其实就是宿主机对应目录
- 如果 `host-agent` 以真实 `host` 模式运行，则它可以直接操作宿主机上的 `/hostfs/...`
- 开发/测试常用的 `simulate` 模式不会碰真实宿主机，而是操作 `data/host-agent-sim-root/` 下面的模拟目录

所以“本机文件系统”不是魔法直通宿主机全盘，而是取决于 `host-agent` 当前运行模式和挂载方式。

### 2. 本机 SSH 配置实现原理

调用链大致是：

```text
admin/hosts.php
  -> admin/host_api.php
  -> admin/shared/host_agent_lib.php
  -> host-agent HTTP API
  -> cli/host_agent.php
```

后台里的“本机 SSH 服务”主要包含四类动作：

- 读取 SSH 服务状态
- 读取和保存 `sshd_config`
- 校验配置
- 启停、重载、重启、自启、安装 `openssh-server`

保存 SSH 配置时，流程不是直接覆盖正式配置，而是：

1. 先读取当前配置
2. 先做格式校验
3. 生成备份文件
4. 再写回正式配置
5. 如果选择“保存后自动重启”，则继续尝试重启 SSH
6. 如果重启失败且开启了回滚，会自动恢复最近一次备份

在真实 `host` 模式下：

- `host-agent` 会调用宿主机里的 `sshd -t -f 临时文件` 做校验
- 服务操作会调用 `systemctl` 或 `service`
- 开机启动会调用 `systemctl enable/disable`，或 `update-rc.d` / `chkconfig`
- 自动安装会调用 `apt`、`dnf`、`yum` 或 `apk`

在 `simulate` 模式下：

- 不会真的改宿主机
- SSH 配置会写到共享数据目录下的模拟路径
- SSH 服务状态、自启状态也只是模拟状态文件

也就是说，开发环境里看到的 SSH 管理流程和正式环境界面一致，但底层默认只是在模拟根目录里演练，不会误伤宿主机。

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
