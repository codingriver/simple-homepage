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
if (!defined('DATA_DIR')) {
    define('DATA_DIR', dirname(__DIR__) . '/data');
}
define('USERS_FILE',        DATA_DIR . '/users.json');
define('CONFIG_FILE',       DATA_DIR . '/config.json');
define('IP_LOCKS_FILE',     DATA_DIR . '/ip_locks.json');
define('INSTALLED_FLAG',    DATA_DIR . '/.installed');
define('AUTH_LOG_FILE',     DATA_DIR . '/logs/auth.log');
/** 登录日志最多保留条数（仅 tail，写入后自动裁剪，避免读全文件） */
define('AUTH_LOG_MAX_LINES', 10);
define('AUTH_SECRET_FILE',  DATA_DIR . '/auth_secret.key');
/** 开发模式标记（容器 entrypoint 根据 NAV_DEV_MODE 创建；PHP-FPM 可能不继承环境变量故用文件） */
define('AUTH_DEV_MODE_FLAG_FILE', DATA_DIR . '/.nav_dev_mode');
define('SESSIONS_FILE', DATA_DIR . '/sessions.json');

/** Token 默认有效期（小时） */
define('AUTH_TOKEN_EXPIRE_HOURS_DEFAULT', 8);
/** 记住我有效期（天） */
define('AUTH_REMEMBER_ME_DAYS_DEFAULT', 60);
/** 登录失败限制次数 */
define('AUTH_LOGIN_FAIL_LIMIT_DEFAULT', 5);
/** 登录锁定时间（分钟） */
define('AUTH_LOGIN_LOCK_MINUTES_DEFAULT', 15);

/**
 * 系统配置默认值（单一来源）
 */
function auth_default_config(): array {
    return [
        'site_name'           => '导航中心',
        'nav_domain'          => '',
        'token_expire_hours'  => AUTH_TOKEN_EXPIRE_HOURS_DEFAULT,
        'remember_me_days'    => AUTH_REMEMBER_ME_DAYS_DEFAULT,
        'login_fail_limit'    => AUTH_LOGIN_FAIL_LIMIT_DEFAULT,
        'login_lock_minutes'  => AUTH_LOGIN_LOCK_MINUTES_DEFAULT,
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
        'theme'               => 'dark',
        'custom_css'          => '',
        'webhook_enabled'     => '0',
        'webhook_type'        => 'custom',
        'webhook_url'         => '',
        'webhook_tg_chat'     => '',
        'webhook_events'      => 'FAIL,IP_LOCKED',

        'task_execution_timeout' => 7200,
        'nginx_last_applied'  => 0,

    ];
}

/**
 * 安全清洗 redirect 参数：
 * - 允许站内相对路径
 * - 允许当前站群（nav_domain / cookie_domain）内的绝对 URL，便于主站登录后回跳到受保护子域名
 * - 其他绝对 URL 一律裁剪为 path 或直接拒绝
 */
function auth_sanitize_redirect(string $redirect): string {
    $redirect = trim($redirect);
    if ($redirect === '') return '';

    // 若是绝对 URL（http:// 或 https://），仅允许当前站群内地址原样保留
    if (preg_match('/^https?:\/\//i', $redirect)) {
        $parsed = parse_url($redirect);
        if (!$parsed || empty($parsed['host'])) return '';

        $host = strtolower((string) $parsed['host']);
        $cfg  = auth_get_config();
        $nav_host = strtolower((string) ($cfg['nav_domain'] ?? ''));
        $cookie_domain = ltrim(strtolower((string) ($cfg['cookie_domain'] ?? '')), '.');

        $is_same_site = false;
        if ($nav_host !== '' && $host === $nav_host) {
            $is_same_site = true;
        }
        if (!$is_same_site && $cookie_domain !== '' && ($host === $cookie_domain || str_ends_with($host, '.' . $cookie_domain))) {
            $is_same_site = true;
        }

        if ($is_same_site) {
            return $redirect;
        }

        // 非本站群绝对 URL：仅提取 path+query+fragment，避免开放重定向
        $redirect = ($parsed['path'] ?? '/');
        if (!empty($parsed['query']))    $redirect .= '?' . $parsed['query'];
        if (!empty($parsed['fragment'])) $redirect .= '#' . $parsed['fragment'];
    }

    // 允许 http(s):// 的本站群绝对 URL 直接通过
    if (preg_match('/^https?:\/\//i', $redirect)) {
        return $redirect;
    }

    // 其余必须是以 / 开头的相对路径，拒绝 //、\、绝对 URL
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
 * 当前请求是否适合使用配置中的 Cookie Domain。
 * - IP 访问永远不使用 Domain Cookie
 * - 配置为空时不使用
 * - 当前 Host 不是该域或其子域时不使用
 */
function auth_cookie_domain_for_request(): string {
    if (auth_is_ip_access()) {
        return '';
    }

    $cfg_domain = trim((string) (auth_get_config()['cookie_domain'] ?? ''));
    if ($cfg_domain === '') {
        return '';
    }

    $host = strtolower((string) strtok(auth_current_host(), ':'));
    $domain = ltrim(strtolower($cfg_domain), '.');
    if ($host === '' || $domain === '') {
        return '';
    }

    if ($host === $domain || str_ends_with($host, '.' . $domain)) {
        return $cfg_domain;
    }

    return '';
}

/**
 * 当前请求是否适合使用 Secure Cookie。
 * 若当前不是 HTTPS，则无论配置如何都回退为 false，避免登录成功后 Cookie 无法回传。
 */
function auth_cookie_secure_for_request(): bool {
    $cfg = auth_get_config();
    $scheme_is_https = auth_request_scheme() === 'https';

    if (!$scheme_is_https) {
        return false;
    }

    $secure_mode = $cfg['cookie_secure'] ?? 'off';
    if ($secure_mode === 'on') {
        return true;
    }
    if ($secure_mode === 'off') {
        return false;
    }
    return true;
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
    global $auth_config_cache;
    if ($auth_config_cache !== null) return $auth_config_cache;
    $cfg = [];
    if (file_exists(CONFIG_FILE)) {
        $cfg = json_decode(file_get_contents(CONFIG_FILE), true) ?? [];
    }
    $auth_config_cache = $cfg + auth_default_config();
    return $auth_config_cache;
}

/**
 * 重置配置缓存（供测试使用）
 */
function auth_reset_config_cache(): void {
    global $auth_config_cache;
    $auth_config_cache = null;
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
    if (file_exists(INSTALLED_FLAG)) {
        return false;
    }
    // 磁盘上已有账户 → 不强制向导（与无人值守安装、异常缺 .installed 时一致）
    if (!empty(auth_load_users_raw())) {
        return false;
    }
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
 * 仅从磁盘读取 users.json（不含开发模式虚拟用户），供安装判断与无人值守引导使用
 *
 * @return array<string, array>
 */
function auth_load_users_raw(): array {
    if (!file_exists(USERS_FILE)) {
        return [];
    }
    $users = json_decode(file_get_contents(USERS_FILE), true) ?? [];
    return is_array($users) ? $users : [];
}

/**
 * 无人值守安装：用户名是否与安装向导规则一致（2–32 位等）
 */
function auth_is_valid_initial_admin_username(string $username): bool {
    return (bool) preg_match('/^[a-zA-Z0-9_-]{2,32}$/', $username);
}

/**
 * 校验安装表单字段（仅安装向导 POST；无人值守不校验密码长度，允许空密码）
 * @param string|null $password2 为 null 时不校验「两次密码一致」
 * @return string[] 错误文案列表
 */
function auth_validate_setup_credentials(string $username, string $password, ?string $password2, string $site_name): array {
    $errors = [];
    if (!preg_match('/^[a-zA-Z0-9_-]{2,32}$/', $username)) {
        $errors[] = '用户名只允许字母、数字、下划线、横杠，长度 2-32 位';
    }
    if (strlen($password) < 8) {
        $errors[] = '密码至少 8 位';
    }
    if ($password2 !== null && $password !== $password2) {
        $errors[] = '两次密码不一致';
    }
    if (trim($site_name) === '') {
        $errors[] = '站点名称不能为空';
    }
    return $errors;
}

/**
 * 读取无人值守安装配置：环境变量优先，否则 data/.initial_admin.json。
 * PASSWORD 允许为空；若已设置 ADMIN 环境变量但为空或用户名非法，则视为无效并删除辅助文件，返回 null（走安装向导）。
 *
 * @return array{user:string,password:string,site_name:string,nav_domain:string}|null
 */
function auth_get_initial_admin_config(): ?array {
    $file = DATA_DIR . '/.initial_admin.json';
    $j    = null;
    if (is_readable($file)) {
        $decoded = json_decode((string) file_get_contents($file), true);
        $j       = is_array($decoded) ? $decoded : [];
    } else {
        $j = [];
    }

    $adminEnv   = getenv('ADMIN');
    $passEnv    = getenv('PASSWORD');
    $nameEnv    = getenv('NAME');
    $domainEnv  = getenv('DOMAIN');

    // 已定义 ADMIN 环境变量（含空串）：必须非空且合法，否则整段无人值守无效
    if ($adminEnv !== false) {
        $user = trim((string) $adminEnv);
        if ($user === '' || !auth_is_valid_initial_admin_username($user)) {
            @unlink($file);
            error_log('[nav] 环境变量 ADMIN 为空或非法，无人值守已取消，请使用安装向导');
            return null;
        }
    } else {
        $user = trim((string)($j['ADMIN'] ?? $j['user'] ?? $j['username'] ?? ''));
        if ($user === '') {
            return null;
        }
        if (!auth_is_valid_initial_admin_username($user)) {
            @unlink($file);
            error_log('[nav] .initial_admin.json 中 ADMIN 非法，已清除，请使用安装向导');
            return null;
        }
    }

    if ($passEnv !== false) {
        $pass = (string) $passEnv;
    } else {
        $pass = (string)($j['PASSWORD'] ?? $j['password'] ?? '');
    }

    if ($nameEnv !== false) {
        $site = trim((string) $nameEnv);
    } else {
        $site = trim((string)($j['NAME'] ?? $j['site_name'] ?? ''));
    }
    if ($domainEnv !== false) {
        $dom = trim((string) $domainEnv);
    } else {
        $dom = trim((string)($j['DOMAIN'] ?? $j['nav_domain'] ?? ''));
    }

    if ($site === '') {
        $site = '导航中心';
    }

    return [
        'user'         => $user,
        'password'     => $pass,
        'site_name'    => $site,
        'nav_domain'   => $dom,
    ];
}

/**
 * 执行首次安装的数据写入（与 setup.php 提交成功时一致）
 */
function auth_apply_initial_install(string $username, string $password, string $site_name, string $nav_domain): void {
    auth_ensure_secret_key();

    if (!is_dir(DATA_DIR)) {
        mkdir(DATA_DIR, 0750, true);
    } else {
        chmod(DATA_DIR, 0750);
    }
    foreach ([
        DATA_DIR . '/backups',
        DATA_DIR . '/logs',
        DATA_DIR . '/favicon_cache',
        DATA_DIR . '/bg',
    ] as $d) {
        if (!is_dir($d)) {
            mkdir($d, 0755, true);
        }
    }

    auth_save_user($username, $password, 'admin');

    $cfg = [
        'site_name'          => $site_name,
        'nav_domain'         => $nav_domain,
        'token_expire_hours' => 8,
        'remember_me_days'   => 60,
        'login_fail_limit'   => 5,
        'login_lock_minutes' => 15,
        'bg_color'           => '',
        'bg_image'           => '',
        'cookie_secure'      => 'off',
        'cookie_domain'      => '',
        'card_size'          => 140,
        'card_height'        => 0,
        'card_show_desc'     => '1',
        'card_layout'        => 'grid',
        'card_direction'     => 'col',
        'display_errors'     => '0',
        'proxy_params_mode'  => 'simple',
        'webhook_enabled'    => '0',
        'webhook_type'       => 'custom',
        'webhook_url'        => '',
        'webhook_tg_chat'    => '',
        'webhook_events'     => 'FAIL,IP_LOCKED',
    ];
    file_put_contents(
        CONFIG_FILE,
        json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
        LOCK_EX
    );

    if (!file_exists(DATA_DIR . '/sites.json')) {
        $sites = ['groups' => [[
            'id'            => 'default',
            'name'          => '我的应用',
            'icon'          => '🌐',
            'order'         => 0,
            'auth_required' => true,
            'visible_to'    => 'all',
            'sites'         => [],
        ]]];
        file_put_contents(
            DATA_DIR . '/sites.json',
            json_encode($sites, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            LOCK_EX
        );
    }

    auth_mark_installed();
    auth_write_log('SETUP', $username, get_client_ip(), 'initial_setup');
}

/**
 * 若配置了合法 ADMIN（或 .initial_admin.json）且尚未安装，则自动执行首次安装并跳过向导（PASSWORD 可为空）
 */
function auth_bootstrap_initial_admin_if_needed(): void {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    if (file_exists(INSTALLED_FLAG)) {
        return;
    }
    if (!empty(auth_load_users_raw())) {
        return;
    }

    $ini = auth_get_initial_admin_config();
    if ($ini === null) {
        return;
    }

    $lockPath = DATA_DIR . '/.bootstrap.lock';
    $fp       = @fopen($lockPath, 'c+');
    if ($fp === false) {
        return;
    }
    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        return;
    }
    try {
        if (file_exists(INSTALLED_FLAG) || !empty(auth_load_users_raw())) {
            return;
        }
        auth_apply_initial_install(
            $ini['user'],
            $ini['password'],
            $ini['site_name'],
            $ini['nav_domain']
        );
        @unlink(DATA_DIR . '/.initial_admin.json');
    } finally {
        flock($fp, LOCK_UN);
        fclose($fp);
        @unlink($lockPath);
    }
}

/**
 * 如果需要安装则跳转到 setup.php（在各页面顶部调用）
 * @param bool $is_setup_page 是否当前就是 setup.php，避免死循环
 */
function auth_check_setup(bool $is_setup_page = false): void {
    auth_bootstrap_initial_admin_if_needed();
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
 * @param string $role        角色（admin/user/host_admin/host_viewer）
 * @param bool   $remember_me 是否记住我
 */
function auth_generate_token(string $username, string $role = 'user', bool $remember_me = false, array $extraClaims = []): string {
    $expire  = $remember_me ? auth_remember_expire() : auth_token_expire();
    $jti     = bin2hex(random_bytes(16));
    $payload = array_merge([
        'username'    => $username,
        'role'        => $role,
        'iat'         => time(),
        'exp'         => time() + $expire,
        'remember_me' => $remember_me,
        'jti'         => $jti, // 唯一ID，防重放
    ], $extraClaims);
    $data = base64_encode(json_encode($payload));
    $sig  = hash_hmac('sha256', $data, auth_secret_key());
    $token = $data . '.' . $sig;
    auth_session_register($jti, $username, $token, $payload['exp']);
    return $token;
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
    if (time() > $payload['exp']) {
        if (!empty($payload['jti'])) {
            auth_session_revoke((string)$payload['jti']);
        }
        return false;
    }
    if (!empty($payload['jti']) && !auth_session_exists((string)$payload['jti'])) {
        return false;
    }
    if (!empty($payload['jti'])) {
        auth_session_touch((string)$payload['jti']);
    }
    return $payload;
}

// ── 会话管理 ──

function auth_session_register(string $jti, string $username, string $token, int $expiresAt = 0): void {
    $dir = dirname(SESSIONS_FILE);
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $sessions = [];
    if (file_exists(SESSIONS_FILE)) {
        $sessions = json_decode(file_get_contents(SESSIONS_FILE), true) ?? [];
    }
    $sessions[$jti] = [
        'username'    => $username,
        'created_at'  => date('Y-m-d H:i:s'),
        'ip'          => get_client_ip(),
        'user_agent'  => $_SERVER['HTTP_USER_AGENT'] ?? '',
        'token_prefix'=> substr($token, 0, 16) . '...',
        'last_active' => date('Y-m-d H:i:s'),
        'expires_at'  => $expiresAt > 0 ? date('Y-m-d H:i:s', $expiresAt) : date('Y-m-d H:i:s', time() + 28800),
    ];
    // 清理过期条目（保留最多 500 条）
    if (count($sessions) > 500) {
        $sessions = array_slice($sessions, -500, null, true);
    }
    file_put_contents(SESSIONS_FILE, json_encode($sessions, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
}

function auth_session_exists(string $jti): bool {
    if (!file_exists(SESSIONS_FILE)) {
        return true; // 向后兼容：尚未启用会话管理时不拒绝
    }
    $sessions = json_decode(file_get_contents(SESSIONS_FILE), true) ?? [];
    return isset($sessions[$jti]);
}

/**
 * 更新会话最后活跃时间
 */
function auth_session_touch(string $jti): void {
    if (!file_exists(SESSIONS_FILE)) return;
    $fp = fopen(SESSIONS_FILE, 'c+');
    if (!$fp) return;
    flock($fp, LOCK_EX);
    $content = stream_get_contents($fp);
    $sessions = json_decode($content, true) ?? [];
    if (isset($sessions[$jti])) {
        $sessions[$jti]['last_active'] = date('Y-m-d H:i:s');
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($sessions, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
    flock($fp, LOCK_UN);
    fclose($fp);
}

/**
 * 获取用户在线状态
 * @return array{status: string, label: string, last_active: ?string}
 */
function auth_user_online_status(string $username): array {
    $sessions = auth_session_list($username);
    if (empty($sessions)) {
        return ['status' => 'offline', 'label' => '离线', 'last_active' => null];
    }
    $lastActive = null;
    foreach ($sessions as $s) {
        $la = $s['last_active'] ?? $s['created_at'] ?? null;
        if ($la && ($lastActive === null || $la > $lastActive)) {
            $lastActive = $la;
        }
    }
    if (!$lastActive) {
        return ['status' => 'offline', 'label' => '离线', 'last_active' => null];
    }
    $diff = time() - strtotime($lastActive);
    if ($diff <= 300) { // 5分钟内活跃视为在线
        return ['status' => 'online', 'label' => '在线', 'last_active' => $lastActive];
    }
    if ($diff <= 600) { // 5-10分钟视为刚离线
        return ['status' => 'recent', 'label' => '刚离线', 'last_active' => $lastActive];
    }
    return ['status' => 'offline', 'label' => '离线', 'last_active' => $lastActive];
}

function auth_session_revoke(string $jti): bool {
    if (!file_exists(SESSIONS_FILE)) return false;
    $fp = fopen(SESSIONS_FILE, 'c+');
    if (!$fp) return false;
    flock($fp, LOCK_EX);
    $content = stream_get_contents($fp);
    $sessions = json_decode($content, true) ?? [];
    if (!isset($sessions[$jti])) {
        flock($fp, LOCK_UN);
        fclose($fp);
        return false;
    }
    unset($sessions[$jti]);
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($sessions, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    flock($fp, LOCK_UN);
    fclose($fp);
    return true;
}

function auth_session_list(?string $filterUsername = null): array {
    if (!file_exists(SESSIONS_FILE)) return [];
    $sessions = json_decode(file_get_contents(SESSIONS_FILE), true) ?? [];
    $now = time();
    $result = [];
    $changed = false;
    foreach ($sessions as $jti => $meta) {
        $expiresAt = !empty($meta['expires_at']) ? strtotime($meta['expires_at']) : 0;
        $lastActive = !empty($meta['last_active']) ? strtotime($meta['last_active']) : 0;
        // 清理 Token 已过期 或 超过 5 分钟未活跃的会话
        if (($expiresAt > 0 && $now > $expiresAt) || ($lastActive > 0 && $now - $lastActive > 300)) {
            unset($sessions[$jti]);
            $changed = true;
            continue;
        }
        if ($filterUsername !== null && ($meta['username'] ?? '') !== $filterUsername) {
            continue;
        }
        $result[] = ['jti' => $jti] + $meta;
    }
    if ($changed) {
        file_put_contents(SESSIONS_FILE, json_encode($sessions, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    }
    return array_reverse($result);
}

/**
 * 从当前请求获取已验证的用户信息
 * 优先读 Cookie，其次读 URL 参数（子站首次跳转）
 */
/**
 * @return array|false
 */
function auth_get_current_user(): ?array {
    $token = $_COOKIE[SESSION_COOKIE_NAME] ?? '';
    if (!$token) {
        return null;
    }
    $payload = auth_verify_token($token);
    return is_array($payload) ? $payload : null;
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

    $is_https = auth_cookie_secure_for_request();
    $cookie_domain = auth_cookie_domain_for_request();

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
    $cfg_domain = trim((string) (auth_get_config()['cookie_domain'] ?? ''));
    $request_domain = auth_cookie_domain_for_request();

    $domains = array_values(array_unique([$request_domain, $cfg_domain, '']));
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
 * 是否启用「开发镜像」内置测试账户（环境变量 NAV_DEV_MODE=1/true，或 data/.nav_dev_mode 存在）
 */
function auth_dev_mode_enabled(): bool {
    $env = getenv('NAV_DEV_MODE');
    if ($env === '1' || strcasecmp((string) $env, 'true') === 0) {
        return true;
    }
    return is_file(AUTH_DEV_MODE_FLAG_FILE);
}

/**
 * 开发模式内置管理员（仅当磁盘上尚无同名用户时注入内存列表，不写入 users.json）
 * 密码：qatest2026（bcrypt 预计算，避免每次请求重新哈希）
 */
function auth_dev_qa_user_record(): array {
    static $hash = '$2y$10$LOX9wuOQK/gXUQaTbywdKObG.z8N587Y6guaGLJdH4RRM21C/ogh.';
    return [
        'password_hash' => $hash,
        'role'          => 'admin',
        'created_at'    => '(dev)',
        'updated_at'    => '(dev)',
        'max_sessions'  => 3,
        '__dev_virtual' => true,
    ];
}

function auth_role_labels(): array {
    return [
        'user' => '普通用户',
        'admin' => '管理员',
        'host_admin' => '主机管理员',
        'host_viewer' => '主机只读',
    ];
}

function auth_role_permissions_map(): array {
    return [
        'admin' => ['*'],
        'host_admin' => [],
        'host_viewer' => [],
        'user' => [],
    ];
}

function auth_user_record_by_username(string $username): ?array {
    $users = auth_load_users();
    if (!isset($users[$username]) || !is_array($users[$username])) {
        return null;
    }
    return $users[$username] + ['username' => $username];
}

function auth_user_permissions(?array $user = null): array {
    if (!$user) {
        $user = auth_get_current_user();
    }
    if (!$user) {
        return [];
    }
    $username = trim((string)($user['username'] ?? ''));
    $role = trim((string)($user['role'] ?? 'user'));
    $record = $username !== '' ? auth_user_record_by_username($username) : null;
    $permissions = [];
    if (is_array($record) && !empty($record['permissions']) && is_array($record['permissions'])) {
        $permissions = array_values(array_unique(array_map('strval', $record['permissions'])));
    } else {
        $permissions = auth_role_permissions_map()[$role] ?? [];
    }
    if ($role === 'admin' && !in_array('*', $permissions, true)) {
        $permissions[] = '*';
    }
    return $permissions;
}

function auth_user_has_permission(string $permission, ?array $user = null): bool {
    if ($permission === '') {
        return false;
    }
    $permissions = auth_user_permissions($user);
    if (in_array('*', $permissions, true)) {
        return true;
    }
    return in_array($permission, $permissions, true);
}

/**
 * 读取用户列表
 */
function auth_load_users(): array {
    $users = [];
    if (file_exists(USERS_FILE)) {
        $users = json_decode(file_get_contents(USERS_FILE), true) ?? [];
    }
    if (!is_array($users)) {
        $users = [];
    }
    if (auth_dev_mode_enabled() && !isset($users['qatest'])) {
        $users['qatest'] = auth_dev_qa_user_record();
    }
    return $users;
}

/**
 * 写入用户列表（开发模式虚拟用户不会落盘）
 */
function auth_write_users(array $users): void {
    foreach ($users as $name => $info) {
        if (!is_array($info)) {
            continue;
        }
        if (!empty($info['__dev_virtual'])) {
            unset($users[$name]);
        } else {
            unset($users[$name]['__dev_virtual']);
        }
    }
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
 * 获取用户最大同时在线设备数
 */
function auth_user_max_sessions(string $username): int {
    $users = auth_load_users();
    $val = $users[$username]['max_sessions'] ?? null;
    if ($val === null || $val === '') return 3;
    $n = (int)$val;
    return $n >= 1 ? $n : 3;
}

/**
 * 获取用户当前活跃会话数量
 */
function auth_user_active_session_count(string $username): int {
    return count(auth_session_list($username));
}

/**
 * 添加或更新用户
 */
function auth_save_user(string $username, string $password, string $role = 'admin'): void {
    $users = auth_load_users();
    $users[$username] = [
        'password_hash' => password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]),
        'role'          => $role,
        'permissions'   => $users[$username]['permissions'] ?? (auth_role_permissions_map()[$role] ?? []),
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

function auth_require_permission(string $permission): array {
    $user = auth_require_login();
    if (!auth_user_has_permission($permission, $user)) {
        http_response_code(403);
        die('403 Forbidden: 权限不足。');
    }
    return $user;
}

// ============================================================
// IP 登录失败锁定
// ============================================================

function ip_locks_load_raw(): array {
    if (!file_exists(IP_LOCKS_FILE)) return [];
    return json_decode(file_get_contents(IP_LOCKS_FILE), true) ?? [];
}

function ip_locks_prune(array $data): array {
    $now = time();
    foreach ($data as $ip => $info) {
        $locked_until = (int)($info['locked_until'] ?? 0);
        if ($locked_until > 0 && $locked_until < $now && ($info['fails'] ?? 0) > 0) {
            $data[$ip] = ['fails' => 0, 'locked_until' => 0, 'last_fail' => $info['last_fail'] ?? 0];
        }
    }
    return $data;
}

/**
 * 读取 IP 锁定记录（同时清理已过期的条目）
 */
function ip_locks_load(): array {
    $data = ip_locks_prune(ip_locks_load_raw());
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
 * 原子化修改 IP 锁定记录（防止读-改-写竞争）
 */
function ip_locks_atomic(callable $mutator): void {
    $dir = dirname(IP_LOCKS_FILE);
    if (!is_dir($dir)) {
        mkdir($dir, 0700, true);
    }
    $lockFile = IP_LOCKS_FILE . '.lock';
    $fp = fopen($lockFile, 'c');
    if (!$fp) {
        return;
    }
    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        return;
    }
    try {
        $data = ip_locks_prune(ip_locks_load_raw());
        $mutator($data);
        ip_locks_save($data);
    } finally {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
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
    $limit = (int)($cfg['login_fail_limit'] ?? AUTH_LOGIN_FAIL_LIMIT_DEFAULT);
    $mins  = (int)($cfg['login_lock_minutes'] ?? AUTH_LOGIN_LOCK_MINUTES_DEFAULT);
    ip_locks_atomic(function(&$locks) use ($ip, $limit, $mins) {
        if (!isset($locks[$ip])) $locks[$ip] = ['fails' => 0, 'locked_until' => 0];
        $locks[$ip]['fails']++;
        $locks[$ip]['last_fail'] = time();
        if ($locks[$ip]['fails'] >= $limit) {
            $locks[$ip]['locked_until'] = time() + $mins * 60;
        }
    });
}

/**
 * 登录成功后重置 IP 失败计数
 */
function ip_reset_fails(string $ip): void {
    ip_locks_atomic(function(&$locks) use ($ip) {
        if (isset($locks[$ip])) {
            $locks[$ip] = ['fails' => 0, 'locked_until' => 0];
        }
    });
}

// ============================================================
// 登录日志
// ============================================================

/**
 * 从文件末尾向前读取，收集最多 $need 条非空行，顺序为最新在前
 */
function auth_read_log_collect_newest(string $path, int $need): array {
    if ($need <= 0 || !is_readable($path)) {
        return [];
    }
    $size = filesize($path);
    if ($size === false || $size === 0) {
        return [];
    }
    $chunk  = 8192;
    $result = [];
    $buf    = '';
    $fp     = fopen($path, 'rb');
    if (!$fp) {
        return [];
    }
    $pos = $size;
    while ($pos > 0 && count($result) < $need) {
        $read = min($chunk, $pos);
        $pos -= $read;
        fseek($fp, $pos);
        $buf = fread($fp, $read) . $buf;
        $parts = explode("\n", $buf);
        $buf = array_shift($parts);
        foreach (array_reverse($parts) as $line) {
            if (trim($line) === '') {
                continue;
            }
            $result[] = $line;
            if (count($result) >= $need) {
                break;
            }
        }
    }
    fclose($fp);
    if (count($result) < $need && trim($buf) !== '') {
        $result[] = $buf;
    }
    return $result;
}

/**
 * 仅保留最近 $max 条（从尾部读取，不写满全文件）
 */
function auth_log_prune_to_max(int $max): void {
    if (!file_exists(AUTH_LOG_FILE)) {
        return;
    }
    $newest = auth_read_log_collect_newest(AUTH_LOG_FILE, $max);
    if ($newest === []) {
        return;
    }
    $chronological = array_reverse($newest);
    file_put_contents(AUTH_LOG_FILE, implode("\n", $chronological) . "\n", LOCK_EX);
}

/**
 * 写入登录日志（追加后裁剪为最近 AUTH_LOG_MAX_LINES 条，读盘仅尾部）
 * @param string $type     SUCCESS / FAIL / IP_LOCKED
 * @param string $username 用户名（可能为空）
 * @param string $ip       客户端IP
 * @param string $note     附加说明
 */
function auth_write_log(string $type, string $username, string $ip, string $note = ''): void {
    $dir = dirname(AUTH_LOG_FILE);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $line = sprintf(
        "[%s] %s user=%s ip=%s%s\n",
        date('Y-m-d H:i:s'),
        str_pad($type, 10),
        $username ?: '-',
        $ip,
        $note ? " note=$note" : ''
    );
    file_put_contents(AUTH_LOG_FILE, $line, FILE_APPEND | LOCK_EX);
    auth_log_prune_to_max(AUTH_LOG_MAX_LINES);
    // 触发 Webhook 通知（webhook_send 由 admin/shared/functions.php 提供，仅在已加载时调用）
    if (function_exists('webhook_send')) {
        webhook_send($type, $username, $ip, $note);
    }

}

/**
 * 读取最近 N 条登录日志（从文件末尾倒序，最新在前）
 * 磁盘上最多 AUTH_LOG_MAX_LINES 条，读取为轻量小文件
 * @param int $lines  每页行数
 * @param int $offset 跳过行数（分页）
 * @return array ['total' => int, 'rows' => string[]]
 */
function auth_read_log(int $lines = 100, int $offset = 0): array {
    if (!file_exists(AUTH_LOG_FILE)) {
        return ['total' => 0, 'rows' => []];
    }

    // 历史超大文件：用尾部多取 1 条判断是否需迁移裁剪（不扫描全文件）
    $sample = auth_read_log_collect_newest(AUTH_LOG_FILE, AUTH_LOG_MAX_LINES + 1);
    if (count($sample) > AUTH_LOG_MAX_LINES) {
        auth_log_prune_to_max(AUTH_LOG_MAX_LINES);
    }

    $all = file(AUTH_LOG_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!$all) {
        return ['total' => 0, 'rows' => []];
    }
    $all = array_reverse($all);

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
        // 后台 header 在输出 HTML 前会 session_write_close()，之后不能再 session_start；
        // 同一请求内后续 csrf_field() 使用此处缓存的 token（见 admin/shared/header.php）
        if (isset($GLOBALS['_nav_csrf_token']) && is_string($GLOBALS['_nav_csrf_token']) && $GLOBALS['_nav_csrf_token'] !== '') {
            return $GLOBALS['_nav_csrf_token'];
        }
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
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

// 运行时应用 display_errors 配置（不修改 ini 文件，避免 FPM 重启）
try {
    $cfg = auth_get_config();
    $de = ($cfg['display_errors'] ?? '0') === '1' ? '1' : '0';
    @ini_set('display_errors', $de);
    @ini_set('display_startup_errors', $de);
} catch (\Throwable $e) {
    // ignore
}
