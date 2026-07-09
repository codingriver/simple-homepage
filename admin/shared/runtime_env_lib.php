<?php
declare(strict_types=1);

/**
 * 运行环境管理：Node.js 多版本安装、切换、检测与 npm 配置。
 */
require_once __DIR__ . '/functions.php';

define('RUNTIME_ENV_ROOT', DATA_DIR . '/runtime');
define('RUNTIME_ENV_CONFIG_FILE', RUNTIME_ENV_ROOT . '/runtime_env.json');
define('RUNTIME_NODE_ROOT', RUNTIME_ENV_ROOT . '/node');
define('RUNTIME_NODE_VERSIONS_DIR', RUNTIME_NODE_ROOT . '/versions');
define('RUNTIME_NODE_CURRENT_LINK', RUNTIME_NODE_ROOT . '/current');
define('RUNTIME_NODE_LOG_FILE', RUNTIME_NODE_ROOT . '/install.log');
define('RUNTIME_ENV_JOBS_DIR', RUNTIME_ENV_ROOT . '/jobs');
define('RUNTIME_ENV_JOB_LOG_BYTES', 60000);

function runtime_env_ensure_dirs(): void {
    foreach ([RUNTIME_ENV_ROOT, RUNTIME_NODE_ROOT, RUNTIME_NODE_VERSIONS_DIR, RUNTIME_ENV_JOBS_DIR] as $dir) {
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
    }
}

function runtime_env_read_config(): array {
    if (!is_file(RUNTIME_ENV_CONFIG_FILE)) {
        return [
            'node' => [
                'registry' => 'https://registry.npmmirror.com',
                'download_base' => 'https://unofficial-builds.nodejs.org/download/release',
                'current_version' => '',
            ],
        ];
    }
    $raw = @file_get_contents(RUNTIME_ENV_CONFIG_FILE);
    $data = json_decode(is_string($raw) ? $raw : '{}', true);
    if (!is_array($data)) {
        $data = [];
    }
    if (!isset($data['node']) || !is_array($data['node'])) {
        $data['node'] = [];
    }
    $data['node'] += [
        'registry' => 'https://registry.npmmirror.com',
        'download_base' => 'https://unofficial-builds.nodejs.org/download/release',
        'current_version' => '',
    ];
    return $data;
}

function runtime_env_save_config(array $data): void {
    runtime_env_ensure_dirs();
    if (!isset($data['node']) || !is_array($data['node'])) {
        $data['node'] = [];
    }
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        $json = "{}\n";
    }
    @file_put_contents(RUNTIME_ENV_CONFIG_FILE, $json, LOCK_EX);
}

function runtime_env_append_log(string $message): void {
    runtime_env_ensure_dirs();
    @file_put_contents(RUNTIME_NODE_LOG_FILE, '[' . date('Y-m-d H:i:s') . '] ' . $message . "\n", FILE_APPEND | LOCK_EX);
}

function runtime_env_job_id(): string {
    return date('YmdHis') . '-' . bin2hex(random_bytes(4));
}

function runtime_env_clean_job_id(string $jobId): string {
    return preg_replace('/[^A-Za-z0-9._-]/', '', $jobId);
}

function runtime_env_job_file(string $jobId): string {
    $jobId = runtime_env_clean_job_id($jobId);
    return RUNTIME_ENV_JOBS_DIR . '/' . $jobId . '.json';
}

function runtime_env_job_log_file(string $jobId): string {
    $jobId = runtime_env_clean_job_id($jobId);
    return RUNTIME_ENV_JOBS_DIR . '/' . $jobId . '.log';
}

function runtime_env_job_write(string $jobId, array $data): void {
    runtime_env_ensure_dirs();
    $data['id'] = runtime_env_clean_job_id($jobId);
    $data['updated_at'] = date('Y-m-d H:i:s');
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        $json = "{}\n";
    }
    @file_put_contents(runtime_env_job_file($jobId), $json, LOCK_EX);
}

function runtime_env_job_read(string $jobId): ?array {
    $file = runtime_env_job_file($jobId);
    if (!is_file($file)) {
        return null;
    }
    $raw = @file_get_contents($file);
    $data = json_decode(is_string($raw) ? $raw : '{}', true);
    return is_array($data) ? $data : null;
}

function runtime_env_job_append_log(string $jobId, string $line): void {
    runtime_env_ensure_dirs();
    $line = rtrim(str_replace("\r\n", "\n", str_replace("\r", "\n", $line)), "\n");
    if ($line === '') {
        return;
    }
    @file_put_contents(runtime_env_job_log_file($jobId), '[' . date('H:i:s') . '] ' . $line . "\n", FILE_APPEND | LOCK_EX);
    runtime_env_append_log('[job:' . runtime_env_clean_job_id($jobId) . '] ' . $line);
}

function runtime_env_job_tail_log(string $jobId, int $bytes = RUNTIME_ENV_JOB_LOG_BYTES): string {
    $file = runtime_env_job_log_file($jobId);
    if (!is_file($file)) {
        return '';
    }
    $size = filesize($file);
    if ($size === false) {
        return '';
    }
    $offset = max(0, $size - max(1024, $bytes));
    $raw = @file_get_contents($file, false, null, $offset);
    return is_string($raw) ? str_replace("\r\n", "\n", str_replace("\r", "\n", $raw)) : '';
}

function runtime_env_job_update(string $jobId, array $patch): array {
    $data = runtime_env_job_read($jobId) ?? ['id' => runtime_env_clean_job_id($jobId), 'status' => 'running'];
    $data = array_merge($data, $patch);
    runtime_env_job_write($jobId, $data);
    return $data;
}

function runtime_env_job_finish(string $jobId, bool $ok, string $message, array $extra = []): array {
    $data = runtime_env_job_update($jobId, array_merge($extra, [
        'status' => $ok ? 'success' : 'failed',
        'phase' => $ok ? '完成' : '失败',
        'percent' => $ok ? 100 : (int)($extra['percent'] ?? (runtime_env_job_read($jobId)['percent'] ?? 100)),
        'message' => $message,
        'finished_at' => date('Y-m-d H:i:s'),
    ]));
    runtime_env_job_append_log($jobId, $message);
    return $data;
}

function runtime_env_job_public_payload(string $jobId): ?array {
    $job = runtime_env_job_read($jobId);
    if ($job === null) {
        return null;
    }
    $job['log'] = runtime_env_job_tail_log($jobId);
    if (($job['status'] ?? '') === 'success') {
        $job['node'] = runtime_env_detect_node();
    }
    return $job;
}

function runtime_env_shell_arg_array(array $args): string {
    return implode(' ', array_map(static fn($v): string => escapeshellarg((string)$v), $args));
}

function runtime_env_start_install_job(string $type, array $args = []): array {
    $type = trim($type);
    if (!in_array($type, ['apk', 'version'], true)) {
        return ['ok' => false, 'msg' => '未知安装类型'];
    }
    $jobId = runtime_env_job_id();
    runtime_env_job_write($jobId, [
        'type' => $type,
        'status' => 'queued',
        'phase' => '排队中',
        'percent' => 0,
        'message' => '等待后台安装进程启动',
        'created_at' => date('Y-m-d H:i:s'),
    ]);
    runtime_env_job_append_log($jobId, '创建安装任务');

    $php = PHP_BINDIR . '/php';
    if (!is_file($php)) {
        $php = 'php';
    }
    $argv = [$php, dirname(__DIR__, 2) . '/cli/runtime_env_job.php', $jobId, $type];
    foreach ($args as $arg) {
        $argv[] = (string)$arg;
    }
    $command = runtime_env_shell_arg_array($argv);
    $shell = 'cd ' . escapeshellarg(dirname(__DIR__, 2)) . ' && nohup ' . $command . ' >/dev/null 2>&1 </dev/null & printf %s "$!"';
    $output = [];
    $code = 0;
    @exec('/bin/sh -lc ' . escapeshellarg($shell), $output, $code);
    $pid = trim(implode("\n", $output));
    if ($code !== 0 || !preg_match('/^\d+$/', $pid)) {
        runtime_env_job_finish($jobId, false, '无法启动后台安装进程', ['percent' => 0]);
        return ['ok' => false, 'msg' => '无法启动后台安装进程', 'data' => ['job_id' => $jobId, 'code' => $code, 'output' => $output]];
    }
    runtime_env_job_update($jobId, ['status' => 'running', 'pid' => (int)$pid, 'phase' => '启动中', 'percent' => 3, 'message' => '后台安装进程已启动']);
    runtime_env_job_append_log($jobId, '后台进程 PID ' . $pid);
    return ['ok' => true, 'msg' => '安装任务已启动', 'data' => ['job_id' => $jobId]];
}

function runtime_env_parse_command_chunks(string $chunk): array {
    $chunk = str_replace("\r", "\n", str_replace("\r\n", "\n", $chunk));
    $lines = array_values(array_filter(array_map('trim', explode("\n", $chunk)), static fn(string $line): bool => $line !== ''));
    return $lines;
}

function runtime_env_run_stream_command(
    string $jobId,
    string $command,
    string $cwd,
    string $phase,
    int $startPercent,
    int $endPercent,
    ?callable $onLine = null,
    ?callable $onTick = null
): array {
    runtime_env_job_update($jobId, [
        'status' => 'running',
        'phase' => $phase,
        'percent' => $startPercent,
        'message' => $phase,
        'command' => $command,
    ]);
    runtime_env_job_append_log($jobId, '$ ' . $command);

    $env = [
        'HOME' => '/home/navwww',
        'USER' => 'navwww',
        'LOGNAME' => 'navwww',
        'PATH' => runtime_env_node_bin_dir() . ':/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
        'SHELL' => '/bin/sh',
        'LANG' => 'en_US.UTF-8',
    ];
    $descriptors = [
        0 => ['file', '/dev/null', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = @proc_open('/bin/sh -lc ' . escapeshellarg($command), $descriptors, $pipes, is_dir($cwd) ? $cwd : RUNTIME_ENV_ROOT, $env);
    if (!is_resource($proc)) {
        return ['ok' => false, 'code' => -1, 'stdout' => '', 'stderr' => '无法启动命令', 'command' => $command];
    }
    foreach ([1, 2] as $idx) {
        stream_set_blocking($pipes[$idx], false);
    }

    $stdout = '';
    $stderr = '';
    $lastTick = microtime(true);
    $lastPercent = $startPercent;
    $statusExitCode = null;
    while (true) {
        $status = proc_get_status($proc);
        $running = (bool)($status['running'] ?? false);
        foreach ([1, 2] as $idx) {
            $chunk = stream_get_contents($pipes[$idx]);
            if (!is_string($chunk) || $chunk === '') {
                continue;
            }
            if ($idx === 1) {
                $stdout .= $chunk;
            } else {
                $stderr .= $chunk;
            }
            foreach (runtime_env_parse_command_chunks($chunk) as $line) {
                runtime_env_job_append_log($jobId, $line);
                if ($onLine !== null) {
                    $patch = $onLine($line, $lastPercent);
                    if (is_array($patch) && $patch !== []) {
                        if (isset($patch['percent'])) {
                            $lastPercent = max($startPercent, min($endPercent, (int)$patch['percent']));
                            $patch['percent'] = $lastPercent;
                        }
                        runtime_env_job_update($jobId, array_merge(['phase' => $phase], $patch));
                    }
                }
            }
        }
        $now = microtime(true);
        if ($onTick !== null && ($now - $lastTick) >= 0.8) {
            $patch = $onTick($lastPercent);
            if (is_array($patch) && $patch !== []) {
                if (isset($patch['percent'])) {
                    $lastPercent = max($startPercent, min($endPercent, (int)$patch['percent']));
                    $patch['percent'] = $lastPercent;
                }
                runtime_env_job_update($jobId, array_merge(['phase' => $phase], $patch));
            }
            $lastTick = $now;
        }
        if (!$running) {
            $exitCode = (int)($status['exitcode'] ?? -1);
            if ($exitCode >= 0) {
                $statusExitCode = $exitCode;
            }
            break;
        }
        usleep(160000);
    }
    foreach ([1, 2] as $idx) {
        $tail = stream_get_contents($pipes[$idx]);
        if (is_string($tail) && $tail !== '') {
            if ($idx === 1) {
                $stdout .= $tail;
            } else {
                $stderr .= $tail;
            }
            foreach (runtime_env_parse_command_chunks($tail) as $line) {
                runtime_env_job_append_log($jobId, $line);
            }
        }
        fclose($pipes[$idx]);
    }
    $code = proc_close($proc);
    if ($code === -1 && $statusExitCode !== null) {
        $code = $statusExitCode;
    }
    if ($code === 0) {
        runtime_env_job_update($jobId, [
            'phase' => $phase,
            'percent' => $endPercent,
            'message' => $phase . '完成',
        ]);
    }
    return [
        'ok' => $code === 0,
        'code' => $code,
        'stdout' => $stdout,
        'stderr' => $stderr,
        'command' => $command,
    ];
}

function runtime_env_url_content_length(string $url): int {
    $result = runtime_env_exec('curl -fsIL --connect-timeout 15 ' . escapeshellarg($url) . ' | awk ' . escapeshellarg('BEGIN{IGNORECASE=1} /^content-length:/ {gsub("\r","",$2); v=$2} END{print v+0}'));
    $n = (int)trim((string)($result['stdout'] ?? ''));
    return $n > 0 ? $n : 0;
}

function runtime_env_install_node_apk_job(string $jobId): array {
    @set_time_limit(0);
    runtime_env_job_update($jobId, ['status' => 'running', 'phase' => '准备安装', 'percent' => 5, 'message' => '准备通过 Alpine apk 安装 nodejs npm']);
    $cmd = runtime_env_sudo_prefix() . 'apk add --no-cache nodejs npm';
    $result = runtime_env_run_stream_command(
        $jobId,
        $cmd,
        RUNTIME_ENV_ROOT,
        'apk 安装',
        8,
        92,
        static function (string $line, int $current): array {
            if (preg_match('/\((\d+)\/(\d+)\)\s+Installing\s+(.+)/i', $line, $m)) {
                $done = max(1, (int)$m[1]);
                $total = max($done, (int)$m[2]);
                return [
                    'percent' => 8 + (int)floor(($done / $total) * 76),
                    'message' => '正在安装：' . $m[3],
                ];
            }
            if (stripos($line, 'fetch') !== false || stripos($line, 'downloading') !== false) {
                return ['percent' => max($current, 15), 'message' => '正在下载 apk 索引或软件包'];
            }
            return ['message' => $line];
        },
        static function (int $current): array {
            return ['percent' => min(90, $current + 1)];
        }
    );
    if (!$result['ok']) {
        return runtime_env_job_finish($jobId, false, 'apk 安装 Node.js 失败', [
            'exit_code' => $result['code'],
            'stderr' => trim((string)$result['stderr']),
            'suggestion' => '请检查容器网络、Alpine 软件源或代理配置；也可以改用指定版本 musl 包安装。',
        ]);
    }
    return runtime_env_job_finish($jobId, true, 'Node.js/npm 已通过 apk 安装', ['node' => runtime_env_detect_node()]);
}

function runtime_env_install_node_version_job(string $jobId, string $version): array {
    @set_time_limit(0);
    $version = runtime_env_normalize_node_version($version);
    if ($version === '') {
        return runtime_env_job_finish($jobId, false, 'Node.js 版本号无效，请填写如 22.20.0', ['percent' => 0]);
    }
    runtime_env_ensure_dirs();
    $cfg = runtime_env_read_config();
    $platform = runtime_env_node_platform();
    $base = rtrim((string)($cfg['node']['download_base'] ?? 'https://unofficial-builds.nodejs.org/download/release'), '/');
    $archive = 'node-v' . $version . '-' . $platform . '.tar.xz';
    $url = $base . '/v' . $version . '/' . $archive;
    $target = runtime_env_node_version_dir($version);
    $tmp = RUNTIME_NODE_ROOT . '/tmp-' . $version . '-' . bin2hex(random_bytes(3));
    $archivePath = $tmp . '/' . $archive;

    if ($target === '' || is_dir($target)) {
        return runtime_env_job_finish($jobId, false, $target === '' ? '目标版本目录无效' : 'Node.js ' . $version . ' 已安装，可直接切换', ['percent' => 0]);
    }
    @mkdir($tmp, 0775, true);
    runtime_env_job_update($jobId, [
        'status' => 'running',
        'phase' => '准备安装',
        'percent' => 5,
        'message' => '准备安装 Node.js ' . $version . ' (' . $platform . ')',
        'url' => $url,
    ]);
    runtime_env_job_append_log($jobId, '安装 Node.js ' . $version . ' (' . $platform . ')');

    if (!runtime_env_command_exists('xz')) {
        $xz = runtime_env_run_stream_command(
            $jobId,
            runtime_env_sudo_prefix() . 'apk add --no-cache xz',
            RUNTIME_ENV_ROOT,
            '安装解压工具',
            6,
            14,
            static fn(string $line, int $current): array => ['percent' => min(14, max($current + 1, 8)), 'message' => $line],
            static fn(int $current): array => ['percent' => min(13, $current + 1)]
        );
        if (!$xz['ok']) {
            task_rrmdir_if_available($tmp);
            return runtime_env_job_finish($jobId, false, '安装 xz 解压工具失败', [
                'exit_code' => $xz['code'],
                'stderr' => trim((string)$xz['stderr']),
                'suggestion' => '指定版本安装需要解压 .tar.xz，请检查 apk 源或代理配置。',
            ]);
        }
    }

    $totalBytes = runtime_env_url_content_length($url);
    runtime_env_job_update($jobId, [
        'phase' => '下载 Node.js',
        'percent' => 15,
        'message' => $totalBytes > 0 ? ('准备下载，大小 ' . runtime_env_format_bytes($totalBytes)) : '准备下载，无法获取文件大小',
        'download_total' => $totalBytes,
        'downloaded' => 0,
    ]);
    $download = runtime_env_run_stream_command(
        $jobId,
        'curl -fL --retry 2 --connect-timeout 20 -o ' . escapeshellarg($archivePath) . ' ' . escapeshellarg($url),
        $tmp,
        '下载 Node.js',
        15,
        68,
        static fn(string $line, int $current): array => ['message' => $line],
        static function (int $current) use ($archivePath, $totalBytes): array {
            clearstatcache(true, $archivePath);
            $done = is_file($archivePath) ? (int)filesize($archivePath) : 0;
            if ($totalBytes > 0) {
                $percent = 15 + (int)floor(min(1, $done / $totalBytes) * 53);
                return [
                    'percent' => $percent,
                    'downloaded' => $done,
                    'message' => '正在下载：' . runtime_env_format_bytes($done) . ' / ' . runtime_env_format_bytes($totalBytes),
                ];
            }
            return [
                'percent' => min(66, $current + 1),
                'downloaded' => $done,
                'message' => '正在下载：' . runtime_env_format_bytes($done),
            ];
        }
    );
    if (!$download['ok']) {
        task_rrmdir_if_available($tmp);
        return runtime_env_job_finish($jobId, false, '下载 Node.js musl 包失败', [
            'exit_code' => $download['code'],
            'stderr' => trim((string)$download['stderr']),
            'url' => $url,
            'suggestion' => '该版本可能没有 ' . $platform . ' 构建，或当前网络无法访问下载源；可换版本或使用 apk 安装。',
        ]);
    }

    $extract = runtime_env_run_stream_command(
        $jobId,
        'tar -xJf ' . escapeshellarg($archivePath) . ' -C ' . escapeshellarg($tmp),
        $tmp,
        '解压安装包',
        70,
        84,
        static fn(string $line, int $current): array => ['message' => $line],
        static fn(int $current): array => ['percent' => min(83, $current + 1), 'message' => '正在解压 Node.js 安装包']
    );
    if (!$extract['ok']) {
        task_rrmdir_if_available($tmp);
        return runtime_env_job_finish($jobId, false, '解压 Node.js 包失败', [
            'exit_code' => $extract['code'],
            'stderr' => trim((string)$extract['stderr']),
        ]);
    }

    runtime_env_job_update($jobId, ['phase' => '安装文件', 'percent' => 88, 'message' => '正在写入版本目录']);
    $extracted = $tmp . '/node-v' . $version . '-' . $platform;
    if (!is_dir($extracted) || !is_file($extracted . '/bin/node')) {
        task_rrmdir_if_available($tmp);
        return runtime_env_job_finish($jobId, false, 'Node.js 包结构异常：未找到 bin/node', ['percent' => 88]);
    }
    @rename($extracted, $target);
    @chmod($target . '/bin/node', 0755);
    task_rrmdir_if_available($tmp);

    runtime_env_job_update($jobId, ['phase' => '切换版本', 'percent' => 94, 'message' => '正在切换当前 Node.js 版本']);
    $switch = runtime_env_set_node_current($version);
    if (!$switch['ok']) {
        return runtime_env_job_finish($jobId, false, (string)$switch['msg'], ['percent' => 94]);
    }
    return runtime_env_job_finish($jobId, true, 'Node.js ' . $version . ' 已安装并切换为当前版本', ['node' => runtime_env_detect_node()]);
}

function runtime_env_format_bytes(int $bytes): string {
    if ($bytes < 1024) {
        return $bytes . ' B';
    }
    if ($bytes < 1024 * 1024) {
        return number_format($bytes / 1024, 1) . ' KB';
    }
    return number_format($bytes / 1024 / 1024, 1) . ' MB';
}

function runtime_env_run_install_job(string $jobId, string $type, array $args = []): array {
    $jobId = runtime_env_clean_job_id($jobId);
    if ($jobId === '') {
        return ['ok' => false, 'msg' => '无效 job id'];
    }
    if ($type === 'apk') {
        return runtime_env_install_node_apk_job($jobId);
    }
    if ($type === 'version') {
        return runtime_env_install_node_version_job($jobId, (string)($args[0] ?? ''));
    }
    return runtime_env_job_finish($jobId, false, '未知安装类型', ['percent' => 0]);
}

function runtime_env_tail_log(int $bytes = 40000): string {
    if (!is_file(RUNTIME_NODE_LOG_FILE)) {
        return '';
    }
    $size = filesize(RUNTIME_NODE_LOG_FILE);
    if ($size === false) {
        return '';
    }
    $offset = max(0, $size - max(1024, $bytes));
    $raw = @file_get_contents(RUNTIME_NODE_LOG_FILE, false, null, $offset);
    return is_string($raw) ? str_replace("\r\n", "\n", str_replace("\r", "\n", $raw)) : '';
}

function runtime_env_exec(string $command, ?string $cwd = null, array $env = []): array {
    $cwd = ($cwd !== null && is_dir($cwd)) ? $cwd : RUNTIME_ENV_ROOT;
    runtime_env_ensure_dirs();
    $baseEnv = [
        'HOME' => '/home/navwww',
        'USER' => 'navwww',
        'LOGNAME' => 'navwww',
        'PATH' => runtime_env_node_bin_dir() . ':/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
        'SHELL' => '/bin/sh',
        'LANG' => 'en_US.UTF-8',
    ];
    $descriptors = [
        0 => ['file', '/dev/null', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = @proc_open('/bin/sh -lc ' . escapeshellarg($command), $descriptors, $pipes, $cwd, array_merge($baseEnv, $env));
    if (!is_resource($proc)) {
        return ['ok' => false, 'code' => -1, 'stdout' => '', 'stderr' => '无法启动命令', 'command' => $command];
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    return [
        'ok' => $code === 0,
        'code' => $code,
        'stdout' => is_string($stdout) ? $stdout : '',
        'stderr' => is_string($stderr) ? $stderr : '',
        'command' => $command,
    ];
}

function runtime_env_command_exists(string $command): bool {
    $result = runtime_env_exec('command -v ' . escapeshellarg($command) . ' >/dev/null 2>&1');
    return $result['ok'];
}

function runtime_env_sudo_prefix(): string {
    return runtime_env_command_exists('sudo') ? 'sudo -n ' : '';
}

function runtime_env_arch(): string {
    $machine = strtolower(php_uname('m'));
    return match ($machine) {
        'x86_64', 'amd64' => 'x64',
        'aarch64', 'arm64' => 'arm64',
        'armv7l', 'armv7' => 'armv7l',
        default => $machine,
    };
}

function runtime_env_node_platform(): string {
    return 'linux-' . runtime_env_arch() . '-musl';
}

function runtime_env_normalize_node_version(string $version): string {
    $version = trim($version);
    $version = ltrim($version, 'vV');
    if (!preg_match('/^\d+\.\d+\.\d+$/', $version)) {
        return '';
    }
    return $version;
}

function runtime_env_node_version_dir(string $version): string {
    $version = runtime_env_normalize_node_version($version);
    return $version === '' ? '' : RUNTIME_NODE_VERSIONS_DIR . '/' . $version;
}

function runtime_env_node_bin_dir(?string $version = null): string {
    if ($version !== null && $version !== '') {
        $dir = runtime_env_node_version_dir($version);
        return $dir !== '' ? $dir . '/bin' : '';
    }
    return RUNTIME_NODE_CURRENT_LINK . '/bin';
}

function runtime_env_node_binary(string $name = 'node'): string {
    $name = preg_replace('/[^A-Za-z0-9._-]/', '', $name);
    if ($name === '') {
        $name = 'node';
    }
    $current = runtime_env_node_bin_dir() . '/' . $name;
    if (is_file($current) && is_executable($current)) {
        return $current;
    }
    return $name;
}

function runtime_env_installed_node_versions(): array {
    runtime_env_ensure_dirs();
    $rows = [];
    foreach (glob(RUNTIME_NODE_VERSIONS_DIR . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
        $version = basename($dir);
        if (runtime_env_normalize_node_version($version) === '') {
            continue;
        }
        $node = $dir . '/bin/node';
        $npm = $dir . '/bin/npm';
        $rows[] = [
            'version' => $version,
            'path' => $dir,
            'node' => is_file($node),
            'npm' => is_file($npm),
            'size' => runtime_env_dir_size($dir),
        ];
    }
    usort($rows, static fn(array $a, array $b): int => version_compare($b['version'], $a['version']));
    return $rows;
}

function runtime_env_dir_size(string $dir): int {
    if (!is_dir($dir)) {
        return 0;
    }
    $size = 0;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS));
    foreach ($it as $item) {
        if ($item->isFile()) {
            $size += (int)$item->getSize();
        }
    }
    return $size;
}

function runtime_env_node_current_version(): string {
    $cfg = runtime_env_read_config();
    $configured = runtime_env_normalize_node_version((string)($cfg['node']['current_version'] ?? ''));
    if ($configured !== '' && is_dir(runtime_env_node_version_dir($configured))) {
        return $configured;
    }
    if (is_link(RUNTIME_NODE_CURRENT_LINK)) {
        $target = readlink(RUNTIME_NODE_CURRENT_LINK);
        $version = runtime_env_normalize_node_version(basename((string)$target));
        if ($version !== '') {
            return $version;
        }
    }
    return '';
}

function runtime_env_detect_node(): array {
    $nodeBin = runtime_env_node_binary('node');
    $npmBin = runtime_env_node_binary('npm');
    $node = runtime_env_exec(escapeshellarg($nodeBin) . ' --version 2>/dev/null || true');
    $npm = runtime_env_exec(escapeshellarg($npmBin) . ' --version 2>/dev/null || true');
    $systemNode = runtime_env_exec('command -v node 2>/dev/null || true');
    $systemNpm = runtime_env_exec('command -v npm 2>/dev/null || true');
    $cfg = runtime_env_read_config();
    return [
        'arch' => runtime_env_arch(),
        'platform' => runtime_env_node_platform(),
        'current_version' => runtime_env_node_current_version(),
        'node_bin' => trim((string)$nodeBin),
        'node_version' => trim($node['stdout']),
        'npm_bin' => trim((string)$npmBin),
        'npm_version' => trim($npm['stdout']),
        'system_node' => trim($systemNode['stdout']),
        'system_npm' => trim($systemNpm['stdout']),
        'registry' => (string)($cfg['node']['registry'] ?? ''),
        'download_base' => (string)($cfg['node']['download_base'] ?? ''),
        'versions' => runtime_env_installed_node_versions(),
        'log' => runtime_env_tail_log(),
    ];
}

function runtime_env_set_node_current(string $version): array {
    $version = runtime_env_normalize_node_version($version);
    if ($version === '') {
        return ['ok' => false, 'msg' => 'Node.js 版本号无效'];
    }
    $dir = runtime_env_node_version_dir($version);
    if ($dir === '' || !is_dir($dir) || !is_file($dir . '/bin/node')) {
        return ['ok' => false, 'msg' => '该 Node.js 版本未安装'];
    }
    runtime_env_ensure_dirs();
    if (file_exists(RUNTIME_NODE_CURRENT_LINK) || is_link(RUNTIME_NODE_CURRENT_LINK)) {
        if (is_dir(RUNTIME_NODE_CURRENT_LINK) && !is_link(RUNTIME_NODE_CURRENT_LINK)) {
            return ['ok' => false, 'msg' => 'current 路径已存在且不是软链接，请手动处理：' . RUNTIME_NODE_CURRENT_LINK];
        }
        @unlink(RUNTIME_NODE_CURRENT_LINK);
    }
    if (!@symlink($dir, RUNTIME_NODE_CURRENT_LINK)) {
        return ['ok' => false, 'msg' => '创建 current 软链接失败'];
    }
    $cfg = runtime_env_read_config();
    $cfg['node']['current_version'] = $version;
    runtime_env_save_config($cfg);
    runtime_env_append_log('切换 Node.js 当前版本为 ' . $version);
    return ['ok' => true, 'msg' => '已切换到 Node.js ' . $version, 'data' => runtime_env_detect_node()];
}

function runtime_env_save_node_config(array $input): array {
    $registry = trim((string)($input['registry'] ?? ''));
    $downloadBase = trim((string)($input['download_base'] ?? ''));
    if ($registry !== '' && !preg_match('#^https?://#i', $registry)) {
        return ['ok' => false, 'msg' => 'npm registry 必须是 http/https URL'];
    }
    if ($downloadBase !== '' && !preg_match('#^https?://#i', $downloadBase)) {
        return ['ok' => false, 'msg' => '下载源必须是 http/https URL'];
    }
    $cfg = runtime_env_read_config();
    if ($registry !== '') {
        $cfg['node']['registry'] = rtrim($registry, '/');
    }
    if ($downloadBase !== '') {
        $cfg['node']['download_base'] = rtrim($downloadBase, '/');
    }
    runtime_env_save_config($cfg);
    @file_put_contents(RUNTIME_NODE_ROOT . '/.npmrc', 'registry=' . ($cfg['node']['registry'] ?? '') . "\n", LOCK_EX);
    runtime_env_append_log('保存 Node.js 配置');
    return ['ok' => true, 'msg' => '配置已保存', 'data' => runtime_env_detect_node()];
}

function runtime_env_install_node_apk(): array {
    @set_time_limit(0);
    runtime_env_append_log('开始通过 Alpine apk 安装 nodejs npm');
    $cmd = runtime_env_sudo_prefix() . 'apk add --no-cache nodejs npm';
    $result = runtime_env_exec($cmd, RUNTIME_ENV_ROOT);
    runtime_env_append_log('$ ' . $cmd . "\n" . trim(($result['stdout'] ?? '') . "\n" . ($result['stderr'] ?? '')));
    if (!$result['ok']) {
        return [
            'ok' => false,
            'msg' => 'apk 安装 Node.js 失败',
            'data' => $result + ['suggestion' => '请确认容器允许执行 apk，或改用指定版本 musl 包安装。'],
        ];
    }
    return ['ok' => true, 'msg' => 'Node.js/npm 已通过 apk 安装', 'data' => runtime_env_detect_node()];
}

function runtime_env_install_node_version(string $version): array {
    @set_time_limit(0);
    $version = runtime_env_normalize_node_version($version);
    if ($version === '') {
        return ['ok' => false, 'msg' => 'Node.js 版本号无效，请填写如 22.20.0'];
    }
    runtime_env_ensure_dirs();
    $cfg = runtime_env_read_config();
    $platform = runtime_env_node_platform();
    $base = rtrim((string)($cfg['node']['download_base'] ?? 'https://unofficial-builds.nodejs.org/download/release'), '/');
    $archive = 'node-v' . $version . '-' . $platform . '.tar.xz';
    $url = $base . '/v' . $version . '/' . $archive;
    $target = runtime_env_node_version_dir($version);
    $tmp = RUNTIME_NODE_ROOT . '/tmp-' . $version . '-' . bin2hex(random_bytes(3));
    $archivePath = $tmp . '/' . $archive;

    if ($target === '') {
        return ['ok' => false, 'msg' => '目标版本目录无效'];
    }
    if (is_dir($target)) {
        return ['ok' => false, 'msg' => 'Node.js ' . $version . ' 已安装，可直接切换'];
    }
    @mkdir($tmp, 0775, true);
    runtime_env_append_log('开始安装 Node.js ' . $version . ' (' . $platform . ')');

    if (!runtime_env_command_exists('xz')) {
        $xzCmd = runtime_env_sudo_prefix() . 'apk add --no-cache xz';
        $xz = runtime_env_exec($xzCmd, RUNTIME_ENV_ROOT);
        runtime_env_append_log('$ ' . $xzCmd . "\n" . trim(($xz['stdout'] ?? '') . "\n" . ($xz['stderr'] ?? '')));
        if (!$xz['ok']) {
            task_rrmdir_if_available($tmp);
            return [
                'ok' => false,
                'msg' => '安装 xz 解压工具失败',
                'data' => $xz + ['suggestion' => '指定版本安装需要解压 .tar.xz，请先允许 apk 安装 xz。'],
            ];
        }
    }

    $downloadCmd = 'curl -fL --retry 2 --connect-timeout 20 -o ' . escapeshellarg($archivePath) . ' ' . escapeshellarg($url);
    $download = runtime_env_exec($downloadCmd, $tmp);
    runtime_env_append_log('$ ' . $downloadCmd . "\n" . trim(($download['stdout'] ?? '') . "\n" . ($download['stderr'] ?? '')));
    if (!$download['ok']) {
        task_rrmdir_if_available($tmp);
        return [
            'ok' => false,
            'msg' => '下载 Node.js musl 包失败',
            'data' => $download + [
                'url' => $url,
                'suggestion' => '该版本可能没有 ' . $platform . ' 构建，或当前网络无法访问下载源；可换版本或使用 apk 安装。',
            ],
        ];
    }

    $extractCmd = 'tar -xJf ' . escapeshellarg($archivePath) . ' -C ' . escapeshellarg($tmp);
    $extract = runtime_env_exec($extractCmd, $tmp);
    runtime_env_append_log('$ ' . $extractCmd . "\n" . trim(($extract['stdout'] ?? '') . "\n" . ($extract['stderr'] ?? '')));
    if (!$extract['ok']) {
        task_rrmdir_if_available($tmp);
        return ['ok' => false, 'msg' => '解压 Node.js 包失败', 'data' => $extract];
    }
    $extracted = $tmp . '/node-v' . $version . '-' . $platform;
    if (!is_dir($extracted) || !is_file($extracted . '/bin/node')) {
        task_rrmdir_if_available($tmp);
        return ['ok' => false, 'msg' => 'Node.js 包结构异常：未找到 bin/node'];
    }
    @rename($extracted, $target);
    @chmod($target . '/bin/node', 0755);
    task_rrmdir_if_available($tmp);
    $switch = runtime_env_set_node_current($version);
    if (!$switch['ok']) {
        return $switch;
    }
    runtime_env_append_log('Node.js ' . $version . ' 安装完成');
    return ['ok' => true, 'msg' => 'Node.js ' . $version . ' 已安装并切换为当前版本', 'data' => runtime_env_detect_node()];
}

function task_rrmdir_if_available(string $dir): void {
    if (function_exists('task_rrmdir')) {
        task_rrmdir($dir);
        return;
    }
    if (!is_dir($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($dir);
}

function runtime_env_uninstall_node_version(string $version): array {
    $version = runtime_env_normalize_node_version($version);
    if ($version === '') {
        return ['ok' => false, 'msg' => 'Node.js 版本号无效'];
    }
    $dir = runtime_env_node_version_dir($version);
    if ($dir === '' || !is_dir($dir)) {
        return ['ok' => false, 'msg' => '该 Node.js 版本未安装'];
    }
    $current = runtime_env_node_current_version();
    task_rrmdir_if_available($dir);
    if ($current === $version && (file_exists(RUNTIME_NODE_CURRENT_LINK) || is_link(RUNTIME_NODE_CURRENT_LINK))) {
        @unlink(RUNTIME_NODE_CURRENT_LINK);
        $cfg = runtime_env_read_config();
        $cfg['node']['current_version'] = '';
        runtime_env_save_config($cfg);
    }
    runtime_env_append_log('卸载 Node.js ' . $version);
    return ['ok' => true, 'msg' => '已卸载 Node.js ' . $version, 'data' => runtime_env_detect_node()];
}

function runtime_env_test_node(): array {
    $node = runtime_env_node_binary('node');
    $npm = runtime_env_node_binary('npm');
    $nodeRun = runtime_env_exec(escapeshellarg($node) . ' -e ' . escapeshellarg('console.log(JSON.stringify({node:process.version, platform:process.platform, arch:process.arch}))'));
    if (!$nodeRun['ok']) {
        return ['ok' => false, 'msg' => 'Node.js 测试失败', 'data' => $nodeRun];
    }
    $npmRun = runtime_env_exec(escapeshellarg($npm) . ' --version');
    $payload = json_decode(trim($nodeRun['stdout']), true);
    return [
        'ok' => $npmRun['ok'],
        'msg' => $npmRun['ok'] ? 'Node.js/npm 可用' : 'Node.js 可用，但 npm 测试失败',
        'data' => [
            'node' => is_array($payload) ? $payload : trim($nodeRun['stdout']),
            'npm' => trim($npmRun['stdout']),
            'npm_error' => trim($npmRun['stderr']),
        ],
    ];
}

function runtime_env_fetch_node_versions(): array {
    $cfg = runtime_env_read_config();
    $base = rtrim((string)($cfg['node']['download_base'] ?? 'https://unofficial-builds.nodejs.org/download/release'), '/');
    $url = $base . '/index.json';
    $result = runtime_env_exec('curl -fL --connect-timeout 15 ' . escapeshellarg($url));
    if (!$result['ok']) {
        return ['ok' => false, 'msg' => '获取版本列表失败', 'data' => $result + ['url' => $url]];
    }
    $rows = json_decode($result['stdout'], true);
    if (!is_array($rows)) {
        return ['ok' => false, 'msg' => '版本列表不是有效 JSON', 'data' => ['url' => $url]];
    }
    $platform = runtime_env_node_platform();
    $versions = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $files = is_array($row['files'] ?? null) ? $row['files'] : [];
        if (!in_array($platform, $files, true)) {
            continue;
        }
        $version = runtime_env_normalize_node_version((string)($row['version'] ?? ''));
        if ($version === '') {
            continue;
        }
        $versions[] = [
            'version' => $version,
            'date' => (string)($row['date'] ?? ''),
            'lts' => $row['lts'] ?? false,
        ];
        if (count($versions) >= 80) {
            break;
        }
    }
    return ['ok' => true, 'msg' => 'ok', 'data' => ['platform' => $platform, 'versions' => $versions]];
}
