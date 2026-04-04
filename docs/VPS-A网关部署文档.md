# VPS-A 透明网关部署文档（Nginx 方案）

## 架构定位

```
用户浏览器
    │  HTTPS *.yourdomain.com
    ▼
[VPS-A 公网IP]  ← 本文档：SSL 终止 + Nginx 完全透传
    │  HTTP  内网 IP（同机房）或 WireGuard 隧道 IP
    ▼
[VPS-B 无公网]  ← 导航站 + 所有业务 + 所有权限配置
```

VPS-A 职责：**SSL 证书管理 + 流量透传**，不做任何鉴权、不拦截任何请求。  
所有业务逻辑、鉴权、路由配置全部在 VPS-B 处理。

---

## 一、系统初始化

```bash
# 更新系统
apt update && apt upgrade -y

# 安装基础工具
apt install -y curl wget ufw openssl

# 设置时区
timedatectl set-timezone Asia/Shanghai

# 优化内核参数（高并发、大文件、长连接）
cat > /etc/sysctl.d/99-gateway.conf << 'EOF'
net.core.somaxconn                  = 65535
net.core.netdev_max_backlog         = 65535
net.ipv4.tcp_max_syn_backlog        = 65535
net.ipv4.tcp_fin_timeout            = 15
net.ipv4.tcp_keepalive_time         = 1200
net.ipv4.tcp_keepalive_intvl        = 30
net.ipv4.tcp_keepalive_probes       = 10
net.ipv4.tcp_tw_reuse               = 1
net.ipv4.tcp_rmem                   = 4096 87380 67108864
net.ipv4.tcp_wmem                   = 4096 65536 67108864
EOF
sysctl -p /etc/sysctl.d/99-gateway.conf

# 文件描述符
echo '* soft nofile 65535' >> /etc/security/limits.conf
echo '* hard nofile 65535' >> /etc/security/limits.conf
```

---

## 二、配置防火墙

```bash
ufw --force reset
ufw default deny incoming
ufw default allow outgoing

ufw allow 22/tcp    # SSH
ufw allow 80/tcp    # HTTP
ufw allow 443/tcp   # HTTPS

ufw enable
ufw status verbose
```

---

## 三、确认 VPS-A 与 VPS-B 的网络连通

### 方式一：同机房内网（最简单，无需配置）

两台 VPS 在同一云厂商同一地域时，直接使用内网 IP：

```bash
# 在 VPS-A 上确认能访问 VPS-B
ping -c 3 <VPS-B内网IP>
curl http://<VPS-B内网IP>
```

### 方式二：跨机房 WireGuard 隧道

若两台机器不在同一内网，建立加密隧道：

```bash
# 安装 WireGuard
apt install -y wireguard

# 生成密钥对
wg genkey | tee /etc/wireguard/private.key | wg pubkey > /etc/wireguard/public.key
chmod 600 /etc/wireguard/private.key

echo "VPS-A 公钥（填入 VPS-B 配置）:"
cat /etc/wireguard/public.key
```

```bash
# /etc/wireguard/wg0.conf
cat > /etc/wireguard/wg0.conf << 'EOF'
[Interface]
PrivateKey = <A 的私钥>
Address    = 10.10.0.1/24
ListenPort = 51820

[Peer]
PublicKey  = <B 的公钥>
AllowedIPs = 10.10.0.2/32
EOF

chmod 600 /etc/wireguard/wg0.conf
systemctl enable --now wg-quick@wg0
ufw allow 51820/udp

# 验证（VPS-B 配置好后）
ping -c 3 10.10.0.2
```

VPS-B 上的对应配置：

```bash
apt install -y wireguard
wg genkey | tee /etc/wireguard/private.key | wg pubkey > /etc/wireguard/public.key

cat > /etc/wireguard/wg0.conf << 'EOF'
[Interface]
PrivateKey          = <B 的私钥>
Address             = 10.10.0.2/24

[Peer]
PublicKey           = <A 的公钥>
Endpoint            = <A 的公网IP>:51820
AllowedIPs          = 10.10.0.1/32
PersistentKeepalive = 25
EOF

chmod 600 /etc/wireguard/wg0.conf
systemctl enable --now wg-quick@wg0
```

> 后续文档中 `<VPS-B的IP>` 替换为内网 IP 或 `10.10.0.2`。

---

## 四、安装 Nginx

```bash
# 安装最新稳定版
apt install -y nginx

# 验证版本
nginx -v

# 确认 nginx 已包含 stream 模块（用于 TCP/UDP 透传，可选）
nginx -V 2>&1 | grep stream

# 启动并设置开机自启
systemctl enable --now nginx
```

---

## 五、申请通配符 SSL 证书（acme.sh）

Nginx 不能自动申请通配符证书，使用 `acme.sh` + Cloudflare DNS API 自动申请和续期。

### 安装 acme.sh

```bash
curl https://get.acme.sh | sh -s email=your@email.com
source ~/.bashrc
acme.sh --version
```

### 获取 Cloudflare API Token

1. 登录 [Cloudflare Dashboard](https://dash.cloudflare.com) → 右上角头像 → **我的个人资料**
2. 左侧 → **API 令牌** → **创建令牌**
3. 选择「编辑区域 DNS」模板：
   - 权限：`Zone` / `DNS` / `Edit`
   - 区域资源：`包含` / `特定区域` / `yourdomain.com`
4. 点击「创建令牌」并复制保存

验证 Token：

```bash
curl -s "https://api.cloudflare.com/client/v4/user/tokens/verify" \
     -H "Authorization: Bearer 你的Token" | python3 -m json.tool
# 返回 "success": true 则有效
```

### 申请通配符证书

```bash
# 设置 Cloudflare API Token 环境变量
export CF_Token="你的Cloudflare_API_Token"
export CF_Account_ID="你的Cloudflare_Account_ID"  # 可选

# 申请通配符证书（DNS 验证，无需服务器开放任何端口）
acme.sh --issue --dns dns_cf \
    -d yourdomain.com \
    -d "*.yourdomain.com" \
    --keylength ec-256

# 安装证书到 Nginx 目录
mkdir -p /etc/nginx/ssl/yourdomain.com

acme.sh --install-cert -d yourdomain.com \
    --ecc \
    --cert-file      /etc/nginx/ssl/yourdomain.com/cert.pem \
    --key-file       /etc/nginx/ssl/yourdomain.com/key.pem \
    --fullchain-file /etc/nginx/ssl/yourdomain.com/fullchain.pem \
    --reloadcmd      "systemctl reload nginx"
```

### 持久化环境变量（自动续期需要）

```bash
# 写入 acme.sh 账户配置（只需执行一次）
echo "CF_Token=你的Token" >> ~/.acme.sh/account.conf
echo "CF_Account_ID=你的AccountID" >> ~/.acme.sh/account.conf
```

验证自动续期：

```bash
# 模拟续期（不实际申请）
acme.sh --renew -d yourdomain.com --ecc --dry-run

# 查看自动续期定时任务
crontab -l | grep acme
```

---

## 六、配置 Nginx

### 主配置文件

```bash
cat > /etc/nginx/nginx.conf << 'NGINXEOF'
user www-data;
worker_processes auto;
worker_rlimit_nofile 65535;
pid /run/nginx.pid;

events {
    worker_connections  65535;
    use                 epoll;
    multi_accept        on;
}

http {
    include       /etc/nginx/mime.types;
    default_type  application/octet-stream;

    sendfile    on;
    tcp_nopush  on;
    tcp_nodelay on;

    # 日志格式（含真实客户端 IP）
    log_format main '$remote_addr - $remote_user [$time_local] "$request" '
                    '$status $body_bytes_sent "$http_referer" '
                    '"$http_user_agent" "$http_x_forwarded_for"';

    access_log /var/log/nginx/access.log main;
    error_log  /var/log/nginx/error.log warn;

    # WebSocket upgrade 映射（各 server 块中使用）
    map $http_upgrade $connection_upgrade {
        default  upgrade;
        ''       close;
    }

    include /etc/nginx/conf.d/*.conf;
}
NGINXEOF
```

### 站点配置文件

```bash
cat > /etc/nginx/conf.d/gateway.conf << 'NGINXEOF'
server {
    listen 80;
    listen [::]:80;
    # listen 443 ssl http2;       # 启用 HTTPS 时取消注释
    # listen [::]:443 ssl http2;  # 启用 HTTPS 时取消注释
    server_name _;

    # HTTP 跳转 HTTPS（启用 HTTPS 后取消注释）
    # if ($scheme = http) {
    #     return 301 https://$host$request_uri;
    # }

    # ── SSL 证书（暂不启用，需要 HTTPS 时取消注释）──
    # ssl_certificate         /etc/nginx/ssl/yourdomain.com/fullchain.pem;
    # ssl_certificate_key     /etc/nginx/ssl/yourdomain.com/key.pem;
    # ssl_protocols           TLSv1.2 TLSv1.3;
    # ssl_ciphers             ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384;
    # ssl_prefer_server_ciphers off;
    # ssl_session_cache       shared:SSL:50m;
    # ssl_session_timeout     1d;
    # ssl_session_tickets     off;
    # ssl_stapling            on;
    # ssl_stapling_verify     on;
    # ssl_trusted_certificate /etc/nginx/ssl/yourdomain.com/fullchain.pem;
    # resolver                1.1.1.1 8.8.8.8 valid=300s;
    # resolver_timeout        5s;

    # 完全透传到 VPS-B，不做任何鉴权和拦截
    location / {
        proxy_pass                      http://<VPS-B的IP>;

        # ── 协议版本：HTTP/1.1 支持长连接和 WebSocket ──
        proxy_http_version              1.1;

        # ── WebSocket / SSE / 协议升级 ──
        proxy_set_header                Upgrade                         $http_upgrade;
        proxy_set_header                Connection                      "upgrade";
        proxy_set_header                Sec-WebSocket-Extensions        $http_sec_websocket_extensions;
        proxy_set_header                Sec-WebSocket-Key               $http_sec_websocket_key;
        proxy_set_header                Sec-WebSocket-Version           $http_sec_websocket_version;

        # ── 客户端真实信息透传 ──
        proxy_set_header                Host                            $host;
        proxy_set_header                X-Real-IP                       $remote_addr;
        proxy_set_header                REMOTE-HOST                     $remote_addr;
        proxy_set_header                X-Forwarded-For                 $proxy_add_x_forwarded_for;
        proxy_set_header                X-Forwarded-Proto               $scheme;
        proxy_set_header                X-Forwarded-Host                $host;
        proxy_set_header                X-Forwarded-Port                $server_port;
        proxy_set_header                X-Original-URI                  $request_uri;
        proxy_set_header                X-Original-Method               $request_method;

        # ── 认证头透传 ──
        proxy_set_header                Authorization                   $http_authorization;
        proxy_set_header                Cookie                          $http_cookie;
        proxy_pass_header               Set-Cookie;
        proxy_pass_request_headers      on;
        proxy_pass_request_body         on;

        # ── 断点续传 / 分片下载 ──
        proxy_set_header                Range                           $http_range;
        proxy_set_header                If-Range                        $http_if_range;

        # ── 内容协商 ──
        proxy_set_header                Accept                          $http_accept;
        proxy_set_header                Accept-Encoding                 $http_accept_encoding;
        proxy_set_header                Accept-Language                 $http_accept_language;

        # ── 跨域 / 防盗链 ──
        proxy_set_header                Referer                         $http_referer;
        proxy_set_header                Origin                          $http_origin;

        # ── UA 透传 ──
        proxy_set_header                User-Agent                      $http_user_agent;

        # ── 缓存协商头 ──
        proxy_set_header                Cache-Control                   $http_cache_control;
        proxy_set_header                If-Modified-Since               $http_if_modified_since;
        proxy_set_header                If-None-Match                   $http_if_none_match;

        # ── CORS 预检请求头 ──
        proxy_set_header                Access-Control-Request-Headers  $http_access_control_request_headers;
        proxy_set_header                Access-Control-Request-Method   $http_access_control_request_method;

        # ── 上传/下载不限大小 ──
        client_max_body_size            0;
        client_body_timeout             86400s;
        proxy_request_buffering         off;

        # ── 关闭所有缓冲（流媒体/WebSocket/SSE）──
        proxy_buffering                 off;
        proxy_buffer_size               16k;
        proxy_buffers                   4 32k;
        proxy_busy_buffers_size         64k;
        proxy_temp_file_write_size      64k;
        proxy_max_temp_file_size        0;

        # ── 完全禁用缓存 ──
        proxy_cache                     off;
        proxy_no_cache                  1;
        proxy_cache_bypass              1;

        # ── 超时（视频流/大文件/SSH 长连接）──
        proxy_connect_timeout           600s;
        proxy_send_timeout              86400s;
        proxy_read_timeout              86400s;
        keepalive_timeout               600s;
        send_timeout                    86400s;

        # ── 文件传输优化 ──
        sendfile                        on;
        tcp_nopush                      on;
        tcp_nodelay                     on;

        # ── 响应头：不拦截、不修改 ──
        proxy_intercept_errors          off;
        proxy_redirect                  off;
        proxy_hide_header               X-Powered-By;
        proxy_pass_header               Server;
        proxy_pass_header               Date;
        proxy_pass_header               Content-Type;
        proxy_pass_header               Content-Length;
        proxy_pass_header               Content-Encoding;
        proxy_pass_header               Content-Range;
        proxy_pass_header               Accept-Ranges;
        proxy_pass_header               ETag;
        proxy_pass_header               Last-Modified;
        proxy_pass_header               Location;
        proxy_pass_header               Refresh;
        proxy_pass_header               WWW-Authenticate;
        proxy_pass_header               Access-Control-Allow-Origin;
        proxy_pass_header               Access-Control-Allow-Methods;
        proxy_pass_header               Access-Control-Allow-Headers;
        proxy_pass_header               Access-Control-Allow-Credentials;
        proxy_pass_header               Access-Control-Expose-Headers;
        proxy_pass_header               Access-Control-Max-Age;

        # ── SSL 后端（后端是 HTTPS 时取消注释）──
        # proxy_ssl_verify                off;
        # proxy_ssl_server_name           on;
        # proxy_ssl_name                  $proxy_host;
        # proxy_ssl_protocols             TLSv1.2 TLSv1.3;
        # proxy_ssl_session_reuse         on;

        # ── 调试用响应头（上线后可删除）──
        add_header                      X-Cache         $upstream_cache_status;
        add_header                      X-Proxy-By      nginx-gateway;
    }

    access_log  /var/log/nginx/gateway.access.log;
    error_log   /var/log/nginx/gateway.error.log;
}
NGINXEOF

# 将 VPS-B IP 替换为实际值
sed -i 's/<VPS-B的IP>/VPS-B内网IP/g' /etc/nginx/conf.d/gateway.conf
```

### 验证并重载

```bash
nginx -t
# 输出 syntax is ok / test is successful 则配置正确

systemctl reload nginx
```

---

## 七、DNS 配置

在 Cloudflare 控制台添加 DNS 记录：

| 类型 | 名称 | 内容 | 代理状态 |
|------|------|------|---------|
| A | `@` | VPS-A 公网 IP | **DNS only（灰云）** |
| A | `*` | VPS-A 公网 IP | **DNS only（灰云）** |

> **必须关闭 Cloudflare 代理（橙云 → 灰云）**
> 开启橙云后 Cloudflare 会做 SSL 终止，VPS-A 的证书将无效，且流量被 CF 二次处理。

验证 DNS 生效：

```bash
nslookup nav.yourdomain.com
nslookup test.yourdomain.com
# 两个都应返回 VPS-A 的公网 IP
```

---

## 八、VPS-B 对应配置

### Nginx 真实 IP 透传

VPS-B 的 Nginx 需要信任来自 VPS-A 的请求头：

```nginx
# /etc/nginx/nginx.conf 的 http 块中添加
set_real_ip_from <VPS-A内网IP>;   # 同机房内网 IP
# 或 WireGuard 隧道：
# set_real_ip_from 10.10.0.1;
real_ip_header   X-Real-IP;
```

```bash
nginx -t && systemctl reload nginx
```

### 防火墙

VPS-B 只允许 VPS-A 访问 80 端口：

```bash
ufw allow from <VPS-A内网IP> to any port 80
# WireGuard 方案：
# ufw allow from 10.10.0.1 to any port 80

# 拒绝其他来源访问 80
ufw deny 80
ufw reload
```

### Nginx server 块无需证书

```nginx
server {
    listen 80;
    server_name nav.yourdomain.com;
    root /var/www/nav/public;
    # ... 正常配置，不需要 SSL
}
```

---

## 九、验证整体链路

```bash
# 1. A 能访问 B
curl -H "Host: nav.yourdomain.com" http://<VPS-B的IP>
# 应返回导航站 HTML

# 2. HTTPS 正常
curl -vI https://nav.yourdomain.com 2>&1 | grep -E 'HTTP/|issuer|expire'
# 应看到 HTTP/2 200

# 3. 通配符证书验证
curl -vI https://test.yourdomain.com 2>&1 | grep -E 'HTTP/|subject'
# 证书 subject 应包含 *.yourdomain.com

# 4. 查看证书有效期
echo | openssl s_client -connect nav.yourdomain.com:443 2>/dev/null \
    | openssl x509 -noout -dates

# 5. 确认 X-Real-IP 透传
tail -f /var/log/nginx/access.log
# 查看 $remote_addr 是否为真实客户端 IP
```

---

## 十、日常运维

```bash
# 查看 Nginx 状态
systemctl status nginx

# 查看实时访问日志
tail -f /var/log/nginx/access.log

# 查看错误日志
tail -f /var/log/nginx/error.log

# 修改配置后验证并重载（不中断连接）
nginx -t && systemctl reload nginx

# 查看证书有效期
acme.sh --list

# 手动续期证书
acme.sh --renew -d yourdomain.com --ecc --force

# 查看当前连接数
ss -tnp | grep nginx | wc -l

# 查看防火墙状态
ufw status verbose
```

---

## 十一、故障排查

### 访问返回 502

```bash
# 检查 A 能否访问 B
curl -v http://<VPS-B的IP>

# 检查 Nginx 配置
nginx -t

# 查看错误日志
tail -50 /var/log/nginx/error.log
```

### 证书申请失败

```bash
# 检查 Cloudflare Token
curl -s "https://api.cloudflare.com/client/v4/user/tokens/verify" \
     -H "Authorization: Bearer $(grep CF_Token ~/.acme.sh/account.conf | cut -d= -f2)"

# 手动重新申请（加 --debug 查看详情）
acme.sh --issue --dns dns_cf \
    -d yourdomain.com -d "*.yourdomain.com" \
    --ecc --debug 2

# 检查 DNS 是否已解析
dig +short nav.yourdomain.com
```

### 证书已申请但 Nginx 加载失败

```bash
# 检查证书文件是否存在
ls -la /etc/nginx/ssl/yourdomain.com/

# 检查证书路径与 Nginx 配置是否一致
grep ssl_certificate /etc/nginx/conf.d/gateway.conf

nginx -t
```

### WebSocket / 视频流断开

```bash
# 确认 proxy_buffering off
grep proxy_buffering /etc/nginx/nginx.conf

# 确认超时时间足够大
grep proxy_read_timeout /etc/nginx/nginx.conf

# 确认 Upgrade / Connection 头已透传
grep -A2 'Upgrade' /etc/nginx/nginx.conf
```

### 客户端 IP 显示为 VPS-A 的 IP

```bash
# 确认 VPS-B 的 Nginx 已配置 set_real_ip_from
grep real_ip /etc/nginx/nginx.conf   # 在 VPS-B 上执行
```

---

## 十二、部署检查清单

```
□ 系统内核参数已优化（sysctl）
□ 防火墙已配置（22/80/443）
□ VPS-A 与 VPS-B 网络互通（curl 访问 B 的 80 端口正常）
□ Nginx 已安装并启动
□ acme.sh 已安装
□ Cloudflare API Token 已配置且验证有效
□ 通配符证书已申请成功（fullchain.pem / key.pem 存在）
□ /etc/nginx/conf.d/gateway.conf 中域名和 VPS-B IP 已替换
□ nginx -t 验证通过
□ DNS A 记录已指向 VPS-A 公网 IP（灰云，非橙云）
□ curl https://nav.yourdomain.com 返回 HTTP/2 200
□ acme.sh 自动续期定时任务已存在（crontab -l 确认）
□ VPS-B Nginx 已配置 set_real_ip_from
□ VPS-B 防火墙仅允许 VPS-A 访问 80 端口
□ /etc/nginx/proxy_params_full 已创建
□ 所有 location 块已使用 include /etc/nginx/proxy_params_full
□ check_proxy_params.sh 检查无缺失项
```

---

## 十三、反代参数模板（proxy_params_full）

将所有反代参数抽取为公共模板文件，每个站点 `location` 只需一行 `include`，从根本上杜绝参数遗漏。

### 13.1 创建模板文件（含完整注释）

```bash
cp proxy_params_full.conf /etc/nginx/proxy_params_full
chmod 644 /etc/nginx/proxy_params_full
```

以下为完整 heredoc（各参数含分组标题和注释）：

```bash
cat > /etc/nginx/proxy_params_full << 'EOF'
# ══════════════════════════════════════════════════════════════════════
# Nginx 完整反代参数模板 - proxy_params_full  v1.3
# 用法：location / { proxy_pass http://后端IP; include /etc/nginx/proxy_params_full; }
# ══════════════════════════════════════════════════════════════════════

# ── 第一组：协议版本 ──
proxy_http_version              1.1;                # HTTP/1.1 支持长连接和 WebSocket，HTTP/1.0 不支持

# ── 第二组：WebSocket / SSE / 协议升级 ──
proxy_set_header                Upgrade                         $http_upgrade;              # 协议升级头，WebSocket 握手必须
proxy_set_header                Connection                      "upgrade";                  # 有 Upgrade 时升级协议，无则关闭连接
proxy_set_header                Sec-WebSocket-Extensions        $http_sec_websocket_extensions; # WS 扩展协商（如压缩）
proxy_set_header                Sec-WebSocket-Key               $http_sec_websocket_key;    # WS 握手密钥，不透传则握手失败
proxy_set_header                Sec-WebSocket-Version           $http_sec_websocket_version; # WS 协议版本（标准为 13）

# ── 第三组：客户端真实信息透传 ──
proxy_set_header                Host                            $host;                      # 原始目标域名，后端按此路由，必须透传
proxy_set_header                X-Real-IP                       $remote_addr;               # 客户端真实 IP，有 CDN 时需先配 set_real_ip_from
proxy_set_header                REMOTE-HOST                     $remote_addr;               # 兼容部分 PHP 框架
proxy_set_header                X-Forwarded-For                 $proxy_add_x_forwarded_for; # 完整 IP 链路，安全场景优先用 X-Real-IP
proxy_set_header                X-Forwarded-Proto               $scheme;                    # 原始协议 http/https，后端生成 URL 时用
proxy_set_header                X-Forwarded-Host                $host;                      # 兼容部分框架
proxy_set_header                X-Forwarded-Port                $server_port;               # 原始端口，后端生成重定向 URL 时需要
proxy_set_header                X-Original-URI                  $request_uri;               # 路径重写时保留原始 URI
proxy_set_header                X-Original-Method               $request_method;            # 内部跳转时保留原始方法

# ── 第四组：认证头透传 ──
proxy_set_header                Authorization                   $http_authorization;        # 不透传后端收不到→401
proxy_set_header                Cookie                          $http_cookie;               # 所有 Cookie，不透传 Session 失效
proxy_pass_header               Set-Cookie;                                                 # 允许后端写 Cookie

# ── 第五组：请求头和请求体完整透传 ──
proxy_pass_request_headers      on;                                                         # 显式声明防意外覆盖
proxy_pass_request_body         on;

# ── 第六组：断点续传 / 分片下载 ──
proxy_set_header                Range                           $http_range;                # 不透传则断点续传失败每次从头下载
proxy_set_header                If-Range                        $http_if_range;             # 条件范围，资源未修改返回 206

# ── 第七组：内容协商 ──
proxy_set_header                Accept                          $http_accept;               # 客户端接受的内容类型
proxy_set_header                Accept-Encoding                 $http_accept_encoding;      # 压缩算法（gzip/br），后端据此压缩
proxy_set_header                Accept-Language                 $http_accept_language;      # 首选语言，多语言网站返回对应语言

# ── 第八组：跨域 / 防盗链 ──
proxy_set_header                Origin                          $http_origin;               # 来源域名，CORS 校验
proxy_set_header                Referer                         $http_referer;              # 来源页面，防盗链判断

# ── 第九组：User-Agent 透传 ──
proxy_set_header                User-Agent                      $http_user_agent;           # 移动适配、爬虫过滤

# ── 第十组：缓存协商头 ──
proxy_set_header                Cache-Control                   $http_cache_control;        # 强刷时后端跳过缓存
proxy_set_header                If-Modified-Since               $http_if_modified_since;    # 未变化返回 304
proxy_set_header                If-None-Match                   $http_if_none_match;        # 比 If-Modified-Since 更精确

# ── 第十一组：CORS 预检请求头透传 ──
proxy_set_header                Access-Control-Request-Headers  $http_access_control_request_headers; # 预检声明的请求头
proxy_set_header                Access-Control-Request-Method   $http_access_control_request_method;  # 预检声明的方法

# ── 第十二组：上传 / 下载限制 ──
client_max_body_size            0;                                                          # 不限制（默认 1m，超过返回 413）
client_body_timeout             86400s;                                                     # 慢速上传防断开
proxy_request_buffering         off;                                                        # 立即转发，大文件上传不占磁盘

# ── 第十三组：响应缓冲控制（视频流/WebSocket/SSE/SSH over WS 必须关闭缓冲）──
proxy_buffering                 off;                # 关闭响应缓冲，后端数据实时推送到客户端（流媒体/SSE/WS 关键，开启则客户端必须等缓冲满才收到数据）
proxy_buffer_size               16k;                # 读取后端响应头的缓冲区大小（响应头通常 1-4k，16k 足够）
proxy_buffers                   4 32k;              # 读取后端响应体的缓冲区：4 个 32k 共 128k（proxy_buffering on 时有效）
proxy_busy_buffers_size         64k;                # 向客户端发送时"忙"缓冲区最大值，不能超过 proxy_buffers 总大小
proxy_temp_file_write_size      64k;                # 缓冲区不足时写临时文件的单次大小（配合 proxy_max_temp_file_size 使用）
proxy_max_temp_file_size        0;                  # 禁止使用临时文件（0=禁用），防止大响应写满磁盘，纯内存/直接转发

# ── 第十四组：完全禁用缓存（确保请求始终透传到后端，不使用任何 Nginx 缓存）──
proxy_cache                     off;                # 不使用 proxy_cache_path 配置的缓存（即使配置了也不用）
proxy_no_cache                  1;                  # 不将响应写入缓存（条件：1=始终不缓存）
proxy_cache_bypass              1;                  # 强制绕过已有缓存直接请求后端（条件：1=始终绕过）

# ── 第十五组：超时设置（适配视频流、大文件传输、SSH/终端等长连接场景）──
proxy_connect_timeout           600s;               # 与后端建立 TCP 连接的等待时间（不是请求总时长，仅握手阶段）
proxy_send_timeout              86400s;             # 向后端发送请求数据的超时（相邻两次写操作的间隔，非总时长）
proxy_read_timeout              86400s;             # 从后端读取响应的超时（相邻两次读操作的间隔，视频直播/SSH 必须足够大）
keepalive_timeout               600s;               # 与客户端的 TCP 长连接保持时间，减少频繁握手开销
send_timeout                    86400s;             # 向客户端发送响应的超时（相邻两次写操作间隔，慢速客户端下载时必须足够大）

# ── 第十六组：文件传输优化 ──
sendfile                        on;                 # 零拷贝传输：内核直接将文件从磁盘发到网络，绕过用户态，降低 CPU 和内存开销
tcp_nopush                      on;                 # 等 TCP 缓冲区满再发包，减少小包数量，提高吞吐量（需配合 sendfile on 使用）
tcp_nodelay                     on;                 # 禁用 Nagle 算法，有数据立即发送，降低延迟（终端/游戏/实时通信必须开启）

# ── 第十七组：响应拦截与修改控制 ──
proxy_intercept_errors          off;                # 不拦截后端 4xx/5xx 错误，直接透传原始错误页（开启后用 error_page 指令替换错误页）
proxy_redirect                  off;                # 不修改后端 Location/Refresh 响应头中的地址（开启后 Nginx 会将后端域名替换为代理域名）
proxy_hide_header               X-Powered-By;       # 从响应中删除此头，避免暴露后端技术栈（如 PHP/7.4、ASP.NET 等）

# ── 第十八组：透传后端响应头（Nginx 默认会过滤部分头，以下明确允许透传到客户端）──
proxy_pass_header               Server;             # 后端服务器标识（如 nginx/1.24.0、Apache/2.4）
proxy_pass_header               Date;               # 响应生成时间戳，客户端/CDN 缓存判断使用
proxy_pass_header               Content-Type;       # 响应内容类型（text/html; charset=utf-8 / application/json 等），浏览器据此解析
proxy_pass_header               Content-Length;     # 响应体字节数，客户端据此显示下载进度和判断是否接收完毕
proxy_pass_header               Content-Encoding;   # 响应压缩编码（gzip/br/deflate），客户端据此解压，不透传则乱码
proxy_pass_header               Content-Range;      # 分片响应的字节范围（如 bytes 0-1023/10240），断点续传/分片下载必须
proxy_pass_header               Accept-Ranges;      # 服务器支持范围请求的标识（bytes），客户端据此决定是否发 Range 请求
proxy_pass_header               ETag;               # 资源版本唯一标识，客户端下次请求用 If-None-Match 做条件验证
proxy_pass_header               Last-Modified;      # 资源最后修改时间，客户端下次请求用 If-Modified-Since 做条件验证
proxy_pass_header               Location;           # 重定向目标地址，301/302/307 跳转必须，不透传则重定向失效
proxy_pass_header               Refresh;            # 定时刷新或跳转指令（如 Refresh: 0; url=xxx）
proxy_pass_header               WWW-Authenticate;   # 401 认证挑战头（如 Basic realm="Admin"），Basic Auth 必须，不透传则浏览器不弹登录框
proxy_pass_header               Set-Cookie;         # 后端写入 Cookie（与第四组请求侧配合，完整实现 Cookie 双向透传）
proxy_pass_header               Access-Control-Allow-Origin;      # CORS：允许跨域的来源（* 或具体域名）
proxy_pass_header               Access-Control-Allow-Methods;     # CORS：允许的 HTTP 方法（GET POST PUT DELETE 等）
proxy_pass_header               Access-Control-Allow-Headers;     # CORS：允许携带的请求头列表
proxy_pass_header               Access-Control-Allow-Credentials; # CORS：是否允许携带 Cookie/Authorization 等凭证（true/false）
proxy_pass_header               Access-Control-Expose-Headers;    # CORS：允许 JS 通过 response.headers 读取的额外响应头
proxy_pass_header               Access-Control-Max-Age;           # CORS：预检请求（OPTIONS）结果缓存时间（秒），减少预检请求次数

# ── 第十九组：SSL 后端（后端是 HTTPS 时取消注释）──
# proxy_ssl_verify                off;             # 不验证后端 SSL 证书有效性（自签名证书或内网场景使用，生产建议开启验证）
# proxy_ssl_server_name           on;              # 启用 SNI（Server Name Indication），后端多域名/多证书场景必须开启
# proxy_ssl_name                  $proxy_host;     # SNI 握手时发送的服务器名称，告知后端该用哪个证书
# proxy_ssl_protocols             TLSv1.2 TLSv1.3; # 与后端通信允许的 TLS 版本（禁用 TLSv1.0/1.1 提升安全性）
# proxy_ssl_session_reuse         on;              # 复用已有 SSL 会话，减少重复握手开销，提升性能
EOF

chmod 644 /etc/nginx/proxy_params_full
echo "模板文件已创建：/etc/nginx/proxy_params_full"
```

### 19 组参数说明

| 组 | 内容 | 关键参数 |
|----|------|----------|
| 1 | 协议版本 | `proxy_http_version 1.1` |
| 2 | WebSocket/SSE | `Upgrade` `Connection` `Sec-WebSocket-*` |
| 3 | 客户端信息 | `X-Real-IP` `X-Forwarded-*` `X-Original-URI` |
| 4 | 认证头 | `Authorization` `Cookie` `Set-Cookie` |
| 5 | 请求透传 | `proxy_pass_request_headers/body` |
| 6 | 断点续传 | `Range` `If-Range` |
| 7 | 内容协商 | `Accept` `Accept-Encoding` `Accept-Language` |
| 8 | 跨域防盗链 | `Origin` `Referer` |
| 9 | UA 透传 | `User-Agent` |
| 10 | 缓存协商 | `Cache-Control` `If-Modified-Since` `If-None-Match` |
| 11 | CORS 预检 | `Access-Control-Request-*` |
| 12 | 上传限制 | `client_max_body_size 0` `proxy_request_buffering off` |
| 13 | 响应缓冲 | `proxy_buffering off` `proxy_max_temp_file_size 0` |
| 14 | 缓存禁用 | `proxy_cache off` `proxy_no_cache` `proxy_cache_bypass` |
| 15 | 超时设置 | `proxy_read_timeout 86400s` `keepalive_timeout 600s` |
| 16 | 文件传输 | `sendfile` `tcp_nopush` `tcp_nodelay` |
| 17 | 响应控制 | `proxy_intercept_errors off` `proxy_redirect off` |
| 18 | 响应头透传 | `Content-Range` `ETag` `CORS` `WWW-Authenticate` 等 |
| 19 | SSL 后端 | 注释备用，后端 HTTPS 时取消注释 |

### 站点 location 使用方式（include 版）

```nginx
server {
    listen 80;
    server_name myapp.yourdomain.com;

    location / {
        proxy_pass http://<后端IP:端口>;
        include    /etc/nginx/proxy_params_full;  # 一行包含所有 19 组参数
    }
}
```

---

### 13.2 完整站点配置模板

内置全部 19 组反代参数，**无需依赖外部 `include` 文件**，复制即用。

```nginx
server {
    listen 80;                   # 监听 IPv4 80 端口
    listen [::]:80;              # 监听 IPv6 80 端口
    # listen 443 ssl http2;      # 启用 HTTPS 时取消注释（IPv4）
    # listen [::]:443 ssl http2; # 启用 HTTPS 时取消注释（IPv6）
    server_name yourdomain.com;  # ← 替换为实际域名

    # ── HTTP 强制跳转 HTTPS（启用 HTTPS 后取消注释）──
    # if ($scheme = http) { return 301 https://$host$request_uri; }

    # ── SSL 证书（启用 HTTPS 时取消注释）──
    # ssl_certificate         /etc/nginx/ssl/yourdomain.com/fullchain.pem;  # 证书链（含中间证书）
    # ssl_certificate_key     /etc/nginx/ssl/yourdomain.com/key.pem;        # 私钥文件
    # ssl_protocols           TLSv1.2 TLSv1.3;                              # 禁用不安全旧版本
    # ssl_ciphers             ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384;
    # ssl_prefer_server_ciphers off;                                        # TLS 1.3 推荐 off
    # ssl_session_cache       shared:SSL:50m;                               # 进程间共享会话缓存
    # ssl_session_timeout     1d;
    # ssl_session_tickets     off;                                          # 禁用 Session Ticket（保障前向保密）
    # ssl_stapling            on;                                           # OCSP Stapling 加速握手
    # ssl_stapling_verify     on;
    # ssl_trusted_certificate /etc/nginx/ssl/yourdomain.com/fullchain.pem;
    # resolver                1.1.1.1 8.8.8.8 valid=300s;
    # resolver_timeout        5s;

    location / {
        proxy_pass              http://<后端IP:端口>; # ← 替换，如 http://10.0.0.2:8080

        # ── 第一组：协议版本 ──
        proxy_http_version      1.1;               # HTTP/1.1 支持长连接和 WebSocket，1.0 不支持

        # ── 第二组：WebSocket / SSE / 协议升级 ──
        proxy_set_header        Upgrade                         $http_upgrade;              # 协议升级头，WS 握手必须
        proxy_set_header        Connection                      "upgrade";                  # 告知后端升级连接，不设置则握手被拒
        proxy_set_header        Sec-WebSocket-Extensions        $http_sec_websocket_extensions; # WS 扩展协商（如压缩）
        proxy_set_header        Sec-WebSocket-Key               $http_sec_websocket_key;    # WS 握手密钥，不透传则 101 响应失败
        proxy_set_header        Sec-WebSocket-Version           $http_sec_websocket_version; # WS 版本（RFC 6455 规定为 13）

        # ── 第三组：客户端真实信息透传 ──
        proxy_set_header        Host                            $host;                      # 原始域名，后端 vhost 路由依赖此值
        proxy_set_header        X-Real-IP                       $remote_addr;               # 客户端真实 IP（单级代理最准确）
        proxy_set_header        REMOTE-HOST                     $remote_addr;               # 兼容读取 REMOTE-HOST 的 PHP 框架
        proxy_set_header        X-Forwarded-For                 $proxy_add_x_forwarded_for; # IP 链路（可被伪造，安全场景用 X-Real-IP）
        proxy_set_header        X-Forwarded-Proto               $scheme;                    # 原始协议，后端生成绝对 URL 时使用
        proxy_set_header        X-Forwarded-Host                $host;                      # 兼容 Django/Rails 等框架
        proxy_set_header        X-Forwarded-Port                $server_port;               # 原始端口，后端生成带端口 URL 时需要
        proxy_set_header        X-Original-URI                  $request_uri;               # 完整原始 URI（含路径和查询字符串）
        proxy_set_header        X-Original-Method               $request_method;            # 原始方法，内部跳转后保留

        # ── 第四组：认证头透传 ──
        proxy_set_header        Authorization                   $http_authorization;        # Bearer/Basic Token，不透传后端返回 401
        proxy_set_header        Cookie                          $http_cookie;               # 所有请求 Cookie，不透传 Session 失效
        proxy_pass_header       Set-Cookie;                                                 # 允许后端 Set-Cookie 透传到浏览器

        # ── 第五组：请求头和请求体完整透传 ──
        proxy_pass_request_headers on;                                                      # 透传所有原始请求头
        proxy_pass_request_body    on;                                                      # 透传 POST/PUT 请求体

        # ── 第六组：断点续传 / 分片下载 ──
        proxy_set_header        Range                           $http_range;                # 字节范围请求，不透传则每次从头下载
        proxy_set_header        If-Range                        $http_if_range;             # 条件范围，资源未变返回 206

        # ── 第七组：内容协商 ──
        proxy_set_header        Accept                          $http_accept;               # 可接受 MIME 类型
        proxy_set_header        Accept-Encoding                 $http_accept_encoding;      # 压缩格式（gzip/br），后端据此压缩
        proxy_set_header        Accept-Language                 $http_accept_language;      # 首选语言，多语言站点据此返回
        # ── 第八组：跨域 / 防盗链 ──
        proxy_set_header        Origin                          $http_origin;               # 来源域，CORS 校验依赖此值
        proxy_set_header        Referer                         $http_referer;              # 来源页面 URL，防盗链判断使用

        # ── 第九组：User-Agent 透传 ──
        proxy_set_header        User-Agent                      $http_user_agent;           # 客户端标识，移动端适配和爬虫过滤使用

        # ── 第十组：缓存协商头 ──
        proxy_set_header        Cache-Control                   $http_cache_control;        # 缓存控制指令，强刷时后端跳过缓存
        proxy_set_header        If-Modified-Since               $http_if_modified_since;    # 资源修改时间，未变化后端返回 304
        proxy_set_header        If-None-Match                   $http_if_none_match;        # ETag 条件请求，比 If-Modified-Since 更精确

        # ── 第十一组：CORS 预检请求头 ──
        proxy_set_header        Access-Control-Request-Headers  $http_access_control_request_headers; # OPTIONS 预检声明的请求头列表
        proxy_set_header        Access-Control-Request-Method   $http_access_control_request_method;  # OPTIONS 预检声明的请求方法

        # ── 第十二组：上传 / 下载限制 ──
        client_max_body_size    0;                  # 不限制请求体（默认 1m，超过返回 413）
        client_body_timeout     86400s;             # 客户端上传超时（相邻两次接收间隔）
        proxy_request_buffering off;                # 接收到数据立即转发，大文件不占 Nginx 磁盘

        # ── 第十三组：响应缓冲控制 ──
        proxy_buffering         off;                # 实时推送后端数据，流媒体/SSE/WS/终端必须关闭
        proxy_buffer_size       16k;                # 响应头缓冲区大小
        proxy_buffers           4 32k;              # 响应体缓冲（proxy_buffering on 时有效）
        proxy_busy_buffers_size 64k;                # 向客户端发送时的忙缓冲区上限
        proxy_temp_file_write_size 64k;             # 缓冲区满时写临时文件的单次大小
        proxy_max_temp_file_size 0;                 # 禁用临时文件，防大响应占满磁盘

        # ── 第十四组：完全禁用缓存 ──
        proxy_cache             off;                # 不使用代理缓存层
        proxy_no_cache          1;                  # 不将响应写入缓存（始终=1）
        proxy_cache_bypass      1;                  # 始终绕过缓存直接请求后端

        # ── 第十五组：超时设置 ──
        proxy_connect_timeout   600s;               # 与后端 TCP 握手超时（仅握手阶段）
        proxy_send_timeout      86400s;             # 向后端写数据超时（相邻两次写操作间隔）
        proxy_read_timeout      86400s;             # 从后端读响应超时（视频流/SSH 必须足够大）
        keepalive_timeout       600s;               # 与客户端长连接保持时间
        send_timeout            86400s;             # 向客户端发送响应超时

        # ── 第十六组：文件传输优化 ──
        sendfile                on;                 # 零拷贝传输，降低 CPU 和内存开销
        tcp_nopush              on;                 # 缓冲区满再发包，提升吞吐量（需配合 sendfile）
        tcp_nodelay             on;                 # 禁用 Nagle，有数据立即发送（终端/实时通信必须）

        # ── 第十七组：响应拦截与修改控制 ──
        proxy_intercept_errors  off;                # 不拦截后端错误，直接透传原始错误页
        proxy_redirect          off;                # 不修改后端 Location/Refresh 头中的地址
        proxy_hide_header       X-Powered-By;       # 删除此响应头，避免暴露后端技术栈

        # ── 第十八组：透传后端响应头 ──
        proxy_pass_header       Server;             # 后端服务器标识
        proxy_pass_header       Date;               # 响应时间戳
        proxy_pass_header       Content-Type;       # 内容类型（浏览器据此解析）
        proxy_pass_header       Content-Length;     # 响应体字节数（下载进度依赖）
        proxy_pass_header       Content-Encoding;   # 压缩编码（不透传则乱码）
        proxy_pass_header       Content-Range;      # 分片字节范围（断点续传必须）
        proxy_pass_header       Accept-Ranges;      # 支持范围请求标识（断点续传必须）
        proxy_pass_header       ETag;               # 资源版本标签（缓存验证）
        proxy_pass_header       Last-Modified;      # 最后修改时间（缓存验证）
        proxy_pass_header       Location;           # 重定向地址（301/302 必须）
        proxy_pass_header       Refresh;            # 定时刷新/跳转指令
        proxy_pass_header       WWW-Authenticate;   # 401 认证挑战（Basic Auth 必须）
        proxy_pass_header       Set-Cookie;         # 后端写入 Cookie（与第四组配合）
        proxy_pass_header       Access-Control-Allow-Origin;      # CORS：允许的来源
        proxy_pass_header       Access-Control-Allow-Methods;     # CORS：允许的方法
        proxy_pass_header       Access-Control-Allow-Headers;     # CORS：允许的请求头
        proxy_pass_header       Access-Control-Allow-Credentials; # CORS：允许携带凭证
        proxy_pass_header       Access-Control-Expose-Headers;    # CORS：允许 JS 读取的头
        proxy_pass_header       Access-Control-Max-Age;           # CORS：预检缓存时间（秒）

        # ── 第十九组：SSL 后端（后端是 HTTPS 时取消注释）──
        # proxy_ssl_verify        off;              # 不验证后端证书（自签名场景）
        # proxy_ssl_server_name   on;               # 启用 SNI（多证书后端必须）
        # proxy_ssl_name          $proxy_host;      # SNI 握手时发送的服务器名
        # proxy_ssl_protocols     TLSv1.2 TLSv1.3;
        # proxy_ssl_session_reuse on;               # 复用 SSL 会话，减少握手开销
    }

    access_log  /var/log/nginx/yoursite.access.log;
    error_log   /var/log/nginx/yoursite.error.log;
}
```

```bash
# 快速部署
cp /etc/nginx/conf.d/gateway.conf /etc/nginx/conf.d/newsite.conf
sed -i 's/yourdomain.com/新域名/g' /etc/nginx/conf.d/newsite.conf
sed -i 's/<后端IP:端口>/新后端IP/g' /etc/nginx/conf.d/newsite.conf
nginx -t && systemctl reload nginx
```

---

## 十四、反代参数检查脚本

检查所有站点配置是否正确 include 了模板，或是否手动遗漏了关键参数。

### 创建脚本

```bash
cat > /usr/local/bin/check_proxy_params.sh << 'EOF'
#!/bin/bash
# 检查 Nginx 反代参数完整性
# 用法：check_proxy_params.sh [conf目录]

CONF_DIR="${1:-/etc/nginx/conf.d}"
PASS=0
FAIL=0

REQUIRED=(
    "proxy_http_version"
    "proxy_set_header.*Upgrade"
    "proxy_set_header.*X-Real-IP"
    "proxy_set_header.*X-Forwarded-For"
    "proxy_set_header.*X-Forwarded-Proto"
    "proxy_set_header.*Host"
    "proxy_set_header.*Authorization"
    "proxy_set_header.*Cookie"
    "proxy_buffering.*off"
    "proxy_request_buffering.*off"
    "proxy_read_timeout"
    "proxy_connect_timeout"
    "client_max_body_size.*0"
    "proxy_intercept_errors.*off"
    "proxy_redirect.*off"
)

echo ""
echo "════════════════════════════════════════"
echo " Nginx 反代参数完整性检查"
echo " 配置目录：$CONF_DIR"
echo "════════════════════════════════════════"

for conf in "$CONF_DIR"/*.conf; do
    [ -f "$conf" ] || continue
    echo ""
    echo "── 检查：$(basename $conf) ──"

    if grep -q "include.*proxy_params_full" "$conf"; then
        echo "  ✅ 已 include proxy_params_full，参数完整"
        ((PASS++))
        continue
    fi

    missing=()
    for pattern in "${REQUIRED[@]}"; do
        if ! grep -qP "$pattern" "$conf"; then
            missing+=("$pattern")
        fi
    done

    if [ ${#missing[@]} -eq 0 ]; then
        echo "  ✅ 所有关键参数均已配置"
        ((PASS++))
    else
        echo "  ❌ 缺少以下参数（建议改用 include /etc/nginx/proxy_params_full）："
        for m in "${missing[@]}"; do
            echo "     - $m"
        done
        ((FAIL++))
    fi
done

echo ""
echo "════════════════════════════════════════"
echo " 检查结果：通过 $PASS 个，问题 $FAIL 个"
if [ $FAIL -gt 0 ]; then
    echo " 建议：在缺失参数的 location 中添加："
    echo "   include /etc/nginx/proxy_params_full;"
fi
echo "════════════════════════════════════════"
echo ""

exit $FAIL
EOF

chmod +x /usr/local/bin/check_proxy_params.sh
echo "脚本已创建：/usr/local/bin/check_proxy_params.sh"
```

### 使用方法

```bash
# 检查默认目录 /etc/nginx/conf.d
check_proxy_params.sh

# 检查指定目录
check_proxy_params.sh /etc/nginx/sites-enabled
```

### 加入定时检查（可选）

```bash
# 每天凌晨检查一次
crontab -e
# 添加：
# 0 3 * * * /usr/local/bin/check_proxy_params.sh >> /var/log/nginx/proxy_check.log 2>&1
```

---

## 十五、与导航网站的兼容性说明

本章分析 VPS-A 按第十三章配置反代后，VPS-B 上的导航网站（`nav-portal`）是否需要改动。

### 结论：基本无需改动，但有 3 处必须确认

### 15.1 完全兼容的部分

| 功能 | 兼容原因 |
|------|----------|
| Cookie 登录态 | 第四组已配置 `Cookie` + `Set-Cookie` 透传，`$_COOKIE` 和 `setcookie()` 正常工作 |
| Authorization 头 | 第四组已配置，Basic Auth / Bearer Token 场景正常 |
| 登录跳转（Location）| `proxy_redirect off` + `proxy_pass_header Location`，`header('Location:...')` 正常透传 |
| 大文件上传（背景图）| 第十二组 `client_max_body_size 0`，不被网关截断 |
| CORS 跨子域请求 | 第十一/十八组完整透传预检和 CORS 响应头 |
| `_nav_token` URL 参数 | `proxy_pass_request_headers on` 确保查询字符串完整透传 |
| Session / CSRF Token | 基于 Cookie，Cookie 透传正常则无问题 |
| WebSocket（如有）| 第二组完整配置 |
| `auth_request` 子站鉴权 | 走 VPS-B 内部请求，不经过网关，无影响 |
| PHP-FPM | 在 VPS-B 本地运行，不经过网关 |

### 15.2 必须确认的 3 处

#### ⚠️ 确认 1：VPS-B Nginx 配置 `set_real_ip_from`（影响 IP 锁定）

`auth.php` 获取 IP 的逻辑按优先级读取：

```php
foreach (['HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $key) {
    // 优先读 X-Real-IP
}
```

若 VPS-B 未配置信任 VPS-A，PHP 拿到的是 VPS-A 的 IP，导致所有用户共享同一 IP 锁定计数，一人触发锁定全部被锁。

```nginx
# VPS-B 的 nginx.conf http 块中添加
set_real_ip_from <VPS-A内网IP>;
real_ip_header   X-Real-IP;
```

```bash
nginx -t && systemctl reload nginx
```

#### ⚠️ 确认 2：VPS-A 必须启用 HTTPS（影响 Cookie 发送）

`auth.php` 设置 Cookie 时强制 `secure`：

```php
setcookie(SESSION_COOKIE_NAME, $token, [
    'secure'   => true,   // 仅 HTTPS 才发送此 Cookie
    'httponly' => true,
    'samesite' => 'Lax',
]);
```

若 VPS-A 只监听 **80（HTTP）**，浏览器不会发送 `secure` Cookie，导致登录后每次都跳回登录页。

| 方案 | 操作 |
|------|------|
| 推荐：启用 VPS-A HTTPS | 取消 gateway.conf 中 443 和 SSL 相关注释 |
| 临时测试 | 修改 `auth.php` 中 `'secure' => false`（仅内网/测试）|

#### ⚠️ 确认 3：`COOKIE_DOMAIN` 与实际域名匹配

```php
define('NAV_DOMAIN',    'nav.yourdomain.com');       // 改为实际域名
define('COOKIE_DOMAIN', '.yourdomain.com');          // 前面有点，支持所有子域共享
define('NAV_LOGIN_URL', 'https://nav.yourdomain.com/login.php');
```

若未改为实际主域，子站无法读取导航站写入的 Cookie，单点登录失效。

### 15.3 导航网站兼容性检查清单

```
□ VPS-B Nginx 已配置 set_real_ip_from <VPS-A内网IP>
□ VPS-B Nginx 已配置 real_ip_header X-Real-IP
□ VPS-A 网关已启用 HTTPS（gateway.conf 443 已取消注释）
□ auth.php 中 COOKIE_DOMAIN 已改为实际主域（如 .yourdomain.com）
□ auth.php 中 NAV_DOMAIN 已改为实际导航站域名
□ auth.php 中 NAV_LOGIN_URL 已改为实际登录页地址
□ 测试：登录后 Cookie 能正常写入（浏览器 DevTools → Cookies）
□ 测试：刷新页面后登录态保持
□ 测试：子站跳转后 _nav_token 参数能透传并写入 Cookie
□ 测试：IP 锁定功能正常（锁定的是客户端 IP 而非 VPS-A IP）
```

---

*文档版本：v1.4 | Nginx 方案 | 配套 VPS-B 导航网站部署文档使用*