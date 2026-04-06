<?php
/**
 * 计划任务：数据文件、crontab 安装、CLI 执行入口
 */
require_once __DIR__ . '/../../shared/auth.php';

define('SCHEDULED_TASKS_FILE', DATA_DIR . '/scheduled_tasks.json');
define('TASKS_WORKDIR_ROOT', DATA_DIR . '/tasks');
define('DDNS_DISPATCHER_TASK_PREFIX', 'sys_ddns_dispatcher_');

define('PHP_BIN_CANDIDATES', [
    PHP_BINARY,
    '/usr/local/bin/php',
    '/usr/bin/php',
    '/opt/homebrew/bin/php',
    'php',
]);

function task_log_file(string $id): string {
    $id = preg_replace('/[^a-zA-Z0-9_-]/', '', $id);
    return DATA_DIR . '/logs/cron_' . $id . '.log';
}

function task_default_workdir(string $id): string {
    $id = preg_replace('/[^a-zA-Z0-9_-]/', '', $id);
    return TASKS_WORKDIR_ROOT . '/' . $id;
}

function task_resolve_workdir(array $task): string {
    $id   = (string)($task['id'] ?? '');
    $mode = (string)($task['working_dir_mode'] ?? 'project');
    $custom = trim((string)($task['working_dir'] ?? ''));
    if ($mode === 'task') {
        return task_default_workdir($id);
    }
    if ($mode === 'custom' && $custom !== '') {
        return $custom;
    }
    return '/var/www/nav';
}

function task_ensure_workdir(array $task): void {
    $dir = task_resolve_workdir($task);
    $mode = (string)($task['working_dir_mode'] ?? 'project');
    if (($mode === 'task' || $mode === 'custom') && $dir !== '' && !is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
}

function task_rrmdir(string $dir): void {
    if ($dir === '' || !is_dir($dir)) {
        return;
    }
    $items = scandir($dir);
    if (!is_array($items)) {
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . '/' . $item;
        if (is_dir($path) && !is_link($path)) {
            task_rrmdir($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

function task_clear_log(string $id): void {
    $file = task_log_file($id);
    if (file_exists($file)) {
        @unlink($file);
    }
}

function task_cleanup_on_delete(array $task): void {
    $id = (string)($task['id'] ?? '');
    task_clear_log($id);
    $mode = (string)($task['working_dir_mode'] ?? 'project');
    if ($mode !== 'task') {
        return;
    }
    $dir = task_resolve_workdir($task);
    $root = realpath(TASKS_WORKDIR_ROOT);
    $real = realpath($dir);
    if ($root && $real && str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
        task_rrmdir($real);
    }
}

/** @return array{tasks: array<int, array>} */
function load_scheduled_tasks(): array {
    if (!file_exists(SCHEDULED_TASKS_FILE)) {
        return ['tasks' => []];
    }
    $raw = file_get_contents(SCHEDULED_TASKS_FILE);
    $data = json_decode($raw ?: '[]', true);
    if (!is_array($data)) {
        return ['tasks' => []];
    }
    if (!isset($data['tasks']) || !is_array($data['tasks'])) {
        return ['tasks' => []];
    }
    return $data;
}

function save_scheduled_tasks(array $data): void {
    if (!isset($data['tasks']) || !is_array($data['tasks'])) {
        $data['tasks'] = [];
    }
    file_put_contents(SCHEDULED_TASKS_FILE,
        json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        LOCK_EX);
}

function cron_is_system_task(array $task): bool {
    return !empty($task['is_system']);
}

function cron_is_ddns_dispatcher_id(string $id): bool {
    return str_starts_with($id, DDNS_DISPATCHER_TASK_PREFIX);
}

function cron_ddns_dispatcher_id(string $schedule): string {
    return DDNS_DISPATCHER_TASK_PREFIX . substr(sha1($schedule), 0, 12);
}

function cron_php_binary(): string {
    foreach (PHP_BIN_CANDIDATES as $candidate) {
        $candidate = trim((string)$candidate);
        if ($candidate === '') {
            continue;
        }
        if ($candidate === 'php') {
            return 'php';
        }
        if (is_file($candidate) && is_executable($candidate)) {
            return $candidate;
        }
    }
    return 'php';
}

function cron_ddns_dispatcher_command(): string {
    return escapeshellcmd(cron_php_binary()) . ' /var/www/nav/cli/ddns_sync.php';
}

function cron_ddns_group_task_names(array $groupTasks): string {
    $names = array_map(fn($row) => (string)(($row['name'] ?? '') !== '' ? $row['name'] : ($row['id'] ?? '')), $groupTasks);
    return implode('、', array_filter($names, fn($v) => $v !== ''));
}

function cron_format_ddns_result_line(array $task, array $run): string {
    $name = (string)($run['task_name'] ?? $task['name'] ?? $task['id'] ?? '-');
    $state = (string)($run['final_state'] ?? ($run['ok'] ? 'updated' : 'failed'));
    $stateLabel = match ($state) {
        'skipped_unchanged' => 'SKIP',
        'updated' => 'UPDATED',
        'failed_update', 'failed_source', 'failed' => 'FAILED',
        default => ($run['ok'] ? 'OK' : 'FAILED'),
    };
    $domain = (string)($run['domain'] ?? $task['target']['domain'] ?? '-');
    $recordType = strtoupper((string)($run['record_type'] ?? $task['target']['record_type'] ?? 'A'));
    $value = trim((string)($run['value'] ?? ''));
    $sourceLabel = (string)($run['source_label'] ?? ddns_source_label($task));
    $message = trim((string)($run['msg'] ?? ''));
    $parts = [
        '[' . date('H:i:s') . '] ',
        $stateLabel,
        ' | 任务=', $name,
        ' | 来源=', $sourceLabel,
        ' | 记录=', $domain, ' ', $recordType,
    ];
    if ($value !== '') {
        $parts[] = ' | 值=';
        $parts[] = $value;
    }
    if ($message !== '') {
        $parts[] = ' | 说明=';
        $parts[] = $message;
    }
    return implode('', $parts);
}

function cron_sync_ddns_dispatcher_task(): array {
    require_once __DIR__ . '/ddns_lib.php';
    $scheduled = load_scheduled_tasks();
    $ddnsData = ddns_load_tasks();
    $cronGroups = [];
    foreach ($ddnsData['tasks'] ?? [] as $task) {
        if (empty($task['enabled'])) {
            continue;
        }
        $cron = trim((string)($task['schedule']['cron'] ?? ''));
        if ($cron !== '' && cron_validate_schedule($cron)) {
            $cronGroups[$cron][] = [
                'id' => (string)($task['id'] ?? ''),
                'name' => (string)($task['name'] ?? ''),
            ];
        }
    }

    $existingDispatchers = [];
    foreach ($scheduled['tasks'] as $idx => $task) {
        $id = (string)($task['id'] ?? '');
        if (cron_is_ddns_dispatcher_id($id)) {
            $existingDispatchers[$id] = ['idx' => $idx, 'task' => $task];
        }
    }

    $desiredIds = [];
    foreach ($cronGroups as $cron => $groupTasks) {
        $id = cron_ddns_dispatcher_id($cron);
        $desiredIds[$id] = true;
        $taskRow = [
            'id' => $id,
            'name' => 'DDNS 调度器 [' . $cron . ']',
            'enabled' => true,
            'schedule' => $cron,
            'command' => cron_ddns_dispatcher_command(),
            'working_dir_mode' => 'project',
            'working_dir' => '',
            'is_system' => true,
            'description' => '由 DDNS 页面自动维护，用于调度相同 cron 的 DDNS 任务',
            'meta' => [
                'ddns_crons' => [$cron],
                'ddns_tasks' => $groupTasks,
                'group_label' => cron_ddns_group_task_names($groupTasks),
            ],
        ];
        if (isset($existingDispatchers[$id])) {
            $existing = $existingDispatchers[$id]['task'];
            $taskRow['last_run'] = $existing['last_run'] ?? null;
            $taskRow['last_code'] = $existing['last_code'] ?? null;
            $taskRow['last_output'] = $existing['last_output'] ?? null;
            $scheduled['tasks'][$existingDispatchers[$id]['idx']] = $taskRow;
        } else {
            $scheduled['tasks'][] = $taskRow;
        }
    }

    if ($existingDispatchers !== []) {
        $scheduled['tasks'] = array_values(array_filter($scheduled['tasks'], function ($task) use ($desiredIds) {
            $id = (string)($task['id'] ?? '');
            if (!cron_is_ddns_dispatcher_id($id)) {
                return true;
            }
            return isset($desiredIds[$id]);
        }));
    }

    save_scheduled_tasks($scheduled);
    ksort($cronGroups, SORT_STRING);
    return [
        'enabled' => $cronGroups !== [],
        'groups' => $cronGroups,
        'dispatcher_ids' => array_keys($desiredIds),
    ];
}

function cron_validate_schedule(string $line): bool {
    $line = trim($line);
    if ($line === '') {
        return false;
    }
    $parts = preg_split('/\s+/', $line);
    return count($parts) >= 5 && $parts[0] !== '' && $parts[1] !== '' && $parts[2] !== '' && $parts[3] !== '' && $parts[4] !== '';
}

function cron_regenerate(): array {
    cron_sync_ddns_dispatcher_task();
    $lines   = [];
    $lines[] = '# simple-homepage generated — do not edit by hand';
    $lines[] = 'SHELL=/bin/bash';
    $lines[] = 'PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin';

    $data = load_scheduled_tasks();
    foreach ($data['tasks'] ?? [] as $t) {
        if (empty($t['enabled'])) {
            continue;
        }
        $id = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($t['id'] ?? ''));
        if ($id === '') {
            continue;
        }
        $sched = trim((string)($t['schedule'] ?? ''));
        if (!cron_validate_schedule($sched)) {
            continue;
        }
        $log = DATA_DIR . '/logs/cron_' . $id . '.log';
        $cmd = escapeshellcmd(cron_php_binary()) . ' /var/www/nav/cli/run_scheduled_task.php ' . escapeshellarg($id);
        $lines[] = $sched . ' ' . $cmd . ' >> ' . $log . ' 2>&1';
    }

    $content = implode("\n", $lines) . "\n";
    return cron_install_stdin($content);
}

/**
 * @return array{ok:bool,msg:string}
 */
function cron_install_stdin(string $content): array {
    $env = [
        'HOME'    => '/home/navwww',
        'USER'    => 'navwww',
        'LOGNAME' => 'navwww',
        'PATH'    => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
        'SHELL'   => '/bin/bash',
    ];
    $des = [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']];

    // 尝试候选命令：直接 crontab -> sudo crontab -> 直接写文件
    $candidates = ['crontab -', 'sudo crontab -'];
    foreach ($candidates as $cmd) {
        $proc = proc_open($cmd, $des, $pipes, '/tmp', $env);
        if (!is_resource($proc)) continue;
        fwrite($pipes[0], $content);
        fclose($pipes[0]);
        $out  = stream_get_contents($pipes[1]);
        $err  = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        if ($code === 0) {
            return ['ok' => true, 'msg' => 'crontab 已更新'];
        }
        // Permission denied → 换下一个候选
        if (stripos($err, 'permission') !== false || stripos($err, 'not allowed') !== false) {
            continue;
        }
        return ['ok' => false, 'msg' => trim($err ?: $out ?: "crontab 退出码 $code")];
    }

    // 最终 fallback：直接写 crontab 文件（需要目录可写）
    $crontab_dir  = '/var/spool/cron/crontabs';
    $crontab_file = $crontab_dir . '/navwww';
    if (is_dir($crontab_dir) && is_writable($crontab_dir)) {
        $r = file_put_contents($crontab_file, $content, LOCK_EX);
        if ($r !== false) {
            chmod($crontab_file, 0600);
            return ['ok' => true, 'msg' => 'crontab 已更新（直接写文件）'];
        }
    }

    return ['ok' => false, 'msg' => 'crontab 安装失败：无权限执行 crontab 命令，且无法写入 crontab 文件。请重建容器。'];
}

/**
 * 执行任务并更新 JSON（供 Web 立即执行，不退出进程）
 * @return array{ok:bool,code:int,output:string,msg:string}
 */
function cron_execute_task(string $id): array {
    $id = preg_replace('/[^a-zA-Z0-9_-]/', '', $id);
    if ($id === '') {
        return ['ok' => false, 'code' => -1, 'output' => '', 'msg' => '无效的任务 ID'];
    }
    $data = load_scheduled_tasks();
    $idx  = -1;
    foreach ($data['tasks'] ?? [] as $i => $t) {
        if (($t['id'] ?? '') === $id) {
            $idx = $i;
            break;
        }
    }
    if ($idx < 0) {
        return ['ok' => false, 'code' => -1, 'output' => '', 'msg' => '任务不存在'];
    }
    $task = $data['tasks'][$idx];

    if (cron_is_ddns_dispatcher_id($id)) {
        require_once __DIR__ . '/ddns_lib.php';
        $targetCron = trim((string)($task['schedule'] ?? ''));
        $results = [];
        $hasFail = false;
        foreach (ddns_load_tasks()['tasks'] ?? [] as $ddnsTask) {
            if (empty($ddnsTask['enabled'])) {
                continue;
            }
            $cron = trim((string)($ddnsTask['schedule']['cron'] ?? ''));
            if ($cron !== $targetCron) {
                continue;
            }
            $run = ddns_run_task($ddnsTask);
            $results[] = cron_format_ddns_result_line($ddnsTask, $run);
            if (!$run['ok']) {
                $hasFail = true;
            }
        }
        $output = empty($results)
            ? '当前分组下没有可执行的 DDNS 任务'
            : implode("\n", $results);
        $code = $hasFail ? 1 : 0;
        $log_dir  = DATA_DIR . '/logs';
        $log_file = $log_dir . '/cron_' . $id . '.log';
        if (!is_dir($log_dir)) {
            mkdir($log_dir, 0755, true);
        }
        $stamp = date('[Y-m-d H:i:s]') . ' [exit:' . $code . '] --- DDNS 分组执行 ---';
        file_put_contents($log_file, $stamp . "\n" . rtrim($output) . "\n", FILE_APPEND | LOCK_EX);
        $data['tasks'][$idx]['last_run'] = date('Y-m-d H:i:s');
        $data['tasks'][$idx]['last_code'] = $code;
        $data['tasks'][$idx]['last_output'] = mb_substr($output, 0, 8000);
        save_scheduled_tasks($data);
        return ['ok' => $code === 0, 'code' => $code, 'output' => $output, 'msg' => $code === 0 ? '执行完成' : "退出码 $code"];
    }

    $cmd = (string)($task['command'] ?? '');
    if ($cmd === '') {
        return ['ok' => false, 'code' => -1, 'output' => '', 'msg' => '命令为空'];
    }
    // 统一换行符：兼容 Windows \r\n 和 Unix \n
    $script = str_replace("\r\n", "\n", str_replace("\r", "\n", $cmd));
    $workdir = task_resolve_workdir($task);
    task_ensure_workdir($task);
    // 在脚本头部设置工作目录
    $script = "cd " . escapeshellarg($workdir) . "\n" . $script;

    // 用 proc_open 把脚本通过 stdin 管道传给 bash
    // bash 读 stdin → 完整多行脚本支持，无需转义，无注入风险
    $desc = [
        0 => ['pipe', 'r'],   // stdin  → 写入脚本内容
        1 => ['pipe', 'w'],   // stdout → 收集输出
        2 => ['pipe', 'w'],   // stderr → 合并到输出
    ];
    $env = [
        'HOME'  => '/home/navwww',
        'USER'  => 'navwww',
        'PATH'  => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
        'SHELL' => '/bin/bash',
        'LANG'  => 'en_US.UTF-8',
        'TASK_ID' => $id,
        'TASK_NAME' => (string)($task['name'] ?? ''),
        'TASK_WORKDIR' => $workdir,
    ];
    $proc = proc_open('/bin/bash', $desc, $pipes, $workdir, $env);
    $output = '';
    $code   = -1;
    if (is_resource($proc)) {
        fwrite($pipes[0], $script);
        fclose($pipes[0]);
        // 合并 stdout + stderr（保持顺序近似）
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $start = microtime(true);
        $timeout = 300; // 最多等待 5 分钟
        while (true) {
            $r = [$pipes[1], $pipes[2]];
            $w = $e = null;
            $changed = @stream_select($r, $w, $e, 1);
            if ($changed > 0) {
                foreach ($r as $s) {
                    $chunk = fread($s, 8192);
                    if ($chunk !== false && $chunk !== '') {
                        $output .= $chunk;
                    }
                }
            }
            if (feof($pipes[1]) && feof($pipes[2])) break;
            if ((microtime(true) - $start) > $timeout) {
                $output .= "\n[超时，已强制终止]";
                proc_terminate($proc);
                break;
            }
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
    } else {
        $output = '[无法启动 bash 进程]';
    }
    // 统一输出换行为 \n
    $output = str_replace("\r\n", "\n", $output);

    // 写日志文件（task_log_page 读取此文件）
    $log_dir  = DATA_DIR . '/logs';
    $log_file = $log_dir . '/cron_' . $id . '.log';
    if (!is_dir($log_dir)) {
        mkdir($log_dir, 0755, true);
    }
    $stamp = date('[Y-m-d H:i:s]') . ' [exit:' . $code . '] --- 手动执行 ---';
    file_put_contents($log_file,
        $stamp . "\n" . rtrim($output) . "\n",
        FILE_APPEND | LOCK_EX);

    $data['tasks'][$idx]['last_run']    = date('Y-m-d H:i:s');
    $data['tasks'][$idx]['last_code']   = $code;
    $data['tasks'][$idx]['last_output'] = mb_substr($output, 0, 8000);
    save_scheduled_tasks($data);
    return ['ok' => $code === 0, 'code' => $code, 'output' => $output, 'msg' => $code === 0 ? '执行完成' : "退出码 $code"];
}

/**
 * 计算 cron 表达式的下一次运行时间（精确到分钟，最多向前查找 366 天）
 * 返回 Y-m-d H:i 字符串，或 false 表示无效表达式
 */
function cron_next_run(string $expr): string|false {
    $parts = preg_split('/\s+/', trim($expr));
    if (count($parts) < 5) return false;
    [$min, $hour, $dom, $mon, $dow] = $parts;

    $expand = function(string $field, int $lo, int $hi): array {
        $vals = [];
        foreach (explode(',', $field) as $part) {
            if ($part === '*') {
                for ($i = $lo; $i <= $hi; $i++) $vals[] = $i;
            } elseif (strpos($part, '/') !== false) {
                [$range, $step] = explode('/', $part, 2);
                $step = max(1, (int)$step);
                $start = ($range === '*') ? $lo : (int)$range;
                for ($i = $start; $i <= $hi; $i += $step) $vals[] = $i;
            } elseif (strpos($part, '-') !== false) {
                [$a, $b] = explode('-', $part, 2);
                for ($i = (int)$a; $i <= (int)$b; $i++) $vals[] = $i;
            } else {
                $vals[] = (int)$part;
            }
        }
        return array_unique($vals);
    };

    $mins  = $expand($min,  0, 59);
    $hours = $expand($hour, 0, 23);
    $doms  = $expand($dom,  1, 31);
    $mons  = $expand($mon,  1, 12);
    $dows  = $expand($dow,  0,  6);

    $now = time();
    $t   = $now - ($now % 60) + 60; // 下一分钟起
    $end = $now + 366 * 86400;
    while ($t < $end) {
        $mn = (int)date('n', $t);
        $md = (int)date('j', $t);
        $dw = (int)date('w', $t);
        $hr = (int)date('G', $t);
        $mi = (int)date('i', $t);
        if (in_array($mn, $mons) && in_array($md, $doms) && in_array($dw, $dows)
            && in_array($hr, $hours) && in_array($mi, $mins)) {
            return date('Y-m-d H:i', $t);
        }
        $t += 60;
    }
    return false;
}

/**
 * 切换任务启用状态，返回新状态 true/false
 */
function task_toggle_enabled(string $id): bool|null {
    $id   = preg_replace('/[^a-zA-Z0-9_-]/', '', $id);
    $data = load_scheduled_tasks();
    foreach ($data['tasks'] as &$t) {
        if (($t['id'] ?? '') === $id) {
            $t['enabled'] = !($t['enabled'] ?? false);
            save_scheduled_tasks($data);
            cron_regenerate();
            return (bool)$t['enabled'];
        }
    }
    return null;
}

/**
 * 读取任务日志文件，返回指定页的100行（page 从1开始），以及总行数
 * @return array{lines:string[],total:int,page:int,pages:int}
 */
function task_log_page(string $id, int $page = 1): array {
    $id   = preg_replace('/[^a-zA-Z0-9_-]/', '', $id);
    $file = DATA_DIR . '/logs/cron_' . $id . '.log';
    if (!file_exists($file)) {
        return ['lines' => [], 'total' => 0, 'page' => 1, 'pages' => 0];
    }
    $all   = file($file, FILE_IGNORE_NEW_LINES);
    $total = count($all);
    $per   = 100;
    $pages = max(1, (int)ceil($total / $per));
    $page  = max(1, min($page, $pages));
    // 顺序输出（旧的在前，新的在后），默认最后一页
    $slice = array_slice($all, ($page - 1) * $per, $per);
    return ['lines' => $slice, 'total' => $total, 'page' => $page, 'pages' => $pages];
}

function cron_run_task_by_id(string $id): void {
    $id = preg_replace('/[^a-zA-Z0-9_-]/', '', $id);
    if ($id === '') {
        fwrite(STDERR, "invalid task id\n");
        exit(1);
    }
    $r = cron_execute_task($id);
    echo $r['output'] . "\n";
    exit($r['ok'] ? 0 : 1);
}
