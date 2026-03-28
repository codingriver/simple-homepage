<?php
/**
 * 核心认证库 v2.0
 * 导航站和所有子站共享使用
 *
 * 功能：Token生成验证、Cookie管理、用户验证、IP锁定、
 *       登录日志、首次安装检测、配置读取
 */

// ============================================================
// 配置区（AUTH_SECRET_KEY 优先读环境变量，其次读 data/auth_secret.key）
// ============================================================
define('SESSION_COOKIE_NAME', 'nav_session');
define('NAV_DOMAIN',        'nav.yourdomain.com');
define('COOKIE_DOMAIN',     '.yourdomain.com');   // 前面有点，支持所有子域共享
define('NAV_LOGIN_URL',     'https://nav.yourdomain.com/login.php');

// 数据目录（相对于本文件的上级目录）
define('DATA_DIR',          __DIR__ . '/../data');
define('USERS_FILE',        DATA_DIR . '/users.json');
define('CONFIG_FILE',       DATA_DIR . '/config.json');
define('IP_LOCKS_FILE',     DATA_DIR . '/ip_locks.json');
define('INSTALLED_FLAG',    DATA_DIR . '/.installed');
define('AUTH_LOG_FILE',     DATA_DIR . '/logs/auth.log');
define('AUTH_SECRET_FILE',  DATA_DIR . '/auth_secret.key');

/**
 * 系统配置默认值（单一来源）
 */
function auth_default_config(): array {
    return [
        'site_name'           => '导航中心',
        'nav_domain'          => '',
        'token_expire_hours'  => 8,
        'remember_me_days'    => 60,
        'login_fail_limit'    => 5,
        'login_lock_minutes'  => 15,
        'bg_color'            => '',
        'bg_image'            => '',
        'cookie_secure'       => 'off',
        'cookie_domain'       => '',
        'card_size'           => 140,
        'card_height'         => 0,
        'card_show_desc'      => '1',
        'card_layout'         => 'grid',
        'card_direction'      => 'col',
        'display_errors'      => '0',
        'proxy_params_mode'   => 'simple',
        'webhook_enabled'     => '0',
        'webhook_type'        => 'custom',
        'webhook_url'         => '',
        'webhook_tg_chat'     => '',
        'webhook_events'      => 'FAIL,IP_LOCKED',
        'nginx_last_applied'  => 0,
    ];
}

/**
 * 安全清洗 redirect 参数：仅允许站内相对路径
 * 若传入绝对 URL（如 IP 直连场景），自动提取路径部分。
 */
function auth_sanitize_redirect(string $redirect): string {
    $redirect = trim($redirect);
    if ($redirect === '') return '';

    // 若是绝对 URL（http:// 或 https://），提取其中的 path+query+fragment
    if (preg_match('/^https?:\/\//i', $redirect)) {
        $parsed = parse_url($redirect);
        if (!$parsed) return '';
        $redirect = ($parsed['path'] ?? '/');
        if (!empty($parsed['query']))    $redirect .= '?' . $parsed['query'];
        if (!empty($parsed['fragment'])) $redirect .= '#' . $parsed['fragment'];
    }

    // 必须是以 / 开头的相对路径，拒绝 //、\、绝对 URL
    if ($redirect === '' || $redirect[0] !== '/') return '';
    if (strpos($redirect, '//') === 0 || strpos($redirect, '\\') !== false) return '';

    return $redirect;
}

/**
 * 获取当前实例的认证密钥。
 * 优先使用环境变量 AUTH_SECRET_KEY；否则使用 data/auth_secret.key。
 */
function auth_secret_key(): string {
    static $secret = null;
    if ($secret !== null) return $secret;

    $env_secret = trim((string) getenv('AUTH_SECRET_KEY'));
    if ($env_secret !== '') {
        $secret = $env_secret;
        return $secret;
    }

    if (!file_exists(AUTH_SECRET_FILE)) {
        auth_rotate_secret_key();
    }

    $secret = trim((string) @file_get_contents(AUTH_SECRET_FILE));
    if ($secret === '') {
        auth_rotate_secret_key();
        $secret = trim((string) @file_get_contents(AUTH_SECRET_FILE));
    }

    if ($secret === '') {
        throw new RuntimeException('AUTH_SECRET_KEY 未初始化');
    }

    return $secret;
}

/**
 * 生成并持久化新的认证密钥。
 */
function auth_rotate_secret_key(): string {
    $dir = dirname(AUTH_SECRET_FILE);
    if (!is_dir($dir)) mkdir($dir, 0750, true);

    $secret = bin2hex(random_bytes(64));
    file_put_contents(AUTH_SECRET_FILE, $secret . PHP_EOL, LOCK_EX);
    @chmod(AUTH_SECRET_FILE, 0600);

    return $secret;
}

/**
 * 确保认证密钥文件存在。
 */
function auth_ensure_secret_key(): string {
    return auth_secret_key();
}

/**
 * 检测当前请求协议，兼容反向代理。
 */
function auth_request_scheme(): string {
    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
        $proto = strtolower(trim(explode(',', (string) $_SERVER['HTTP_X_FORWARDED_PROTO'])[0]));
        if (in_array($proto, ['http', 'https'], true)) {
            return $proto;
        }
    }
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return 'https';
    }
    if (!empty($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443) {
        return 'https';
    }
    return 'http';
}

/**
 * 获取导航站域名。
 * 优先使用 config.json 中的 nav_domain；否则回退到当前 Host 或示例常量。
 */
function auth_nav_domain(): string {
    $cfg_domain = trim((string) (auth_get_config()['nav_domain'] ?? ''));
    if ($cfg_domain !== '') {
        return $cfg_domain;
    }

    $host = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));
    if ($host !== '') {
        return $host;
    }

    return NAV_DOMAIN;
}

/**
 * 获取导航站登录地址。
 */
function auth_nav_login_url(): string {
    $domain = auth_nav_domain();
    if ($domain === '') {
        return NAV_LOGIN_URL;
    }
    return auth_request_scheme() . '://' . $domain . '/login.php';
}

/**
 * 获取当前访问使用的 Host。
 */
function auth_current_host(): string {
    $host = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));
    return $host !== '' ? $host : auth_nav_domain();
}

/**
 * 当前访问是否为 IP 模式（含端口）。
 */
function auth_is_ip_access(): bool {
    $host_only = strtok(auth_current_host(), ':');
    return $host_only !== false && filter_var($host_only, FILTER_VALIDATE_IP) !== false;
}

/**
 * 获取当前访问上下文下的登录地址。
 * 导航站本体通过当前 Host 登录，便于保留内网 IP 排障入口。
 */
function auth_current_login_url(): string {
    return auth_request_scheme() . '://' . auth_current_host() . '/login.php';
}

// ============================================================
// 动态配置读取（从 config.json，带默认值）
// ============================================================

/**
 * 读取系统配置，带默认值
 */
function auth_get_config(): array {
    static $cfg = null;
    if ($cfg !== null) return $cfg;
    $cfg = [];
    if (file_exists(CONFIG_FILE)) {
        $cfg = json_decode(file_get_contents(CONFIG_FILE), true) ?? [];
    }
    $cfg += auth_default_config();
    return $cfg;
}

/** Token 默认有效期（秒） */
function auth_token_expire(): int {
    return (int)(auth_get_config()['token_expire_hours']) * 3600;
}

/** 记住我有效期（秒） */
function auth_remember_expire(): int {
    return (int)(auth_get_config()['remember_me_days']) * 86400;
}

// ============================================================
// 首次安装检测
// ============================================================

/**
 * 是否需要运行安装向导
 * 条件：.installed 文件不存在 AND users.json 为空
 */
function auth_needs_setup(): bool {
    // .installed 存在 → 已安装，直接返回 false
    if (file_exists(INSTALLED_FLAG)) return false;
    // .installed 不存在但有用户 → 异常状态，不触发向导（有用户就能登录）
    $users = auth_load_users();
    return empty($users);
}

/**
 * 标记安装完成，写入 .installed 锁文件
 */
function auth_mark_installed(): void {
    $dir = DATA_DIR;
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    file_put_contents(INSTALLED_FLAG, date('Y-m-d H:i:s'));
}

/**
 * 如果需要安装则跳转到 setup.php（在各页面顶部调用）
 * @param bool $is_setup_page 是否当前就是 setup.php，避免死循环
 */
function auth_check_setup(bool $is_setup_page = false): void {
    if (!$is_setup_page && auth_needs_setup()) {
        header('Location: /setup.php');
        exit;
    }
}

// ============================================================
// Token 生成与验证（JWT-like，HMAC-SHA256）
// ============================================================

/**
 * 生成 Token
 * @param string $username    用户名
 * @param string $role        角色（admin/user）
 * @param bool   $remember_me 是否记住我
 */
function auth_generate_token(string $username, string $role = 'user', bool $remember_me = false): string {
    $expire  = $remember_me ? auth_remember_expire() : auth_token_expire();
    $payload = [
        'username'    => $username,
        'role'        => $role,
        'iat'         => time(),
        'exp'         => time() + $expire,
        'remember_me' => $remember_me,
        'jti'         => bin2hex(random_bytes(16)), // 唯一ID，防重放
    ];
    $data = base64_encode(json_encode($payload));
    $sig  = hash_hmac('sha256', $data, auth_secret_key());
    return $data . '.' . $sig;
}

/**
 * 验证 Token，返回 payload 数组或 false
 */
/**
 * @return array|false
 */
function auth_verify_token(string $token) {
    $parts = explode('.', $token, 2);
    if (count($parts) !== 2) return false;
    [$data, $sig] = $parts;
    // 时间安全的签名比对，防时序攻击
    $expected = hash_hmac('sha256', $data, auth_secret_key());
    if (!hash_equals($expected, $sig)) return false;
    $payload = json_decode(base64_decode($data), true);
    if (!$payload || !isset($payload['exp'])) return false;
    if (time() > $payload['exp']) return false;
    return $payload;
}

/**
 * 从当前请求获取已验证的用户信息
 * 优先读 Cookie，其次读 URL 参数（子站首次跳转）
 */
/**
 * @return array|false
 */
function auth_get_current_user() {
    $token = null;
    if (!empty($_COOKIE[SESSION_COOKIE_NAME])) {
        $token = $_COOKIE[SESSION_COOKIE_NAME];
    } elseif (!empty($_GET['_nav_token'])) {
        // 子站通过 URL 参数传入 Token，必须是字符串
        $token = (string) $_GET['_nav_token'];
    }
    if (!$token) return false;
    return auth_verify_token($token);
}

// ============================================================
// Cookie 管理
// ============================================================

/**
 * 设置登录 Cookie
 */
function auth_set_cookie(string $token, bool $remember_me = false): void {
    $expire = $remember_me
        ? (time() + auth_remember_expire())
        : (time() + auth_token_expire());
    $cfg = auth_get_config();

    // 检测当前访问的 host 是否为 IP 地址（含端口，如 192.168.1.100:8080）
    $is_ip_access = auth_is_ip_access();

    // Cookie Secure 模式
    // IP 访问时强制降级为 false（否则 HTTP+IP 永远登不进去）
    if ($is_ip_access) {
        $is_https = false;
    } else {
        $secure_mode = $cfg['cookie_secure'] ?? 'off';
        if ($secure_mode === 'on') {
            $is_https = true;
        } elseif ($secure_mode === 'off') {
            $is_https = false;
        } else {
            $is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                     || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
                     || (!empty($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);
        }
    }

    // Cookie Domain
    // IP 访问时强制留空（IP 地址无法匹配域名 Cookie，留空浏览器自动绑定当前 IP）
    if ($is_ip_access) {
        $cookie_domain = '';
    } else {
        $cookie_domain = trim($cfg['cookie_domain'] ?? '');
    }

    setcookie(SESSION_COOKIE_NAME, $token, [
        'expires'  => $expire,
        'path'     => '/',
        'domain'   => $cookie_domain,
        'secure'   => $is_https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/**
 * 清除登录 Cookie（退出登录）
 */
function auth_clear_cookie(): void {
    $cfg       = auth_get_config();
    $is_ip_access = auth_is_ip_access();

    if ($is_ip_access) {
        $cookie_domain = '';
    } else {
        $cookie_domain = trim($cfg['cookie_domain'] ?? '');
    }

    $domains = array_values(array_unique([$cookie_domain, '']));
    foreach ($domains as $domain) {
        foreach ([false, true] as $secure) {
            setcookie(SESSION_COOKIE_NAME, '', [
                'expires'  => time() - 3600,
                'path'     => '/',
                'domain'   => $domain,
                'secure'   => $secure,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
    }
}

// ============================================================
// 用户管理（JSON 文件存储）
// ============================================================

/**
 * 读取用户列表
 */
function auth_load_users(): array {
    if (!file_exists(USERS_FILE)) return [];
    return json_decode(file_get_contents(USERS_FILE), true) ?? [];
}

/**
 * 写入用户列表
 */
function auth_write_users(array $users): void {
    $dir = dirname(USERS_FILE);
    if (!is_dir($dir)) mkdir($dir, 0700, true);
    file_put_contents(
        USERS_FILE,
        json_encode($users, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
        LOCK_EX
    );
}

/**
 * 验证用户名密码，返回用户信息或 false
 */
/**
 * @return array|false
 */
function auth_check_password(string $username, string $password) {
    $users = auth_load_users();
    if (!isset($users[$username])) return false;
    if (!password_verify($password, $users[$username]['password_hash'])) return false;
    return $users[$username];
}

/**
 * 添加或更新用户
 */
function auth_save_user(string $username, string $password, string $role = 'admin'): void {
    $users = auth_load_users();
    $users[$username] = [
        'password_hash' => password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]),
        'role'          => $role,
        'created_at'    => $users[$username]['created_at'] ?? date('Y-m-d H:i:s'),
        'updated_at'    => date('Y-m-d H:i:s'),
    ];
    auth_write_users($users);
}

// ============================================================
// 权限检查
// ============================================================

/**
 * 要求已登录，否则跳转登录页
 */
function auth_require_login(): array {
    $user = auth_get_current_user();
    if (!$user) {
        $redirect = urlencode($_SERVER['REQUEST_URI'] ?? '/');
        header('Location: ' . auth_current_login_url() . '?redirect=' . $redirect);
        exit;
    }
    return $user;
}

/**
 * 要求管理员权限，否则返回 403
 */
function auth_require_admin(): array {
    $user = auth_require_login();
    if (($user['role'] ?? '') !== 'admin') {
        http_response_code(403);
        die('403 Forbidden: 需要管理员权限。');
    }
    return $user;
}

// ============================================================
// IP 登录失败锁定
// ============================================================

/**
 * 读取 IP 锁定记录（同时清理已过期的条目）
 */
function ip_locks_load(): array {
    if (!file_exists(IP_LOCKS_FILE)) return [];
    $data = json_decode(file_get_contents(IP_LOCKS_FILE), true) ?? [];
    // 顺带清理过期记录
    $now = time();
    $changed = false;
    foreach ($data as $ip => $info) {
        $locked_until = (int)($info['locked_until'] ?? 0);
        if ($locked_until > 0 && $locked_until < $now && ($info['fails'] ?? 0) > 0) {
            // 锁定已过期，重置
            $data[$ip] = ['fails' => 0, 'locked_until' => 0, 'last_fail' => $info['last_fail'] ?? 0];
            $changed = true;
        }
    }
    if ($changed) ip_locks_save($data);
    return $data;
}

/**
 * 写入 IP 锁定记录
 */
function ip_locks_save(array $data): void {
    $dir = dirname(IP_LOCKS_FILE);
    if (!is_dir($dir)) mkdir($dir, 0700, true);
    file_put_contents(IP_LOCKS_FILE, json_encode($data, JSON_PRETTY_PRINT), LOCK_EX);
}

/**
 * 获取当前请求的真实客户端 IP（支持反向代理链）
 *
 * 优先级：
 *   1. X-Real-IP（上游反代直接设置的单一真实 IP，最可信）
 *   2. X-Forwarded-For 链中最左侧的非私有 IP（穿越多层反代时）
 *   3. REMOTE_ADDR 兜底（直连或仅容器网关时）
 *
 * 注意：需在 Nginx fastcgi_param 中将这两个头传给 PHP，
 *       否则 $_SERVER 中不会存在这些键。
 */
function get_client_ip(): string {
    // 1. X-Real-IP：通常由最外层反代（如宿主机 Nginx/Caddy）直接写入真实 IP
    if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
        $ip = trim($_SERVER['HTTP_X_REAL_IP']);
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return $ip;
        }
        // X-Real-IP 是内网 IP 时（如直接内网访问）也接受
        if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
    }

    // 2. X-Forwarded-For：可能包含多个 IP（客户端, 代理1, 代理2, ...）
    //    取最左侧的公网 IP；若全为私有 IP（内网访问），取最左侧的私有 IP
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ips = array_map('trim', explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']));
        $first_private = null;
        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP)) continue;
            // 优先返回公网 IP
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return $ip;
            }
            // 记录第一个合法私有 IP 备用
            if ($first_private === null) $first_private = $ip;
        }
        if ($first_private !== null) return $first_private;
    }

    // 3. REMOTE_ADDR 兜底（直连时为真实 IP，容器内为 Docker 网关 IP）
    $ip = trim($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
}

/**
 * 检查 IP 是否被锁定
 */
function ip_is_locked(string $ip): bool {
    $locks = ip_locks_load();
    if (!isset($locks[$ip])) return false;
    return ($locks[$ip]['locked_until'] ?? 0) > time();
}

/**
 * 记录登录失败，超过阈值则锁定
 */
function ip_record_fail(string $ip): void {
    $cfg   = auth_get_config();
    $limit = (int)($cfg['login_fail_limit'] ?? 5);
    $mins  = (int)($cfg['login_lock_minutes'] ?? 15);
    $locks = ip_locks_load();
    if (!isset($locks[$ip])) $locks[$ip] = ['fails' => 0, 'locked_until' => 0];
    $locks[$ip]['fails']++;
    $locks[$ip]['last_fail'] = time();
    if ($locks[$ip]['fails'] >= $limit) {
        $locks[$ip]['locked_until'] = time() + $mins * 60;
    }
    ip_locks_save($locks);
}

/**
 * 登录成功后重置 IP 失败计数
 */
function ip_reset_fails(string $ip): void {
    $locks = ip_locks_load();
    if (isset($locks[$ip])) {
        $locks[$ip] = ['fails' => 0, 'locked_until' => 0];
        ip_locks_save($locks);
    }
}

// ============================================================
// 登录日志
// ============================================================

/**
 * 写入登录日志
 * @param string $type     SUCCESS / FAIL / IP_LOCKED
 * @param string $username 用户名（可能为空）
 * @param string $ip       客户端IP
 * @param string $note     附加说明
 */
function auth_write_log(string $type, string $username, string $ip, string $note = ''): void {
    $dir = dirname(AUTH_LOG_FILE);
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $line = sprintf(
        "[%s] %s user=%s ip=%s%s\n",
        date('Y-m-d H:i:s'),
        str_pad($type, 10),
        $username ?: '-',
        $ip,
        $note ? " note=$note" : ''
    );
    file_put_contents(AUTH_LOG_FILE, $line, FILE_APPEND | LOCK_EX);
    // 触发 Webhook 通知（webhook_send 由 admin/shared/functions.php 提供，仅在已加载时调用）
    if (function_exists('webhook_send')) {
        webhook_send($type, $username, $ip, $note);
    }
}

/**
 * 读取最近 N 条登录日志（从文件末尾倒序，最新在前）
 * @param int $lines  每页行数
 * @param int $offset 跳过行数（分页）
 * @return array ['total' => int, 'rows' => string[]]
 */
function auth_read_log(int $lines = 100, int $offset = 0): array {
    if (!file_exists(AUTH_LOG_FILE)) return ['total' => 0, 'rows' => []];
    $all = file(AUTH_LOG_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!$all) return ['total' => 0, 'rows' => []];
    $all = array_reverse($all); // 最新在前
    return ['total' => count($all), 'rows' => array_slice($all, $offset, $lines)];
}

// ============================================================
// 工具函数
// ============================================================

/**
 * 判断 IP 是否为内网地址
 * 允许：10.x / 172.16-31.x / 192.168.x / 127.x
 */
function is_private_ip(string $ip): bool {
    $ip = trim($ip);
    if (!filter_var($ip, FILTER_VALIDATE_IP)) return false;
    // FILTER_FLAG_NO_PRIV_RANGE 会拒绝私有地址，返回 false 表示是私有地址
    return filter_var($ip, FILTER_VALIDATE_IP,
        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
}

/**
 * 检查 IP 是否属于可外连公网地址
 */
function is_public_ip(string $ip): bool {
    $ip = trim($ip);
    if (!filter_var($ip, FILTER_VALIDATE_IP)) return false;
    return filter_var($ip, FILTER_VALIDATE_IP,
        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
}

/**
 * 检查反代目标是否为合法内网地址（防 SSRF）
 * 支持格式：http://192.168.1.x:port
 * 允许：私有地址段（10.x / 172.16-31.x / 192.168.x）
 * 拒绝：loopback(127.x)、链路本地(169.254.x)、外网IP、无效地址
 */
function is_allowed_proxy_target(string $url): bool {
    $parsed = parse_url($url);
    if (!$parsed || !isset($parsed['host'])) return false;

    $scheme = strtolower((string)($parsed['scheme'] ?? ''));
    if (!in_array($scheme, ['http', 'https'], true)) return false;

    $host = $parsed['host'];
    // 必须是合法 IP（hostname 不允许，防 DNS 重绑定）
    if (!filter_var($host, FILTER_VALIDATE_IP)) return false;

    // 明确拒绝 loopback、链路本地、未指定、广播等地址
    if (preg_match('/^(127\.|169\.254\.|0\.|255\.|::1$|fe80:|::$)/i', $host)) return false;

    // 必须是 RFC1918 私网地址（仅允许 10/172.16-31/192.168）
    if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        if (preg_match('/^10\./', $host)) return true;
        if (preg_match('/^192\.168\./', $host)) return true;
        if (preg_match('/^172\.(1[6-9]|2\d|3[0-1])\./', $host)) return true;
        return false;
    }

    // IPv6 场景暂不允许作为 proxy_target（避免边界复杂度）
    return false;
}

// ============================================================
// CSRF 保护（login.php / setup.php 公共页面也可调用）
// ============================================================

if (!function_exists('csrf_token')) {
    function csrf_token(): string {
        if (session_status() === PHP_SESSION_NONE) session_start();
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    function csrf_field(): string {
        return '<input type="hidden" name="_csrf" value="' . htmlspecialchars(csrf_token()) . '">';
    }

    function csrf_check(): void {
        if (session_status() === PHP_SESSION_NONE) session_start();
        $token    = $_POST['_csrf'] ?? '';
        $expected = $_SESSION['csrf_token'] ?? '';
        if (!$token || !$expected || !hash_equals($expected, $token)) {
            http_response_code(403);
            // AJAX 请求返回 JSON，普通请求返回带倒计时跳转的 HTML
            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => false, 'msg' => 'CSRF验证失败，请刷新页面重试']);
            } else {
                // 智能判断跳转目标：后台页面跳回来源，其他跳登录页
                $ref = $_SERVER['HTTP_REFERER'] ?? '';
                $back = $ref ?: 'javascript:history.back()';
                // 5秒后自动跳转
                echo '<!DOCTYPE html><html lang="zh-CN"><head>'
                    . '<meta charset="UTF-8">'
                    . '<meta http-equiv="refresh" content="5;url=' . htmlspecialchars($back) . '">'
                    . '<title>请求已过期</title>'
                    . '<style>'
                    . 'body{font-family:sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;background:#080b10;color:#cdd6e8;}'
                    . '.box{text-align:center;padding:40px;background:#0e1218;border:1px solid #1e2733;border-radius:12px;max-width:380px;}'
                    . '.icon{font-size:48px;margin-bottom:16px;}'
                    . 'h2{color:#ff5566;margin:0 0 10px;}'
                    . 'p{color:#556070;font-size:14px;margin:8px 0;}'
                    . '.count{color:#00d4aa;font-weight:700;font-size:18px;}'
                    . 'a{color:#00d4aa;text-decoration:none;font-size:13px;}'
                    . '</style>'
                    . '</head><body><div class="box">'
                    . '<div class="icon">⚠️</div>'
                    . '<h2>请求已过期</h2>'
                    . '<p>安全令牌验证失败（页面可能已过期）</p>'
                    . '<p><span class="count" id="c">5</span> 秒后自动返回…</p>'
                    . '<p><a href="' . htmlspecialchars($back) . '">立即返回</a></p>'
                    . '</div>'
                    . '<script>var n=5,t=setInterval(function(){n--;document.getElementById("c").textContent=n;if(n<=0){clearInterval(t);location.href="' . htmlspecialchars($back, ENT_QUOTES) . '";}},1000);</script>'
                    . '</body></html>';
            }
            exit;
        }
    }
} 
