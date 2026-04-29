<?php
/**
 * 计划任务：数据文件、crontab 安装、CLI 执行入口
 */
require_once __DIR__ . '/../../shared/auth.php';
if (!function_exists('load_config')) {
    require_once __DIR__ . '/functions.php';
}

define('SCHEDULED_TASKS_FILE', DATA_DIR . '/scheduled_tasks.json');
define('TASKS_WORKDIR_ROOT', DATA_DIR . '/tasks');
define('DDNS_DISPATCHER_TASK_PREFIX', 'sys_ddns_dispatcher_');
define('TASK_DISPATCH_LOG_FILE', DATA_DIR . '/logs/task_dispatch.log');
define('SCHEDULED_TASKS_LOCK_FILE', DATA_DIR . '/.scheduled_tasks.lock');
define('TASK_EXECUTION_TIMEOUT', 7200);
define('TASK_EXECUTION_KILL_AFTER', 10);

define('PHP_BIN_CANDIDATES', [
    '/usr/local/bin/php',
    '/usr/bin/php',
    '/opt/homebrew/bin/php',
    'php',
]);

function task_log_file(string $id): string {
    $id = preg_replace('/[^a-zA-Z0-9_-]/', '', $id);
    if ($id === '') {
        return task_log_path_from_filename(task_default_log_filename(''));
    }
    $data = load_scheduled_tasks();
    $task = task_find_by_id($id, $data['tasks'] ?? []);
    if (is_array($task)) {
        return task_log_file_for_task($task, $data['tasks'] ?? []);
    }
    return task_log_path_from_filename(task_default_log_filename($id));
}

function task_script_dir(): string {
    return TASKS_WORKDIR_ROOT;
}

function task_legacy_script_filename(string $id): string {
    $id = preg_replace('/[^a-zA-Z0-9_-]/', '', $id);
    if ($id === '') {
        $id = 'task';
    }
    return $id . '.sh';
}

function task_script_path_from_filename(string $filename): string {
    return rtrim(task_script_dir(), '/') . '/' . $filename;
}

function task_log_path_from_filename(string $filename): string {
    return rtrim(task_script_dir(), '/') . '/' . $filename;
}

function task_lock_file(string $id): string {
    $id = preg_replace('/[^a-zA-Z0-9_-]/', '', $id);
    return DATA_DIR . '/logs/cron_' . $id . '.lock';
}

function task_script_file(string $id): string {
    return task_script_path_from_filename(task_legacy_script_filename($id));
}

function task_legacy_log_file(string $id): string {
    $id = preg_replace('/[^a-zA-Z0-9_-]/', '', $id);
    return DATA_DIR . '/logs/cron_' . $id . '.log';
}

function task_log_dir(): string {
    return DATA_DIR . '/logs';
}

function task_ensure_log_dir(): void {
    if (!is_dir(task_log_dir())) {
        @mkdir(task_log_dir(), 0755, true);
    }
}

function task_dispatch_log(string $message, array $context = []): void {
    task_ensure_log_dir();
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message;
    if ($context !== []) {
        $line .= ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    @file_put_contents(TASK_DISPATCH_LOG_FILE, $line . "\n", FILE_APPEND | LOCK_EX);
}

function task_execution_source(): string {
    $source = trim((string)getenv('TASK_RUN_SOURCE'));
    return $source === 'cron' ? 'cron' : 'manual';
}

function task_execution_label(): string {
    return task_execution_source() === 'cron' ? '定时执行' : '手动执行';
}

function task_project_root(): string {
    return realpath(__DIR__ . '/../..') ?: '/var/www/nav';
}

function task_default_workdir(string $id = ''): string {
    return TASKS_WORKDIR_ROOT;
}

function task_normalize_workdir_mode(?string $mode, string $default = 'task'): string {
    return 'task';
}

function task_resolve_workdir(array $task): string {
    return task_default_workdir((string)($task['id'] ?? ''));
}

function task_ensure_workdir_root(): void {
    if (!is_dir(TASKS_WORKDIR_ROOT)) {
        @mkdir(TASKS_WORKDIR_ROOT, 0755, true);
    }
}

function task_ensure_workdir(array $task): void {
    task_ensure_workdir_root();
    $dir = task_resolve_workdir($task);
    if ($dir !== '' && !is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
}

function task_normalize_script_contents(string $cmd): string {
    $cmd = task_normalize_editor_contents($cmd);
    return rtrim($cmd, "\n") . "\n";
}

function task_maybe_fix_doubled_blank_lines(string $cmd): string {
    $lines = explode("\n", $cmd);
    $lineCount = count($lines);
    if ($lineCount < 4) {
        return $cmd;
    }

    $alternatingBlanks = 0;
    $checkedPairs = 0;
    for ($i = 1; $i < $lineCount; $i += 2) {
        $checkedPairs++;
        if (trim($lines[$i]) === '') {
            $alternatingBlanks++;
        }
    }

    if ($checkedPairs < 2 || $alternatingBlanks !== $checkedPairs) {
        return $cmd;
    }

    $collapsed = [];
    for ($i = 0; $i < $lineCount; $i += 2) {
        $collapsed[] = $lines[$i];
    }
    if ($lineCount % 2 === 0 && trim((string)end($lines)) === '') {
        $collapsed[] = '';
    }
    return implode("\n", $collapsed);
}

function task_normalize_editor_contents(string $cmd): string {
    $cmd = preg_replace("/\r+\n?/", "\n", $cmd);
    $cmd = is_string($cmd) ? $cmd : '';
    $cmd = task_maybe_fix_doubled_blank_lines($cmd);
    return is_string($cmd) ? $cmd : '';
}

function task_runtime_tmp_dir(array $task): string {
    return rtrim(task_resolve_workdir($task), '/') . '/.tmp';
}

function task_ensure_runtime_tmp_dir(array $task): string {
    task_ensure_workdir($task);
    $dir = task_runtime_tmp_dir($task);
    if ($dir !== '' && !is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir;
}

function task_command_mentions_cfst(string $cmd): bool {
    return preg_match('/(^|[\\s\\/])(?:\\.\\/)?cfst(?:[-._A-Za-z0-9]*)\\b/i', $cmd) === 1;
}

function task_try_prepare_cfst_runtime(): array {
    if (!file_exists('/tmp/cfst.lock')) {
        return ['ok' => true, 'msg' => ''];
    }
    $cmd = 'sudo -n /usr/local/bin/nav-task-compat cfst >/dev/null 2>&1';
    $out = [];
    $code = 0;
    @exec($cmd, $out, $code);
    if ($code === 0 && !file_exists('/tmp/cfst.lock')) {
        return ['ok' => true, 'msg' => '已清理 /tmp/cfst.lock'];
    }
    return ['ok' => false, 'msg' => '检测到 /tmp/cfst.lock 且当前运行用户无权清理；这通常是之前以 root 手动运行 cfst 留下的锁文件'];
}

function task_try_cleanup_stale_lock(string $id): void {
    $id = preg_replace('/[^a-zA-Z0-9_-]/', '', $id);
    if ($id === '') {
        return;
    }
    $cmd = 'sudo -n /usr/local/bin/nav-task-compat lock ' . escapeshellarg($id) . ' >/dev/null 2>&1';
    @exec($cmd, $out, $code);
    if ($code === 0) {
        task_dispatch_log('stale task lock cleaned', ['id' => $id]);
    }
}

function task_is_valid_script_filename(string $filename): bool {
    $filename = trim($filename);
    if ($filename === '' || basename($filename) !== $filename) {
        return false;
    }
    return preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*\.sh$/', $filename) === 1;
}

function task_name_script_filename_candidate(string $name): string {
    $name = trim($name);
    if ($name === '') {
        return '';
    }
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $name)) {
        return '';
    }
    return $name . '.sh';
}

function task_default_script_filename(string $id): string {
    $clean = preg_replace('/[^a-zA-Z0-9_-]/', '', $id);
    if ($clean === '') {
        $clean = substr(md5(uniqid((string)mt_rand(), true)), 0, 12);
    }
    return 'task_' . $clean . '.sh';
}

function task_log_filename_from_script_filename(string $scriptFilename): string {
    $scriptFilename = trim($scriptFilename);
    if ($scriptFilename === '') {
        return '';
    }
    return preg_replace('/\.sh$/i', '.log', $scriptFilename) ?: '';
}

function task_default_log_filename(string $id): string {
    $clean = preg_replace('/[^a-zA-Z0-9_-]/', '', $id);
    if ($clean === '') {
        $clean = substr(md5(uniqid((string)mt_rand(), true)), 0, 12);
    }
    return 'task_' . $clean . '.log';
}

function task_script_filename_conflicts(string $filename, string $taskId, array $allTasks = []): bool {
    foreach ($allTasks as $row) {
        if (!is_array($row) || (string)($row['id'] ?? '') === $taskId) {
            continue;
        }
        $other = trim((string)($row['script_filename'] ?? ''));
        if ($other !== '' && $other === $filename) {
            return true;
        }
    }
    return false;
}

function task_resolve_script_filename(array $task, array $allTasks = []): string {
    $id = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($task['id'] ?? ''));
    $explicit = trim((string)($task['script_filename'] ?? ''));
    if (task_is_valid_script_filename($explicit)) {
        return $explicit;
    }

    $legacy = task_legacy_script_filename($id);
    if ($id !== '' && is_file(task_script_path_from_filename($legacy))) {
        return $legacy;
    }

    $candidate = task_name_script_filename_candidate((string)($task['name'] ?? ''));
    if ($candidate !== '' && !task_script_filename_conflicts($candidate, $id, $allTasks)) {
        return $candidate;
    }

    return task_default_script_filename($id);
}

function task_script_file_for_task(array $task, array $allTasks = []): string {
    return task_script_path_from_filename(task_resolve_script_filename($task, $allTasks));
}

function task_resolve_log_filename(array $task, array $allTasks = []): string {
    $id = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($task['id'] ?? ''));
    $fromScript = task_log_filename_from_script_filename(task_resolve_script_filename($task, $allTasks));
    if ($fromScript !== '') {
        return $fromScript;
    }
    return task_default_log_filename($id);
}

function task_log_file_for_task(array $task, array $allTasks = []): string {
    return task_log_path_from_filename(task_resolve_log_filename($task, $allTasks));
}

function task_find_by_id(string $id, array $tasks): ?array {
    foreach ($tasks as $task) {
        if (is_array($task) && (string)($task['id'] ?? '') === $id) {
            return $task;
        }
    }
    return null;
}

function task_existing_log_file(string $id): string {
    $id = preg_replace('/[^a-zA-Z0-9_-]/', '', $id);
    if ($id === '') {
        return '';
    }

    $data = load_scheduled_tasks();
    $task = task_find_by_id($id, $data['tasks'] ?? []);
    if (is_array($task)) {
        $current = task_log_file_for_task($task, $data['tasks'] ?? []);
        if (is_file($current)) {
            return $current;
        }
    }

    $default = task_log_path_from_filename(task_default_log_filename($id));
    if (is_file($default)) {
        return $default;
    }

    $legacy = task_legacy_log_file($id);
    if (is_file($legacy)) {
        return $legacy;
    }

    return is_array($task)
        ? task_log_file_for_task($task, $data['tasks'] ?? [])
        : $default;
}

function task_read_script_contents_for_task(array $task, array $allTasks = []): string {
    $path = task_script_file_for_task($task, $allTasks);
    if (!is_file($path)) {
        return '';
    }
    $raw = @file_get_contents($path);
    if ($raw === false) {
        return '';
    }
    return str_replace("\r\n", "\n", str_replace("\r", "\n", $raw));
}

function task_resolve_command_text(array $task): string {
    $fromScript = task_read_script_contents_for_task($task);
    if ($fromScript !== '') {
        return task_normalize_editor_contents($fromScript);
    }
    return task_normalize_editor_contents((string)($task['command'] ?? ''));
}

function task_write_script_file(array $task, string $cmd, array $allTasks = []): array {
    $id = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($task['id'] ?? ''));
    if ($id === '') {
        return ['ok' => false, 'msg' => '无效的任务 ID'];
    }
    task_ensure_workdir_root();
    $filename = task_resolve_script_filename($task, $allTasks);
    $path = task_script_path_from_filename($filename);
    if (@file_put_contents($path, task_normalize_script_contents($cmd), LOCK_EX) === false) {
        return ['ok' => false, 'msg' => '任务脚本写入失败'];
    }
    @chmod($path, 0700);
    return ['ok' => true, 'path' => $path, 'filename' => $filename];
}

function task_sync_script_for_task(array $task, array $allTasks = []): array {
    $id = (string)($task['id'] ?? '');
    if ($id === '' || cron_is_ddns_dispatcher_id($id)) {
        return ['ok' => true, 'path' => ''];
    }
    $cmd = (string)($task['command'] ?? '');
    $filename = task_resolve_script_filename($task, $allTasks);
    $path = task_script_path_from_filename($filename);
    if (trim($cmd) === '') {
        if (is_file($path)) {
            @unlink($path);
        }
        return ['ok' => true, 'path' => $path, 'filename' => $filename];
    }
    return task_write_script_file($task, $cmd, $allTasks);
}

function task_sync_scripts_from_scheduled_tasks(array $data, bool $remove_orphans = false): void {
    task_ensure_workdir_root();
    $expected = [];
    foreach ($data['tasks'] ?? [] as $task) {
        $id = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($task['id'] ?? ''));
        if ($id === '' || cron_is_ddns_dispatcher_id($id)) {
            continue;
        }
        $path = task_script_file_for_task($task, $data['tasks'] ?? []);
        $expected[$path] = true;
        if (trim((string)($task['command'] ?? '')) !== '') {
            task_write_script_file($task, (string)($task['command'] ?? ''), $data['tasks'] ?? []);
        } elseif (is_file($path)) {
            @unlink($path);
        }
    }

    if (!$remove_orphans) {
        return;
    }
    foreach (glob(TASKS_WORKDIR_ROOT . '/*.sh') ?: [] as $path) {
        if (!isset($expected[$path])) {
            @unlink($path);
        }
    }
}

function task_file_size(string $path): int {
    clearstatcache(true, $path);
    if (!file_exists($path)) {
        return 0;
    }
    $size = @filesize($path);
    return $size === false ? 0 : (int)$size;
}

function task_read_file_segment(string $path, int $offset): string {
    $size = task_file_size($path);
    if ($size <= $offset) {
        return '';
    }
    $chunk = @file_get_contents($path, false, null, $offset);
    if ($chunk === false) {
        return '';
    }
    return str_replace("\r\n", "\n", str_replace("\r", "\n", $chunk));
}

function scheduled_tasks_lock_exclusive() {
    $dir = dirname(SCHEDULED_TASKS_LOCK_FILE);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $handle = @fopen(SCHEDULED_TASKS_LOCK_FILE, 'c');
    if (!is_resource($handle)) {
        return null;
    }
    if (!@flock($handle, LOCK_EX)) {
        @fclose($handle);
        return null;
    }
    return $handle;
}

function scheduled_tasks_unlock($handle): void {
    if (!is_resource($handle)) {
        return;
    }
    @flock($handle, LOCK_UN);
    @fclose($handle);
}

function task_proc_send_signal(int $pid, int $signal): bool {
    if ($pid <= 0) {
        return false;
    }
    if (function_exists('posix_kill')) {
        return @posix_kill($pid, $signal);
    }
    $out = [];
    $code = 0;
    @exec('kill -' . (int)$signal . ' ' . (int)$pid . ' >/dev/null 2>&1', $out, $code);
    return $code === 0;
}

function task_lock_write_pid($handle, int $pid): void {
    if (!is_resource($handle)) {
        return;
    }
    @rewind($handle);
    @ftruncate($handle, 0);
    @fwrite($handle, (string)$pid);
    @fflush($handle);
}

function task_lock_read_pid(string $path): ?int {
    if (!file_exists($path)) {
        return null;
    }
    $raw = @file_get_contents($path);
    if ($raw === false) {
        return null;
    }
    $pid = (int)trim($raw);
    return $pid > 0 ? $pid : null;
}

function task_pid_exists(?int $pid): bool {
    if ($pid === null || $pid <= 0) {
        return false;
    }
    if (function_exists('posix_kill')) {
        return @posix_kill($pid, 0);
    }
    $out = [];
    $code = 0;
    @exec('kill -0 ' . (int)$pid . ' >/dev/null 2>&1', $out, $code);
    return $code === 0;
}
function task_execution_timeout(): int {
    $cfg = load_config();
    $val = $cfg['task_execution_timeout'] ?? TASK_EXECUTION_TIMEOUT;
    if ($val === '' || $val === null) {
        return TASK_EXECUTION_TIMEOUT;
    }
    $n = (int)$val;
    if ($n < 0) {
        return TASK_EXECUTION_TIMEOUT;
    }
    return $n;
}


function task_try_acquire_execution_lock(string $id): array {
    task_ensure_log_dir();
    $path = task_lock_file($id);
    for ($attempt = 0; $attempt < 2; $attempt++) {
        $handle = @fopen($path, 'c+');
        if (is_resource($handle)) {
            if (!@flock($handle, LOCK_EX | LOCK_NB)) {
                @fclose($handle);
                return ['ok' => false, 'msg' => '任务已在运行中', 'handle' => null];
            }
            $pid = task_lock_read_pid($path);
            if ($pid !== null && !task_pid_exists($pid) && $attempt === 0) {
                @flock($handle, LOCK_UN);
                @fclose($handle);
                task_try_cleanup_stale_lock($id);
                clearstatcache(true, $path);
                continue;
            }
            task_lock_write_pid($handle, (int)getmypid());
            return ['ok' => true, 'msg' => 'ok', 'handle' => $handle];
        }
        if ($attempt === 0 && file_exists($path)) {
            task_try_cleanup_stale_lock($id);
            clearstatcache(true, $path);
            continue;
        }
        return ['ok' => false, 'msg' => '任务锁文件打开失败', 'handle' => null];
    }
    return ['ok' => false, 'msg' => '任务锁文件打开失败', 'handle' => null];
}

function task_release_execution_lock($handle): void {
    if (!is_resource($handle)) {
        return;
    }
    @flock($handle, LOCK_UN);
    @fclose($handle);
}

function task_is_execution_locked(string $id): bool {
    $lock = task_try_acquire_execution_lock($id);
    if (!($lock['ok'] ?? false)) {
        return ($lock['msg'] ?? '') === '任务已在运行中';
    }
    task_release_execution_lock($lock['handle'] ?? null);
    return false;
}

function task_append_skip_log(string $id, string $message): void {
    $file = task_log_file($id);
    task_ensure_workdir_root();
    $line = '[' . date('Y-m-d H:i:s') . '] [skip] --- ' . task_execution_label() . ' --- ' . $message;
    @file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX);
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
    $data = load_scheduled_tasks();
    $task = task_find_by_id($id, $data['tasks'] ?? []);
    $paths = [
        task_log_file($id),
        task_log_path_from_filename(task_default_log_filename($id)),
        task_legacy_log_file($id),
    ];
    if (is_array($task)) {
        $paths[] = task_log_file_for_task($task, $data['tasks'] ?? []);
    }
    foreach (array_unique(array_filter($paths)) as $file) {
        if (file_exists($file)) {
            @unlink($file);
        }
    }
}

function cron_task_runtime(array $task): array {
    return is_array($task['runtime'] ?? null) ? $task['runtime'] : [];
}

function cron_task_is_running(array $task): bool {
    return !empty(cron_task_runtime($task)['running']);
}

function cron_task_has_active_lock(string $id): bool {
    $lock = task_try_acquire_execution_lock($id);
    if ($lock['ok'] ?? false) {
        task_release_execution_lock($lock['handle'] ?? null);
        return false;
    }
    return ($lock['msg'] ?? '') === '任务已在运行中';
}

function cron_reconcile_running_state(string $id): bool {
    $id = preg_replace('/[^a-zA-Z0-9_-]/', '', $id);
    if ($id === '') {
        return false;
    }
    $data = load_scheduled_tasks();
    foreach ($data['tasks'] ?? [] as $idx => $task) {
        if (($task['id'] ?? '') !== $id) {
            continue;
        }
        $runtime = cron_task_runtime($task);
        if (empty($runtime['running'])) {
            return false;
        }
        if (cron_task_has_active_lock($id)) {
            return true;
        }
        task_try_cleanup_stale_lock($id);
        if (!isset($data['tasks'][$idx]['runtime']) || !is_array($data['tasks'][$idx]['runtime'])) {
            $data['tasks'][$idx]['runtime'] = [];
        }
        $data['tasks'][$idx]['runtime']['running'] = false;
        $data['tasks'][$idx]['runtime']['started_at'] = '';
        save_scheduled_tasks($data);
        return false;
    }
    return false;
}

function cron_mark_running(string $id, bool $running): bool {
    $id = preg_replace('/[^a-zA-Z0-9_-]/', '', $id);
    if ($id === '') {
        return false;
    }
    $data = load_scheduled_tasks();
    foreach ($data['tasks'] ?? [] as $idx => $task) {
        if (($task['id'] ?? '') !== $id) {
            continue;
        }
        if (!isset($data['tasks'][$idx]['runtime']) || !is_array($data['tasks'][$idx]['runtime'])) {
            $data['tasks'][$idx]['runtime'] = [];
        }
        $data['tasks'][$idx]['runtime']['running'] = $running;
        $data['tasks'][$idx]['runtime']['started_at'] = $running ? date('Y-m-d H:i:s') : '';
        save_scheduled_tasks($data);
        return true;
    }
    return false;
}

function cron_store_run_result(string $id, int $code, string $output): void {
    $id = preg_replace('/[^a-zA-Z0-9_-]/', '', $id);
    if ($id === '') {
        return;
    }
    $lock = scheduled_tasks_lock_exclusive();
    $data = load_scheduled_tasks();
    foreach ($data['tasks'] ?? [] as $idx => $task) {
        if (($task['id'] ?? '') !== $id) {
            continue;
        }
        if (!isset($data['tasks'][$idx]['runtime']) || !is_array($data['tasks'][$idx]['runtime'])) {
            $data['tasks'][$idx]['runtime'] = [];
        }
        $data['tasks'][$idx]['runtime']['running'] = false;
        $data['tasks'][$idx]['runtime']['started_at'] = '';
        $data['tasks'][$idx]['command'] = task_resolve_command_text($data['tasks'][$idx]);
        $data['tasks'][$idx]['last_run'] = date('Y-m-d H:i:s');
        $data['tasks'][$idx]['last_code'] = $code;
        $data['tasks'][$idx]['last_output'] = mb_substr($output, 0, 8000);
        save_scheduled_tasks($data, $lock);
        scheduled_tasks_unlock($lock);
        return;
    }
    scheduled_tasks_unlock($lock);
}

function task_spawn_background_command(string $command, ?string $cwd = null, array $env = []): array {
    $cwd = $cwd ?: task_project_root();
    if (!is_dir($cwd)) {
        return ['ok' => false, 'msg' => '后台进程工作目录不存在'];
    }
    $baseEnv = [
        'HOME'  => '/home/navwww',
        'USER'  => 'navwww',
        'LOGNAME' => 'navwww',
        'PATH'  => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
        'SHELL' => '/bin/bash',
        'LANG'  => 'en_US.UTF-8',
    ];
    $pairs = [];
    foreach (array_merge($baseEnv, $env) as $key => $value) {
        $key = trim((string)$key);
        if ($key === '' || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key)) {
            continue;
        }
        $pairs[] = $key . '=' . escapeshellarg((string)$value);
    }
    $shell = 'cd ' . escapeshellarg($cwd)
        . ' && env ' . implode(' ', $pairs)
        . ' nohup ' . $command
        . ' >/dev/null 2>&1 </dev/null & printf %s "$!"';
    $output = [];
    $code = 0;
    @exec('/bin/sh -lc ' . escapeshellarg($shell), $output, $code);
    $pid = trim(implode("\n", $output));
    if ($code !== 0 || !preg_match('/^\d+$/', $pid)) {
        task_dispatch_log('spawn failed', [
            'cwd' => $cwd,
            'command' => $command,
            'code' => $code,
            'output' => $output,
        ]);
        return ['ok' => false, 'msg' => '无法启动后台进程'];
    }
    task_dispatch_log('spawn started', [
        'cwd' => $cwd,
        'command' => $command,
        'pid' => (int)$pid,
    ]);
    return ['ok' => true, 'msg' => '后台进程已启动', 'pid' => (int)$pid];
}

function cron_dispatch_task_async(string $id): array {
    $id = preg_replace('/[^a-zA-Z0-9_-]/', '', $id);
    if ($id === '') {
        return ['ok' => false, 'msg' => '无效的任务 ID'];
    }
    $data = load_scheduled_tasks();
    $task = null;
    foreach ($data['tasks'] ?? [] as $row) {
        if (($row['id'] ?? '') === $id) {
            $task = $row;
            break;
        }
    }
    if (!$task) {
        return ['ok' => false, 'msg' => '任务不存在'];
    }
    if (cron_task_is_running($task)) {
        return ['ok' => false, 'msg' => '后台执行已在运行中'];
    }
    if (task_is_execution_locked($id)) {
        return ['ok' => false, 'msg' => '后台执行已在运行中'];
    }
    if (!cron_is_ddns_dispatcher_id($id) && trim(task_resolve_command_text($task)) === '') {
        return ['ok' => false, 'msg' => '命令为空'];
    }

    $command = escapeshellcmd(cron_php_binary())
        . ' '
        . escapeshellarg(task_project_root() . '/cli/run_scheduled_task.php')
        . ' '
        . escapeshellarg($id);
    $spawn = task_spawn_background_command($command, task_project_root(), [
        'TASK_ID' => $id,
        'TASK_RUN_SOURCE' => 'manual',
        'TASK_SILENT_CLI' => '1',
    ]);
    if (!$spawn['ok']) {
        return $spawn;
    }
    cron_mark_running($id, true);
    return ['ok' => true, 'msg' => '已开始后台执行'];
}

function task_cleanup_on_delete(array $task): void {
    $id = (string)($task['id'] ?? '');
    task_clear_log($id);
    $paths = array_unique([
        task_script_file_for_task($task),
        task_script_file($id),
        task_log_file_for_task($task),
        task_log_path_from_filename(task_default_log_filename($id)),
        task_legacy_log_file($id),
    ]);
    foreach ($paths as $script) {
        if (is_file($script)) {
            @unlink($script);
        }
    }
    $lock = task_lock_file($id);
    if ($lock !== '' && file_exists($lock)) {
        @unlink($lock);
    }
}

function scheduled_tasks_clear_manual_tasks(): array {
    $data = load_scheduled_tasks();
    $allTasks = is_array($data['tasks'] ?? null) ? $data['tasks'] : [];
    $kept = [];
    $removed = 0;

    foreach ($allTasks as $task) {
        if (!is_array($task)) {
            continue;
        }
        if (cron_is_system_task($task)) {
            $kept[] = $task;
            continue;
        }
        task_cleanup_on_delete($task);
        $removed++;
    }

    $data['tasks'] = $kept;
    save_scheduled_tasks($data);
    cron_regenerate();

    return [
        'ok' => true,
        'removed' => $removed,
        'kept_system' => count($kept),
    ];
}

function scheduled_task_upsert(array $input): array {
    $id = trim((string)($input['id'] ?? ''));
    $name = trim((string)($input['name'] ?? ''));
    $schedule = trim((string)($input['schedule'] ?? ''));
    $command = task_normalize_editor_contents((string)($input['command'] ?? ''));
    $enabled = !empty($input['enabled']);

    if ($id === '') {
        $id = 't_' . bin2hex(random_bytes(8));
    }
    if (!preg_match('/^[a-zA-Z0-9_-]+$/', $id)) {
        return ['ok' => false, 'msg' => '任务 ID 仅允许字母数字、下划线、短横线'];
    }
    if ($name === '') {
        return ['ok' => false, 'msg' => '请填写任务名称'];
    }
    if (cron_is_ddns_dispatcher_id($id)) {
        return ['ok' => false, 'msg' => 'DDNS 调度器由系统自动维护，不能手动编辑'];
    }
    if (!cron_validate_schedule($schedule)) {
        return ['ok' => false, 'msg' => 'Cron 表达式无效（需至少 5 个时间字段）'];
    }

    $lock = scheduled_tasks_lock_exclusive();
    $data = load_scheduled_tasks();

    $taskRow = null;
    $found = false;
    foreach ($data['tasks'] as &$task) {
        if (($task['id'] ?? '') !== $id) {
            continue;
        }
        $task['name'] = $name;
        $task['enabled'] = $enabled;
        $task['schedule'] = $schedule;
        $task['command'] = $command;
        unset($task['working_dir_mode'], $task['working_dir']);
        $taskRow = $task;
        $found = true;
        break;
    }
    unset($task);

    if (!$found) {
        $taskRow = [
            'id' => $id,
            'name' => $name,
            'enabled' => $enabled,
            'schedule' => $schedule,
            'command' => $command,
            'created_at' => date('Y-m-d H:i:s'),
        ];
        array_unshift($data['tasks'], $taskRow);
    }

    if (is_array($taskRow)) {
        $scriptFilename = task_resolve_script_filename($taskRow, $data['tasks']);
        foreach ($data['tasks'] as &$task) {
            if (($task['id'] ?? '') !== $id) {
                continue;
            }
            $task['script_filename'] = $scriptFilename;
            $taskRow = $task;
            break;
        }
        unset($task);
    }

    task_ensure_workdir(['id' => $id]);
    $scriptSync = task_sync_script_for_task($taskRow ?? ['id' => $id, 'name' => $name, 'command' => $command], $data['tasks']);
    if (!($scriptSync['ok'] ?? false)) {
        return ['ok' => false, 'msg' => (string)($scriptSync['msg'] ?? '任务脚本写入失败')];
    }

    save_scheduled_tasks($data, $lock);
    scheduled_tasks_unlock($lock);
    $regen = cron_regenerate();
    if (!($regen['ok'] ?? false)) {
        return ['ok' => false, 'msg' => (string)($regen['msg'] ?? 'crontab 更新失败')];
    }

    return ['ok' => true, 'msg' => '已保存并更新 crontab', 'id' => $id, 'task' => $taskRow];
}

/** @return array{tasks: array<int, array>} */
function load_scheduled_tasks(): array {
    if (!file_exists(SCHEDULED_TASKS_FILE)) {
        return ['tasks' => []];
    }
    for ($attempt = 0; $attempt < 5; $attempt++) {
        clearstatcache(true, SCHEDULED_TASKS_FILE);
        $raw = @file_get_contents(SCHEDULED_TASKS_FILE);
        if ($raw === false || trim($raw) === '') {
            usleep(50000);
            continue;
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            usleep(50000);
            continue;
        }
        if (!isset($data['tasks']) || !is_array($data['tasks'])) {
            return ['tasks' => []];
        }
        return $data;
    }
    return ['tasks' => []];
}

function task_sort_for_display(array $tasks): array {
    $indexed = [];
    foreach (array_values($tasks) as $idx => $task) {
        $createdAt = trim((string)($task['created_at'] ?? ''));
        $indexed[] = [
            'idx' => $idx,
            'created_at' => $createdAt,
            'sort_key' => $createdAt !== '' ? strtotime($createdAt) : false,
            'task' => $task,
        ];
    }
    usort($indexed, static function (array $a, array $b): int {
        $aHas = $a['sort_key'] !== false;
        $bHas = $b['sort_key'] !== false;
        if ($aHas && $bHas) {
            if ($a['sort_key'] === $b['sort_key']) {
                return $a['idx'] <=> $b['idx'];
            }
            return $b['sort_key'] <=> $a['sort_key'];
        }
        if ($aHas !== $bHas) {
            return $aHas ? -1 : 1;
        }
        return $a['idx'] <=> $b['idx'];
    });
    return array_values(array_map(static fn(array $row) => $row['task'], $indexed));
}

function save_scheduled_tasks(array $data, $externalLock = null): void {
    if (!isset($data['tasks']) || !is_array($data['tasks'])) {
        $data['tasks'] = [];
    }
    $dir = dirname(SCHEDULED_TASKS_FILE);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        $json = "{\"tasks\":[]}\n";
    }
    $ownLock = false;
    $lock = $externalLock;
    if (!is_resource($lock)) {
        $lock = scheduled_tasks_lock_exclusive();
        $ownLock = true;
    }
    $tmp = @tempnam($dir, 'scheduled_tasks_');
    if ($tmp !== false) {
        if (@file_put_contents($tmp, $json, LOCK_EX) !== false && @rename($tmp, SCHEDULED_TASKS_FILE)) {
            if ($ownLock) {
                scheduled_tasks_unlock($lock);
            }
            return;
        }
        @unlink($tmp);
    }
    file_put_contents(SCHEDULED_TASKS_FILE, $json, LOCK_EX);
    if ($ownLock) {
        scheduled_tasks_unlock($lock);
    }
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
    $candidates = array_values(array_unique(array_filter([
        PHP_BINDIR . '/php',
        PHP_BINARY,
        ...PHP_BIN_CANDIDATES,
    ], static fn($candidate) => is_string($candidate) && trim($candidate) !== '')));

    foreach ($candidates as $candidate) {
        $candidate = trim((string)$candidate);
        if ($candidate === '') {
            continue;
        }
        $basename = strtolower(basename($candidate));
        if ($basename !== 'php' && (str_starts_with($basename, 'php-fpm') || str_starts_with($basename, 'php-cgi'))) {
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

/**
 * 严格验证单个 cron 字段。
 * 支持的语法：* 、 n 、 n,m 、 n-m 、 * /step 、 n-m/step
 *
 * @param string $field cron 字段值
 * @param int $min 最小允许值
 * @param int $max 最大允许值
 * @return bool
 */
function cron_validate_field(string $field, int $min, int $max): bool {
    if ($field === '' || preg_match('/[^0-9*,\-\/]/', $field)) {
        return false;
    }
    foreach (explode(',', $field) as $part) {
        if ($part === '*') {
            continue;
        }
        if (str_contains($part, '/')) {
            [$range, $step] = explode('/', $part, 2);
            if ($step === '' || !ctype_digit($step) || (int)$step < 1) {
                return false;
            }
            if ($range !== '*' && str_contains($range, '-')) {
                [$start, $end] = explode('-', $range, 2);
                if ($start === '' || $end === '' || !ctype_digit($start) || !ctype_digit($end)) {
                    return false;
                }
                $s = (int)$start;
                $e = (int)$end;
                if ($s < $min || $e > $max || $s > $e) {
                    return false;
                }
            } elseif ($range !== '*') {
                if (!ctype_digit($range)) {
                    return false;
                }
                $v = (int)$range;
                if ($v < $min || $v > $max) {
                    return false;
                }
            }
        } elseif (str_contains($part, '-')) {
            [$start, $end] = explode('-', $part, 2);
            if ($start === '' || $end === '' || !ctype_digit($start) || !ctype_digit($end)) {
                return false;
            }
            $s = (int)$start;
            $e = (int)$end;
            if ($s < $min || $e > $max || $s > $e) {
                return false;
            }
        } else {
            if (!ctype_digit($part)) {
                return false;
            }
            $v = (int)$part;
            if ($v < $min || $v > $max) {
                return false;
            }
        }
    }
    return true;
}

function cron_validate_schedule(string $line): bool {
    $line = trim($line);
    if ($line === '' || preg_match('/[\r\n]/', $line)) {
        return false;
    }
    $parts = preg_split('/\s+/', $line);
    if (count($parts) !== 5) {
        return false;
    }
    [$min, $hour, $dom, $mon, $dow] = $parts;
    return cron_validate_field($min, 0, 59)
        && cron_validate_field($hour, 0, 23)
        && cron_validate_field($dom, 1, 31)
        && cron_validate_field($mon, 1, 12)
        && cron_validate_field($dow, 0, 6);
}

function cron_regenerate(): array {
    cron_sync_ddns_dispatcher_task();
    $lines   = [];
    $lines[] = '# simple-homepage generated — do not edit by hand';
    $lines[] = 'SHELL=/bin/bash';
    $lines[] = 'PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin';

    $lineToTask = []; // lineNumber (1-based) => ['id'=>..., 'name'=>...]

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
        $sched = preg_replace('/\s+/', ' ', $sched);
        if (!cron_validate_schedule($sched)) {
            continue;
        }
        $cmd = 'TASK_RUN_SOURCE=cron TASK_SILENT_CLI=1 '
            . escapeshellcmd(cron_php_binary())
            . ' /var/www/nav/cli/run_scheduled_task.php '
            . escapeshellarg($id);
        $lines[] = $sched . ' ' . $cmd;
        $lineToTask[count($lines)] = ['id' => $id, 'name' => (string)($t['name'] ?? '')];
    }

    $content = implode("\n", $lines) . "\n";
    $result = cron_install_stdin($content);
    if (!$result['ok']) {
        $errMsg = (string)($result['msg'] ?? '');
        // 尝试解析 crontab 错误中的行号，如 "-":4: bad command
        if (preg_match('/"-":(\d+):/i', $errMsg, $m)) {
            $badLine = (int)$m[1];
            if (isset($lineToTask[$badLine])) {
                $badTask = $lineToTask[$badLine];
                $result['msg'] = 'crontab 第 ' . $badLine . ' 行语法错误（任务：' . ($badTask['name'] ?: $badTask['id'])
                    . '）。请检查该任务的「执行周期」是否为标准 5 字段 Cron 格式（如 */5 * * * *），'
                    . '不要写成 6 字段（带秒）或其他非常规格式。原始错误：' . $errMsg;
            }
        }
    }
    return $result;
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
        $runLabel = task_execution_label();
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
        task_ensure_workdir($task);
        $log_file = task_log_file_for_task($task, $data['tasks'] ?? []);
        $stamp = date('[Y-m-d H:i:s]') . ' [exit:' . $code . '] --- DDNS 分组' . $runLabel . ' ---';
        file_put_contents($log_file, $stamp . "\n" . rtrim($output) . "\n", FILE_APPEND | LOCK_EX);
        cron_store_run_result($id, $code, $output);
        return ['ok' => $code === 0, 'code' => $code, 'output' => $output, 'msg' => $code === 0 ? '执行完成' : "退出码 $code"];
    }

    $runLabel = task_execution_label();
    $cmd = task_resolve_command_text($task);
    if (trim($cmd) === '') {
        cron_mark_running($id, false);
        return ['ok' => false, 'code' => -1, 'output' => '', 'msg' => '命令为空'];
    }
    $workdir = task_resolve_workdir($task);
    task_ensure_workdir($task);
    $runtimeTmpDir = task_ensure_runtime_tmp_dir($task);
    $script_file = task_script_file_for_task($task, $data['tasks'] ?? []);
    if (!is_file($script_file)) {
        $script_write = task_write_script_file($task, $cmd, $data['tasks'] ?? []);
        if (!$script_write['ok']) {
            cron_mark_running($id, false);
            return ['ok' => false, 'code' => -1, 'output' => '', 'msg' => $script_write['msg']];
        }
        $script_file = (string)$script_write['path'];
    }
    $log_file = task_log_file_for_task($task, $data['tasks'] ?? []);
    task_ensure_workdir($task);
    $log_offset = task_file_size($log_file);
    $start_stamp = date('[Y-m-d H:i:s]') . ' --- ' . $runLabel . ' ---';
    @file_put_contents($log_file, $start_stamp . "\n", FILE_APPEND | LOCK_EX);

    if (task_command_mentions_cfst($cmd)) {
        $compat = task_try_prepare_cfst_runtime();
        if (($compat['msg'] ?? '') !== '') {
            @file_put_contents($log_file, '[compat] ' . $compat['msg'] . "\n", FILE_APPEND | LOCK_EX);
        }
        if (!($compat['ok'] ?? false)) {
            $output = task_read_file_segment($log_file, $log_offset);
            cron_store_run_result($id, 1, $output);
            return ['ok' => false, 'code' => 1, 'output' => $output, 'msg' => $compat['msg']];
        }
    }

    $desc = [
        0 => ['file', '/dev/null', 'r'],
        1 => ['file', '/dev/null', 'a'],
        2 => ['file', '/dev/null', 'a'],
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
        'TASK_SCRIPT_FILE' => $script_file,
        'TASK_LOG_FILE' => $log_file,
        'TMPDIR' => $runtimeTmpDir,
        'TMP' => $runtimeTmpDir,
        'TEMP' => $runtimeTmpDir,
    ];
    $cmdline = '/bin/bash ' . escapeshellarg($script_file)
        . ' >> ' . escapeshellarg($log_file) . ' 2>&1';
    $proc = proc_open($cmdline, $desc, $pipes, $workdir, $env);
    $code   = -1;
    $status_exit_code = null;
    $timedOut = false;
    if (is_resource($proc)) {
        $startAt = time();
        $sigtermAt = null;
        $timeoutLimit = task_execution_timeout();
        $killAfter = defined('TASK_EXECUTION_KILL_AFTER') ? (int)TASK_EXECUTION_KILL_AFTER : 10;
        $timeoutHint = '如需调整，请前往「系统设置」修改"计划任务执行超时"。';
        while (true) {
            $status = proc_get_status($proc);
            if (!($status['running'] ?? false)) {
                $exitcode = (int)($status['exitcode'] ?? -1);
                if ($exitcode >= 0) {
                    $status_exit_code = $exitcode;
                }
                break;
            }
            $elapsed = time() - $startAt;
            if ($timeoutLimit > 0 && $elapsed >= $timeoutLimit) {
                if ($sigtermAt === null) {
                    $pid = (int)($status['pid'] ?? 0);
                    if ($pid > 0) {
                        task_proc_send_signal($pid, 15);
                    }
                    $sigtermAt = time();
                    @file_put_contents($log_file, "\n[" . date('Y-m-d H:i:s') . "] [TIMEOUT] 任务运行超过 {$timeoutLimit} 秒，已发送 SIGTERM。{$timeoutHint}\n", FILE_APPEND | LOCK_EX);
                } elseif ((time() - $sigtermAt) >= $killAfter) {
                    $pid = (int)($status['pid'] ?? 0);
                    if ($pid > 0) {
                        task_proc_send_signal($pid, 9);
                    }
                    $timedOut = true;
                    @file_put_contents($log_file, "\n[" . date('Y-m-d H:i:s') . "] [TIMEOUT] 任务未在 {$killAfter} 秒内退出，已强制 SIGKILL。{$timeoutHint}\n", FILE_APPEND | LOCK_EX);
                    for ($i = 0; $i < 50; $i++) {
                        $status = proc_get_status($proc);
                        if (!($status['running'] ?? false)) {
                            $status_exit_code = 124;
                            break 2;
                        }
                        usleep(100000);
                    }
                    $status_exit_code = 124;
                    break;
                }
            }
            usleep(200000);
        }
        $close_code = proc_close($proc);
        $code = ($close_code === -1 && $status_exit_code !== null) ? $status_exit_code : $close_code;
        if ($timedOut && $code !== 124) {
            $code = 124;
        }
    } else {
        @file_put_contents($log_file, "[无法启动 bash 进程]\n", FILE_APPEND | LOCK_EX);
    }

    $end_stamp = date('[Y-m-d H:i:s]') . ' [exit:' . $code . '] --- ' . $runLabel . '结束 ---';
    @file_put_contents($log_file, $end_stamp . "\n", FILE_APPEND | LOCK_EX);
    $output = task_read_file_segment($log_file, $log_offset);
    cron_store_run_result($id, $code, $output);
    return ['ok' => $code === 0, 'code' => $code, 'output' => $output, 'msg' => $code === 0 ? '执行完成' : "退出码 $code"];
}

/**
 * 计算 cron 表达式的下一次运行时间（精确到分钟，最多向前查找 366 天）
 * 返回 Y-m-d H:i 字符串，或 false 表示无效表达式
 */
function cron_next_run(string $expr): string|false {
    $parts = preg_split('/\s+/', trim($expr));
    if (count($parts) !== 5) return false;
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
    $file = task_existing_log_file($id);
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

function task_status_snapshot(array $task): array {
    $enabled = !empty($task['enabled']);
    $id = (string)($task['id'] ?? '');
    $running = cron_task_is_running($task);
    if ($running && $id !== '') {
        $running = cron_reconcile_running_state($id);
        if (!$running) {
            $task['runtime'] = array_merge(cron_task_runtime($task), [
                'running' => false,
                'started_at' => '',
            ]);
        }
    }
    return [
        'id' => $id,
        'enabled' => $enabled,
        'running' => $running,
        'started_at' => (string)(cron_task_runtime($task)['started_at'] ?? ''),
        'last_run' => (string)($task['last_run'] ?? ''),
        'last_code' => array_key_exists('last_code', $task) ? $task['last_code'] : null,
        'next' => ($enabled && !empty($task['schedule'])) ? (cron_next_run((string)$task['schedule']) ?: '-') : '-',
        'is_system' => cron_is_system_task($task),
    ];
}

function scheduled_tasks_status_payload(array $ids = []): array {
    $idMap = [];
    foreach ($ids as $id) {
        $clean = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$id);
        if ($clean !== '') {
            $idMap[$clean] = true;
        }
    }

    $data = load_scheduled_tasks();
    $tasks = [];
    foreach ($data['tasks'] ?? [] as $task) {
        $id = (string)($task['id'] ?? '');
        if ($id === '') {
            continue;
        }
        if ($idMap !== [] && !isset($idMap[$id])) {
            continue;
        }
        $tasks[$id] = task_status_snapshot($task);
    }

    return [
        'server_time' => date('Y-m-d H:i:s'),
        'tasks' => $tasks,
    ];
}

function cron_run_task_by_id(string $id): void {
    $id = preg_replace('/[^a-zA-Z0-9_-]/', '', $id);
    if ($id === '') {
        fwrite(STDERR, "invalid task id\n");
        exit(1);
    }
    $lock = task_try_acquire_execution_lock($id);
    if (!($lock['ok'] ?? false)) {
        if (($lock['msg'] ?? '') === '任务已在运行中') {
            task_append_skip_log($id, '任务已在运行中，跳过本次执行');
            exit(0);
        }
        fwrite(STDERR, ($lock['msg'] ?? 'task lock failed') . "\n");
        exit(1);
    }

    cron_mark_running($id, true);
    try {
        $r = cron_execute_task($id);
        $task = task_find_by_id($id, load_scheduled_tasks()['tasks'] ?? []);
        $taskName = is_array($task) ? (string)($task['name'] ?? $id) : $id;
        if (function_exists('notify_event')) {
            notify_event(($r['ok'] ?? false) ? 'task_succeeded' : 'task_failed', [
                'task' => $taskName,
                'task_id' => $id,
                'source' => task_execution_source(),
                'exit_code' => (string)($r['code'] ?? ''),
                'message' => (string)($r['msg'] ?? ''),
            ]);
        }
        if (!($r['ok'] ?? false)) {
            task_dispatch_log('task run failed', [
                'id' => $id,
                'code' => $r['code'] ?? null,
                'msg' => $r['msg'] ?? 'unknown error',
            ]);
        }
        if (getenv('TASK_SILENT_CLI') !== '1') {
            echo $r['output'] . "\n";
        }
        exit($r['ok'] ? 0 : 1);
    } finally {
        cron_mark_running($id, false);
        task_release_execution_lock($lock['handle'] ?? null);
    }
}
