<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/auth.php';

define('HOST_AGENT_DATA_FILE', DATA_DIR . '/host_agent.json');
define('HOST_AGENT_DEFAULT_PORT', 39091);

function host_agent_docker_socket_path(): string {
    $path = trim((string)getenv('HOST_AGENT_DOCKER_SOCKET'));
    return $path !== '' ? $path : '/var/run/docker.sock';
}

function host_agent_install_mode(): string {
    $mode = strtolower(trim((string)getenv('HOST_AGENT_INSTALL_MODE')));
    return in_array($mode, ['host', 'simulate'], true) ? $mode : 'host';
}

function host_agent_default_url(string $container_name): string {
    return 'http://' . $container_name . ':' . HOST_AGENT_DEFAULT_PORT;
}

function host_agent_container_ip_from_inspect(array $inspect): string {
    foreach ((array)($inspect['NetworkSettings']['Networks'] ?? []) as $network) {
        if (!is_array($network)) {
            continue;
        }
        $ip = trim((string)($network['IPAddress'] ?? ''));
        if ($ip !== '') {
            return $ip;
        }
    }
    return '';
}

function host_agent_http_request(string $url, string $method, string $token, ?array $payload = null): array {
    $ch = curl_init($url);
    $headers = [
        'X-Host-Agent-Token: ' . $token,
        'Content-Type: application/json',
    ];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => $headers,
    ]);
    if ($payload !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
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
        'ok' => $errno === 0 && $status >= 200 && $status < 300 && is_array($decoded) && (($decoded['ok'] ?? false) === true),
        'status' => $status,
        'errno' => $errno,
        'error' => $error,
        'msg' => is_array($decoded) ? (string)($decoded['msg'] ?? '') : '',
        'data' => is_array($decoded) ? ($decoded['data'] ?? $decoded) : null,
        'body' => is_string($body) ? $body : '',
    ];
}

function host_agent_load_state(): array {
    if (!is_file(HOST_AGENT_DATA_FILE)) {
        return [];
    }
    $raw = json_decode((string)@file_get_contents(HOST_AGENT_DATA_FILE), true);
    return is_array($raw) ? $raw : [];
}

function host_agent_save_state(array $state): void {
    if (!is_dir(DATA_DIR)) {
        @mkdir(DATA_DIR, 0750, true);
    }
    file_put_contents(
        HOST_AGENT_DATA_FILE,
        json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );
}

function host_agent_token(): string {
    $state = host_agent_load_state();
    $token = trim((string)($state['token'] ?? ''));
    if ($token !== '') {
        return $token;
    }
    $token = bin2hex(random_bytes(24));
    $state['token'] = $token;
    $state['updated_at'] = date('Y-m-d H:i:s');
    host_agent_save_state($state);
    return $token;
}

function host_agent_socket_mounted(): bool {
    $path = host_agent_docker_socket_path();
    if ($path === '' || !file_exists($path)) {
        return false;
    }
    $type = @filetype($path);
    if ($type !== 'socket') {
        return false;
    }
    return true;
}

function host_agent_docker_proxy_binary(): string {
    $preferred = '/usr/local/bin/host-agent-docker';
    if (is_executable($preferred)) {
        return $preferred;
    }
    return '/usr/local/bin/nav-host-agent-docker';
}

function host_agent_can_use_sudo_proxy(): bool {
    $binary = host_agent_docker_proxy_binary();
    if (!is_executable($binary)) {
        return false;
    }
    $command = 'sudo -n ' . escapeshellarg($binary) . ' PING';
    $output = [];
    $code = 0;
    @exec($command . ' 2>/dev/null', $output, $code);
    return $code === 0 && trim(implode("\n", $output)) === 'pong';
}

function host_agent_docker_access_method(): string {
    if (!host_agent_socket_mounted()) {
        return '';
    }
    $path = host_agent_docker_socket_path();
    if (is_readable($path) && is_writable($path)) {
        return 'direct';
    }
    if (host_agent_can_use_sudo_proxy()) {
        return 'sudo_proxy';
    }
    return '';
}

function host_agent_socket_available(): bool {
    return host_agent_docker_access_method() !== '';
}

function host_agent_mount_hint(): string {
    $path = host_agent_docker_socket_path();
    return '在 docker compose 中临时增加：' . $path . ':' . $path . '。安装并确认 host-agent 运行正常后，请移除这个挂载；后续只有升级或重装 host-agent 时才需要再次挂回。';
}

function host_agent_docker_request(string $method, string $path, ?array $payload = null): array {
    $socket = host_agent_docker_socket_path();
    $access_method = host_agent_docker_access_method();
    if ($access_method === '') {
        return [
            'ok' => false,
            'status' => 0,
            'error' => host_agent_socket_mounted()
                ? 'docker.sock 已挂载，但当前后台进程没有读写权限；请确认容器内允许通过 sudo 代理访问 Docker API'
                : '未检测到可用的 docker.sock 挂载',
            'body' => '',
            'json' => null,
        ];
    }

    if ($access_method === 'sudo_proxy') {
        $binary = host_agent_docker_proxy_binary();
        $command = 'sudo -n ' . escapeshellarg($binary)
            . ' ' . escapeshellarg(strtoupper($method))
            . ' ' . escapeshellarg($path)
            . ' ' . escapeshellarg($payload === null ? '' : base64_encode(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)))
            . ' ' . escapeshellarg($socket);
        $output = [];
        $code = 0;
        @exec($command . ' 2>&1', $output, $code);
        $raw = implode("\n", $output);
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [
                'ok' => false,
                'status' => 0,
                'error' => 'sudo 代理返回异常：' . $raw,
                'body' => $raw,
                'json' => null,
            ];
        }
        $json = null;
        if (($decoded['body'] ?? '') !== '') {
            $candidate = json_decode((string)$decoded['body'], true);
            if (is_array($candidate)) {
                $json = $candidate;
            }
        }
        return [
            'ok' => (bool)($decoded['ok'] ?? false),
            'status' => (int)($decoded['status'] ?? 0),
            'error' => (string)($decoded['error'] ?? ''),
            'body' => (string)($decoded['body'] ?? ''),
            'json' => $json,
        ];
    }

    $ch = curl_init('http://localhost' . $path);
    $headers = ['Content-Type: application/json'];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_UNIX_SOCKET_PATH => $socket,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 15,
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
        'error' => $error,
        'body' => is_string($body) ? $body : '',
        'json' => is_array($decoded) ? $decoded : null,
    ];
}

function host_agent_detect_self_container(): array {
    $container_id = trim((string)getenv('HOSTNAME'));
    if ($container_id === '') {
        $container_id = trim((string)@file_get_contents('/etc/hostname'));
    }
    if ($container_id === '') {
        return ['ok' => false, 'msg' => '无法识别当前容器 ID'];
    }

    $inspect = host_agent_docker_request('GET', '/containers/' . rawurlencode($container_id) . '/json');
    if (!$inspect['ok'] || !is_array($inspect['json'])) {
        return ['ok' => false, 'msg' => '无法通过 Docker API 读取当前容器信息：' . ($inspect['error'] ?: $inspect['body'])];
    }

    $data = $inspect['json'];
    $name = ltrim((string)($data['Name'] ?? ''), '/');
    $image = trim((string)($data['Config']['Image'] ?? ''));
    $networks = array_keys((array)($data['NetworkSettings']['Networks'] ?? []));
    $dataMountSource = '';
    $appMountSource = '';
    foreach ((array)($data['Mounts'] ?? []) as $mount) {
        if (!is_array($mount)) {
            continue;
        }
        $destination = trim((string)($mount['Destination'] ?? ''));
        if ($destination === '/var/www/nav/data') {
            $dataMountSource = trim((string)($mount['Source'] ?? ''));
        }
        if ($destination === '/var/www/nav') {
            $appMountSource = trim((string)($mount['Source'] ?? ''));
        }
    }
    $network = '';
    foreach ($networks as $item) {
        if ($item !== 'host' && $item !== 'none' && $item !== '') {
            $network = (string)$item;
            break;
        }
    }

    if ($name === '' || $image === '' || $network === '') {
        return ['ok' => false, 'msg' => '当前容器缺少名称、镜像或可复用网络信息，无法一键安装 host-agent'];
    }

    return [
        'ok' => true,
        'container_id' => $container_id,
        'container_name' => $name,
        'image' => $image,
        'network' => $network,
        'data_mount_source' => $dataMountSource,
        'app_mount_source' => $appMountSource,
    ];
}

function host_agent_container_name(?string $base_name = null): string {
    $state = host_agent_load_state();
    $saved = trim((string)($state['container_name'] ?? ''));
    if ($saved !== '') {
        return $saved;
    }
    $base = trim((string)$base_name);
    if ($base === '') {
        $self = host_agent_detect_self_container();
        $base = $self['ok'] ? (string)$self['container_name'] : 'simple-homepage';
    }
    return preg_replace('/[^a-zA-Z0-9_.-]+/', '-', $base) . '-host-agent';
}

function host_agent_probe(string $base_url, string $token): array {
    $ch = curl_init(rtrim($base_url, '/') . '/health');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT => 3,
        CURLOPT_HTTPHEADER => ['X-Host-Agent-Token: ' . $token],
    ]);
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
        'ok' => $errno === 0 && $status === 200 && is_array($decoded) && (($decoded['ok'] ?? false) === true),
        'status' => $status,
        'error' => $error,
        'data' => is_array($decoded) ? $decoded : null,
    ];
}

function host_agent_api_request(string $method, string $path, ?array $payload = null): array {
    $state = host_agent_load_state();
    $token = trim((string)($state['token'] ?? ''));
    $service_url = trim((string)($state['service_url'] ?? ''));
    $container_name = trim((string)($state['container_name'] ?? ''));
    if ($service_url === '' && $container_name !== '') {
        $service_url = host_agent_default_url($container_name);
    }
    if ($service_url === '' || $token === '') {
        return ['ok' => false, 'status' => 0, 'msg' => 'host-agent 尚未完成安装或缺少访问令牌', 'data' => null, 'body' => ''];
    }

    $request = ['ok' => false, 'status' => 0, 'errno' => 0, 'error' => '', 'msg' => '', 'data' => null, 'body' => ''];
    $attemptUrls = [rtrim($service_url, '/') . $path];
    if ($container_name !== '' && host_agent_socket_available()) {
        $inspect = host_agent_docker_request('GET', '/containers/' . rawurlencode($container_name) . '/json');
        if ($inspect['ok'] && is_array($inspect['json'])) {
            $ip = host_agent_container_ip_from_inspect($inspect['json']);
            if ($ip !== '') {
                $attemptUrls[] = 'http://' . $ip . ':' . HOST_AGENT_DEFAULT_PORT . $path;
            }
        }
    }
    $attemptUrls = array_values(array_unique(array_filter($attemptUrls)));

    for ($attempt = 0; $attempt < 4; $attempt += 1) {
        foreach ($attemptUrls as $url) {
            $request = host_agent_http_request($url, $method, $token, $payload);
            if (!empty($request['ok'])) {
                break 2;
            }
        }
        $retryable = in_array((int)($request['errno'] ?? 0), [6, 7, 28], true)
            || in_array((int)($request['status'] ?? 0), [0, 502, 503, 504], true);
        if (!$retryable) {
            break;
        }
        usleep(250000);
    }

    return [
        'ok' => (bool)$request['ok'],
        'status' => (int)$request['status'],
        'msg' => (string)($request['error'] ?: ($request['msg'] ?? '')),
        'data' => $request['data'] ?? null,
        'body' => (string)($request['body'] ?? ''),
    ];
}

function host_agent_ssh_status(): array {
    return host_agent_api_request('GET', '/ssh/status');
}

function host_agent_ssh_config_read(): array {
    return host_agent_api_request('GET', '/ssh/config');
}

function host_agent_ssh_config_save(string $content): array {
    return host_agent_api_request('POST', '/ssh/config', ['content' => $content]);
}

function host_agent_ssh_service_action(string $action): array {
    return host_agent_api_request('POST', '/ssh/action', ['action' => $action]);
}

function host_agent_ssh_validate_config(string $content): array {
    return host_agent_api_request('POST', '/ssh/config/validate', ['content' => $content]);
}

function host_agent_ssh_structured_save(array $payload): array {
    return host_agent_api_request('POST', '/ssh/config/structured', $payload);
}

function host_agent_ssh_restore_last_backup(): array {
    return host_agent_api_request('POST', '/ssh/config/restore-last');
}

function host_agent_ssh_toggle_enable(bool $enabled): array {
    return host_agent_api_request('POST', '/ssh/enable', ['enabled' => $enabled]);
}

function host_agent_ssh_install_service(): array {
    return host_agent_api_request('POST', '/ssh/install');
}

function host_agent_ssh_target_status(array $target): array {
    return host_agent_api_request('POST', '/ssh/target/status', ['target' => $target]);
}

function host_agent_ssh_target_config_read(array $target): array {
    return host_agent_api_request('POST', '/ssh/target/config/read', ['target' => $target]);
}

function host_agent_ssh_target_config_save(array $target, string $content): array {
    return host_agent_api_request('POST', '/ssh/target/config/save', ['target' => $target, 'content' => $content]);
}

function host_agent_ssh_target_config_apply(array $target, string $content, bool $restartAfterSave, bool $rollbackOnFailure): array {
    return host_agent_api_request('POST', '/ssh/target/config/apply', [
        'target' => $target,
        'content' => $content,
        'restart_after_save' => $restartAfterSave,
        'rollback_on_failure' => $rollbackOnFailure,
    ]);
}

function host_agent_ssh_target_validate_config(array $target, string $content): array {
    return host_agent_api_request('POST', '/ssh/target/config/validate', ['target' => $target, 'content' => $content]);
}

function host_agent_ssh_target_structured_save(array $target, array $payload): array {
    return host_agent_api_request('POST', '/ssh/target/config/structured', ['target' => $target] + $payload);
}

function host_agent_ssh_target_restore_last_backup(array $target): array {
    return host_agent_api_request('POST', '/ssh/target/config/restore-last', ['target' => $target]);
}

function host_agent_ssh_target_service_action(array $target, string $action): array {
    return host_agent_api_request('POST', '/ssh/target/action', ['target' => $target, 'action' => $action]);
}

function host_agent_ssh_target_toggle_enable(array $target, bool $enabled): array {
    return host_agent_api_request('POST', '/ssh/target/enable', ['target' => $target, 'enabled' => $enabled]);
}

function host_agent_ssh_target_install_service(array $target): array {
    return host_agent_api_request('POST', '/ssh/target/install', ['target' => $target]);
}

function host_agent_authorized_keys_list(array $target, string $user): array {
    return host_agent_api_request('POST', '/ssh/authorized-keys/list', ['target' => $target, 'user' => $user]);
}

function host_agent_authorized_keys_add(array $target, string $user, string $publicKey): array {
    return host_agent_api_request('POST', '/ssh/authorized-keys/add', ['target' => $target, 'user' => $user, 'public_key' => $publicKey]);
}

function host_agent_authorized_keys_remove(array $target, string $user, string $lineHash): array {
    return host_agent_api_request('POST', '/ssh/authorized-keys/remove', ['target' => $target, 'user' => $user, 'line_hash' => $lineHash]);
}

function host_agent_remote_test(array $target): array {
    return host_agent_api_request('POST', '/remote/test', ['target' => $target]);
}

function host_agent_fs_list(array $target, string $path): array {
    return host_agent_api_request('POST', '/fs/list', ['target' => $target, 'path' => $path]);
}

function host_agent_fs_read(array $target, string $path): array {
    return host_agent_api_request('POST', '/fs/read', ['target' => $target, 'path' => $path]);
}

function host_agent_fs_write(array $target, string $path, string $content = '', ?string $contentBase64 = null): array {
    $payload = ['target' => $target, 'path' => $path];
    if ($contentBase64 !== null) {
        $payload['content_base64'] = $contentBase64;
    } else {
        $payload['content'] = $content;
    }
    return host_agent_api_request('POST', '/fs/write', $payload);
}

function host_agent_fs_delete(array $target, string $path): array {
    return host_agent_api_request('POST', '/fs/delete', ['target' => $target, 'path' => $path]);
}

function host_agent_fs_mkdir(array $target, string $path): array {
    return host_agent_api_request('POST', '/fs/mkdir', ['target' => $target, 'path' => $path]);
}

function host_agent_fs_rename(array $target, string $sourcePath, string $targetPath): array {
    return host_agent_api_request('POST', '/fs/rename', ['target' => $target, 'source_path' => $sourcePath, 'target_path' => $targetPath]);
}

function host_agent_fs_copy(array $target, string $sourcePath, string $targetPath): array {
    return host_agent_api_request('POST', '/fs/copy', ['target' => $target, 'source_path' => $sourcePath, 'target_path' => $targetPath]);
}

function host_agent_fs_move(array $target, string $sourcePath, string $targetPath): array {
    return host_agent_api_request('POST', '/fs/move', ['target' => $target, 'source_path' => $sourcePath, 'target_path' => $targetPath]);
}

function host_agent_fs_search(array $target, string $path, string $keyword, int $limit = 200): array {
    return host_agent_api_request('POST', '/fs/search', ['target' => $target, 'path' => $path, 'keyword' => $keyword, 'limit' => $limit]);
}

function host_agent_fs_stat(array $target, string $path): array {
    return host_agent_api_request('POST', '/fs/stat', ['target' => $target, 'path' => $path]);
}

function host_agent_fs_chmod(array $target, string $path, string $mode): array {
    return host_agent_api_request('POST', '/fs/chmod', ['target' => $target, 'path' => $path, 'mode' => $mode]);
}

function host_agent_fs_chown(array $target, string $path, string $owner): array {
    return host_agent_api_request('POST', '/fs/chown', ['target' => $target, 'path' => $path, 'owner' => $owner]);
}

function host_agent_fs_chgrp(array $target, string $path, string $group): array {
    return host_agent_api_request('POST', '/fs/chgrp', ['target' => $target, 'path' => $path, 'group' => $group]);
}

function host_agent_fs_apply_acl(array $target, string $path, string $owner = '', string $group = '', string $mode = '', bool $recursive = false): array {
    return host_agent_api_request('POST', '/fs/acl/apply', [
        'target' => $target,
        'path' => $path,
        'owner' => $owner,
        'group' => $group,
        'mode' => $mode,
        'recursive' => $recursive,
    ]);
}

function host_agent_fs_archive(array $target, string $path, string $archivePath): array {
    return host_agent_api_request('POST', '/fs/archive', ['target' => $target, 'path' => $path, 'archive_path' => $archivePath]);
}

function host_agent_fs_extract(array $target, string $path, string $destination): array {
    return host_agent_api_request('POST', '/fs/extract', ['target' => $target, 'path' => $path, 'destination' => $destination]);
}

function host_agent_fs_archive_async(array $target, string $path, string $archivePath): array {
    if (($target['type'] ?? 'local') === 'remote') {
        $root = '';
    } else {
        $root = !empty($target['root']) ? (string)$target['root'] : (host_agent_install_mode() === 'simulate' ? '/var/www/nav/data/host-agent-sim-root' : '/hostfs');
    }
    return host_agent_api_request('POST', '/task/submit', [
        'action' => 'archive_compress',
        'payload' => ['paths' => [$path], 'dest_path' => $archivePath, 'format' => 'tar.gz', 'root' => $root],
    ]);
}

function host_agent_fs_extract_async(array $target, string $path, string $destination): array {
    if (($target['type'] ?? 'local') === 'remote') {
        $root = '';
    } else {
        $root = !empty($target['root']) ? (string)$target['root'] : (host_agent_install_mode() === 'simulate' ? '/var/www/nav/data/host-agent-sim-root' : '/hostfs');
    }
    return host_agent_api_request('POST', '/task/submit', [
        'action' => 'archive_extract',
        'payload' => ['path' => $path, 'dest_dir' => $destination, 'root' => $root],
    ]);
}

function host_agent_remote_exec_command(array $target, string $command): array {
    return host_agent_api_request('POST', '/remote/exec', ['target' => $target, 'command' => $command]);
}

function host_agent_terminal_open(array $target, bool $persist = true, int $idleMinutes = 120): array {
    return host_agent_api_request('POST', '/terminal/open', [
        'target' => $target,
        'persist' => $persist,
        'idle_minutes' => $idleMinutes,
    ]);
}

function host_agent_terminal_list(): array {
    return host_agent_api_request('GET', '/terminal/list');
}

function host_agent_terminal_read(string $id): array {
    return host_agent_api_request('GET', '/terminal/read?id=' . rawurlencode($id));
}

function host_agent_terminal_write(string $id, string $data): array {
    return host_agent_api_request('POST', '/terminal/write', ['id' => $id, 'data' => $data]);
}

function host_agent_terminal_close(string $id): array {
    return host_agent_api_request('POST', '/terminal/close', ['id' => $id]);
}

function host_agent_system_overview(): array {
    return host_agent_api_request('GET', '/system/overview');
}

function host_agent_process_list(string $keyword = '', string $sort = 'cpu', int $limit = 100): array {
    return host_agent_api_request('POST', '/process/list', [
        'keyword' => $keyword,
        'sort' => $sort,
        'limit' => $limit,
    ]);
}

function host_agent_process_kill(int $pid, string $signal = 'TERM'): array {
    return host_agent_api_request('POST', '/process/kill', [
        'pid' => $pid,
        'signal' => $signal,
    ]);
}

function host_agent_service_list(string $keyword = '', int $limit = 120): array {
    return host_agent_api_request('POST', '/service/list', [
        'keyword' => $keyword,
        'limit' => $limit,
    ]);
}

function host_agent_service_action(string $service, string $action): array {
    return host_agent_api_request('POST', '/service/action', [
        'service' => $service,
        'service_action' => $action,
    ]);
}

function host_agent_service_logs(string $service, int $limit = 120): array {
    return host_agent_api_request('POST', '/service/logs', [
        'service' => $service,
        'limit' => $limit,
    ]);
}

function host_agent_network_overview(int $limit = 120): array {
    return host_agent_api_request('POST', '/network/overview', ['limit' => $limit]);
}

function host_agent_docker_summary(): array {
    return host_agent_api_request('GET', '/docker/summary');
}

function host_agent_docker_containers_list(bool $all = true): array {
    return host_agent_api_request('GET', '/docker/containers?all=' . ($all ? '1' : '0'));
}

function host_agent_docker_container_action(string $id, string $action): array {
    return host_agent_api_request('POST', '/docker/container/action', [
        'id' => $id,
        'container_action' => $action,
    ]);
}

function host_agent_docker_container_delete(string $id, bool $force = false): array {
    return host_agent_api_request('POST', '/docker/container/delete', [
        'id' => $id,
        'force' => $force,
    ]);
}

function host_agent_docker_container_logs(string $id, int $tail = 200): array {
    return host_agent_api_request('POST', '/docker/container/logs', [
        'id' => $id,
        'tail' => $tail,
    ]);
}

function host_agent_docker_container_inspect(string $id): array {
    return host_agent_api_request('POST', '/docker/container/inspect', ['id' => $id]);
}

function host_agent_docker_container_stats(string $id): array {
    return host_agent_api_request('POST', '/docker/container/stats', ['id' => $id]);
}

function host_agent_docker_images_list(): array {
    return host_agent_api_request('GET', '/docker/images');
}

function host_agent_docker_volumes_list(): array {
    return host_agent_api_request('GET', '/docker/volumes');
}

function host_agent_docker_networks_list(): array {
    return host_agent_api_request('GET', '/docker/networks');
}

function host_agent_user_list(string $keyword = ''): array {
    return host_agent_api_request('POST', '/user/list', ['keyword' => $keyword]);
}

function host_agent_user_save(array $payload): array {
    return host_agent_api_request('POST', '/user/save', $payload);
}

function host_agent_user_delete(string $username, bool $removeHome = false): array {
    return host_agent_api_request('POST', '/user/delete', [
        'username' => $username,
        'remove_home' => $removeHome,
    ]);
}

function host_agent_user_password(string $username, string $password): array {
    return host_agent_api_request('POST', '/user/password', [
        'username' => $username,
        'password' => $password,
    ]);
}

function host_agent_user_lock(string $username, bool $locked): array {
    return host_agent_api_request('POST', '/user/lock', [
        'username' => $username,
        'locked' => $locked,
    ]);
}

function host_agent_group_list(string $keyword = ''): array {
    return host_agent_api_request('POST', '/group/list', ['keyword' => $keyword]);
}

function host_agent_group_save(array $payload): array {
    return host_agent_api_request('POST', '/group/save', $payload);
}

function host_agent_group_delete(string $groupname): array {
    return host_agent_api_request('POST', '/group/delete', ['groupname' => $groupname]);
}

function host_agent_sftp_status(): array {
    return host_agent_api_request('GET', '/share/sftp/status');
}

function host_agent_sftp_policy_list(): array {
    return host_agent_api_request('GET', '/share/sftp/policies');
}

function host_agent_sftp_policy_save(array $payload): array {
    return host_agent_api_request('POST', '/share/sftp/policy/save', $payload);
}

function host_agent_sftp_policy_delete(string $username): array {
    return host_agent_api_request('POST', '/share/sftp/policy/delete', ['username' => $username]);
}

function host_agent_smb_status(): array {
    return host_agent_api_request('GET', '/share/smb/status');
}

function host_agent_smb_share_list(): array {
    return host_agent_api_request('GET', '/share/smb/shares');
}

function host_agent_smb_share_save(array $payload): array {
    return host_agent_api_request('POST', '/share/smb/share/save', $payload);
}

function host_agent_smb_share_delete(string $name): array {
    return host_agent_api_request('POST', '/share/smb/share/delete', ['name' => $name]);
}

function host_agent_smb_install(): array {
    return host_agent_api_request('POST', '/share/smb/install');
}

function host_agent_smb_action(string $action): array {
    return host_agent_api_request('POST', '/share/smb/action', ['action' => $action]);
}

function host_agent_ftp_status(): array {
    return host_agent_api_request('GET', '/share/ftp/status');
}

function host_agent_ftp_settings_save(array $payload): array {
    return host_agent_api_request('POST', '/share/ftp/settings/save', $payload);
}

function host_agent_ftp_install(): array {
    return host_agent_api_request('POST', '/share/ftp/install');
}

function host_agent_ftp_action(string $action): array {
    return host_agent_api_request('POST', '/share/ftp/action', ['action' => $action]);
}

function host_agent_smb_uninstall(): array {
    return host_agent_api_request('POST', '/share/smb/uninstall');
}

function host_agent_ftp_uninstall(): array {
    return host_agent_api_request('POST', '/share/ftp/uninstall');
}

function host_agent_nfs_status(): array {
    return host_agent_api_request('GET', '/share/nfs/status');
}

function host_agent_nfs_export_save(array $payload): array {
    return host_agent_api_request('POST', '/share/nfs/export/save', $payload);
}

function host_agent_nfs_export_delete(string $path): array {
    return host_agent_api_request('POST', '/share/nfs/export/delete', ['path' => $path]);
}

function host_agent_nfs_install(): array {
    return host_agent_api_request('POST', '/share/nfs/install');
}

function host_agent_nfs_uninstall(): array {
    return host_agent_api_request('POST', '/share/nfs/uninstall');
}

function host_agent_nfs_action(string $action): array {
    return host_agent_api_request('POST', '/share/nfs/action', ['action' => $action]);
}

function host_agent_afp_status(): array {
    return host_agent_api_request('GET', '/share/afp/status');
}

function host_agent_afp_share_save(array $payload): array {
    return host_agent_api_request('POST', '/share/afp/share/save', $payload);
}

function host_agent_afp_share_delete(string $name): array {
    return host_agent_api_request('POST', '/share/afp/share/delete', ['name' => $name]);
}

function host_agent_afp_install(): array {
    return host_agent_api_request('POST', '/share/afp/install');
}

function host_agent_afp_uninstall(): array {
    return host_agent_api_request('POST', '/share/afp/uninstall');
}

function host_agent_afp_action(string $action): array {
    return host_agent_api_request('POST', '/share/afp/action', ['action' => $action]);
}

function host_agent_async_status(): array {
    return host_agent_api_request('GET', '/share/async/status');
}

function host_agent_async_module_save(array $payload): array {
    return host_agent_api_request('POST', '/share/async/module/save', $payload);
}

function host_agent_async_module_delete(string $name): array {
    return host_agent_api_request('POST', '/share/async/module/delete', ['name' => $name]);
}

function host_agent_async_install(): array {
    return host_agent_api_request('POST', '/share/async/install');
}

function host_agent_async_uninstall(): array {
    return host_agent_api_request('POST', '/share/async/uninstall');
}

function host_agent_async_action(string $action): array {
    return host_agent_api_request('POST', '/share/async/action', ['action' => $action]);
}

function host_agent_share_snapshot(string $service): array {
    return host_agent_api_request('GET', '/share/snapshot?service=' . rawurlencode($service));
}

function host_agent_share_snapshot_restore(string $service, array $files): array {
    return host_agent_api_request('POST', '/share/snapshot/restore', ['service' => $service, 'files' => $files]);
}

function host_agent_status_summary(): array {
    $self = host_agent_detect_self_container();
    $container_name = host_agent_container_name($self['ok'] ? (string)$self['container_name'] : null);
    $state = host_agent_load_state();
    $token = trim((string)($state['token'] ?? ''));
    $service_url = trim((string)($state['service_url'] ?? ''));
    if ($service_url === '') {
        $service_url = host_agent_default_url($container_name);
    }

    $summary = [
        'ok' => true,
        'docker_socket_path' => host_agent_docker_socket_path(),
        'docker_socket_mounted' => host_agent_socket_mounted(),
        'docker_access_method' => host_agent_docker_access_method(),
        'docker_accessible' => host_agent_socket_available(),
        'docker_mount_hint' => host_agent_mount_hint(),
        'install_mode' => host_agent_install_mode(),
        'container_name' => $container_name,
        'service_url' => $service_url,
        'installed' => false,
        'running' => false,
        'healthy' => false,
        'status' => 'not_installed',
        'message' => '',
        'state' => $state,
    ];

    if (!$summary['docker_socket_mounted']) {
        $summary['message'] = '当前容器未挂载可用的 docker.sock，无法在后台一键安装 host-agent。';
        return $summary;
    }
    if (!$summary['docker_accessible']) {
        $summary['message'] = '当前容器已经挂载 docker.sock，但后台进程还没有足够权限访问它；请确认镜像已升级到包含 host-agent Docker 代理的新版本，或检查 sudo 白名单是否生效。';
        return $summary;
    }

    $inspect = host_agent_docker_request('GET', '/containers/' . rawurlencode($container_name) . '/json');
    if ($inspect['status'] === 404) {
        $summary['message'] = '未发现 host-agent 容器，可以在本页执行一键安装。';
        return $summary;
    }
    if (!$inspect['ok'] || !is_array($inspect['json'])) {
        $summary['status'] = 'error';
        $summary['message'] = '读取 host-agent 容器状态失败：' . ($inspect['error'] ?: $inspect['body']);
        return $summary;
    }

    $data = $inspect['json'];
    $summary['installed'] = true;
    $summary['running'] = (bool)($data['State']['Running'] ?? false);
    $summary['status'] = strtolower((string)($data['State']['Status'] ?? 'created'));
    $summary['message'] = $summary['running'] ? 'host-agent 容器已启动。' : 'host-agent 容器已安装，但当前未运行。';
    $summary['container'] = [
        'id' => (string)($data['Id'] ?? ''),
        'image' => (string)($data['Config']['Image'] ?? ''),
        'created' => (string)($data['Created'] ?? ''),
    ];

    if ($summary['running'] && $token !== '') {
        $probe = host_agent_probe($service_url, $token);
        if (!$probe['ok']) {
            $ip = host_agent_container_ip_from_inspect($data);
            if ($ip !== '') {
                $fallbackUrl = 'http://' . $ip . ':' . HOST_AGENT_DEFAULT_PORT;
                $retry = host_agent_probe($fallbackUrl, $token);
                if ($retry['ok']) {
                    $probe = $retry;
                    $summary['service_url'] = $fallbackUrl;
                }
            }
        }
        if ($probe['ok']) {
            $summary['healthy'] = true;
            $summary['message'] = 'host-agent 已运行并通过健康检查。';
            $summary['agent_meta'] = $probe['data']['data'] ?? [];
        } else {
            $summary['message'] = 'host-agent 容器已运行，但健康检查暂未通过：' . ($probe['error'] ?: ('HTTP ' . $probe['status']));
        }
    }

    return $summary;
}

function host_agent_install(): array {
    $status = host_agent_status_summary();
    if (!$status['docker_accessible']) {
        return ['ok' => false, 'msg' => $status['message'] . ' ' . $status['docker_mount_hint']];
    }

    if ($status['healthy']) {
        return ['ok' => true, 'msg' => 'host-agent 已经安装并正常运行。安装完成后请从当前应用容器移除 docker.sock 挂载；后续只有升级或重装 host-agent 时才需要再次挂回。'];
    }

    $self = host_agent_detect_self_container();
    if (!$self['ok']) {
        return ['ok' => false, 'msg' => (string)$self['msg']];
    }

    $container_name = (string)$status['container_name'];
    if ($status['installed'] && !$status['running']) {
        $start = host_agent_docker_request('POST', '/containers/' . rawurlencode($container_name) . '/start');
        if (!$start['ok'] && $start['status'] !== 304) {
            return ['ok' => false, 'msg' => '启动已存在的 host-agent 容器失败：' . ($start['error'] ?: $start['body'])];
        }
    } elseif (!$status['installed']) {
        $token = host_agent_token();
        $mode = host_agent_install_mode();
        $cmd = [
            'php',
            '/var/www/nav/cli/host_agent.php',
            'serve',
            '--listen=0.0.0.0:' . HOST_AGENT_DEFAULT_PORT,
            '--root=' . ($mode === 'host' ? '/hostfs' : '/var/www/nav/data/host-agent-sim-root'),
        ];

        $payload = [
            'Image' => (string)$self['image'],
            'Cmd' => $cmd,
            'Env' => [
                'HOST_AGENT_TOKEN=' . $token,
                'HOST_AGENT_MODE=' . $mode,
                'HOST_AGENT_PORT=' . HOST_AGENT_DEFAULT_PORT,
            ],
            'Labels' => [
                'com.simple-homepage.host-agent' => '1',
                'com.simple-homepage.managed-by' => (string)$self['container_name'],
            ],
            'ExposedPorts' => [
                HOST_AGENT_DEFAULT_PORT . '/tcp' => new stdClass(),
            ],
            'HostConfig' => [
                'RestartPolicy' => ['Name' => 'unless-stopped'],
            ],
            'NetworkingConfig' => [
                'EndpointsConfig' => [
                    (string)$self['network'] => new stdClass(),
                ],
            ],
        ];

        if ($mode === 'host') {
            $payload['HostConfig']['Privileged'] = true;
            $payload['HostConfig']['PidMode'] = 'host';
            $payload['HostConfig']['Binds'] = ['/:/hostfs'];
        } else {
            $payload['HostConfig']['Binds'] = [];
            $dataMountSource = trim((string)($self['data_mount_source'] ?? ''));
            if ($dataMountSource !== '') {
                $payload['HostConfig']['Binds'][] = $dataMountSource . ':/var/www/nav/data';
            }
        }
        if (host_agent_socket_mounted()) {
            $payload['HostConfig']['Binds'][] = host_agent_docker_socket_path() . ':' . host_agent_docker_socket_path();
        }
        $appMountSource = trim((string)($self['app_mount_source'] ?? ''));
        if ($appMountSource !== '') {
            $payload['HostConfig']['Binds'][] = $appMountSource . ':/var/www/nav';
        }

        $create = host_agent_docker_request('POST', '/containers/create?name=' . rawurlencode($container_name), $payload);
        if (!$create['ok'] || !is_array($create['json'])) {
            return ['ok' => false, 'msg' => '创建 host-agent 容器失败：' . ($create['error'] ?: $create['body'])];
        }
        $container_id = (string)($create['json']['Id'] ?? '');
        if ($container_id === '') {
            return ['ok' => false, 'msg' => 'Docker API 未返回 host-agent 容器 ID'];
        }
        $start = host_agent_docker_request('POST', '/containers/' . rawurlencode($container_id) . '/start');
        if (!$start['ok'] && $start['status'] !== 304) {
            return ['ok' => false, 'msg' => '启动 host-agent 容器失败：' . ($start['error'] ?: $start['body'])];
        }

        host_agent_save_state([
            'token' => $token,
            'container_name' => $container_name,
            'service_url' => host_agent_default_url($container_name),
            'image' => (string)$self['image'],
            'installed_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
            'install_mode' => $mode,
        ]);
    }

    $state = host_agent_load_state();
    $token = trim((string)($state['token'] ?? ''));
    $service_url = trim((string)($state['service_url'] ?? host_agent_default_url($container_name)));
    $probe_error = '';
    for ($i = 0; $i < 8; $i++) {
        usleep(300000);
        $probe = host_agent_probe($service_url, $token);
        if ($probe['ok']) {
            return [
                'ok' => true,
                'msg' => 'host-agent 已安装并通过健康检查。当前后台的一键安装能力依赖 docker.sock；请在确认 host-agent 功能正常后，从当前应用容器移除 docker.sock 挂载，后续只有升级或重装 host-agent 时才需要再次挂回。',
            ];
        }
        $probe_error = $probe['error'] ?: ('HTTP ' . $probe['status']);
    }

    return [
        'ok' => false,
        'msg' => 'host-agent 容器已创建，但健康检查未通过：' . $probe_error,
    ];
}

function host_agent_stop(): array {
    $status = host_agent_status_summary();
    if (!$status['docker_accessible']) {
        return ['ok' => false, 'msg' => $status['message'] . ' ' . $status['docker_mount_hint']];
    }
    if (!$status['installed']) {
        return ['ok' => false, 'msg' => 'host-agent 尚未安装，无需停止。'];
    }
    $container_name = (string)$status['container_name'];
    $stop = host_agent_docker_request('POST', '/containers/' . rawurlencode($container_name) . '/stop?t=30');
    if ($stop['ok'] || $stop['status'] === 304) {
        return ['ok' => true, 'msg' => 'host-agent 已停止。'];
    }
    return ['ok' => false, 'msg' => '停止 host-agent 失败：' . ($stop['error'] ?: $stop['body'])];
}

function host_agent_restart(): array {
    $status = host_agent_status_summary();
    if (!$status['docker_accessible']) {
        return ['ok' => false, 'msg' => $status['message'] . ' ' . $status['docker_mount_hint']];
    }
    if (!$status['installed']) {
        return ['ok' => false, 'msg' => 'host-agent 尚未安装，无法重启。请先执行一键安装。'];
    }
    $container_name = (string)$status['container_name'];
    $restart = host_agent_docker_request('POST', '/containers/' . rawurlencode($container_name) . '/restart?t=30');
    if ($restart['ok']) {
        return ['ok' => true, 'msg' => 'host-agent 已重启。'];
    }
    return ['ok' => false, 'msg' => '重启 host-agent 失败：' . ($restart['error'] ?: $restart['body'])];
}

// ============================================================
// Package Manager SDK (Phase 1)
// ============================================================

function host_agent_package_manager(): array {
    return host_agent_api_request('GET', '/package/manager');
}

function host_agent_package_search(string $keyword, int $limit = 50): array {
    return host_agent_api_request('POST', '/package/search', ['keyword' => $keyword, 'limit' => $limit]);
}

function host_agent_package_info(string $pkg): array {
    return host_agent_api_request('POST', '/package/info', ['pkg' => $pkg]);
}

function host_agent_package_install(string $pkg): array {
    return host_agent_api_request('POST', '/package/install', ['pkg' => $pkg]);
}

function host_agent_package_remove(string $pkg, bool $purge = false): array {
    return host_agent_api_request('POST', '/package/remove', ['pkg' => $pkg, 'purge' => $purge]);
}

function host_agent_package_update(string $pkg): array {
    return host_agent_api_request('POST', '/package/update', ['pkg' => $pkg]);
}

function host_agent_package_upgrade_all(): array {
    return host_agent_api_request('POST', '/package/upgrade-all');
}

function host_agent_package_list(int $limit = 500): array {
    return host_agent_api_request('POST', '/package/list', ['limit' => $limit]);
}

// ============================================================
// Configuration Manager SDK (Phase 2)
// ============================================================

function host_agent_config_definitions(): array {
    return host_agent_api_request('GET', '/config/definitions');
}

function host_agent_config_read(string $configId): array {
    return host_agent_api_request('POST', '/config/read', ['config_id' => $configId]);
}

function host_agent_config_apply(string $configId, string $content, bool $validateOnly = false): array {
    return host_agent_api_request('POST', '/config/apply', [
        'config_id' => $configId,
        'content' => $content,
        'validate_only' => $validateOnly,
    ]);
}

function host_agent_config_validate(string $configId, string $content): array {
    return host_agent_api_request('POST', '/config/validate', [
        'config_id' => $configId,
        'content' => $content,
    ]);
}

function host_agent_config_history(string $configId, int $limit = 10): array {
    return host_agent_api_request('POST', '/config/history', [
        'config_id' => $configId,
        'limit' => $limit,
    ]);
}

function host_agent_config_restore(string $configId, string $backupPath): array {
    return host_agent_api_request('POST', '/config/restore', [
        'config_id' => $configId,
        'backup_path' => $backupPath,
    ]);
}

// ============================================================
// Declarative Manifest SDK (Phase 3)
// ============================================================

function host_agent_manifest_apply(array $manifest): array {
    return host_agent_api_request('POST', '/manifest/apply', ['manifest' => $manifest]);
}

function host_agent_manifest_dry_run(array $manifest): array {
    return host_agent_api_request('POST', '/manifest/dry-run', ['manifest' => $manifest]);
}

function host_agent_manifest_validate(array $manifest): array {
    return host_agent_api_request('POST', '/manifest/validate', ['manifest' => $manifest]);
}

// ============================================================
// Async Task Queue SDK
// ============================================================

function host_agent_task_submit(string $action, array $payload): array {
    return host_agent_api_request('POST', '/task/submit', [
        'action' => $action,
        'payload' => $payload,
    ]);
}

function host_agent_task_status(string $taskId): array {
    return host_agent_api_request('GET', '/task/status?id=' . rawurlencode($taskId));
}

function host_agent_task_cancel(string $taskId): array {
    return host_agent_api_request('POST', '/task/cancel', ['id' => $taskId]);
}

function host_agent_task_list(): array {
    return host_agent_api_request('GET', '/task/list');
}

// ============================================================
// Archive Extract / Compress SDK
// ============================================================

function host_agent_archive_extract(string $path, string $destDir): array {
    return host_agent_api_request('POST', '/archive/extract', [
        'path' => $path,
        'dest_dir' => $destDir,
    ]);
}

function host_agent_archive_compress(array $paths, string $destPath, string $format = 'tar.gz'): array {
    return host_agent_api_request('POST', '/archive/compress', [
        'paths' => $paths,
        'dest_path' => $destPath,
        'format' => $format,
    ]);
}

function host_agent_archive_list(string $path): array {
    return host_agent_api_request('GET', '/archive/list?path=' . rawurlencode($path));
}

function host_agent_archive_tools(): array {
    return host_agent_api_request('GET', '/archive/tools');
}
