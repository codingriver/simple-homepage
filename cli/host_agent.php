#!/usr/bin/env php
<?php
declare(strict_types=1);

function host_agent_arg_value(array $argv, string $prefix, string $default = ''): string {
    foreach ($argv as $arg) {
        if (strpos($arg, $prefix) === 0) {
            return substr($arg, strlen($prefix));
        }
    }
    return $default;
}

function host_agent_json_decode(string $body): array {
    if (trim($body) === '') {
        return [];
    }
    $decoded = json_decode($body, true);
    return is_array($decoded) ? $decoded : [];
}

function host_agent_json_response(array $payload, int $status = 200): string {
    $reason = [
        200 => 'OK',
        401 => 'Unauthorized',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        500 => 'Internal Server Error',
        422 => 'Unprocessable Entity',
    ][$status] ?? 'OK';

    $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return "HTTP/1.1 {$status} {$reason}\r\n"
        . "Content-Type: application/json; charset=utf-8\r\n"
        . 'Content-Length: ' . strlen((string)$body) . "\r\n"
        . "Connection: close\r\n\r\n"
        . $body;
}

function host_agent_sim_state_file(string $root): string {
    return rtrim($root, '/') . '/var/lib/host-agent/ssh_service_state.json';
}

function host_agent_default_ssh_config(): string {
    return implode("\n", [
        '# Managed by host-agent',
        'Port 22',
        'PermitRootLogin prohibit-password',
        'PasswordAuthentication yes',
        'PubkeyAuthentication yes',
        'ChallengeResponseAuthentication no',
        'UsePAM yes',
        'X11Forwarding no',
        'PrintMotd no',
        'Subsystem sftp /usr/lib/openssh/sftp-server',
        '',
    ]);
}

function host_agent_ensure_parent_dir(string $path): void {
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
}

function host_agent_sim_fs_meta_file(string $root): string {
    return rtrim($root, '/') . '/var/lib/host-agent/fs_meta.json';
}

function host_agent_sim_fs_meta_load(string $root): array {
    $path = host_agent_sim_fs_meta_file($root);
    if (!is_file($path)) {
        return ['paths' => []];
    }
    $decoded = json_decode((string)file_get_contents($path), true);
    if (!is_array($decoded)) {
        return ['paths' => []];
    }
    $decoded['paths'] = is_array($decoded['paths'] ?? null) ? $decoded['paths'] : [];
    return $decoded;
}

function host_agent_sim_fs_meta_save(string $root, array $data): void {
    $path = host_agent_sim_fs_meta_file($root);
    host_agent_ensure_parent_dir($path);
    file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

function host_agent_ssh_config_path(string $root, string $mode): string {
    return rtrim($root, '/') . '/etc/ssh/sshd_config';
}

function host_agent_read_file(string $path): string {
    return is_file($path) ? (string)file_get_contents($path) : '';
}

function host_agent_is_utf8(string $content): bool {
    return $content === '' || preg_match('//u', $content) === 1;
}

function host_agent_file_payload(string $path, string $content): array {
    $isBinary = !host_agent_is_utf8($content);
    $payload = [
        'ok' => true,
        'path' => $path,
        'size' => strlen($content),
        'content_base64' => base64_encode($content),
        'encoding' => $isBinary ? 'base64' : 'utf-8',
        'is_binary' => $isBinary,
    ];
    if (!$isBinary) {
        $payload['content'] = $content;
    }
    return $payload;
}

function host_agent_decode_content_payload(array $payload): array {
    if (array_key_exists('content_base64', $payload)) {
        $decoded = base64_decode((string)$payload['content_base64'], true);
        if ($decoded === false) {
            return ['ok' => false, 'msg' => 'Base64 内容无效'];
        }
        return ['ok' => true, 'content' => $decoded];
    }
    return ['ok' => true, 'content' => (string)($payload['content'] ?? '')];
}

function host_agent_parse_encoded_pairs(string $output): array {
    $result = [];
    foreach (preg_split('/\r?\n/', trim($output)) ?: [] as $line) {
        if ($line === '') {
            continue;
        }
        [$key, $value] = array_pad(explode("\t", $line, 2), 2, '');
        if ($key === '') {
            continue;
        }
        $decoded = base64_decode($value, true);
        $result[$key] = $decoded === false ? $value : $decoded;
    }
    return $result;
}

function host_agent_sim_state(string $root): array {
    $path = host_agent_sim_state_file($root);
    if (!is_file($path)) {
        host_agent_ensure_parent_dir($path);
        $default = [
            'service_manager' => 'simulate',
            'service_name' => 'ssh',
            'running' => true,
            'enabled' => true,
            'updated_at' => date('Y-m-d H:i:s'),
        ];
        file_put_contents($path, json_encode($default, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
        return $default;
    }
    $decoded = json_decode((string)file_get_contents($path), true);
    return is_array($decoded) ? $decoded : [];
}

function host_agent_save_sim_state(string $root, array $state): void {
    $path = host_agent_sim_state_file($root);
    host_agent_ensure_parent_dir($path);
    $state['updated_at'] = date('Y-m-d H:i:s');
    file_put_contents($path, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

function host_agent_ensure_ssh_config(string $root, string $mode): string {
    $path = host_agent_ssh_config_path($root, $mode);
    if (!is_file($path)) {
        host_agent_ensure_parent_dir($path);
        file_put_contents($path, host_agent_default_ssh_config(), LOCK_EX);
    }
    return $path;
}

function host_agent_proc_run(array $command, ?string $stdin = null, array $env = []): array {
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $descriptors, $pipes, null, $env ?: null);
    if (!is_resource($process)) {
        return ['ok' => false, 'code' => 1, 'stdout' => '', 'stderr' => 'proc_open failed'];
    }
    if ($stdin !== null) {
        fwrite($pipes[0], $stdin);
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);
    return [
        'ok' => $code === 0,
        'code' => $code,
        'stdout' => is_string($stdout) ? $stdout : '',
        'stderr' => is_string($stderr) ? $stderr : '',
    ];
}

function host_agent_host_shell(string $script, ?string $stdin = null): array {
    $mode = (string)(getenv('HOST_AGENT_MODE') ?: 'host');
    if ($mode === 'simulate') {
        // simulate 模式下不进入宿主机 namespace，直接在容器内执行
        // 使用 -c 而非 -lc，避免加载用户 profile 中的错误配置（如无效 alias）
        return host_agent_proc_run(['sh', '-c', $script], $stdin);
    }
    // 使用 -c 而非 -lc，避免宿主机 /root/.profile 中的错误配置干扰系统命令执行
    return host_agent_proc_run(
        ['/usr/bin/nsenter', '-t', '1', '-m', '-u', '-i', '-n', '-p', 'sh', '-c', $script],
        $stdin
    );
}

function host_agent_parse_query_params(string $target): array {
    $query = (string)parse_url($target, PHP_URL_QUERY);
    $params = [];
    parse_str($query, $params);
    return is_array($params) ? $params : [];
}

function host_agent_normalize_path_dots(string $path): string {
    $parts = explode('/', $path);
    $resolved = [];
    foreach ($parts as $part) {
        if ($part === '' || $part === '.') {
            continue;
        }
        if ($part === '..') {
            array_pop($resolved);
        } else {
            $resolved[] = $part;
        }
    }
    return '/' . implode('/', $resolved);
}

function host_agent_safe_local_path(string $root, string $path): array {
    $root = rtrim($root, '/');
    $relative = '/' . ltrim(trim($path), '/');
    $candidate = $root . $relative;
    $normalized = preg_replace('#/+#', '/', $candidate);
    // 手动规范化 . 和 ..，防止路径遍历；不解析符号链接（realpath 在跨 mount namespace 时不可靠）
    $normalized = host_agent_normalize_path_dots($normalized);

    $dir = dirname($normalized);
    $base = basename($normalized);
    if (!is_dir($dir)) {
        $probe = $dir;
        while ($probe !== '/' && !is_dir($probe)) {
            $probe = dirname($probe);
        }
        $realDir = @realpath($probe);
        if ($realDir !== false && strpos($realDir, $root) === 0) {
            $normalized = $realDir . substr($normalized, strlen($probe));
        }
    } else {
        $realDir = @realpath($dir);
        if ($realDir !== false && strpos($realDir, $root) === 0) {
            $normalized = $realDir . '/' . $base;
        }
    }

    if (strpos($normalized, $root) !== 0) {
        return ['ok' => false, 'msg' => '非法路径'];
    }
    return ['ok' => true, 'path' => $normalized, 'relative' => $relative];
}

function host_agent_local_display_path(string $root, string $actualPath): string {
    $root = rtrim($root, '/');
    if (strpos($actualPath, $root) === 0) {
        $relative = substr($actualPath, strlen($root));
        return $relative !== '' ? $relative : '/';
    }
    return $actualPath;
}

function host_agent_host_service_manager(): string {
    // 检测 systemd：systemctl 存在且 /run/systemd/system 目录存在（避免容器内误报）
    $result = host_agent_host_shell('if command -v systemctl >/dev/null 2>&1 && [ -d /run/systemd/system ]; then echo systemd; elif command -v service >/dev/null 2>&1; then echo service; else echo unknown; fi');
    return trim($result['stdout']) !== '' ? trim($result['stdout']) : 'unknown';
}

function host_agent_host_service_name(string $manager): string {
    $candidates = ['ssh', 'sshd'];
    foreach ($candidates as $candidate) {
        if ($manager === 'systemd') {
            $result = host_agent_host_shell('systemctl show -p LoadState --value ' . escapeshellarg($candidate . '.service') . ' 2>/dev/null');
            if (trim($result['stdout']) === 'loaded') {
                return $candidate;
            }
        } elseif ($manager === 'service') {
            $result = host_agent_host_shell('service ' . escapeshellarg($candidate) . ' status >/tmp/host-agent-service-check.log 2>&1; code=$?; cat /tmp/host-agent-service-check.log; rm -f /tmp/host-agent-service-check.log; exit 0');
            $text = strtolower($result['stdout'] . "\n" . $result['stderr']);
            if (strpos($text, 'unrecognized service') === false && strpos($text, 'not found') === false) {
                return $candidate;
            }
        }
    }
    return 'ssh';
}

function host_agent_live_ssh_status(string $root, string $mode): array {
    $configPath = host_agent_ensure_ssh_config($root, $mode);
    if ($mode !== 'host') {
        $state = host_agent_sim_state($root);
        return [
            'ok' => true,
            'installed' => (bool)($state['installed'] ?? true),
            'service_manager' => (string)($state['service_manager'] ?? 'simulate'),
            'service_name' => (string)($state['service_name'] ?? 'ssh'),
            'running' => (bool)($state['running'] ?? true),
            'enabled' => (bool)($state['enabled'] ?? true),
            'config_path' => $configPath,
            'mode' => $mode,
            'updated_at' => (string)($state['updated_at'] ?? ''),
        ];
    }

    $manager = host_agent_host_service_manager();
    $service = host_agent_host_service_name($manager);
    $running = false;
    $enabled = null;
    $details = '';

    if ($manager === 'systemd') {
        $active = host_agent_host_shell('systemctl is-active ' . escapeshellarg($service . '.service') . ' 2>/dev/null || true');
        $enabledResult = host_agent_host_shell('systemctl is-enabled ' . escapeshellarg($service . '.service') . ' 2>/dev/null || true');
        $running = trim($active['stdout']) === 'active';
        $enabledValue = trim($enabledResult['stdout']);
        $enabled = in_array($enabledValue, ['enabled', 'static'], true) ? true : (in_array($enabledValue, ['disabled', 'masked'], true) ? false : null);
        $details = trim($active['stdout'] . "\n" . $enabledResult['stdout']);
    } elseif ($manager === 'service') {
        $active = host_agent_host_shell('service ' . escapeshellarg($service) . ' status 2>&1 || true');
        $text = strtolower(trim($active['stdout'] . "\n" . $active['stderr']));
        $running = (str_contains($text, 'running') || str_contains($text, 'started') || str_contains($text, 'active'))
            && !str_contains($text, 'not running')
            && !str_contains($text, 'not active')
            && !str_contains($text, 'stopped');
        $details = trim($active['stdout'] . "\n" . $active['stderr']);
    } else {
        $details = '未检测到 systemd / service 管理器';
    }

    return [
        'ok' => true,
        'installed' => host_agent_detect_sshd_binary($root, $mode) !== '',
        'service_manager' => $manager,
        'service_name' => $service,
        'running' => $running,
        'enabled' => $enabled,
        'config_path' => $configPath,
        'mode' => $mode,
        'details' => $details,
        'updated_at' => date('Y-m-d H:i:s'),
    ];
}

function host_agent_read_ssh_config_payload(string $root, string $mode): array {
    $path = host_agent_ensure_ssh_config($root, $mode);
    return [
        'ok' => true,
        'path' => $path,
        'content' => host_agent_read_file($path),
        'size' => is_file($path) ? filesize($path) : 0,
    ];
}

function host_agent_save_ssh_config(string $root, string $mode, string $content): array {
    if (strlen($content) > 512 * 1024) {
        return ['ok' => false, 'msg' => '配置内容过大'];
    }
    $path = host_agent_ensure_ssh_config($root, $mode);
    $backup = $path . '.bak.' . date('Ymd_His');
    $old = host_agent_read_file($path);
    file_put_contents($backup, $old, LOCK_EX);
    file_put_contents($path, $content, LOCK_EX);
    return [
        'ok' => true,
        'path' => $path,
        'backup_path' => $backup,
        'size' => strlen($content),
        'updated_at' => date('Y-m-d H:i:s'),
    ];
}

function host_agent_ssh_service_action(string $root, string $mode, string $action): array {
    $allowed = ['start', 'stop', 'restart', 'reload'];
    if (!in_array($action, $allowed, true)) {
        return ['ok' => false, 'msg' => '不支持的操作'];
    }

    if ($mode !== 'host') {
        $state = host_agent_sim_state($root);
        if ($action === 'start' || $action === 'restart' || $action === 'reload') {
            $state['running'] = true;
        } elseif ($action === 'stop') {
            $state['running'] = false;
        }
        host_agent_save_sim_state($root, $state);
        return [
            'ok' => true,
            'msg' => 'simulate 模式已执行 ' . $action,
            'status' => host_agent_live_ssh_status($root, $mode),
        ];
    }

    $status = host_agent_live_ssh_status($root, $mode);
    if (empty($status['installed'])) {
        return [
            'ok' => false,
            'msg' => 'SSH 服务未安装，请先点击「安装 SSH 服务」后再执行 ' . $action . ' 操作',
            'status' => $status,
        ];
    }
    $manager = (string)($status['service_manager'] ?? 'unknown');
    $service = (string)($status['service_name'] ?? 'ssh');
    if ($manager === 'systemd') {
        $result = host_agent_host_shell('systemctl ' . escapeshellarg($action) . ' ' . escapeshellarg($service . '.service') . ' 2>&1');
    } elseif ($manager === 'service') {
        $result = host_agent_host_shell('service ' . escapeshellarg($service) . ' ' . escapeshellarg($action) . ' 2>&1');
    } else {
        return ['ok' => false, 'msg' => '未检测到可用的 SSH 服务管理器'];
    }

    $afterStatus = host_agent_live_ssh_status($root, $mode);
    $commandFailed = !$result['ok'];
    $stateMismatch = false;
    if (!$commandFailed && ($action === 'start' || $action === 'restart')) {
        $stateMismatch = empty($afterStatus['running']);
    } elseif (!$commandFailed && $action === 'stop') {
        $stateMismatch = !empty($afterStatus['running']);
    }

    if ($commandFailed || $stateMismatch) {
        $output = trim($result['stdout'] . "\n" . $result['stderr']);
        $hint = $stateMismatch ? '（命令返回成功，但服务状态未改变）' : '';
        return [
            'ok' => false,
            'msg' => 'SSH 服务 ' . $action . ' 失败' . $hint . '：' . ($output !== '' ? $output : '执行失败'),
            'command_output' => $output,
            'status' => $afterStatus,
        ];
    }

    return [
        'ok' => true,
        'msg' => 'SSH 服务已执行 ' . $action,
        'command_output' => trim($result['stdout'] . "\n" . $result['stderr']),
        'status' => $afterStatus,
    ];
}

function host_agent_detect_sshd_binary(string $root, string $mode): string {
    if ($mode !== 'host') {
        return '/usr/sbin/sshd';
    }
    $result = host_agent_host_shell('command -v sshd || true');
    return trim($result['stdout']);
}

function host_agent_validate_ssh_config(string $root, string $mode, string $content): array {
    if (trim($content) === '') {
        return ['ok' => false, 'msg' => 'SSH 配置不能为空'];
    }
    if ($mode !== 'host') {
        $lines = preg_split('/\r?\n/', $content) ?: [];
        foreach ($lines as $index => $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }
            if (!preg_match('/^[A-Za-z][A-Za-z0-9]+(\s+.+)?$/', $trimmed)) {
                return ['ok' => false, 'msg' => '第 ' . ($index + 1) . ' 行格式无效'];
            }
        }
        return ['ok' => true, 'msg' => 'simulate 模式校验通过'];
    }

    $temp = '/tmp/host-agent-sshd-config-' . bin2hex(random_bytes(4)) . '.conf';
    $put = host_agent_host_shell('cat > ' . escapeshellarg($temp), $content);
    if (!$put['ok']) {
        return ['ok' => false, 'msg' => '临时配置文件写入失败'];
    }
    $binary = host_agent_detect_sshd_binary($root, $mode);
    if ($binary === '') {
        host_agent_host_shell('rm -f ' . escapeshellarg($temp));
        return ['ok' => false, 'msg' => '宿主机未检测到 sshd，可先执行 SSH 安装'];
    }
    $check = host_agent_host_shell($binary . ' -t -f ' . escapeshellarg($temp) . ' 2>&1');
    host_agent_host_shell('rm -f ' . escapeshellarg($temp));
    return [
        'ok' => $check['ok'],
        'msg' => $check['ok'] ? '配置校验通过' : trim($check['stderr'] ?: $check['stdout'] ?: 'sshd -t 校验失败'),
    ];
}

function host_agent_parse_ssh_options(string $content): array {
    $defaults = [
        'port' => '22',
        'listenaddress' => '',
        'passwordauthentication' => 'yes',
        'pubkeyauthentication' => 'yes',
        'permitrootlogin' => 'prohibit-password',
        'allowusers' => '',
        'allowgroups' => '',
        'x11forwarding' => 'no',
        'maxauthtries' => '6',
        'clientaliveinterval' => '0',
        'clientalivecountmax' => '3',
    ];
    $parsed = $defaults;
    $lines = preg_split('/\r?\n/', $content) ?: [];
    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '#')) {
            continue;
        }
        if (!preg_match('/^([A-Za-z][A-Za-z0-9]+)\s+(.+)$/', $trimmed, $matches)) {
            continue;
        }
        $key = strtolower($matches[1]);
        if (array_key_exists($key, $parsed)) {
            $parsed[$key] = trim($matches[2]);
        }
    }
    return $parsed;
}

function host_agent_apply_structured_ssh_options(string $content, array $options): string {
    $map = [
        'Port' => (string)($options['port'] ?? '22'),
        'ListenAddress' => trim((string)($options['listen_address'] ?? '')),
        'PasswordAuthentication' => !empty($options['password_auth']) ? 'yes' : 'no',
        'PubkeyAuthentication' => !empty($options['pubkey_auth']) ? 'yes' : 'no',
        'PermitRootLogin' => (string)($options['permit_root_login'] ?? 'prohibit-password'),
        'AllowUsers' => trim((string)($options['allow_users'] ?? '')),
        'AllowGroups' => trim((string)($options['allow_groups'] ?? '')),
        'X11Forwarding' => !empty($options['x11_forwarding']) ? 'yes' : 'no',
        'MaxAuthTries' => trim((string)($options['max_auth_tries'] ?? '6')),
        'ClientAliveInterval' => trim((string)($options['client_alive_interval'] ?? '0')),
        'ClientAliveCountMax' => trim((string)($options['client_alive_count_max'] ?? '3')),
    ];
    $lines = preg_split('/\r?\n/', $content) ?: [];
    $seen = [];
    foreach ($lines as $index => $line) {
        foreach ($map as $directive => $value) {
            if (preg_match('/^\s*#?\s*' . preg_quote($directive, '/') . '\s+/i', $line)) {
                if ($value === '') {
                    $lines[$index] = '# ' . $directive . ' removed by host-agent';
                } else {
                    $lines[$index] = $directive . ' ' . $value;
                }
                $seen[$directive] = true;
                break;
            }
        }
    }
    foreach ($map as $directive => $value) {
        if ($value !== '' && empty($seen[$directive])) {
            $lines[] = $directive . ' ' . $value;
        }
    }
    return trim(implode("\n", $lines)) . "\n";
}

function host_agent_ssh_diff(string $oldContent, string $newContent): array {
    $oldLines = preg_split('/\r?\n/', trim($oldContent)) ?: [];
    $newLines = preg_split('/\r?\n/', trim($newContent)) ?: [];
    $max = max(count($oldLines), count($newLines));
    $diff = [];
    for ($i = 0; $i < $max; $i++) {
        $old = $oldLines[$i] ?? '';
        $new = $newLines[$i] ?? '';
        if ($old === $new) {
            continue;
        }
        if ($old !== '') {
            $diff[] = '- ' . $old;
        }
        if ($new !== '') {
            $diff[] = '+ ' . $new;
        }
        if (count($diff) >= 200) {
            break;
        }
    }
    return $diff;
}

function host_agent_ssh_risk_warnings(array $oldStructured, array $newStructured): array {
    $warnings = [];
    if (($newStructured['passwordauthentication'] ?? 'yes') !== 'yes' && ($newStructured['pubkeyauthentication'] ?? 'yes') !== 'yes') {
        $warnings[] = '密码登录和公钥登录同时关闭，可能导致 SSH 无法登录';
    }
    if (($oldStructured['port'] ?? '22') !== ($newStructured['port'] ?? '22')) {
        $warnings[] = 'SSH 端口发生变化，请确认防火墙和安全组已放通新端口';
    }
    if (($oldStructured['permitrootlogin'] ?? '') !== ($newStructured['permitrootlogin'] ?? '') && ($newStructured['permitrootlogin'] ?? '') === 'no') {
        $warnings[] = '已关闭 root 登录，请确认存在可用的非 root 管理账号';
    }
    return $warnings;
}

function host_agent_ssh_apply_result(string $oldContent, string $newContent, array $saveResult): array {
    return $saveResult + [
        'diff_lines' => host_agent_ssh_diff($oldContent, $newContent),
        'warnings' => host_agent_ssh_risk_warnings(host_agent_parse_ssh_options($oldContent), host_agent_parse_ssh_options($newContent)),
    ];
}

function host_agent_restore_last_ssh_backup(string $root, string $mode): array {
    $path = host_agent_ensure_ssh_config($root, $mode);
    $pattern = $path . '.bak.*';
    $matches = glob($pattern) ?: [];
    rsort($matches);
    $backup = $matches[0] ?? '';
    if ($backup === '' || !is_file($backup)) {
        return ['ok' => false, 'msg' => '未找到可恢复的 SSH 配置备份'];
    }
    file_put_contents($path, (string)file_get_contents($backup), LOCK_EX);
    return ['ok' => true, 'msg' => '已恢复最近一次 SSH 配置备份', 'path' => $path, 'backup_path' => $backup];
}

function host_agent_ssh_enable_toggle(string $root, string $mode, bool $enabled): array {
    if ($mode !== 'host') {
        $state = host_agent_sim_state($root);
        $state['enabled'] = $enabled;
        host_agent_save_sim_state($root, $state);
        return ['ok' => true, 'msg' => 'simulate 模式已' . ($enabled ? '启用' : '禁用') . ' SSH 开机启动', 'status' => host_agent_live_ssh_status($root, $mode)];
    }

    $status = host_agent_live_ssh_status($root, $mode);
    if (empty($status['installed'])) {
        return [
            'ok' => false,
            'msg' => 'SSH 服务未安装，无法设置开机启动。请先点击「安装 SSH 服务」',
            'status' => $status,
        ];
    }
    $manager = (string)($status['service_manager'] ?? 'unknown');
    $service = (string)($status['service_name'] ?? 'ssh');
    if ($manager === 'systemd') {
        $result = host_agent_host_shell('systemctl ' . ($enabled ? 'enable' : 'disable') . ' ' . escapeshellarg($service . '.service') . ' 2>&1');
    } elseif ($manager === 'service') {
        $cmd = $enabled
            ? 'if command -v update-rc.d >/dev/null 2>&1; then update-rc.d ' . escapeshellarg($service) . ' defaults; elif command -v chkconfig >/dev/null 2>&1; then chkconfig ' . escapeshellarg($service) . ' on; else echo "update-rc.d/chkconfig not available"; exit 1; fi'
            : 'if command -v update-rc.d >/dev/null 2>&1; then update-rc.d ' . escapeshellarg($service) . ' disable; elif command -v chkconfig >/dev/null 2>&1; then chkconfig ' . escapeshellarg($service) . ' off; else echo "update-rc.d/chkconfig not available"; exit 1; fi';
        $result = host_agent_host_shell($cmd . ' 2>&1');
    } else {
        return ['ok' => false, 'msg' => '未检测到支持自启管理的服务管理器'];
    }

    $errorOutput = trim($result['stderr'] ?: $result['stdout'] ?: '操作失败');
    if (str_contains(strtolower($errorOutput), 'cannot execute: required file not found')) {
        $errorOutput = '当前环境缺少自启管理所需的依赖（如 Perl），无法设置 SSH 开机启动。SSH 服务本身可正常手动启停。';
    }
    return [
        'ok' => $result['ok'],
        'msg' => $result['ok'] ? ('SSH 已' . ($enabled ? '启用' : '禁用') . '开机启动') : $errorOutput,
        'status' => host_agent_live_ssh_status($root, $mode),
    ];
}

function host_agent_install_ssh_service(string $root, string $mode): array {
    if ($mode !== 'host') {
        $state = host_agent_sim_state($root);
        $state['installed'] = true;
        $state['running'] = true;
        $state['enabled'] = true;
        host_agent_save_sim_state($root, $state);
        host_agent_ensure_ssh_config($root, $mode);
        return ['ok' => true, 'msg' => 'simulate 模式已模拟安装 openssh-server'];
    }

    $detect = host_agent_host_shell('if command -v apt-get >/dev/null 2>&1; then echo apt; elif command -v dnf >/dev/null 2>&1; then echo dnf; elif command -v yum >/dev/null 2>&1; then echo yum; elif command -v apk >/dev/null 2>&1; then echo apk; else echo unknown; fi');
    $manager = trim($detect['stdout']);
    if ($manager === 'apt') {
        $result = host_agent_host_shell('DEBIAN_FRONTEND=noninteractive apt-get update && DEBIAN_FRONTEND=noninteractive apt-get install -y openssh-server 2>&1');
    } elseif ($manager === 'dnf') {
        $result = host_agent_host_shell('dnf install -y openssh-server 2>&1');
    } elseif ($manager === 'yum') {
        $result = host_agent_host_shell('yum install -y openssh-server 2>&1');
    } elseif ($manager === 'apk') {
        $result = host_agent_host_shell('apk add --no-cache openssh-server 2>&1');
    } else {
        return ['ok' => false, 'msg' => '未识别宿主机包管理器，无法自动安装 openssh-server'];
    }

    // mac Docker Desktop / 精简环境兼容：修复不完整的 openssh-server 安装
    $fixupScript = '
        # 创建 privilege separation 用户（Docker Desktop VM 等精简环境可能缺失）
        if ! id sshd >/dev/null 2>&1; then
            useradd -r -s /usr/sbin/nologin -d /run/sshd sshd 2>/dev/null || adduser --system --shell /usr/sbin/nologin --home /run/sshd --no-create-home --group sshd 2>/dev/null || true
        fi
        # 确保 /run/sshd 目录存在
        mkdir -p /run/sshd && chmod 0755 /run/sshd
        # 生成 host keys（若缺失）
        if command -v ssh-keygen >/dev/null 2>&1; then
            ssh-keygen -A 2>/dev/null || true
        fi
        # 补充 Perl（Docker Desktop VM 上 update-rc.d 依赖 Perl 但可能缺失）
        if [ -f /usr/sbin/update-rc.d ] && [ ! -x /usr/bin/perl ]; then
            if command -v apt-get >/dev/null 2>&1; then
                DEBIAN_FRONTEND=noninteractive apt-get install --reinstall -y perl-base 2>/dev/null || true
            fi
        fi
        # 尝试修复 dpkg 配置（Docker Desktop VM 上 postinst 可能因缺少工具而失败）
        if command -v dpkg >/dev/null 2>&1; then
            dpkg --configure -a 2>/dev/null || true
        fi
    ';
    $fixup = host_agent_host_shell($fixupScript);

    $hasSshd = host_agent_detect_sshd_binary($root, $mode) !== '';
    if ($hasSshd) {
        host_agent_ssh_enable_toggle($root, $mode, true);
        host_agent_ssh_service_action($root, $mode, 'start');
        return [
            'ok' => true,
            'msg' => 'SSH 服务已安装并尝试启动',
            'output' => trim($result['stdout'] . "\n" . $result['stderr'] . "\n" . $fixup['stdout'] . "\n" . $fixup['stderr']),
        ];
    }

    return [
        'ok' => false,
        'msg' => trim($result['stderr'] ?: $result['stdout'] ?: '安装失败'),
        'output' => trim($result['stdout'] . "\n" . $result['stderr'] . "\n" . $fixup['stdout'] . "\n" . $fixup['stderr']),
    ];
}

function host_agent_managed_block_replace(string $content, string $startMarker, string $endMarker, string $blockContent): string {
    $pattern = '/' . preg_quote($startMarker, '/') . '.*?' . preg_quote($endMarker, '/') . '\n?/s';
    $replacement = $startMarker . "\n" . rtrim($blockContent) . "\n" . $endMarker . "\n";
    if (preg_match($pattern, $content) === 1) {
        return (string)preg_replace($pattern, $replacement, $content, 1);
    }
    return rtrim($content) . "\n\n" . $replacement;
}

function host_agent_managed_block_extract(string $content, string $startMarker, string $endMarker): string {
    $pattern = '/' . preg_quote($startMarker, '/') . '\n?(.*?)\n?' . preg_quote($endMarker, '/') . '/s';
    if (preg_match($pattern, $content, $matches) !== 1) {
        return '';
    }
    return trim((string)($matches[1] ?? ''));
}

function host_agent_share_service_sim_state_file(string $root): string {
    return rtrim($root, '/') . '/var/lib/host-agent/share_services.json';
}

function host_agent_share_service_sim_state(string $root): array {
    $path = host_agent_share_service_sim_state_file($root);
    if (!is_file($path)) {
        host_agent_ensure_parent_dir($path);
        $default = [
            'smb' => [
                'installed' => false,
                'running' => false,
                'enabled' => false,
                'updated_at' => date('Y-m-d H:i:s'),
            ],
            'ftp' => [
                'installed' => false,
                'running' => false,
                'enabled' => false,
                'updated_at' => date('Y-m-d H:i:s'),
            ],
        ];
        file_put_contents($path, json_encode($default, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
        return $default;
    }
    $decoded = json_decode((string)file_get_contents($path), true);
    return is_array($decoded) ? $decoded : [];
}

function host_agent_share_service_sim_save(string $root, array $state): void {
    $path = host_agent_share_service_sim_state_file($root);
    host_agent_ensure_parent_dir($path);
    foreach (['smb', 'ftp'] as $name) {
        if (!is_array($state[$name] ?? null)) {
            $state[$name] = ['installed' => false, 'running' => false, 'enabled' => false];
        }
        $state[$name]['updated_at'] = date('Y-m-d H:i:s');
    }
    file_put_contents($path, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

function host_agent_sftp_block_markers(): array {
    return ['# >>> HOST-AGENT SFTP POLICIES BEGIN >>>', '# <<< HOST-AGENT SFTP POLICIES END <<<'];
}

function host_agent_smb_block_markers(): array {
    return ['# >>> HOST-AGENT SMB SHARES BEGIN >>>', '# <<< HOST-AGENT SMB SHARES END <<<'];
}

function host_agent_ftp_block_markers(): array {
    return ['# >>> HOST-AGENT FTP SETTINGS BEGIN >>>', '# <<< HOST-AGENT FTP SETTINGS END <<<'];
}

function host_agent_smb_config_path(string $root, string $mode): string {
    return rtrim($root, '/') . '/etc/samba/smb.conf';
}

function host_agent_ftp_config_path(string $root, string $mode): string {
    return rtrim($root, '/') . '/etc/vsftpd.conf';
}

function host_agent_ftp_userlist_path(string $root, string $mode): string {
    return rtrim($root, '/') . '/etc/vsftpd.userlist';
}

function host_agent_ensure_smb_config(string $root, string $mode): string {
    $path = host_agent_smb_config_path($root, $mode);
    if (!is_file($path)) {
        host_agent_ensure_parent_dir($path);
        file_put_contents($path, "[global]\n   workgroup = WORKGROUP\n   server string = host-agent samba\n   map to guest = Bad User\n", LOCK_EX);
    }
    return $path;
}

function host_agent_ensure_ftp_config(string $root, string $mode): string {
    $path = host_agent_ftp_config_path($root, $mode);
    if (!is_file($path)) {
        host_agent_ensure_parent_dir($path);
        file_put_contents($path, "listen=YES\nlisten_port=21\nanonymous_enable=NO\nlocal_enable=YES\nwrite_enable=YES\nchroot_local_user=YES\n", LOCK_EX);
    }
    $userlist = host_agent_ftp_userlist_path($root, $mode);
    if (!is_file($userlist)) {
        host_agent_ensure_parent_dir($userlist);
        file_put_contents($userlist, '', LOCK_EX);
    }
    return $path;
}

function host_agent_generic_service_meta(string $service): array {
    $service = strtolower(trim($service));
    $defs = [
        'smb' => [
            'label' => 'SMB',
            'packages' => ['apt' => 'samba', 'dnf' => 'samba', 'yum' => 'samba', 'apk' => 'samba'],
            'service_candidates' => ['smbd', 'samba'],
            'config_path' => 'smb.conf',
        ],
        'ftp' => [
            'label' => 'FTP',
            'packages' => ['apt' => 'vsftpd', 'dnf' => 'vsftpd', 'yum' => 'vsftpd', 'apk' => 'vsftpd'],
            'service_candidates' => ['vsftpd'],
            'config_path' => 'vsftpd.conf',
        ],
        'nfs' => [
            'label' => 'NFS',
            'packages' => ['apt' => 'nfs-kernel-server', 'dnf' => 'nfs-utils', 'yum' => 'nfs-utils', 'apk' => 'nfs-utils'],
            'service_candidates' => ['nfs-server', 'nfs-kernel-server', 'nfs'],
            'config_path' => 'exports',
        ],
        'afp' => [
            'label' => 'AFP',
            'packages' => ['apt' => 'netatalk', 'dnf' => 'netatalk', 'yum' => 'netatalk', 'apk' => 'netatalk'],
            'service_candidates' => ['netatalk', 'afpd'],
            'config_path' => 'afp.conf',
        ],
        'async' => [
            'label' => 'Async / Rsync',
            'packages' => ['apt' => 'rsync', 'dnf' => 'rsync', 'yum' => 'rsync', 'apk' => 'rsync'],
            'service_candidates' => ['rsync'],
            'config_path' => 'rsyncd.conf',
        ],
    ];
    return $defs[$service] ?? [];
}

function host_agent_detect_named_host_service(string $service): array {
    $meta = host_agent_generic_service_meta($service);
    if (!$meta) {
        return ['service_name' => '', 'manager' => 'unknown', 'running' => false, 'enabled' => null, 'installed' => false];
    }
    $manager = host_agent_host_service_manager();
    foreach ((array)($meta['service_candidates'] ?? []) as $candidate) {
        $candidate = trim((string)$candidate);
        if ($candidate === '') {
            continue;
        }
        if ($manager === 'systemd') {
            $load = host_agent_host_shell('systemctl show -p LoadState --value ' . escapeshellarg($candidate . '.service') . ' 2>/dev/null || true');
            $loadState = trim((string)($load['stdout'] ?? ''));
            if ($loadState !== '' && $loadState !== 'not-found') {
                $active = host_agent_host_shell('systemctl is-active ' . escapeshellarg($candidate . '.service') . ' 2>/dev/null || true');
                $enabledResult = host_agent_host_shell('systemctl is-enabled ' . escapeshellarg($candidate . '.service') . ' 2>/dev/null || true');
                $enabledValue = trim((string)($enabledResult['stdout'] ?? ''));
                return [
                    'service_name' => $candidate,
                    'manager' => $manager,
                    'running' => trim((string)($active['stdout'] ?? '')) === 'active',
                    'enabled' => in_array($enabledValue, ['enabled', 'static'], true) ? true : (in_array($enabledValue, ['disabled', 'masked'], true) ? false : null),
                    'installed' => true,
                ];
            }
        } elseif ($manager === 'service') {
            $status = host_agent_host_shell('service ' . escapeshellarg($candidate) . ' status 2>&1 || true');
            $text = strtolower(trim((string)($status['stdout'] ?? '') . "\n" . (string)($status['stderr'] ?? '')));
            if ($text !== '' && !str_contains($text, 'unrecognized service') && !str_contains($text, 'not found')) {
                return [
                    'service_name' => $candidate,
                    'manager' => $manager,
                    'running' => str_contains($text, 'running') || str_contains($text, 'started') || str_contains($text, 'active'),
                    'enabled' => null,
                    'installed' => true,
                ];
            }
        }
    }
    return ['service_name' => (string)(((array)$meta['service_candidates'])[0] ?? $service), 'manager' => $manager, 'running' => false, 'enabled' => null, 'installed' => false];
}

function host_agent_generic_service_status(string $root, string $mode, string $service): array {
    $meta = host_agent_generic_service_meta($service);
    if (!$meta) {
        return ['ok' => false, 'msg' => '未知服务'];
    }
    if ($mode !== 'host') {
        $state = host_agent_share_service_sim_state($root);
        $item = is_array($state[$service] ?? null) ? $state[$service] : ['installed' => false, 'running' => false, 'enabled' => false];
        return [
            'ok' => true,
            'service' => $service,
            'label' => (string)($meta['label'] ?? strtoupper($service)),
            'installed' => (bool)($item['installed'] ?? false),
            'running' => (bool)($item['running'] ?? false),
            'enabled' => (bool)($item['enabled'] ?? false),
            'service_name' => (string)(((array)$meta['service_candidates'])[0] ?? $service),
            'service_manager' => 'simulate',
            'updated_at' => (string)($item['updated_at'] ?? ''),
        ];
    }
    $detected = host_agent_detect_named_host_service($service);
    return [
        'ok' => true,
        'service' => $service,
        'label' => (string)($meta['label'] ?? strtoupper($service)),
        'installed' => (bool)($detected['installed'] ?? false),
        'running' => (bool)($detected['running'] ?? false),
        'enabled' => $detected['enabled'] ?? null,
        'service_name' => (string)($detected['service_name'] ?? $service),
        'service_manager' => (string)($detected['manager'] ?? 'unknown'),
        'updated_at' => date('Y-m-d H:i:s'),
    ];
}

function host_agent_generic_service_action(string $root, string $mode, string $service, string $action): array {
    $action = strtolower(trim($action));
    if (!in_array($action, ['start', 'stop', 'restart', 'reload', 'enable', 'disable'], true)) {
        return ['ok' => false, 'msg' => '服务操作不支持'];
    }
    $meta = host_agent_generic_service_meta($service);
    if (!$meta) {
        return ['ok' => false, 'msg' => '未知服务'];
    }
    if ($mode !== 'host') {
        $state = host_agent_share_service_sim_state($root);
        $item = is_array($state[$service] ?? null) ? $state[$service] : ['installed' => false, 'running' => false, 'enabled' => false];
        if (in_array($action, ['start', 'restart', 'reload'], true)) {
            $item['running'] = true;
        } elseif ($action === 'stop') {
            $item['running'] = false;
        } elseif ($action === 'enable') {
            $item['enabled'] = true;
        } elseif ($action === 'disable') {
            $item['enabled'] = false;
        }
        if ($action !== 'disable') {
            $item['installed'] = true;
        }
        $state[$service] = $item;
        host_agent_share_service_sim_save($root, $state);
        return ['ok' => true, 'msg' => 'simulate 模式已执行 ' . $action, 'status' => host_agent_generic_service_status($root, $mode, $service)];
    }
    $detected = host_agent_detect_named_host_service($service);
    $name = trim((string)($detected['service_name'] ?? ''));
    if ($name === '') {
        return ['ok' => false, 'msg' => '未检测到服务名'];
    }
    $manager = (string)($detected['manager'] ?? 'unknown');
    if ($manager === 'systemd') {
        $result = host_agent_host_shell('systemctl ' . escapeshellarg($action) . ' ' . escapeshellarg($name . '.service') . ' 2>&1');
    } elseif ($manager === 'service') {
        if (in_array($action, ['enable', 'disable'], true)) {
            $cmd = $action === 'enable'
                ? 'if command -v update-rc.d >/dev/null 2>&1; then update-rc.d ' . escapeshellarg($name) . ' defaults; elif command -v chkconfig >/dev/null 2>&1; then chkconfig ' . escapeshellarg($name) . ' on; else exit 1; fi'
                : 'if command -v update-rc.d >/dev/null 2>&1; then update-rc.d ' . escapeshellarg($name) . ' disable; elif command -v chkconfig >/dev/null 2>&1; then chkconfig ' . escapeshellarg($name) . ' off; else exit 1; fi';
            $result = host_agent_host_shell($cmd . ' 2>&1');
        } else {
            $result = host_agent_host_shell('service ' . escapeshellarg($name) . ' ' . escapeshellarg($action) . ' 2>&1');
        }
    } else {
        return ['ok' => false, 'msg' => '未检测到可用的服务管理器'];
    }
    return [
        'ok' => $result['ok'],
        'msg' => $result['ok'] ? ((string)($meta['label'] ?? strtoupper($service)) . ' 服务已执行 ' . $action) : trim($result['stderr'] ?: $result['stdout'] ?: '服务操作失败'),
        'output' => trim($result['stdout'] . "\n" . $result['stderr']),
        'status' => host_agent_generic_service_status($root, $mode, $service),
    ];
}

function host_agent_generic_service_install(string $root, string $mode, string $service): array {
    $meta = host_agent_generic_service_meta($service);
    if (!$meta) {
        return ['ok' => false, 'msg' => '未知服务'];
    }
    if ($mode !== 'host') {
        $state = host_agent_share_service_sim_state($root);
        $item = is_array($state[$service] ?? null) ? $state[$service] : ['installed' => false, 'running' => false, 'enabled' => false];
        $item['installed'] = true;
        $item['running'] = true;
        $item['enabled'] = true;
        $state[$service] = $item;
        host_agent_share_service_sim_save($root, $state);
        if ($service === 'smb') {
            host_agent_ensure_smb_config($root, $mode);
        } elseif ($service === 'ftp') {
            host_agent_ensure_ftp_config($root, $mode);
        } elseif ($service === 'nfs') {
            host_agent_ensure_nfs_configs($root, $mode);
        } elseif ($service === 'afp') {
            host_agent_ensure_afp_config($root, $mode);
        } elseif ($service === 'async') {
            host_agent_ensure_async_config($root, $mode);
        }
        return ['ok' => true, 'msg' => 'simulate 模式已模拟安装 ' . (string)($meta['label'] ?? strtoupper($service))];
    }
    $detect = host_agent_host_shell('if command -v apt-get >/dev/null 2>&1; then echo apt; elif command -v dnf >/dev/null 2>&1; then echo dnf; elif command -v yum >/dev/null 2>&1; then echo yum; elif command -v apk >/dev/null 2>&1; then echo apk; else echo unknown; fi');
    $manager = trim((string)($detect['stdout'] ?? ''));
    $package = (string)(((array)($meta['packages'] ?? []))[$manager] ?? '');
    if ($package === '') {
        return ['ok' => false, 'msg' => '未识别宿主机包管理器，无法自动安装 ' . (string)($meta['label'] ?? strtoupper($service))];
    }
    if ($manager === 'apt') {
        $result = host_agent_host_shell('DEBIAN_FRONTEND=noninteractive apt-get update && DEBIAN_FRONTEND=noninteractive apt-get install -y ' . escapeshellarg($package) . ' 2>&1');
    } elseif ($manager === 'dnf') {
        $result = host_agent_host_shell('dnf install -y ' . escapeshellarg($package) . ' 2>&1');
    } elseif ($manager === 'yum') {
        $result = host_agent_host_shell('yum install -y ' . escapeshellarg($package) . ' 2>&1');
    } elseif ($manager === 'apk') {
        $result = host_agent_host_shell('apk add --no-cache ' . escapeshellarg($package) . ' 2>&1');
    } else {
        return ['ok' => false, 'msg' => '未识别宿主机包管理器'];
    }
    if ($result['ok']) {
        host_agent_generic_service_action($root, $mode, $service, 'enable');
        host_agent_generic_service_action($root, $mode, $service, 'start');
    }
    return [
        'ok' => $result['ok'],
        'msg' => $result['ok'] ? ((string)($meta['label'] ?? strtoupper($service)) . ' 已安装并尝试启动') : trim($result['stderr'] ?: $result['stdout'] ?: '安装失败'),
        'output' => trim($result['stdout'] . "\n" . $result['stderr']),
    ];
}

function host_agent_generic_service_uninstall(string $root, string $mode, string $service): array {
    $meta = host_agent_generic_service_meta($service);
    if (!$meta) {
        return ['ok' => false, 'msg' => '未知服务'];
    }
    if ($mode !== 'host') {
        $state = host_agent_share_service_sim_state($root);
        $state[$service] = ['installed' => false, 'running' => false, 'enabled' => false];
        host_agent_share_service_sim_save($root, $state);
        return ['ok' => true, 'msg' => 'simulate 模式已模拟卸载 ' . (string)($meta['label'] ?? strtoupper($service)), 'status' => host_agent_generic_service_status($root, $mode, $service)];
    }
    $detect = host_agent_host_shell('if command -v apt-get >/dev/null 2>&1; then echo apt; elif command -v dnf >/dev/null 2>&1; then echo dnf; elif command -v yum >/dev/null 2>&1; then echo yum; elif command -v apk >/dev/null 2>&1; then echo apk; else echo unknown; fi');
    $manager = trim((string)($detect['stdout'] ?? ''));
    $package = (string)(((array)($meta['packages'] ?? []))[$manager] ?? '');
    if ($package === '') {
        return ['ok' => false, 'msg' => '未识别宿主机包管理器，无法自动卸载 ' . (string)($meta['label'] ?? strtoupper($service))];
    }
    host_agent_generic_service_action($root, $mode, $service, 'stop');
    if ($manager === 'apt') {
        $result = host_agent_host_shell('DEBIAN_FRONTEND=noninteractive apt-get remove -y ' . escapeshellarg($package) . ' 2>&1');
    } elseif ($manager === 'dnf') {
        $result = host_agent_host_shell('dnf remove -y ' . escapeshellarg($package) . ' 2>&1');
    } elseif ($manager === 'yum') {
        $result = host_agent_host_shell('yum remove -y ' . escapeshellarg($package) . ' 2>&1');
    } elseif ($manager === 'apk') {
        $result = host_agent_host_shell('apk del ' . escapeshellarg($package) . ' 2>&1');
    } else {
        return ['ok' => false, 'msg' => '未识别宿主机包管理器'];
    }
    return [
        'ok' => $result['ok'],
        'msg' => $result['ok'] ? ((string)($meta['label'] ?? strtoupper($service)) . ' 已卸载') : trim($result['stderr'] ?: $result['stdout'] ?: '卸载失败'),
        'output' => trim($result['stdout'] . "\n" . $result['stderr']),
        'status' => host_agent_generic_service_status($root, $mode, $service),
    ];
}

// ============================================================
// 通用包管理器抽象层 (Phase 1: Package Manager Abstraction)
// ============================================================

function host_agent_cache_key(string ...$parts): string {
    return 'host_agent_cache:' . implode(':', $parts);
}

function host_agent_cache_get(string $key): ?array {
    $cache = &$GLOBALS['HOST_AGENT_CACHE'];
    if (!is_array($cache) || !isset($cache[$key])) {
        return null;
    }
    $entry = $cache[$key];
    if (!is_array($entry) || ($entry['expires'] ?? 0) < time()) {
        unset($cache[$key]);
        return null;
    }
    return $entry['value'] ?? null;
}

function host_agent_cache_set(string $key, array $value, int $ttl = 60): void {
    $cache = &$GLOBALS['HOST_AGENT_CACHE'];
    if (!is_array($cache)) {
        $cache = [];
    }
    $cache[$key] = ['expires' => time() + $ttl, 'value' => $value];
}

function host_agent_cache_delete(string $pattern): void {
    $cache = &$GLOBALS['HOST_AGENT_CACHE'];
    if (!is_array($cache)) {
        return;
    }
    foreach ($cache as $key => $entry) {
        if (strpos($key, $pattern) !== false) {
            unset($cache[$key]);
        }
    }
}

function host_agent_detect_package_manager(): string {
    $cached = host_agent_cache_get('pkg_manager');
    if ($cached !== null && isset($cached['manager'])) {
        return $cached['manager'];
    }

    $detectors = [
        'brew' => 'command -v brew',
        'port' => 'command -v port',
        'apt'  => 'command -v apt-get',
        'dnf'  => 'command -v dnf',
        'yum'  => 'command -v yum',
        'apk'  => 'command -v apk',
        'pacman' => 'command -v pacman',
        'zypper' => 'command -v zypper',
        'emerge' => 'command -v emerge',
    ];
    $script = '';
    foreach ($detectors as $name => $cmd) {
        $script .= "if {$cmd} >/dev/null 2>&1; then echo {$name}; exit 0; fi; ";
    }
    $script .= 'echo unknown';
    $result = host_agent_host_shell($script);
    $detected = trim((string)($result['stdout'] ?? ''));
    $manager = $detected !== '' ? $detected : 'unknown';
    host_agent_cache_set('pkg_manager', ['manager' => $manager], 300);
    return $manager;
}

function host_agent_detect_service_manager(): string {
    $script = 'if [ -d /run/systemd/system ] && command -v systemctl >/dev/null 2>&1; then echo systemd; '
        . 'elif command -v rc-service >/dev/null 2>&1 && command -v rc-update >/dev/null 2>&1; then echo openrc; '
        . 'elif command -v sv >/dev/null 2>&1 && [ -d /var/service ] || [ -d /etc/service ]; then echo runit; '
        . 'elif command -v service >/dev/null 2>&1; then echo sysvinit; '
        . 'elif command -v launchctl >/dev/null 2>&1; then echo launchd; '
        . 'else echo unknown; fi';
    $result = host_agent_host_shell($script);
    $detected = trim((string)($result['stdout'] ?? ''));
    return $detected !== '' ? $detected : 'unknown';
}

function host_agent_package_manager_commands(string $manager): array {
    $commands = [
        'apt' => [
            'install'        => 'DEBIAN_FRONTEND=noninteractive apt-get install -y {pkg}',
            'remove'         => 'DEBIAN_FRONTEND=noninteractive apt-get remove -y {pkg}',
            'purge'          => 'DEBIAN_FRONTEND=noninteractive apt-get purge -y {pkg}',
            'autoremove'     => 'DEBIAN_FRONTEND=noninteractive apt-get autoremove -y',
            'update'         => 'DEBIAN_FRONTEND=noninteractive apt-get update',
            'upgrade'        => 'DEBIAN_FRONTEND=noninteractive apt-get upgrade -y',
            'list_installed' => "dpkg-query -W -f='${Package}\\t${Version}\\n'",
            'search'         => 'apt-cache search {keyword}',
            'info'           => 'apt-cache show {pkg}',
            'is_installed'   => 'dpkg-query -W -f=\'${Status}\' {pkg} 2>/dev/null | grep -q "install ok installed"',
        ],
        'dnf' => [
            'install'        => 'dnf install -y {pkg}',
            'remove'         => 'dnf remove -y {pkg}',
            'autoremove'     => 'dnf autoremove -y',
            'update'         => 'dnf check-update',
            'upgrade'        => 'dnf upgrade -y',
            'list_installed' => "dnf list installed --qf '%{name}\\t%{version}\\n'",
            'search'         => 'dnf search {keyword}',
            'info'           => 'dnf info {pkg}',
            'is_installed'   => 'rpm -q {pkg} >/dev/null 2>&1',
        ],
        'yum' => [
            'install'        => 'yum install -y {pkg}',
            'remove'         => 'yum remove -y {pkg}',
            'autoremove'     => 'yum autoremove -y',
            'update'         => 'yum check-update',
            'upgrade'        => 'yum update -y',
            'list_installed' => "yum list installed --qf '%{name}\\t%{version}\\n'",
            'search'         => 'yum search {keyword}',
            'info'           => 'yum info {pkg}',
            'is_installed'   => 'rpm -q {pkg} >/dev/null 2>&1',
        ],
        'apk' => [
            'install'        => 'apk add --no-cache {pkg}',
            'remove'         => 'apk del {pkg}',
            'autoremove'     => 'apk del --purge $(apk info --depends {pkg} 2>/dev/null | grep -v ^$ | sed "s/^/  /" | xargs) 2>/dev/null || true',
            'update'         => 'apk update',
            'upgrade'        => 'apk upgrade',
            'list_installed' => "apk info -v | sed 's/\(.*\)-\([0-9]\)/\\1\\t\\2/'",
            'search'         => 'apk search {keyword}',
            'info'           => 'apk info -a {pkg}',
            'is_installed'   => 'apk info -e {pkg} >/dev/null 2>&1',
        ],
        'pacman' => [
            'install'        => 'pacman -S --noconfirm {pkg}',
            'remove'         => 'pacman -R --noconfirm {pkg}',
            'autoremove'     => 'pacman -Rs --noconfirm $(pacman -Qdtq) 2>/dev/null || true',
            'update'         => 'pacman -Sy',
            'upgrade'        => 'pacman -Syu --noconfirm',
            'list_installed' => "pacman -Q | awk '{print $1\"\\t\"$2}'",
            'search'         => 'pacman -Ss {keyword}',
            'info'           => 'pacman -Si {pkg}',
            'is_installed'   => 'pacman -Q {pkg} >/dev/null 2>&1',
        ],
        'zypper' => [
            'install'        => 'zypper --non-interactive install {pkg}',
            'remove'         => 'zypper --non-interactive remove {pkg}',
            'autoremove'     => 'zypper --non-interactive remove --clean-deps $(zypper packages --unneeded | tail -n +3 | cut -d\| -f3 | tr -d " " | sort -u | grep -v "^Name$") 2>/dev/null || true',
            'update'         => 'zypper refresh',
            'upgrade'        => 'zypper --non-interactive update',
            'list_installed' => "zypper search --installed-only --details | awk 'NR>2 {print $3\"\\t\"$5}'",
            'search'         => 'zypper search {keyword}',
            'info'           => 'zypper info {pkg}',
            'is_installed'   => 'rpm -q {pkg} >/dev/null 2>&1',
        ],
        'emerge' => [
            'install'        => 'emerge -q {pkg}',
            'remove'         => 'emerge --depclean {pkg}',
            'autoremove'     => 'emerge --depclean',
            'update'         => 'emerge --sync',
            'upgrade'        => 'emerge -uDNq @world',
            'list_installed' => "qlist -Iv | awk '{print $1\"\\t\"$2}'",
            'search'         => 'emerge -s {keyword}',
            'info'           => 'equery meta {pkg}',
            'is_installed'   => 'qlist -I {pkg} >/dev/null 2>&1',
        ],
        'brew' => [
            'install'        => 'brew install {pkg}',
            'remove'         => 'brew uninstall {pkg}',
            'autoremove'     => 'brew autoremove',
            'update'         => 'brew update',
            'upgrade'        => 'brew upgrade {pkg}',
            'list_installed' => "brew list --versions | awk '{print $1\"\\t\"$2}'",
            'search'         => 'brew search {keyword}',
            'info'           => 'brew info {pkg}',
            'is_installed'   => 'brew list {pkg} >/dev/null 2>&1',
        ],
        'port' => [
            'install'        => 'sudo port install {pkg}',
            'remove'         => 'sudo port uninstall {pkg}',
            'autoremove'     => 'sudo port reclaim',
            'update'         => 'sudo port selfupdate',
            'upgrade'        => 'sudo port upgrade outdated',
            'list_installed' => "port installed | awk 'NR>1 {print $1\"\\t\"$2}'",
            'search'         => 'port search {keyword}',
            'info'           => 'port info {pkg}',
            'is_installed'   => 'port installed {pkg} | grep -q "active"',
        ],
    ];
    return $commands[$manager] ?? [];
}

function host_agent_service_manager_commands(string $manager): array {
    $commands = [
        'systemd' => [
            'start'   => 'systemctl start {service}.service',
            'stop'    => 'systemctl stop {service}.service',
            'restart' => 'systemctl restart {service}.service',
            'reload'  => 'systemctl reload {service}.service',
            'enable'  => 'systemctl enable {service}.service',
            'disable' => 'systemctl disable {service}.service',
            'status'  => 'systemctl is-active {service}.service',
            'is_enabled' => 'systemctl is-enabled {service}.service',
        ],
        'openrc' => [
            'start'   => 'rc-service {service} start',
            'stop'    => 'rc-service {service} stop',
            'restart' => 'rc-service {service} restart',
            'reload'  => 'rc-service {service} reload',
            'enable'  => 'rc-update add {service} default',
            'disable' => 'rc-update del {service} default',
            'status'  => 'rc-service {service} status',
            'is_enabled' => 'rc-update show | grep -q "^{service}"',
        ],
        'runit' => [
            'start'   => 'sv up {service}',
            'stop'    => 'sv down {service}',
            'restart' => 'sv restart {service}',
            'reload'  => 'sv hup {service}',
            'enable'  => 'ln -s /etc/sv/{service} /var/service/ 2>/dev/null || ln -s /etc/sv/{service} /etc/service/ 2>/dev/null || true',
            'disable' => 'rm /var/service/{service} 2>/dev/null || rm /etc/service/{service} 2>/dev/null || true',
            'status'  => 'sv status {service}',
            'is_enabled' => '[ -L /var/service/{service} ] || [ -L /etc/service/{service} ]',
        ],
        'sysvinit' => [
            'start'   => 'service {service} start',
            'stop'    => 'service {service} stop',
            'restart' => 'service {service} restart',
            'reload'  => 'service {service} reload',
            'enable'  => 'if command -v update-rc.d >/dev/null 2>&1; then update-rc.d {service} defaults; elif command -v chkconfig >/dev/null 2>&1; then chkconfig {service} on; else exit 1; fi',
            'disable' => 'if command -v update-rc.d >/dev/null 2>&1; then update-rc.d {service} disable; elif command -v chkconfig >/dev/null 2>&1; then chkconfig {service} off; else exit 1; fi',
            'status'  => 'service {service} status',
            'is_enabled' => 'true', // sysvinit 没有统一方式检测
        ],
        'launchd' => [
            'start'   => 'launchctl start {service}',
            'stop'    => 'launchctl stop {service}',
            'restart' => 'launchctl stop {service} 2>/dev/null; sleep 1; launchctl start {service}',
            'reload'  => 'launchctl stop {service} 2>/dev/null; sleep 1; launchctl start {service}',
            'enable'  => 'launchctl load -w /Library/LaunchDaemons/{service}.plist 2>/dev/null || launchctl load -w /Library/LaunchAgents/{service}.plist 2>/dev/null || true',
            'disable' => 'launchctl unload -w /Library/LaunchDaemons/{service}.plist 2>/dev/null || launchctl unload -w /Library/LaunchAgents/{service}.plist 2>/dev/null || true',
            'status'  => 'launchctl list | grep -q "^{service}"',
            'is_enabled' => 'launchctl list | grep -q "^{service}"',
        ],
    ];
    return $commands[$manager] ?? [];
}

function host_agent_package_alias_map(): array {
    return [
        'nginx' => ['apt' => 'nginx', 'dnf' => 'nginx', 'yum' => 'nginx', 'apk' => 'nginx', 'pacman' => 'nginx', 'zypper' => 'nginx', 'brew' => 'nginx'],
        'apache' => ['apt' => 'apache2', 'dnf' => 'httpd', 'yum' => 'httpd', 'apk' => 'apache2', 'pacman' => 'apache', 'zypper' => 'apache2', 'brew' => 'httpd'],
        'mysql' => ['apt' => 'mysql-server', 'dnf' => 'mysql-server', 'yum' => 'mysql-server', 'apk' => 'mysql', 'pacman' => 'mariadb', 'zypper' => 'mysql', 'brew' => 'mysql'],
        'mariadb' => ['apt' => 'mariadb-server', 'dnf' => 'mariadb-server', 'yum' => 'mariadb-server', 'apk' => 'mariadb', 'pacman' => 'mariadb', 'zypper' => 'mariadb', 'brew' => 'mariadb'],
        'postgresql' => ['apt' => 'postgresql', 'dnf' => 'postgresql-server', 'yum' => 'postgresql-server', 'apk' => 'postgresql', 'pacman' => 'postgresql', 'zypper' => 'postgresql', 'brew' => 'postgresql'],
        'redis' => ['apt' => 'redis-server', 'dnf' => 'redis', 'yum' => 'redis', 'apk' => 'redis', 'pacman' => 'redis', 'zypper' => 'redis', 'brew' => 'redis'],
        'memcached' => ['apt' => 'memcached', 'dnf' => 'memcached', 'yum' => 'memcached', 'apk' => 'memcached', 'pacman' => 'memcached', 'zypper' => 'memcached', 'brew' => 'memcached'],
        'mongodb' => ['apt' => 'mongodb-org', 'dnf' => 'mongodb-org', 'yum' => 'mongodb-org', 'apk' => 'mongodb', 'pacman' => 'mongodb', 'zypper' => 'mongodb', 'brew' => 'mongodb-community'],
        'nodejs' => ['apt' => 'nodejs', 'dnf' => 'nodejs', 'yum' => 'nodejs', 'apk' => 'nodejs', 'pacman' => 'nodejs', 'zypper' => 'nodejs', 'brew' => 'node'],
        'npm' => ['apt' => 'npm', 'dnf' => 'npm', 'yum' => 'npm', 'apk' => 'npm', 'pacman' => 'npm', 'zypper' => 'npm', 'brew' => 'npm'],
        'python3' => ['apt' => 'python3', 'dnf' => 'python3', 'yum' => 'python3', 'apk' => 'python3', 'pacman' => 'python', 'zypper' => 'python3', 'brew' => 'python@3.11'],
        'php' => ['apt' => 'php', 'dnf' => 'php', 'yum' => 'php', 'apk' => 'php82', 'pacman' => 'php', 'zypper' => 'php', 'brew' => 'php'],
        'php-fpm' => ['apt' => 'php-fpm', 'dnf' => 'php-fpm', 'yum' => 'php-fpm', 'apk' => 'php82-fpm', 'pacman' => 'php-fpm', 'zypper' => 'php-fpm', 'brew' => 'php'],
        'docker' => ['apt' => 'docker.io', 'dnf' => 'docker', 'yum' => 'docker', 'apk' => 'docker', 'pacman' => 'docker', 'zypper' => 'docker', 'brew' => 'docker'],
        'git' => ['apt' => 'git', 'dnf' => 'git', 'yum' => 'git', 'apk' => 'git', 'pacman' => 'git', 'zypper' => 'git', 'brew' => 'git'],
        'vim' => ['apt' => 'vim', 'dnf' => 'vim', 'yum' => 'vim', 'apk' => 'vim', 'pacman' => 'vim', 'zypper' => 'vim', 'brew' => 'vim'],
        'htop' => ['apt' => 'htop', 'dnf' => 'htop', 'yum' => 'htop', 'apk' => 'htop', 'pacman' => 'htop', 'zypper' => 'htop', 'brew' => 'htop'],
        'curl' => ['apt' => 'curl', 'dnf' => 'curl', 'yum' => 'curl', 'apk' => 'curl', 'pacman' => 'curl', 'zypper' => 'curl', 'brew' => 'curl'],
        'wget' => ['apt' => 'wget', 'dnf' => 'wget', 'yum' => 'wget', 'apk' => 'wget', 'pacman' => 'wget', 'zypper' => 'wget', 'brew' => 'wget'],
        'openssh-server' => ['apt' => 'openssh-server', 'dnf' => 'openssh-server', 'yum' => 'openssh-server', 'apk' => 'openssh-server', 'pacman' => 'openssh', 'zypper' => 'openssh', 'brew' => null],
        'sudo' => ['apt' => 'sudo', 'dnf' => 'sudo', 'yum' => 'sudo', 'apk' => 'sudo', 'pacman' => 'sudo', 'zypper' => 'sudo', 'brew' => null],
        'ufw' => ['apt' => 'ufw', 'dnf' => 'firewalld', 'yum' => 'firewalld', 'apk' => 'ufw', 'pacman' => 'ufw', 'zypper' => 'firewalld', 'brew' => null],
        'fail2ban' => ['apt' => 'fail2ban', 'dnf' => 'fail2ban', 'yum' => 'fail2ban', 'apk' => 'fail2ban', 'pacman' => 'fail2ban', 'zypper' => 'fail2ban', 'brew' => null],
        'samba' => ['apt' => 'samba', 'dnf' => 'samba', 'yum' => 'samba', 'apk' => 'samba', 'pacman' => 'samba', 'zypper' => 'samba', 'brew' => 'samba'],
        'nfs' => ['apt' => 'nfs-kernel-server', 'dnf' => 'nfs-utils', 'yum' => 'nfs-utils', 'apk' => 'nfs-utils', 'pacman' => 'nfs-utils', 'zypper' => 'nfs-client', 'brew' => null],
        'rsync' => ['apt' => 'rsync', 'dnf' => 'rsync', 'yum' => 'rsync', 'apk' => 'rsync', 'pacman' => 'rsync', 'zypper' => 'rsync', 'brew' => 'rsync'],
    ];
}

function host_agent_resolve_package_name(string $alias, string $manager): ?string {
    $map = host_agent_package_alias_map();
    $alias = strtolower(trim($alias));
    if (isset($map[$alias][$manager])) {
        return $map[$alias][$manager];
    }
    return $alias; // 没有映射时直接使用原名
}

function host_agent_package_is_installed(string $manager, string $pkg): bool {
    $cmds = host_agent_package_manager_commands($manager);
    if (empty($cmds['is_installed'])) {
        return false;
    }
    $cmd = str_replace('{pkg}', escapeshellarg($pkg), $cmds['is_installed']);
    $result = host_agent_host_shell($cmd . ' 2>/dev/null; echo "_EXIT_:$?"');
    $stdout = (string)($result['stdout'] ?? '');
    if (preg_match('/_EXIT_:(\d+)/', $stdout, $m)) {
        return (int)$m[1] === 0;
    }
    return $result['ok'] && $result['code'] === 0;
}

function host_agent_package_install(string $manager, string $pkg): array {
    $cmds = host_agent_package_manager_commands($manager);
    if (empty($cmds['install'])) {
        return ['ok' => false, 'msg' => '包管理器 ' . $manager . ' 不支持安装操作'];
    }
    if (host_agent_package_is_installed($manager, $pkg)) {
        return ['ok' => true, 'msg' => $pkg . ' 已安装'];
    }
    $cmd = str_replace('{pkg}', escapeshellarg($pkg), $cmds['install']);
    $result = host_agent_host_shell($cmd . ' 2>&1');
    $ok = $result['ok'] && $result['code'] === 0;
    if ($ok) {
        host_agent_cache_delete(host_agent_cache_key('pkg_list', $manager));
    }
    return [
        'ok' => $ok,
        'msg' => $ok ? ($pkg . ' 安装成功') : ($pkg . ' 安装失败'),
        'output' => trim($result['stdout'] . "\n" . $result['stderr']),
        'code' => $result['code'],
    ];
}

function host_agent_package_remove(string $manager, string $pkg, bool $purge = false): array {
    $cmds = host_agent_package_manager_commands($manager);
    $action = ($purge && !empty($cmds['purge'])) ? 'purge' : 'remove';
    if (empty($cmds[$action])) {
        return ['ok' => false, 'msg' => '包管理器 ' . $manager . ' 不支持卸载操作'];
    }
    $cmd = str_replace('{pkg}', escapeshellarg($pkg), $cmds[$action]);
    $result = host_agent_host_shell($cmd . ' 2>&1');
    $ok = $result['ok'] && $result['code'] === 0;
    if ($ok) {
        host_agent_cache_delete(host_agent_cache_key('pkg_list', $manager));
    }
    return [
        'ok' => $ok,
        'msg' => $ok ? ($pkg . ' 卸载成功') : ($pkg . ' 卸载失败'),
        'output' => trim($result['stdout'] . "\n" . $result['stderr']),
        'code' => $result['code'],
    ];
}

function host_agent_package_update(string $manager, string $pkg): array {
    $cmds = host_agent_package_manager_commands($manager);
    if (empty($cmds['upgrade'])) {
        return ['ok' => false, 'msg' => '包管理器 ' . $manager . ' 不支持更新操作'];
    }
    $cmd = str_replace('{pkg}', escapeshellarg($pkg), $cmds['upgrade']);
    $result = host_agent_host_shell($cmd . ' 2>&1');
    $ok = $result['ok'] && $result['code'] === 0;
    if ($ok) {
        host_agent_cache_delete(host_agent_cache_key('pkg_list', $manager));
    }
    return [
        'ok' => $ok,
        'msg' => $ok ? ($pkg . ' 更新成功') : ($pkg . ' 更新失败或无更新'),
        'output' => trim($result['stdout'] . "\n" . $result['stderr']),
        'code' => $result['code'],
    ];
}

function host_agent_package_upgrade_all(string $manager): array {
    $cmds = host_agent_package_manager_commands($manager);
    if (empty($cmds['upgrade'])) {
        return ['ok' => false, 'msg' => '包管理器 ' . $manager . ' 不支持全系统升级'];
    }
    $result = host_agent_host_shell($cmds['upgrade'] . ' 2>&1');
    $ok = $result['ok'] && $result['code'] === 0;
    if ($ok) {
        host_agent_cache_delete(host_agent_cache_key('pkg_list', $manager));
    }
    return [
        'ok' => $ok,
        'msg' => $ok ? '系统升级完成' : '系统升级失败',
        'output' => trim($result['stdout'] . "\n" . $result['stderr']),
        'code' => $result['code'],
    ];
}

function host_agent_package_search(string $manager, string $keyword, int $limit = 50): array {
    $cacheKey = host_agent_cache_key('pkg_search', $manager, md5($keyword . ':' . $limit));
    $cached = host_agent_cache_get($cacheKey);
    if ($cached !== null) {
        return $cached;
    }

    $cmds = host_agent_package_manager_commands($manager);
    if (empty($cmds['search'])) {
        return ['ok' => false, 'msg' => '包管理器 ' . $manager . ' 不支持搜索'];
    }
    $cmd = str_replace('{keyword}', escapeshellarg($keyword), $cmds['search']);
    $result = host_agent_host_shell($cmd . ' 2>&1');
    if (!$result['ok'] || $result['code'] !== 0) {
        return ['ok' => false, 'msg' => '搜索失败', 'output' => trim($result['stdout'] . "\n" . $result['stderr'])];
    }
    $lines = preg_split('/\r?\n/', trim($result['stdout'])) ?: [];
    $packages = [];
    foreach (array_slice($lines, 0, $limit) as $line) {
        $line = trim($line);
        if ($line === '') continue;
        if ($manager === 'apt') {
            if (preg_match('/^(\S+)\s*-\s*(.+)$/', $line, $m)) {
                $packages[] = ['name' => trim($m[1]), 'description' => trim($m[2]), 'version' => ''];
            }
        } elseif ($manager === 'dnf' || $manager === 'yum') {
            if (preg_match('/^(\S+)\.(\S+)\s*:\s*(.+)$/', $line, $m)) {
                $packages[] = ['name' => trim($m[1]), 'description' => trim($m[3]), 'version' => ''];
            }
        } elseif ($manager === 'apk') {
            if (preg_match('/^(\S+)-(\S+)\s*-\s*(.+)$/', $line, $m)) {
                $packages[] = ['name' => trim($m[1]), 'description' => trim($m[3]), 'version' => trim($m[2])];
            } else {
                $packages[] = ['name' => $line, 'description' => '', 'version' => ''];
            }
        } elseif ($manager === 'pacman') {
            if (preg_match('/^(\S+)\/(\S+)\s+(.+)$/', $line, $m)) {
                $packages[] = ['name' => trim($m[2]), 'description' => trim($m[3]), 'version' => ''];
            }
        } elseif ($manager === 'zypper') {
            if (preg_match('/^(\S+)\s+\|\s+(\S+)\s+\|\s+(\S+)/', $line, $m)) {
                $packages[] = ['name' => trim($m[2]), 'description' => '', 'version' => trim($m[3])];
            }
        } elseif ($manager === 'brew') {
            if (preg_match('/^(\S+)\s*\(([^)]+)\)\s*(.*)$/', $line, $m)) {
                $packages[] = ['name' => trim($m[1]), 'description' => trim($m[3]), 'version' => trim($m[2])];
            } else {
                $packages[] = ['name' => $line, 'description' => '', 'version' => ''];
            }
        } else {
            $packages[] = ['name' => $line, 'description' => '', 'version' => ''];
        }
    }
    $result = ['ok' => true, 'packages' => $packages, 'manager' => $manager];
    host_agent_cache_set($cacheKey, $result, 30);
    return $result;
}

function host_agent_package_list(string $manager, int $limit = 500): array {
    $cacheKey = host_agent_cache_key('pkg_list', $manager);
    $cached = host_agent_cache_get($cacheKey);
    if ($cached !== null) {
        return $cached;
    }

    $cmds = host_agent_package_manager_commands($manager);
    $cmds = host_agent_package_manager_commands($manager);
    if (empty($cmds['list_installed'])) {
        return ['ok' => false, 'msg' => '包管理器 ' . $manager . ' 不支持列出已安装包'];
    }
    $result = host_agent_host_shell($cmds['list_installed'] . ' 2>&1');
    if (!$result['ok'] || $result['code'] !== 0) {
        return ['ok' => false, 'msg' => '获取已安装包列表失败', 'output' => trim($result['stdout'] . "\n" . $result['stderr'])];
    }
    $lines = preg_split('/\r?\n/', trim($result['stdout'])) ?: [];
    $packages = [];
    foreach (array_slice($lines, 0, $limit) as $line) {
        $line = trim($line);
        if ($line === '') continue;
        $parts = preg_split('/\s+/', $line, 2);
        $packages[] = [
            'name' => $parts[0] ?? $line,
            'version' => $parts[1] ?? '',
        ];
    }
    $result = ['ok' => true, 'packages' => $packages, 'manager' => $manager, 'total' => count($lines)];
    host_agent_cache_set($cacheKey, $result, 60);
    return $result;
}

function host_agent_package_info(string $manager, string $pkg): array {
    $cmds = host_agent_package_manager_commands($manager);
    if (empty($cmds['info'])) {
        return [
            'ok' => false,
            'msg' => '包管理器 ' . $manager . ' 不支持查看包信息',
            'info' => '',
            'installed' => false,
            'manager' => $manager,
        ];
    }
    $cmd = str_replace('{pkg}', escapeshellarg($pkg), $cmds['info']);
    $result = host_agent_host_shell($cmd . ' 2>&1');
    return [
        'ok' => $result['ok'] && $result['code'] === 0,
        'info' => trim($result['stdout'] . "\n" . $result['stderr']),
        'installed' => host_agent_package_is_installed($manager, $pkg),
        'manager' => $manager,
    ];
}

// ============================================================
// Configuration Manager (Phase 2)
// ============================================================

function host_agent_config_definitions(): array {
    return [
        'nginx' => [
            'label' => 'Nginx',
            'icon' => '🌐',
            'paths' => [
                'apt' => '/etc/nginx/nginx.conf',
                'dnf' => '/etc/nginx/nginx.conf',
                'yum' => '/etc/nginx/nginx.conf',
                'apk' => '/etc/nginx/nginx.conf',
                'pacman' => '/etc/nginx/nginx.conf',
                'zypper' => '/etc/nginx/nginx.conf',
                'brew' => '/opt/homebrew/etc/nginx/nginx.conf',
            ],
            'format' => 'nginx',
            'validate_cmd' => 'nginx -t',
            'reload_cmd' => 'nginx -s reload',
            'sections' => ['main', 'events', 'http', 'server', 'location'],
        ],
        'php-fpm' => [
            'label' => 'PHP-FPM',
            'icon' => '🐘',
            'paths' => [
                'apt' => '/etc/php/8.2/fpm/php.ini',
                'dnf' => '/etc/php.ini',
                'yum' => '/etc/php.ini',
                'apk' => '/etc/php82/php.ini',
                'pacman' => '/etc/php/php.ini',
                'zypper' => '/etc/php8/php.ini',
                'brew' => '/opt/homebrew/etc/php/8.2/php.ini',
            ],
            'format' => 'ini',
            'validate_cmd' => '',
            'reload_cmd' => '',
            'sections' => [],
        ],
        'redis' => [
            'label' => 'Redis',
            'icon' => '🔴',
            'paths' => [
                'apt' => '/etc/redis/redis.conf',
                'dnf' => '/etc/redis.conf',
                'yum' => '/etc/redis.conf',
                'apk' => '/etc/redis.conf',
                'pacman' => '/etc/redis/redis.conf',
                'zypper' => '/etc/redis/redis.conf',
                'brew' => '/opt/homebrew/etc/redis.conf',
            ],
            'format' => 'redis_conf',
            'validate_cmd' => '',
            'reload_cmd' => '',
            'sections' => [],
        ],
        'ssh' => [
            'label' => 'SSH',
            'icon' => '🔐',
            'paths' => [
                'default' => '/etc/ssh/sshd_config',
            ],
            'format' => 'ssh_config',
            'validate_cmd' => '',
            'reload_cmd' => '',
            'sections' => [],
        ],
        'mysql' => [
            'label' => 'MySQL',
            'icon' => '🐬',
            'paths' => [
                'apt' => '/etc/mysql/my.cnf',
                'dnf' => '/etc/my.cnf',
                'yum' => '/etc/my.cnf',
                'apk' => '/etc/my.cnf',
                'pacman' => '/etc/mysql/my.cnf',
                'zypper' => '/etc/my.cnf',
                'brew' => '/opt/homebrew/etc/my.cnf',
            ],
            'format' => 'ini',
            'validate_cmd' => '',
            'reload_cmd' => '',
            'sections' => ['mysqld', 'client', 'mysql'],
        ],
        'postgresql' => [
            'label' => 'PostgreSQL',
            'icon' => '🐘',
            'paths' => [
                'apt' => '/etc/postgresql/16/main/postgresql.conf',
                'dnf' => '/var/lib/pgsql/data/postgresql.conf',
                'yum' => '/var/lib/pgsql/data/postgresql.conf',
                'apk' => '/etc/postgresql/postgresql.conf',
                'pacman' => '/var/lib/postgres/data/postgresql.conf',
                'zypper' => '/var/lib/pgsql/data/postgresql.conf',
                'brew' => '/opt/homebrew/var/postgresql/postgresql.conf',
            ],
            'format' => 'postgresql_conf',
            'validate_cmd' => '',
            'reload_cmd' => '',
            'sections' => [],
        ],
    ];
}

function host_agent_config_get_path(string $configId, string $manager): ?string {
    $defs = host_agent_config_definitions();
    $def = $defs[$configId] ?? null;
    if (!$def) return null;
    $paths = (array)($def['paths'] ?? []);
    return $paths[$manager] ?? $paths['default'] ?? null;
}

function host_agent_config_read(string $configId, string $manager): array {
    $path = host_agent_config_get_path($configId, $manager);
    if ($path === null) {
        return ['ok' => false, 'msg' => '配置 ' . $configId . ' 在当前系统无可用路径'];
    }
    $content = host_agent_host_shell('cat ' . escapeshellarg($path) . ' 2>/dev/null || true');
    return [
        'ok' => true,
        'config_id' => $configId,
        'path' => $path,
        'content' => trim((string)($content['stdout'] ?? '')),
        'exists' => $content['ok'] && $content['code'] === 0,
        'format' => (string)((host_agent_config_definitions()[$configId] ?? [])['format'] ?? 'text'),
    ];
}

function host_agent_config_backup_path(string $path): string {
    return $path . '.backup.' . date('Ymd_His');
}

function host_agent_config_apply(string $configId, string $manager, string $content, bool $validateOnly = false): array {
    $defs = host_agent_config_definitions();
    $def = $defs[$configId] ?? null;
    if (!$def) {
        return ['ok' => false, 'msg' => '未知配置项：' . $configId];
    }

    $path = host_agent_config_get_path($configId, $manager);
    if ($path === null) {
        return ['ok' => false, 'msg' => '配置 ' . $configId . ' 在当前系统无可用路径'];
    }

    if (strlen($content) > 2 * 1024 * 1024) {
        return ['ok' => false, 'msg' => '配置内容超过 2MB 限制'];
    }

    // 1. 读取旧内容
    $oldResult = host_agent_host_shell('cat ' . escapeshellarg($path) . ' 2>/dev/null || true');
    $oldContent = (string)($oldResult['stdout'] ?? '');

    // 2. 备份
    $backupPath = host_agent_config_backup_path($path);
    host_agent_host_shell('cp ' . escapeshellarg($path) . ' ' . escapeshellarg($backupPath) . ' 2>/dev/null || true');

    // 3. 写入新内容
    $putResult = host_agent_host_shell('cat > ' . escapeshellarg($path), $content);
    if (!$putResult['ok']) {
        // 回滚
        host_agent_host_shell('cp ' . escapeshellarg($backupPath) . ' ' . escapeshellarg($path) . ' 2>/dev/null || true');
        return ['ok' => false, 'msg' => '写入配置文件失败，已回滚', 'path' => $path];
    }

    // 4. 校验
    $validateCmd = trim((string)($def['validate_cmd'] ?? ''));
    if ($validateCmd !== '') {
        $validateResult = host_agent_host_shell($validateCmd . ' 2>&1');
        if (!$validateResult['ok'] || $validateResult['code'] !== 0) {
            // 回滚
            host_agent_host_shell('cp ' . escapeshellarg($backupPath) . ' ' . escapeshellarg($path) . ' 2>/dev/null || true');
            return [
                'ok' => false,
                'msg' => '配置校验失败，已自动回滚',
                'path' => $path,
                'validate_output' => trim($validateResult['stdout'] . "\n" . $validateResult['stderr']),
                'backup_path' => $backupPath,
            ];
        }
    }

    if ($validateOnly) {
        // 仅校验，回滚到原内容
        host_agent_host_shell('cp ' . escapeshellarg($backupPath) . ' ' . escapeshellarg($path) . ' 2>/dev/null || true');
        return [
            'ok' => true,
            'msg' => '配置校验通过',
            'path' => $path,
            'validate_output' => $validateCmd !== '' ? trim((string)($validateResult['stdout'] ?? '')) : '',
        ];
    }

    // 5. 重载服务
    $reloadCmd = trim((string)($def['reload_cmd'] ?? ''));
    $reloadOutput = '';
    if ($reloadCmd !== '') {
        $reloadResult = host_agent_host_shell($reloadCmd . ' 2>&1');
        $reloadOutput = trim($reloadResult['stdout'] . "\n" . $reloadResult['stderr']);
    }

    return [
        'ok' => true,
        'msg' => '配置已应用' . ($reloadCmd !== '' ? '并尝试重载服务' : ''),
        'path' => $path,
        'backup_path' => $backupPath,
        'reload_output' => $reloadOutput,
    ];
}

function host_agent_config_history(string $configId, string $manager, int $limit = 10): array {
    $path = host_agent_config_get_path($configId, $manager);
    if ($path === null) {
        return ['ok' => false, 'msg' => '配置路径未知'];
    }
    $dir = dirname($path);
    $basename = basename($path);
    $result = host_agent_host_shell('ls -1t ' . escapeshellarg($dir) . '/' . escapeshellarg($basename) . '.backup.* 2>/dev/null | head -n ' . (int)$limit);
    $files = [];
    if ($result['ok']) {
        foreach (preg_split('/\r?\n/', trim($result['stdout'])) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') continue;
            if (preg_match('/\.backup\.(\d{8}_\d{6})$/', $line, $m)) {
                $ts = $m[1];
                $time = substr($ts, 0, 4) . '-' . substr($ts, 4, 2) . '-' . substr($ts, 6, 2) . ' ' . substr($ts, 9, 2) . ':' . substr($ts, 11, 2) . ':' . substr($ts, 13, 2);
                $files[] = ['path' => $line, 'time' => $time, 'timestamp' => $ts];
            }
        }
    }
    return ['ok' => true, 'backups' => $files, 'config_id' => $configId];
}

function host_agent_config_restore(string $configId, string $manager, string $backupPath): array {
    $defs = host_agent_config_definitions();
    $def = $defs[$configId] ?? null;
    if (!$def) {
        return ['ok' => false, 'msg' => '未知配置项'];
    }

    $path = host_agent_config_get_path($configId, $manager);
    if ($path === null) {
        return ['ok' => false, 'msg' => '配置路径未知'];
    }

    // 安全检查：备份文件必须是以 .backup. 结尾的、在同一目录下的文件
    $dir = dirname($path);
    $realBackup = realpath($backupPath);
    if ($realBackup === false || strpos($realBackup, $dir) !== 0 || !str_ends_with($realBackup, '.backup.')) {
        return ['ok' => false, 'msg' => '非法备份路径'];
    }

    // 备份当前
    $currentBackup = host_agent_config_backup_path($path);
    host_agent_host_shell('cp ' . escapeshellarg($path) . ' ' . escapeshellarg($currentBackup) . ' 2>/dev/null || true');

    // 恢复
    $cp = host_agent_host_shell('cp ' . escapeshellarg($backupPath) . ' ' . escapeshellarg($path));
    if (!$cp['ok']) {
        return ['ok' => false, 'msg' => '恢复失败'];
    }

    // 重载服务
    $reloadCmd = trim((string)($def['reload_cmd'] ?? ''));
    $reloadOutput = '';
    if ($reloadCmd !== '') {
        $reloadResult = host_agent_host_shell($reloadCmd . ' 2>&1');
        $reloadOutput = trim($reloadResult['stdout'] . "\n" . $reloadResult['stderr']);
    }

    return [
        'ok' => true,
        'msg' => '配置已恢复' . ($reloadCmd !== '' ? '并尝试重载服务' : ''),
        'path' => $path,
        'backup_path' => $currentBackup,
        'restored_from' => $backupPath,
        'reload_output' => $reloadOutput,
    ];
}

function host_agent_config_definitions_response(): array {
    $defs = host_agent_config_definitions();
    $result = [];
    foreach ($defs as $id => $def) {
        $result[] = [
            'id' => $id,
            'label' => $def['label'] ?? $id,
            'icon' => $def['icon'] ?? '📄',
            'format' => $def['format'] ?? 'text',
            'sections' => $def['sections'] ?? [],
        ];
    }
    return ['ok' => true, 'definitions' => $result];
}

// ============================================================
// Declarative Manifest Engine (Phase 3)
// ============================================================

function host_agent_manifest_validate_schema(array $manifest): array {
    $errors = [];

    // packages
    if (isset($manifest['packages']) && is_array($manifest['packages'])) {
        foreach ($manifest['packages'] as $name => $spec) {
            if (!is_array($spec)) {
                $errors[] = 'packages.' . $name . ' 必须是对象';
                continue;
            }
            $state = $spec['state'] ?? '';
            if (!in_array($state, ['installed', 'absent'], true)) {
                $errors[] = 'packages.' . $name . '.state 必须是 "installed" 或 "absent"';
            }
        }
    }

    // services
    if (isset($manifest['services']) && is_array($manifest['services'])) {
        foreach ($manifest['services'] as $name => $spec) {
            if (!is_array($spec)) {
                $errors[] = 'services.' . $name . ' 必须是对象';
                continue;
            }
            $state = $spec['state'] ?? '';
            if ($state !== '' && !in_array($state, ['running', 'stopped'], true)) {
                $errors[] = 'services.' . $name . '.state 必须是 "running" 或 "stopped"';
            }
            if (isset($spec['enabled']) && !is_bool($spec['enabled'])) {
                $errors[] = 'services.' . $name . '.enabled 必须是布尔值';
            }
        }
    }

    // configs
    if (isset($manifest['configs']) && is_array($manifest['configs'])) {
        $validConfigIds = array_keys(host_agent_config_definitions());
        foreach ($manifest['configs'] as $id => $spec) {
            if (!in_array($id, $validConfigIds, true)) {
                $errors[] = 'configs.' . $id . ' 不是有效的配置项';
            }
        }
    }

    // users
    if (isset($manifest['users']) && is_array($manifest['users'])) {
        foreach ($manifest['users'] as $name => $spec) {
            if (!is_array($spec)) {
                $errors[] = 'users.' . $name . ' 必须是对象';
                continue;
            }
            $state = $spec['state'] ?? '';
            if (!in_array($state, ['present', 'absent'], true)) {
                $errors[] = 'users.' . $name . '.state 必须是 "present" 或 "absent"';
            }
        }
    }

    return $errors;
}

function host_agent_manifest_apply(array $manifest, bool $dryRun = false): array {
    $manager = host_agent_detect_package_manager();
    $svcManager = host_agent_detect_service_manager();
    $changes = [];
    $errors = [];

    // 1. 校验 schema
    $schemaErrors = host_agent_manifest_validate_schema($manifest);
    if (!empty($schemaErrors)) {
        return ['ok' => false, 'msg' => 'Manifest 校验失败', 'errors' => $schemaErrors];
    }

    // 2. 包状态对齐
    if (isset($manifest['packages']) && is_array($manifest['packages'])) {
        foreach ($manifest['packages'] as $pkg => $spec) {
            $desired = ($spec['state'] ?? '') === 'installed';
            $resolved = host_agent_resolve_package_name($pkg, $manager);
            if ($resolved === null) {
                $errors[] = 'packages.' . $pkg . ': 该包在 ' . $manager . ' 上无映射';
                continue;
            }
            $isInstalled = host_agent_package_is_installed($manager, $resolved);
            if ($isInstalled !== $desired) {
                if ($dryRun) {
                    $changes[] = ['type' => 'package', 'name' => $pkg, 'action' => $desired ? 'install' : 'remove', 'dry_run' => true];
                } else {
                    if ($desired) {
                        $result = host_agent_package_install($manager, $resolved);
                        $changes[] = ['type' => 'package', 'name' => $pkg, 'action' => 'install', 'ok' => $result['ok'], 'msg' => $result['msg'] ?? ''];
                    } else {
                        $result = host_agent_package_remove($manager, $resolved);
                        $changes[] = ['type' => 'package', 'name' => $pkg, 'action' => 'remove', 'ok' => $result['ok'], 'msg' => $result['msg'] ?? ''];
                    }
                }
            }
        }
    }

    // 3. 服务状态对齐
    if (isset($manifest['services']) && is_array($manifest['services'])) {
        foreach ($manifest['services'] as $svc => $spec) {
            $desiredRunning = ($spec['state'] ?? '') === 'running' ? true : (($spec['state'] ?? '') === 'stopped' ? false : null);
            $desiredEnabled = $spec['enabled'] ?? null;

            // 检测服务当前状态
            $status = host_agent_detect_named_host_service($svc);
            if (empty($status['service_name'])) {
                // 尝试通用服务检测
                $status = host_agent_generic_service_status('/hostfs', 'host', $svc);
                if (!$status['ok'] || empty($status['service_name'])) {
                    $errors[] = 'services.' . $svc . ': 未检测到服务';
                    continue;
                }
                $currentRunning = $status['running'] ?? false;
                $currentEnabled = $status['enabled'] ?? null;
                $serviceName = $status['service_name'];
            } else {
                $currentRunning = $status['running'] ?? false;
                $currentEnabled = $status['enabled'] ?? null;
                $serviceName = $status['service_name'];
            }

            if ($desiredRunning !== null && $currentRunning !== $desiredRunning) {
                $action = $desiredRunning ? 'start' : 'stop';
                if ($dryRun) {
                    $changes[] = ['type' => 'service', 'name' => $svc, 'action' => $action, 'dry_run' => true];
                } else {
                    // 使用通用服务操作
                    $result = host_agent_generic_service_action('/hostfs', 'host', $svc, $action);
                    $changes[] = ['type' => 'service', 'name' => $svc, 'action' => $action, 'ok' => $result['ok'], 'msg' => $result['msg'] ?? ''];
                }
            }

            if ($desiredEnabled !== null && $currentEnabled !== $desiredEnabled && $desiredEnabled !== null) {
                $action = $desiredEnabled ? 'enable' : 'disable';
                if ($dryRun) {
                    $changes[] = ['type' => 'service', 'name' => $svc, 'action' => $action, 'dry_run' => true];
                } else {
                    $result = host_agent_generic_service_action('/hostfs', 'host', $svc, $action);
                    $changes[] = ['type' => 'service', 'name' => $svc, 'action' => $action, 'ok' => $result['ok'], 'msg' => $result['msg'] ?? ''];
                }
            }
        }
    }

    // 4. 配置状态对齐
    if (isset($manifest['configs']) && is_array($manifest['configs'])) {
        foreach ($manifest['configs'] as $configId => $spec) {
            $readResult = host_agent_config_read($configId, $manager);
            if (!$readResult['ok']) {
                $errors[] = 'configs.' . $configId . ': 读取失败 - ' . ($readResult['msg'] ?? '');
                continue;
            }
            $currentContent = $readResult['content'] ?? '';

            // 如果 spec 是字符串，直接作为完整配置内容
            // 如果 spec 是对象，逐键对比并生成新配置
            if (is_string($spec)) {
                $desiredContent = $spec;
            } elseif (is_array($spec)) {
                // 简单的键值对替换（仅支持 INI-like 和 key-value 格式）
                $desiredContent = host_agent_manifest_apply_config_changes($currentContent, $configId, $spec);
            } else {
                $errors[] = 'configs.' . $configId . ': 不支持的数据类型';
                continue;
            }

            if ($currentContent !== $desiredContent) {
                if ($dryRun) {
                    $changes[] = ['type' => 'config', 'name' => $configId, 'action' => 'apply', 'dry_run' => true];
                } else {
                    $result = host_agent_config_apply($configId, $manager, $desiredContent);
                    $changes[] = ['type' => 'config', 'name' => $configId, 'action' => 'apply', 'ok' => $result['ok'], 'msg' => $result['msg'] ?? '', 'backup_path' => $result['backup_path'] ?? ''];
                }
            }
        }
    }

    return [
        'ok' => empty($errors),
        'msg' => empty($errors) ? ($dryRun ? '预演完成' : '应用完成') : '部分操作失败',
        'dry_run' => $dryRun,
        'changes' => $changes,
        'errors' => $errors,
        'changed' => !empty($changes),
    ];
}

function host_agent_manifest_apply_config_changes(string $currentContent, string $configId, array $changes): string {
    $defs = host_agent_config_definitions();
    $format = (string)($defs[$configId]['format'] ?? 'text');

    $lines = preg_split('/\r?\n/', $currentContent) ?: [];

    foreach ($changes as $key => $value) {
        if (!is_string($value) && !is_int($value) && !is_bool($value)) {
            continue;
        }
        $valueStr = is_bool($value) ? ($value ? 'yes' : 'no') : (string)$value;
        $found = false;

        foreach ($lines as $i => $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }

            if ($format === 'ini' || $format === 'ssh_config' || $format === 'redis_conf' || $format === 'postgresql_conf') {
                // key = value 格式
                if (preg_match('/^' . preg_quote($key, '/') . '\s*[=:]\s*/i', $trimmed)) {
                    $lines[$i] = $key . ' = ' . $valueStr;
                    $found = true;
                    break;
                }
            } elseif ($format === 'nginx') {
                // nginx key value; 格式
                if (preg_match('/^\s*' . preg_quote($key, '/') . '\s+/i', $trimmed)) {
                    $indent = '';
                    if (preg_match('/^(\s*)/', $line, $m)) {
                        $indent = $m[1];
                    }
                    $lines[$i] = $indent . $key . ' ' . $valueStr . ';';
                    $found = true;
                    break;
                }
            }
        }

        if (!$found) {
            // 添加到文件末尾
            $lines[] = $key . ' = ' . $valueStr;
        }
    }

    return implode("\n", $lines) . "\n";
}

function host_agent_sftp_policies_from_config(string $content): array {
    [$startMarker, $endMarker] = host_agent_sftp_block_markers();
    $block = host_agent_managed_block_extract($content, $startMarker, $endMarker);
    if ($block === '') {
        return [];
    }
    $policies = [];
    $current = null;
    foreach (preg_split('/\r?\n/', $block) ?: [] as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '#')) {
            continue;
        }
        if (preg_match('/^Match\s+User\s+(.+)$/i', $trimmed, $matches)) {
            if (is_array($current) && !empty($current['username'])) {
                $policies[] = $current;
            }
            $current = [
                'username' => trim((string)($matches[1] ?? '')),
                'enabled' => true,
                'sftp_only' => true,
                'chroot_directory' => '',
                'force_internal_sftp' => true,
                'allow_password' => true,
                'allow_pubkey' => true,
            ];
            continue;
        }
        if (!is_array($current) || !preg_match('/^([A-Za-z][A-Za-z0-9]+)\s+(.+)$/', $trimmed, $matches)) {
            continue;
        }
        $key = strtolower((string)$matches[1]);
        $value = trim((string)$matches[2]);
        if ($key === 'chrootdirectory') {
            $current['chroot_directory'] = $value;
        } elseif ($key === 'forcecommand') {
            $current['force_internal_sftp'] = str_contains(strtolower($value), 'internal-sftp');
            $current['sftp_only'] = $current['force_internal_sftp'];
        } elseif ($key === 'passwordauthentication') {
            $current['allow_password'] = strtolower($value) === 'yes';
        } elseif ($key === 'pubkeyauthentication') {
            $current['allow_pubkey'] = strtolower($value) === 'yes';
        }
    }
    if (is_array($current) && !empty($current['username'])) {
        $policies[] = $current;
    }
    usort($policies, static fn(array $a, array $b): int => strcmp((string)($a['username'] ?? ''), (string)($b['username'] ?? '')));
    return $policies;
}

function host_agent_render_sftp_policies_block(array $policies): string {
    $lines = [];
    foreach ($policies as $policy) {
        if (!is_array($policy) || empty($policy['username']) || empty($policy['enabled'])) {
            continue;
        }
        $username = trim((string)$policy['username']);
        $lines[] = 'Match User ' . $username;
        if (trim((string)($policy['chroot_directory'] ?? '')) !== '') {
            $lines[] = '    ChrootDirectory ' . trim((string)$policy['chroot_directory']);
        }
        if (!empty($policy['force_internal_sftp'])) {
            $lines[] = '    ForceCommand internal-sftp';
        }
        $lines[] = '    PasswordAuthentication ' . (!empty($policy['allow_password']) ? 'yes' : 'no');
        $lines[] = '    PubkeyAuthentication ' . (!empty($policy['allow_pubkey']) ? 'yes' : 'no');
        $lines[] = '    PermitTTY no';
        $lines[] = '    X11Forwarding no';
        $lines[] = '';
    }
    return trim(implode("\n", $lines));
}

function host_agent_sftp_status(string $root, string $mode): array {
    $ssh = host_agent_live_ssh_status($root, $mode);
    $config = host_agent_read_ssh_config_payload($root, $mode);
    $content = (string)($config['content'] ?? '');
    $policies = host_agent_sftp_policies_from_config($content);
    return [
        'ok' => true,
        'service' => 'sftp',
        'service_name' => (string)($ssh['service_name'] ?? 'ssh'),
        'service_manager' => (string)($ssh['service_manager'] ?? 'simulate'),
        'installed' => (bool)($ssh['installed'] ?? true),
        'running' => (bool)($ssh['running'] ?? true),
        'enabled' => $ssh['enabled'] ?? true,
        'config_path' => (string)($ssh['config_path'] ?? host_agent_ssh_config_path($root, $mode)),
        'subsystem_detected' => preg_match('/^\s*Subsystem\s+sftp\s+/mi', $content) === 1,
        'policies' => $policies,
        'policy_count' => count($policies),
        'updated_at' => (string)($ssh['updated_at'] ?? date('Y-m-d H:i:s')),
    ];
}

function host_agent_sftp_policy_list(string $root, string $mode): array {
    $status = host_agent_sftp_status($root, $mode);
    return ['ok' => true, 'items' => (array)($status['policies'] ?? []), 'status' => $status];
}

function host_agent_sftp_policy_save(string $root, string $mode, array $payload): array {
    $username = trim((string)($payload['username'] ?? ''));
    if ($username === '' || !preg_match('/^[a-z_][a-z0-9_-]*[$]?$/i', $username)) {
        return ['ok' => false, 'msg' => 'SFTP 用户名无效'];
    }
    $current = host_agent_read_ssh_config_payload($root, $mode);
    if (empty($current['ok'])) {
        return $current;
    }
    $content = (string)($current['content'] ?? '');
    $policies = host_agent_sftp_policies_from_config($content);
    $next = [];
    foreach ($policies as $item) {
        if (($item['username'] ?? '') !== $username) {
            $next[] = $item;
        }
    }
    $next[] = [
        'username' => $username,
        'enabled' => !empty($payload['enabled']),
        'sftp_only' => !empty($payload['sftp_only']),
        'chroot_directory' => trim((string)($payload['chroot_directory'] ?? '')),
        'force_internal_sftp' => !empty($payload['force_internal_sftp']),
        'allow_password' => !empty($payload['allow_password']),
        'allow_pubkey' => !empty($payload['allow_pubkey']),
    ];
    usort($next, static fn(array $a, array $b): int => strcmp((string)($a['username'] ?? ''), (string)($b['username'] ?? '')));
    [$startMarker, $endMarker] = host_agent_sftp_block_markers();
    $newContent = host_agent_managed_block_replace($content, $startMarker, $endMarker, host_agent_render_sftp_policies_block($next));
    $validation = host_agent_validate_ssh_config($root, $mode, $newContent);
    if (empty($validation['ok'])) {
        return $validation;
    }
    $saved = host_agent_save_ssh_config($root, $mode, $newContent);
    if (empty($saved['ok'])) {
        return $saved;
    }
    return $saved + ['msg' => 'SFTP 策略已保存', 'status' => host_agent_sftp_status($root, $mode)];
}

function host_agent_sftp_policy_delete(string $root, string $mode, string $username): array {
    $username = trim($username);
    if ($username === '') {
        return ['ok' => false, 'msg' => '用户名不能为空'];
    }
    $current = host_agent_read_ssh_config_payload($root, $mode);
    if (empty($current['ok'])) {
        return $current;
    }
    $content = (string)($current['content'] ?? '');
    $policies = host_agent_sftp_policies_from_config($content);
    $before = count($policies);
    $policies = array_values(array_filter($policies, static fn(array $item): bool => (string)($item['username'] ?? '') !== $username));
    if (count($policies) === $before) {
        return ['ok' => false, 'msg' => '未找到对应 SFTP 策略'];
    }
    [$startMarker, $endMarker] = host_agent_sftp_block_markers();
    $newContent = host_agent_managed_block_replace($content, $startMarker, $endMarker, host_agent_render_sftp_policies_block($policies));
    $validation = host_agent_validate_ssh_config($root, $mode, $newContent);
    if (empty($validation['ok'])) {
        return $validation;
    }
    $saved = host_agent_save_ssh_config($root, $mode, $newContent);
    if (empty($saved['ok'])) {
        return $saved;
    }
    return $saved + ['msg' => 'SFTP 策略已删除', 'status' => host_agent_sftp_status($root, $mode)];
}

function host_agent_smb_shares_from_config(string $content): array {
    [$startMarker, $endMarker] = host_agent_smb_block_markers();
    $block = host_agent_managed_block_extract($content, $startMarker, $endMarker);
    if ($block === '') {
        return [];
    }
    $shares = [];
    $current = null;
    foreach (preg_split('/\r?\n/', $block) ?: [] as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '#') || str_starts_with($trimmed, ';')) {
            continue;
        }
        if (preg_match('/^\[(.+)\]$/', $trimmed, $matches)) {
            if (is_array($current) && !empty($current['name'])) {
                $shares[] = $current;
            }
            $current = [
                'name' => trim((string)($matches[1] ?? '')),
                'path' => '',
                'comment' => '',
                'browseable' => true,
                'read_only' => false,
                'guest_ok' => false,
                'valid_users' => [],
                'write_users' => [],
            ];
            continue;
        }
        if (!is_array($current) || !preg_match('/^([^=]+?)\s*=\s*(.+)$/', $trimmed, $matches)) {
            continue;
        }
        $key = strtolower(trim((string)$matches[1]));
        $value = trim((string)$matches[2]);
        if ($key === 'path') {
            $current['path'] = $value;
        } elseif ($key === 'comment') {
            $current['comment'] = $value;
        } elseif ($key === 'browseable') {
            $current['browseable'] = strtolower($value) === 'yes';
        } elseif ($key === 'read only') {
            $current['read_only'] = strtolower($value) === 'yes';
        } elseif ($key === 'guest ok') {
            $current['guest_ok'] = strtolower($value) === 'yes';
        } elseif ($key === 'valid users') {
            $current['valid_users'] = array_values(array_filter(array_map('trim', explode(',', $value)), static fn(string $item): bool => $item !== ''));
        } elseif ($key === 'write list') {
            $current['write_users'] = array_values(array_filter(array_map('trim', explode(',', $value)), static fn(string $item): bool => $item !== ''));
        }
    }
    if (is_array($current) && !empty($current['name'])) {
        $shares[] = $current;
    }
    usort($shares, static fn(array $a, array $b): int => strcmp((string)($a['name'] ?? ''), (string)($b['name'] ?? '')));
    return $shares;
}

function host_agent_render_smb_shares_block(array $shares): string {
    $lines = [];
    foreach ($shares as $share) {
        if (!is_array($share) || empty($share['name']) || trim((string)($share['path'] ?? '')) === '') {
            continue;
        }
        $lines[] = '[' . trim((string)$share['name']) . ']';
        if (trim((string)($share['comment'] ?? '')) !== '') {
            $lines[] = '   comment = ' . trim((string)$share['comment']);
        }
        $lines[] = '   path = ' . trim((string)$share['path']);
        $lines[] = '   browseable = ' . (!empty($share['browseable']) ? 'yes' : 'no');
        $lines[] = '   read only = ' . (!empty($share['read_only']) ? 'yes' : 'no');
        $lines[] = '   guest ok = ' . (!empty($share['guest_ok']) ? 'yes' : 'no');
        if (!empty($share['valid_users'])) {
            $lines[] = '   valid users = ' . implode(', ', array_values(array_filter(array_map('trim', (array)$share['valid_users']))));
        }
        if (!empty($share['write_users'])) {
            $lines[] = '   write list = ' . implode(', ', array_values(array_filter(array_map('trim', (array)$share['write_users']))));
        }
        $lines[] = '';
    }
    return trim(implode("\n", $lines));
}

function host_agent_smb_status(string $root, string $mode): array {
    $service = host_agent_generic_service_status($root, $mode, 'smb');
    $path = host_agent_ensure_smb_config($root, $mode);
    $content = host_agent_read_file($path);
    $shares = host_agent_smb_shares_from_config($content);
    return $service + [
        'config_path' => $path,
        'shares' => $shares,
        'share_count' => count($shares),
    ];
}

function host_agent_smb_share_list(string $root, string $mode): array {
    $status = host_agent_smb_status($root, $mode);
    return ['ok' => true, 'items' => (array)($status['shares'] ?? []), 'status' => $status];
}

function host_agent_smb_validate_config(string $root, string $mode, string $content): array {
    if ($mode !== 'host') {
        return ['ok' => true, 'msg' => 'simulate 模式校验通过'];
    }
    $result = host_agent_host_shell('if command -v testparm >/dev/null 2>&1; then tmp=/tmp/host-agent-smb-' . bin2hex(random_bytes(4)) . '.conf; cat > "$tmp"; testparm -s "$tmp" >/tmp/host-agent-smb-check.log 2>&1; code=$?; rm -f "$tmp"; cat /tmp/host-agent-smb-check.log; rm -f /tmp/host-agent-smb-check.log; exit $code; else exit 0; fi', $content);
    return ['ok' => $result['ok'], 'msg' => $result['ok'] ? 'SMB 配置校验通过' : trim($result['stderr'] ?: $result['stdout'] ?: 'SMB 配置校验失败')];
}

function host_agent_smb_share_save(string $root, string $mode, array $payload): array {
    $name = trim((string)($payload['name'] ?? ''));
    $pathValue = trim((string)($payload['path'] ?? ''));
    if ($name === '' || !preg_match('/^[A-Za-z0-9_.-]+$/', $name)) {
        return ['ok' => false, 'msg' => 'SMB 共享名无效'];
    }
    if ($pathValue === '' || !str_starts_with($pathValue, '/')) {
        return ['ok' => false, 'msg' => 'SMB 共享目录必须是绝对路径'];
    }
    if ($mode !== 'host' && !is_dir(rtrim($root, '/') . $pathValue)) {
        @mkdir(rtrim($root, '/') . $pathValue, 0755, true);
    }
    $configPath = host_agent_ensure_smb_config($root, $mode);
    $content = host_agent_read_file($configPath);
    $shares = host_agent_smb_shares_from_config($content);
    $next = [];
    foreach ($shares as $item) {
        if (($item['name'] ?? '') !== $name) {
            $next[] = $item;
        }
    }
    $next[] = [
        'name' => $name,
        'path' => $pathValue,
        'comment' => trim((string)($payload['comment'] ?? '')),
        'browseable' => !empty($payload['browseable']),
        'read_only' => !empty($payload['read_only']),
        'guest_ok' => !empty($payload['guest_ok']),
        'valid_users' => array_values(array_filter(array_map('trim', explode(',', (string)($payload['valid_users'] ?? ''))), static fn(string $item): bool => $item !== '')),
        'write_users' => array_values(array_filter(array_map('trim', explode(',', (string)($payload['write_users'] ?? ''))), static fn(string $item): bool => $item !== '')),
    ];
    usort($next, static fn(array $a, array $b): int => strcmp((string)($a['name'] ?? ''), (string)($b['name'] ?? '')));
    [$startMarker, $endMarker] = host_agent_smb_block_markers();
    $newContent = host_agent_managed_block_replace($content, $startMarker, $endMarker, host_agent_render_smb_shares_block($next));
    $validation = host_agent_smb_validate_config($root, $mode, $newContent);
    if (empty($validation['ok'])) {
        return $validation;
    }
    file_put_contents($configPath, $newContent, LOCK_EX);
    return ['ok' => true, 'msg' => 'SMB 共享已保存', 'status' => host_agent_smb_status($root, $mode)];
}

function host_agent_smb_share_delete(string $root, string $mode, string $name): array {
    $name = trim($name);
    if ($name === '') {
        return ['ok' => false, 'msg' => '共享名不能为空'];
    }
    $configPath = host_agent_ensure_smb_config($root, $mode);
    $content = host_agent_read_file($configPath);
    $shares = host_agent_smb_shares_from_config($content);
    $before = count($shares);
    $shares = array_values(array_filter($shares, static fn(array $item): bool => (string)($item['name'] ?? '') !== $name));
    if (count($shares) === $before) {
        return ['ok' => false, 'msg' => '未找到对应 SMB 共享'];
    }
    [$startMarker, $endMarker] = host_agent_smb_block_markers();
    $newContent = host_agent_managed_block_replace($content, $startMarker, $endMarker, host_agent_render_smb_shares_block($shares));
    $validation = host_agent_smb_validate_config($root, $mode, $newContent);
    if (empty($validation['ok'])) {
        return $validation;
    }
    file_put_contents($configPath, $newContent, LOCK_EX);
    return ['ok' => true, 'msg' => 'SMB 共享已删除', 'status' => host_agent_smb_status($root, $mode)];
}

function host_agent_ftp_settings_defaults(string $root, string $mode): array {
    return [
        'listen_port' => '21',
        'anonymous_enable' => 'NO',
        'local_enable' => 'YES',
        'write_enable' => 'YES',
        'chroot_local_user' => 'YES',
        'local_root' => '',
        'pasv_enable' => 'YES',
        'pasv_min_port' => '40000',
        'pasv_max_port' => '40100',
        'userlist_enable' => 'YES',
        'userlist_deny' => 'NO',
        'userlist_file' => host_agent_ftp_userlist_path($root, $mode),
    ];
}

function host_agent_ftp_settings_from_config(string $root, string $mode, string $content): array {
    $settings = host_agent_ftp_settings_defaults($root, $mode);
    [$startMarker, $endMarker] = host_agent_ftp_block_markers();
    $block = host_agent_managed_block_extract($content, $startMarker, $endMarker);
    $source = $block !== '' ? $block : $content;
    foreach (preg_split('/\r?\n/', $source) ?: [] as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '#') || !str_contains($trimmed, '=')) {
            continue;
        }
        [$key, $value] = array_pad(explode('=', $trimmed, 2), 2, '');
        $key = trim($key);
        if (array_key_exists($key, $settings)) {
            $settings[$key] = trim($value);
        }
    }
    return $settings;
}

function host_agent_render_ftp_settings_block(array $settings): string {
    $lines = [];
    foreach ($settings as $key => $value) {
        $value = trim((string)$value);
        if ($value === '') {
            continue;
        }
        $lines[] = $key . '=' . $value;
    }
    return trim(implode("\n", $lines));
}

function host_agent_ftp_allowed_users(string $root, string $mode): array {
    $path = host_agent_ftp_userlist_path($root, $mode);
    if (!is_file($path)) {
        return [];
    }
    return array_values(array_filter(array_map('trim', preg_split('/\r?\n/', (string)file_get_contents($path)) ?: []), static fn(string $item): bool => $item !== ''));
}

function host_agent_ftp_status(string $root, string $mode): array {
    $service = host_agent_generic_service_status($root, $mode, 'ftp');
    $path = host_agent_ensure_ftp_config($root, $mode);
    $content = host_agent_read_file($path);
    $settings = host_agent_ftp_settings_from_config($root, $mode, $content);
    return $service + [
        'config_path' => $path,
        'userlist_path' => host_agent_ftp_userlist_path($root, $mode),
        'settings' => $settings,
        'allowed_users' => host_agent_ftp_allowed_users($root, $mode),
    ];
}

function host_agent_ftp_settings_save(string $root, string $mode, array $payload): array {
    $configPath = host_agent_ensure_ftp_config($root, $mode);
    $content = host_agent_read_file($configPath);
    $settings = host_agent_ftp_settings_defaults($root, $mode);
    $settings['listen_port'] = trim((string)($payload['listen_port'] ?? '21')) ?: '21';
    $settings['anonymous_enable'] = !empty($payload['anonymous_enable']) ? 'YES' : 'NO';
    $settings['local_enable'] = !empty($payload['local_enable']) ? 'YES' : 'NO';
    $settings['write_enable'] = !empty($payload['write_enable']) ? 'YES' : 'NO';
    $settings['chroot_local_user'] = !empty($payload['chroot_local_user']) ? 'YES' : 'NO';
    $settings['local_root'] = trim((string)($payload['local_root'] ?? ''));
    $settings['pasv_enable'] = !empty($payload['pasv_enable']) ? 'YES' : 'NO';
    $settings['pasv_min_port'] = trim((string)($payload['pasv_min_port'] ?? '40000')) ?: '40000';
    $settings['pasv_max_port'] = trim((string)($payload['pasv_max_port'] ?? '40100')) ?: '40100';
    $settings['userlist_enable'] = 'YES';
    $settings['userlist_deny'] = 'NO';
    $settings['userlist_file'] = host_agent_ftp_userlist_path($root, $mode);
    [$startMarker, $endMarker] = host_agent_ftp_block_markers();
    $newContent = host_agent_managed_block_replace($content, $startMarker, $endMarker, host_agent_render_ftp_settings_block($settings));
    file_put_contents($configPath, $newContent, LOCK_EX);
    $userlistPath = host_agent_ftp_userlist_path($root, $mode);
    $users = array_values(array_filter(array_map('trim', explode(',', (string)($payload['allowed_users'] ?? ''))), static fn(string $item): bool => $item !== ''));
    host_agent_ensure_parent_dir($userlistPath);
    file_put_contents($userlistPath, implode("\n", $users) . (count($users) ? "\n" : ''), LOCK_EX);
    return ['ok' => true, 'msg' => 'FTP 配置已保存', 'status' => host_agent_ftp_status($root, $mode)];
}

function host_agent_nfs_exports_from_content(string $content): array {
    [$startMarker, $endMarker] = host_agent_nfs_block_markers();
    $block = host_agent_managed_block_extract($content, $startMarker, $endMarker);
    if ($block === '') {
        return [];
    }
    $items = [];
    foreach (preg_split('/\r?\n/', $block) ?: [] as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '#')) {
            continue;
        }
        if (preg_match('/^(\S+)\s+(\S+)\(([^)]*)\)$/', $trimmed, $matches)) {
            $opts = array_values(array_filter(array_map('trim', explode(',', (string)$matches[3])), static fn(string $item): bool => $item !== ''));
            $items[] = [
                'path' => (string)$matches[1],
                'clients' => (string)$matches[2],
                'options' => $opts,
                'async_mode' => in_array('async', $opts, true),
            ];
        }
    }
    return $items;
}

function host_agent_render_nfs_exports(array $items): string {
    $lines = [];
    foreach ($items as $item) {
        if (!is_array($item) || trim((string)($item['path'] ?? '')) === '' || trim((string)($item['clients'] ?? '')) === '') {
            continue;
        }
        $opts = array_values(array_filter(array_map('trim', (array)($item['options'] ?? [])), static fn(string $value): bool => $value !== ''));
        $lines[] = trim((string)$item['path']) . ' ' . trim((string)$item['clients']) . '(' . implode(',', $opts) . ')';
    }
    return trim(implode("\n", $lines));
}

function host_agent_nfs_ports_from_content(string $root, string $mode, string $content): array {
    $defaults = [
        'mountd_port' => '',
        'statd_port' => '',
        'lockd_port' => '',
    ];
    [$startMarker, $endMarker] = host_agent_nfs_ports_markers();
    $block = host_agent_managed_block_extract($content, $startMarker, $endMarker);
    if ($block === '') {
        return $defaults;
    }
    foreach (preg_split('/\r?\n/', $block) ?: [] as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '#') || !str_contains($trimmed, '=')) {
            continue;
        }
        [$key, $value] = array_pad(explode('=', $trimmed, 2), 2, '');
        $key = trim($key);
        if ($key === 'mountd.port') {
            $defaults['mountd_port'] = trim($value);
        } elseif ($key === 'statd.port') {
            $defaults['statd_port'] = trim($value);
        } elseif ($key === 'lockd.port') {
            $defaults['lockd_port'] = trim($value);
        }
    }
    return $defaults;
}

function host_agent_render_nfs_ports(array $ports): string {
    $lines = [];
    if (trim((string)($ports['mountd_port'] ?? '')) !== '') $lines[] = 'mountd.port=' . trim((string)$ports['mountd_port']);
    if (trim((string)($ports['statd_port'] ?? '')) !== '') $lines[] = 'statd.port=' . trim((string)$ports['statd_port']);
    if (trim((string)($ports['lockd_port'] ?? '')) !== '') $lines[] = 'lockd.port=' . trim((string)$ports['lockd_port']);
    return trim(implode("\n", $lines));
}

function host_agent_nfs_status(string $root, string $mode): array {
    $service = host_agent_generic_service_status($root, $mode, 'nfs');
    host_agent_ensure_nfs_configs($root, $mode);
    $exportsPath = host_agent_nfs_exports_path($root, $mode);
    $nfsConfPath = host_agent_nfs_conf_path($root, $mode);
    return $service + [
        'exports_path' => $exportsPath,
        'nfs_conf_path' => $nfsConfPath,
        'exports' => host_agent_nfs_exports_from_content(host_agent_read_file($exportsPath)),
        'ports' => host_agent_nfs_ports_from_content($root, $mode, host_agent_read_file($nfsConfPath)),
    ];
}

function host_agent_nfs_export_save(string $root, string $mode, array $payload): array {
    $pathValue = trim((string)($payload['path'] ?? ''));
    $clients = trim((string)($payload['clients'] ?? ''));
    if ($pathValue === '' || !str_starts_with($pathValue, '/')) {
        return ['ok' => false, 'msg' => 'NFS 导出目录必须是绝对路径'];
    }
    if ($clients === '') {
        return ['ok' => false, 'msg' => 'NFS 客户端范围不能为空'];
    }
    host_agent_ensure_nfs_configs($root, $mode);
    if ($mode !== 'host') {
        @mkdir(rtrim($root, '/') . $pathValue, 0755, true);
    }
    $exportsContent = host_agent_read_file(host_agent_nfs_exports_path($root, $mode));
    $items = host_agent_nfs_exports_from_content($exportsContent);
    $options = array_values(array_filter(array_map('trim', explode(',', (string)($payload['options'] ?? 'rw,sync,no_subtree_check'))), static fn(string $item): bool => $item !== ''));
    if (!empty($payload['async_mode'])) {
        $options = array_values(array_unique(array_merge(array_diff($options, ['sync']), ['async'])));
    } else {
        $options = array_values(array_unique(array_merge(array_diff($options, ['async']), ['sync'])));
    }
    $items = array_values(array_filter($items, static fn(array $item): bool => (string)($item['path'] ?? '') !== $pathValue));
    $items[] = ['path' => $pathValue, 'clients' => $clients, 'options' => $options, 'async_mode' => in_array('async', $options, true)];
    [$startMarker, $endMarker] = host_agent_nfs_block_markers();
    file_put_contents(host_agent_nfs_exports_path($root, $mode), host_agent_managed_block_replace($exportsContent, $startMarker, $endMarker, host_agent_render_nfs_exports($items)), LOCK_EX);
    $nfsConf = host_agent_read_file(host_agent_nfs_conf_path($root, $mode));
    [$pStart, $pEnd] = host_agent_nfs_ports_markers();
    file_put_contents(host_agent_nfs_conf_path($root, $mode), host_agent_managed_block_replace($nfsConf, $pStart, $pEnd, host_agent_render_nfs_ports([
        'mountd_port' => trim((string)($payload['mountd_port'] ?? '')),
        'statd_port' => trim((string)($payload['statd_port'] ?? '')),
        'lockd_port' => trim((string)($payload['lockd_port'] ?? '')),
    ])), LOCK_EX);
    return ['ok' => true, 'msg' => 'NFS 导出已保存', 'status' => host_agent_nfs_status($root, $mode)];
}

function host_agent_nfs_export_delete(string $root, string $mode, string $pathValue): array {
    $pathValue = trim($pathValue);
    if ($pathValue === '') return ['ok' => false, 'msg' => '导出目录不能为空'];
    host_agent_ensure_nfs_configs($root, $mode);
    $content = host_agent_read_file(host_agent_nfs_exports_path($root, $mode));
    $items = host_agent_nfs_exports_from_content($content);
    $before = count($items);
    $items = array_values(array_filter($items, static fn(array $item): bool => (string)($item['path'] ?? '') !== $pathValue));
    if ($before === count($items)) return ['ok' => false, 'msg' => '未找到对应 NFS 导出'];
    [$startMarker, $endMarker] = host_agent_nfs_block_markers();
    file_put_contents(host_agent_nfs_exports_path($root, $mode), host_agent_managed_block_replace($content, $startMarker, $endMarker, host_agent_render_nfs_exports($items)), LOCK_EX);
    return ['ok' => true, 'msg' => 'NFS 导出已删除', 'status' => host_agent_nfs_status($root, $mode)];
}

function host_agent_afp_shares_from_content(string $content): array {
    [$startMarker, $endMarker] = host_agent_afp_block_markers();
    $block = host_agent_managed_block_extract($content, $startMarker, $endMarker);
    if ($block === '') return ['port' => '', 'shares' => []];
    $port = '';
    $shares = [];
    $current = null;
    foreach (preg_split('/\r?\n/', $block) ?: [] as $line) {
      $trimmed = trim($line);
      if ($trimmed === '' || str_starts_with($trimmed, '#')) continue;
      if (preg_match('/^afp port\s*=\s*(\d+)$/i', $trimmed, $m)) {
        $port = (string)$m[1];
        continue;
      }
      if (preg_match('/^\[(.+)\]$/', $trimmed, $m)) {
        if (is_array($current) && !empty($current['name'])) $shares[] = $current;
        $current = ['name' => trim((string)$m[1]), 'path' => '', 'valid_users' => [], 'rwlist' => []];
        continue;
      }
      if (!is_array($current) || !preg_match('/^([^=]+?)\s*=\s*(.+)$/', $trimmed, $m)) continue;
      $key = strtolower(trim((string)$m[1]));
      $value = trim((string)$m[2]);
      if ($key === 'path') $current['path'] = $value;
      elseif ($key === 'valid users') $current['valid_users'] = array_values(array_filter(array_map('trim', explode(',', $value)), static fn(string $i): bool => $i !== ''));
      elseif ($key === 'rwlist') $current['rwlist'] = array_values(array_filter(array_map('trim', explode(',', $value)), static fn(string $i): bool => $i !== ''));
    }
    if (is_array($current) && !empty($current['name'])) $shares[] = $current;
    return ['port' => $port, 'shares' => $shares];
}

function host_agent_render_afp_block(array $payload): string {
    $lines = [];
    if (trim((string)($payload['port'] ?? '')) !== '') $lines[] = 'afp port = ' . trim((string)$payload['port']);
    foreach ((array)($payload['shares'] ?? []) as $share) {
        if (!is_array($share) || empty($share['name']) || trim((string)($share['path'] ?? '')) === '') continue;
        $lines[] = '[' . trim((string)$share['name']) . ']';
        $lines[] = '    path = ' . trim((string)$share['path']);
        if (!empty($share['valid_users'])) $lines[] = '    valid users = ' . implode(', ', (array)$share['valid_users']);
        if (!empty($share['rwlist'])) $lines[] = '    rwlist = ' . implode(', ', (array)$share['rwlist']);
        $lines[] = '';
    }
    return trim(implode("\n", $lines));
}

function host_agent_afp_status(string $root, string $mode): array {
    $service = host_agent_generic_service_status($root, $mode, 'afp');
    $path = host_agent_ensure_afp_config($root, $mode);
    $parsed = host_agent_afp_shares_from_content(host_agent_read_file($path));
    return $service + ['config_path' => $path, 'port' => (string)($parsed['port'] ?? ''), 'shares' => (array)($parsed['shares'] ?? [])];
}

function host_agent_afp_share_save(string $root, string $mode, array $payload): array {
    $name = trim((string)($payload['name'] ?? ''));
    $pathValue = trim((string)($payload['path'] ?? ''));
    if ($name === '' || !preg_match('/^[A-Za-z0-9_.-]+$/', $name)) return ['ok' => false, 'msg' => 'AFP 共享名无效'];
    if ($pathValue === '' || !str_starts_with($pathValue, '/')) return ['ok' => false, 'msg' => 'AFP 共享目录必须是绝对路径'];
    $config = host_agent_ensure_afp_config($root, $mode);
    if ($mode !== 'host') @mkdir(rtrim($root, '/') . $pathValue, 0755, true);
    $parsed = host_agent_afp_shares_from_content(host_agent_read_file($config));
    $shares = array_values(array_filter((array)($parsed['shares'] ?? []), static fn(array $item): bool => (string)($item['name'] ?? '') !== $name));
    $shares[] = [
        'name' => $name,
        'path' => $pathValue,
        'valid_users' => array_values(array_filter(array_map('trim', explode(',', (string)($payload['valid_users'] ?? ''))), static fn(string $i): bool => $i !== '')),
        'rwlist' => array_values(array_filter(array_map('trim', explode(',', (string)($payload['rwlist'] ?? ''))), static fn(string $i): bool => $i !== '')),
    ];
    [$startMarker, $endMarker] = host_agent_afp_block_markers();
    file_put_contents($config, host_agent_managed_block_replace(host_agent_read_file($config), $startMarker, $endMarker, host_agent_render_afp_block(['port' => trim((string)($payload['port'] ?? '')), 'shares' => $shares])), LOCK_EX);
    return ['ok' => true, 'msg' => 'AFP 共享已保存', 'status' => host_agent_afp_status($root, $mode)];
}

function host_agent_afp_share_delete(string $root, string $mode, string $name): array {
    $config = host_agent_ensure_afp_config($root, $mode);
    $parsed = host_agent_afp_shares_from_content(host_agent_read_file($config));
    $shares = array_values(array_filter((array)($parsed['shares'] ?? []), static fn(array $item): bool => (string)($item['name'] ?? '') !== trim($name)));
    [$startMarker, $endMarker] = host_agent_afp_block_markers();
    file_put_contents($config, host_agent_managed_block_replace(host_agent_read_file($config), $startMarker, $endMarker, host_agent_render_afp_block(['port' => (string)($parsed['port'] ?? ''), 'shares' => $shares])), LOCK_EX);
    return ['ok' => true, 'msg' => 'AFP 共享已删除', 'status' => host_agent_afp_status($root, $mode)];
}

function host_agent_async_modules_from_content(string $content): array {
    [$startMarker, $endMarker] = host_agent_async_block_markers();
    $block = host_agent_managed_block_extract($content, $startMarker, $endMarker);
    if ($block === '') return ['port' => '', 'modules' => []];
    $port = '';
    $modules = [];
    $current = null;
    foreach (preg_split('/\r?\n/', $block) ?: [] as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '#')) continue;
        if (preg_match('/^port\s*=\s*(\d+)$/i', $trimmed, $m)) {
            $port = (string)$m[1];
            continue;
        }
        if (preg_match('/^\[(.+)\]$/', $trimmed, $m)) {
            if (is_array($current) && !empty($current['name'])) $modules[] = $current;
            $current = ['name' => trim((string)$m[1]), 'path' => '', 'comment' => '', 'read_only' => false, 'auth_users' => []];
            continue;
        }
        if (!is_array($current) || !preg_match('/^([^=]+?)\s*=\s*(.+)$/', $trimmed, $m)) continue;
        $key = strtolower(trim((string)$m[1]));
        $value = trim((string)$m[2]);
        if ($key === 'path') $current['path'] = $value;
        elseif ($key === 'comment') $current['comment'] = $value;
        elseif ($key === 'read only') $current['read_only'] = strtolower($value) === 'yes';
        elseif ($key === 'auth users') $current['auth_users'] = array_values(array_filter(array_map('trim', explode(',', $value)), static fn(string $i): bool => $i !== ''));
    }
    if (is_array($current) && !empty($current['name'])) $modules[] = $current;
    return ['port' => $port, 'modules' => $modules];
}

function host_agent_render_async_block(array $payload): string {
    $lines = [];
    if (trim((string)($payload['port'] ?? '')) !== '') $lines[] = 'port = ' . trim((string)$payload['port']);
    foreach ((array)($payload['modules'] ?? []) as $module) {
        if (!is_array($module) || empty($module['name']) || trim((string)($module['path'] ?? '')) === '') continue;
        $lines[] = '[' . trim((string)$module['name']) . ']';
        $lines[] = '    path = ' . trim((string)$module['path']);
        if (trim((string)($module['comment'] ?? '')) !== '') $lines[] = '    comment = ' . trim((string)$module['comment']);
        $lines[] = '    read only = ' . (!empty($module['read_only']) ? 'yes' : 'no');
        if (!empty($module['auth_users'])) $lines[] = '    auth users = ' . implode(', ', (array)$module['auth_users']);
        $lines[] = '';
    }
    return trim(implode("\n", $lines));
}

function host_agent_async_status(string $root, string $mode): array {
    $service = host_agent_generic_service_status($root, $mode, 'async');
    $path = host_agent_ensure_async_config($root, $mode);
    $parsed = host_agent_async_modules_from_content(host_agent_read_file($path));
    return $service + ['config_path' => $path, 'port' => (string)($parsed['port'] ?? ''), 'modules' => (array)($parsed['modules'] ?? [])];
}

function host_agent_async_module_save(string $root, string $mode, array $payload): array {
    $name = trim((string)($payload['name'] ?? ''));
    $pathValue = trim((string)($payload['path'] ?? ''));
    if ($name === '' || !preg_match('/^[A-Za-z0-9_.-]+$/', $name)) return ['ok' => false, 'msg' => 'Async 模块名无效'];
    if ($pathValue === '' || !str_starts_with($pathValue, '/')) return ['ok' => false, 'msg' => 'Async 目录必须是绝对路径'];
    $config = host_agent_ensure_async_config($root, $mode);
    if ($mode !== 'host') @mkdir(rtrim($root, '/') . $pathValue, 0755, true);
    $parsed = host_agent_async_modules_from_content(host_agent_read_file($config));
    $modules = array_values(array_filter((array)($parsed['modules'] ?? []), static fn(array $item): bool => (string)($item['name'] ?? '') !== $name));
    $modules[] = [
        'name' => $name,
        'path' => $pathValue,
        'comment' => trim((string)($payload['comment'] ?? '')),
        'read_only' => !empty($payload['read_only']),
        'auth_users' => array_values(array_filter(array_map('trim', explode(',', (string)($payload['auth_users'] ?? ''))), static fn(string $i): bool => $i !== '')),
    ];
    [$startMarker, $endMarker] = host_agent_async_block_markers();
    file_put_contents($config, host_agent_managed_block_replace(host_agent_read_file($config), $startMarker, $endMarker, host_agent_render_async_block(['port' => trim((string)($payload['port'] ?? '873')), 'modules' => $modules])), LOCK_EX);
    return ['ok' => true, 'msg' => 'Async / Rsync 模块已保存', 'status' => host_agent_async_status($root, $mode)];
}

function host_agent_async_module_delete(string $root, string $mode, string $name): array {
    $config = host_agent_ensure_async_config($root, $mode);
    $parsed = host_agent_async_modules_from_content(host_agent_read_file($config));
    $modules = array_values(array_filter((array)($parsed['modules'] ?? []), static fn(array $item): bool => (string)($item['name'] ?? '') !== trim($name)));
    [$startMarker, $endMarker] = host_agent_async_block_markers();
    file_put_contents($config, host_agent_managed_block_replace(host_agent_read_file($config), $startMarker, $endMarker, host_agent_render_async_block(['port' => (string)($parsed['port'] ?? ''), 'modules' => $modules])), LOCK_EX);
    return ['ok' => true, 'msg' => 'Async / Rsync 模块已删除', 'status' => host_agent_async_status($root, $mode)];
}

function host_agent_share_service_files(string $root, string $mode, string $service): array {
    $map = [
        'sftp' => [host_agent_ssh_config_path($root, $mode)],
        'smb' => [host_agent_smb_config_path($root, $mode), host_agent_share_service_sim_state_file($root)],
        'ftp' => [host_agent_ftp_config_path($root, $mode), host_agent_ftp_userlist_path($root, $mode), host_agent_share_service_sim_state_file($root)],
        'nfs' => [host_agent_nfs_exports_path($root, $mode), host_agent_nfs_conf_path($root, $mode), host_agent_share_service_sim_state_file($root)],
        'afp' => [host_agent_afp_config_path($root, $mode), host_agent_share_service_sim_state_file($root)],
        'async' => [host_agent_async_config_path($root, $mode), host_agent_share_service_sim_state_file($root)],
    ];
    return array_values(array_unique(array_filter($map[$service] ?? [])));
}

function host_agent_share_snapshot(string $root, string $mode, string $service): array {
    $service = trim($service);
    $files = host_agent_share_service_files($root, $mode, $service);
    if (!$files) {
        return ['ok' => false, 'msg' => '未知共享服务'];
    }
    foreach ($files as $path) {
        host_agent_ensure_parent_dir($path);
        if (!is_file($path)) {
            file_put_contents($path, '', LOCK_EX);
        }
    }
    $items = [];
    foreach ($files as $path) {
        $items[] = [
            'path' => $path,
            'content_base64' => base64_encode(host_agent_read_file($path)),
        ];
    }
    return ['ok' => true, 'service' => $service, 'files' => $items];
}

function host_agent_share_snapshot_restore(string $root, string $mode, string $service, array $files): array {
    $service = trim($service);
    $allowed = host_agent_share_service_files($root, $mode, $service);
    if (!$allowed) {
        return ['ok' => false, 'msg' => '未知共享服务'];
    }
    foreach ($files as $file) {
        if (!is_array($file)) {
            continue;
        }
        $path = (string)($file['path'] ?? '');
        if ($path === '' || !in_array($path, $allowed, true)) {
            return ['ok' => false, 'msg' => '存在不允许恢复的文件'];
        }
        $content = base64_decode((string)($file['content_base64'] ?? ''), true);
        if ($content === false) {
            return ['ok' => false, 'msg' => '快照内容无效'];
        }
        host_agent_ensure_parent_dir($path);
        file_put_contents($path, $content, LOCK_EX);
    }
    return ['ok' => true, 'msg' => '共享服务配置已恢复', 'snapshot' => host_agent_share_snapshot($root, $mode, $service)];
}

function host_agent_nfs_exports_path(string $root, string $mode): string {
    return rtrim($root, '/') . '/etc/exports';
}

function host_agent_nfs_conf_path(string $root, string $mode): string {
    return rtrim($root, '/') . '/etc/nfs.conf';
}

function host_agent_afp_config_path(string $root, string $mode): string {
    return rtrim($root, '/') . '/etc/netatalk/afp.conf';
}

function host_agent_async_config_path(string $root, string $mode): string {
    return rtrim($root, '/') . '/etc/rsyncd.conf';
}

function host_agent_nfs_block_markers(): array {
    return ['# >>> HOST-AGENT NFS EXPORTS BEGIN >>>', '# <<< HOST-AGENT NFS EXPORTS END <<<'];
}

function host_agent_nfs_ports_markers(): array {
    return ['# >>> HOST-AGENT NFS PORTS BEGIN >>>', '# <<< HOST-AGENT NFS PORTS END <<<'];
}

function host_agent_afp_block_markers(): array {
    return ['# >>> HOST-AGENT AFP SHARES BEGIN >>>', '# <<< HOST-AGENT AFP SHARES END <<<'];
}

function host_agent_async_block_markers(): array {
    return ['# >>> HOST-AGENT ASYNC RSYNC BEGIN >>>', '# <<< HOST-AGENT ASYNC RSYNC END <<<'];
}

function host_agent_ensure_nfs_configs(string $root, string $mode): void {
    $exports = host_agent_nfs_exports_path($root, $mode);
    if (!is_file($exports)) {
        host_agent_ensure_parent_dir($exports);
        file_put_contents($exports, "# /etc/exports managed by host-agent\n", LOCK_EX);
    }
    $conf = host_agent_nfs_conf_path($root, $mode);
    if (!is_file($conf)) {
        host_agent_ensure_parent_dir($conf);
        file_put_contents($conf, "[nfsd]\n", LOCK_EX);
    }
}

function host_agent_ensure_afp_config(string $root, string $mode): string {
    $path = host_agent_afp_config_path($root, $mode);
    if (!is_file($path)) {
        host_agent_ensure_parent_dir($path);
        file_put_contents($path, "[Global]\n", LOCK_EX);
    }
    return $path;
}

function host_agent_ensure_async_config(string $root, string $mode): string {
    $path = host_agent_async_config_path($root, $mode);
    if (!is_file($path)) {
        host_agent_ensure_parent_dir($path);
        file_put_contents($path, "uid = root\ngid = root\nuse chroot = no\nmax connections = 8\nlog file = /var/log/rsyncd.log\n", LOCK_EX);
    }
    return $path;
}

function &host_agent_terminal_registry(): array {
    static $registry = [];
    return $registry;
}

function host_agent_terminal_state_file(string $root): string {
    return rtrim($root, '/') . '/var/lib/host-agent/terminal_sessions.json';
}

function host_agent_terminal_write_state(string $root): void {
    $registry =& host_agent_terminal_registry();
    $state = [];
    foreach ($registry as $id => $session) {
        if (!is_array($session)) {
            continue;
        }
        $state[] = [
            'id' => (string)$id,
            'title' => (string)($session['title'] ?? $id),
            'host_id' => (string)($session['host_id'] ?? 'local'),
            'host_label' => (string)($session['host_label'] ?? ''),
            'persist' => !empty($session['persist']),
            'idle_minutes' => max(1, (int)($session['idle_minutes'] ?? 120)),
            'running' => !empty($session['running']),
            'created_at' => (string)($session['created_at'] ?? ''),
            'updated_at' => (string)($session['updated_at_text'] ?? ''),
            'last_attach_at' => (string)($session['last_attach_at_text'] ?? ''),
            'ended_at' => (string)($session['ended_at'] ?? ''),
        ];
    }
    $path = host_agent_terminal_state_file($root);
    host_agent_ensure_parent_dir($path);
    file_put_contents($path, json_encode(['sessions' => $state], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

function host_agent_terminal_cleanup(): void {
    $registry =& host_agent_terminal_registry();
    foreach ($registry as $id => $session) {
        if (!is_array($session) || !isset($session['proc'])) {
            unset($registry[$id]);
            continue;
        }
        $status = proc_get_status($session['proc']);
        $session['running'] = (bool)($status['running'] ?? false);
        if (!$session['running'] && empty($session['ended_at'])) {
            $session['ended_at'] = date('Y-m-d H:i:s');
        }
        $updatedAt = (int)($session['updated_at_unix'] ?? time());
        $idleMinutes = max(1, (int)($session['idle_minutes'] ?? 120));
        $expired = false;
        if (!$session['running'] && (time() - $updatedAt) > ($idleMinutes * 60)) {
            $expired = true;
        }
        if (!empty($session['running']) && empty($session['persist']) && (time() - $updatedAt) > ($idleMinutes * 60)) {
            $expired = true;
        }
        if ($expired) {
            foreach (($session['pipes'] ?? []) as $pipe) {
                if (is_resource($pipe)) {
                    @fclose($pipe);
                }
            }
            @proc_terminate($session['proc']);
            @proc_close($session['proc']);
            foreach (($session['cleanup'] ?? []) as $path) {
                @unlink((string)$path);
            }
            unset($registry[$id]);
        }
    }
    $root = $GLOBALS['HOST_AGENT_ROOT'] ?? '';
    if (is_string($root) && $root !== '') {
        host_agent_terminal_write_state($root);
    }
}

function host_agent_remote_temp_key(array $target): array {
    $privateKey = (string)($target['private_key'] ?? '');
    if ($privateKey === '') {
        return ['ok' => false, 'msg' => '未提供私钥内容'];
    }
    $path = sys_get_temp_dir() . '/host-agent-key-' . bin2hex(random_bytes(5));
    file_put_contents($path, $privateKey, LOCK_EX);
    chmod($path, 0600);
    return ['ok' => true, 'path' => $path];
}

function host_agent_remote_shell_command(array $target, string $script): array {
    $host = trim((string)($target['hostname'] ?? ''));
    $port = max(1, min(65535, (int)($target['port'] ?? 22)));
    $user = trim((string)($target['username'] ?? 'root'));
    $authType = (string)($target['auth_type'] ?? 'key');
    $base = [
        'ssh',
        '-o', 'StrictHostKeyChecking=no',
        '-o', 'UserKnownHostsFile=/dev/null',
        '-o', 'LogLevel=ERROR',
        '-p', (string)$port,
    ];
    $cleanup = [];
    $env = [];

    if ($authType === 'password') {
        $base = array_merge(['sshpass', '-p', (string)($target['password'] ?? '')], $base);
    } else {
        $key = host_agent_remote_temp_key($target);
        if (empty($key['ok'])) {
            return ['ok' => false, 'msg' => (string)($key['msg'] ?? '私钥准备失败')];
        }
        $cleanup[] = (string)$key['path'];
        $base = array_merge($base, ['-i', (string)$key['path']]);
    }
    $base[] = $user . '@' . $host;
    $base[] = $script;
    return ['ok' => true, 'command' => $base, 'cleanup' => $cleanup, 'env' => $env];
}

function host_agent_remote_exec(array $target, string $script, ?string $stdin = null): array {
    $prepared = host_agent_remote_shell_command($target, $script);
    if (empty($prepared['ok'])) {
        return ['ok' => false, 'code' => 1, 'stdout' => '', 'stderr' => (string)($prepared['msg'] ?? '准备远程命令失败')];
    }
    $result = host_agent_proc_run((array)$prepared['command'], $stdin, (array)($prepared['env'] ?? []));
    foreach ((array)($prepared['cleanup'] ?? []) as $path) {
        @unlink((string)$path);
    }
    return $result;
}

function host_agent_local_file_list(string $root, string $path): array {
    $resolved = host_agent_safe_local_path($root, $path);
    if (empty($resolved['ok'])) {
        return $resolved;
    }
    $target = (string)$resolved['path'];
    if (!is_dir($target)) {
        return ['ok' => false, 'msg' => '目录不存在'];
    }
    $items = [];
    foreach (scandir($target) ?: [] as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        $full = $target . '/' . $name;
        $stat = @stat($full);
        $mode = $stat ? sprintf('%04o', $stat['mode'] & 07777) : '';
        $owner = '';
        $group = '';
        if ($stat) {
            if (function_exists('posix_getpwuid')) {
                $pw = posix_getpwuid($stat['uid']);
                $owner = ($pw['name'] ?? '') . ' (' . $stat['uid'] . ')';
            } else {
                $owner = (string)$stat['uid'];
            }
            if (function_exists('posix_getgrgid')) {
                $gr = posix_getgrgid($stat['gid']);
                $group = ($gr['name'] ?? '') . ' (' . $stat['gid'] . ')';
            } else {
                $group = (string)$stat['gid'];
            }
        }
        $items[] = [
            'name' => $name,
            'path' => host_agent_local_display_path($root, $full),
            'type' => is_dir($full) ? 'dir' : 'file',
            'size' => is_file($full) ? filesize($full) : 0,
            'mtime' => date('Y-m-d H:i:s', filemtime($full) ?: time()),
            'mode' => $mode,
            'owner' => $owner,
            'group' => $group,
        ];
    }
    usort($items, static function (array $a, array $b): int {
        if ($a['type'] !== $b['type']) {
            return $a['type'] === 'dir' ? -1 : 1;
        }
        return strcmp((string)$a['name'], (string)$b['name']);
    });
    return ['ok' => true, 'cwd' => host_agent_local_display_path($root, $target), 'items' => $items];
}

function host_agent_local_file_read(string $root, string $path): array {
    $resolved = host_agent_safe_local_path($root, $path);
    if (empty($resolved['ok'])) {
        return $resolved;
    }
    $target = (string)$resolved['path'];
    if (!is_file($target)) {
        return ['ok' => false, 'msg' => '文件不存在'];
    }
    return host_agent_file_payload(host_agent_local_display_path($root, $target), (string)file_get_contents($target));
}

function host_agent_local_file_write(string $root, string $path, string $content): array {
    $resolved = host_agent_safe_local_path($root, $path);
    if (empty($resolved['ok'])) {
        return $resolved;
    }
    $target = (string)$resolved['path'];
    host_agent_ensure_parent_dir($target);
    file_put_contents($target, $content, LOCK_EX);
    return ['ok' => true, 'msg' => '文件已保存', 'path' => host_agent_local_display_path($root, $target)];
}

function host_agent_local_file_delete(string $root, string $path): array {
    $resolved = host_agent_safe_local_path($root, $path);
    if (empty($resolved['ok'])) {
        return $resolved;
    }
    $target = (string)$resolved['path'];
    if (is_dir($target)) {
        @rmdir($target);
    } elseif (is_file($target)) {
        @unlink($target);
    }
    return ['ok' => !file_exists($target), 'msg' => !file_exists($target) ? '已删除' : '删除失败'];
}

function host_agent_local_file_rename(string $root, string $sourcePath, string $targetPath): array {
    $sourceResolved = host_agent_safe_local_path($root, $sourcePath);
    $targetResolved = host_agent_safe_local_path($root, $targetPath);
    if (empty($sourceResolved['ok'])) {
        return $sourceResolved;
    }
    if (empty($targetResolved['ok'])) {
        return $targetResolved;
    }
    $source = (string)$sourceResolved['path'];
    $target = (string)$targetResolved['path'];
    if (!file_exists($source)) {
        return ['ok' => false, 'msg' => '源文件或目录不存在'];
    }
    if (file_exists($target)) {
        return ['ok' => false, 'msg' => '目标路径已存在'];
    }
    host_agent_ensure_parent_dir($target);
    $ok = @rename($source, $target);
    return ['ok' => $ok, 'msg' => $ok ? '重命名成功' : '重命名失败', 'path' => host_agent_local_display_path($root, $target)];
}

function host_agent_local_file_copy(string $root, string $sourcePath, string $targetPath): array {
    $sourceResolved = host_agent_safe_local_path($root, $sourcePath);
    $targetResolved = host_agent_safe_local_path($root, $targetPath);
    if (empty($sourceResolved['ok'])) {
        return $sourceResolved;
    }
    if (empty($targetResolved['ok'])) {
        return $targetResolved;
    }
    $source = (string)$sourceResolved['path'];
    $target = (string)$targetResolved['path'];
    if (!file_exists($source)) {
        return ['ok' => false, 'msg' => '源文件或目录不存在'];
    }
    if (file_exists($target)) {
        return ['ok' => false, 'msg' => '目标路径已存在'];
    }
    host_agent_ensure_parent_dir($target);
    $cmd = 'cp -a ' . escapeshellarg($source) . ' ' . escapeshellarg($target);
    $result = host_agent_proc_run(['sh', '-lc', $cmd]);
    return ['ok' => $result['ok'], 'msg' => $result['ok'] ? '复制成功' : trim($result['stderr'] ?: $result['stdout'] ?: '复制失败'), 'path' => host_agent_local_display_path($root, $target)];
}

function host_agent_local_file_move(string $root, string $sourcePath, string $targetPath): array {
    return host_agent_local_file_rename($root, $sourcePath, $targetPath);
}

function host_agent_local_mkdir(string $root, string $path): array {
    $resolved = host_agent_safe_local_path($root, $path);
    if (empty($resolved['ok'])) {
        return $resolved;
    }
    $target = (string)$resolved['path'];
    if (!is_dir($target)) {
        mkdir($target, 0755, true);
    }
    return ['ok' => true, 'msg' => '目录已创建', 'path' => host_agent_local_display_path($root, $target)];
}

// ============================================================
// Archive Extract / Compress (文件解压/压缩)
// ============================================================

function host_agent_archive_detect_format(string $path): ?string {
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $name = strtolower(basename($path));
    if (str_ends_with($name, '.tar.gz') || str_ends_with($name, '.tgz')) return 'tar.gz';
    if (str_ends_with($name, '.tar.bz2') || str_ends_with($name, '.tbz2')) return 'tar.bz2';
    if (str_ends_with($name, '.tar.xz') || str_ends_with($name, '.txz')) return 'tar.xz';
    if (str_ends_with($name, '.tar.lz') || str_ends_with($name, '.tlz')) return 'tar.lz';
    if ($ext === 'tar') return 'tar';
    if ($ext === 'zip') return 'zip';
    if ($ext === '7z') return '7z';
    if ($ext === 'rar') return 'rar';
    if (str_ends_with($name, '.tar.zst')) return 'tar.zst';
    return null;
}

function host_agent_archive_extract_cmd(string $format, string $path, string $destDir): ?string {
    $path = escapeshellarg($path);
    $destDir = escapeshellarg($destDir);
    $cmds = [
        'tar.gz'  => 'tar -xzf ' . $path . ' -C ' . $destDir,
        'tar.bz2' => 'tar -xjf ' . $path . ' -C ' . $destDir,
        'tar.xz'  => 'tar -xJf ' . $path . ' -C ' . $destDir,
        'tar.lz'  => 'tar --lzip -xf ' . $path . ' -C ' . $destDir,
        'tar.zst' => 'tar --zstd -xf ' . $path . ' -C ' . $destDir,
        'tar'     => 'tar -xf ' . $path . ' -C ' . $destDir,
        'zip'     => 'unzip -o ' . $path . ' -d ' . $destDir,
        '7z'      => '7z x ' . $path . ' -o' . $destDir . ' -y',
        'rar'     => 'unrar x -o+ ' . $path . ' ' . $destDir,
    ];
    return $cmds[$format] ?? null;
}

function host_agent_archive_compress_cmd(string $format, array $paths, string $destPath): ?string {
    $destPath = escapeshellarg($destPath);
    if ($format === 'tar.gz') {
        return 'tar -czf ' . $destPath . ' -C ' . escapeshellarg(dirname($paths[0])) . ' ' . implode(' ', array_map('basename', $paths));
    }
    if ($format === 'tar.bz2') {
        return 'tar -cjf ' . $destPath . ' -C ' . escapeshellarg(dirname($paths[0])) . ' ' . implode(' ', array_map('basename', $paths));
    }
    if ($format === 'tar.xz') {
        return 'tar -cJf ' . $destPath . ' -C ' . escapeshellarg(dirname($paths[0])) . ' ' . implode(' ', array_map('basename', $paths));
    }
    if ($format === 'zip') {
        $items = implode(' ', array_map('escapeshellarg', $paths));
        return 'zip -r ' . $destPath . ' ' . $items;
    }
    if ($format === '7z') {
        $items = implode(' ', array_map('escapeshellarg', $paths));
        return '7z a ' . $destPath . ' ' . $items;
    }
    if ($format === 'tar') {
        return 'tar -cf ' . $destPath . ' -C ' . escapeshellarg(dirname($paths[0])) . ' ' . implode(' ', array_map('basename', $paths));
    }
    return null;
}

function host_agent_archive_list_cmd(string $format, string $path): ?string {
    $path = escapeshellarg($path);
    $cmds = [
        'tar.gz'  => 'tar -tzf ' . $path,
        'tar.bz2' => 'tar -tjf ' . $path,
        'tar.xz'  => 'tar -tJf ' . $path,
        'tar.lz'  => 'tar --lzip -tf ' . $path,
        'tar.zst' => 'tar --zstd -tf ' . $path,
        'tar'     => 'tar -tf ' . $path,
        'zip'     => 'unzip -l ' . $path,
        '7z'      => '7z l ' . $path,
        'rar'     => 'unrar l ' . $path,
    ];
    return $cmds[$format] ?? null;
}

function host_agent_archive_extract(string $root, string $path, string $destDir): array {
    $resolved = host_agent_safe_local_path($root, $path);
    if (empty($resolved['ok'])) return $resolved;
    $sourcePath = (string)$resolved['path'];
    if (!is_file($sourcePath)) return ['ok' => false, 'msg' => '文件不存在'];

    $format = host_agent_archive_detect_format($sourcePath);
    if ($format === null) return ['ok' => false, 'msg' => '不支持的压缩格式'];

    $destResolved = host_agent_safe_local_path($root, $destDir);
    if (empty($destResolved['ok'])) return $destResolved;
    $destPath = (string)$destResolved['path'];

    if (!is_dir($destPath)) {
        mkdir($destPath, 0755, true);
    }

    $cmd = host_agent_archive_extract_cmd($format, $sourcePath, $destPath);
    if ($cmd === null) return ['ok' => false, 'msg' => '无法构建解压命令'];

    $result = host_agent_host_shell($cmd . ' 2>&1');
    $ok = $result['ok'] && $result['code'] === 0;
    return [
        'ok' => $ok,
        'msg' => $ok ? '解压完成' : ('解压失败：' . trim($result['stderr'] ?: $result['stdout'])),
        'format' => $format,
        'dest' => host_agent_local_display_path($root, $destPath),
    ];
}

function host_agent_archive_compress(string $root, array $paths, string $destPath, string $format): array {
    $resolvedPaths = [];
    foreach ($paths as $p) {
        $r = host_agent_safe_local_path($root, $p);
        if (empty($r['ok'])) return $r;
        $resolvedPaths[] = (string)$r['path'];
    }
    $destResolved = host_agent_safe_local_path($root, $destPath);
    if (empty($destResolved['ok'])) return $destResolved;
    $dest = (string)$destResolved['path'];

    host_agent_ensure_parent_dir($dest);

    $validFormats = ['tar.gz', 'tar.bz2', 'tar.xz', 'tar', 'zip', '7z'];
    if (!in_array($format, $validFormats, true)) {
        return ['ok' => false, 'msg' => '不支持的压缩格式，支持: ' . implode(', ', $validFormats)];
    }

    $cmd = host_agent_archive_compress_cmd($format, $resolvedPaths, $dest);
    if ($cmd === null) return ['ok' => false, 'msg' => '无法构建压缩命令'];

    $result = host_agent_host_shell($cmd . ' 2>&1');
    $ok = $result['ok'] && $result['code'] === 0;
    return [
        'ok' => $ok,
        'msg' => $ok ? '压缩完成' : ('压缩失败：' . trim($result['stderr'] ?: $result['stdout'])),
        'format' => $format,
        'dest' => host_agent_local_display_path($root, $dest),
    ];
}

function host_agent_archive_list(string $root, string $path): array {
    $resolved = host_agent_safe_local_path($root, $path);
    if (empty($resolved['ok'])) return $resolved;
    $sourcePath = (string)$resolved['path'];
    if (!is_file($sourcePath)) return ['ok' => false, 'msg' => '文件不存在'];

    $format = host_agent_archive_detect_format($sourcePath);
    if ($format === null) return ['ok' => false, 'msg' => '不支持的压缩格式'];

    $cmd = host_agent_archive_list_cmd($format, $sourcePath);
    if ($cmd === null) return ['ok' => false, 'msg' => '无法构建列表命令'];

    $result = host_agent_host_shell($cmd . ' 2>&1');
    if (!$result['ok'] || $result['code'] !== 0) {
        return ['ok' => false, 'msg' => '读取压缩包内容失败', 'output' => trim($result['stderr'] ?: $result['stdout'])];
    }

    $lines = preg_split('/\r?\n/', trim($result['stdout'])) ?: [];
    $entries = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line === '.' || $line === '..') continue;
        $entries[] = $line;
    }
    return ['ok' => true, 'format' => $format, 'entries' => $entries, 'total' => count($entries)];
}

function host_agent_archive_detect_tools(): array {
    $tools = [];
    $checks = [
        'tar'   => 'command -v tar',
        'zip'   => 'command -v unzip',
        '7z'    => 'command -v 7z',
        'unrar' => 'command -v unrar',
        'rar'   => 'command -v rar',
    ];
    foreach ($checks as $name => $cmd) {
        $result = host_agent_host_shell($cmd . ' >/dev/null 2>&1 && echo yes || echo no');
        if (trim($result['stdout'] ?? '') === 'yes') {
            $tools[] = $name;
        }
    }
    return $tools;
}

function host_agent_local_file_stat(string $root, string $path, string $mode = 'host'): array {
    $resolved = host_agent_safe_local_path($root, $path);
    if (empty($resolved['ok'])) {
        return $resolved;
    }
    $target = (string)$resolved['path'];
    if (!file_exists($target)) {
        return ['ok' => false, 'msg' => '文件不存在'];
    }
    $permMode = substr(sprintf('%o', fileperms($target) ?: 0), -4);
    $owner = function_exists('posix_getpwuid') ? ((posix_getpwuid(fileowner($target))['name'] ?? (string)fileowner($target))) : (string)fileowner($target);
    $group = function_exists('posix_getgrgid') ? ((posix_getgrgid(filegroup($target))['name'] ?? (string)filegroup($target))) : (string)filegroup($target);
    if ($mode !== 'host') {
        $meta = host_agent_sim_fs_meta_load($root);
        $displayPath = host_agent_local_display_path($root, $target);
        $entry = is_array($meta['paths'][$displayPath] ?? null) ? $meta['paths'][$displayPath] : [];
        if (isset($entry['mode']) && trim((string)$entry['mode']) !== '') {
            $permMode = trim((string)$entry['mode']);
        }
        if (isset($entry['owner']) && trim((string)$entry['owner']) !== '') {
            $owner = trim((string)$entry['owner']);
        }
        if (isset($entry['group']) && trim((string)$entry['group']) !== '') {
            $group = trim((string)$entry['group']);
        }
    }
    return [
        'ok' => true,
        'path' => host_agent_local_display_path($root, $target),
        'mode' => $permMode,
        'owner' => $owner,
        'group' => $group,
        'is_dir' => is_dir($target),
        'size' => is_file($target) ? filesize($target) : (int)trim((string)(shell_exec('du -sb ' . escapeshellarg($target) . ' 2>/dev/null | awk \'{print $1}\'') ?: '0')),
    ];
}

function host_agent_local_file_search(string $root, string $path, string $keyword, int $limit = 200): array {
    $resolved = host_agent_safe_local_path($root, $path);
    if (empty($resolved['ok'])) {
        return $resolved;
    }
    $base = (string)$resolved['path'];
    if (!is_dir($base)) {
        return ['ok' => false, 'msg' => '目录不存在'];
    }
    $needle = trim($keyword);
    if ($needle === '') {
        return ['ok' => false, 'msg' => '搜索关键字不能为空'];
    }
    $items = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $file) {
        $name = (string)$file->getFilename();
        if (stripos($name, $needle) === false) {
            continue;
        }
        $fullPath = (string)$file->getPathname();
        $items[] = [
            'name' => $name,
            'path' => host_agent_local_display_path($root, $fullPath),
            'type' => $file->isDir() ? 'dir' : 'file',
            'size' => $file->isFile() ? (int)$file->getSize() : 0,
            'mtime' => date('Y-m-d H:i:s', (int)$file->getMTime()),
        ];
        if (count($items) >= max(1, min(1000, $limit))) {
            break;
        }
    }
    return ['ok' => true, 'cwd' => host_agent_local_display_path($root, $base), 'items' => $items];
}

function host_agent_local_file_chmod(string $root, string $path, string $mode, string $installMode = 'host'): array {
    $resolved = host_agent_safe_local_path($root, $path);
    if (empty($resolved['ok'])) {
        return $resolved;
    }
    $target = (string)$resolved['path'];
    if (!file_exists($target)) {
        return ['ok' => false, 'msg' => '文件不存在'];
    }
    $numeric = octdec(preg_replace('/[^0-7]/', '', $mode));
    if ($numeric <= 0) {
        return ['ok' => false, 'msg' => '权限模式无效'];
    }
    if ($installMode !== 'host') {
        $meta = host_agent_sim_fs_meta_load($root);
        $displayPath = host_agent_local_display_path($root, $target);
        $entry = is_array($meta['paths'][$displayPath] ?? null) ? $meta['paths'][$displayPath] : [];
        $entry['mode'] = substr(sprintf('%04o', $numeric), -4);
        $meta['paths'][$displayPath] = $entry;
        host_agent_sim_fs_meta_save($root, $meta);
        @chmod($target, $numeric);
        return ['ok' => true, 'msg' => 'simulate 权限已更新'];
    }
    $ok = @chmod($target, $numeric);
    return ['ok' => $ok, 'msg' => $ok ? '权限已更新' : '权限更新失败'];
}

function host_agent_local_file_chown(string $root, string $path, string $owner, string $installMode = 'host'): array {
    $resolved = host_agent_safe_local_path($root, $path);
    if (empty($resolved['ok'])) {
        return $resolved;
    }
    $target = (string)$resolved['path'];
    if (!file_exists($target)) {
        return ['ok' => false, 'msg' => '文件不存在'];
    }
    if ($installMode !== 'host') {
        $meta = host_agent_sim_fs_meta_load($root);
        $displayPath = host_agent_local_display_path($root, $target);
        $entry = is_array($meta['paths'][$displayPath] ?? null) ? $meta['paths'][$displayPath] : [];
        $entry['owner'] = trim($owner);
        $meta['paths'][$displayPath] = $entry;
        host_agent_sim_fs_meta_save($root, $meta);
        return ['ok' => true, 'msg' => 'simulate 属主已更新'];
    }
    $ok = @chown($target, $owner);
    return ['ok' => $ok, 'msg' => $ok ? '属主已更新' : '属主更新失败'];
}

function host_agent_local_file_chgrp(string $root, string $path, string $group, string $installMode = 'host'): array {
    $resolved = host_agent_safe_local_path($root, $path);
    if (empty($resolved['ok'])) {
        return $resolved;
    }
    $target = (string)$resolved['path'];
    if (!file_exists($target)) {
        return ['ok' => false, 'msg' => '文件不存在'];
    }
    if ($installMode !== 'host') {
        $meta = host_agent_sim_fs_meta_load($root);
        $displayPath = host_agent_local_display_path($root, $target);
        $entry = is_array($meta['paths'][$displayPath] ?? null) ? $meta['paths'][$displayPath] : [];
        $entry['group'] = trim($group);
        $meta['paths'][$displayPath] = $entry;
        host_agent_sim_fs_meta_save($root, $meta);
        return ['ok' => true, 'msg' => 'simulate 属组已更新'];
    }
    $ok = @chgrp($target, $group);
    return ['ok' => $ok, 'msg' => $ok ? '属组已更新' : '属组更新失败'];
}

function host_agent_local_acl_targets(string $root, string $path, bool $recursive): array {
    $resolved = host_agent_safe_local_path($root, $path);
    if (empty($resolved['ok'])) {
        return $resolved;
    }
    $target = (string)$resolved['path'];
    if (!file_exists($target)) {
        return ['ok' => false, 'msg' => '文件不存在'];
    }
    $targets = [$target];
    if ($recursive && is_dir($target)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($target, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            $targets[] = (string)$item->getPathname();
        }
    }
    return ['ok' => true, 'paths' => $targets];
}

function host_agent_local_file_acl_apply(string $root, string $installMode, string $path, string $owner, string $group, string $modeValue, bool $recursive): array {
    $resolvedTargets = host_agent_local_acl_targets($root, $path, $recursive);
    if (empty($resolvedTargets['ok'])) {
        return $resolvedTargets;
    }
    $targets = array_values(array_filter((array)($resolvedTargets['paths'] ?? []), static fn($item): bool => is_string($item) && $item !== ''));
    $owner = trim($owner);
    $group = trim($group);
    $modeValue = trim($modeValue);
    if ($owner === '' && $group === '' && $modeValue === '') {
        return ['ok' => false, 'msg' => '至少提供属主、属组或权限模式之一'];
    }
    if ($installMode !== 'host') {
        $meta = host_agent_sim_fs_meta_load($root);
        foreach ($targets as $target) {
            $displayPath = host_agent_local_display_path($root, $target);
            $entry = is_array($meta['paths'][$displayPath] ?? null) ? $meta['paths'][$displayPath] : [];
            if ($owner !== '') {
                $entry['owner'] = $owner;
            }
            if ($group !== '') {
                $entry['group'] = $group;
            }
            if ($modeValue !== '') {
                $numeric = octdec(preg_replace('/[^0-7]/', '', $modeValue));
                if ($numeric <= 0) {
                    return ['ok' => false, 'msg' => '权限模式无效'];
                }
                $entry['mode'] = substr(sprintf('%04o', $numeric), -4);
                @chmod($target, $numeric);
            }
            $meta['paths'][$displayPath] = $entry;
        }
        host_agent_sim_fs_meta_save($root, $meta);
        return ['ok' => true, 'msg' => 'simulate 共享目录权限已更新', 'stat' => host_agent_local_file_stat($root, $path, $installMode), 'affected' => count($targets)];
    }
    foreach ($targets as $target) {
        if ($owner !== '' && !@chown($target, $owner)) {
            return ['ok' => false, 'msg' => '属主更新失败: ' . host_agent_local_display_path($root, $target)];
        }
        if ($group !== '' && !@chgrp($target, $group)) {
            return ['ok' => false, 'msg' => '属组更新失败: ' . host_agent_local_display_path($root, $target)];
        }
        if ($modeValue !== '') {
            $numeric = octdec(preg_replace('/[^0-7]/', '', $modeValue));
            if ($numeric <= 0) {
                return ['ok' => false, 'msg' => '权限模式无效'];
            }
            if (!@chmod($target, $numeric)) {
                return ['ok' => false, 'msg' => '权限更新失败: ' . host_agent_local_display_path($root, $target)];
            }
        }
    }
    return ['ok' => true, 'msg' => '共享目录权限已更新', 'stat' => host_agent_local_file_stat($root, $path, $installMode), 'affected' => count($targets)];
}

function host_agent_local_archive(string $root, string $path, string $archivePath): array {
    $sourceResolved = host_agent_safe_local_path($root, $path);
    $archiveResolved = host_agent_safe_local_path($root, $archivePath);
    if (empty($sourceResolved['ok'])) {
        return $sourceResolved;
    }
    if (empty($archiveResolved['ok'])) {
        return $archiveResolved;
    }
    $source = (string)$sourceResolved['path'];
    $archive = (string)$archiveResolved['path'];
    if (!file_exists($source)) {
        return ['ok' => false, 'msg' => '源文件或目录不存在'];
    }
    $format = host_agent_archive_detect_format($archive);
    if ($format === null) {
        $format = 'tar.gz'; // 默认格式
    }
    $validFormats = ['tar.gz', 'tar.bz2', 'tar.xz', 'tar', 'zip', '7z'];
    if (!in_array($format, $validFormats, true)) {
        return ['ok' => false, 'msg' => '不支持的压缩格式，支持: ' . implode(', ', $validFormats)];
    }
    host_agent_ensure_parent_dir($archive);
    $cmd = host_agent_archive_compress_cmd($format, [$source], $archive);
    if ($cmd === null) {
        return ['ok' => false, 'msg' => '无法构建压缩命令'];
    }
    $result = host_agent_proc_run(['sh', '-lc', $cmd]);
    return ['ok' => $result['ok'], 'msg' => $result['ok'] ? '压缩包已创建' : trim($result['stderr'] ?: $result['stdout'] ?: '压缩失败'), 'path' => host_agent_local_display_path($root, $archive), 'format' => $format];
}

function host_agent_local_extract(string $root, string $path, string $destination): array {
    $pathResolved = host_agent_safe_local_path($root, $path);
    $destResolved = host_agent_safe_local_path($root, $destination);
    if (empty($pathResolved['ok'])) {
        return $pathResolved;
    }
    if (empty($destResolved['ok'])) {
        return $destResolved;
    }
    $archive = (string)$pathResolved['path'];
    $dest = (string)$destResolved['path'];
    if (!is_file($archive)) {
        return ['ok' => false, 'msg' => '压缩文件不存在'];
    }
    if (!is_dir($dest)) {
        mkdir($dest, 0755, true);
    }
    $format = host_agent_archive_detect_format($archive);
    if ($format === null) {
        return ['ok' => false, 'msg' => '不支持的压缩格式'];
    }
    $cmd = host_agent_archive_extract_cmd($format, $archive, $dest);
    if ($cmd === null) {
        return ['ok' => false, 'msg' => '无法构建解压命令'];
    }
    $result = host_agent_proc_run(['sh', '-lc', $cmd]);
    return ['ok' => $result['ok'], 'msg' => $result['ok'] ? '压缩包已解压' : trim($result['stderr'] ?: $result['stdout'] ?: '解压失败'), 'path' => host_agent_local_display_path($root, $dest), 'format' => $format];
}

function host_agent_remote_file_list(array $target, string $path): array {
    $script = 'set -e; cd ' . escapeshellarg($path) . ' 2>/dev/null || exit 12; for item in * .*; do [ "$item" = "." ] && continue; [ "$item" = ".." ] && continue; if [ -d "$item" ]; then t=dir; else t=file; fi; size=$(wc -c < "$item" 2>/dev/null || echo 0); mtime=$(date -r "$item" "+%Y-%m-%d %H:%M:%S" 2>/dev/null || echo ""); mode=$(stat -c "%a" "$item" 2>/dev/null || stat -f "%Lp" "$item" 2>/dev/null || echo ""); owner=$(stat -c "%U (%u)" "$item" 2>/dev/null || stat -f "%Su (%u)" "$item" 2>/dev/null || echo ""); group=$(stat -c "%G (%g)" "$item" 2>/dev/null || stat -f "%Sg (%g)" "$item" 2>/dev/null || echo ""); printf "%s\t%s\t%s\t%s\t%s\t%s\t%s\n" "$item" "$t" "$size" "$mtime" "$mode" "$owner" "$group"; done';
    $result = host_agent_remote_exec($target, 'sh -lc ' . escapeshellarg($script));
    if (!$result['ok'] && $result['code'] === 12) {
        return ['ok' => false, 'msg' => '远程目录不存在'];
    }
    if (!$result['ok']) {
        return ['ok' => false, 'msg' => trim($result['stderr'] ?: $result['stdout'] ?: '远程目录读取失败')];
    }
    $items = [];
    foreach (preg_split('/\r?\n/', trim($result['stdout'])) ?: [] as $line) {
        if ($line === '') {
            continue;
        }
        [$name, $type, $size, $mtime, $mode, $owner, $group] = array_pad(explode("\t", $line), 7, '');
        $items[] = [
            'name' => $name,
            'path' => rtrim($path, '/') . '/' . $name,
            'type' => $type,
            'size' => (int)$size,
            'mtime' => $mtime,
            'mode' => $mode,
            'owner' => $owner,
            'group' => $group,
        ];
    }
    return ['ok' => true, 'cwd' => $path, 'items' => $items];
}

function host_agent_remote_file_read(array $target, string $path): array {
    $result = host_agent_remote_exec($target, 'sh -lc ' . escapeshellarg('base64 < ' . escapeshellarg($path)));
    if (!$result['ok']) {
        return ['ok' => false, 'msg' => trim($result['stderr'] ?: $result['stdout'] ?: '远程文件读取失败')];
    }
    $decoded = base64_decode(trim($result['stdout']), true);
    if ($decoded === false) {
        return ['ok' => false, 'msg' => '远程文件内容解码失败'];
    }
    return host_agent_file_payload($path, $decoded);
}

function host_agent_remote_file_write(array $target, string $path, string $content): array {
    $script = 'dir=$(dirname ' . escapeshellarg($path) . '); mkdir -p "$dir"; { base64 -d 2>/dev/null || base64 -D; } > ' . escapeshellarg($path);
    $result = host_agent_remote_exec($target, 'sh -lc ' . escapeshellarg($script), base64_encode($content));
    if (!$result['ok']) {
        return ['ok' => false, 'msg' => trim($result['stderr'] ?: $result['stdout'] ?: '远程文件保存失败')];
    }
    return ['ok' => true, 'msg' => '远程文件已保存', 'path' => $path];
}

function host_agent_remote_file_delete(array $target, string $path): array {
    $result = host_agent_remote_exec($target, 'sh -lc ' . escapeshellarg('rm -rf ' . escapeshellarg($path)));
    return ['ok' => $result['ok'], 'msg' => $result['ok'] ? '远程文件已删除' : trim($result['stderr'] ?: $result['stdout'] ?: '远程删除失败')];
}

function host_agent_remote_mkdir(array $target, string $path): array {
    $result = host_agent_remote_exec($target, 'sh -lc ' . escapeshellarg('mkdir -p ' . escapeshellarg($path)));
    return ['ok' => $result['ok'], 'msg' => $result['ok'] ? '远程目录已创建' : trim($result['stderr'] ?: $result['stdout'] ?: '远程目录创建失败')];
}

function host_agent_remote_file_rename(array $target, string $sourcePath, string $targetPath): array {
    $script = 'if [ ! -e ' . escapeshellarg($sourcePath) . ' ]; then exit 14; fi; '
        . 'if [ -e ' . escapeshellarg($targetPath) . ' ]; then exit 15; fi; '
        . 'mkdir -p ' . escapeshellarg(dirname($targetPath)) . ' && mv ' . escapeshellarg($sourcePath) . ' ' . escapeshellarg($targetPath);
    $result = host_agent_remote_exec($target, 'sh -lc ' . escapeshellarg($script));
    if (!$result['ok']) {
        if (($result['code'] ?? 0) === 14) {
            return ['ok' => false, 'msg' => '源文件或目录不存在'];
        }
        if (($result['code'] ?? 0) === 15) {
            return ['ok' => false, 'msg' => '目标路径已存在'];
        }
    }
    return ['ok' => $result['ok'], 'msg' => $result['ok'] ? '重命名成功' : trim($result['stderr'] ?: $result['stdout'] ?: '重命名失败'), 'path' => $targetPath];
}

function host_agent_remote_file_copy(array $target, string $sourcePath, string $targetPath): array {
    $script = 'if [ ! -e ' . escapeshellarg($sourcePath) . ' ]; then exit 14; fi; '
        . 'if [ -e ' . escapeshellarg($targetPath) . ' ]; then exit 15; fi; '
        . 'mkdir -p ' . escapeshellarg(dirname($targetPath)) . ' && cp -a ' . escapeshellarg($sourcePath) . ' ' . escapeshellarg($targetPath);
    $result = host_agent_remote_exec($target, 'sh -lc ' . escapeshellarg($script));
    if (!$result['ok']) {
        if (($result['code'] ?? 0) === 14) {
            return ['ok' => false, 'msg' => '源文件或目录不存在'];
        }
        if (($result['code'] ?? 0) === 15) {
            return ['ok' => false, 'msg' => '目标路径已存在'];
        }
    }
    return ['ok' => $result['ok'], 'msg' => $result['ok'] ? '复制成功' : trim($result['stderr'] ?: $result['stdout'] ?: '复制失败'), 'path' => $targetPath];
}

function host_agent_remote_file_move(array $target, string $sourcePath, string $targetPath): array {
    return host_agent_remote_file_rename($target, $sourcePath, $targetPath);
}

function host_agent_remote_file_stat(array $target, string $path): array {
    $script = 'if [ ! -e ' . escapeshellarg($path) . ' ]; then exit 14; fi; '
        . 'mode=$(stat -c %a ' . escapeshellarg($path) . ' 2>/dev/null || stat -f %Lp ' . escapeshellarg($path) . ' 2>/dev/null || echo ""); '
        . 'owner=$(stat -c %U ' . escapeshellarg($path) . ' 2>/dev/null || stat -f %Su ' . escapeshellarg($path) . ' 2>/dev/null || echo ""); '
        . 'group=$(stat -c %G ' . escapeshellarg($path) . ' 2>/dev/null || stat -f %Sg ' . escapeshellarg($path) . ' 2>/dev/null || echo ""); '
        . 'size=$(wc -c < ' . escapeshellarg($path) . ' 2>/dev/null || echo 0); '
        . 'if [ -d ' . escapeshellarg($path) . ' ]; then is_dir=yes; else is_dir=no; fi; '
        . 'printf "%s\t%s\t%s\t%s\t%s\n" "$mode" "$owner" "$group" "$size" "$is_dir"';
    $result = host_agent_remote_exec($target, 'sh -lc ' . escapeshellarg($script));
    if (!$result['ok']) {
        if (($result['code'] ?? 0) === 14) {
            return ['ok' => false, 'msg' => '文件不存在'];
        }
        return ['ok' => false, 'msg' => trim($result['stderr'] ?: $result['stdout'] ?: '远程文件属性读取失败')];
    }
    [$mode, $owner, $group, $size, $isDir] = array_pad(explode("\t", trim($result['stdout'])), 5, '');
    return ['ok' => true, 'path' => $path, 'mode' => $mode, 'owner' => $owner, 'group' => $group, 'size' => (int)$size, 'is_dir' => $isDir === 'yes'];
}

function host_agent_remote_file_search(array $target, string $path, string $keyword, int $limit = 200): array {
    $limit = max(1, min(1000, $limit));
    $script = 'cd ' . escapeshellarg($path) . ' 2>/dev/null || exit 12; '
        . 'find . -iname ' . escapeshellarg('*' . $keyword . '*') . ' | sed "s#^\\./##" | head -n ' . (int)$limit . ' | while read -r item; do '
        . '[ -z "$item" ] && continue; '
        . 'full=' . escapeshellarg(rtrim($path, '/')) . '/"$item"; '
        . 'if [ -d "$item" ]; then t=dir; else t=file; fi; '
        . 'size=$(wc -c < "$item" 2>/dev/null || echo 0); '
        . 'mtime=$(date -r "$item" "+%Y-%m-%d %H:%M:%S" 2>/dev/null || echo ""); '
        . 'name=$(basename "$item"); '
        . 'printf "%s\t%s\t%s\t%s\t%s\n" "$name" "$full" "$t" "$size" "$mtime"; '
        . 'done';
    $result = host_agent_remote_exec($target, 'sh -lc ' . escapeshellarg($script));
    if (!$result['ok']) {
        if (($result['code'] ?? 0) === 12) {
            return ['ok' => false, 'msg' => '远程目录不存在'];
        }
        return ['ok' => false, 'msg' => trim($result['stderr'] ?: $result['stdout'] ?: '远程搜索失败')];
    }
    $items = [];
    foreach (preg_split('/\r?\n/', trim($result['stdout'])) ?: [] as $line) {
        if ($line === '') {
            continue;
        }
        [$name, $fullPath, $type, $size, $mtime] = array_pad(explode("\t", $line), 5, '');
        $items[] = [
            'name' => $name,
            'path' => $fullPath,
            'type' => $type,
            'size' => (int)$size,
            'mtime' => $mtime,
        ];
    }
    return ['ok' => true, 'cwd' => $path, 'items' => $items];
}

function host_agent_remote_file_chmod(array $target, string $path, string $mode): array {
    $result = host_agent_remote_exec($target, 'sh -lc ' . escapeshellarg('chmod ' . escapeshellarg($mode) . ' ' . escapeshellarg($path)));
    return ['ok' => $result['ok'], 'msg' => $result['ok'] ? '权限已更新' : trim($result['stderr'] ?: $result['stdout'] ?: '权限更新失败')];
}

function host_agent_remote_file_chown(array $target, string $path, string $owner): array {
    $result = host_agent_remote_exec($target, 'sh -lc ' . escapeshellarg('chown ' . escapeshellarg($owner) . ' ' . escapeshellarg($path)));
    return ['ok' => $result['ok'], 'msg' => $result['ok'] ? '属主已更新' : trim($result['stderr'] ?: $result['stdout'] ?: '属主更新失败')];
}

function host_agent_remote_file_chgrp(array $target, string $path, string $group): array {
    $result = host_agent_remote_exec($target, 'sh -lc ' . escapeshellarg('chgrp ' . escapeshellarg($group) . ' ' . escapeshellarg($path)));
    return ['ok' => $result['ok'], 'msg' => $result['ok'] ? '属组已更新' : trim($result['stderr'] ?: $result['stdout'] ?: '属组更新失败')];
}

function host_agent_remote_file_acl_apply(array $target, string $path, string $owner, string $group, string $modeValue, bool $recursive): array {
    $commands = [];
    $flag = $recursive ? '-R ' : '';
    if ($owner !== '') {
        $commands[] = 'chown ' . $flag . escapeshellarg($owner) . ' ' . escapeshellarg($path);
    }
    if ($group !== '') {
        $commands[] = 'chgrp ' . $flag . escapeshellarg($group) . ' ' . escapeshellarg($path);
    }
    if ($modeValue !== '') {
        $commands[] = 'chmod ' . $flag . escapeshellarg($modeValue) . ' ' . escapeshellarg($path);
    }
    if ($commands === []) {
        return ['ok' => false, 'msg' => '至少提供属主、属组或权限模式之一'];
    }
    $script = implode(' && ', $commands) . ' && stat -c "%a\t%U\t%G\t%n" ' . escapeshellarg($path);
    $result = host_agent_remote_exec($target, 'sh -lc ' . escapeshellarg($script));
    if (!$result['ok']) {
        return ['ok' => false, 'msg' => trim($result['stderr'] ?: $result['stdout'] ?: '共享目录权限更新失败')];
    }
    [$modeOut, $ownerOut, $groupOut, $pathOut] = array_pad(explode("\t", trim($result['stdout'])), 4, '');
    return [
        'ok' => true,
        'msg' => '共享目录权限已更新',
        'affected' => 1,
        'stat' => [
            'ok' => true,
            'path' => $pathOut !== '' ? $pathOut : $path,
            'mode' => $modeOut !== '' ? $modeOut : '',
            'owner' => $ownerOut,
            'group' => $groupOut,
        ],
    ];
}

function host_agent_remote_archive(array $target, string $path, string $archivePath): array {
    $script = 'tar -czf ' . escapeshellarg($archivePath) . ' -C ' . escapeshellarg(dirname($path)) . ' ' . escapeshellarg(basename($path));
    $result = host_agent_remote_exec($target, 'sh -lc ' . escapeshellarg($script));
    return ['ok' => $result['ok'], 'msg' => $result['ok'] ? '压缩包已创建' : trim($result['stderr'] ?: $result['stdout'] ?: '压缩失败'), 'path' => $archivePath];
}

function host_agent_remote_extract(array $target, string $path, string $destination): array {
    $script = 'mkdir -p ' . escapeshellarg($destination) . ' && tar -xzf ' . escapeshellarg($path) . ' -C ' . escapeshellarg($destination);
    $result = host_agent_remote_exec($target, 'sh -lc ' . escapeshellarg($script));
    return ['ok' => $result['ok'], 'msg' => $result['ok'] ? '压缩包已解压' : trim($result['stderr'] ?: $result['stdout'] ?: '解压失败'), 'path' => $destination];
}

function host_agent_remote_ssh_context_script(): string {
    return <<<'SH'
emit() {
  printf '%s\t%s\n' "$1" "$(printf '%s' "${2-}" | base64 | tr -d '\n')"
}
config_path=/etc/ssh/sshd_config
if [ -f /etc/sshd_config ]; then
  config_path=/etc/sshd_config
fi
if [ -f /etc/ssh/sshd_config ]; then
  config_path=/etc/ssh/sshd_config
fi
manager=unknown
if command -v systemctl >/dev/null 2>&1; then
  manager=systemd
elif command -v service >/dev/null 2>&1; then
  manager=service
fi
service_name=ssh
if [ "$manager" = systemd ]; then
  for cand in ssh sshd; do
    load=$(systemctl show -p LoadState --value "$cand.service" 2>/dev/null || true)
    if [ "$load" = loaded ]; then
      service_name=$cand
      break
    fi
  done
elif [ "$manager" = service ]; then
  for cand in ssh sshd; do
    status_out=$(service "$cand" status 2>&1 || true)
    status_lc=$(printf '%s' "$status_out" | tr '[:upper:]' '[:lower:]')
    case "$status_lc" in
      *"unrecognized service"*|*"not found"*)
        ;;
      *)
        service_name=$cand
        break
        ;;
    esac
  done
fi
is_root=no
if [ "$(id -u)" = "0" ]; then
  is_root=yes
fi
run_priv_shell() {
  if [ "$is_root" = yes ]; then
    sh -lc "$1"
    return $?
  fi
  if command -v sudo >/dev/null 2>&1; then
    sudo -n sh -lc "$1"
    return $?
  fi
  return 126
}
SH;
}

function host_agent_remote_ssh_status(array $target): array {
    $script = host_agent_remote_ssh_context_script() . <<<'SH'
installed=no
if command -v sshd >/dev/null 2>&1; then
  installed=yes
fi
running=no
enabled=unknown
details=
if [ "$manager" = systemd ]; then
  active=$(systemctl is-active "$service_name.service" 2>/dev/null || true)
  enabled_raw=$(systemctl is-enabled "$service_name.service" 2>/dev/null || true)
  if [ "$active" = active ]; then
    running=yes
  fi
  case "$enabled_raw" in
    enabled|static) enabled=yes ;;
    disabled|masked) enabled=no ;;
  esac
  details=$(printf 'active=%s enabled=%s' "$active" "$enabled_raw")
elif [ "$manager" = service ]; then
  service_status=$(service "$service_name" status 2>&1 || true)
  service_lc=$(printf '%s' "$service_status" | tr '[:upper:]' '[:lower:]')
  case "$service_lc" in
    *running*|*started*|*active*) running=yes ;;
  esac
  details=$service_status
else
  details='未检测到 systemd / service 管理器'
fi
emit installed "$installed"
emit service_manager "$manager"
emit service_name "$service_name"
emit running "$running"
emit enabled "$enabled"
emit config_path "$config_path"
emit details "$details"
SH;
    $result = host_agent_remote_exec($target, 'sh -lc ' . escapeshellarg($script));
    if (!$result['ok']) {
        return ['ok' => false, 'msg' => trim($result['stderr'] ?: $result['stdout'] ?: '远程 SSH 状态读取失败')];
    }
    $map = host_agent_parse_encoded_pairs($result['stdout']);
    $enabledRaw = (string)($map['enabled'] ?? 'unknown');
    return [
        'ok' => true,
        'installed' => (($map['installed'] ?? 'no') === 'yes'),
        'service_manager' => (string)($map['service_manager'] ?? 'unknown'),
        'service_name' => (string)($map['service_name'] ?? 'ssh'),
        'running' => (($map['running'] ?? 'no') === 'yes'),
        'enabled' => $enabledRaw === 'yes' ? true : ($enabledRaw === 'no' ? false : null),
        'config_path' => (string)($map['config_path'] ?? '/etc/ssh/sshd_config'),
        'details' => (string)($map['details'] ?? ''),
        'mode' => 'remote',
        'updated_at' => date('Y-m-d H:i:s'),
    ];
}

function host_agent_remote_privilege_error(array $result, string $fallback): array {
    $message = trim($result['stderr'] ?: $result['stdout'] ?: $fallback);
    if (($result['code'] ?? 0) === 126) {
        $message = '当前远程用户没有 root 或免密 sudo 权限，无法执行该 SSH 管理操作';
    }
    return ['ok' => false, 'msg' => $message];
}

function host_agent_remote_ssh_config_read(array $target): array {
    $status = host_agent_remote_ssh_status($target);
    if (empty($status['ok'])) {
        return $status;
    }
    $path = (string)($status['config_path'] ?? '/etc/ssh/sshd_config');
    $script = 'if [ -f ' . escapeshellarg($path) . ' ]; then base64 < ' . escapeshellarg($path) . '; else printf ""; fi';
    $result = host_agent_remote_exec($target, 'sh -lc ' . escapeshellarg($script));
    if (!$result['ok']) {
        return ['ok' => false, 'msg' => trim($result['stderr'] ?: $result['stdout'] ?: '远程 SSH 配置读取失败')];
    }
    $content = trim($result['stdout']) === '' ? '' : base64_decode(trim($result['stdout']), true);
    if ($content === false) {
        return ['ok' => false, 'msg' => '远程 SSH 配置解码失败'];
    }
    return host_agent_file_payload($path, $content) + [
        'structured' => host_agent_parse_ssh_options($content),
    ];
}

function host_agent_remote_ssh_validate(array $target, string $content): array {
    if (trim($content) === '') {
        return ['ok' => false, 'msg' => 'SSH 配置不能为空'];
    }
    $script = host_agent_remote_ssh_context_script() . <<<'SH'
tmp=$(mktemp /tmp/host-agent-sshd-config.XXXXXX)
if ! { base64 -d 2>/dev/null || base64 -D; } > "$tmp"; then
  rm -f "$tmp"
  echo 'SSH 配置内容解码失败'
  exit 13
fi
if ! command -v sshd >/dev/null 2>&1; then
  rm -f "$tmp"
  echo '远程主机未检测到 sshd，可先执行 SSH 安装'
  exit 20
fi
check_output=$(sshd -t -f "$tmp" 2>&1)
code=$?
if [ $code -ne 0 ]; then
  printf '%s' "$check_output"
  rm -f "$tmp"
  exit $code
fi
rm -f "$tmp"
echo '配置校验通过'
SH;
    $result = host_agent_remote_exec($target, 'sh -lc ' . escapeshellarg($script), base64_encode($content));
    if (!$result['ok']) {
        return ['ok' => false, 'msg' => trim($result['stderr'] ?: $result['stdout'] ?: '远程 SSH 配置校验失败')];
    }
    return ['ok' => true, 'msg' => trim($result['stdout']) ?: '配置校验通过'];
}

function host_agent_remote_ssh_config_save(array $target, string $content): array {
    $validation = host_agent_remote_ssh_validate($target, $content);
    if (empty($validation['ok'])) {
        return $validation;
    }
    $status = host_agent_remote_ssh_status($target);
    if (empty($status['ok'])) {
        return $status;
    }
    $path = (string)($status['config_path'] ?? '/etc/ssh/sshd_config');
    $dir = dirname($path);
    $writeCommand = 'mkdir -p ' . escapeshellarg($dir)
        . ' && if [ -f ' . escapeshellarg($path) . ' ]; then cp ' . escapeshellarg($path) . ' "$backup"; fi'
        . ' && cat "$tmp" > ' . escapeshellarg($path);
    $script = host_agent_remote_ssh_context_script() . "\n"
        . "tmp=\$(mktemp /tmp/host-agent-sshd-config.XXXXXX) || exit 1\n"
        . "if ! { base64 -d 2>/dev/null || base64 -D; } > \"\$tmp\"; then\n"
        . "  rm -f \"\$tmp\"\n"
        . "  echo 'SSH 配置内容解码失败'\n"
        . "  exit 13\n"
        . "fi\n"
        . "backup=" . escapeshellarg($path) . "\n"
        . "backup=\"\${backup}.bak.\$(date +%Y%m%d_%H%M%S)\"\n"
        . "run_priv_shell " . escapeshellarg($writeCommand) . "\n"
        . "code=\$?\n"
        . "if [ \$code -ne 0 ]; then\n"
        . "  rm -f \"\$tmp\"\n"
        . "  exit \$code\n"
        . "fi\n"
        . "rm -f \"\$tmp\"\n"
        . "emit path " . escapeshellarg($path) . "\n"
        . "emit backup_path \"\$backup\"\n";
    $result = host_agent_remote_exec($target, 'sh -lc ' . escapeshellarg($script), base64_encode($content));
    if (!$result['ok']) {
        return host_agent_remote_privilege_error($result, '远程 SSH 配置保存失败');
    }
    $map = host_agent_parse_encoded_pairs($result['stdout']);
    return [
        'ok' => true,
        'msg' => '远程 SSH 配置已保存',
        'path' => (string)($map['path'] ?? $path),
        'backup_path' => (string)($map['backup_path'] ?? ''),
        'size' => strlen($content),
        'updated_at' => date('Y-m-d H:i:s'),
    ];
}

function host_agent_remote_ssh_restore_last_backup(array $target): array {
    $status = host_agent_remote_ssh_status($target);
    if (empty($status['ok'])) {
        return $status;
    }
    $path = (string)($status['config_path'] ?? '/etc/ssh/sshd_config');
    $restoreCommand = 'cat "$latest" > ' . escapeshellarg($path);
    $script = host_agent_remote_ssh_context_script() . "\n"
        . "latest=\$(ls -1t " . escapeshellarg($path) . ".bak.* 2>/dev/null | head -n 1)\n"
        . "if [ -z \"\$latest\" ]; then\n"
        . "  echo '未找到可恢复的 SSH 配置备份'\n"
        . "  exit 14\n"
        . "fi\n"
        . "run_priv_shell " . escapeshellarg($restoreCommand) . "\n"
        . "code=\$?\n"
        . "if [ \$code -ne 0 ]; then\n"
        . "  exit \$code\n"
        . "fi\n"
        . "emit path " . escapeshellarg($path) . "\n"
        . "emit backup_path \"\$latest\"\n";
    $result = host_agent_remote_exec($target, 'sh -lc ' . escapeshellarg($script));
    if (!$result['ok']) {
        if (($result['code'] ?? 0) === 14) {
            return ['ok' => false, 'msg' => '未找到可恢复的 SSH 配置备份'];
        }
        return host_agent_remote_privilege_error($result, '远程 SSH 配置恢复失败');
    }
    $map = host_agent_parse_encoded_pairs($result['stdout']);
    return [
        'ok' => true,
        'msg' => '已恢复远程 SSH 最近一次配置备份',
        'path' => (string)($map['path'] ?? $path),
        'backup_path' => (string)($map['backup_path'] ?? ''),
    ];
}

function host_agent_remote_ssh_service_action(array $target, string $action): array {
    $allowed = ['start', 'stop', 'restart', 'reload'];
    if (!in_array($action, $allowed, true)) {
        return ['ok' => false, 'msg' => '不支持的操作'];
    }
    $status = host_agent_remote_ssh_status($target);
    if (empty($status['ok'])) {
        return $status;
    }
    $manager = (string)($status['service_manager'] ?? 'unknown');
    $service = (string)($status['service_name'] ?? 'ssh');
    if ($manager === 'systemd') {
        $command = 'systemctl ' . $action . ' ' . escapeshellarg($service . '.service');
    } elseif ($manager === 'service') {
        $command = 'service ' . escapeshellarg($service) . ' ' . escapeshellarg($action);
    } else {
        return ['ok' => false, 'msg' => '远程主机未检测到可用的 SSH 服务管理器'];
    }
    $script = host_agent_remote_ssh_context_script() . "\n"
        . "run_priv_shell " . escapeshellarg($command . ' 2>&1') . "\n"
        . "code=\$?\n"
        . "if [ \$code -ne 0 ]; then\n"
        . "  exit \$code\n"
        . "fi\n";
    $result = host_agent_remote_exec($target, 'sh -lc ' . escapeshellarg($script));
    if (!$result['ok']) {
        return host_agent_remote_privilege_error($result, '远程 SSH 服务操作失败');
    }
    return [
        'ok' => true,
        'msg' => '远程 SSH 服务已执行 ' . $action,
        'status' => host_agent_remote_ssh_status($target),
        'command_output' => trim($result['stdout'] . "\n" . $result['stderr']),
    ];
}

function host_agent_remote_ssh_enable_toggle(array $target, bool $enabled): array {
    $status = host_agent_remote_ssh_status($target);
    if (empty($status['ok'])) {
        return $status;
    }
    $manager = (string)($status['service_manager'] ?? 'unknown');
    $service = (string)($status['service_name'] ?? 'ssh');
    if ($manager === 'systemd') {
        $command = 'systemctl ' . ($enabled ? 'enable' : 'disable') . ' ' . escapeshellarg($service . '.service');
    } elseif ($manager === 'service') {
        $command = $enabled
            ? 'if command -v update-rc.d >/dev/null 2>&1; then update-rc.d ' . escapeshellarg($service) . ' defaults; elif command -v chkconfig >/dev/null 2>&1; then chkconfig ' . escapeshellarg($service) . ' on; else exit 125; fi'
            : 'if command -v update-rc.d >/dev/null 2>&1; then update-rc.d ' . escapeshellarg($service) . ' disable; elif command -v chkconfig >/dev/null 2>&1; then chkconfig ' . escapeshellarg($service) . ' off; else exit 125; fi';
    } else {
        return ['ok' => false, 'msg' => '远程主机未检测到支持自启管理的服务管理器'];
    }
    $script = host_agent_remote_ssh_context_script() . "\n"
        . "run_priv_shell " . escapeshellarg($command . ' 2>&1') . "\n"
        . "code=\$?\n"
        . "if [ \$code -ne 0 ]; then\n"
        . "  exit \$code\n"
        . "fi\n";
    $result = host_agent_remote_exec($target, 'sh -lc ' . escapeshellarg($script));
    if (!$result['ok']) {
        if (($result['code'] ?? 0) === 125) {
            return ['ok' => false, 'msg' => '远程主机缺少 update-rc.d / chkconfig，无法管理 SSH 开机启动'];
        }
        return host_agent_remote_privilege_error($result, '远程 SSH 开机启动设置失败');
    }
    return [
        'ok' => true,
        'msg' => '远程 SSH 已' . ($enabled ? '启用' : '禁用') . '开机启动',
        'status' => host_agent_remote_ssh_status($target),
    ];
}

function host_agent_remote_install_ssh_service(array $target): array {
    $script = host_agent_remote_ssh_context_script() . <<<'SH'
if command -v apt-get >/dev/null 2>&1; then
  install_cmd='DEBIAN_FRONTEND=noninteractive apt-get update && DEBIAN_FRONTEND=noninteractive apt-get install -y openssh-server'
elif command -v dnf >/dev/null 2>&1; then
  install_cmd='dnf install -y openssh-server'
elif command -v yum >/dev/null 2>&1; then
  install_cmd='yum install -y openssh-server'
elif command -v apk >/dev/null 2>&1; then
  install_cmd='apk add --no-cache openssh-server'
else
  echo '未识别远程主机包管理器，无法自动安装 openssh-server'
  exit 15
fi
run_priv_shell "$install_cmd 2>&1"
code=$?
if [ $code -ne 0 ]; then
  exit $code
fi
SH;
    $result = host_agent_remote_exec($target, 'sh -lc ' . escapeshellarg($script));
    if (!$result['ok']) {
        if (($result['code'] ?? 0) === 15) {
            return ['ok' => false, 'msg' => '未识别远程主机包管理器，无法自动安装 openssh-server'];
        }
        return host_agent_remote_privilege_error($result, '远程 SSH 安装失败');
    }
    $enable = host_agent_remote_ssh_enable_toggle($target, true);
    $start = host_agent_remote_ssh_service_action($target, 'start');
    return [
        'ok' => true,
        'msg' => '远程 SSH 服务已安装，并已尝试启用开机启动和启动服务',
        'output' => trim($result['stdout'] . "\n" . $result['stderr']),
        'enable_result' => $enable,
        'start_result' => $start,
    ];
}

function host_agent_authorized_keys_path(string $user): string {
    $trimmed = trim($user);
    return $trimmed === '' || $trimmed === 'root'
        ? '/root/.ssh/authorized_keys'
        : '/home/' . $trimmed . '/.ssh/authorized_keys';
}

function host_agent_authorized_keys_entries(string $content): array {
    $entries = [];
    foreach (preg_split('/\r?\n/', $content) ?: [] as $line) {
        $raw = trim($line);
        if ($raw === '') {
            continue;
        }
        $entry = [
            'line' => $raw,
            'line_hash' => sha1($raw),
            'type' => '',
            'comment' => '',
            'options' => '',
            'valid' => false,
        ];
        if (preg_match('/^(?:(.+?)\s+)?((?:ssh|ecdsa|sk)-[A-Za-z0-9@._+-]+)\s+([A-Za-z0-9+\/=]+)(?:\s+(.*))?$/', $raw, $matches)) {
            $prefix = trim((string)($matches[1] ?? ''));
            $type = trim((string)($matches[2] ?? ''));
            $keyData = trim((string)($matches[3] ?? ''));
            $comment = trim((string)($matches[4] ?? ''));
            $entry['options'] = ($prefix !== '' && $prefix !== $type) ? $prefix : '';
            $entry['type'] = $type;
            $entry['key'] = $keyData;
            $entry['comment'] = $comment;
            $entry['valid'] = true;
        }
        $entries[] = $entry;
    }
    return $entries;
}

function host_agent_authorized_keys_list(array $target, string $root, string $mode, string $user): array {
    $path = host_agent_authorized_keys_path($user);
    $result = ($target['type'] ?? 'local') === 'remote'
        ? host_agent_remote_file_read($target, $path)
        : host_agent_local_file_read($root, $path);
    if (empty($result['ok']) && str_contains((string)($result['msg'] ?? ''), '不存在')) {
        return ['ok' => true, 'path' => $path, 'user' => $user, 'content' => '', 'entries' => []];
    }
    if (empty($result['ok'])) {
        return $result;
    }
    $content = (string)($result['content'] ?? '');
    return [
        'ok' => true,
        'path' => $path,
        'user' => $user,
        'content' => $content,
        'entries' => host_agent_authorized_keys_entries($content),
    ];
}

function host_agent_authorized_keys_add(array $target, string $root, string $mode, string $user, string $publicKey): array {
    $trimmed = trim($publicKey);
    if ($trimmed === '') {
        return ['ok' => false, 'msg' => '公钥内容不能为空'];
    }
    $listed = host_agent_authorized_keys_list($target, $root, $mode, $user);
    if (empty($listed['ok'])) {
        return $listed;
    }
    $lines = array_values(array_filter(preg_split('/\r?\n/', (string)($listed['content'] ?? '')) ?: [], static fn(string $line): bool => trim($line) !== ''));
    if (!in_array($trimmed, array_map('trim', $lines), true)) {
        $lines[] = $trimmed;
    }
    $content = implode("\n", $lines);
    if ($content !== '') {
        $content .= "\n";
    }
    $path = (string)($listed['path'] ?? host_agent_authorized_keys_path($user));
    $write = ($target['type'] ?? 'local') === 'remote'
        ? host_agent_remote_file_write($target, $path, $content)
        : host_agent_local_file_write($root, $path, $content);
    if (empty($write['ok'])) {
        return $write;
    }
    return host_agent_authorized_keys_list($target, $root, $mode, $user) + ['msg' => 'authorized_keys 已更新'];
}

function host_agent_authorized_keys_remove(array $target, string $root, string $mode, string $user, string $lineHash): array {
    if ($lineHash === '') {
        return ['ok' => false, 'msg' => '缺少 line_hash'];
    }
    $listed = host_agent_authorized_keys_list($target, $root, $mode, $user);
    if (empty($listed['ok'])) {
        return $listed;
    }
    $kept = [];
    $changed = false;
    foreach (preg_split('/\r?\n/', (string)($listed['content'] ?? '')) ?: [] as $line) {
        $trimmed = trim($line);
        if ($trimmed === '') {
            continue;
        }
        if (sha1($trimmed) === $lineHash) {
            $changed = true;
            continue;
        }
        $kept[] = $trimmed;
    }
    if (!$changed) {
        return ['ok' => false, 'msg' => '未找到要删除的 authorized_keys 条目'];
    }
    $content = implode("\n", $kept);
    if ($content !== '') {
        $content .= "\n";
    }
    $path = (string)($listed['path'] ?? host_agent_authorized_keys_path($user));
    $write = ($target['type'] ?? 'local') === 'remote'
        ? host_agent_remote_file_write($target, $path, $content)
        : host_agent_local_file_write($root, $path, $content);
    if (empty($write['ok'])) {
        return $write;
    }
    return host_agent_authorized_keys_list($target, $root, $mode, $user) + ['msg' => 'authorized_keys 条目已删除'];
}

function host_agent_terminal_title(array $target): string {
    if (($target['type'] ?? 'local') === 'remote') {
        return trim((string)($target['name'] ?? $target['hostname'] ?? '远程主机')) ?: '远程主机';
    }
    return '本机';
}

function host_agent_terminal_summary(string $id, array $session): array {
    return [
        'id' => $id,
        'title' => (string)($session['title'] ?? $id),
        'host_id' => (string)($session['host_id'] ?? 'local'),
        'host_label' => (string)($session['host_label'] ?? ''),
        'persist' => !empty($session['persist']),
        'idle_minutes' => max(1, (int)($session['idle_minutes'] ?? 120)),
        'running' => !empty($session['running']),
        'created_at' => (string)($session['created_at'] ?? ''),
        'updated_at' => (string)($session['updated_at_text'] ?? ''),
        'last_attach_at' => (string)($session['last_attach_at_text'] ?? ''),
        'ended_at' => (string)($session['ended_at'] ?? ''),
    ];
}

function host_agent_terminal_list(string $root): array {
    host_agent_terminal_cleanup();
    $registry =& host_agent_terminal_registry();
    $items = [];
    foreach ($registry as $id => $session) {
        if (!is_array($session)) {
            continue;
        }
        $items[] = host_agent_terminal_summary((string)$id, $session);
    }
    usort($items, static function (array $a, array $b): int {
        return strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? ''));
    });
    host_agent_terminal_write_state($root);
    return ['ok' => true, 'sessions' => $items];
}

function host_agent_terminal_open(array $target, string $root, string $mode, bool $persist = true, int $idleMinutes = 120): array {
    host_agent_terminal_cleanup();
    $command = [];
    $cleanup = [];
    $hostId = trim((string)($target['id'] ?? ''));
    if ($hostId === '') {
        $hostId = ($target['type'] ?? 'local') === 'remote'
            ? ('remote:' . trim((string)($target['hostname'] ?? 'unknown')))
            : 'local';
    }
    $title = host_agent_terminal_title($target);
    if (($target['type'] ?? 'local') === 'remote') {
        $prepared = host_agent_remote_shell_command($target, 'bash -l');
        if (empty($prepared['ok'])) {
            return ['ok' => false, 'msg' => (string)($prepared['msg'] ?? '远程终端准备失败')];
        }
        $cleanup = (array)($prepared['cleanup'] ?? []);
        $shell = implode(' ', array_map('escapeshellarg', (array)$prepared['command']));
        $command = ['script', '-qfc', $shell, '/dev/null'];
    } else {
        $shell = $mode === 'host'
            ? '/usr/bin/nsenter -t 1 -m -u -i -n -p bash -l'
            : 'bash -l';
        $command = ['script', '-qfc', $shell, '/dev/null'];
    }
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = proc_open($command, $descriptors, $pipes);
    if (!is_resource($proc)) {
        foreach ($cleanup as $path) {
            @unlink((string)$path);
        }
        return ['ok' => false, 'msg' => '终端进程启动失败'];
    }
    foreach ($pipes as $pipe) {
        stream_set_blocking($pipe, false);
    }
    $id = 'term_' . bin2hex(random_bytes(8));
    $registry =& host_agent_terminal_registry();
    $registry[$id] = [
        'proc' => $proc,
        'pipes' => $pipes,
        'buffer' => '',
        'cleanup' => $cleanup,
        'host_id' => $hostId,
        'host_label' => $title,
        'title' => $title,
        'persist' => $persist,
        'idle_minutes' => max(1, $idleMinutes),
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at_text' => date('Y-m-d H:i:s'),
        'updated_at_unix' => time(),
        'last_attach_at_text' => date('Y-m-d H:i:s'),
        'running' => true,
    ];
    host_agent_terminal_write_state($root);
    return ['ok' => true, 'id' => $id] + host_agent_terminal_summary($id, $registry[$id]);
}

function host_agent_terminal_read(string $id): array {
    host_agent_terminal_cleanup();
    $registry =& host_agent_terminal_registry();
    if (empty($registry[$id])) {
        return ['ok' => true, 'running' => false, 'output' => '', 'msg' => '终端会话不存在'];
    }
    $session =& $registry[$id];
    foreach ([1, 2] as $index) {
        $chunk = '';
        while (($part = fread($session['pipes'][$index], 8192)) !== false && $part !== '') {
            $chunk .= $part;
            if (strlen($part) < 8192) {
                break;
            }
        }
        if ($chunk !== '') {
            $session['buffer'] .= $chunk;
        }
    }
    $status = proc_get_status($session['proc']);
    $output = (string)$session['buffer'];
    $session['buffer'] = '';
    $session['running'] = (bool)($status['running'] ?? false);
    $session['updated_at_unix'] = time();
    $session['updated_at_text'] = date('Y-m-d H:i:s');
    $session['last_attach_at_text'] = date('Y-m-d H:i:s');
    if (!$session['running'] && empty($session['ended_at'])) {
        $session['ended_at'] = date('Y-m-d H:i:s');
    }
    $root = $GLOBALS['HOST_AGENT_ROOT'] ?? '';
    if (is_string($root) && $root !== '') {
        host_agent_terminal_write_state($root);
    }
    return ['ok' => true, 'running' => $session['running'], 'output' => $output] + host_agent_terminal_summary($id, $session);
}

function host_agent_terminal_write_pipe($pipe, string $data, float $timeoutSeconds = 2.0): bool {
    if (!is_resource($pipe)) {
        return false;
    }
    $length = strlen($data);
    $offset = 0;
    $deadline = microtime(true) + max(0.2, $timeoutSeconds);
    while ($offset < $length) {
        $chunk = substr($data, $offset);
        $written = @fwrite($pipe, $chunk);
        if (is_int($written) && $written > 0) {
            $offset += $written;
            continue;
        }
        $remaining = $deadline - microtime(true);
        if ($remaining <= 0) {
            break;
        }
        $write = [$pipe];
        $read = [];
        $except = [];
        $seconds = (int)floor($remaining);
        $microseconds = (int)(($remaining - $seconds) * 1000000);
        $ready = @stream_select($read, $write, $except, $seconds, $microseconds);
        if ($ready === false) {
            break;
        }
        if ($ready === 0) {
            usleep(20000);
        }
    }
    @fflush($pipe);
    return $offset === $length;
}

function host_agent_terminal_write(string $id, string $data): array {
    $registry =& host_agent_terminal_registry();
    if (empty($registry[$id])) {
        return ['ok' => false, 'msg' => '终端会话不存在'];
    }
    if (!host_agent_terminal_write_pipe($registry[$id]['pipes'][0], $data)) {
        return ['ok' => false, 'msg' => '终端输入写入失败'];
    }
    $registry[$id]['updated_at_unix'] = time();
    $registry[$id]['updated_at_text'] = date('Y-m-d H:i:s');
    $root = $GLOBALS['HOST_AGENT_ROOT'] ?? '';
    if (is_string($root) && $root !== '') {
        host_agent_terminal_write_state($root);
    }
    return ['ok' => true];
}

function host_agent_terminal_close(string $id): array {
    $registry =& host_agent_terminal_registry();
    if (empty($registry[$id])) {
        return ['ok' => false, 'msg' => '终端会话不存在'];
    }
    $session = $registry[$id];
    foreach (($session['pipes'] ?? []) as $pipe) {
        if (is_resource($pipe)) {
            @fclose($pipe);
        }
    }
    foreach (($session['cleanup'] ?? []) as $path) {
        @unlink((string)$path);
    }
    @proc_terminate($session['proc']);
    @proc_close($session['proc']);
    unset($registry[$id]);
    $root = $GLOBALS['HOST_AGENT_ROOT'] ?? '';
    if (is_string($root) && $root !== '') {
        host_agent_terminal_write_state($root);
    }
    return ['ok' => true, 'msg' => '终端会话已关闭'];
}

function host_agent_remote_test(array $target): array {
    $result = host_agent_remote_exec($target, 'printf host-agent-ok');
    return [
        'ok' => $result['ok'] && str_contains($result['stdout'], 'host-agent-ok'),
        'msg' => $result['ok'] ? 'SSH 连接成功' : trim($result['stderr'] ?: $result['stdout'] ?: 'SSH 连接失败'),
        'output' => trim($result['stdout'] . "\n" . $result['stderr']),
    ];
}

function host_agent_remote_exec_command(array $target, string $command): array {
    if (trim($command) === '') {
        return ['ok' => false, 'msg' => '命令不能为空'];
    }
    $result = host_agent_remote_exec($target, 'sh -lc ' . escapeshellarg($command));
    return [
        'ok' => $result['ok'],
        'msg' => $result['ok'] ? '命令执行成功' : trim($result['stderr'] ?: $result['stdout'] ?: '命令执行失败'),
        'stdout' => $result['stdout'],
        'stderr' => $result['stderr'],
        'code' => (int)($result['code'] ?? 1),
    ];
}

function host_agent_runtime_shell(string $mode, string $script, ?string $stdin = null): array {
    return $mode === 'host'
        ? host_agent_host_shell($script, $stdin)
        : host_agent_proc_run(['sh', '-c', $script], $stdin);
}

function host_agent_docker_socket_path(): string {
    return '/var/run/docker.sock';
}

function host_agent_docker_available(): bool {
    return file_exists(host_agent_docker_socket_path());
}

function host_agent_docker_request(string $method, string $path, ?array $payload = null): array {
    if (!host_agent_docker_available()) {
        return ['ok' => false, 'status' => 0, 'body' => '', 'json' => null, 'error' => 'docker.sock 不可用'];
    }
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'status' => 0, 'body' => '', 'json' => null, 'error' => 'curl 扩展不可用'];
    }
    $ch = curl_init('http://localhost' . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_UNIX_SOCKET_PATH => host_agent_docker_socket_path(),
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 20,
    ]);
    if ($payload !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
    $body = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = $errno ? curl_error($ch) : '';
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $decoded = null;
    if (is_string($body) && $body !== '') {
        $decoded = json_decode($body, true);
    }
    return [
        'ok' => $errno === 0 && $status >= 200 && $status < 300,
        'status' => $status,
        'body' => is_string($body) ? $body : '',
        'json' => is_array($decoded) ? $decoded : null,
        'error' => $error,
    ];
}

function host_agent_emit_script_header(): string {
    return <<<'SH'
emit() {
  key="$1"
  shift
  value="$*"
  encoded=$(printf "%s" "$value" | base64 | tr -d '\n')
  printf "%s\t%s\n" "$key" "$encoded"
}
SH;
}

function host_agent_parse_table_output(string $output, array $columns): array {
    $items = [];
    foreach (preg_split('/\r?\n/', trim($output)) ?: [] as $line) {
        if ($line === '') {
            continue;
        }
        $parts = explode("\t", $line);
        $row = [];
        foreach ($columns as $index => $name) {
            $row[$name] = (string)($parts[$index] ?? '');
        }
        $items[] = $row;
    }
    return $items;
}

function host_agent_system_overview(string $root, string $mode): array {
    $script = host_agent_emit_script_header() . "\n" . <<<'SH'
hostname_value=$(hostname 2>/dev/null || true)
kernel_value=$(uname -srmo 2>/dev/null || uname -a 2>/dev/null || true)
os_value=$(grep '^PRETTY_NAME=' /etc/os-release 2>/dev/null | head -n1 | cut -d= -f2- | tr -d '"' || true)
if [ -z "$os_value" ]; then os_value=$(uname -s 2>/dev/null || true); fi
cpu_model=$(awk -F: '/model name|Hardware|Processor/ {gsub(/^[ \t]+/, "", $2); print $2; exit}' /proc/cpuinfo 2>/dev/null)
cpu_cores=$(getconf _NPROCESSORS_ONLN 2>/dev/null || nproc 2>/dev/null || echo 1)
load_values=$(cut -d' ' -f1-3 /proc/loadavg 2>/dev/null || echo "0.00 0.00 0.00")
boot_time=$(awk '/^btime / {print $2; exit}' /proc/stat 2>/dev/null || echo 0)
uptime_seconds=$(cut -d'.' -f1 /proc/uptime 2>/dev/null || echo 0)
mem_total=$(awk '/^MemTotal:/ {print $2; exit}' /proc/meminfo 2>/dev/null || echo 0)
mem_available=$(awk '/^MemAvailable:/ {print $2; exit}' /proc/meminfo 2>/dev/null || echo 0)
swap_total=$(awk '/^SwapTotal:/ {print $2; exit}' /proc/meminfo 2>/dev/null || echo 0)
swap_free=$(awk '/^SwapFree:/ {print $2; exit}' /proc/meminfo 2>/dev/null || echo 0)
disk_line=$(df -Pk / 2>/dev/null | awk 'NR==2 {print $2"\t"$3"\t"$4"\t"$5"\t"$6}')
set -- $load_values
load1="$1"
load5="$2"
load15="$3"
prev=$(awk '/^cpu / {print $2+$3+$4+$5+$6+$7+$8, $5}' /proc/stat 2>/dev/null)
sleep 0.2
curr=$(awk '/^cpu / {print $2+$3+$4+$5+$6+$7+$8, $5}' /proc/stat 2>/dev/null)
prev_total=$(printf "%s" "$prev" | awk '{print $1}')
prev_idle=$(printf "%s" "$prev" | awk '{print $2}')
curr_total=$(printf "%s" "$curr" | awk '{print $1}')
curr_idle=$(printf "%s" "$curr" | awk '{print $2}')
cpu_percent=$(awk -v pt="${prev_total:-0}" -v pi="${prev_idle:-0}" -v ct="${curr_total:-0}" -v ci="${curr_idle:-0}" 'BEGIN{dt=ct-pt; di=ci-pi; if (dt <= 0) {print "0.00"} else {printf "%.2f", (dt-di)*100/dt}}')
emit hostname "$hostname_value"
emit kernel "$kernel_value"
emit os "$os_value"
emit cpu_model "$cpu_model"
emit cpu_cores "$cpu_cores"
emit load1 "$load1"
emit load5 "$load5"
emit load15 "$load15"
emit boot_time "$boot_time"
emit uptime_seconds "$uptime_seconds"
emit mem_total_kb "$mem_total"
emit mem_available_kb "$mem_available"
emit swap_total_kb "$swap_total"
emit swap_free_kb "$swap_free"
emit cpu_percent "$cpu_percent"
if [ -n "$disk_line" ]; then
  disk_total=$(printf "%s" "$disk_line" | awk '{print $1}')
  disk_used=$(printf "%s" "$disk_line" | awk '{print $2}')
  disk_available=$(printf "%s" "$disk_line" | awk '{print $3}')
  disk_use_percent=$(printf "%s" "$disk_line" | awk '{print $4}')
  disk_mount=$(printf "%s" "$disk_line" | awk '{print $5}')
  emit disk_total_kb "$disk_total"
  emit disk_used_kb "$disk_used"
  emit disk_available_kb "$disk_available"
  emit disk_use_percent "$disk_use_percent"
  emit disk_mount "$disk_mount"
fi
SH;
    $result = host_agent_runtime_shell($mode, $script);
    if (!$result['ok']) {
        return ['ok' => false, 'msg' => trim($result['stderr'] ?: $result['stdout'] ?: '系统概览读取失败')];
    }
    $data = host_agent_parse_encoded_pairs($result['stdout']);
    return ['ok' => true, 'data' => $data, 'mode' => $mode];
}

function host_agent_process_list(string $mode, string $keyword = '', string $sort = 'cpu', int $limit = 100): array {
    $limit = max(1, min(300, $limit));
    $sortFlag = $sort === 'mem' ? '-pmem' : '-pcpu';
    $script = <<<'SH'
ps -eo pid=,user=,%cpu=,%mem=,etimes=,stat=,comm=,args= --sort=__SORT__ | head -n __LIMIT__ | while read -r pid user cpu mem etimes stat comm args; do
  [ -n "$pid" ] || continue
  printf "%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\n" "$pid" "$user" "$cpu" "$mem" "$etimes" "$stat" "$comm" "$args"
done
SH;
    $script = str_replace(['__SORT__', '__LIMIT__'], [$sortFlag, (string)$limit], $script);
    $result = host_agent_runtime_shell($mode, $script);
    if (!$result['ok']) {
        return ['ok' => false, 'msg' => trim($result['stderr'] ?: $result['stdout'] ?: '进程列表读取失败')];
    }
    $items = host_agent_parse_table_output($result['stdout'], ['pid', 'user', 'cpu', 'mem', 'etimes', 'stat', 'comm', 'args']);
    if ($keyword !== '') {
        $keywordLower = mb_strtolower($keyword);
        $items = array_values(array_filter($items, static function (array $item) use ($keywordLower): bool {
            $haystack = mb_strtolower(implode(' ', [$item['pid'] ?? '', $item['user'] ?? '', $item['comm'] ?? '', $item['args'] ?? '']));
            return str_contains($haystack, $keywordLower);
        }));
    }
    return ['ok' => true, 'items' => array_values($items)];
}

function host_agent_process_kill(string $mode, int $pid, string $signal = 'TERM'): array {
    if ($pid <= 1) {
        return ['ok' => false, 'msg' => 'PID 无效'];
    }
    $signal = strtoupper(trim($signal));
    if (!in_array($signal, ['TERM', 'KILL'], true)) {
        $signal = 'TERM';
    }
    $result = host_agent_runtime_shell($mode, 'kill -' . escapeshellarg($signal) . ' ' . (int)$pid . ' 2>&1');
    if ($result['ok']) {
        $verify = host_agent_runtime_shell($mode, 'for i in 1 2 3 4 5 6 7 8 9 10; do stat=$(ps -o stat= -p ' . (int)$pid . ' 2>/dev/null | tr -d " \t\r\n"); if [ -z "$stat" ] || printf "%s" "$stat" | grep -q "^Z"; then exit 0; fi; sleep 0.1; done; exit 1');
        if (!$verify['ok']) {
            $result = ['ok' => false, 'stdout' => '', 'stderr' => 'process still running'];
        }
    }
    return [
        'ok' => $result['ok'],
        'msg' => $result['ok'] ? ('进程已发送 ' . $signal . ' 信号') : trim($result['stderr'] ?: $result['stdout'] ?: '进程结束失败'),
        'pid' => $pid,
        'signal' => $signal,
    ];
}

function host_agent_service_list(string $root, string $mode, string $keyword = '', int $limit = 120): array {
    $limit = max(1, min(300, $limit));
    if ($mode !== 'host') {
        $ssh = host_agent_live_ssh_status($root, $mode);
        $items = [[
            'name' => (string)($ssh['service_name'] ?? 'ssh'),
            'load' => 'loaded',
            'active' => !empty($ssh['running']) ? 'active' : 'inactive',
            'sub' => !empty($ssh['running']) ? 'running' : 'dead',
            'description' => 'simulate SSH service',
            'enabled' => array_key_exists('enabled', $ssh) ? (!empty($ssh['enabled']) ? 'enabled' : 'disabled') : '',
            'manager' => 'simulate',
        ]];
        return ['ok' => true, 'items' => $items];
    }
    $script = <<<'SH'
systemctl list-units --type=service --all --no-pager --no-legend --plain | head -n __LIMIT__ | while read -r unit load active sub rest; do
  [ -n "$unit" ] || continue
  desc="$rest"
  enabled=$(systemctl is-enabled "$unit" 2>/dev/null || true)
  printf "%s\t%s\t%s\t%s\t%s\t%s\n" "$unit" "$load" "$active" "$sub" "$enabled" "$desc"
done
SH;
    $result = host_agent_runtime_shell($mode, str_replace('__LIMIT__', (string)$limit, $script));
    if (!$result['ok']) {
        return ['ok' => false, 'msg' => trim($result['stderr'] ?: $result['stdout'] ?: '服务列表读取失败')];
    }
    $items = host_agent_parse_table_output($result['stdout'], ['name', 'load', 'active', 'sub', 'enabled', 'description']);
    foreach ($items as &$item) {
        $item['manager'] = 'systemd';
    }
    unset($item);
    if ($keyword !== '') {
        $keywordLower = mb_strtolower($keyword);
        $items = array_values(array_filter($items, static function (array $item) use ($keywordLower): bool {
            $haystack = mb_strtolower(implode(' ', [$item['name'] ?? '', $item['description'] ?? '', $item['active'] ?? '', $item['enabled'] ?? '']));
            return str_contains($haystack, $keywordLower);
        }));
    }
    return ['ok' => true, 'items' => $items];
}

function host_agent_service_action_generic(string $root, string $mode, string $service, string $action): array {
    $service = trim($service);
    $action = strtolower(trim($action));
    if ($service === '' || !preg_match('/^[A-Za-z0-9_.@-]+(?:\.service)?$/', $service)) {
        return ['ok' => false, 'msg' => '服务名无效'];
    }
    if (!in_array($action, ['start', 'stop', 'restart', 'reload', 'enable', 'disable'], true)) {
        return ['ok' => false, 'msg' => '服务操作不支持'];
    }
    if ($mode !== 'host') {
        if ($service === 'ssh' || $service === 'ssh.service') {
            if ($action === 'enable' || $action === 'disable') {
                return host_agent_ssh_enable_toggle($root, $mode, $action === 'enable');
            }
            return host_agent_ssh_service_action($root, $mode, $action);
        }
        return ['ok' => false, 'msg' => 'simulate 模式仅支持 SSH 服务'];
    }
    $unit = str_ends_with($service, '.service') ? $service : ($service . '.service');
    $result = host_agent_runtime_shell($mode, 'systemctl ' . escapeshellarg($action) . ' ' . escapeshellarg($unit) . ' 2>&1');
    return [
        'ok' => $result['ok'],
        'msg' => $result['ok'] ? ('服务已执行 ' . $action) : trim($result['stderr'] ?: $result['stdout'] ?: '服务操作失败'),
        'output' => trim($result['stdout'] . "\n" . $result['stderr']),
    ];
}

function host_agent_service_logs(string $root, string $mode, string $service, int $limit = 120): array {
    $service = trim($service);
    $limit = max(10, min(500, $limit));
    if ($service === '') {
        return ['ok' => false, 'msg' => '服务名不能为空'];
    }
    if ($mode !== 'host') {
        if ($service === 'ssh' || $service === 'ssh.service') {
            $ssh = host_agent_live_ssh_status($root, $mode);
            return ['ok' => true, 'lines' => [
                '[simulate] service=ssh',
                '[simulate] running=' . (!empty($ssh['running']) ? 'yes' : 'no'),
                '[simulate] enabled=' . (!empty($ssh['enabled']) ? 'yes' : 'no'),
                '[simulate] updated_at=' . (string)($ssh['updated_at'] ?? ''),
            ]];
        }
        $normalized = str_ends_with($service, '.service') ? substr($service, 0, -8) : $service;
        $meta = host_agent_generic_service_meta($normalized);
        if ($meta) {
            $status = host_agent_generic_service_status($root, $mode, $normalized);
            $files = host_agent_share_service_files($root, $mode, $normalized);
            return ['ok' => true, 'lines' => [
                '[simulate] service=' . $normalized,
                '[simulate] label=' . (string)($meta['label'] ?? strtoupper($normalized)),
                '[simulate] running=' . (!empty($status['running']) ? 'yes' : 'no'),
                '[simulate] enabled=' . (!empty($status['enabled']) ? 'yes' : 'no'),
                '[simulate] installed=' . (!empty($status['installed']) ? 'yes' : 'no'),
                '[simulate] service_name=' . (string)($status['service_name'] ?? $normalized),
                '[simulate] updated_at=' . (string)($status['updated_at'] ?? ''),
                '[simulate] files=' . implode(', ', $files),
            ]];
        }
        return ['ok' => true, 'lines' => ['[simulate] 暂无可用服务日志']];
    }
    $unit = str_ends_with($service, '.service') ? $service : ($service . '.service');
    $result = host_agent_runtime_shell($mode, 'journalctl -u ' . escapeshellarg($unit) . ' -n ' . (int)$limit . ' --no-pager --output=short-iso 2>&1');
    if (!$result['ok'] && trim($result['stdout'] . $result['stderr']) === '') {
        return ['ok' => false, 'msg' => '服务日志读取失败'];
    }
    return ['ok' => true, 'lines' => preg_split('/\r?\n/', trim($result['stdout'] . "\n" . $result['stderr'])) ?: []];
}

function host_agent_network_overview(string $mode, int $limit = 120): array {
    $limit = max(10, min(300, $limit));
    $script = <<<'SH'
if command -v ss >/dev/null 2>&1; then
  listeners=$(ss -lntupH 2>/dev/null | head -n __LIMIT__)
  connections=$(ss -ntupH state established 2>/dev/null | head -n __LIMIT__)
else
  listeners=$(php -r '
function decode_addr($value){[$ip,$port]=explode(":",trim($value));$port=hexdec($port);if(strlen($ip)===8){$parts=str_split($ip,2);$parts=array_reverse($parts);$ip=implode(".",array_map("hexdec",$parts));return $ip.":".$port;}return $value;}
$files=["/proc/net/tcp","/proc/net/tcp6","/proc/net/udp","/proc/net/udp6"];$limit='__LIMIT__';$lines=[];foreach($files as $file){if(!is_file($file))continue;$raw=file($file, FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);if(!$raw)continue;array_shift($raw);foreach($raw as $line){$line=preg_replace("/^\s+/","",$line);$parts=preg_split("/\s+/",trim($line));if(count($parts)<4)continue;$state=$parts[3];if($state!=="0A")continue;$proto=str_contains($file,"udp")?"udp":"tcp";$lines[]=$proto." LISTEN 0 0 ".decode_addr($parts[1])." 0.0.0.0:* users:-";if(count($lines)>=$limit)break 2;}}echo implode("\n",$lines);
')
  connections=$(php -r '
function decode_addr($value){[$ip,$port]=explode(":",trim($value));$port=hexdec($port);if(strlen($ip)===8){$parts=str_split($ip,2);$parts=array_reverse($parts);$ip=implode(".",array_map("hexdec",$parts));return $ip.":".$port;}return $value;}
$files=["/proc/net/tcp","/proc/net/tcp6"];$limit='__LIMIT__';$lines=[];foreach($files as $file){if(!is_file($file))continue;$raw=file($file, FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);if(!$raw)continue;array_shift($raw);foreach($raw as $line){$line=preg_replace("/^\s+/","",$line);$parts=preg_split("/\s+/",trim($line));if(count($parts)<4)continue;$state=$parts[3];if($state!=="01")continue;$lines[]="tcp ESTAB 0 0 ".decode_addr($parts[1])." ".decode_addr($parts[2])." users:-";if(count($lines)>=$limit)break 2;}}echo implode("\n",$lines);
')
fi
echo "__LISTENERS__"
printf "%s\n" "$listeners"
echo "__CONNECTIONS__"
printf "%s\n" "$connections"
SH;
    $result = host_agent_runtime_shell($mode, str_replace('__LIMIT__', (string)$limit, $script));
    if (!$result['ok']) {
        return ['ok' => false, 'msg' => trim($result['stderr'] ?: $result['stdout'] ?: '网络信息读取失败')];
    }
    $listeners = [];
    $connections = [];
    $target = &$listeners;
    foreach (preg_split('/\r?\n/', trim($result['stdout'])) ?: [] as $line) {
        if ($line === '__LISTENERS__') {
            $target = &$listeners;
            continue;
        }
        if ($line === '__CONNECTIONS__') {
            $target = &$connections;
            continue;
        }
        if (trim($line) === '') {
            continue;
        }
        $target[] = $line;
    }
    return ['ok' => true, 'listeners' => $listeners, 'connections' => $connections];
}

function host_agent_sim_users_file(string $root): string {
    return rtrim($root, '/') . '/var/lib/host-agent/sim_users.json';
}

function host_agent_sim_groups_file(string $root): string {
    return rtrim($root, '/') . '/var/lib/host-agent/sim_groups.json';
}

function host_agent_sim_users_load(string $root): array {
    $path = host_agent_sim_users_file($root);
    if (!is_file($path)) {
        host_agent_ensure_parent_dir($path);
        $default = ['items' => [[
            'username' => 'root',
            'uid' => '0',
            'gid' => '0',
            'gecos' => 'root',
            'home' => '/root',
            'shell' => '/bin/sh',
            'locked' => false,
            'groups' => ['root'],
        ]]];
        file_put_contents($path, json_encode($default, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }
    $decoded = json_decode((string)file_get_contents($path), true);
    return is_array($decoded) ? $decoded : ['items' => []];
}

function host_agent_sim_users_save(string $root, array $data): void {
    $path = host_agent_sim_users_file($root);
    host_agent_ensure_parent_dir($path);
    file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

function host_agent_sim_groups_load(string $root): array {
    $path = host_agent_sim_groups_file($root);
    if (!is_file($path)) {
        host_agent_ensure_parent_dir($path);
        $default = ['items' => [[
            'groupname' => 'root',
            'gid' => '0',
            'members' => ['root'],
        ]]];
        file_put_contents($path, json_encode($default, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }
    $decoded = json_decode((string)file_get_contents($path), true);
    return is_array($decoded) ? $decoded : ['items' => []];
}

function host_agent_sim_groups_save(string $root, array $data): void {
    $path = host_agent_sim_groups_file($root);
    host_agent_ensure_parent_dir($path);
    file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

function host_agent_user_list(string $root, string $mode, string $keyword = ''): array {
    if ($mode !== 'host') {
        $users = array_values(array_filter((array)(host_agent_sim_users_load($root)['items'] ?? []), static fn($item) => is_array($item)));
        if ($keyword !== '') {
            $keywordLower = mb_strtolower($keyword);
            $users = array_values(array_filter($users, static function (array $item) use ($keywordLower): bool {
                return str_contains(mb_strtolower(implode(' ', [(string)($item['username'] ?? ''), (string)($item['home'] ?? ''), implode(',', (array)($item['groups'] ?? []))])), $keywordLower);
            }));
        }
        return ['ok' => true, 'items' => $users];
    }
    $result = host_agent_runtime_shell($mode, 'getent passwd | while IFS=: read -r name pass uid gid gecos home shell; do printf "%s\t%s\t%s\t%s\t%s\t%s\n" "$name" "$uid" "$gid" "$gecos" "$home" "$shell"; done');
    if (!$result['ok']) {
        return ['ok' => false, 'msg' => trim($result['stderr'] ?: $result['stdout'] ?: '用户列表读取失败')];
    }
    $items = host_agent_parse_table_output($result['stdout'], ['username', 'uid', 'gid', 'gecos', 'home', 'shell']);
    if ($keyword !== '') {
        $keywordLower = mb_strtolower($keyword);
        $items = array_values(array_filter($items, static function (array $item) use ($keywordLower): bool {
            return str_contains(mb_strtolower(implode(' ', $item)), $keywordLower);
        }));
    }
    return ['ok' => true, 'items' => $items];
}

function host_agent_group_list(string $root, string $mode, string $keyword = ''): array {
    if ($mode !== 'host') {
        $groups = array_values(array_filter((array)(host_agent_sim_groups_load($root)['items'] ?? []), static fn($item) => is_array($item)));
        if ($keyword !== '') {
            $keywordLower = mb_strtolower($keyword);
            $groups = array_values(array_filter($groups, static function (array $item) use ($keywordLower): bool {
                return str_contains(mb_strtolower(implode(' ', [(string)($item['groupname'] ?? ''), implode(',', (array)($item['members'] ?? []))])), $keywordLower);
            }));
        }
        return ['ok' => true, 'items' => $groups];
    }
    $result = host_agent_runtime_shell($mode, 'getent group | while IFS=: read -r name pass gid members; do printf "%s\t%s\t%s\n" "$name" "$gid" "$members"; done');
    if (!$result['ok']) {
        return ['ok' => false, 'msg' => trim($result['stderr'] ?: $result['stdout'] ?: '用户组列表读取失败')];
    }
    $items = host_agent_parse_table_output($result['stdout'], ['groupname', 'gid', 'members_csv']);
    foreach ($items as &$item) {
        $item['members'] = trim((string)($item['members_csv'] ?? '')) === '' ? [] : array_values(array_filter(array_map('trim', explode(',', (string)$item['members_csv']))));
        unset($item['members_csv']);
    }
    unset($item);
    if ($keyword !== '') {
        $keywordLower = mb_strtolower($keyword);
        $items = array_values(array_filter($items, static function (array $item) use ($keywordLower): bool {
            return str_contains(mb_strtolower(implode(' ', [(string)($item['groupname'] ?? ''), implode(',', (array)($item['members'] ?? []))])), $keywordLower);
        }));
    }
    return ['ok' => true, 'items' => $items];
}

function host_agent_user_save(string $root, string $mode, array $payload): array {
    $username = trim((string)($payload['username'] ?? ''));
    if ($username === '' || !preg_match('/^[a-z_][a-z0-9_-]*[$]?$/i', $username)) {
        return ['ok' => false, 'msg' => '用户名无效'];
    }
    $shell = trim((string)($payload['shell'] ?? '/bin/sh')) ?: '/bin/sh';
    $home = trim((string)($payload['home'] ?? ''));
    $groups = array_values(array_filter(array_map('trim', explode(',', (string)($payload['groups'] ?? ''))), static fn($item) => $item !== ''));
    $password = (string)($payload['password'] ?? '');
    if ($mode !== 'host') {
        $data = host_agent_sim_users_load($root);
        $items = [];
        $existing = null;
        foreach (($data['items'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            if (($item['username'] ?? '') === $username) {
                $existing = $item;
                continue;
            }
            $items[] = $item;
        }
        $record = [
            'username' => $username,
            'uid' => (string)($existing['uid'] ?? (1000 + count($items))),
            'gid' => (string)($existing['gid'] ?? (1000 + count($items))),
            'gecos' => (string)($payload['gecos'] ?? ($existing['gecos'] ?? '')),
            'home' => $home !== '' ? $home : ((string)($existing['home'] ?? ('/home/' . $username))),
            'shell' => $shell,
            'locked' => (bool)($existing['locked'] ?? false),
            'groups' => $groups,
        ];
        $items[] = $record;
        $data['items'] = array_values($items);
        host_agent_sim_users_save($root, $data);
        return ['ok' => true, 'msg' => 'simulate 用户已保存', 'user' => $record];
    }
    $exists = host_agent_runtime_shell($mode, 'id -u ' . escapeshellarg($username) . ' >/dev/null 2>&1');
    $script = $exists['ok']
        ? ('usermod -s ' . escapeshellarg($shell)
            . ($home !== '' ? (' -d ' . escapeshellarg($home)) : '')
            . ($groups ? (' -G ' . escapeshellarg(implode(',', $groups))) : '')
            . ' ' . escapeshellarg($username))
        : ('useradd -m -s ' . escapeshellarg($shell)
            . ($home !== '' ? (' -d ' . escapeshellarg($home)) : '')
            . ($groups ? (' -G ' . escapeshellarg(implode(',', $groups))) : '')
            . ' ' . escapeshellarg($username));
    $result = host_agent_runtime_shell($mode, $script . ' 2>&1');
    if (!$result['ok']) {
        return ['ok' => false, 'msg' => trim($result['stderr'] ?: $result['stdout'] ?: '用户保存失败')];
    }
    if ($password !== '') {
        $passResult = host_agent_runtime_shell($mode, 'chpasswd', $username . ':' . $password);
        if (!$passResult['ok']) {
            return ['ok' => false, 'msg' => trim($passResult['stderr'] ?: $passResult['stdout'] ?: '密码设置失败')];
        }
    }
    return ['ok' => true, 'msg' => '用户已保存'];
}

function host_agent_user_delete(string $root, string $mode, string $username, bool $removeHome = false): array {
    $username = trim($username);
    if ($username === '' || $username === 'root') {
        return ['ok' => false, 'msg' => '该用户不允许删除'];
    }
    if ($mode !== 'host') {
        $data = host_agent_sim_users_load($root);
        $before = count((array)($data['items'] ?? []));
        $data['items'] = array_values(array_filter((array)($data['items'] ?? []), static fn($item) => is_array($item) && ($item['username'] ?? '') !== $username));
        host_agent_sim_users_save($root, $data);
        return ['ok' => count($data['items']) < $before, 'msg' => count($data['items']) < $before ? 'simulate 用户已删除' : '用户不存在'];
    }
    $result = host_agent_runtime_shell($mode, 'userdel ' . ($removeHome ? '-r ' : '') . escapeshellarg($username) . ' 2>&1');
    return ['ok' => $result['ok'], 'msg' => $result['ok'] ? '用户已删除' : trim($result['stderr'] ?: $result['stdout'] ?: '用户删除失败')];
}

function host_agent_user_password(string $root, string $mode, string $username, string $password): array {
    $username = trim($username);
    if ($username === '' || $password === '') {
        return ['ok' => false, 'msg' => '用户名和密码不能为空'];
    }
    if ($mode !== 'host') {
        return ['ok' => true, 'msg' => 'simulate 模式已更新用户密码'];
    }
    $result = host_agent_runtime_shell($mode, 'chpasswd', $username . ':' . $password);
    return ['ok' => $result['ok'], 'msg' => $result['ok'] ? '用户密码已更新' : trim($result['stderr'] ?: $result['stdout'] ?: '密码更新失败')];
}

function host_agent_user_lock(string $root, string $mode, string $username, bool $locked): array {
    $username = trim($username);
    if ($username === '' || $username === 'root' && $locked) {
        return ['ok' => false, 'msg' => '该用户不允许执行此操作'];
    }
    if ($mode !== 'host') {
        $data = host_agent_sim_users_load($root);
        foreach (($data['items'] ?? []) as &$item) {
            if (is_array($item) && ($item['username'] ?? '') === $username) {
                $item['locked'] = $locked;
            }
        }
        unset($item);
        host_agent_sim_users_save($root, $data);
        return ['ok' => true, 'msg' => 'simulate 模式已' . ($locked ? '锁定' : '解锁') . '用户'];
    }
    $result = host_agent_runtime_shell($mode, 'passwd ' . ($locked ? '-l ' : '-u ') . escapeshellarg($username) . ' 2>&1');
    return ['ok' => $result['ok'], 'msg' => $result['ok'] ? ('用户已' . ($locked ? '锁定' : '解锁')) : trim($result['stderr'] ?: $result['stdout'] ?: '用户状态更新失败')];
}

function host_agent_group_save(string $root, string $mode, array $payload): array {
    $groupname = trim((string)($payload['groupname'] ?? ''));
    if ($groupname === '' || !preg_match('/^[a-z_][a-z0-9_-]*[$]?$/i', $groupname)) {
        return ['ok' => false, 'msg' => '用户组名无效'];
    }
    $members = array_values(array_filter(array_map('trim', explode(',', (string)($payload['members'] ?? ''))), static fn($item) => $item !== ''));
    if ($mode !== 'host') {
        $data = host_agent_sim_groups_load($root);
        $items = [];
        $existing = null;
        foreach (($data['items'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            if (($item['groupname'] ?? '') === $groupname) {
                $existing = $item;
                continue;
            }
            $items[] = $item;
        }
        $record = [
            'groupname' => $groupname,
            'gid' => (string)($existing['gid'] ?? (1000 + count($items))),
            'members' => $members,
        ];
        $items[] = $record;
        $data['items'] = array_values($items);
        host_agent_sim_groups_save($root, $data);
        return ['ok' => true, 'msg' => 'simulate 用户组已保存', 'group' => $record];
    }
    $exists = host_agent_runtime_shell($mode, 'getent group ' . escapeshellarg($groupname) . ' >/dev/null 2>&1');
    $result = $exists['ok']
        ? ['ok' => true, 'stdout' => '', 'stderr' => '']
        : host_agent_runtime_shell($mode, 'groupadd ' . escapeshellarg($groupname) . ' 2>&1');
    if (!$result['ok']) {
        return ['ok' => false, 'msg' => trim($result['stderr'] ?: $result['stdout'] ?: '用户组保存失败')];
    }
    foreach ($members as $member) {
        host_agent_runtime_shell($mode, 'usermod -a -G ' . escapeshellarg($groupname) . ' ' . escapeshellarg($member) . ' 2>&1');
    }
    return ['ok' => true, 'msg' => '用户组已保存'];
}

function host_agent_group_delete(string $root, string $mode, string $groupname): array {
    $groupname = trim($groupname);
    if ($groupname === '' || $groupname === 'root') {
        return ['ok' => false, 'msg' => '该用户组不允许删除'];
    }
    if ($mode !== 'host') {
        $data = host_agent_sim_groups_load($root);
        $before = count((array)($data['items'] ?? []));
        $data['items'] = array_values(array_filter((array)($data['items'] ?? []), static fn($item) => is_array($item) && ($item['groupname'] ?? '') !== $groupname));
        host_agent_sim_groups_save($root, $data);
        return ['ok' => count($data['items']) < $before, 'msg' => count($data['items']) < $before ? 'simulate 用户组已删除' : '用户组不存在'];
    }
    $result = host_agent_runtime_shell($mode, 'groupdel ' . escapeshellarg($groupname) . ' 2>&1');
    return ['ok' => $result['ok'], 'msg' => $result['ok'] ? '用户组已删除' : trim($result['stderr'] ?: $result['stdout'] ?: '用户组删除失败')];
}

function host_agent_docker_containers_list(bool $all = true): array {
    $result = host_agent_docker_request('GET', '/containers/json?all=' . ($all ? '1' : '0'));
    if (!$result['ok'] || !is_array($result['json'])) {
        return ['ok' => false, 'msg' => trim($result['error'] ?: $result['body'] ?: '容器列表读取失败')];
    }
    $items = [];
    foreach ($result['json'] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $items[] = [
            'id' => (string)($row['Id'] ?? ''),
            'name' => ltrim((string)(($row['Names'][0] ?? '')), '/'),
            'image' => (string)($row['Image'] ?? ''),
            'image_id' => (string)($row['ImageID'] ?? ''),
            'state' => (string)($row['State'] ?? ''),
            'status' => (string)($row['Status'] ?? ''),
            'created' => (int)($row['Created'] ?? 0),
            'ports' => array_values(array_filter(array_map(static function ($port): string {
                if (!is_array($port)) {
                    return '';
                }
                $private = (string)($port['PrivatePort'] ?? '');
                $public = (string)($port['PublicPort'] ?? '');
                $type = (string)($port['Type'] ?? '');
                return $public !== '' ? ($public . '->' . $private . '/' . $type) : ($private . '/' . $type);
            }, (array)($row['Ports'] ?? [])))),
        ];
    }
    return ['ok' => true, 'items' => $items];
}

function host_agent_docker_container_action(string $id, string $action): array {
    $id = trim($id);
    if ($id === '') {
        return ['ok' => false, 'msg' => '容器 ID 不能为空'];
    }
    $action = strtolower(trim($action));
    if (!in_array($action, ['start', 'stop', 'restart'], true)) {
        return ['ok' => false, 'msg' => '容器操作不支持'];
    }
    $result = host_agent_docker_request('POST', '/containers/' . rawurlencode($id) . '/' . $action);
    return [
        'ok' => $result['ok'] || ($action === 'start' && $result['status'] === 304),
        'msg' => ($result['ok'] || ($action === 'start' && $result['status'] === 304)) ? ('容器已执行 ' . $action) : trim($result['error'] ?: $result['body'] ?: '容器操作失败'),
    ];
}

function host_agent_docker_container_delete(string $id, bool $force = false): array {
    $id = trim($id);
    if ($id === '') {
        return ['ok' => false, 'msg' => '容器 ID 不能为空'];
    }
    $query = '?force=' . ($force ? '1' : '0');
    $result = host_agent_docker_request('DELETE', '/containers/' . rawurlencode($id) . $query);
    return [
        'ok' => $result['ok'],
        'msg' => $result['ok'] ? '容器已删除' : trim($result['error'] ?: $result['body'] ?: '容器删除失败'),
    ];
}

function host_agent_docker_container_logs(string $id, int $tail = 200): array {
    $id = trim($id);
    $tail = max(10, min(1000, $tail));
    if ($id === '') {
        return ['ok' => false, 'msg' => '容器 ID 不能为空'];
    }
    $result = host_agent_docker_request('GET', '/containers/' . rawurlencode($id) . '/logs?stdout=1&stderr=1&tail=' . $tail);
    if (!$result['ok']) {
        return ['ok' => false, 'msg' => trim($result['error'] ?: $result['body'] ?: '容器日志读取失败')];
    }
    $body = (string)$result['body'];
    if ($body !== '' && strlen($body) >= 8) {
        $offset = 0;
        $decoded = '';
        while ($offset + 8 <= strlen($body)) {
            $header = substr($body, $offset, 8);
            $size = unpack('N', substr($header, 4, 4));
            $frameSize = (int)($size[1] ?? 0);
            if ($frameSize <= 0 || $offset + 8 + $frameSize > strlen($body)) {
                $decoded = $body;
                break;
            }
            $decoded .= substr($body, $offset + 8, $frameSize);
            $offset += 8 + $frameSize;
        }
        if ($decoded !== '') {
            $body = $decoded;
        }
    }
    return ['ok' => true, 'lines' => preg_split('/\r?\n/', trim($body)) ?: []];
}

function host_agent_docker_container_inspect(string $id): array {
    $id = trim($id);
    if ($id === '') {
        return ['ok' => false, 'msg' => '容器 ID 不能为空'];
    }
    $result = host_agent_docker_request('GET', '/containers/' . rawurlencode($id) . '/json');
    if (!$result['ok'] || !is_array($result['json'])) {
        return ['ok' => false, 'msg' => trim($result['error'] ?: $result['body'] ?: '容器详情读取失败')];
    }
    return ['ok' => true, 'item' => $result['json']];
}

function host_agent_docker_container_stats(string $id): array {
    $id = trim($id);
    if ($id === '') {
        return ['ok' => false, 'msg' => '容器 ID 不能为空'];
    }
    $result = host_agent_docker_request('GET', '/containers/' . rawurlencode($id) . '/stats?stream=0');
    if (!$result['ok'] || !is_array($result['json'])) {
        return ['ok' => false, 'msg' => trim($result['error'] ?: $result['body'] ?: '容器资源读取失败')];
    }
    $stats = $result['json'];
    $cpuTotal = (float)($stats['cpu_stats']['cpu_usage']['total_usage'] ?? 0);
    $preCpuTotal = (float)($stats['precpu_stats']['cpu_usage']['total_usage'] ?? 0);
    $systemTotal = (float)($stats['cpu_stats']['system_cpu_usage'] ?? 0);
    $preSystemTotal = (float)($stats['precpu_stats']['system_cpu_usage'] ?? 0);
    $cpuDelta = $cpuTotal - $preCpuTotal;
    $systemDelta = $systemTotal - $preSystemTotal;
    $onlineCpus = max(1, (int)($stats['cpu_stats']['online_cpus'] ?? 1));
    $cpuPercent = $systemDelta > 0 && $cpuDelta > 0 ? ($cpuDelta / $systemDelta) * $onlineCpus * 100 : 0;
    $memoryUsage = (int)($stats['memory_stats']['usage'] ?? 0);
    $memoryLimit = (int)($stats['memory_stats']['limit'] ?? 0);
    $memoryPercent = $memoryLimit > 0 ? ($memoryUsage / $memoryLimit) * 100 : 0;
    return ['ok' => true, 'item' => [
        'cpu_percent' => round($cpuPercent, 2),
        'memory_usage' => $memoryUsage,
        'memory_limit' => $memoryLimit,
        'memory_percent' => round($memoryPercent, 2),
    ]];
}

function host_agent_docker_images_list(): array {
    $result = host_agent_docker_request('GET', '/images/json');
    if (!$result['ok'] || !is_array($result['json'])) {
        return ['ok' => false, 'msg' => trim($result['error'] ?: $result['body'] ?: '镜像列表读取失败')];
    }
    $items = [];
    foreach ($result['json'] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $items[] = [
            'id' => (string)($row['Id'] ?? ''),
            'tags' => array_values(array_filter((array)($row['RepoTags'] ?? []), static fn($item) => is_string($item))),
            'size' => (int)($row['Size'] ?? 0),
            'created' => (int)($row['Created'] ?? 0),
        ];
    }
    return ['ok' => true, 'items' => $items];
}

function host_agent_docker_volumes_list(): array {
    $result = host_agent_docker_request('GET', '/volumes');
    if (!$result['ok'] || !is_array($result['json'])) {
        return ['ok' => false, 'msg' => trim($result['error'] ?: $result['body'] ?: '卷列表读取失败')];
    }
    $items = [];
    foreach ((array)($result['json']['Volumes'] ?? []) as $row) {
        if (!is_array($row)) {
            continue;
        }
        $items[] = [
            'name' => (string)($row['Name'] ?? ''),
            'driver' => (string)($row['Driver'] ?? ''),
            'mountpoint' => (string)($row['Mountpoint'] ?? ''),
            'scope' => (string)($row['Scope'] ?? ''),
        ];
    }
    return ['ok' => true, 'items' => $items];
}

function host_agent_docker_networks_list(): array {
    $result = host_agent_docker_request('GET', '/networks');
    if (!$result['ok'] || !is_array($result['json'])) {
        return ['ok' => false, 'msg' => trim($result['error'] ?: $result['body'] ?: '网络列表读取失败')];
    }
    $items = [];
    foreach ($result['json'] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $containers = is_array($row['Containers'] ?? null) ? array_values($row['Containers']) : [];
        $items[] = [
            'id' => (string)($row['Id'] ?? ''),
            'name' => (string)($row['Name'] ?? ''),
            'driver' => (string)($row['Driver'] ?? ''),
            'scope' => (string)($row['Scope'] ?? ''),
            'containers_count' => count($containers),
        ];
    }
    return ['ok' => true, 'items' => $items];
}

function host_agent_compose_scan(array $scanDirs = []): array {
    if (empty($scanDirs)) {
        $scanDirs = ['/opt', '/home', '/root', '/var/www', '/srv', '/data'];
    }
    $composeFiles = [];
    $seen = [];
    foreach ($scanDirs as $dir) {
        if (!is_dir($dir)) continue;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $file) {
            if (!$file->isFile()) continue;
            $name = strtolower($file->getFilename());
            if ($name !== 'docker-compose.yml' && $name !== 'compose.yaml' && $name !== 'docker-compose.yaml') continue;
            $realPath = $file->getRealPath();
            if (isset($seen[$realPath])) continue;
            $seen[$realPath] = true;
            // 限制扫描深度，避免遍历过大目录
            $depth = substr_count(substr($realPath, strlen($dir)), DIRECTORY_SEPARATOR);
            if ($depth > 4) continue;
            $composeFiles[] = $realPath;
        }
    }
    return array_values($composeFiles);
}

function host_agent_compose_status(string $composeFile): array {
    $dir = dirname($composeFile);
    $cmd = 'cd ' . escapeshellarg($dir) . ' && docker compose -f ' . escapeshellarg(basename($composeFile)) . ' ps --format json 2>&1';
    $result = host_agent_host_shell($cmd);
    $items = [];
    if ($result['ok'] && $result['code'] === 0) {
        $lines = array_filter(array_map('trim', explode("\n", $result['stdout'])));
        foreach ($lines as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $items[] = $decoded;
            }
        }
    }
    // 判断整体状态
    $status = 'unknown';
    if (!empty($items)) {
        $running = 0;
        $total = count($items);
        foreach ($items as $item) {
            $state = strtolower((string)($item['State'] ?? $item['Status'] ?? ''));
            if (strpos($state, 'running') !== false) {
                $running++;
            }
        }
        if ($running === $total) {
            $status = 'running';
        } elseif ($running === 0) {
            $status = 'stopped';
        } else {
            $status = 'partial';
        }
    }
    return [
        'ok' => true,
        'file' => $composeFile,
        'dir' => $dir,
        'name' => basename($dir),
        'status' => $status,
        'services' => $items,
    ];
}

function host_agent_compose_list(): array {
    $files = host_agent_compose_scan();
    $stacks = [];
    foreach ($files as $file) {
        $stacks[] = host_agent_compose_status($file);
    }
    usort($stacks, static function(array $a, array $b): int {
        return strcmp((string)$a['name'], (string)$b['name']);
    });
    return ['ok' => true, 'items' => $stacks];
}

function host_agent_compose_action(string $composeFile, string $action): array {
    $allowed = ['up', 'down', 'pull', 'restart'];
    if (!in_array($action, $allowed, true)) {
        return ['ok' => false, 'msg' => '不支持的 Compose 操作，支持: ' . implode(', ', $allowed)];
    }
    $dir = dirname($composeFile);
    $extra = '';
    if ($action === 'up') {
        $extra = ' -d';
    }
    $cmd = 'cd ' . escapeshellarg($dir) . ' && docker compose -f ' . escapeshellarg(basename($composeFile)) . ' ' . $action . $extra . ' 2>&1';
    $result = host_agent_host_shell($cmd);
    $ok = $result['ok'] && $result['code'] === 0;
    return [
        'ok' => $ok,
        'msg' => $ok ? ('Compose ' . $action . ' 完成') : ('Compose ' . $action . ' 失败: ' . trim($result['stderr'] ?: $result['stdout'])),
        'output' => trim($result['stdout'] ?: ''),
    ];
}

function host_agent_docker_summary(): array {
    if (!host_agent_docker_available()) {
        return ['ok' => false, 'msg' => 'docker.sock 不可用'];
    }
    $version = host_agent_docker_request('GET', '/version');
    if (!$version['ok'] || !is_array($version['json'])) {
        return ['ok' => false, 'msg' => trim($version['error'] ?: $version['body'] ?: 'Docker 信息读取失败')];
    }
    $info = host_agent_docker_request('GET', '/info');
    if (!$info['ok'] || !is_array($info['json'])) {
        return ['ok' => false, 'msg' => trim($info['error'] ?: $info['body'] ?: 'Docker 信息读取失败')];
    }
    $versionJson = $version['json'];
    $infoJson = $info['json'];
    return ['ok' => true, 'data' => [
        'server_version' => (string)($versionJson['Version'] ?? ''),
        'api_version' => (string)($versionJson['ApiVersion'] ?? ''),
        'os' => (string)($infoJson['OperatingSystem'] ?? ''),
        'kernel' => (string)($infoJson['KernelVersion'] ?? ''),
        'architecture' => (string)($infoJson['Architecture'] ?? ''),
        'containers' => (int)($infoJson['Containers'] ?? 0),
        'containers_running' => (int)($infoJson['ContainersRunning'] ?? 0),
        'containers_paused' => (int)($infoJson['ContainersPaused'] ?? 0),
        'containers_stopped' => (int)($infoJson['ContainersStopped'] ?? 0),
        'images' => (int)($infoJson['Images'] ?? 0),
        'driver' => (string)($infoJson['Driver'] ?? ''),
        'mem_total' => (int)($infoJson['MemTotal'] ?? 0),
        'ncpu' => (int)($infoJson['NCPU'] ?? 0),
        'name' => (string)($infoJson['Name'] ?? ''),
    ]];
}

function host_agent_target_from_payload(array $payload): array {
    $target = is_array($payload['target'] ?? null) ? $payload['target'] : ['type' => 'local'];
    $type = (string)($target['type'] ?? 'local');
    if ($type === 'remote') {
        return $target;
    }
    return ['type' => 'local'];
}

function host_agent_ssh_target_status(array $target, string $root, string $mode): array {
    return ($target['type'] ?? 'local') === 'remote'
        ? host_agent_remote_ssh_status($target)
        : host_agent_live_ssh_status($root, $mode);
}

function host_agent_ssh_target_config_read(array $target, string $root, string $mode): array {
    if (($target['type'] ?? 'local') === 'remote') {
        return host_agent_remote_ssh_config_read($target);
    }
    $current = host_agent_read_ssh_config_payload($root, $mode);
    if (empty($current['ok'])) {
        return $current;
    }
    return $current + ['structured' => host_agent_parse_ssh_options((string)($current['content'] ?? ''))];
}

function host_agent_ssh_target_validate(array $target, string $root, string $mode, string $content): array {
    return ($target['type'] ?? 'local') === 'remote'
        ? host_agent_remote_ssh_validate($target, $content)
        : host_agent_validate_ssh_config($root, $mode, $content);
}

function host_agent_ssh_target_config_save(array $target, string $root, string $mode, string $content): array {
    $validation = host_agent_ssh_target_validate($target, $root, $mode, $content);
    if (empty($validation['ok'])) {
        return $validation;
    }
    $current = host_agent_ssh_target_config_read($target, $root, $mode);
    $oldContent = !empty($current['ok']) ? (string)($current['content'] ?? '') : '';
    return ($target['type'] ?? 'local') === 'remote'
        ? host_agent_ssh_apply_result($oldContent, $content, host_agent_remote_ssh_config_save($target, $content))
        : host_agent_ssh_apply_result($oldContent, $content, host_agent_save_ssh_config($root, $mode, $content));
}

function host_agent_ssh_target_structured_save(array $target, string $root, string $mode, array $payload): array {
    $current = host_agent_ssh_target_config_read($target, $root, $mode);
    if (empty($current['ok'])) {
        return $current;
    }
    $content = host_agent_apply_structured_ssh_options((string)($current['content'] ?? ''), $payload);
    $result = host_agent_ssh_target_config_save($target, $root, $mode, $content);
    if (!empty($result['ok'])) {
        $result['structured'] = host_agent_parse_ssh_options($content);
    }
    return $result;
}

function host_agent_ssh_target_restore_last_backup(array $target, string $root, string $mode): array {
    return ($target['type'] ?? 'local') === 'remote'
        ? host_agent_remote_ssh_restore_last_backup($target)
        : host_agent_restore_last_ssh_backup($root, $mode);
}

function host_agent_ssh_target_service_action(array $target, string $root, string $mode, string $action): array {
    return ($target['type'] ?? 'local') === 'remote'
        ? host_agent_remote_ssh_service_action($target, $action)
        : host_agent_ssh_service_action($root, $mode, $action);
}

function host_agent_ssh_target_enable_toggle(array $target, string $root, string $mode, bool $enabled): array {
    return ($target['type'] ?? 'local') === 'remote'
        ? host_agent_remote_ssh_enable_toggle($target, $enabled)
        : host_agent_ssh_enable_toggle($root, $mode, $enabled);
}

function host_agent_ssh_target_install(array $target, string $root, string $mode): array {
    return ($target['type'] ?? 'local') === 'remote'
        ? host_agent_remote_install_ssh_service($target)
        : host_agent_install_ssh_service($root, $mode);
}

function host_agent_ssh_target_apply(array $target, string $root, string $mode, string $content, bool $restartAfterSave, bool $rollbackOnFailure): array {
    $save = host_agent_ssh_target_config_save($target, $root, $mode, $content);
    if (empty($save['ok']) || !$restartAfterSave) {
        return $save;
    }
    $restart = host_agent_ssh_target_service_action($target, $root, $mode, 'restart');
    $save['restart_result'] = $restart;
    if (!empty($restart['ok'])) {
        $save['msg'] = 'SSH 配置已保存并完成重启';
        return $save;
    }
    if ($rollbackOnFailure) {
        $restore = host_agent_ssh_target_restore_last_backup($target, $root, $mode);
        $save['rollback_result'] = $restore;
        if (!empty($restore['ok'])) {
            $retry = host_agent_ssh_target_service_action($target, $root, $mode, 'restart');
            $save['rollback_restart_result'] = $retry;
            $save['msg'] = 'SSH 重启失败，已自动回滚到最近备份';
        } else {
            $save['msg'] = 'SSH 重启失败，且自动回滚失败';
        }
    } else {
        $save['msg'] = 'SSH 配置已保存，但服务重启失败';
    }
    $save['ok'] = false;
    return $save;
}

// ============================================================
// Async Task Queue
// ============================================================

function host_agent_task_generate_id(): string {
    return 'task_' . bin2hex(random_bytes(8));
}

function host_agent_task_state_path(string $taskId): string {
    return '/tmp/host-agent-task-' . $taskId . '.json';
}

function host_agent_task_log_path(string $taskId): string {
    return '/tmp/host-agent-task-' . $taskId . '.log';
}

function host_agent_task_cleanup(): void {
    $queue = &$GLOBALS['TASK_QUEUE'];
    if (!is_array($queue)) {
        $queue = [];
        return;
    }
    $now = time();
    $ttl = 3600; // 1 小时
    foreach ($queue as $id => $task) {
        if (!is_array($task)) {
            unset($queue[$id]);
            continue;
        }
        $completedAt = (int)($task['completed_at'] ?? 0);
        if ($completedAt > 0 && ($now - $completedAt) > $ttl) {
            unset($queue[$id]);
            @unlink(host_agent_task_state_path($id));
            @unlink(host_agent_task_log_path($id));
        }
    }
}

function host_agent_task_submit(string $action, array $payload): array {
    if (!function_exists('pcntl_fork')) {
        // pcntl 不可用，降级为同步执行
        $taskId = host_agent_task_generate_id();
        $result = host_agent_execute_action($action, $payload);
        $stateFile = host_agent_task_state_path($taskId);
        file_put_contents($stateFile, json_encode([
            'status' => empty($result['ok']) ? 'failed' : 'completed',
            'completed_at' => time(),
            'result' => $result,
        ], JSON_UNESCAPED_UNICODE));
        return [
            'ok' => true,
            'task_id' => $taskId,
            'status' => 'completed',
            'sync' => true,
            'result' => $result,
        ];
    }

    $queue = &$GLOBALS['TASK_QUEUE'];
    if (!is_array($queue)) {
        $queue = [];
    }
    host_agent_task_cleanup();

    $taskId = host_agent_task_generate_id();
    $queue[$taskId] = [
        'id' => $taskId,
        'status' => 'pending',
        'action' => $action,
        'payload' => $payload,
        'result' => null,
        'output' => '',
        'started_at' => null,
        'completed_at' => null,
        'pid' => null,
    ];

    $pid = pcntl_fork();
    if ($pid === -1) {
        // fork 失败，降级为同步执行
        $result = host_agent_execute_action($action, $payload);
        $queue[$taskId]['status'] = 'completed';
        $queue[$taskId]['result'] = $result;
        $queue[$taskId]['completed_at'] = time();
        return [
            'ok' => true,
            'task_id' => $taskId,
            'status' => 'completed',
            'sync' => true,
            'result' => $result,
        ];
    }

    if ($pid === 0) {
        // 子进程
        $logFile = host_agent_task_log_path($taskId);
        $stateFile = host_agent_task_state_path($taskId);
        $startTime = time();

        // 记录开始状态
        file_put_contents($stateFile, json_encode([
            'status' => 'running',
            'started_at' => $startTime,
        ], JSON_UNESCAPED_UNICODE));

        // 执行操作，捕获输出
        ob_start();
        $result = host_agent_execute_action($action, $payload);
        $output = ob_get_clean();

        // 写入日志和结果
        file_put_contents($logFile, $output);
        file_put_contents($stateFile, json_encode([
            'status' => empty($result['ok']) ? 'failed' : 'completed',
            'started_at' => $startTime,
            'completed_at' => time(),
            'result' => $result,
        ], JSON_UNESCAPED_UNICODE));
        exit(0);
    }

    // 父进程
    $queue[$taskId]['status'] = 'running';
    $queue[$taskId]['pid'] = $pid;
    $queue[$taskId]['started_at'] = time();

    return [
        'ok' => true,
        'task_id' => $taskId,
        'status' => 'running',
    ];
}

function host_agent_task_status(string $taskId): array {
    $queue = &$GLOBALS['TASK_QUEUE'];
    if (!is_array($queue) || !isset($queue[$taskId])) {
        // 尝试从文件读取（可能进程已退出，内存数据已丢失）
        $stateFile = host_agent_task_state_path($taskId);
        if (file_exists($stateFile)) {
            $state = json_decode(file_get_contents($stateFile), true);
            if (is_array($state)) {
                $logFile = host_agent_task_log_path($taskId);
                $output = file_exists($logFile) ? file_get_contents($logFile) : '';
                $result = $state['result'] ?? null;
                return [
                    'ok' => true,
                    'task_id' => $taskId,
                    'status' => $state['status'] ?? 'unknown',
                    'started_at' => $state['started_at'] ?? null,
                    'completed_at' => $state['completed_at'] ?? null,
                    'output' => $output,
                    'result' => $result,
                ];
            }
        }
        return ['ok' => false, 'msg' => '任务不存在'];
    }

    $task = $queue[$taskId];
    $stateFile = host_agent_task_state_path($taskId);
    $logFile = host_agent_task_log_path($taskId);

    // 如果任务在运行中，检查子进程是否已退出
    if ($task['status'] === 'running' && !empty($task['pid'])) {
        $status = 0;
        $waited = pcntl_waitpid($task['pid'], $status, WNOHANG);
        if ($waited === $task['pid'] || $waited === -1) {
            // 子进程已退出
            $task['status'] = 'completed';
            $task['completed_at'] = time();
            if (file_exists($stateFile)) {
                $state = json_decode(file_get_contents($stateFile), true);
                if (is_array($state)) {
                    $task['status'] = $state['status'] ?? 'completed';
                    $task['result'] = $state['result'] ?? null;
                }
            }
            $queue[$taskId] = $task;
        }
    }

    $output = file_exists($logFile) ? file_get_contents($logFile) : '';
    $result = $task['result'];
    if ($result === null && file_exists($stateFile)) {
        $state = json_decode(file_get_contents($stateFile), true);
        if (is_array($state) && isset($state['result'])) {
            $result = $state['result'];
        }
    }

    return [
        'ok' => true,
        'task_id' => $taskId,
        'status' => $task['status'],
        'action' => $task['action'] ?? '',
        'started_at' => $task['started_at'] ?? null,
        'completed_at' => $task['completed_at'] ?? null,
        'output' => $output,
        'result' => $result,
    ];
}

function host_agent_task_cancel(string $taskId): array {
    $queue = &$GLOBALS['TASK_QUEUE'];
    if (!is_array($queue) || !isset($queue[$taskId])) {
        return ['ok' => false, 'msg' => '任务不存在'];
    }

    $task = $queue[$taskId];
    if ($task['status'] !== 'running' || empty($task['pid'])) {
        return ['ok' => false, 'msg' => '任务未在运行中'];
    }

    posix_kill($task['pid'], SIGTERM);
    usleep(200000); // 200ms 等待优雅退出
    $status = 0;
    $waited = pcntl_waitpid($task['pid'], $status, WNOHANG);
    if ($waited === 0) {
        posix_kill($task['pid'], SIGKILL);
        pcntl_waitpid($task['pid'], $status);
    }

    $queue[$taskId]['status'] = 'cancelled';
    $queue[$taskId]['completed_at'] = time();

    $stateFile = host_agent_task_state_path($taskId);
    file_put_contents($stateFile, json_encode([
        'status' => 'cancelled',
        'completed_at' => time(),
    ], JSON_UNESCAPED_UNICODE));

    return ['ok' => true, 'msg' => '任务已取消'];
}

function host_agent_task_list(): array {
    $queue = &$GLOBALS['TASK_QUEUE'];
    if (!is_array($queue)) {
        return ['ok' => true, 'tasks' => []];
    }
    $tasks = [];
    foreach ($queue as $id => $task) {
        if (!is_array($task)) continue;
        $tasks[] = [
            'id' => $id,
            'status' => $task['status'] ?? 'unknown',
            'action' => $task['action'] ?? '',
            'started_at' => $task['started_at'] ?? null,
            'completed_at' => $task['completed_at'] ?? null,
        ];
    }
    // 按开始时间倒序
    usort($tasks, static function (array $a, array $b): int {
        return ($b['started_at'] ?? 0) <=> ($a['started_at'] ?? 0);
    });
    return ['ok' => true, 'tasks' => $tasks];
}

function host_agent_execute_action(string $action, array $payload): array {
    $manager = host_agent_detect_package_manager();
    $svcManager = host_agent_detect_service_manager();

    switch ($action) {
        case 'package_install':
            $pkg = trim((string)($payload['pkg'] ?? ''));
            if ($pkg === '') return ['ok' => false, 'msg' => '缺少 pkg 参数'];
            $resolved = host_agent_resolve_package_name($pkg, $manager);
            if ($resolved === null) return ['ok' => false, 'msg' => '包名无法解析'];
            return host_agent_package_install($manager, $resolved);

        case 'package_remove':
            $pkg = trim((string)($payload['pkg'] ?? ''));
            $purge = !empty($payload['purge']);
            if ($pkg === '') return ['ok' => false, 'msg' => '缺少 pkg 参数'];
            $resolved = host_agent_resolve_package_name($pkg, $manager);
            if ($resolved === null) return ['ok' => false, 'msg' => '包名无法解析'];
            return host_agent_package_remove($manager, $resolved, $purge);

        case 'package_update':
            $pkg = trim((string)($payload['pkg'] ?? ''));
            if ($pkg === '') return ['ok' => false, 'msg' => '缺少 pkg 参数'];
            $resolved = host_agent_resolve_package_name($pkg, $manager);
            if ($resolved === null) return ['ok' => false, 'msg' => '包名无法解析'];
            return host_agent_package_update($manager, $resolved);

        case 'package_upgrade_all':
            return host_agent_package_upgrade_all($manager);

        case 'config_apply':
            $configId = trim((string)($payload['config_id'] ?? ''));
            $content = (string)($payload['content'] ?? '');
            $validateOnly = !empty($payload['validate_only']);
            return host_agent_config_apply($configId, $manager, $content, $validateOnly);

        case 'config_restore':
            $configId = trim((string)($payload['config_id'] ?? ''));
            $backupPath = trim((string)($payload['backup_path'] ?? ''));
            return host_agent_config_restore($configId, $manager, $backupPath);

        case 'manifest_apply':
            $manifest = is_array($payload['manifest'] ?? null) ? $payload['manifest'] : [];
            return host_agent_manifest_apply($manifest, false);

        case 'manifest_dry_run':
            $manifest = is_array($payload['manifest'] ?? null) ? $payload['manifest'] : [];
            return host_agent_manifest_apply($manifest, true);

        case 'download':
            $url = trim((string)($payload['url'] ?? ''));
            $destDir = trim((string)($payload['dest_dir'] ?? '/tmp'));
            $filename = trim((string)($payload['filename'] ?? ''));
            $root = trim((string)($payload['root'] ?? '/hostfs'));
            if ($url === '') return ['ok' => false, 'msg' => '缺少下载 URL'];
            if ($filename === '') {
                $filename = basename(parse_url($url, PHP_URL_PATH) ?: 'download');
            }
            $destDirResolved = host_agent_safe_local_path($root, $destDir);
            if (empty($destDirResolved['ok'])) return $destDirResolved;
            $destDirReal = (string)$destDirResolved['path'];
            $destPath = rtrim($destDirReal, '/') . '/' . $filename;
            host_agent_ensure_parent_dir($destPath);
            // 检测可用下载工具（优先 aria2）
            $aria2Check = host_agent_host_shell('command -v aria2c >/dev/null 2>&1 && echo yes || echo no');
            $wgetCheck = host_agent_host_shell('command -v wget >/dev/null 2>&1 && echo yes || echo no');
            $curlCheck = host_agent_host_shell('command -v curl >/dev/null 2>&1 && echo yes || echo no');
            if (trim($aria2Check['stdout'] ?? '') === 'yes') {
                // aria2 支持断点续传和多线程，输出简洁进度
                $cmd = 'aria2c -c -x 4 -s 4 --file-allocation=none --summary-interval=5 --console-log-level=warn -d ' . escapeshellarg($destDirReal) . ' -o ' . escapeshellarg($filename) . ' ' . escapeshellarg($url) . ' 2>&1';
            } elseif (trim($wgetCheck['stdout'] ?? '') === 'yes') {
                $cmd = 'wget -c --progress=dot:giga -O ' . escapeshellarg($destPath) . ' ' . escapeshellarg($url) . ' 2>&1';
            } elseif (trim($curlCheck['stdout'] ?? '') === 'yes') {
                $cmd = 'curl -C - -L --progress-bar -o ' . escapeshellarg($destPath) . ' ' . escapeshellarg($url) . ' 2>&1';
            } else {
                return ['ok' => false, 'msg' => '宿主机未安装 aria2、wget 或 curl，无法下载'];
            }
            $result = host_agent_host_shell($cmd);
            $ok = $result['ok'] && file_exists($destPath);
            return [
                'ok' => $ok,
                'msg' => $ok ? ('下载完成: ' . host_agent_local_display_path($root, $destPath)) : ('下载失败: ' . trim($result['stderr'] ?: $result['stdout'])),
                'dest_path' => host_agent_local_display_path($root, $destPath),
                'url' => $url,
            ];

        case 'archive_extract':
            $path = trim((string)($payload['path'] ?? ''));
            $destDir = trim((string)($payload['dest_dir'] ?? ''));
            $root = trim((string)($payload['root'] ?? '/hostfs'));
            return host_agent_archive_extract($root, $path, $destDir);

        case 'archive_compress':
            $paths = (array)($payload['paths'] ?? []);
            $destPath = trim((string)($payload['dest_path'] ?? ''));
            $format = trim((string)($payload['format'] ?? 'tar.gz'));
            $root = trim((string)($payload['root'] ?? '/hostfs'));
            return host_agent_archive_compress($root, $paths, $destPath, $format);

        default:
            return ['ok' => false, 'msg' => '未知 action: ' . $action];
    }
}

function host_agent_handle_request(string $request, string $token, string $root, string $mode): string {
    $parts = preg_split("/\r\n\r\n/", $request, 2);
    $header_lines = preg_split("/\r\n/", (string)($parts[0] ?? '')) ?: [];
    $request_line = trim((string)array_shift($header_lines));
    if ($request_line === '') {
        return host_agent_json_response(['ok' => false, 'msg' => 'empty request'], 500);
    }

    [$method, $target] = array_pad(explode(' ', $request_line, 3), 2, '');
    $body = (string)($parts[1] ?? '');
    $headers = [];
    foreach ($header_lines as $line) {
        $pos = strpos($line, ':');
        if ($pos === false) {
            continue;
        }
        $name = strtolower(trim(substr($line, 0, $pos)));
        $value = trim(substr($line, $pos + 1));
        $headers[$name] = $value;
    }

    if ($token !== '') {
        $provided = trim((string)($headers['x-host-agent-token'] ?? ''));
        if (!hash_equals($token, $provided)) {
            return host_agent_json_response(['ok' => false, 'msg' => 'invalid token'], 401);
        }
    }

    $method = strtoupper($method);
    $path = (string)parse_url($target, PHP_URL_PATH);
    $query = host_agent_parse_query_params($target);

    if ($method === 'GET' && ($path === '/health' || $path === '/meta')) {
        return host_agent_json_response([
            'ok' => true,
            'data' => [
                'service' => 'host-agent',
                'mode' => $mode,
                'root' => $root,
                'hostname' => gethostname() ?: '',
                'time' => date('Y-m-d H:i:s'),
            ],
        ]);
    }

    if ($method === 'GET' && $path === '/system/overview') {
        $result = host_agent_system_overview($root, $mode);
        return host_agent_json_response($result, !empty($result['ok']) ? 200 : 422);
    }

    if ($method === 'POST' && $path === '/process/list') {
        $payload = host_agent_json_decode($body);
        $result = host_agent_process_list(
            $mode,
            trim((string)($payload['keyword'] ?? '')),
            trim((string)($payload['sort'] ?? 'cpu')),
            (int)($payload['limit'] ?? 100)
        );
        return host_agent_json_response($result, !empty($result['ok']) ? 200 : 422);
    }

    if ($method === 'POST' && $path === '/process/kill') {
        $payload = host_agent_json_decode($body);
        $result = host_agent_process_kill(
            $mode,
            (int)($payload['pid'] ?? 0),
            trim((string)($payload['signal'] ?? 'TERM'))
        );
        return host_agent_json_response($result, !empty($result['ok']) ? 200 : 422);
    }

    if ($method === 'POST' && $path === '/service/list') {
        $payload = host_agent_json_decode($body);
        $result = host_agent_service_list(
            $root,
            $mode,
            trim((string)($payload['keyword'] ?? '')),
            (int)($payload['limit'] ?? 120)
        );
        return host_agent_json_response($result, !empty($result['ok']) ? 200 : 422);
    }

    if ($method === 'POST' && $path === '/service/action') {
        $payload = host_agent_json_decode($body);
        $result = host_agent_service_action_generic(
            $root,
            $mode,
            trim((string)($payload['service'] ?? '')),
            trim((string)($payload['service_action'] ?? ''))
        );
        return host_agent_json_response($result, !empty($result['ok']) ? 200 : 422);
    }

    if ($method === 'POST' && $path === '/service/logs') {
        $payload = host_agent_json_decode($body);
        $result = host_agent_service_logs(
            $root,
            $mode,
            trim((string)($payload['service'] ?? '')),
            (int)($payload['limit'] ?? 120)
        );
        return host_agent_json_response($result, !empty($result['ok']) ? 200 : 422);
    }

    if ($method === 'POST' && $path === '/network/overview') {
        $payload = host_agent_json_decode($body);
        $result = host_agent_network_overview($mode, (int)($payload['limit'] ?? 120));
        return host_agent_json_response($result, !empty($result['ok']) ? 200 : 422);
    }

    if ($method === 'POST' && $path === '/user/list') {
        $payload = host_agent_json_decode($body);
        $result = host_agent_user_list($root, $mode, trim((string)($payload['keyword'] ?? '')));
        return host_agent_json_response($result, !empty($result['ok']) ? 200 : 422);
    }

    if ($method === 'POST' && $path === '/user/save') {
        $payload = host_agent_json_decode($body);
        $result = host_agent_user_save($root, $mode, $payload);
        return host_agent_json_response($result, !empty($result['ok']) ? 200 : 422);
    }

    if ($method === 'POST' && $path === '/user/delete') {
        $payload = host_agent_json_decode($body);
        $result = host_agent_user_delete($root, $mode, trim((string)($payload['username'] ?? '')), !empty($payload['remove_home']));
        return host_agent_json_response($result, !empty($result['ok']) ? 200 : 422);
    }

    if ($method === 'POST' && $path === '/user/password') {
        $payload = host_agent_json_decode($body);
        $result = host_agent_user_password($root, $mode, trim((string)($payload['username'] ?? '')), (string)($payload['password'] ?? ''));
        return host_agent_json_response($result, !empty($result['ok']) ? 200 : 422);
    }

    if ($method === 'POST' && $path === '/user/lock') {
        $payload = host_agent_json_decode($body);
        $result = host_agent_user_lock($root, $mode, trim((string)($payload['username'] ?? '')), !empty($payload['locked']));
        return host_agent_json_response($result, !empty($result['ok']) ? 200 : 422);
    }

    if ($method === 'POST' && $path === '/group/list') {
        $payload = host_agent_json_decode($body);
        $result = host_agent_group_list($root, $mode, trim((string)($payload['keyword'] ?? '')));
        return host_agent_json_response($result, !empty($result['ok']) ? 200 : 422);
    }

    if ($method === 'POST' && $path === '/group/save') {
        $payload = host_agent_json_decode($body);
        $result = host_agent_group_save($root, $mode, $payload);
        return host_agent_json_response($result, !empty($result['ok']) ? 200 : 422);
    }

    if ($method === 'POST' && $path === '/group/delete') {
        $payload = host_agent_json_decode($body);
        $result = host_agent_group_delete($root, $mode, trim((string)($payload['groupname'] ?? '')));
        return host_agent_json_response($result, !empty($result['ok']) ? 200 : 422);
    }

    if ($method === 'GET' && $path === '/share/sftp/status') {
        $result = host_agent_sftp_status($root, $mode);
        return host_agent_json_response($result, !empty($result['ok']) ? 200 : 422);
    }

    if ($method === 'GET' && $path === '/share/sftp/policies') {
        $result = host_agent_sftp_policy_list($root, $mode);
        return host_agent_json_response($result, !empty($result['ok']) ? 200 : 422);
    }

    if ($method === 'POST' && $path === '/share/sftp/policy/save') {
        $payload = host_agent_json_decode($body);
        $result = host_agent_sftp_policy_save($root, $mode, $payload);
        return host_agent_json_response($result, !empty($result['ok']) ? 200 : 422);
    }

    if ($method === 'POST' && $path === '/share/sftp/policy/delete') {
        $payload = host_agent_json_decode($body);
        $result = host_agent_sftp_policy_delete($root, $mode, trim((string)($payload['username'] ?? '')));
        return host_agent_json_response($result, !empty($result['ok']) ? 200 : 422);
    }

    if ($method === 'GET' && $path === '/share/smb/status') {
        $result = host_agent_smb_status($root, $mode);
        return host_agent_json_response($result, !empty($result['ok']) ? 200 : 422);
    }

    if ($method === 'GET' && $path === '/share/smb/shares') {
        $result = host_agent_smb_share_list($root, $mode);
        return host_agent_json_response($result, !empty($result['ok']) ? 200 : 422);
    }

    if ($method === 'POST' && $path === '/share/smb/share/save') {
        $payload = host_agent_json_decode($body);
        $result = host_agent_smb_share_save($root, $mode, $payload);
        return host_agent_json_response($result, !empty($result['ok']) ? 200 : 422);
    }

    if ($method === 'POST' && $path === '/share/smb/share/delete') {
        $payload = host_agent_json_decode($body);
        $result = host_agent_smb_share_delete($root, $mode, trim((string)($payload['name'] ?? '')));
        return host_agent_json_response($result, !empty($result['ok']) ? 200 : 422);
    }

    if ($method === 'POST' && $path === '/share/smb/install') {
        $result = host_agent_generic_service_install($root, $mode, 'smb');
        return host_agent_json_response($result, !empty($result['ok']) ? 200 : 422);
    }

    if ($method === 'POST' && $path === '/share/smb/action') {
        $payload = host_agent_json_decode($body);
        $result = host_agent_generic_service_action($root, $mode, 'smb', trim((string)($payload['action'] ?? '')));
        return host_agent_json_response($result, !empty($result['ok']) ? 200 : 422);
    }

    if ($method === 'GET' && $path === '/share/ftp/status') {
        $result = host_agent_ftp_status($root, $mode);
        return host_agent_json_response($result, !empty($result['ok']) ? 200 : 422);
    }

    if ($method === 'POST' && $path === '/share/ftp/settings/save') {
        $payload = host_agent_json_decode($body);
        $result = host_agent_ftp_settings_save($root, $mode, $payload);
        return host_agent_json_response($result, !empty($result['ok']) ? 200 : 422);
    }

    if ($method === 'POST' && $path === '/share/ftp/install') {
        $result = host_agent_generic_service_install($root, $mode, 'ftp');
        return host_agent_json_response($result, !empty($result['ok']) ? 200 : 422);
    }

    if ($method === 'POST' && $path === '/share/ftp/action') {
        $payload = host_agent_json_decode($body);
        $result = host_agent_generic_service_action($root, $mode, 'ftp', trim((string)($payload['action'] ?? '')));
        return host_agent_json_response($result, !empty($result['ok']) ? 200 : 422);
    }

    if ($method === 'POST' && $path === '/share/smb/uninstall') {
        $result = host_agent_generic_service_uninstall($root, $mode, 'smb');
        return host_agent_json_response($result, !empty($result['ok']) ? 200 : 422);
    }

    if ($method === 'POST' && $path === '/share/ftp/uninstall') {
        $result = host_agent_generic_service_uninstall($root, $mode, 'ftp');
        return host_agent_json_response($result, !empty($result['ok']) ? 200 : 422);
    }

    if ($method === 'GET' && $path === '/share/nfs/status') {
        $result = host_agent_nfs_status($root, $mode);
        return host_agent_json_response($result, !empty($result['ok']) ? 200 : 422);
    }

    if ($method === 'POST' && $path === '/share/nfs/export/save') {
        $payload = host_agent_json_decode($body);
        $result = host_agent_nfs_export_save($root, $mode, $payload);
        return host_agent_json_response($result, !empty($result['ok']) ? 200 : 422);
    }

    if ($method === 'POST' && $path === '/share/nfs/export/delete') {
        $payload = host_agent_json_decode($body);
        $result = host_agent_nfs_export_delete($root, $mode, trim((string)($payload['path'] ?? '')));
        return host_agent_json_response($result, !empty($result['ok']) ? 200 : 422);
    }

    if ($method === 'POST' && $path === '/share/nfs/install') {
        $result = host_agent_generic_service_install($root, $mode, 'nfs');
        return host_agent_json_response($result, !empty($result['ok']) ? 200 : 422);
    }

    if ($method === 'POST' && $path === '/share/nfs/uninstall') {
        $result = host_agent_generic_service_uninstall($root, $mode, 'nfs');
        return host_agent_json_response($result, !empty($result['ok']) ? 200 : 422);
    }

    if ($method === 'POST' && $path === '/share/nfs/action') {
        $payload = host_agent_json_decode($body);
        $result = host_agent_generic_service_action($root, $mode, 'nfs', trim((string)($payload['action'] ?? '')));
        return host_agent_json_response($result, !empty($result['ok']) ? 200 : 422);
    }

    if ($method === 'GET' && $path === '/share/afp/status') {
        $result = host_agent_afp_status($root, $mode);
        return host_agent_json_response($result, !empty($result['ok']) ? 200 : 422);
    }

    if ($method === 'POST' && $path === '/share/afp/share/save') {
        $payload = host_agent_json_decode($body);
        $result = host_agent_afp_share_save($root, $mode, $payload);
        return host_agent_json_response($result, !empty($result['ok']) ? 200 : 422);
    }

    if ($method === 'POST' && $path === '/share/afp/share/delete') {
        $payload = host_agent_json_decode($body);
        $result = host_agent_afp_share_delete($root, $mode, trim((string)($payload['name'] ?? '')));
        return host_agent_json_response($result, !empty($result['ok']) ? 200 : 422);
    }

    if ($method === 'POST' && $path === '/share/afp/install') {
        $result = host_agent_generic_service_install($root, $mode, 'afp');
        return host_agent_json_response($result, !empty($result['ok']) ? 200 : 422);
    }

    if ($method === 'POST' && $path === '/share/afp/uninstall') {
        $result = host_agent_generic_service_uninstall($root, $mode, 'afp');
        return host_agent_json_response($result, !empty($result['ok']) ? 200 : 422);
    }

    if ($method === 'POST' && $path === '/share/afp/action') {
        $payload = host_agent_json_decode($body);
        $result = host_agent_generic_service_action($root, $mode, 'afp', trim((string)($payload['action'] ?? '')));
        return host_agent_json_response($result, !empty($result['ok']) ? 200 : 422);
    }

    if ($method === 'GET' && $path === '/share/async/status') {
        $result = host_agent_async_status($root, $mode);
        return host_agent_json_response($result, !empty($result['ok']) ? 200 : 422);
    }

    if ($method === 'POST' && $path === '/share/async/module/save') {
        $payload = host_agent_json_decode($body);
        $result = host_agent_async_module_save($root, $mode, $payload);
        return host_agent_json_response($result, !empty($result['ok']) ? 200 : 422);
    }

    if ($method === 'POST' && $path === '/share/async/module/delete') {
        $payload = host_agent_json_decode($body);
        $result = host_agent_async_module_delete($root, $mode, trim((string)($payload['name'] ?? '')));
        return host_agent_json_response($result, !empty($result['ok']) ? 200 : 422);
    }

    if ($method === 'POST' && $path === '/share/async/install') {
        $result = host_agent_generic_service_install($root, $mode, 'async');
        return host_agent_json_response($result, !empty($result['ok']) ? 200 : 422);
    }

    if ($method === 'POST' && $path === '/share/async/uninstall') {
        $result = host_agent_generic_service_uninstall($root, $mode, 'async');
        return host_agent_json_response($result, !empty($result['ok']) ? 200 : 422);
    }

    if ($method === 'POST' && $path === '/share/async/action') {
        $payload = host_agent_json_decode($body);
        $result = host_agent_generic_service_action($root, $mode, 'async', trim((string)($payload['action'] ?? '')));
        return host_agent_json_response($result, !empty($result['ok']) ? 200 : 422);
    }

    if ($method === 'GET' && $path === '/share/snapshot') {
        $result = host_agent_share_snapshot($root, $mode, trim((string)($query['service'] ?? '')));
        return host_agent_json_response($result, !empty($result['ok']) ? 200 : 422);
    }

    if ($method === 'POST' && $path === '/share/snapshot/restore') {
        $payload = host_agent_json_decode($body);
        $result = host_agent_share_snapshot_restore($root, $mode, trim((string)($payload['service'] ?? '')), (array)($payload['files'] ?? []));
        return host_agent_json_response($result, !empty($result['ok']) ? 200 : 422);
    }

    if ($method === 'GET' && $path === '/docker/summary') {
        $result = host_agent_docker_summary();
        return host_agent_json_response($result, !empty($result['ok']) ? 200 : 422);
    }

    if ($method === 'GET' && $path === '/docker/containers') {
        $result = host_agent_docker_containers_list(($query['all'] ?? '1') !== '0');
        return host_agent_json_response($result, !empty($result['ok']) ? 200 : 422);
    }

    if ($method === 'POST' && $path === '/docker/container/action') {
        $payload = host_agent_json_decode($body);
        $result = host_agent_docker_container_action(
            trim((string)($payload['id'] ?? '')),
            trim((string)($payload['container_action'] ?? ''))
        );
        return host_agent_json_response($result, !empty($result['ok']) ? 200 : 422);
    }

    if ($method === 'POST' && $path === '/docker/container/delete') {
        $payload = host_agent_json_decode($body);
        $result = host_agent_docker_container_delete(trim((string)($payload['id'] ?? '')), !empty($payload['force']));
        return host_agent_json_response($result, !empty($result['ok']) ? 200 : 422);
    }

    if ($method === 'POST' && $path === '/docker/container/logs') {
        $payload = host_agent_json_decode($body);
        $result = host_agent_docker_container_logs(
            trim((string)($payload['id'] ?? '')),
            (int)($payload['tail'] ?? 200)
        );
        return host_agent_json_response($result, !empty($result['ok']) ? 200 : 422);
    }

    if ($method === 'POST' && $path === '/docker/container/inspect') {
        $payload = host_agent_json_decode($body);
        $result = host_agent_docker_container_inspect(trim((string)($payload['id'] ?? '')));
        return host_agent_json_response($result, !empty($result['ok']) ? 200 : 422);
    }

    if ($method === 'POST' && $path === '/docker/container/stats') {
        $payload = host_agent_json_decode($body);
        $result = host_agent_docker_container_stats(trim((string)($payload['id'] ?? '')));
        return host_agent_json_response($result, !empty($result['ok']) ? 200 : 422);
    }

    if ($method === 'GET' && $path === '/docker/images') {
        $result = host_agent_docker_images_list();
        return host_agent_json_response($result, !empty($result['ok']) ? 200 : 422);
    }

    if ($method === 'GET' && $path === '/docker/volumes') {
        $result = host_agent_docker_volumes_list();
        return host_agent_json_response($result, !empty($result['ok']) ? 200 : 422);
    }

    if ($method === 'GET' && $path === '/docker/networks') {
        $result = host_agent_docker_networks_list();
        return host_agent_json_response($result, !empty($result['ok']) ? 200 : 422);
    }

    if ($method === 'GET' && $path === '/docker/compose/list') {
        $result = host_agent_compose_list();
        return host_agent_json_response($result, !empty($result['ok']) ? 200 : 422);
    }

    if ($method === 'POST' && $path === '/docker/compose/action') {
        $payload = host_agent_json_decode($body);
        $result = host_agent_compose_action(
            trim((string)($payload['file'] ?? '')),
            trim((string)($payload['compose_action'] ?? ''))
        );
        return host_agent_json_response($result, !empty($result['ok']) ? 200 : 422);
    }

    if ($method === 'GET' && $path === '/ssh/status') {
        return host_agent_json_response([
            'ok' => true,
            'data' => host_agent_live_ssh_status($root, $mode),
        ]);
    }

    if ($method === 'GET' && $path === '/ssh/config') {
        return host_agent_json_response([
            'ok' => true,
            'data' => host_agent_read_ssh_config_payload($root, $mode) + [
                'structured' => host_agent_parse_ssh_options(host_agent_read_ssh_config_payload($root, $mode)['content'] ?? ''),
            ],
        ]);
    }

    if ($method === 'POST' && $path === '/ssh/config') {
        $payload = host_agent_json_decode($body);
        $content = (string)($payload['content'] ?? '');
        $validation = host_agent_validate_ssh_config($root, $mode, $content);
        if (empty($validation['ok'])) {
            return host_agent_json_response($validation, 422);
        }
        $result = host_agent_save_ssh_config($root, $mode, $content);
        return host_agent_json_response($result, !empty($result['ok']) ? 200 : 422);
    }

    if ($method === 'POST' && $path === '/ssh/config/structured') {
        $payload = host_agent_json_decode($body);
        $current = host_agent_read_ssh_config_payload($root, $mode);
        if (empty($current['ok'])) {
            return host_agent_json_response($current, 422);
        }
        $content = host_agent_apply_structured_ssh_options((string)($current['content'] ?? ''), $payload);
        $validation = host_agent_validate_ssh_config($root, $mode, $content);
        if (empty($validation['ok'])) {
            return host_agent_json_response($validation, 422);
        }
        $result = host_agent_save_ssh_config($root, $mode, $content);
        $result['structured'] = host_agent_parse_ssh_options($content);
        return host_agent_json_response($result, !empty($result['ok']) ? 200 : 422);
    }

    if ($method === 'POST' && $path === '/ssh/config/restore-last') {
        $result = host_agent_restore_last_ssh_backup($root, $mode);
        return host_agent_json_response($result, !empty($result['ok']) ? 200 : 422);
    }

    if ($method === 'POST' && $path === '/ssh/config/validate') {
        $payload = host_agent_json_decode($body);
        $result = host_agent_validate_ssh_config($root, $mode, (string)($payload['content'] ?? ''));
        return host_agent_json_response($result, !empty($result['ok']) ? 200 : 422);
    }

    if ($method === 'POST' && $path === '/ssh/action') {
        $payload = host_agent_json_decode($body);
        $result = host_agent_ssh_service_action($root, $mode, strtolower(trim((string)($payload['action'] ?? ''))));
        return host_agent_json_response($result, !empty($result['ok']) ? 200 : 422);
    }

    if ($method === 'POST' && $path === '/ssh/enable') {
        $payload = host_agent_json_decode($body);
        $result = host_agent_ssh_enable_toggle($root, $mode, !empty($payload['enabled']));
        return host_agent_json_response($result, !empty($result['ok']) ? 200 : 422);
    }

    if ($method === 'POST' && $path === '/ssh/install') {
        $result = host_agent_install_ssh_service($root, $mode);
        return host_agent_json_response($result, !empty($result['ok']) ? 200 : 422);
    }

    if ($method === 'POST' && $path === '/ssh/target/status') {
        $payload = host_agent_json_decode($body);
        $targetSpec = host_agent_target_from_payload($payload);
        $result = host_agent_ssh_target_status($targetSpec, $root, $mode);
        return host_agent_json_response($result, !empty($result['ok']) ? 200 : 422);
    }

    if ($method === 'POST' && $path === '/ssh/target/config/read') {
        $payload = host_agent_json_decode($body);
        $targetSpec = host_agent_target_from_payload($payload);
        $result = host_agent_ssh_target_config_read($targetSpec, $root, $mode);
        return host_agent_json_response($result, !empty($result['ok']) ? 200 : 422);
    }

    if ($method === 'POST' && $path === '/ssh/target/config/save') {
        $payload = host_agent_json_decode($body);
        $targetSpec = host_agent_target_from_payload($payload);
        $result = host_agent_ssh_target_config_save($targetSpec, $root, $mode, (string)($payload['content'] ?? ''));
        return host_agent_json_response($result, !empty($result['ok']) ? 200 : 422);
    }

    if ($method === 'POST' && $path === '/ssh/target/config/apply') {
        $payload = host_agent_json_decode($body);
        $targetSpec = host_agent_target_from_payload($payload);
        $result = host_agent_ssh_target_apply(
            $targetSpec,
            $root,
            $mode,
            (string)($payload['content'] ?? ''),
            !empty($payload['restart_after_save']),
            !empty($payload['rollback_on_failure'])
        );
        return host_agent_json_response($result, !empty($result['ok']) ? 200 : 422);
    }

    if ($method === 'POST' && $path === '/ssh/target/config/validate') {
        $payload = host_agent_json_decode($body);
        $targetSpec = host_agent_target_from_payload($payload);
        $result = host_agent_ssh_target_validate($targetSpec, $root, $mode, (string)($payload['content'] ?? ''));
        return host_agent_json_response($result, !empty($result['ok']) ? 200 : 422);
    }

    if ($method === 'POST' && $path === '/ssh/target/config/structured') {
        $payload = host_agent_json_decode($body);
        $targetSpec = host_agent_target_from_payload($payload);
        $result = host_agent_ssh_target_structured_save($targetSpec, $root, $mode, $payload);
        return host_agent_json_response($result, !empty($result['ok']) ? 200 : 422);
    }

    if ($method === 'POST' && $path === '/ssh/target/config/restore-last') {
        $payload = host_agent_json_decode($body);
        $targetSpec = host_agent_target_from_payload($payload);
        $result = host_agent_ssh_target_restore_last_backup($targetSpec, $root, $mode);
        return host_agent_json_response($result, !empty($result['ok']) ? 200 : 422);
    }

    if ($method === 'POST' && $path === '/ssh/target/action') {
        $payload = host_agent_json_decode($body);
        $targetSpec = host_agent_target_from_payload($payload);
        $result = host_agent_ssh_target_service_action($targetSpec, $root, $mode, strtolower(trim((string)($payload['action'] ?? ''))));
        return host_agent_json_response($result, !empty($result['ok']) ? 200 : 422);
    }

    if ($method === 'POST' && $path === '/ssh/target/enable') {
        $payload = host_agent_json_decode($body);
        $targetSpec = host_agent_target_from_payload($payload);
        $result = host_agent_ssh_target_enable_toggle($targetSpec, $root, $mode, !empty($payload['enabled']));
        return host_agent_json_response($result, !empty($result['ok']) ? 200 : 422);
    }

    if ($method === 'POST' && $path === '/ssh/target/install') {
        $payload = host_agent_json_decode($body);
        $targetSpec = host_agent_target_from_payload($payload);
        $result = host_agent_ssh_target_install($targetSpec, $root, $mode);
        return host_agent_json_response($result, !empty($result['ok']) ? 200 : 422);
    }

    if ($method === 'POST' && $path === '/ssh/authorized-keys/list') {
        $payload = host_agent_json_decode($body);
        $targetSpec = host_agent_target_from_payload($payload);
        $result = host_agent_authorized_keys_list($targetSpec, $root, $mode, trim((string)($payload['user'] ?? 'root')) ?: 'root');
        return host_agent_json_response($result, !empty($result['ok']) ? 200 : 422);
    }

    if ($method === 'POST' && $path === '/ssh/authorized-keys/add') {
        $payload = host_agent_json_decode($body);
        $targetSpec = host_agent_target_from_payload($payload);
        $result = host_agent_authorized_keys_add(
            $targetSpec,
            $root,
            $mode,
            trim((string)($payload['user'] ?? 'root')) ?: 'root',
            (string)($payload['public_key'] ?? '')
        );
        return host_agent_json_response($result, !empty($result['ok']) ? 200 : 422);
    }

    if ($method === 'POST' && $path === '/ssh/authorized-keys/remove') {
        $payload = host_agent_json_decode($body);
        $targetSpec = host_agent_target_from_payload($payload);
        $result = host_agent_authorized_keys_remove(
            $targetSpec,
            $root,
            $mode,
            trim((string)($payload['user'] ?? 'root')) ?: 'root',
            trim((string)($payload['line_hash'] ?? ''))
        );
        return host_agent_json_response($result, !empty($result['ok']) ? 200 : 422);
    }

    if ($method === 'POST' && $path === '/remote/test') {
        $payload = host_agent_json_decode($body);
        $result = host_agent_remote_test(host_agent_target_from_payload($payload));
        return host_agent_json_response($result, !empty($result['ok']) ? 200 : 422);
    }

    if ($method === 'POST' && $path === '/remote/exec') {
        $payload = host_agent_json_decode($body);
        $result = host_agent_remote_exec_command(host_agent_target_from_payload($payload), (string)($payload['command'] ?? ''));
        return host_agent_json_response($result, !empty($result['ok']) ? 200 : 422);
    }

    if ($method === 'GET' && $path === '/fs/list') {
        $payload = ['target' => ['type' => (string)($query['target'] ?? 'local')], 'path' => (string)($query['path'] ?? '/')];
        if (($query['target'] ?? 'local') === 'remote') {
            return host_agent_json_response(['ok' => false, 'msg' => 'GET 不支持远程文件列表，请使用 POST'], 422);
        }
        $result = host_agent_local_file_list($root, (string)($payload['path'] ?? '/'));
        return host_agent_json_response($result, !empty($result['ok']) ? 200 : 422);
    }

    if ($method === 'POST' && $path === '/fs/list') {
        $payload = host_agent_json_decode($body);
        $targetSpec = host_agent_target_from_payload($payload);
        $pathValue = (string)($payload['path'] ?? '/');
        $result = ($targetSpec['type'] ?? 'local') === 'remote'
            ? host_agent_remote_file_list($targetSpec, $pathValue)
            : host_agent_local_file_list($root, $pathValue);
        return host_agent_json_response($result, !empty($result['ok']) ? 200 : 422);
    }

    if ($method === 'POST' && $path === '/fs/read') {
        $payload = host_agent_json_decode($body);
        $targetSpec = host_agent_target_from_payload($payload);
        $pathValue = (string)($payload['path'] ?? '/');
        $result = ($targetSpec['type'] ?? 'local') === 'remote'
            ? host_agent_remote_file_read($targetSpec, $pathValue)
            : host_agent_local_file_read($root, $pathValue);
        return host_agent_json_response($result, !empty($result['ok']) ? 200 : 422);
    }

    if ($method === 'POST' && $path === '/fs/write') {
        $payload = host_agent_json_decode($body);
        $targetSpec = host_agent_target_from_payload($payload);
        $pathValue = (string)($payload['path'] ?? '/');
        $decoded = host_agent_decode_content_payload($payload);
        if (empty($decoded['ok'])) {
            return host_agent_json_response($decoded, 422);
        }
        $content = (string)($decoded['content'] ?? '');
        $result = ($targetSpec['type'] ?? 'local') === 'remote'
            ? host_agent_remote_file_write($targetSpec, $pathValue, $content)
            : host_agent_local_file_write($root, $pathValue, $content);
        return host_agent_json_response($result, !empty($result['ok']) ? 200 : 422);
    }

    if ($method === 'POST' && $path === '/fs/delete') {
        $payload = host_agent_json_decode($body);
        $targetSpec = host_agent_target_from_payload($payload);
        $pathValue = (string)($payload['path'] ?? '/');
        $result = ($targetSpec['type'] ?? 'local') === 'remote'
            ? host_agent_remote_file_delete($targetSpec, $pathValue)
            : host_agent_local_file_delete($root, $pathValue);
        return host_agent_json_response($result, !empty($result['ok']) ? 200 : 422);
    }

    if ($method === 'POST' && $path === '/fs/mkdir') {
        $payload = host_agent_json_decode($body);
        $targetSpec = host_agent_target_from_payload($payload);
        $pathValue = (string)($payload['path'] ?? '/');
        $result = ($targetSpec['type'] ?? 'local') === 'remote'
            ? host_agent_remote_mkdir($targetSpec, $pathValue)
            : host_agent_local_mkdir($root, $pathValue);
        return host_agent_json_response($result, !empty($result['ok']) ? 200 : 422);
    }

    if ($method === 'POST' && $path === '/fs/rename') {
        $payload = host_agent_json_decode($body);
        $targetSpec = host_agent_target_from_payload($payload);
        $sourcePath = (string)($payload['source_path'] ?? '');
        $targetPath = (string)($payload['target_path'] ?? '');
        $result = ($targetSpec['type'] ?? 'local') === 'remote'
            ? host_agent_remote_file_rename($targetSpec, $sourcePath, $targetPath)
            : host_agent_local_file_rename($root, $sourcePath, $targetPath);
        return host_agent_json_response($result, !empty($result['ok']) ? 200 : 422);
    }

    if ($method === 'POST' && $path === '/fs/copy') {
        $payload = host_agent_json_decode($body);
        $targetSpec = host_agent_target_from_payload($payload);
        $sourcePath = (string)($payload['source_path'] ?? '');
        $targetPath = (string)($payload['target_path'] ?? '');
        $result = ($targetSpec['type'] ?? 'local') === 'remote'
            ? host_agent_remote_file_copy($targetSpec, $sourcePath, $targetPath)
            : host_agent_local_file_copy($root, $sourcePath, $targetPath);
        return host_agent_json_response($result, !empty($result['ok']) ? 200 : 422);
    }

    if ($method === 'POST' && $path === '/fs/move') {
        $payload = host_agent_json_decode($body);
        $targetSpec = host_agent_target_from_payload($payload);
        $sourcePath = (string)($payload['source_path'] ?? '');
        $targetPath = (string)($payload['target_path'] ?? '');
        $result = ($targetSpec['type'] ?? 'local') === 'remote'
            ? host_agent_remote_file_move($targetSpec, $sourcePath, $targetPath)
            : host_agent_local_file_move($root, $sourcePath, $targetPath);
        return host_agent_json_response($result, !empty($result['ok']) ? 200 : 422);
    }

    if ($method === 'POST' && $path === '/fs/search') {
        $payload = host_agent_json_decode($body);
        $targetSpec = host_agent_target_from_payload($payload);
        $pathValue = (string)($payload['path'] ?? '/');
        $keyword = trim((string)($payload['keyword'] ?? ''));
        $limit = (int)($payload['limit'] ?? 200);
        $result = ($targetSpec['type'] ?? 'local') === 'remote'
            ? host_agent_remote_file_search($targetSpec, $pathValue, $keyword, $limit)
            : host_agent_local_file_search($root, $pathValue, $keyword, $limit);
        return host_agent_json_response($result, !empty($result['ok']) ? 200 : 422);
    }

    if ($method === 'POST' && $path === '/fs/stat') {
        $payload = host_agent_json_decode($body);
        $targetSpec = host_agent_target_from_payload($payload);
        $pathValue = (string)($payload['path'] ?? '/');
        $result = ($targetSpec['type'] ?? 'local') === 'remote'
            ? host_agent_remote_file_stat($targetSpec, $pathValue)
            : host_agent_local_file_stat($root, $pathValue, $mode);
        return host_agent_json_response($result, !empty($result['ok']) ? 200 : 422);
    }

    if ($method === 'POST' && $path === '/fs/chmod') {
        $payload = host_agent_json_decode($body);
        $targetSpec = host_agent_target_from_payload($payload);
        $pathValue = (string)($payload['path'] ?? '/');
        $modeValue = trim((string)($payload['mode'] ?? ''));
        $result = ($targetSpec['type'] ?? 'local') === 'remote'
            ? host_agent_remote_file_chmod($targetSpec, $pathValue, $modeValue)
            : host_agent_local_file_chmod($root, $pathValue, $modeValue, $mode);
        return host_agent_json_response($result, !empty($result['ok']) ? 200 : 422);
    }

    if ($method === 'POST' && $path === '/fs/chown') {
        $payload = host_agent_json_decode($body);
        $targetSpec = host_agent_target_from_payload($payload);
        $pathValue = (string)($payload['path'] ?? '/');
        $ownerValue = trim((string)($payload['owner'] ?? ''));
        $result = ($targetSpec['type'] ?? 'local') === 'remote'
            ? host_agent_remote_file_chown($targetSpec, $pathValue, $ownerValue)
            : host_agent_local_file_chown($root, $pathValue, $ownerValue, $mode);
        return host_agent_json_response($result, !empty($result['ok']) ? 200 : 422);
    }

    if ($method === 'POST' && $path === '/fs/chgrp') {
        $payload = host_agent_json_decode($body);
        $targetSpec = host_agent_target_from_payload($payload);
        $pathValue = (string)($payload['path'] ?? '/');
        $groupValue = trim((string)($payload['group'] ?? ''));
        $result = ($targetSpec['type'] ?? 'local') === 'remote'
            ? host_agent_remote_file_chgrp($targetSpec, $pathValue, $groupValue)
            : host_agent_local_file_chgrp($root, $pathValue, $groupValue, $mode);
        return host_agent_json_response($result, !empty($result['ok']) ? 200 : 422);
    }

    if ($method === 'POST' && $path === '/fs/acl/apply') {
        $payload = host_agent_json_decode($body);
        $targetSpec = host_agent_target_from_payload($payload);
        $pathValue = (string)($payload['path'] ?? '/');
        $ownerValue = trim((string)($payload['owner'] ?? ''));
        $groupValue = trim((string)($payload['group'] ?? ''));
        $modeValue = trim((string)($payload['mode'] ?? ''));
        $recursive = !empty($payload['recursive']);
        $result = ($targetSpec['type'] ?? 'local') === 'remote'
            ? host_agent_remote_file_acl_apply($targetSpec, $pathValue, $ownerValue, $groupValue, $modeValue, $recursive)
            : host_agent_local_file_acl_apply($root, $mode, $pathValue, $ownerValue, $groupValue, $modeValue, $recursive);
        return host_agent_json_response($result, !empty($result['ok']) ? 200 : 422);
    }

    if ($method === 'POST' && $path === '/fs/archive') {
        $payload = host_agent_json_decode($body);
        $targetSpec = host_agent_target_from_payload($payload);
        $pathValue = (string)($payload['path'] ?? '/');
        $archivePath = (string)($payload['archive_path'] ?? ($pathValue . '.tar.gz'));
        $result = ($targetSpec['type'] ?? 'local') === 'remote'
            ? host_agent_remote_archive($targetSpec, $pathValue, $archivePath)
            : host_agent_local_archive($root, $pathValue, $archivePath);
        return host_agent_json_response($result, !empty($result['ok']) ? 200 : 422);
    }

    if ($method === 'POST' && $path === '/fs/extract') {
        $payload = host_agent_json_decode($body);
        $targetSpec = host_agent_target_from_payload($payload);
        $pathValue = (string)($payload['path'] ?? '/');
        $destination = (string)($payload['destination'] ?? dirname($pathValue));
        $result = ($targetSpec['type'] ?? 'local') === 'remote'
            ? host_agent_remote_extract($targetSpec, $pathValue, $destination)
            : host_agent_local_extract($root, $pathValue, $destination);
        return host_agent_json_response($result, !empty($result['ok']) ? 200 : 422);
    }

    if ($method === 'POST' && $path === '/terminal/open') {
        $payload = host_agent_json_decode($body);
        $targetSpec = host_agent_target_from_payload($payload);
        $result = host_agent_terminal_open(
            $targetSpec,
            $root,
            $mode,
            !empty($payload['persist']),
            max(5, (int)($payload['idle_minutes'] ?? 120))
        );
        return host_agent_json_response($result, !empty($result['ok']) ? 200 : 422);
    }

    if ($method === 'GET' && $path === '/terminal/list') {
        $result = host_agent_terminal_list($root);
        return host_agent_json_response($result, !empty($result['ok']) ? 200 : 422);
    }

    if ($method === 'GET' && $path === '/terminal/read') {
        $result = host_agent_terminal_read((string)($query['id'] ?? ''));
        return host_agent_json_response($result, !empty($result['ok']) ? 200 : 422);
    }

    if ($method === 'POST' && $path === '/terminal/write') {
        $payload = host_agent_json_decode($body);
        $result = host_agent_terminal_write((string)($payload['id'] ?? ''), (string)($payload['data'] ?? ''));
        return host_agent_json_response($result, !empty($result['ok']) ? 200 : 422);
    }

    if ($method === 'POST' && $path === '/terminal/close') {
        $payload = host_agent_json_decode($body);
        $result = host_agent_terminal_close((string)($payload['id'] ?? ''));
        return host_agent_json_response($result, !empty($result['ok']) ? 200 : 422);
    }

    // Package Manager Routes
    if ($method === 'GET' && $path === '/package/manager') {
        $manager = host_agent_detect_package_manager();
        $svcManager = host_agent_detect_service_manager();
        return host_agent_json_response([
            'ok' => true,
            'manager' => $manager,
            'service_manager' => $svcManager,
        ]);
    }

    if ($method === 'POST' && $path === '/package/search') {
        $payload = host_agent_json_decode($body);
        $manager = host_agent_detect_package_manager();
        $result = host_agent_package_search(
            $manager,
            trim((string)($payload['keyword'] ?? '')),
            (int)($payload['limit'] ?? 50)
        );
        return host_agent_json_response($result, !empty($result['ok']) ? 200 : 422);
    }

    if ($method === 'POST' && $path === '/package/info') {
        $payload = host_agent_json_decode($body);
        $manager = host_agent_detect_package_manager();
        $pkg = trim((string)($payload['pkg'] ?? ''));
        $result = host_agent_package_info($manager, $pkg);
        return host_agent_json_response($result, !empty($result['ok']) ? 200 : 422);
    }

    if ($method === 'POST' && $path === '/package/install') {
        $payload = host_agent_json_decode($body);
        $manager = host_agent_detect_package_manager();
        $pkg = trim((string)($payload['pkg'] ?? ''));
        $resolved = host_agent_resolve_package_name($pkg, $manager);
        $result = host_agent_package_install($manager, $resolved);
        return host_agent_json_response($result, !empty($result['ok']) ? 200 : 422);
    }

    if ($method === 'POST' && $path === '/package/remove') {
        $payload = host_agent_json_decode($body);
        $manager = host_agent_detect_package_manager();
        $pkg = trim((string)($payload['pkg'] ?? ''));
        $purge = !empty($payload['purge']);
        $resolved = host_agent_resolve_package_name($pkg, $manager);
        $result = host_agent_package_remove($manager, $resolved, $purge);
        return host_agent_json_response($result, !empty($result['ok']) ? 200 : 422);
    }

    if ($method === 'POST' && $path === '/package/update') {
        $payload = host_agent_json_decode($body);
        $manager = host_agent_detect_package_manager();
        $pkg = trim((string)($payload['pkg'] ?? ''));
        $resolved = host_agent_resolve_package_name($pkg, $manager);
        $result = host_agent_package_update($manager, $resolved);
        return host_agent_json_response($result, !empty($result['ok']) ? 200 : 422);
    }

    if ($method === 'POST' && $path === '/package/upgrade-all') {
        $manager = host_agent_detect_package_manager();
        $result = host_agent_package_upgrade_all($manager);
        return host_agent_json_response($result, !empty($result['ok']) ? 200 : 422);
    }

    if ($method === 'POST' && $path === '/package/list') {
        $payload = host_agent_json_decode($body);
        $manager = host_agent_detect_package_manager();
        $result = host_agent_package_list($manager, (int)($payload['limit'] ?? 500));
        return host_agent_json_response($result, !empty($result['ok']) ? 200 : 422);
    }

    // Configuration Manager Routes
    if ($method === 'GET' && $path === '/config/definitions') {
        return host_agent_json_response(host_agent_config_definitions_response());
    }

    if ($method === 'POST' && $path === '/config/read') {
        $payload = host_agent_json_decode($body);
        $manager = host_agent_detect_package_manager();
        $result = host_agent_config_read(
            trim((string)($payload['config_id'] ?? '')),
            $manager
        );
        return host_agent_json_response($result, !empty($result['ok']) ? 200 : 422);
    }

    if ($method === 'POST' && $path === '/config/apply') {
        $payload = host_agent_json_decode($body);
        $manager = host_agent_detect_package_manager();
        $result = host_agent_config_apply(
            trim((string)($payload['config_id'] ?? '')),
            $manager,
            (string)($payload['content'] ?? ''),
            !empty($payload['validate_only'])
        );
        return host_agent_json_response($result, !empty($result['ok']) ? 200 : 422);
    }

    if ($method === 'POST' && $path === '/config/validate') {
        $payload = host_agent_json_decode($body);
        $manager = host_agent_detect_package_manager();
        $result = host_agent_config_apply(
            trim((string)($payload['config_id'] ?? '')),
            $manager,
            (string)($payload['content'] ?? ''),
            true
        );
        return host_agent_json_response($result, !empty($result['ok']) ? 200 : 422);
    }

    if ($method === 'POST' && $path === '/config/history') {
        $payload = host_agent_json_decode($body);
        $manager = host_agent_detect_package_manager();
        $result = host_agent_config_history(
            trim((string)($payload['config_id'] ?? '')),
            $manager,
            (int)($payload['limit'] ?? 10)
        );
        return host_agent_json_response($result, !empty($result['ok']) ? 200 : 422);
    }

    if ($method === 'POST' && $path === '/config/restore') {
        $payload = host_agent_json_decode($body);
        $manager = host_agent_detect_package_manager();
        $result = host_agent_config_restore(
            trim((string)($payload['config_id'] ?? '')),
            $manager,
            trim((string)($payload['backup_path'] ?? ''))
        );
        return host_agent_json_response($result, !empty($result['ok']) ? 200 : 422);
    }

    // Async Task Routes
    if ($method === 'POST' && $path === '/task/submit') {
        $payload = host_agent_json_decode($body);
        $action = trim((string)($payload['action'] ?? ''));
        if ($action === '') {
            return host_agent_json_response(['ok' => false, 'msg' => '缺少 action 参数'], 422);
        }
        $result = host_agent_task_submit($action, (array)($payload['payload'] ?? []));
        return host_agent_json_response($result, !empty($result['ok']) ? 200 : 422);
    }

    if ($method === 'GET' && $path === '/task/status') {
        $taskId = trim((string)($query['id'] ?? ''));
        if ($taskId === '') {
            return host_agent_json_response(['ok' => false, 'msg' => '缺少 id 参数'], 422);
        }
        $result = host_agent_task_status($taskId);
        return host_agent_json_response($result, !empty($result['ok']) ? 200 : 404);
    }

    if ($method === 'POST' && $path === '/task/cancel') {
        $payload = host_agent_json_decode($body);
        $taskId = trim((string)($payload['id'] ?? ''));
        if ($taskId === '') {
            return host_agent_json_response(['ok' => false, 'msg' => '缺少 id 参数'], 422);
        }
        $result = host_agent_task_cancel($taskId);
        return host_agent_json_response($result, !empty($result['ok']) ? 200 : 422);
    }

    if ($method === 'GET' && $path === '/task/list') {
        $result = host_agent_task_list();
        return host_agent_json_response($result, 200);
    }

    // Declarative Manifest Routes
    if ($method === 'POST' && $path === '/manifest/apply') {
        $payload = host_agent_json_decode($body);
        $manifest = is_array($payload['manifest'] ?? null) ? $payload['manifest'] : [];
        $result = host_agent_manifest_apply($manifest, false);
        return host_agent_json_response($result, !empty($result['ok']) ? 200 : 422);
    }

    if ($method === 'POST' && $path === '/manifest/dry-run') {
        $payload = host_agent_json_decode($body);
        $manifest = is_array($payload['manifest'] ?? null) ? $payload['manifest'] : [];
        $result = host_agent_manifest_apply($manifest, true);
        return host_agent_json_response($result, !empty($result['ok']) ? 200 : 422);
    }

    if ($method === 'POST' && $path === '/manifest/validate') {
        $payload = host_agent_json_decode($body);
        $manifest = is_array($payload['manifest'] ?? null) ? $payload['manifest'] : [];
        $errors = host_agent_manifest_validate_schema($manifest);
        return host_agent_json_response([
            'ok' => empty($errors),
            'msg' => empty($errors) ? 'Manifest 格式有效' : 'Manifest 格式校验失败',
            'errors' => $errors,
        ], empty($errors) ? 200 : 422);
    }

    // Archive Extract / Compress / List Routes
    if ($method === 'POST' && $path === '/archive/extract') {
        $payload = host_agent_json_decode($body);
        $result = host_agent_archive_extract(
            $root,
            trim((string)($payload['path'] ?? '')),
            trim((string)($payload['dest_dir'] ?? ''))
        );
        return host_agent_json_response($result, !empty($result['ok']) ? 200 : 422);
    }

    if ($method === 'POST' && $path === '/archive/compress') {
        $payload = host_agent_json_decode($body);
        $result = host_agent_archive_compress(
            $root,
            (array)($payload['paths'] ?? []),
            trim((string)($payload['dest_path'] ?? '')),
            trim((string)($payload['format'] ?? 'tar.gz'))
        );
        return host_agent_json_response($result, !empty($result['ok']) ? 200 : 422);
    }

    if ($method === 'GET' && $path === '/archive/list') {
        $result = host_agent_archive_list(
            $root,
            trim((string)($query['path'] ?? ''))
        );
        return host_agent_json_response($result, !empty($result['ok']) ? 200 : 422);
    }

    if ($method === 'GET' && $path === '/archive/tools') {
        $result = ['ok' => true, 'tools' => host_agent_archive_detect_tools()];
        return host_agent_json_response($result, 200);
    }

    if ($method !== 'GET' && $method !== 'POST') {
        return host_agent_json_response(['ok' => false, 'msg' => 'method not allowed'], 405);
    }

    return host_agent_json_response(['ok' => false, 'msg' => 'not found'], 404);
}

function host_agent_run_server(array $argv): int {
    $listen = host_agent_arg_value($argv, '--listen=', '0.0.0.0:39091');
    $root = host_agent_arg_value($argv, '--root=', '/hostfs');
    $token = (string)getenv('HOST_AGENT_TOKEN');
    $mode = (string)(getenv('HOST_AGENT_MODE') ?: 'host');
    $GLOBALS['HOST_AGENT_ROOT'] = $root;

    $server = @stream_socket_server('tcp://' . $listen, $errno, $errstr);
    if ($server === false) {
        fwrite(STDERR, "host-agent listen failed: {$errstr} ({$errno})\n");
        return 1;
    }

    fwrite(STDOUT, '[host-agent] listening on ' . $listen . ' mode=' . $mode . ' root=' . $root . PHP_EOL);
    while ($conn = @stream_socket_accept($server, -1)) {
        $request = '';
        $contentLength = 0;
        while (!feof($conn)) {
            $chunk = fread($conn, 4096);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $request .= $chunk;
            if (strpos($request, "\r\n\r\n") !== false) {
                if (preg_match('/\r\nContent-Length:\s*(\d+)/i', $request, $matches)) {
                    $contentLength = (int)$matches[1];
                }
                $parts = explode("\r\n\r\n", $request, 2);
                $bodyLength = strlen($parts[1] ?? '');
                while ($contentLength > $bodyLength && !feof($conn)) {
                    $extra = fread($conn, $contentLength - $bodyLength);
                    if ($extra === false || $extra === '') {
                        break;
                    }
                    $request .= $extra;
                    $bodyLength += strlen($extra);
                }
                break;
            }
        }
        $response = host_agent_handle_request($request, $token, $root, $mode);
        fwrite($conn, $response);
        fclose($conn);
    }

    fclose($server);
    return 0;
}

$command = $argv[1] ?? 'serve';
if ($command === 'serve') {
    exit(host_agent_run_server($argv));
}

fwrite(STDERR, "usage: php cli/host_agent.php serve [--listen=0.0.0.0:39091] [--root=/hostfs]\n");
exit(1);
