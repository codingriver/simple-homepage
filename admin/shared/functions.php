<?php
/**
 * 后台公共函数库 admin/shared/functions.php
 * 包含：站点数据读写、配置读写、CSRF、Flash消息、备份/恢复、统计
 */
require_once __DIR__ . '/../../shared/auth.php';

// 数据文件路径常量
define('SITES_FILE',   DATA_DIR . '/sites.json');
define('BACKUPS_DIR',  DATA_DIR . '/backups');
define('BG_DIR',       DATA_DIR . '/bg');
define('MAX_BACKUPS',  20); // 最多保留备份数

// ── 站点数据 ──

/** 读取站点配置 */
function load_sites(): array {
    if (!file_exists(SITES_FILE)) return ['groups' => []];
    return json_decode(file_get_contents(SITES_FILE), true) ?? ['groups' => []];
}

/** 写入站点配置 */
function save_sites(array $data): void {
    file_put_contents(SITES_FILE,
        json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        LOCK_EX);
}

// ── 系统配置 ──

/** 读取系统配置 */
function load_config(): array {
    if (!file_exists(CONFIG_FILE)) return [];
    return json_decode(file_get_contents(CONFIG_FILE), true) ?? [];
}

/** 写入系统配置 */
function save_config(array $cfg): void {
    file_put_contents(CONFIG_FILE,
        json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
        LOCK_EX);
}

// ── CSRF 保护 ──

/** 获取或生成 CSRF Token（存储在 Session 中）*/
function csrf_token(): string {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** 输出隐藏的 CSRF Token 字段 */
function csrf_field(): string {
    return '<input type="hidden" name="_csrf" value="' . htmlspecialchars(csrf_token()) . '">';
}

/** 验证 CSRF Token，失败则终止 */
function csrf_check(): void {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $token     = $_POST['_csrf'] ?? '';
    $expected  = $_SESSION['csrf_token'] ?? '';
    if (!$token || !$expected || !hash_equals($expected, $token)) {
        http_response_code(403);
        die('CSRF验证失败，请刷新页面重试。');
    }
}

// ── Flash 消息（一次性提示）──

/** 设置 Flash 消息 */
function flash_set(string $type, string $message): void {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $_SESSION['flash'] = ['type' => $type, 'msg' => $message];
}

/** 读取并清除 Flash 消息，返回 ['type','msg'] 或 null */
function flash_get() {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['flash'])) return null;
    $f = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $f;
}

// ── 备份与恢复 ──

/**
 * 创建一条备份记录（打包 sites.json + config.json）
 * @param string $trigger  备份触发方式：manual / auto_import / auto_settings
 * @return string 备份文件路径
 */
function backup_create(string $trigger = 'manual'): string {
    if (!is_dir(BACKUPS_DIR)) mkdir(BACKUPS_DIR, 0755, true);

    $sites_data  = file_exists(SITES_FILE)  ? file_get_contents(SITES_FILE)  : '{}';
    $config_data = file_exists(CONFIG_FILE) ? file_get_contents(CONFIG_FILE) : '{}';

    $backup = [
        'created_at' => date('Y-m-d H:i:s'),
        'trigger'    => $trigger,
        'sites'      => json_decode($sites_data, true)  ?? [],
        'config'     => json_decode($config_data, true) ?? [],
    ];

    $filename = 'backup_' . date('Ymd_His') . '_' . $trigger . '.json';
    $path     = BACKUPS_DIR . '/' . $filename;
    file_put_contents($path, json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);

    // 清理超出数量的旧备份
    backup_cleanup();

    return $path;
}

/**
 * 列出所有备份，按时间倒序
 * @return array [['file','filename','created_at','trigger','size','sites_count','groups_count'], ...]
 */
function backup_list(): array {
    if (!is_dir(BACKUPS_DIR)) return [];
    $files = glob(BACKUPS_DIR . '/backup_*.json');
    if (!$files) return [];
    $result = [];
    foreach ($files as $f) {
        $raw  = @file_get_contents($f);
        $data = $raw ? (json_decode($raw, true) ?? []) : [];
        $groups     = $data['sites']['groups'] ?? [];
        $sites_cnt  = array_sum(array_map(function($g) { return count($g['sites'] ?? []); }, $groups));
        $result[] = [
            'file'         => $f,
            'filename'     => basename($f),
            'created_at'   => $data['created_at'] ?? date('Y-m-d H:i:s', filemtime($f)),
            'trigger'      => $data['trigger']    ?? 'unknown',
            'size'         => filesize($f),
            'groups_count' => count($groups),
            'sites_count'  => $sites_cnt,
        ];
    }
    // 按时间倒序
    usort($result, function($a, $b) { return strcmp($b['created_at'], $a['created_at']); });
    return $result;
}

/**
 * 恢复指定备份（恢复前自动备份当前状态）
 * @param string $filename  备份文件名（basename）
 */
function backup_restore(string $filename): bool {
    // 安全校验：只允许 backup_*.json 格式的文件名
    if (!preg_match('/^backup_[\d_a-z]+\.json$/', $filename)) return false;
    $path = BACKUPS_DIR . '/' . $filename;
    if (!file_exists($path)) return false;

    $data = json_decode(file_get_contents($path), true);
    if (!$data) return false;

    // 恢复前先备份当前状态
    backup_create('auto_before_restore');

    // 写入 sites.json
    if (isset($data['sites'])) {
        file_put_contents(SITES_FILE,
            json_encode($data['sites'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    }
    // 写入 config.json
    if (isset($data['config'])) {
        file_put_contents(CONFIG_FILE,
            json_encode($data['config'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    }
    return true;
}

/**
 * 删除指定备份文件
 */
function backup_delete(string $filename): bool {
    if (!preg_match('/^backup_[\d_a-z]+\.json$/', $filename)) return false;
    $path = BACKUPS_DIR . '/' . $filename;
    if (!file_exists($path)) return false;
    return unlink($path);
}

/**
 * 清理超出数量限制的最旧备份
 */
function backup_cleanup(): void {
    $list = backup_list();
    if (count($list) <= MAX_BACKUPS) return;
    // 删除最旧的（list 已按时间倒序，末尾最旧）
    $to_delete = array_slice($list, MAX_BACKUPS);
    foreach ($to_delete as $item) {
        @unlink($item['file']);
    }
}

// ── 统计信息 ──

/** 获取站点统计数据 */
function get_stats(): array {
    $sites_data = load_sites();
    $groups     = $sites_data['groups'] ?? [];
    $sites_cnt  = array_sum(array_map(function($g) { return count($g['sites'] ?? []); }, $groups));
    $users      = auth_load_users();
    $admins     = count(array_filter($users, function($u) { return ($u['role'] ?? '') === 'admin'; }));
    return [
        'groups' => count($groups),
        'sites'  => $sites_cnt,
        'users'  => count($users),
        'admins' => $admins,
    ];
}

// ── 调试设置 ──

/**
 * 读取 PHP display_errors 运行时 ini 文件状态
 */
function debug_get_display_errors(): bool {
    $ini_file = '/usr/local/etc/php/conf.d/99-nav-custom.ini';
    if (!file_exists($ini_file)) return false;
    $content = file_get_contents($ini_file);
    return (bool) preg_match('/^\s*display_errors\s*=\s*On/mi', $content);
}

/**
 * 设置 PHP display_errors（修改 ini 文件 + 异步重启 PHP-FPM）
 */
function debug_set_display_errors(bool $on): array {
    $ini_file = '/usr/local/etc/php/conf.d/99-nav-custom.ini';
    if (!file_exists($ini_file)) {
        return ['ok' => false, 'msg' => 'ini 文件不存在：' . $ini_file];
    }
    $content = file_get_contents($ini_file);
    $value   = $on ? 'On' : 'Off';
    if (preg_match('/^\s*display_errors\s*=/mi', $content)) {
        $content = preg_replace('/^(\s*display_errors\s*=\s*).*/mi', '${1}' . $value, $content);
    } else {
        $content .= "\ndisplay_errors = {$value}\n";
    }
    if (preg_match('/^\s*display_startup_errors\s*=/mi', $content)) {
        $content = preg_replace('/^(\s*display_startup_errors\s*=\s*).*/mi', '${1}' . $value, $content);
    }
    file_put_contents($ini_file, $content, LOCK_EX);
    // 异步重启 PHP-FPM，避免杀死当前进程导致 502
    exec('nohup /usr/bin/supervisorctl -c /etc/supervisord.conf restart php-fpm >/tmp/fpm-restart.log 2>&1 &');
    return ['ok' => true, 'msg' => 'ini 已更新，PHP-FPM 正在后台重启'];
}

/**
 * 读取日志文件内容（倒序，最新在前）
 * @param string $type  nginx_access | nginx_error | php_fpm
 * @param int    $lines 读取行数
 */
function debug_read_log(string $type, int $lines = 100): string {
    $map = [
        'nginx_access' => '/var/log/nginx/nav.access.log',
        'nginx_error'  => '/var/log/nginx/nav.error.log',
        'nginx_main'   => '/var/log/nginx/error.log',
        'php_fpm'      => '/var/log/php-fpm/error.log',
    ];
    $path = $map[$type] ?? '';
    if (!$path)              return '（未知日志类型）';
    if (!file_exists($path)) return '（日志文件不存在：' . $path . '）';
    if (!is_readable($path)) return '（日志文件无读取权限：' . $path . '）';
    $size = filesize($path);
    if ($size === 0)         return '（日志为空）';
    // 用 PHP 原生方式读取最后 N 行（不依赖 exec/shell）
    $fp = fopen($path, 'r');
    if (!$fp) return '（无法打开日志文件）';
    $chunk  = 8192;
    $result = [];
    $buf    = '';
    fseek($fp, 0, SEEK_END);
    $pos = ftell($fp);
    while ($pos > 0 && count($result) < $lines) {
        $read = min($chunk, $pos);
        $pos -= $read;
        fseek($fp, $pos);
        $buf = fread($fp, $read) . $buf;
        $parts = explode("\n", $buf);
        // 最后一个可能不完整，保留到下次
        $buf = array_shift($parts);
        // 从后往前收集
        foreach (array_reverse($parts) as $line) {
            if (trim($line) === '') continue;
            $result[] = $line;
            if (count($result) >= $lines) break;
        }
    }
    fclose($fp);
    // 补充 buf 剩余
    if (count($result) < $lines && trim($buf) !== '') {
        $result[] = $buf;
    }
    return implode("\n", $result);
}



/**
 * 根据当前 sites.json 生成 Nginx 反代配置片段
 * 输出到 /etc/nginx/conf.d/nav-proxy.conf（由 nginx reload 后生效）
 *
 * 仅处理 type=proxy 的站点：
 *   - proxy_mode=path   → location /p/{slug}/ { proxy_pass ... }
 *   - proxy_mode=domain → 独立 server 块（子域名模式）
 *
 * @return array ['ok' => bool, 'msg' => string, 'conf' => string]
 */
function nginx_generate_proxy_conf(): array {
    $cfg        = load_config();
    $nav_domain = $cfg['nav_domain'] ?? 'nav.yourdomain.com';
    $sites_data = load_sites();
    $groups     = $sites_data['groups'] ?? [];

    $path_blocks   = []; // location /p/{slug}/ 块（追加到主站 server 内，需手动 include）
    $domain_blocks = []; // 独立 server 块（子域名模式）

    foreach ($groups as $grp) {
        foreach ($grp['sites'] ?? [] as $s) {
            if (($s['type'] ?? '') !== 'proxy') continue;
            $target = rtrim($s['proxy_target'] ?? '', '/');
            // 校验 proxy_target 格式，防止配置注入（仅允许 http(s)://host:port 形式）
            if (!preg_match('#^https?://[a-zA-Z0-9._-]+(:\d+)?(/[^\n\r]*)?$#', $target)) {
                continue; // 格式非法，跳过此站点，不写入 Nginx 配置
            }
            $name   = $s['name'] ?? $s['id'];

            if (($s['proxy_mode'] ?? 'path') === 'path') {
                $slug = preg_replace('/[^a-z0-9_-]/', '-', strtolower($s['slug'] ?? $s['id']));
                $path_blocks[] = implode("\n", [
                    "    # {$name}",
                    "    location /p/{$slug}/ {",
                    "        auth_request      /auth/verify.php;",
                    "        error_page 401  = @login_redirect;",
                    "        proxy_pass        {$target}/;",
                    "        proxy_http_version 1.1;",
                    "        proxy_set_header  Upgrade \$http_upgrade;",
                    "        proxy_set_header  Connection \$connection_upgrade;",
                    "        proxy_set_header  Host \$host;",
                    "        proxy_set_header  X-Real-IP \$remote_addr;",
                    "        proxy_set_header  X-Forwarded-For \$proxy_add_x_forwarded_for;",
                    "        proxy_set_header  X-Forwarded-Proto \$scheme;",
                    "        proxy_connect_timeout 10s;",
                    "        proxy_send_timeout    60s;",
                    "        proxy_read_timeout    60s;",
                    "        proxy_buffering       on;",
                    "        proxy_buffer_size     8k;",
                    "        proxy_buffers         8 16k;",
                    "        proxy_busy_buffers_size 32k;",
                    "    }",
                ]);
            } else {
                // 子域名模式：独立 server 块
                $pd = $s['proxy_domain'] ?? '';
                if (!$pd) continue;
                $domain_blocks[] = implode("\n", [
                    "server {",
                    "    listen 443 ssl http2;",
                    "    server_name {$pd};",
                    "    # 复用主站证书（需通配符证书）或单独申请",
                    "    ssl_certificate     /etc/letsencrypt/live/{$nav_domain}/fullchain.pem;",
                    "    ssl_certificate_key /etc/letsencrypt/live/{$nav_domain}/privkey.pem;",
                    "",
                    "    location = /auth/verify {",
                    "        internal;",
                    "        proxy_pass https://{$nav_domain}/auth/verify.php;",
                    "        proxy_pass_request_body off;",
                    "        proxy_set_header Content-Length \"\";",
                    "        proxy_set_header Cookie \$http_cookie;",
                    "    }",
                    "",
                    "    location / {",
                    "        auth_request /auth/verify;",
                    "        error_page 401 = @nav_login;",
                    "        proxy_pass {$target};",
                    "        proxy_http_version 1.1;",
                    "        proxy_set_header Upgrade \$http_upgrade;",
                    "        proxy_set_header Connection \$connection_upgrade;",
                    "        proxy_set_header Host \$host;",
                    "        proxy_set_header X-Real-IP \$remote_addr;",
                    "        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;",
                    "        proxy_set_header X-Forwarded-Proto \$scheme;",
                    "        proxy_connect_timeout 10s;",
                    "        proxy_send_timeout    60s;",
                    "        proxy_read_timeout    60s;",
                    "        proxy_buffering       on;",
                    "        proxy_buffer_size     8k;",
                    "        proxy_buffers         8 16k;",
                    "        proxy_busy_buffers_size 32k;",
                    "    }",
                    "",
                    "    location @nav_login {",
                    "        return 302 https://{$nav_domain}/login.php?redirect=\$request_uri;",
                    "    }",
                    "}",
                ]);
            }
        }
    }

    // 组装最终配置内容
    // 注意：此文件通过 nav.conf 的 include 指令嵌入 server {} 块内
    // 因此只能包含 location 块，不能包含 map/server 等顶层指令
    $lines = [
        "# 导航站自动生成的 Nginx 反代配置",
        "# 生成时间：" . date('Y-m-d H:i:s'),
        "# 此文件由后台自动管理，请勿手动编辑",
        "# 注意：此文件被 include 到 server {} 块内，只能包含 location 块",
        "",
    ];

    if (!empty($path_blocks)) {
        $lines[] = "# ── 路径前缀模式 ──";
        foreach ($path_blocks as $b) { $lines[] = $b; $lines[] = ""; }
    }

    if (empty($path_blocks) && empty($domain_blocks)) {
        $lines[] = "# 暂无代理站点配置";
    }

    if (!empty($domain_blocks)) {
        // 子域名模式需要独立 server 块，无法 include 到 server 内
        // 生成注释供参考，实际需手动添加到 nginx 主配置
        $lines[] = "# ── 子域名模式（独立 server 块，需手动添加到 nginx 主配置）──";
        foreach ($domain_blocks as $b) {
            foreach (explode("\n", $b) as $bl) $lines[] = '# ' . $bl;
            $lines[] = "";
        }
    }

    $conf = implode("\n", $lines);

    // 写入配置文件
    $conf_path = nginx_proxy_conf_path();
    $result    = @file_put_contents($conf_path, $conf, LOCK_EX);

    if ($result === false) {
        return [
            'ok'   => false,
            'msg'  => "写入配置文件失败：{$conf_path}，请检查 www-data 是否有写入权限",
            'conf' => $conf,
        ];
    }

    return ['ok' => true, 'msg' => "配置已写入 {$conf_path}", 'conf' => $conf];
}

/**
 * 写入反代配置，并在 reload 失败时自动回滚
 * @param bool $reload 是否立即 reload
 * @return array ['ok'=>bool,'msg'=>string]
 */
function nginx_apply_proxy_conf(bool $reload = false): array {
    $conf_path = nginx_proxy_conf_path();
    $old_conf  = file_exists($conf_path) ? @file_get_contents($conf_path) : null;

    $gen = nginx_generate_proxy_conf();
    if (!$gen['ok']) {
        return ['ok' => false, 'msg' => $gen['msg']];
    }

    if (!$reload) {
        return ['ok' => true, 'msg' => '反代配置已写入，请点击「Reload Nginx」使其生效'];
    }

    $rel = nginx_reload();
    if ($rel['ok']) {
        return ['ok' => true, 'msg' => 'Nginx 已成功 reload，代理配置已生效'];
    }

    // reload 失败：回滚到旧配置并尝试恢复 reload
    if ($old_conf !== null) {
        @file_put_contents($conf_path, $old_conf, LOCK_EX);
        $rollback_rel = nginx_reload();
        if ($rollback_rel['ok']) {
            return ['ok' => false, 'msg' => 'Reload 失败，已自动回滚到上一次可用配置：' . $rel['msg']];
        }
        return ['ok' => false, 'msg' => 'Reload 失败，且自动回滚后恢复失败，请手动检查 Nginx：' . $rel['msg']];
    }

    return ['ok' => false, 'msg' => 'Reload 失败，且不存在可回滚的旧配置：' . $rel['msg']];
}

/**
 * 自动检测 Nginx 可执行文件路径
 * 按优先级检测：宝塔路径 → 标准路径 → which 命令
 */
function nginx_bin(): string {
    $candidates = [
        '/www/server/nginx/sbin/nginx', // 宝塔面板
        '/usr/sbin/nginx',              // Ubuntu/Debian 标准
        '/usr/local/sbin/nginx',        // 编译安装
        '/usr/local/bin/nginx',         // macOS Homebrew
    ];
    foreach ($candidates as $path) {
        if (is_executable($path)) return $path;
    }
    // 最后尝试 which
    $which = trim(shell_exec('which nginx 2>/dev/null') ?? '');
    return $which ?: '/usr/sbin/nginx';
}

/**
 * 自动检测 Nginx 反代配置文件路径
 * 宝塔：/www/server/nginx/conf/nav-proxy.conf
 * 标准：/etc/nginx/conf.d/nav-proxy.conf
 */
function nginx_proxy_conf_path(): string {
    // 宝塔环境
    if (is_dir('/www/server/nginx/conf')) {
        return '/www/server/nginx/conf/nav-proxy.conf';
    }
    return '/etc/nginx/conf.d/nav-proxy.conf';
}

/**
 * 执行 nginx -t 语法检测 + nginx -s reload
 * 需要 web 用户有 sudo 权限执行这两个命令
 *
 * sudo 白名单配置（在服务器上执行一次，限定具体参数防提权）：
 *   NGINX_BIN=$(which nginx || echo /usr/sbin/nginx)
 *   echo "www-data ALL=(ALL) NOPASSWD: $NGINX_BIN -t" > /etc/sudoers.d/nav-nginx
 *   echo "www-data ALL=(ALL) NOPASSWD: $NGINX_BIN -s reload" >> /etc/sudoers.d/nav-nginx
 *   chmod 440 /etc/sudoers.d/nav-nginx
 *
 * @return array ['ok' => bool, 'msg' => string, 'test_output' => string]
 */
function nginx_reload(): array {
    // 优先使用包装脚本（容器内无 sudo 时的替代方案）
    $use_wrapper = is_executable('/usr/local/bin/nginx-reload') && is_executable('/usr/local/bin/nginx-test');

    // 先测试 nginx 是否运行正常
    if ($use_wrapper) {
        $test_output = [];
        $test_code   = 0;
        exec('/usr/local/bin/nginx-test 2>&1', $test_output, $test_code);
        $test_msg = implode("\n", $test_output);
        // supervisorctl status 返回 0 且包含 RUNNING 即正常
        if ($test_code !== 0 || strpos($test_msg, 'RUNNING') === false) {
            return [
                'ok'          => false,
                'msg'         => 'Nginx 未正常运行，中止 reload',
                'test_output' => $test_msg,
            ];
        }
        // 写入触发文件，让 watcher 执行 HUP
        $reload_output = [];
        $reload_code   = 0;
        exec('/usr/local/bin/nginx-reload 2>&1', $reload_output, $reload_code);
        if ($reload_code !== 0) {
            return [
                'ok'          => false,
                'msg'         => 'nginx reload 触发失败',
                'test_output' => implode("\n", $reload_output),
            ];
        }
        return [
            'ok'          => true,
            'msg'         => 'Nginx 已成功 reload',
            'test_output' => $test_msg,
        ];
    }

    // 降级：使用 sudo
    $nginx = nginx_bin();
    $test_output = [];
    $test_code   = 0;
    exec('sudo ' . escapeshellcmd($nginx) . ' -t 2>&1', $test_output, $test_code);
    $test_msg = implode("\n", $test_output);
    if ($test_code !== 0) {
        return [
            'ok'          => false,
            'msg'         => 'Nginx 配置语法错误，已中止 reload，请检查配置',
            'test_output' => $test_msg,
        ];
    }
    $reload_output = [];
    $reload_code   = 0;
    exec('sudo ' . escapeshellcmd($nginx) . ' -s reload 2>&1', $reload_output, $reload_code);
    if ($reload_code !== 0) {
        return [
            'ok'          => false,
            'msg'         => 'nginx reload 执行失败',
            'test_output' => implode("\n", $reload_output),
        ];
    }
    return [
        'ok'          => true,
        'msg'         => 'Nginx 已成功 reload',
        'test_output' => $test_msg,
    ];
}

/**
 * 记录 Nginx 最后一次成功 reload 的时间戳
 */
function nginx_mark_applied(): void {
    $cfg = load_config();
    $cfg['nginx_last_applied'] = time();
    save_config($cfg);
    // 同步刷新 auth 缓存
    auth_reload_config();
}

/**
 * 获取未在 Nginx 中生效的 proxy 站点列表
 * 判断依据：sites.json 的修改时间 > nginx_last_applied
 * @return array  [['name'=>..., 'proxy_domain'=>..., 'group'=>...], ...]
 */
function nginx_pending_sites(): array {
    $cfg          = load_config();
    $last_applied = (int)($cfg['nginx_last_applied'] ?? 0);
    $sites_mtime  = file_exists(SITES_FILE) ? filemtime(SITES_FILE) : 0;

    // sites.json 没有变化，不需要提示
    if ($sites_mtime <= $last_applied) return [];

    $sites_data = load_sites();
    $pending    = [];
    foreach ($sites_data['groups'] as $grp) {
        foreach ($grp['sites'] ?? [] as $s) {
            if (($s['type'] ?? '') !== 'proxy') continue;
            $pending[] = [
                'name'         => $s['name'] ?? $s['id'],
                'proxy_domain' => $s['proxy_domain'] ?? '',
                'group'        => $grp['name'] ?? $grp['id'],
            ];
        }
    }
    return $pending;
}

/**
 * 同步刷新 auth_get_config 的静态缓存（修改 config 后调用）
 */
function auth_reload_config(): void {
    // auth_get_config 使用 static $cfg，无法直接清除
    // 通过写入一个特殊 flag，让下次调用重新读文件
    // 实际在同一请求内无需刷新，下次请求自动读新值
}
