# 导航门户系统 v1.2

基于 PHP 8.2 + Nginx + JSON 文件存储的轻量级私有导航门户，无需数据库。

功能特性：
- 登录保护 + 记住我（60天）
- 后台 Web 界面管理站点/分组/用户
- Nginx 反向代理（路径前缀 + 子域名两种模式）
- SSL 证书管理（Let's Encrypt 申请/续期/下载）
- Web 终端（浏览器内命令行，类宝塔面板，需 ttyd）
- 站点配置导入导出，修改前自动备份

近期新增设计文档：

- `docs/文件系统模块需求与设计文档.md`
- `docs/宿主机运维模块需求与设计文档.md`
- `docs/Docker宿主管理模块需求与设计文档.md`

---

## 目录结构

```
nav-portal/
├── public/                   ← Nginx Web 根目录
│   ├── index.php             ← 导航首页（读 data/sites.json）
│   ├── login.php             ← 登录页（记住我60天）
│   ├── logout.php
│   └── auth/verify.php       ← Nginx auth_request 验证接口
├── admin/                    ← 后台管理（仅 admin 角色）
│   ├── shared/               ← 公共组件（CSS/函数/头尾）
│   ├── index.php             ← 控制台概览 + SSL到期提醒
│   ├── sites.php             ← 站点管理
│   ├── groups.php            ← 分组管理
│   ├── users.php             ← 用户管理
│   ├── settings.php          ← 系统设置 + 导入导出 + 生成Nginx配置
│   ├── ssl.php               ← SSL证书管理
│   └── terminal.php          ← Web终端（二次验证 + ttyd）
├── shared/
│   └── auth.php              ← 核心认证库
├── subsite-middleware/
│   ├── auth_check.php        ← 子站接入中间件
│   └── example_subsite_index.php
├── nav/                      ← 旧版导航（向后兼容，可保留）
├── data/                     ← 数据目录（禁止 Web 访问）
│   ├── sites.json            ★ 站点配置
│   ├── config.json           ★ 系统配置
│   ├── users.json            ★ 账户数据（永久保存，勿删）
│   ├── ip_locks.json         ← IP 锁定记录
│   ├── manage_users.php      ← 命令行用户管理工具
│   ├── backups/              ← 导入前自动备份
│   └── logs/                 ← auth.log / terminal.log
└── nginx-conf/
    ├── nav.conf              ← 主站 Nginx 配置
    └── subsite.conf          ← 子站配置模板
```

---

## 部署步骤（Ubuntu 22.04 + Nginx + PHP 8.2）

### 1. 安装环境

```bash
sudo apt update && sudo apt upgrade -y
sudo apt install -y nginx certbot python3-certbot-nginx
sudo apt install -y software-properties-common
sudo add-apt-repository ppa:ondrej/php -y && sudo apt update
sudo apt install -y php8.2-fpm php8.2-cli php8.2-mbstring php8.2-json php8.2-zip
```

### 2. 上传代码

```bash
git clone your-repo /var/www/nav-portal
# 或 scp -r nav-portal/ root@server:/var/www/

sudo chown -R www-data:www-data /var/www/nav-portal
sudo chmod -R 755 /var/www/nav-portal
sudo chmod -R 700 /var/www/nav-portal/data
```

### 3. 修改配置

编辑 `shared/auth.php`：

```php
define('AUTH_SECRET_KEY', '替换为至少64位随机字符串');
define('NAV_DOMAIN',      'nav.yourdomain.com');
define('COOKIE_DOMAIN',   '.yourdomain.com');   // 注意前面有点
define('NAV_LOGIN_URL',   'https://nav.yourdomain.com/login.php');
```

### 4. 初始化用户

```bash
# 方式A：命令行添加
php /var/www/nav-portal/data/manage_users.php add admin 你的密码 admin

# 方式B：运行初始化脚本（运行后建议删除）
php /var/www/nav-portal/data/init_users.php
rm /var/www/nav-portal/data/init_users.php
```

### 5. 配置 Nginx

```bash
sudo cp /var/www/nav-portal/nginx-conf/nav.conf /etc/nginx/sites-available/
sudo nano /etc/nginx/sites-available/nav.conf   # 修改域名和路径
sudo ln -s /etc/nginx/sites-available/nav.conf /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
```

### 6. 申请 SSL 证书

```bash
# 通配符证书（推荐，一次搞定所有子域）
sudo certbot --nginx -d yourdomain.com -d '*.yourdomain.com'
# 或单域名
sudo certbot --nginx -d nav.yourdomain.com
```

### 7. 创建必要目录

```bash
mkdir -p /var/www/nav-portal/data/backups
mkdir -p /var/www/nav-portal/data/logs
sudo chown -R www-data:www-data /var/www/nav-portal/data
```

---

## Web 终端部署（可选）

需要在服务器安装并启动 ttyd：

```bash
# 安装
sudo apt install -y ttyd
# 或编译安装：https://github.com/tsl0922/ttyd

# 启动（绑定本地，不对外暴露）
ttyd -p 7681 --interface 127.0.0.1 bash &

# 设置开机自启（systemd）
sudo tee /etc/systemd/system/ttyd.service <<EOF
[Unit]
Description=ttyd Web Terminal
After=network.target

[Service]
ExecStart=/usr/bin/ttyd -p 7681 --interface 127.0.0.1 bash
Restart=always
User=www-data

[Install]
WantedBy=multi-user.target
EOF
sudo systemctl enable --now ttyd
```

Nginx 中已配置 `/admin/terminal/` 反代到 `127.0.0.1:7681`（见 nav.conf）。

---

## SSL 证书管理权限配置

后台 `admin/ssl.php` 需要 PHP 执行 certbot / nginx 命令，配置 sudo 白名单：

```bash
sudo tee /etc/sudoers.d/nav-portal <<EOF
www-data ALL=(ALL) NOPASSWD: /usr/bin/certbot
www-data ALL=(ALL) NOPASSWD: /usr/sbin/nginx
www-data ALL=(ALL) NOPASSWD: /bin/systemctl restart nginx
EOF
sudo chmod 440 /etc/sudoers.d/nav-portal
```

---

## 后台使用说明

访问 `https://nav.yourdomain.com/admin/` 进入后台（需 admin 账户登录）。

| 页面 | 功能 |
|------|------|
| 控制台 | 站点/用户统计，SSL到期提醒，快捷操作入口 |
| 站点管理 | 增删改查站点，支持 Internal/Proxy/External 三种类型 |
| 分组管理 | 管理分组，设置验证开关和可见范围 |
| 用户管理 | 增删改查账户，设置 admin/user 角色 |
| 系统设置 | 站点名称、Cookie有效期、导入导出配置、生成Nginx反代配置 |
| SSL证书 | 申请/续期 Let's Encrypt 证书，下载证书，Nginx 操作 |
| Web终端 | 二次密码验证后进入浏览器命令行（需 ttyd 运行中） |

### 添加反代站点流程

1. 后台 → 站点管理 → 添加站点 → 类型选「Proxy 反代」
2. 选择模式：路径前缀（`/p/slug/`）或子域名
3. 填写代理目标（内网地址，如 `http://192.168.1.100:3000`）
4. 后台 → 系统设置 → 生成并下载 `nav-proxy.conf`
5. 将生成的配置追加到服务器 Nginx 配置并 reload

---

## 命令行用户管理

```bash
php /var/www/nav-portal/data/manage_users.php list
php /var/www/nav-portal/data/manage_users.php add {user} {pwd} [admin|user]
php /var/www/nav-portal/data/manage_users.php passwd {user} {newpwd}
php /var/www/nav-portal/data/manage_users.php del {user}
php /var/www/nav-portal/data/manage_users.php reset                  # 完整重置并重新进入安装向导
```

`reset` 会清空用户、安装锁、登录日志、站点与分组、备份、反代配置、IP 锁定记录，并重新生成认证密钥。

账户保存在 `data/users.json`，重启服务器不丢失。

如需清空当前实例并重新进入安装向导，可执行：

```bash
php /var/www/nav-portal/data/manage_users.php reset
```

`reset` 会清空用户、安装锁、登录日志 `data/logs/auth.log`、站点与分组、备份、反代配置、IP 锁定记录，并重新生成认证密钥。

---

## 开发设计文档

- `docs/导航网站需求文档.md`
- `docs/文件系统模块需求与设计文档.md`

---

## 子站接入中间件

在自建子站入口 PHP 文件顶部加一行：

```php
<?php
require_once '/var/www/nav-portal/subsite-middleware/auth_check.php';
// 后面是正常代码...
```

从导航页点击「Internal」类型站点时，URL 会携带 `_nav_token` 参数，
中间件验证后写入本站 Cookie，后续无需重复验证。

---

## 安全注意事项

1. `AUTH_SECRET_KEY` 必须修改为随机长字符串（至少64字符）
2. `data/` 目录的 `.json` 文件禁止 Web 访问（Nginx 已配置）
3. Web 终端仅限 admin 且需二次密码验证，操作记录写入 `data/logs/terminal.log`
4. SSL 管理的 sudo 白名单只允许特定命令，不授予全局 root
5. 反代目标（proxy_target）只允许内网 IP，防止 SSRF
6. 所有表单含 CSRF Token 保护
7. 密码使用 bcrypt 哈希，cost=10

---

## 账户数据说明

账户保存在 `data/users.json`（磁盘文件），与进程无关：

```
manage_users.php  →  写入 data/users.json  →  永久保存
                              ↑
                    重启/断电均不影响
```

---

*README v1.2 — 如有变更请同步更新*
