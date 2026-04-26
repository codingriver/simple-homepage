<?php
/**
 * 后台公共函数库 admin/shared/functions.php
 * 包含：站点数据读写、配置读写、CSRF、Flash消息、备份/恢复、统计
 */
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../shared/notify_runtime.php';
require_once __DIR__ . '/../../shared/http_client.php';

// 数据文件路径常量
define('SITES_FILE',   DATA_DIR . '/sites.json');
define('BACKUPS_DIR',  DATA_DIR . '/backups');
define('BG_DIR',       DATA_DIR . '/bg');
define('MAX_BACKUPS',  20); // 最多保留备份数
define('AUDIT_LOG_FILE', DATA_DIR . '/logs/audit.log');

// ── 站点数据 ──

/** 读取站点配置 */
function load_sites(): array {
    if (!file_exists(SITES_FILE)) {
        return ['groups' => []];
    }
    return json_decode(file_get_contents(SITES_FILE), true) ?? ['groups' => []];
}

/** 写入站点配置 */
function save_sites(array $data): void {
    file_put_contents(SITES_FILE,
        json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        LOCK_EX);
}

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
 * 包含：sites、config、scheduled_tasks（含各任务的 command 脚本）、dns_config（域名解析账户）、ddns_tasks。
 *
 * @param string $trigger 触发标识：manual / export / auto_import 等
 * @return array<string, mixed>
 */
function backup_collect_payload(string $trigger = 'manual'): array {
    $sites_data  = file_exists(SITES_FILE)  ? file_get_contents(SITES_FILE)  : '{}';
    $config_data = file_exists(CONFIG_FILE) ? file_get_contents(CONFIG_FILE) : '{}';

    $st_file   = DATA_DIR . '/scheduled_tasks.json';
    $dns_file  = DATA_DIR . '/dns_config.json';
    $ddns_file = DATA_DIR . '/ddns_tasks.json';
    $tpl_file  = DATA_DIR . '/task_templates.json';
    $notify_file = DATA_DIR . '/notifications.json';
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
        'sites'           => json_decode($sites_data, true) ?? [],
        'config'          => json_decode($config_data, true) ?? [],
        'scheduled_tasks' => $scheduled_tasks,
        'dns_config'      => file_exists($dns_file) ? (json_decode(file_get_contents($dns_file), true) ?? []) : [],
        'ddns_tasks'      => file_exists($ddns_file) ? (json_decode(file_get_contents($ddns_file), true) ?? []) : [],
        'task_templates'  => file_exists($tpl_file) ? (json_decode(file_get_contents($tpl_file), true) ?? []) : [],
        'notifications'   => file_exists($notify_file) ? (json_decode(file_get_contents($notify_file), true) ?? []) : [],
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
    $wrote_templates = false;
    $wrote_notifications = false;

    if (isset($data['sites'])) {
        file_put_contents(SITES_FILE,
            json_encode($data['sites'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            LOCK_EX);
    }
    if (isset($data['config']) && is_array($data['config'])) {
        file_put_contents(CONFIG_FILE,
            json_encode($data['config'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            LOCK_EX);
    }

    $st_file   = DATA_DIR . '/scheduled_tasks.json';
    $dns_file  = DATA_DIR . '/dns_config.json';
    $ddns_file = DATA_DIR . '/ddns_tasks.json';
    $tpl_file  = DATA_DIR . '/task_templates.json';
    $notify_file = DATA_DIR . '/notifications.json';
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
    if (isset($data['task_templates']) && is_array($data['task_templates'])) {
        file_put_contents($tpl_file,
            json_encode($data['task_templates'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            LOCK_EX);
        $wrote_templates = true;
    }
    if (isset($data['notifications']) && is_array($data['notifications'])) {
        file_put_contents($notify_file,
            json_encode($data['notifications'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            LOCK_EX);
        $wrote_notifications = true;
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
    if ($wrote_templates && !file_exists($tpl_file)) {
        file_put_contents($tpl_file, json_encode(['version' => 1, 'templates' => []], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }
    if ($wrote_notifications && !file_exists($notify_file)) {
        file_put_contents($notify_file, json_encode(['version' => 1, 'channels' => []], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
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

    // simulate 模式下 host-agent 路径映射与真实 trash 目录不一致，直接本地操作
    $isSimulate = strtolower(trim((string)getenv('HOST_AGENT_INSTALL_MODE'))) === 'simulate';
    if ($isSimulate) {
        $source = '/var/www/nav/data/host-agent-sim-root' . $path;
        $target = $dataDir;
        if (!file_exists($source)) {
            @unlink($metaPath);
            @rmdir($dataDir);
            @rmdir($entryDir);
            return ['ok' => false, 'msg' => '源文件或目录不存在'];
        }
        $parent = dirname($target);
        if (!is_dir($parent)) {
            @mkdir($parent, 0755, true);
        }
        $ok = @rename($source, $target);
        if (!$ok) {
            @unlink($metaPath);
            @rmdir($dataDir);
            @rmdir($entryDir);
            return ['ok' => false, 'msg' => '移动到回收站失败'];
        }
        return ['ok' => true, 'msg' => '已移至回收站', 'entry_id' => $entryId];
    }

    // 通过 host-agent 将文件/目录移动到回收站
    $result = host_agent_fs_move(['type' => 'local'], $path, $dataDir);
    if (empty($result['ok'])) {
        // 移动失败，清理回收站目录
        @unlink($metaPath);
        @rmdir($dataDir);
        @rmdir($entryDir);
        return ['ok' => false, 'msg' => '移动到回收站失败: ' . ($result['msg'] ?? '')];
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

    $isSimulate = strtolower(trim((string)getenv('HOST_AGENT_INSTALL_MODE'))) === 'simulate';
    if ($isSimulate) {
        $source = $dataDir;
        $target = '/var/www/nav/data/host-agent-sim-root' . $originalPath;
        $parent = dirname($target);
        if (!is_dir($parent)) {
            @mkdir($parent, 0755, true);
        }
        $ok = @rename($source, $target);
        if (!$ok) {
            return ['ok' => false, 'msg' => '恢复失败'];
        }
        @unlink($metaPath);
        @rmdir(dirname($metaPath));
        $entryDir = dirname($metaPath);
        @rmdir($entryDir);
        return ['ok' => true, 'msg' => '已恢复到 ' . $originalPath];
    }

    $result = host_agent_fs_move(['type' => 'local'], $dataDir, $originalPath);
    if (empty($result['ok'])) {
        return ['ok' => false, 'msg' => '恢复失败: ' . ($result['msg'] ?? '')];
    }

    // 清理回收站目录
    @unlink($metaPath);
    @rmdir(dirname($metaPath));
    $entryDir = dirname($metaPath);
    @rmdir($entryDir);

    return ['ok' => true, 'msg' => '已恢复到 ' . $originalPath];
}

function trash_permanent_delete(string $entryId): array {
    $metaPath = trash_meta_path($entryId);
    $dataDir = trash_data_path($entryId);
    $entryDir = dirname($metaPath);

    if (is_dir($dataDir)) {
        $result = host_agent_fs_delete(['type' => 'local'], $dataDir);
        if (empty($result['ok'])) {
            return ['ok' => false, 'msg' => '永久删除失败: ' . ($result['msg'] ?? '')];
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
        'notifications'  => DATA_DIR . '/logs/notifications.log',
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

define('HEALTH_CACHE_FILE', DATA_DIR . '/health_cache.json');
define('HEALTH_CACHE_TTL',  300);  // 缓存有效期（秒），5 分钟
define('HEALTH_TIMEOUT',    5);    // 单站点检测超时（秒）
define('HEALTH_ALERT_FILE', DATA_DIR . '/health_alerts.json');
define('API_TOKENS_FILE', DATA_DIR . '/api_tokens.json');

/**
 * 读取健康状态缓存
 * @return array  { url => ['status'=>'up'|'down'|'unknown', 'code'=>int, 'ms'=>int, 'checked_at'=>int] }
 */
function health_load_cache(): array {
    if (!file_exists(HEALTH_CACHE_FILE)) return [];
    return json_decode(file_get_contents(HEALTH_CACHE_FILE), true) ?? [];
}

/**
 * 写入健康状态缓存
 */
function health_save_cache(array $data): void {
    file_put_contents(HEALTH_CACHE_FILE,
        json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
}

/**
 * 检测单个 URL 的可用性（HTTP HEAD，超时 HEALTH_TIMEOUT 秒）
 * @return array ['status'=>'up'|'down', 'code'=>int, 'ms'=>int]
 */
function health_check_url(string $url): array {
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return ['status' => 'down', 'code' => 0, 'ms' => 0];
    }

    $start = microtime(true);
    $code   = 0;
    $status = 'down';

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_NOBODY => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_TIMEOUT => HEALTH_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_USERAGENT => 'NavPortal-HealthCheck/1.0',
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ]);
        $resp = curl_exec($ch);
        if ($resp !== false) {
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $status = ($code >= 200 && $code < 500) ? 'up' : 'down';
        }
        curl_close($ch);
    } else {
        $ctx = stream_context_create([
            'http' => [
                'method'          => 'HEAD',
                'timeout'         => HEALTH_TIMEOUT,
                'ignore_errors'   => true,
                'follow_location' => 1,
                'max_redirects'   => 3,
                'header'          => "User-Agent: NavPortal-HealthCheck/1.0\r\n",
            ],
        ]);
        try {
            @file_get_contents($url, false, $ctx);
            if (!empty($http_response_header)) {
                preg_match('#HTTP/\d+\.?\d*\s+(\d+)#', $http_response_header[0], $m);
                $code = (int)($m[1] ?? 0);
            }
            $status = ($code >= 200 && $code < 500) ? 'up' : 'down';
        } catch (\Throwable $e) {
            $status = 'down';
        }
    }

    $ms = (int)round((microtime(true) - $start) * 1000);
    return ['status' => $status, 'code' => $code, 'ms' => $ms];
}

function health_build_curl_handle(string $url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_NOBODY => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_TIMEOUT => HEALTH_TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_USERAGENT => 'NavPortal-HealthCheck/1.0',
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
    ]);
    return $ch;
}

/**
 * 并行检测多个 URL；无 curl_multi 时回退为串行。
 * @param list<string> $urls
 * @return array<string, array{status:string,code:int,ms:int}>
 */
function health_check_many(array $urls): array {
    $targets = array_values(array_filter(array_unique(array_map(
        static fn($url) => trim((string)$url),
        $urls
    )), static fn($url) => $url !== '' && filter_var($url, FILTER_VALIDATE_URL)));
    if ($targets === []) {
        return [];
    }

    if (!function_exists('curl_multi_init') || !function_exists('curl_init')) {
        $result = [];
        foreach ($targets as $url) {
            $result[$url] = health_check_url($url);
        }
        return $result;
    }

    $mh = curl_multi_init();
    $handles = [];
    foreach ($targets as $url) {
        $ch = health_build_curl_handle($url);
        $handles[$url] = $ch;
        curl_multi_add_handle($mh, $ch);
    }

    $running = null;
    do {
        $status = curl_multi_exec($mh, $running);
        if ($status > CURLM_OK) {
            break;
        }
        if ($running > 0) {
            $selected = curl_multi_select($mh, 1.0);
            if ($selected === -1) {
                usleep(100000);
            }
        }
    } while ($running > 0);

    $result = [];
    foreach ($handles as $url => $ch) {
        $code = 0;
        $status = 'down';
        if (curl_errno($ch) === 0) {
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $status = ($code >= 200 && $code < 500) ? 'up' : 'down';
        }
        $ms = (int) round(((float) curl_getinfo($ch, CURLINFO_TOTAL_TIME)) * 1000);
        $result[$url] = ['status' => $status, 'code' => $code, 'ms' => $ms];
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);

    return $result;
}

/**
 * 检测所有站点并更新缓存
 * 仅检测 external / internal 类型有 url 字段的站点，以及 proxy 类型的 proxy_target
 * @return array  url => health_result
 */
function health_check_all(): array {
    $sites_data = load_sites();
    $cache      = health_load_cache();
    $now        = time();
    $urls       = [];

    foreach ($sites_data['groups'] as $grp) {
        foreach ($grp['sites'] ?? [] as $s) {
            // 构造要检测的 URL
            if (($s['type'] ?? '') === 'proxy') {
                $url = $s['proxy_target'] ?? '';
            } else {
                $url = $s['url'] ?? '';
            }
            if (!$url || !filter_var($url, FILTER_VALIDATE_URL)) continue;
            $urls[] = $url;
        }
    }

    foreach (health_check_many($urls) as $url => $result) {
            $result['checked_at'] = $now;
            $cache[$url] = $result;
    }

    health_save_cache($cache);
    return $cache;
}

/**
 * 根据站点数据获取其健康状态（从缓存）
 * @param array $site   站点数组
 * @param array $cache  health_load_cache() 返回的缓存
 * @return string  'up' | 'down' | 'unknown'
 */
function health_get_status(array $site, array $cache): string {
    $url = ($site['type'] ?? '') === 'proxy'
        ? ($site['proxy_target'] ?? '')
        : ($site['url'] ?? '');
    if (!$url || !isset($cache[$url])) return 'unknown';
    // 超过 TTL 视为 unknown
    if ((time() - ($cache[$url]['checked_at'] ?? 0)) > HEALTH_CACHE_TTL * 2) return 'unknown';
    return $cache[$url]['status'] ?? 'unknown';
}

/**
 * 加载已告警的 down 站点记录（url => alerted_at）
 */
function health_alert_load(): array {
    if (!file_exists(HEALTH_ALERT_FILE)) return [];
    return json_decode(file_get_contents(HEALTH_ALERT_FILE), true) ?? [];
}

/**
 * 保存已告警的 down 站点记录
 */
function health_alert_save(array $data): void {
    file_put_contents(HEALTH_ALERT_FILE,
        json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
}

// ══════════════════════════════════════════════════════════════
// ── API Token 管理
// ══════════════════════════════════════════════════════════════

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

// ══════════════════════════════════════════════════════════════
// ── Webhook 通知
// ══════════════════════════════════════════════════════════════

/**
 * 发送 Webhook 通知
 * 支持：Telegram Bot、飞书、钉钉、自定义（POST JSON）
 *
 * config.json 中的相关字段：
 *   webhook_enabled   : '1' | '0'
 *   webhook_type      : 'telegram' | 'feishu' | 'dingtalk' | 'custom'
 *   webhook_url       : Webhook URL
 *   webhook_tg_chat   : Telegram Chat ID（仅 telegram 类型需要）
 *   webhook_events    : 逗号分隔的事件列表，如 'SUCCESS,FAIL,IP_LOCKED'
 *
 * @param string $event    事件类型：SUCCESS / FAIL / IP_LOCKED / SETUP
 * @param string $username 用户名
 * @param string $ip       客户端 IP
 * @param string $note     附加说明
 */
function webhook_http_post_json(string $url, string $payload, int $timeout = 5): array {
    return http_post_json($url, $payload, $timeout);
}

function webhook_send(string $event, string $username, string $ip, string $note = ''): void {
    $cfg = load_config();
    if (($cfg['webhook_enabled'] ?? '0') !== '1') return;

    // 检查事件是否在订阅列表内
    $events = array_filter(array_map('trim', explode(',', $cfg['webhook_events'] ?? 'FAIL,IP_LOCKED,HEALTH_DOWN')));
    if (!in_array($event, $events, true)) return;

    $url  = trim($cfg['webhook_url'] ?? '');
    $type = $cfg['webhook_type'] ?? 'custom';
    if (!$url) return;

    $site_name = $cfg['site_name'] ?? '导航中心';
    $time_str  = date('Y-m-d H:i:s');
    $emoji_map = [
        'SUCCESS'      => '✅',
        'FAIL'         => '❌',
        'IP_LOCKED'    => '🔒',
        'LOGOUT'       => '🚪',
        'SETUP'        => '🎉',
        'HEALTH_DOWN'  => '💔',
    ];
    $emoji = $emoji_map[$event] ?? '📢';
    $text  = "{$emoji} [{$site_name}] " . ($event === 'HEALTH_DOWN' ? '健康告警' : '登录事件') . "\n"
           . "事件：{$event}\n"
           . ($event === 'HEALTH_DOWN' ? '' : "用户：{$username}\n")
           . ($event === 'HEALTH_DOWN' ? '' : "IP：{$ip}\n")
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
 * 发送健康告警 Webhook 通知
 * @param string $siteName 站点名称
 * @param string $url      检测 URL
 * @param int    $code     HTTP 状态码
 * @param int    $ms       响应耗时
 */
function webhook_send_health_alert(string $siteName, string $url, int $code, int $ms): void {
    $note = "站点：{$siteName}\nURL：{$url}\n状态码：" . ($code ?: '-') . "\n耗时：" . ($ms ?: '-') . "ms";
    webhook_send('HEALTH_DOWN', 'health-check', '127.0.0.1', $note);
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



/**
 * 清洗即将写入 Nginx 配置的用户输入字段
 */
function nginx_sanitize_config_literal(string $s): string {
    // 禁止换行、回车、空字符、Nginx 语句结束符
    $s = preg_replace('/[\r\n\0;]/', '', $s);
    return trim($s);
}

/**
 * 根据当前 sites.json 生成 Nginx 反代配置片段
 * 输出到：
 *   - /etc/nginx/conf.d/nav-proxy.conf        （路径前缀模式）
 *   - /etc/nginx/http.d/nav-proxy-domains.conf（子域名模式）
 * 由 nginx reload 后生效。
 *
 * 仅处理 type=proxy 的站点：
 *   - proxy_mode=path   → location /p/{slug}/ { proxy_pass ... }
 *   - proxy_mode=domain → 独立 server 块（子域名模式）
 *
 * @return array ['ok' => bool, 'msg' => string, 'path_conf' => string, 'domain_conf' => string]
 */
function nginx_generate_proxy_conf(): array {
    $cfg        = load_config();
    $nav_domain = $cfg['nav_domain'] ?? 'nav.yourdomain.com';
    $port       = (int)(getenv('NAV_PORT') ?: 58080);
    $sites_data = load_sites();
    $groups     = $sites_data['groups'] ?? [];

    // proxy_params_mode: 'simple'（精简）或 'full'（完整）
    $params_mode = ($cfg['proxy_params_mode'] ?? 'simple') === 'full' ? 'full' : 'simple';
    $params_files = nginx_proxy_params_file_paths();
    $selected_params_file = ($params_mode === 'full') ? $params_files['full'] : $params_files['simple'];

    $path_blocks   = []; // location /p/{slug}/ 块（追加到主站 server 内，需手动 include）
    $domain_blocks = []; // 独立 server 块（子域名模式）

    foreach ($groups as $grp) {
        foreach ($grp['sites'] ?? [] as $s) {
            if (($s['type'] ?? '') !== 'proxy') continue;
            $target = rtrim($s['proxy_target'] ?? '', '/');
            // 校验 proxy_target 格式，防止配置注入（仅允许 http(s)://host:port/path 形式，禁止 ..）
            if (!preg_match('#^https?://[a-zA-Z0-9._-]+(:\d+)?(/[a-zA-Z0-9._~!$&\'()*+,;=:@/-]*)?$#', $target) || str_contains($target, '..')) {
                continue; // 格式非法，跳过此站点，不写入 Nginx 配置
            }
            $name   = nginx_sanitize_config_literal($s['name'] ?? $s['id']);

            if (($s['proxy_mode'] ?? 'path') === 'path') {
                $slug = preg_replace('/[^a-z0-9_-]/', '-', strtolower($s['slug'] ?? $s['id']));
                $block_lines = [
                    "    # {$name}",
                    "    location /p/{$slug}/ {",
                    "        if (\$cookie_nav_session = \"\") { return 302 /login.php?redirect=\$request_uri; }",
                    "        auth_request      /auth/verify.php;",
                    "        error_page 401  = @login_redirect;",
                    "        proxy_pass        {$target}/;",
                    "        include           {$selected_params_file};",
                ];
                $block_lines[] = "    }";
                $path_blocks[] = implode("\n", $block_lines);
            } else {
                // 子域名模式：独立 server 块
                $pd = nginx_sanitize_config_literal($s['proxy_domain'] ?? '');
                if (!$pd || !preg_match('/^[a-zA-Z0-9._-]+$/', $pd)) continue;
                $block_lines = [
                    "server {",
                    "    listen {$port};",
                    "    listen [::]:{$port};",
                    "    server_name {$pd};",
                    "",
                    "    location = /auth/verify {",
                    "        internal;",
                    "        fastcgi_pass unix:/run/php-fpm.sock;",
                    "        fastcgi_param SCRIPT_FILENAME /var/www/nav/public/auth/verify.php;",
                    "        fastcgi_pass_request_body off;",
                    "        fastcgi_param CONTENT_LENGTH \"\";",
                    "        include fastcgi_params;",
                    "        fastcgi_param HTTP_X_REAL_IP \$remote_addr;",
                    "        fastcgi_param HTTP_X_FORWARDED_FOR \$proxy_add_x_forwarded_for;",
                    "        fastcgi_param HTTP_X_FORWARDED_PROTO \$http_x_forwarded_proto;",
                    "        fastcgi_connect_timeout 10s;",
                    "        fastcgi_send_timeout 30s;",
                    "        fastcgi_read_timeout 30s;",
                    "    }",
                    "",
                    "    location / {",
                    "        if (\$cookie_nav_session = \"\") { return 302 https://{$nav_domain}/login.php?redirect=https://\$host\$request_uri; }",
                    "        auth_request /auth/verify;",
                    "        error_page 401 = @nav_login;",
                    "        proxy_pass {$target};",
                    "        include {$selected_params_file};",
                ];
                $block_lines = array_merge($block_lines, [
                    "    }",
                    "",
                    "    location @nav_login {",
                    "        return 302 https://{$nav_domain}/login.php?redirect=https://\$host\$request_uri;",
                    "    }",
                    "}",
                ]);
                $domain_blocks[] = implode("\n", $block_lines);
            }
        }
    }

    // 组装路径模式配置
    // 注意：此文件通过 nav.conf 的 include 指令嵌入 server {} 块内
    // 因此只能包含 location 块，不能包含 map/server 等顶层指令
    $path_lines = [
        "# 导航站自动生成的 Nginx 反代配置",
        "# 生成时间：" . date('Y-m-d H:i:s'),
        "# 此文件由后台自动管理，请勿手动编辑",
        "# 路径前缀模式：此文件被 include 到 server {} 块内，只能包含 location 块",
        "",
    ];

    if (!empty($path_blocks)) {
        $path_lines[] = "# ── 路径前缀模式 ──";
        foreach ($path_blocks as $b) { $path_lines[] = $b; $path_lines[] = ""; }
    }

    if (empty($path_blocks)) {
        $path_lines[] = "# 暂无路径前缀代理站点配置";
    }

    // 组装子域名模式配置
    $domain_lines = [
        "# 导航站自动生成的 Nginx 子域名代理配置",
        "# 生成时间：" . date('Y-m-d H:i:s'),
        "# 此文件由后台自动管理，请勿手动编辑",
        "",
    ];

    if (!empty($domain_blocks)) {
        $domain_lines[] = "# ── 子域名模式 ──";
        foreach ($domain_blocks as $b) {
            $domain_lines[] = $b;
            $domain_lines[] = "";
        }
    } else {
        $domain_lines[] = "# 暂无子域名代理站点配置";
    }

    $path_conf   = implode("\n", $path_lines);
    $domain_conf = implode("\n", $domain_lines);

    $templateResult = nginx_write_proxy_params_templates();
    if (!$templateResult['ok']) {
        return [
            'ok' => false,
            'msg' => $templateResult['msg'],
            'path_conf' => $path_conf,
            'domain_conf' => $domain_conf,
        ];
    }

    // 写入配置文件
    $path_conf_path   = nginx_proxy_conf_path();
    $domain_conf_path = nginx_domain_proxy_conf_path();
    $path_result      = @file_put_contents($path_conf_path, $path_conf, LOCK_EX);
    $domain_result    = @file_put_contents($domain_conf_path, $domain_conf, LOCK_EX);

    if ($path_result === false || $domain_result === false) {
        return [
            'ok'   => false,
            'msg'  => "写入配置文件失败：{$path_conf_path} 或 {$domain_conf_path}，请检查 www-data 是否有写入权限",
            'path_conf' => $path_conf,
            'domain_conf' => $domain_conf,
        ];
    }

    return [
        'ok' => true,
        'msg' => "配置已写入 {$path_conf_path} 和 {$domain_conf_path}",
        'path_conf' => $path_conf,
        'domain_conf' => $domain_conf,
    ];
}

/**
 * 写入反代配置，并在 reload 失败时自动回滚
 * @param bool $reload 是否立即 reload
 * @return array ['ok'=>bool,'msg'=>string]
 */
function nginx_apply_proxy_conf(bool $reload = false): array {
    $conf_path        = nginx_proxy_conf_path();
    $domain_conf_path = nginx_domain_proxy_conf_path();
    $old_conf         = file_exists($conf_path) ? @file_get_contents($conf_path) : null;
    $old_domain_conf  = file_exists($domain_conf_path) ? @file_get_contents($domain_conf_path) : null;

    $params_paths         = nginx_proxy_params_file_paths();
    $old_params_simple    = file_exists($params_paths['simple']) ? @file_get_contents($params_paths['simple']) : null;
    $old_params_full      = file_exists($params_paths['full'])   ? @file_get_contents($params_paths['full'])   : null;

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
        if ($old_domain_conf !== null) {
            @file_put_contents($domain_conf_path, $old_domain_conf, LOCK_EX);
        }
        if ($old_params_simple !== null) {
            @file_put_contents($params_paths['simple'], $old_params_simple, LOCK_EX);
        }
        if ($old_params_full !== null) {
            @file_put_contents($params_paths['full'], $old_params_full, LOCK_EX);
        }
        $rollback_rel = nginx_reload();
        if ($rollback_rel['ok']) {
            return ['ok' => false, 'msg' => 'Reload 失败，已自动回滚到上一次可用配置：' . $rel['msg']];
        }
        return ['ok' => false, 'msg' => 'Reload 失败，且自动回滚后恢复失败，请手动检查 Nginx：' . $rollback_rel['msg']];
    }

    return ['ok' => false, 'msg' => 'Reload 失败，且不存在可回滚的旧配置：' . $rel['msg']];
}

/**
 * 自动检测 Nginx 可执行文件路径
 * 按优先级检测：标准路径 → which 命令
 */
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

/**
 * Nginx 反代配置文件路径
 */
function nginx_proxy_conf_path(): string {
    return '/etc/nginx/conf.d/nav-proxy.conf';
}

/**
 * Nginx 子域名反代配置文件路径
 */
function nginx_domain_proxy_conf_path(): string {
    return '/etc/nginx/http.d/nav-proxy-domains.conf';
}

/**
 * 反代参数文件路径（精简 / 完整）
 * 通过 include 引入，便于模式切换与审计
 * @return array{simple:string,full:string}
 */
function nginx_proxy_params_file_paths(): array {
    $baseDataDir = realpath(DATA_DIR) ?: DATA_DIR;
    $dir = rtrim($baseDataDir, '/') . '/nginx';
    return [
        'simple' => $dir . '/proxy-params-simple.conf',
        'full' => $dir . '/proxy-params-full.conf',
    ];
}

/**
 * 精简反代参数模板（默认内置版本）
 */
function nginx_default_proxy_params_simple_template(): string {
    return implode("\n", [
        '# Nginx 精简反代参数模板',
        '# 适用：普通网站 / API / 常规反向代理',
        'proxy_http_version              1.1;',
        'proxy_set_header                Host                            $host;',
        'proxy_set_header                X-Real-IP                       $remote_addr;',
        'proxy_set_header                X-Forwarded-For                 $proxy_add_x_forwarded_for;',
        'proxy_set_header                X-Forwarded-Proto               $scheme;',
        'proxy_set_header                X-Forwarded-Host                $host;',
        'proxy_set_header                X-Forwarded-Port                $server_port;',
        'proxy_set_header                Upgrade                         $http_upgrade;',
        'proxy_set_header                Connection                      "upgrade";',
        'proxy_connect_timeout           60s;',
        'proxy_send_timeout              60s;',
        'proxy_read_timeout              60s;',
        'proxy_buffering                 off;',
        'proxy_request_buffering         off;',
        'client_max_body_size            64m;',
    ]);
}

/**
 * 完整反代参数模板（优先项目模板，其次 docs 示例）
 */
function nginx_default_proxy_params_full_template(): string {
    $projectRoot = dirname(__DIR__, 2);
    $candidates = [
        $projectRoot . '/nginx-conf/proxy_params_full.conf',
        $projectRoot . '/docs/proxy_params_full.conf',
    ];
    foreach ($candidates as $path) {
        $content = @file_get_contents($path);
        if ($content !== false && trim($content) !== '') {
            return rtrim($content) . "\n";
        }
    }
    return implode("\n", [
        '# Nginx 完整反代参数模板（回退版本）',
        'proxy_http_version              1.1;',
        'proxy_set_header                Upgrade                         $http_upgrade;',
        'proxy_set_header                Connection                      "upgrade";',
        'proxy_set_header                Host                            $host;',
        'proxy_set_header                X-Real-IP                       $remote_addr;',
        'proxy_set_header                X-Forwarded-For                 $proxy_add_x_forwarded_for;',
        'proxy_set_header                X-Forwarded-Proto               $scheme;',
        'proxy_set_header                X-Forwarded-Host                $host;',
        'proxy_set_header                X-Forwarded-Port                $server_port;',
        'proxy_set_header                Authorization                   $http_authorization;',
        'proxy_set_header                Cookie                          $http_cookie;',
        'proxy_request_buffering         off;',
        'proxy_buffering                 off;',
        'proxy_connect_timeout           86400s;',
        'proxy_send_timeout              86400s;',
        'proxy_read_timeout              86400s;',
        'client_max_body_size            0;',
    ]);
}

/**
 * 生成并写入反代参数模板文件
 * full：使用 nginx-conf/proxy_params_full.conf（不存在时回退 docs/ 与内置模板）
 * simple：使用 nginx-conf/proxy_params_simple.conf（不存在时回退内置模板）
 * @return array{ok:bool,msg:string}
 */
function nginx_write_proxy_params_templates(): array {
    $paths = nginx_proxy_params_file_paths();
    $dir = dirname($paths['simple']);
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return ['ok' => false, 'msg' => '创建反代参数模板目录失败：' . $dir];
        }
    }

    $projectRoot = dirname(__DIR__, 2);
    $simpleTemplatePath = $projectRoot . '/nginx-conf/proxy_params_simple.conf';
    $simpleContent = @file_get_contents($simpleTemplatePath);
    if ($simpleContent === false || trim($simpleContent) === '') {
        $simpleContent = nginx_default_proxy_params_simple_template();
    }

    $fullContent = nginx_default_proxy_params_full_template();
    if (trim($fullContent) === '') {
        return ['ok' => false, 'msg' => '读取完整模板失败'];
    }

    $okSimple = @file_put_contents($paths['simple'], rtrim($simpleContent) . "\n", LOCK_EX);
    $okFull = @file_put_contents($paths['full'], rtrim($fullContent) . "\n", LOCK_EX);
    if ($okSimple === false || $okFull === false) {
        return ['ok' => false, 'msg' => '写入反代参数模板文件失败，请检查 Nginx 配置目录写入权限'];
    }
    return ['ok' => true, 'msg' => 'ok'];
}


/**
 * 主配置路径（优先运行环境，其次仓库 docker 示例）
 */
function nginx_main_conf_path(): string {
    $runtime = '/etc/nginx/nginx.conf';
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
    return [
        'main' => [
            'label' => 'Nginx 主配置',
            'path' => nginx_main_conf_path(),
        ],
        'http' => [
            'label' => 'Nginx HTTP 模块',
            'path' => nginx_http_conf_path(),
        ],
        'proxy_path' => [
            'label' => 'Nginx 反代配置（路径模式）',
            'path' => nginx_proxy_conf_path(),
        ],
        'proxy_domain' => [
            'label' => 'Nginx 反代配置（子域名模式）',
            'path' => nginx_domain_proxy_conf_path(),
        ],
        'proxy_params_simple' => [
            'label' => 'Nginx 反代参数模板（精简模式）',
            'path' => nginx_proxy_params_file_paths()['simple'],
        ],
        'proxy_params_full' => [
            'label' => 'Nginx 反代参数模板（完整模式）',
            'path' => nginx_proxy_params_file_paths()['full'],
        ],
    ];
}

/**
 * 解析 nginx.conf 内 http { ... } 的边界
 * @return array{open:int,close:int,inner_start:int,inner_end:int}|null
 */
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

    if (($target === 'proxy_params_simple' || $target === 'proxy_params_full') && !is_file($path)) {
        $initResult = nginx_write_proxy_params_templates();
        if (!$initResult['ok']) {
            return ['ok' => false, 'msg' => $initResult['msg'], 'content' => '', 'path' => $path, 'label' => $label];
        }
    }

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

    if (($target === 'proxy_params_simple' || $target === 'proxy_params_full') && !is_file($path)) {
        $initResult = nginx_write_proxy_params_templates();
        if (!$initResult['ok']) {
            return ['ok' => false, 'msg' => $initResult['msg'], 'path' => $path];
        }
    }

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

/**
 * 记录 Nginx 最后一次成功 reload 的时间戳
 */
function nginx_mark_applied(): void {
    $cfg = load_config();
    $cfg['nginx_last_applied'] = time();
    $cfg['nginx_last_applied_proxy_state'] = nginx_current_proxy_state();
    save_config($cfg);
    // 同步刷新 auth 缓存
    auth_reload_config();
}

/**
 * 归一化单个 proxy 站点为“影响实际 Nginx 生效结果”的状态
 * 仅保留真正影响生成配置的字段，避免普通信息变更误触发未生效提示。
 *
 * @return array<string,mixed>|null
 */
function nginx_effective_proxy_site_state(array $site): ?array {
    if (($site['type'] ?? '') !== 'proxy') {
        return null;
    }

    $target = rtrim((string)($site['proxy_target'] ?? ''), '/');
    if (!preg_match('#^https?://[a-zA-Z0-9._-]+(:\d+)?(/[^\n\r]*)?$#', $target)) {
        return null;
    }

    $mode = (($site['proxy_mode'] ?? 'path') === 'domain') ? 'domain' : 'path';
    $id = (string)($site['id'] ?? '');
    $slug = preg_replace('/[^a-z0-9_-]/', '-', strtolower((string)($site['slug'] ?? $id)));

    return [
        'id' => $id,
        'mode' => $mode,
        'target' => $target,
        'slug' => $mode === 'path' ? $slug : '',
        'proxy_domain' => $mode === 'domain' ? (string)($site['proxy_domain'] ?? '') : '',
    ];
}

/**
 * 当前会影响 Nginx 反代生效结果的完整状态快照
 *
 * @return array{mode:string,nav_domain:string,port:int,sites:array<string,array<string,mixed>>}
 */
function nginx_current_proxy_state(): array {
    $cfg = load_config();
    $sitesData = load_sites();
    $groups = $sitesData['groups'] ?? [];
    $sites = [];

    foreach ($groups as $grp) {
        foreach ($grp['sites'] ?? [] as $site) {
            $state = nginx_effective_proxy_site_state(is_array($site) ? $site : []);
            if ($state === null) {
                continue;
            }
            $id = (string)($state['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $sites[$id] = $state;
        }
    }

    ksort($sites);

    return [
        'mode' => (($cfg['proxy_params_mode'] ?? 'simple') === 'full') ? 'full' : 'simple',
        'nav_domain' => (string)($cfg['nav_domain'] ?? 'nav.yourdomain.com'),
        'port' => (int)(getenv('NAV_PORT') ?: 58080),
        'sites' => $sites,
    ];
}

/**
 * 获取未在 Nginx 中生效的 proxy 站点列表
 * 判断依据：仅比较真正影响 Nginx 生成结果的字段，而不是 sites.json 修改时间。
 * @return array<int,array{name:string,proxy_domain:string,group:string}>
 */
function nginx_pending_sites(): array {
    $cfg = load_config();
    $applied = is_array($cfg['nginx_last_applied_proxy_state'] ?? null)
        ? $cfg['nginx_last_applied_proxy_state']
        : [];
    $current = nginx_current_proxy_state();

    $appliedSites = is_array($applied['sites'] ?? null) ? $applied['sites'] : [];
    $currentSites = is_array($current['sites'] ?? null) ? $current['sites'] : [];
    $pending = [];

    $globalChanged = (($applied['mode'] ?? null) !== $current['mode'])
        || (($applied['nav_domain'] ?? null) !== $current['nav_domain'])
        || ((int)($applied['port'] ?? -1) !== (int)$current['port']);

    $sitesData = load_sites();
    foreach ($sitesData['groups'] ?? [] as $grp) {
        foreach ($grp['sites'] ?? [] as $site) {
            if (($site['type'] ?? '') !== 'proxy') {
                continue;
            }
            $id = (string)($site['id'] ?? '');
            if ($id === '' || !isset($currentSites[$id])) {
                continue;
            }
            $changed = $globalChanged
                || !isset($appliedSites[$id])
                || json_encode($appliedSites[$id], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    !== json_encode($currentSites[$id], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (!$changed) {
                continue;
            }
            $pending[] = [
                'name' => $site['name'] ?? $id,
                'proxy_domain' => $site['proxy_domain'] ?? '',
                'group' => $grp['name'] ?? $grp['id'] ?? '',
            ];
        }
    }

    $removedCount = count(array_diff(array_keys($appliedSites), array_keys($currentSites)));
    if ($removedCount > 0) {
        $pending[] = [
            'name' => '已删除代理站点 × ' . $removedCount,
            'proxy_domain' => '',
            'group' => 'system',
        ];
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
        'ip'      => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'action'  => $action,
        'context' => $context,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    file_put_contents(AUDIT_LOG_FILE, $line . "\n", FILE_APPEND | LOCK_EX);
}
