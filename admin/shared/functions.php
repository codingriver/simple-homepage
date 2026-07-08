<?php
/**
 * 后台公共函数库 admin/shared/functions.php
 * 包含：配置读写、CSRF、Flash消息、备份/恢复、统计
 */
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../shared/http_client.php';

// 数据文件路径常量
define('BACKUPS_DIR',  DATA_DIR . '/backups');
define('MAX_BACKUPS',  20); // 最多保留备份数
define('AUDIT_LOG_FILE', DATA_DIR . '/logs/audit.log');

// ── 系统配置 ──

/** 读取系统配置（含默认值，确保所有字段存在，与 auth_get_config() 保持一致）*/
function load_config(): array {
    $raw = file_exists(CONFIG_FILE)
        ? (json_decode(file_get_contents(CONFIG_FILE), true) ?? [])
        : [];
    return $raw + auth_default_config();
}

/** 写入系统配置 */
function save_config(array $cfg): void {
    file_put_contents(CONFIG_FILE,
        json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
        LOCK_EX);
}

// ── 文件系统白名单 ──

function fs_allowed_roots(): array {
    $cfg = load_config();
    $roots = $cfg['fs_allowed_roots'] ?? [];
    if (!is_array($roots)) {
        $roots = [];
    }
    $roots = array_values(array_filter(array_map('strval', $roots)));
    if (empty($roots)) {
        return [];
    }
    return $roots;
}

function fs_path_in_allowed_roots(string $path, ?array $roots = null): bool {
    $roots = $roots ?? fs_allowed_roots();
    if (empty($roots)) {
        return true;
    }
    $normalized = rtrim($path, '/');
    if ($normalized === '') {
        $normalized = '/';
    }
    foreach ($roots as $root) {
        $root = rtrim($root, '/');
        if ($root === '') {
            continue;
        }
        if ($normalized === $root) {
            return true;
        }
        if (strpos($normalized . '/', $root . '/') === 0) {
            return true;
        }
    }
    return false;
}

// ── CSRF 保护 ──

if (!function_exists('csrf_token')) {
    /** 获取或生成 CSRF Token（存储在 Session 中）*/
    function csrf_token(): string {
        auth_start_php_session();
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
        auth_start_php_session();
        $token     = $_POST['_csrf'] ?? '';
        $expected  = $_SESSION['csrf_token'] ?? '';
        if (!$token || !$expected || !hash_equals($expected, $token)) {
            http_response_code(403);
            die('CSRF验证失败，请刷新页面重试。');
        }
    }
}

// ── Flash 消息（一次性提示）──

/** 设置 Flash 消息 */
function flash_set(string $type, string $message): void {
    auth_start_php_session();
    $_SESSION['flash'] = ['type' => $type, 'msg' => $message];
}

/** 读取并清除 Flash 消息，返回 ['type','msg'] 或 null */
function flash_get() {
    auth_start_php_session();
    if (empty($_SESSION['flash'])) return null;
    $f = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $f;
}

/**
 * 受控执行系统命令（统一出口）
 * @return array{ok:bool,code:int,output:string}
 */
function admin_run_command(string $command, int $timeoutSeconds = 60): array {
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = proc_open($command, $descriptors, $pipes);
    if (!is_resource($proc)) {
        return ['ok' => false, 'code' => 1, 'output' => '无法启动进程'];
    }
    foreach ($pipes as $pipe) {
        stream_set_blocking($pipe, false);
    }
    fclose($pipes[0]);
    $stdout = '';
    $stderr = '';
    $startAt = microtime(true);
    $sigtermAt = null;
    $killAfter = 5;
    $capturedExitCode = null;
    while (true) {
        $status = proc_get_status($proc);
        if (!($status['running'] ?? false)) {
            $capturedExitCode = (int)($status['exitcode'] ?? 0);
            break;
        }
        $elapsed = microtime(true) - $startAt;
        if ($timeoutSeconds > 0 && $elapsed >= $timeoutSeconds) {
            if ($sigtermAt === null) {
                $pid = (int)($status['pid'] ?? 0);
                if ($pid > 0 && function_exists('posix_kill')) {
                    @posix_kill($pid, SIGTERM);
                }
                $sigtermAt = microtime(true);
            } elseif ((microtime(true) - $sigtermAt) >= $killAfter) {
                $pid = (int)($status['pid'] ?? 0);
                if ($pid > 0 && function_exists('posix_kill')) {
                    @posix_kill($pid, SIGKILL);
                }
                for ($i = 0; $i < 20; $i++) {
                    $status = proc_get_status($proc);
                    if (!($status['running'] ?? false)) {
                        $capturedExitCode = (int)($status['exitcode'] ?? 0);
                        break 2;
                    }
                    usleep(100000);
                }
                break;
            }
        }
        $read = [$pipes[1], $pipes[2]];
        $write = null;
        $except = null;
        $tv_sec = 0;
        $tv_usec = 100000;
        if (stream_select($read, $write, $except, $tv_sec, $tv_usec) > 0) {
            foreach ($read as $stream) {
                $data = fread($stream, 4096);
                if ($data !== false && $data !== '') {
                    if ($stream === $pipes[1]) {
                        $stdout .= $data;
                    } else {
                        $stderr .= $data;
                    }
                }
            }
        }
    }
    $remainingStdout = stream_get_contents($pipes[1]);
    $remainingStderr = stream_get_contents($pipes[2]);
    if ($remainingStdout !== false) {
        $stdout .= $remainingStdout;
    }
    if ($remainingStderr !== false) {
        $stderr .= $remainingStderr;
    }
    fclose($pipes[1]);
    fclose($pipes[2]);
    if ($capturedExitCode !== null) {
        $exitCode = $capturedExitCode;
    } else {
        $exitCode = 0;
        $status = proc_get_status($proc);
        if ($status['running'] ?? false) {
            $exitCode = 1;
        } else {
            $exitCode = (int)($status['exitcode'] ?? 0);
        }
    }
    proc_close($proc);
    $output = trim($stdout . ($stderr ? "\n" . $stderr : ''));
    $timedOut = ($timeoutSeconds > 0 && (microtime(true) - $startAt) >= $timeoutSeconds);
    if ($timedOut) {
        $output .= "\n[TIMEOUT] 命令执行超过 {$timeoutSeconds} 秒，已强制终止。";
        $exitCode = 124;
    }
    return [
        'ok' => $exitCode === 0 && !$timedOut,
        'code' => $exitCode,
        'output' => $output,
    ];
}

// ── 备份与恢复 ──

/**
 * 组装与「备份下载 / 导出配置」一致的 JSON 载荷（不写文件）。
 * 包含：config、scheduled_tasks（含各任务的 command 脚本）、dns_config（域名解析账户）、ddns_tasks、domain_expiry。
 *
 * @param string $trigger 触发标识：manual / export / auto_import 等
 * @return array<string, mixed>
 */
function backup_collect_payload(string $trigger = 'manual'): array {
    $config_data = file_exists(CONFIG_FILE) ? file_get_contents(CONFIG_FILE) : '{}';

    $st_file   = DATA_DIR . '/scheduled_tasks.json';
    $dns_file  = DATA_DIR . '/dns_config.json';
    $ddns_file = DATA_DIR . '/ddns_tasks.json';
    $domain_expiry_file = DATA_DIR . '/domain_expiry.json';
    $scheduled_tasks = file_exists($st_file) ? (json_decode(file_get_contents($st_file), true) ?? []) : [];
    if (is_array($scheduled_tasks)) {
        require_once __DIR__ . '/cron_lib.php';
        foreach ($scheduled_tasks['tasks'] ?? [] as $idx => $task) {
            if (!is_array($task)) {
                continue;
            }
            $scheduled_tasks['tasks'][$idx]['command'] = task_resolve_command_text($task);
        }
    }

    return [
        'created_at'      => date('Y-m-d H:i:s'),
        'trigger'         => $trigger,
        'config'          => json_decode($config_data, true) ?? [],
        'scheduled_tasks' => $scheduled_tasks,
        'dns_config'      => file_exists($dns_file) ? (json_decode(file_get_contents($dns_file), true) ?? []) : [],
        'ddns_tasks'      => file_exists($ddns_file) ? (json_decode(file_get_contents($ddns_file), true) ?? []) : [],
        'domain_expiry'   => file_exists($domain_expiry_file) ? (json_decode(file_get_contents($domain_expiry_file), true) ?? []) : [],
    ];
}

/**
 * 将备份/导入 JSON 中的各段写入数据文件。仅处理传入的键；未提供的键不覆盖现有文件。
 * 写入计划任务或 DNS 配置后会刷新 crontab / 清除 DNS Zone 缓存。
 *
 * @param array<string, mixed> $data
 */
function backup_apply_restored_sections(array $data): void {
    $wrote_st   = false;
    $wrote_dns  = false;
    $wrote_ddns = false;

    if (isset($data['config']) && is_array($data['config'])) {
        file_put_contents(CONFIG_FILE,
            json_encode($data['config'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            LOCK_EX);
    }

    $st_file   = DATA_DIR . '/scheduled_tasks.json';
    $dns_file  = DATA_DIR . '/dns_config.json';
    $ddns_file = DATA_DIR . '/ddns_tasks.json';
    $domain_expiry_file = DATA_DIR . '/domain_expiry.json';
    if (isset($data['scheduled_tasks']) && is_array($data['scheduled_tasks'])) {
        file_put_contents($st_file,
            json_encode($data['scheduled_tasks'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            LOCK_EX);
        $wrote_st = true;
        require_once __DIR__ . '/cron_lib.php';
        task_sync_scripts_from_scheduled_tasks($data['scheduled_tasks']);
    }
    if (isset($data['dns_config']) && is_array($data['dns_config'])) {
        file_put_contents($dns_file,
            json_encode($data['dns_config'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            LOCK_EX);
        $wrote_dns = true;
    }
    if (isset($data['ddns_tasks']) && is_array($data['ddns_tasks'])) {
        file_put_contents($ddns_file,
            json_encode($data['ddns_tasks'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            LOCK_EX);
        $wrote_ddns = true;
    }
    if (isset($data['domain_expiry']) && is_array($data['domain_expiry'])) {
        file_put_contents($domain_expiry_file,
            json_encode($data['domain_expiry'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            LOCK_EX);
    }
    if ($wrote_st) {
        require_once __DIR__ . '/cron_lib.php';
        cron_regenerate();
    }
    if ($wrote_dns) {
        require_once __DIR__ . '/dns_api_lib.php';
        dns_api_invalidate_zones_cache();
    }
    if ($wrote_ddns && !file_exists($ddns_file)) {
        file_put_contents($ddns_file, json_encode(['version' => 1, 'tasks' => []], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }

}

/**
 * 创建一条备份记录（与导出配置使用同一载荷结构）
 * @param string $trigger  备份触发方式：manual / auto_import / auto_settings
 * @return string 备份文件路径
 */
function backup_create(string $trigger = 'manual'): string {
    if (!is_dir(BACKUPS_DIR)) {
        mkdir(BACKUPS_DIR, 0755, true);
    }

    $backup = backup_collect_payload($trigger);

    $filename = 'backup_' . date('Ymd_His') . '_' . $trigger . '.json';
    $path     = BACKUPS_DIR . '/' . $filename;
    $written = file_put_contents($path, json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    if ($written === false) {
        if (function_exists('notify_event')) {
            notify_event('backup_failed', [
                'trigger' => $trigger,
                'path' => $path,
                'message' => '备份文件写入失败',
            ]);
        }
        return '';
    }

    backup_cleanup();
    if (function_exists('notify_event')) {
        notify_event('backup_succeeded', [
            'trigger' => $trigger,
            'path' => $path,
            'filename' => basename($path),
        ]);
    }

    return $path;
}

/**
 * 列出所有备份，按时间倒序
 * @return array [['file','filename','created_at','trigger','size'], ...]
 */
function backup_list(): array {
    if (!is_dir(BACKUPS_DIR)) return [];
    $files = glob(BACKUPS_DIR . '/backup_*.json');
    if (!$files) return [];
    $result = [];
    foreach ($files as $f) {
        $raw  = @file_get_contents($f);
        $data = $raw ? (json_decode($raw, true) ?? []) : [];
        $result[] = [
            'file'         => $f,
            'filename'     => basename($f),
            'created_at'   => $data['created_at'] ?? date('Y-m-d H:i:s', filemtime($f)),
            'trigger'      => $data['trigger']    ?? 'unknown',
            'size'         => filesize($f),
        ];
    }
    // 按时间倒序
    usort($result, function($a, $b) { return strcmp($b['created_at'], $a['created_at']); });
    return $result;
}

/** 备份文件数量（不解析 JSON，供控制台等仅需数量的场景） */
function backup_count(): int {
    if (!is_dir(BACKUPS_DIR)) {
        return 0;
    }
    $files = glob(BACKUPS_DIR . '/backup_*.json');
    return $files ? count($files) : 0;
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

    backup_apply_restored_sections($data);
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

// ── 回收站 ──

define('TRASH_DIR', DATA_DIR . '/trash');
define('TRASH_RETENTION_DAYS', 30);

function trash_ensure_dir(): void {
    if (!is_dir(TRASH_DIR)) {
        @mkdir(TRASH_DIR, 0750, true);
    }
}

function trash_generate_entry_id(): string {
    return 'trash_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4));
}

function trash_meta_path(string $entryId): string {
    return TRASH_DIR . '/' . $entryId . '/meta.json';
}

function trash_data_path(string $entryId): string {
    return TRASH_DIR . '/' . $entryId . '/data';
}

function trash_move(string $hostId, string $path, string $operator = ''): array {
    trash_ensure_dir();
    $entryId = trash_generate_entry_id();
    $entryDir = TRASH_DIR . '/' . $entryId;
    $dataDir = $entryDir . '/data';
    $metaPath = $entryDir . '/meta.json';

    if (!@mkdir($entryDir, 0750, true)) {
        return ['ok' => false, 'msg' => '无法创建回收站目录'];
    }

    $meta = [
        'entry_id' => $entryId,
        'host_id' => $hostId,
        'original_path' => $path,
        'deleted_at' => date('Y-m-d H:i:s'),
        'operator' => $operator,
    ];
    file_put_contents($metaPath, json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);

    if (!file_exists($path)) {
        @unlink($metaPath);
        @rmdir($dataDir);
        @rmdir($entryDir);
        return ['ok' => false, 'msg' => '源文件或目录不存在'];
    }
    $parent = dirname($dataDir);
    if (!is_dir($parent)) {
        @mkdir($parent, 0755, true);
    }
    $ok = @rename($path, $dataDir);
    if (!$ok) {
        @unlink($metaPath);
        @rmdir($dataDir);
        @rmdir($entryDir);
        return ['ok' => false, 'msg' => '移动到回收站失败'];
    }

    return ['ok' => true, 'msg' => '已移至回收站', 'entry_id' => $entryId];
}

function trash_list(int $limit = 200): array {
    trash_ensure_dir();
    $items = [];
    foreach (scandir(TRASH_DIR) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $metaPath = TRASH_DIR . '/' . $entry . '/meta.json';
        if (!is_file($metaPath)) continue;
        $meta = json_decode(file_get_contents($metaPath), true);
        if (!is_array($meta)) continue;
        $dataDir = TRASH_DIR . '/' . $entry . '/data';
        $meta['entry_id'] = $entry;
        $meta['exists'] = file_exists($dataDir);
        $meta['size'] = is_dir($dataDir) ? 0 : (is_file($dataDir) ? filesize($dataDir) : 0);
        $items[] = $meta;
    }
    usort($items, static function(array $a, array $b): int {
        return strcmp((string)($b['deleted_at'] ?? ''), (string)($a['deleted_at'] ?? ''));
    });
    return array_slice($items, 0, $limit);
}

function trash_restore(string $entryId): array {
    $metaPath = trash_meta_path($entryId);
    if (!is_file($metaPath)) {
        return ['ok' => false, 'msg' => '回收站条目不存在'];
    }
    $meta = json_decode(file_get_contents($metaPath), true);
    if (!is_array($meta)) {
        return ['ok' => false, 'msg' => '回收站元数据损坏'];
    }
    $originalPath = (string)($meta['original_path'] ?? '');
    if ($originalPath === '') {
        return ['ok' => false, 'msg' => '原始路径记录缺失'];
    }
    $dataDir = trash_data_path($entryId);
    if (!file_exists($dataDir)) {
        return ['ok' => false, 'msg' => '回收站数据已丢失'];
    }

    $parent = dirname($originalPath);
    if (!is_dir($parent)) {
        @mkdir($parent, 0755, true);
    }
    $ok = @rename($dataDir, $originalPath);
    if (!$ok) {
        return ['ok' => false, 'msg' => '恢复失败'];
    }

    // 清理回收站目录
    @unlink($metaPath);
    @rmdir(dirname($metaPath));
    $entryDir = dirname($metaPath);
    @rmdir($entryDir);

    return ['ok' => true, 'msg' => '已恢复到 ' . $originalPath];
}

function trash_recursive_rmdir(string $dir): bool {
    if (!is_dir($dir)) return false;
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        if ($item->isDir()) {
            rmdir($item->getRealPath());
        } else {
            unlink($item->getRealPath());
        }
    }
    return rmdir($dir);
}

function trash_permanent_delete(string $entryId): array {
    $metaPath = trash_meta_path($entryId);
    $dataDir = trash_data_path($entryId);
    $entryDir = dirname($metaPath);

    if (is_dir($dataDir)) {
        if (!trash_recursive_rmdir($dataDir)) {
            return ['ok' => false, 'msg' => '永久删除失败'];
        }
    }

    @unlink($metaPath);
    @rmdir($entryDir);

    return ['ok' => true, 'msg' => '已永久删除'];
}

function trash_auto_clean(): void {
    trash_ensure_dir();
    $cutoff = strtotime('-' . TRASH_RETENTION_DAYS . ' days');
    foreach (scandir(TRASH_DIR) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $metaPath = TRASH_DIR . '/' . $entry . '/meta.json';
        if (!is_file($metaPath)) continue;
        $meta = json_decode(file_get_contents($metaPath), true);
        if (!is_array($meta)) continue;
        $deletedAt = strtotime((string)($meta['deleted_at'] ?? ''));
        if ($deletedAt !== false && $deletedAt < $cutoff) {
            trash_permanent_delete($entry);
        }
    }
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

/** 获取后台统计数据 */
function get_stats(): array {
    $users  = auth_load_users();
    $admins = count(array_filter($users, function($u) { return ($u['role'] ?? '') === 'admin'; }));
    return [
        'users'  => count($users),
        'admins' => $admins,
    ];
}

// ── 调试设置 ──

/**
 * 读取 Docker 构建时写入的元数据（项目根 /.build-info.json），未注入则为 null
 *
 * @return array{git_commit:string,git_ref:string,build_date:string,source:string}|null
 */
function nav_read_build_info(): ?array {
    $path = dirname(__DIR__, 2) . '/.build-info.json';
    if (!is_readable($path)) {
        return null;
    }
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') {
        return null;
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return null;
    }
    return [
        'git_commit' => (string)($data['git_commit'] ?? ''),
        'git_ref'    => (string)($data['git_ref'] ?? ''),
        'build_date' => (string)($data['build_date'] ?? ''),
        'source'     => (string)($data['source'] ?? ''),
    ];
}

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
 * 设置 PHP display_errors（持久化到 config.json，由入口文件运行时应用）
 */
function debug_set_display_errors(bool $on): array {
    $cfg = load_config();
    $cfg['display_errors'] = $on ? '1' : '0';
    save_config($cfg);
    return ['ok' => true, 'msg' => '设置已保存，下次请求生效'];
}

/**
 * 读取日志文件内容（倒序，最新在前）
 * @param string $type  nginx_access | nginx_error | nginx_main | php_fpm | request_timing | dns | dns_python
 * @param int    $lines 读取行数
 */
function debug_read_log(string $type, int $lines = 100): string {
    $map = [
        'nginx_access'   => '/var/log/nginx/nav.access.log',
        'nginx_error'    => '/var/log/nginx/nav.error.log',
        'nginx_main'     => '/var/log/nginx/error.log',
        'php_fpm'        => '/var/log/php-fpm/error.log',
        'request_timing' => DATA_DIR . '/logs/request_timing.log',
        'dns'            => DATA_DIR . '/logs/dns.log',
        'dns_python'     => DATA_DIR . '/logs/dns_python.log',

        'auth'           => DATA_DIR . '/logs/auth.log',
        'audit'          => DATA_DIR . '/logs/audit.log',
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

// ══════════════════════════════════════════════════════════════
// ── 站点健康检测
// ══════════════════════════════════════════════════════════════

define('API_TOKENS_FILE', DATA_DIR . '/api_tokens.json');
function api_tokens_load(): array {
    if (!file_exists(API_TOKENS_FILE)) return [];
    return json_decode(file_get_contents(API_TOKENS_FILE), true) ?? [];
}

function api_tokens_save(array $tokens): void {
    $dir = dirname(API_TOKENS_FILE);
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    file_put_contents(API_TOKENS_FILE, json_encode($tokens, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
}

function api_token_generate(string $name): string {
    $token = 'np_' . bin2hex(random_bytes(32));
    $tokens = api_tokens_load();
    $tokens[$token] = [
        'name' => $name,
        'created_at' => date('Y-m-d H:i:s'),
    ];
    api_tokens_save($tokens);
    return $token;
}

function api_token_verify(string $token): bool {
    if ($token === '') return false;
    $tokens = api_tokens_load();
    return isset($tokens[$token]);
}

function api_token_mask(string $token): string {
    if (strlen($token) <= 12) return $token;
    return substr($token, 0, 8) . '...' . substr($token, -4);
}

function api_token_get_name(string $token): string {
    $tokens = api_tokens_load();
    return $tokens[$token]['name'] ?? '';
}
function webhook_http_post_json(string $url, string $payload, int $timeout = 5): array {
    return http_post_json($url, $payload, $timeout);
}

function webhook_send(string $event, string $username, string $ip, string $note = ''): void {
    $cfg = load_config();
    if (($cfg['webhook_enabled'] ?? '0') !== '1') return;

    // 检查事件是否在订阅列表内
    $events = array_filter(array_map('trim', explode(',', $cfg['webhook_events'] ?? 'FAIL,IP_LOCKED')));
    if (!in_array($event, $events, true)) return;

    $url  = trim($cfg['webhook_url'] ?? '');
    $type = $cfg['webhook_type'] ?? 'custom';
    if (!$url) return;

    $site_name = $cfg['site_name'] ?? '后台中心';
    $time_str  = date('Y-m-d H:i:s');
    $emoji_map = [
        'SUCCESS'      => '✅',
        'FAIL'         => '❌',
        'IP_LOCKED'    => '🔒',
        'LOGOUT'       => '🚪',
        'SETUP'        => '🎉',
    ];
    $emoji = $emoji_map[$event] ?? '📢';
    $text  = "{$emoji} [{$site_name}] 登录事件\n"
           . "事件：{$event}\n"
           . "用户：{$username}\n"
           . "IP：{$ip}\n"
           . "时间：{$time_str}"
           . ($note ? "\n备注：{$note}" : '');

    // 根据类型构造 payload
    switch ($type) {
        case 'telegram':
            $chat_id = trim($cfg['webhook_tg_chat'] ?? '');
            if (!$chat_id) return;
            $payload = json_encode(['chat_id' => $chat_id, 'text' => $text, 'parse_mode' => '']);
            break;
        case 'feishu':
            $payload = json_encode(['msg_type' => 'text', 'content' => ['text' => $text]]);
            break;
        case 'dingtalk':
            $payload = json_encode(['msgtype' => 'text', 'text' => ['content' => $text]]);
            break;
        default: // custom
            $payload = json_encode([
                'event'    => $event,
                'username' => $username,
                'ip'       => $ip,
                'note'     => $note,
                'time'     => $time_str,
                'site'     => $site_name,
                'text'     => $text,
            ]);
    }

    webhook_http_post_json($url, $payload, 3);
}
/**
 * 测试 Webhook（发送一条测试消息）
 * @return array ['ok' => bool, 'msg' => string]
 */
function webhook_test(): array {
    $cfg = load_config();
    $url  = trim($cfg['webhook_url'] ?? '');
    $type = $cfg['webhook_type'] ?? 'custom';
    if (!$url) return ['ok' => false, 'msg' => '未配置 Webhook URL'];

    $site_name = $cfg['site_name'] ?? '导航中心';
    $text = "🔔 [{$site_name}] Webhook 测试消息\n这是一条来自导航站后台的测试通知，发送时间：" . date('Y-m-d H:i:s');

    switch ($type) {
        case 'telegram':
            $chat_id = trim($cfg['webhook_tg_chat'] ?? '');
            if (!$chat_id) return ['ok' => false, 'msg' => '未配置 Telegram Chat ID'];
            $payload = json_encode(['chat_id' => $chat_id, 'text' => $text]);
            break;
        case 'feishu':
            $payload = json_encode(['msg_type' => 'text', 'content' => ['text' => $text]]);
            break;
        case 'dingtalk':
            $payload = json_encode(['msgtype' => 'text', 'text' => ['content' => $text]]);
            break;
        default:
            $payload = json_encode(['text' => $text, 'event' => 'TEST', 'site' => $site_name]);
    }

    $result = webhook_http_post_json($url, $payload, 5);
    if (!$result['ok']) {
        $msg = $result['error'] !== ''
            ? '发送失败：' . $result['error']
            : '发送失败，HTTP 状态码：' . ($result['status'] ?: 0);
        return ['ok' => false, 'msg' => $msg];
    }
    return ['ok' => true, 'msg' => '测试消息已发送，请检查接收端'];
}
function nginx_bin(): string {
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $candidates = [
        '/usr/sbin/nginx',              // Ubuntu/Debian 标准
        '/usr/local/sbin/nginx',        // 编译安装
        '/usr/local/bin/nginx',         // macOS Homebrew
    ];
    foreach ($candidates as $path) {
        if (is_executable($path)) {
            $cached = $path;
            return $cached;
        }
    }
    // 最后尝试 command -v（缓存结果，避免 settings 等页面重复 exec）
    $which = admin_run_command('command -v nginx');
    $bin = trim($which['output']);
    $cached = ($which['ok'] && $bin !== '') ? $bin : '/usr/sbin/nginx';
    return $cached;
}

/**
 * 检测当前环境是否具备可用的 Nginx reload 执行能力
 * 优先级：sudo 白名单 -> 容器内包装脚本
 *
 * @return array{ok:bool,method:string,msg:string,hint:string,test_output:string,nginx_bin:string}
 */
function nginx_reload_capability(): array {
    $nginx = nginx_bin();
    $safe_nginx = escapeshellarg($nginx);
    $hint = 'NGINX_BIN=' . $nginx . "\n"
        . 'USER_NAME=$(id -un)' . "\n"
        . 'printf \'%s ALL=(ALL) NOPASSWD: %s -t\n\' "$USER_NAME" "$NGINX_BIN" > /etc/sudoers.d/nav-nginx' . "\n"
        . 'printf \'%s ALL=(ALL) NOPASSWD: %s -s reload\n\' "$USER_NAME" "$NGINX_BIN" >> /etc/sudoers.d/nav-nginx' . "\n"
        . 'chmod 440 /etc/sudoers.d/nav-nginx';

    $sudo_test = admin_run_command('sudo -n ' . $safe_nginx . ' -t');
    if ($sudo_test['ok']) {
        return [
            'ok' => true,
            'method' => 'sudo',
            'msg' => '已检测到 sudo 白名单，可直接执行 Nginx 语法检测与 Reload。',
            'hint' => $hint,
            'test_output' => $sudo_test['output'],
            'nginx_bin' => $nginx,
        ];
    }

    $has_wrapper = is_executable('/usr/local/bin/nginx-reload') && is_executable('/usr/local/bin/nginx-test');
    if ($has_wrapper) {
        $wrapper_test = admin_run_command('/usr/local/bin/nginx-test');
        if ($wrapper_test['ok']) {
            return [
                'ok' => true,
                'method' => 'wrapper',
                'msg' => '已检测到容器内 Reload 包装器，可直接执行 Nginx 语法检测与 Reload。',
                'hint' => '',
                'test_output' => $wrapper_test['output'],
                'nginx_bin' => $nginx,
            ];
        }
        return [
            'ok' => false,
            'method' => 'wrapper',
            'msg' => '已检测到容器内 Reload 包装器，但当前 Nginx 配置语法检测未通过。',
            'hint' => '',
            'test_output' => $wrapper_test['output'],
            'nginx_bin' => $nginx,
        ];
    }

    return [
        'ok' => false,
        'method' => 'none',
        'msg' => '未检测到可用的 Nginx reload 执行权限。',
        'hint' => $hint,
        'test_output' => $sudo_test['output'],
        'nginx_bin' => $nginx,
    ];
}
function nginx_main_conf_path(): string {
    $runtime = '/etc/nginx/nginx.conf';
    if (is_link($runtime)) {
        $real = @readlink($runtime);
        if ($real !== false && is_file($real)) {
            return $real;
        }
    }
    if (is_file($runtime)) {
        return $runtime;
    }
    return dirname(__DIR__, 2) . '/docker/nginx.conf';
}

/**
 * HTTP 模块编辑入口（当前与主配置同文件）
 */
function nginx_http_conf_path(): string {
    return nginx_main_conf_path();
}

/**
 * 可编辑目标定义
 * @return array<string,array{label:string,path:string}>
 */
function nginx_editable_targets(): array {
    $targets = [
        'main' => [
            'label' => 'Nginx 主配置',
            'path' => nginx_main_conf_path(),
        ],
        'http' => [
            'label' => 'Nginx HTTP 模块',
            'path' => nginx_http_conf_path(),
        ],
    ];
    $phpFpm = '/usr/local/etc/php-fpm.d/nav.conf';
    if (is_file($phpFpm)) {
        $targets['php_fpm'] = [
            'label' => 'PHP-FPM 池配置 (nav.conf)',
            'path' => $phpFpm,
        ];
    }
    $phpIni = '/usr/local/etc/php/conf.d/99-nav-custom.ini';
    if (is_file($phpIni)) {
        $targets['php_custom'] = [
            'label' => 'PHP 自定义参数 (custom.ini)',
            'path' => $phpIni,
        ];
    }
    return $targets;
}
function nginx_http_block_bounds(string $content): ?array {
    if (!preg_match('/\bhttp\s*\{/i', $content, $m, PREG_OFFSET_CAPTURE)) {
        return null;
    }
    $start = (int)$m[0][1];
    $openPos = strpos($content, '{', $start);
    if ($openPos === false) {
        return null;
    }
    $len = strlen($content);
    $depth = 0;
    for ($i = $openPos; $i < $len; $i++) {
        $ch = $content[$i];
        if ($ch === '{') {
            $depth++;
        } elseif ($ch === '}') {
            $depth--;
            if ($depth === 0) {
                return [
                    'open' => $openPos,
                    'close' => $i,
                    'inner_start' => $openPos + 1,
                    'inner_end' => $i - 1,
                ];
            }
        }
    }
    return null;
}

/**
 * 读取指定编辑目标内容
 * @return array{ok:bool,msg:string,content:string,path:string,label:string}
 */
function nginx_read_target(string $target): array {
    $targets = nginx_editable_targets();
    if (!isset($targets[$target])) {
        return ['ok' => false, 'msg' => '未知配置目标', 'content' => '', 'path' => '', 'label' => ''];
    }
    $path = $targets[$target]['path'];
    $label = $targets[$target]['label'];

    if (!is_file($path)) {
        return ['ok' => false, 'msg' => '配置文件不存在：' . $path, 'content' => '', 'path' => $path, 'label' => $label];
    }
    $raw = @file_get_contents($path);
    if ($raw === false) {
        return ['ok' => false, 'msg' => '读取配置文件失败：' . $path, 'content' => '', 'path' => $path, 'label' => $label];
    }

    if ($target !== 'http') {
        return ['ok' => true, 'msg' => '', 'content' => $raw, 'path' => $path, 'label' => $label];
    }

    $bounds = nginx_http_block_bounds($raw);
    if (!$bounds) {
        return ['ok' => false, 'msg' => '未找到 http { ... } 模块', 'content' => '', 'path' => $path, 'label' => $label];
    }
    $inner = substr($raw, $bounds['inner_start'], $bounds['inner_end'] - $bounds['inner_start'] + 1);
    return ['ok' => true, 'msg' => '', 'content' => $inner, 'path' => $path, 'label' => $label];
}

/**
 * 写入指定编辑目标内容
 * @return array{ok:bool,msg:string,path:string}
 */
function nginx_write_target(string $target, string $content): array {
    $targets = nginx_editable_targets();
    if (!isset($targets[$target])) {
        return ['ok' => false, 'msg' => '未知配置目标', 'path' => ''];
    }

    // 防止异常超大提交导致内存/磁盘压力
    if (strlen($content) > 2 * 1024 * 1024) {
        return ['ok' => false, 'msg' => '配置内容过大（超过 2MB）', 'path' => $targets[$target]['path'] ?? ''];
    }

    $path = $targets[$target]['path'];

    if (!is_file($path)) {
        return ['ok' => false, 'msg' => '配置文件不存在：' . $path, 'path' => $path];
    }

    if ($target === 'http') {
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return ['ok' => false, 'msg' => '读取配置文件失败：' . $path, 'path' => $path];
        }
        $bounds = nginx_http_block_bounds($raw);
        if (!$bounds) {
            return ['ok' => false, 'msg' => '未找到 http { ... } 模块', 'path' => $path];
        }
        $newRaw = substr($raw, 0, $bounds['inner_start'])
            . "\n" . rtrim($content) . "\n"
            . substr($raw, $bounds['close']);
        $ok = @file_put_contents($path, $newRaw, LOCK_EX);
        return $ok === false
            ? ['ok' => false, 'msg' => '写入失败，请检查文件权限：' . $path, 'path' => $path]
            : ['ok' => true, 'msg' => '已保存：' . $path, 'path' => $path];
    }

    $ok = @file_put_contents($path, $content, LOCK_EX);
    return $ok === false
        ? ['ok' => false, 'msg' => '写入失败，请检查文件权限：' . $path, 'path' => $path]
        : ['ok' => true, 'msg' => '已保存：' . $path, 'path' => $path];
}

/**
 * 仅执行 nginx -t 语法检测
 * @return array{ok:bool,msg:string,test_output:string}
 */
function nginx_test_config(): array {
    $capability = nginx_reload_capability();
    $nginx = $capability['nginx_bin'];
    $safe_nginx = escapeshellarg($nginx);

    $test = admin_run_command('sudo -n ' . $safe_nginx . ' -t');
    if ($test['ok']) {
        return [
            'ok' => true,
            'msg' => 'Nginx 配置语法检测通过',
            'test_output' => $test['output'],
        ];
    }

    $use_wrapper = ($capability['method'] === 'wrapper')
        || (is_executable('/usr/local/bin/nginx-test'));
    if ($use_wrapper) {
        $wrapperTest = admin_run_command('/usr/local/bin/nginx-test');
        return [
            'ok' => $wrapperTest['ok'],
            'msg' => $wrapperTest['ok'] ? 'Nginx 配置语法检测通过' : 'Nginx 配置语法检测失败',
            'test_output' => $wrapperTest['output'],
        ];
    }

    return [
        'ok' => false,
        'msg' => '未检测到可用的 Nginx 语法检测执行方式',
        'test_output' => $test['output'],
    ];
}

/**
 * 将当前编辑内容写入原文件做预览检测，检测完恢复原文件
 * 用于"检查语法"按钮实时检测当前编辑框内容，不实际保存
 *
 * @return array{ok:bool,msg:string,test_output:string}
 */
function php_fpm_test_config(): array {
    $test = admin_run_command('/usr/local/sbin/php-fpm -t --fpm-config /usr/local/etc/php-fpm.d/nav.conf');
    if ($test['ok']) {
        return [
            'ok' => true,
            'msg' => 'PHP-FPM 配置语法检测通过',
            'test_output' => $test['output'],
        ];
    }
    return [
        'ok' => false,
        'msg' => 'PHP-FPM 配置语法检测失败',
        'test_output' => $test['output'],
    ];
}

function php_fpm_reload(): array {
    if (!is_executable('/usr/local/bin/php-fpm-reload')) {
        return [
            'ok' => false,
            'msg' => 'PHP-FPM reload 脚本不可用',
            'test_output' => '',
        ];
    }
    $reload = admin_run_command('/usr/local/bin/php-fpm-reload');
    if ($reload['ok']) {
        return [
            'ok' => true,
            'msg' => 'PHP-FPM 已 graceful reload',
            'test_output' => $reload['output'],
        ];
    }
    return [
        'ok' => false,
        'msg' => 'PHP-FPM reload 失败：' . $reload['output'],
        'test_output' => $reload['output'],
    ];
}

/**
 * 隔离反代配置后执行 nginx -t
 * 用于编辑系统配置时的语法检测，避免反代配置错误干扰结果
 *
 * @return array{ok:bool,msg:string,test_output:string}
 */
function nginx_test_config_isolated(): array {
    return nginx_test_config();
}

function nginx_test_config_preview(string $target, string $content): array {
    $targets = nginx_editable_targets();
    if (!isset($targets[$target])) {
        return ['ok' => false, 'msg' => '未知配置目标', 'test_output' => ''];
    }
    $path = $targets[$target]['path'];
    if (!is_file($path)) {
        return ['ok' => false, 'msg' => '配置文件不存在：' . $path, 'test_output' => ''];
    }

    $backup = @file_get_contents($path);
    if ($backup === false) {
        return ['ok' => false, 'msg' => '读取原文件失败：' . $path, 'test_output' => ''];
    }

    // 写入预览内容（复用 nginx_write_target 的 http 块替换逻辑）
    if ($target === 'http') {
        $bounds = nginx_http_block_bounds($backup);
        if (!$bounds) {
            return ['ok' => false, 'msg' => '未找到 http { ... } 模块', 'test_output' => ''];
        }
        $preview = substr($backup, 0, $bounds['inner_start'])
            . "\n" . rtrim($content) . "\n"
            . substr($backup, $bounds['close']);
        $written = @file_put_contents($path, $preview, LOCK_EX);
    } else {
        $written = @file_put_contents($path, $content, LOCK_EX);
    }

    if ($written === false) {
        @file_put_contents($path, $backup, LOCK_EX);
        return ['ok' => false, 'msg' => '写入预览文件失败，请检查文件权限：' . $path, 'test_output' => ''];
    }

    // 执行语法检测（隔离反代配置，避免反代错误干扰系统配置检测）
    $test = nginx_test_config_isolated();

    // 恢复原文件（无论检测成功与否）
    @file_put_contents($path, $backup, LOCK_EX);

    return $test;
}

/**
 * 执行 nginx -t 语法检测 + nginx -s reload
 * 优先使用 sudo 白名单；容器环境可退回到包装脚本
 *
 * sudo 白名单配置（在服务器上执行一次，限定具体参数防提权）：
 *   NGINX_BIN=$(which nginx || echo /usr/sbin/nginx)
 *   USER_NAME=$(id -un)
 *   printf '%s ALL=(ALL) NOPASSWD: %s -t\n' "$USER_NAME" "$NGINX_BIN" > /etc/sudoers.d/nav-nginx
 *   printf '%s ALL=(ALL) NOPASSWD: %s -s reload\n' "$USER_NAME" "$NGINX_BIN" >> /etc/sudoers.d/nav-nginx
 *   chmod 440 /etc/sudoers.d/nav-nginx
 *
 * @return array ['ok' => bool, 'msg' => string, 'test_output' => string]
 */
function nginx_reload(): array {
    $capability = nginx_reload_capability();
    $nginx = $capability['nginx_bin'];
    $safe_nginx = escapeshellarg($nginx);

    // 优先使用 sudo 直接执行真实的 nginx -t / nginx -s reload
    $test = admin_run_command('sudo -n ' . $safe_nginx . ' -t');
    $test_msg = $test['output'];
    if ($test['ok']) {
        $reload = admin_run_command('sudo -n ' . $safe_nginx . ' -s reload');
        if (!$reload['ok']) {
            return [
                'ok'          => false,
                'msg'         => 'nginx reload 执行失败',
                'test_output' => $reload['output'],
            ];
        }
        return [
            'ok'          => true,
            'msg'         => 'Nginx 已成功 reload',
            'test_output' => $test_msg,
        ];
    }

    // 降级：使用包装脚本（容器内无 sudo 时的替代方案）
    $use_wrapper = ($capability['method'] === 'wrapper')
        || (is_executable('/usr/local/bin/nginx-reload') && is_executable('/usr/local/bin/nginx-test'));
    if ($use_wrapper) {
        $wrapperTest = admin_run_command('/usr/local/bin/nginx-test');
        $wrapperMsg = $wrapperTest['output'];
        if (!$wrapperTest['ok']) {
            return [
                'ok'          => false,
                'msg'         => 'Nginx 配置语法错误，已中止 reload，请检查配置',
                'test_output' => $wrapperMsg !== '' ? $wrapperMsg : $test_msg,
            ];
        }

        $reload = admin_run_command('/usr/local/bin/nginx-reload');
        if (!$reload['ok']) {
            return [
                'ok'          => false,
                'msg'         => 'nginx reload 执行失败',
                'test_output' => $reload['output'],
            ];
        }

        return [
            'ok'          => true,
            'msg'         => 'Nginx 已成功 reload',
            'test_output' => $wrapperMsg,
        ];
    }

    return [
        'ok'          => false,
        'msg'         => 'Nginx 配置检测失败，且未找到可用的 reload 执行方式',
        'test_output' => $test_msg,
    ];
}
function auth_reload_config(): void {
    // auth_get_config 使用 static $cfg，无法直接清除
    // 通过写入一个特殊 flag，让下次调用重新读文件
    // 实际在同一请求内无需刷新，下次请求自动读新值
}

/**
 * 写入操作审计日志（JSON Lines）
 */
function audit_log(string $action, array $context = []): void {
    $dir = dirname(AUDIT_LOG_FILE);
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    $user = auth_get_current_user();
    $line = json_encode([
        'time'    => date('Y-m-d H:i:s'),
        'user'    => $user['username'] ?? 'guest',
        'ip'      => get_client_ip(),
        'action'  => $action,
        'context' => $context,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    file_put_contents(AUDIT_LOG_FILE, $line . "\n", FILE_APPEND | LOCK_EX);
}
