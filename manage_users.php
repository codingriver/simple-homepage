<?php
/**
 * 用户管理命令行工具（仓库根目录：manage_users.php）
 * Docker 内路径：/var/www/nav/manage_users.php（与 COPY 位置一致，勿写成 data/ 下）
 * 只能通过 CLI 运行，Web 访问返回 403
 *
 * 用法：
 *   php manage_users.php list                    列出所有用户
 *   php manage_users.php info <user>             查看用户详情
 *   php manage_users.php add <user> <pwd>        添加管理员账户
 *   php manage_users.php passwd <user> <pwd>    修改密码
 *   php manage_users.php del <user>             删除用户
 *   php manage_users.php reset                   完整重置并重新激活Web安装向导（保留备份）
 */

// 禁止 Web 访问
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('403 Forbidden: 此脚本只允许命令行运行。');
}

if (is_file(__DIR__ . '/shared/auth.php')) {
    require_once __DIR__ . '/shared/auth.php';
} elseif (is_file(__DIR__ . '/../shared/auth.php')) {
    require_once __DIR__ . '/../shared/auth.php';
} else {
    fwrite(STDERR, "找不到 shared/auth.php（请将本脚本放在项目根目录或 data/ 下运行）。\n");
    exit(1);
}

$args = array_slice($argv, 1);
$cmd  = $args[0] ?? 'help';

switch ($cmd) {

    // ── 列出所有用户 ──
    case 'list':
        $users = auth_load_users();
        if (empty($users)) { echo "暂无用户。\n"; break; }
        echo str_pad('用户名', 18) . str_pad('角色', 8) . str_pad('创建时间', 22) . "\n";
        echo str_repeat('-', 48) . "\n";
        foreach ($users as $name => $info) {
            echo str_pad($name, 18)
               . str_pad($info['role'] ?? 'user', 8)
               . str_pad($info['created_at'] ?? '-', 22) . "\n";
        }
        break;

    // ── 查看用户详情 ──
    case 'info':
        $username = $args[1] ?? '';
        if (!$username) { echo "用法：php manage_users.php info <用户名>\n"; exit(1); }
        $users = auth_load_users();
        if (!isset($users[$username])) { echo "错误：用户 '{$username}' 不存在。\n"; exit(1); }
        $u = $users[$username];
        echo "用户名   : {$username}\n";
        echo "角色     : " . ($u['role'] ?? 'user') . "\n";
        echo "创建时间 : " . ($u['created_at'] ?? '-') . "\n";
        echo "更新时间 : " . ($u['updated_at'] ?? '-') . "\n";
        echo "密码哈希 : " . substr($u['password_hash'], 0, 20) . "...\n";
        break;

    // ── 添加管理员账户（固定 admin 角色）──
    case 'add':
        $username = $args[1] ?? '';
        $password = $args[2] ?? '';
        if (!$username || !$password) {
            echo "用法：php manage_users.php add <用户名> <密码>\n"; exit(1);
        }
        if (!preg_match('/^[a-zA-Z0-9_-]{2,32}$/', $username)) {
            echo "错误：用户名只允许字母、数字、下划线、横杠，2-32 位。\n"; exit(1);
        }
        if (strlen($password) < 8) {
            echo "错误：密码至少 8 位。\n"; exit(1);
        }
        auth_save_user($username, $password, 'admin');
        echo "OK: 管理员账户 '{$username}' 已创建/更新。\n";
        break;

    // ── 修改密码 ──
    case 'passwd':
        $username = $args[1] ?? '';
        $password = $args[2] ?? '';
        if (!$username || !$password) {
            echo "用法：php manage_users.php passwd <用户名> <新密码>\n"; exit(1);
        }
        $users = auth_load_users();
        if (!isset($users[$username])) { echo "错误：用户 '{$username}' 不存在。\n"; exit(1); }
        if (strlen($password) < 8) { echo "错误：密码至少 8 位。\n"; exit(1); }
        $role = $users[$username]['role'] ?? 'admin';
        auth_save_user($username, $password, $role);
        echo "OK: '{$username}' 的密码已修改。\n";
        break;

    // ── 完整重置（等同 setup + 清空站点/分组/反代配置，保留备份）──
    case 'reset':
        echo "\n╔══════════════════════════════════════════════════════════╗\n";
        echo "║  🔄  开始完整重置...                                     ║\n";
        echo "╚══════════════════════════════════════════════════════════╝\n\n";

        // [1] 清空用户数据
        file_put_contents(USERS_FILE, '{}', LOCK_EX);
        echo "[1/10] ✅ 用户数据已清空\n";

        // [2] 删除安装锁
        if (file_exists(INSTALLED_FLAG)) {
            unlink(INSTALLED_FLAG);
            echo "[2/10] ✅ 安装锁 .installed 已删除\n";
        } else {
            echo "[2/10] ⏭  安装锁不存在，跳过\n";
        }

        // [3] 重置 config.json（保留文件但重置敏感项）
        if (file_exists(CONFIG_FILE)) {
            $cfg = json_decode(file_get_contents(CONFIG_FILE), true) ?? [];
            $cfg['cookie_secure'] = 'off';
            $cfg['cookie_domain'] = '';
            file_put_contents(CONFIG_FILE,
                json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
            echo "[3/10] ✅ config.json: cookie_secure=off, cookie_domain='' 已重置\n";
        } else {
            echo "[3/10] ⏭  config.json 不存在，跳过\n";
        }

        // [4] 清空 IP 锁定记录
        if (file_exists(IP_LOCKS_FILE)) {
            file_put_contents(IP_LOCKS_FILE, '{}', LOCK_EX);
            echo "[4/10] ✅ IP 锁定记录已清空\n";
        } else {
            echo "[4/10] ⏭  IP 锁定记录不存在，跳过\n";
        }

        // [5] 生成新认证密钥文件
        auth_rotate_secret_key();
        echo "[5/10] ✅ 认证密钥已更换为新随机值（data/auth_secret.key）\n";

        // [6] 清空站点配置（sites.json）
        $sites_file = DATA_DIR . '/sites.json';
        file_put_contents($sites_file,
            json_encode(['groups' => []], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
        echo "[6/10] ✅ 站点与分组配置已清空（sites.json 重置为空分组）\n";

        // [7] 清空登录日志
        if (file_exists(AUTH_LOG_FILE)) {
            file_put_contents(AUTH_LOG_FILE, '', LOCK_EX);
            echo "[7/10] ✅ 登录日志已清空（auth.log）\n";
        } else {
            echo "[7/10] ⏭  登录日志不存在，跳过\n";
        }

        // [8] 保留备份目录
        $backup_dir = DATA_DIR . '/backups';
        if (is_dir($backup_dir)) {
            $bak_files = glob($backup_dir . '/backup_*.json');
            $bak_count = is_array($bak_files) ? count($bak_files) : 0;
            echo "[8/10] ✅ 备份文件已保留（当前 {$bak_count} 个，未清理）\n";
        } else {
            echo "[8/10] ⏭  备份目录不存在，跳过\n";
        }

        // [9] 清空反代配置（proxy 相关 nginx conf）
        $nginx_conf_dirs = [
            '/etc/nginx/conf.d',
            '/etc/nginx/sites-enabled',
        ];
        $proxy_cleaned = 0;
        foreach ($nginx_conf_dirs as $ndir) {
            if (!is_dir($ndir)) continue;
            foreach (glob($ndir . '/*.conf') as $cf) {
                $basename = basename($cf);
                // 只清除非默认的反代配置，保留 default.conf / nginx.conf
                if (in_array($basename, ['default.conf', 'default'])) continue;
                $content_cf = file_get_contents($cf);
                // 只处理含 proxy_pass 的配置文件
                if (strpos($content_cf, 'proxy_pass') !== false) {
                    // 清空内容而非删除（避免 include 报错），写入注释占位
                    file_put_contents($cf,
                        "# 反代配置已由 manage_users.php reset 清空\n"
                        . "# 如需添加反代，在此文件中添加：\n"
                        . "# location /proxy-path/ {\n"
                        . "#     proxy_pass http://内网IP:端口/;\n"
                        . "# }\n",
                        LOCK_EX);
                    echo "         已清空反代配置（保留文件）: {$cf}\n";
                    $proxy_cleaned++;
                }
            }
        }
        // 特殊处理：容器内固定路径 nav-proxy*.conf（被 Nginx include，不能删除）
        $nav_proxy = '/etc/nginx/conf.d/nav-proxy.conf';
        $nav_proxy_domains = '/etc/nginx/http.d/nav-proxy-domains.conf';
        $nav_proxy_placeholder = "# 反代配置已由 manage_users.php reset 清空\n"
            . "# 如需添加反代，在此文件中添加：\n"
            . "# location /proxy-path/ {\n"
            . "#     proxy_pass http://内网IP:端口/;\n"
            . "# }\n";
        $nav_proxy_domains_placeholder = "# 子域名反代配置已由 manage_users.php reset 清空\n"
            . "# 如需添加子域名反代，在此文件中添加独立 server 块。\n";
        if (!file_exists($nav_proxy) || strpos(file_get_contents($nav_proxy), 'proxy_pass') !== false) {
            file_put_contents($nav_proxy, $nav_proxy_placeholder, LOCK_EX);
            echo "         已重建空白 nav-proxy.conf（避免 nginx include 报错）\n";
            $proxy_cleaned++;
        }
        if (!file_exists($nav_proxy_domains) || strpos(file_get_contents($nav_proxy_domains), 'server {') !== false) {
            file_put_contents($nav_proxy_domains, $nav_proxy_domains_placeholder, LOCK_EX);
            echo "         已重建空白 nav-proxy-domains.conf（避免 nginx include 报错）\n";
            $proxy_cleaned++;
        }
        echo "[9/10] ✅ 反代配置已清空（共处理 {$proxy_cleaned} 个，均保留文件避免 include 报错）\n";

        // [10] 检查并创建 proxy_params_full
        $ppf_path = '/etc/nginx/proxy_params_full';
        if (file_exists($ppf_path)) {
            echo "[10/10] ✅ /etc/nginx/proxy_params_full 已存在，无需创建\n";
        } else {
            // 从文档中提取的完整模板内容
            $ppf_content = <<<'PPFEOF'
# ══════════════════════════════════════════════════════════════════════
# Nginx 完整反代参数模板 - proxy_params_full  v1.3
# 用法：location / { proxy_pass http://后端IP; include /etc/nginx/proxy_params_full; }
# ══════════════════════════════════════════════════════════════════════

# ── 第一组：协议版本 ──
proxy_http_version              1.1;

# ── 第二组：WebSocket / SSE / 协议升级 ──
proxy_set_header                Upgrade                         $http_upgrade;
proxy_set_header                Connection                      "upgrade";
proxy_set_header                Sec-WebSocket-Extensions        $http_sec_websocket_extensions;
proxy_set_header                Sec-WebSocket-Key               $http_sec_websocket_key;
proxy_set_header                Sec-WebSocket-Version           $http_sec_websocket_version;

# ── 第三组：客户端真实信息透传 ──
proxy_set_header                Host                            $host;
proxy_set_header                X-Real-IP                       $remote_addr;
proxy_set_header                REMOTE-HOST                     $remote_addr;
proxy_set_header                X-Forwarded-For                 $proxy_add_x_forwarded_for;
proxy_set_header                X-Forwarded-Proto               $scheme;
proxy_set_header                X-Forwarded-Host                $host;
proxy_set_header                X-Forwarded-Port                $server_port;
proxy_set_header                X-Original-URI                  $request_uri;
proxy_set_header                X-Original-Method               $request_method;

# ── 第四组：认证头透传 ──
proxy_set_header                Authorization                   $http_authorization;
proxy_set_header                Cookie                          $http_cookie;
proxy_pass_header               Set-Cookie;

# ── 第五组：请求头和请求体完整透传 ──
proxy_pass_request_headers      on;
proxy_pass_request_body         on;

# ── 第六组：断点续传 / 分片下载 ──
proxy_set_header                Range                           $http_range;
proxy_set_header                If-Range                        $http_if_range;

# ── 第七组：内容协商 ──
proxy_set_header                Accept                          $http_accept;
proxy_set_header                Accept-Encoding                 $http_accept_encoding;
proxy_set_header                Accept-Language                 $http_accept_language;

# ── 第八组：跨域 / 防盗链 ──
proxy_set_header                Origin                          $http_origin;
proxy_set_header                Referer                         $http_referer;

# ── 第九组：User-Agent 透传 ──
proxy_set_header                User-Agent                      $http_user_agent;

# ── 第十组：缓存协商头 ──
proxy_set_header                Cache-Control                   $http_cache_control;
proxy_set_header                If-Modified-Since               $http_if_modified_since;
proxy_set_header                If-None-Match                   $http_if_none_match;

# ── 第十一组：CORS 预检请求头透传 ──
proxy_set_header                Access-Control-Request-Headers  $http_access_control_request_headers;
proxy_set_header                Access-Control-Request-Method   $http_access_control_request_method;

# ── 第十二组：上传 / 下载限制 ──
client_max_body_size            0;
client_body_timeout             86400s;
proxy_request_buffering         off;

# ── 第十三组：响应缓冲控制 ──
proxy_buffering                 off;
proxy_buffer_size               16k;
proxy_buffers                   4 32k;
proxy_busy_buffers_size         64k;
proxy_temp_file_write_size      64k;
proxy_max_temp_file_size        0;

# ── 第十四组：完全禁用缓存 ──
proxy_cache                     off;
proxy_no_cache                  1;
proxy_cache_bypass              1;

# ── 第十五组：超时设置 ──
proxy_connect_timeout           600s;
proxy_send_timeout              86400s;
proxy_read_timeout              86400s;
keepalive_timeout               600s;
send_timeout                    86400s;

# ── 第十六组：文件传输优化 ──
sendfile                        on;
tcp_nopush                      on;
tcp_nodelay                     on;

# ── 第十七组：响应拦截与修改控制 ──
proxy_intercept_errors          off;
proxy_redirect                  off;
proxy_hide_header               X-Powered-By;

# ── 第十八组：透传后端响应头 ──
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
proxy_pass_header               Set-Cookie;
proxy_pass_header               Access-Control-Allow-Origin;
proxy_pass_header               Access-Control-Allow-Methods;
proxy_pass_header               Access-Control-Allow-Headers;
proxy_pass_header               Access-Control-Allow-Credentials;
proxy_pass_header               Access-Control-Expose-Headers;
proxy_pass_header               Access-Control-Max-Age;

# ── 第十九组：SSL 后端（后端是 HTTPS 时取消注释）──
# proxy_ssl_verify                off;
# proxy_ssl_server_name           on;
# proxy_ssl_name                  $proxy_host;
# proxy_ssl_protocols             TLSv1.2 TLSv1.3;
# proxy_ssl_session_reuse         on;
PPFEOF;
            $ppf_dir = dirname($ppf_path);
            if (is_writable($ppf_dir)) {
                file_put_contents($ppf_path, $ppf_content);
                chmod($ppf_path, 0644);
                echo "[10/10] ✅ /etc/nginx/proxy_params_full 已自动创建（19组完整参数）\n";
            } else {
                echo "[10/10] ⚠️  /etc/nginx/ 无写权限，请手动创建 proxy_params_full\n";
                echo "         运行：cp /path/to/proxy-params-full.conf /etc/nginx/proxy-params-full\n";
                // 输出内容供手动复制
                echo "\n--- proxy_params_full 内容（请手动保存）---\n";
                echo $ppf_content;
                echo "--- END ---\n";
            }
        }

        // 重载 nginx（如果存在且可执行）
        if (is_executable('/usr/sbin/nginx') || is_executable('/usr/bin/nginx')) {
            exec('nginx -t 2>&1', $ng_out, $ng_ret);
            if ($ng_ret === 0) {
                exec('systemctl reload nginx 2>&1');
                echo "\n         Nginx 配置验证通过，已重载\n";
            } else {
                echo "\n         ⚠️  Nginx 配置验证失败，请手动检查：nginx -t\n";
            }
        }

        echo "\n";
        echo "╔══════════════════════════════════════════════════════════╗\n";
        echo "║  ✅  完整重置完成！                                      ║\n";
        echo "║                                                          ║\n";
        echo "║  已重置内容：                                            ║\n";
        echo "║    • 用户数据已清空                                      ║\n";
        echo "║    • 安装锁已删除                                        ║\n";
        echo "║    • cookie_secure=off，cookie_domain 已清空             ║\n";
        echo "║    • IP 锁定记录已清空                                   ║\n";
        echo "║    • AUTH_SECRET_KEY 已更换为新随机密钥                  ║\n";
        echo "║    • 站点与分组配置已清空                                ║\n";
        echo "║    • 登录日志 auth.log 已清空                            ║\n";
        echo "║    • 备份文件已保留（未清空）                            ║\n";
        echo "║    • 反代 nginx 配置已清空                               ║\n";
        echo "║    • proxy_params_full 已检查/创建                       ║\n";
        echo "║                                                          ║\n";
        echo "║  ⚠️  请立即打开浏览器访问网站首页完成安装向导！          ║\n";
        echo "║  完成前网站处于未保护状态，请尽快操作。                  ║\n";
        echo "╚══════════════════════════════════════════════════════════╝\n\n";
        break;
    case 'del':
        $username = $args[1] ?? '';
        if (!$username) { echo "用法：php manage_users.php del <用户名>\n"; exit(1); }
        $users = auth_load_users();
        if (!isset($users[$username])) { echo "错误：用户 '{$username}' 不存在。\n"; exit(1); }
        unset($users[$username]);
        file_put_contents(USERS_FILE,
            json_encode($users, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
        echo "OK: 用户 '{$username}' 已删除。\n";
        break;

    // ── 帮助 ──
    default:
        echo "\n=== 导航网站用户管理工具 ===\n\n";
        echo "用法：\n";
        echo "  php manage_users.php list                    列出所有用户\n";
        echo "  php manage_users.php info <用户名>           查看用户详情\n";
        echo "  php manage_users.php add <用户名> <密码>     添加管理员账户\n";
        echo "  php manage_users.php passwd <用户名> <新密码> 修改密码\n";
        echo "  php manage_users.php del <用户名>            删除用户\n";
        echo "  php manage_users.php reset                   完整重置：清空用户/安装锁/登录日志/站点分组/反代配置/IP锁定，保留备份，并检查创建 proxy_params_full\n";
        echo "\n注意：此脚本只能命令行运行，Web 访问返回 403。\n\n";
        break;
}
