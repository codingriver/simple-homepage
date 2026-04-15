<?php
declare(strict_types=1);

require_once __DIR__ . '/shared/functions.php';
require_once __DIR__ . '/shared/host_agent_lib.php';
require_once __DIR__ . '/shared/ssh_manager_lib.php';
require_once __DIR__ . '/shared/share_service_lib.php';

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'XMLHttpRequest') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'msg' => '需要 AJAX 请求'], JSON_UNESCAPED_UNICODE);
    exit;
}

$user = auth_require_permission('ssh.view');
$action = trim((string)($_REQUEST['action'] ?? ''));

function host_api_send(array $result, int $fallbackStatus = 422): void {
    $payload = is_array($result['data'] ?? null) ? $result['data'] : $result;
    if (!array_key_exists('ok', $payload)) {
        $payload['ok'] = (bool)($result['ok'] ?? false);
    }
    if (!empty($result['msg']) && empty($payload['msg'])) {
        $payload['msg'] = (string)$result['msg'];
    }
    http_response_code(200);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function host_api_target_payload(string $hostId): array {
    if ($hostId === '' || $hostId === 'local') {
        return ['type' => 'local'];
    }
    $host = ssh_manager_find_host($hostId);
    if (!$host) {
        return [];
    }
    return ssh_manager_host_runtime_spec($host);
}

function host_api_require_any_permission(array $permissions): void {
    global $user;
    foreach ($permissions as $permission) {
        if (auth_user_has_permission((string)$permission, $user)) {
            return;
        }
    }
    auth_require_permission((string)($permissions[0] ?? 'ssh.manage'));
}

function host_api_request_list(string $key): array {
    $raw = $_POST[$key] ?? $_GET[$key] ?? [];
    if (is_array($raw)) {
        return $raw;
    }
    $value = trim((string)$raw);
    if ($value === '') {
        return [];
    }
    if (str_contains($value, ',')) {
        return array_values(array_filter(array_map('trim', explode(',', $value)), static fn(string $item): bool => $item !== ''));
    }
    return [$value];
}

function host_api_share_history_capture(string $service, string $action, array $meta = []): void {
    $snapshot = host_agent_share_snapshot($service);
    if (empty($snapshot['ok'])) {
        share_service_audit('share_history_capture_failed', ['service' => $service, 'action_name' => $action, 'msg' => (string)($snapshot['msg'] ?? '')] + $meta);
        return;
    }
    $payload = is_array($snapshot['data'] ?? null) ? $snapshot['data'] : $snapshot;
    share_service_history_write($service, $action, [
        'service' => $service,
        'files' => (array)($payload['files'] ?? []),
    ], $meta);
}

function host_api_targets_from_ids(array $hostIds): array {
    $targets = [];
    foreach ($hostIds as $hostId) {
        $id = trim((string)$hostId);
        if ($id === '' || $id === 'local') {
            continue;
        }
        $target = host_api_target_payload($id);
        if ($target) {
            $targets[$id] = $target;
        }
    }
    return $targets;
}

if ($action === 'remote_test') {
    host_api_require_any_permission(['ssh.manage', 'ssh.batch']);
    $hostId = trim((string)($_POST['host_id'] ?? ''));
    $target = host_api_target_payload($hostId);
    if (!$target) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'msg' => '远程主机不存在'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $result = host_agent_remote_test($target);
    ssh_manager_audit('remote_host_test', ['host_id' => $hostId, 'ok' => (bool)($result['ok'] ?? false)]);
    host_api_send($result);
}

if ($action === 'ssh_target_status') {
    $hostId = trim((string)($_REQUEST['host_id'] ?? 'local'));
    $target = host_api_target_payload($hostId);
    if (!$target) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'msg' => '目标主机不存在'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    host_api_send(host_agent_ssh_target_status($target));
}

if ($action === 'ssh_target_config_read') {
    $hostId = trim((string)($_REQUEST['host_id'] ?? 'local'));
    $target = host_api_target_payload($hostId);
    if (!$target) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'msg' => '目标主机不存在'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    host_api_send(host_agent_ssh_target_config_read($target));
}

if ($action === 'ssh_target_config_save') {
    host_api_require_any_permission(['ssh.config.manage', 'ssh.manage']);
    csrf_check();
    $hostId = trim((string)($_POST['host_id'] ?? 'local'));
    $target = host_api_target_payload($hostId);
    if (!$target) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'msg' => '目标主机不存在'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $result = host_agent_ssh_target_config_save($target, (string)($_POST['content'] ?? ''));
    ssh_manager_audit('ssh_config_save', ['host_id' => $hostId, 'ok' => (bool)($result['ok'] ?? false)]);
    host_api_send($result);
}

if ($action === 'ssh_target_config_apply') {
    host_api_require_any_permission(['ssh.config.manage', 'ssh.manage']);
    csrf_check();
    $hostId = trim((string)($_POST['host_id'] ?? 'local'));
    $target = host_api_target_payload($hostId);
    if (!$target) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'msg' => '目标主机不存在'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $result = host_agent_ssh_target_config_apply(
        $target,
        (string)($_POST['content'] ?? ''),
        ($_POST['restart_after_save'] ?? '0') === '1',
        ($_POST['rollback_on_failure'] ?? '1') === '1'
    );
    ssh_manager_audit('ssh_config_apply', ['host_id' => $hostId, 'restart_after_save' => ($_POST['restart_after_save'] ?? '0') === '1', 'ok' => (bool)($result['ok'] ?? false)]);
    host_api_send($result);
}

if ($action === 'ssh_target_config_validate') {
    host_api_require_any_permission(['ssh.config.manage', 'ssh.manage']);
    csrf_check();
    $hostId = trim((string)($_POST['host_id'] ?? 'local'));
    $target = host_api_target_payload($hostId);
    if (!$target) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'msg' => '目标主机不存在'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $result = host_agent_ssh_target_validate_config($target, (string)($_POST['content'] ?? ''));
    ssh_manager_audit('ssh_config_validate', ['host_id' => $hostId, 'ok' => (bool)($result['ok'] ?? false)]);
    host_api_send($result);
}

if ($action === 'ssh_target_structured_save') {
    host_api_require_any_permission(['ssh.config.manage', 'ssh.manage']);
    csrf_check();
    $hostId = trim((string)($_POST['host_id'] ?? 'local'));
    $target = host_api_target_payload($hostId);
    if (!$target) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'msg' => '目标主机不存在'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $result = host_agent_ssh_target_structured_save($target, [
        'port' => trim((string)($_POST['ssh_port'] ?? '22')),
        'listen_address' => trim((string)($_POST['listen_address'] ?? '')),
        'password_auth' => ($_POST['password_auth'] ?? '1') === '1',
        'pubkey_auth' => ($_POST['pubkey_auth'] ?? '1') === '1',
        'permit_root_login' => trim((string)($_POST['permit_root_login'] ?? 'prohibit-password')),
        'allow_users' => trim((string)($_POST['allow_users'] ?? '')),
        'allow_groups' => trim((string)($_POST['allow_groups'] ?? '')),
        'x11_forwarding' => ($_POST['x11_forwarding'] ?? '0') === '1',
        'max_auth_tries' => trim((string)($_POST['max_auth_tries'] ?? '6')),
        'client_alive_interval' => trim((string)($_POST['client_alive_interval'] ?? '0')),
        'client_alive_count_max' => trim((string)($_POST['client_alive_count_max'] ?? '3')),
    ]);
    ssh_manager_audit('ssh_structured_save', ['host_id' => $hostId, 'ok' => (bool)($result['ok'] ?? false)]);
    host_api_send($result);
}

if ($action === 'ssh_target_restore_backup') {
    host_api_require_any_permission(['ssh.config.manage', 'ssh.manage']);
    csrf_check();
    $hostId = trim((string)($_POST['host_id'] ?? 'local'));
    $target = host_api_target_payload($hostId);
    if (!$target) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'msg' => '目标主机不存在'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $result = host_agent_ssh_target_restore_last_backup($target);
    ssh_manager_audit('ssh_restore_backup', ['host_id' => $hostId, 'ok' => (bool)($result['ok'] ?? false)]);
    host_api_send($result);
}

if ($action === 'ssh_target_service_action') {
    host_api_require_any_permission(['ssh.service.manage', 'ssh.manage']);
    csrf_check();
    $hostId = trim((string)($_POST['host_id'] ?? 'local'));
    $serviceAction = trim((string)($_POST['service_action'] ?? ''));
    $target = host_api_target_payload($hostId);
    if (!$target) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'msg' => '目标主机不存在'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $result = host_agent_ssh_target_service_action($target, $serviceAction);
    ssh_manager_audit('ssh_service_action', ['host_id' => $hostId, 'service_action' => $serviceAction, 'ok' => (bool)($result['ok'] ?? false)]);
    host_api_send($result);
}

if ($action === 'ssh_target_toggle_enable') {
    host_api_require_any_permission(['ssh.service.manage', 'ssh.manage']);
    csrf_check();
    $hostId = trim((string)($_POST['host_id'] ?? 'local'));
    $enabled = ($_POST['enabled'] ?? '1') === '1';
    $target = host_api_target_payload($hostId);
    if (!$target) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'msg' => '目标主机不存在'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $result = host_agent_ssh_target_toggle_enable($target, $enabled);
    ssh_manager_audit('ssh_toggle_enable', ['host_id' => $hostId, 'enabled' => $enabled, 'ok' => (bool)($result['ok'] ?? false)]);
    host_api_send($result);
}

if ($action === 'ssh_target_install_service') {
    host_api_require_any_permission(['ssh.service.manage', 'ssh.manage']);
    csrf_check();
    $hostId = trim((string)($_POST['host_id'] ?? 'local'));
    $target = host_api_target_payload($hostId);
    if (!$target) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'msg' => '目标主机不存在'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $result = host_agent_ssh_target_install_service($target);
    ssh_manager_audit('ssh_install_service', ['host_id' => $hostId, 'ok' => (bool)($result['ok'] ?? false)]);
    host_api_send($result);
}

if ($action === 'file_list') {
    $hostId = trim((string)($_REQUEST['host_id'] ?? 'local'));
    $path = trim((string)($_REQUEST['path'] ?? '/'));
    $target = host_api_target_payload($hostId);
    if (!$target) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'msg' => '目标主机不存在'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $result = host_agent_fs_list($target, $path);
    host_api_send($result);
}

if ($action === 'file_read') {
    auth_require_permission('ssh.files');
    $hostId = trim((string)($_POST['host_id'] ?? 'local'));
    $path = trim((string)($_POST['path'] ?? '/'));
    $target = host_api_target_payload($hostId);
    if (!$target) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'msg' => '目标主机不存在'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $result = host_agent_fs_read($target, $path);
    host_api_send($result);
}

if ($action === 'file_write') {
    host_api_require_any_permission(['ssh.files.write', 'ssh.manage']);
    csrf_check();
    $hostId = trim((string)($_POST['host_id'] ?? 'local'));
    $path = trim((string)($_POST['path'] ?? '/'));
    $content = (string)($_POST['content'] ?? '');
    $contentBase64 = array_key_exists('content_base64', $_POST) ? (string)$_POST['content_base64'] : null;
    $target = host_api_target_payload($hostId);
    if (!$target) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'msg' => '目标主机不存在'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $result = host_agent_fs_write($target, $path, $content, $contentBase64);
    ssh_manager_audit('file_write', ['host_id' => $hostId, 'path' => $path, 'ok' => (bool)($result['ok'] ?? false)]);
    host_api_send($result);
}

if ($action === 'file_delete') {
    host_api_require_any_permission(['ssh.files.write', 'ssh.manage']);
    csrf_check();
    $hostId = trim((string)($_POST['host_id'] ?? 'local'));
    $path = trim((string)($_POST['path'] ?? '/'));
    $target = host_api_target_payload($hostId);
    if (!$target) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'msg' => '目标主机不存在'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $result = host_agent_fs_delete($target, $path);
    ssh_manager_audit('file_delete', ['host_id' => $hostId, 'path' => $path, 'ok' => (bool)($result['ok'] ?? false)]);
    host_api_send($result);
}

if ($action === 'file_mkdir') {
    host_api_require_any_permission(['ssh.files.write', 'ssh.manage']);
    csrf_check();
    $hostId = trim((string)($_POST['host_id'] ?? 'local'));
    $path = trim((string)($_POST['path'] ?? '/'));
    $target = host_api_target_payload($hostId);
    if (!$target) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'msg' => '目标主机不存在'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $result = host_agent_fs_mkdir($target, $path);
    ssh_manager_audit('file_mkdir', ['host_id' => $hostId, 'path' => $path, 'ok' => (bool)($result['ok'] ?? false)]);
    host_api_send($result);
}

if ($action === 'file_stat') {
    auth_require_permission('ssh.files');
    $hostId = trim((string)($_REQUEST['host_id'] ?? 'local'));
    $path = trim((string)($_REQUEST['path'] ?? '/'));
    $target = host_api_target_payload($hostId);
    if (!$target) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'msg' => '目标主机不存在'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    host_api_send(host_agent_fs_stat($target, $path));
}

if ($action === 'file_chmod') {
    host_api_require_any_permission(['ssh.files.write', 'ssh.manage']);
    csrf_check();
    $hostId = trim((string)($_POST['host_id'] ?? 'local'));
    $path = trim((string)($_POST['path'] ?? '/'));
    $mode = trim((string)($_POST['mode'] ?? ''));
    $target = host_api_target_payload($hostId);
    if (!$target) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'msg' => '目标主机不存在'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $result = host_agent_fs_chmod($target, $path, $mode);
    ssh_manager_audit('file_chmod', ['host_id' => $hostId, 'path' => $path, 'mode' => $mode, 'ok' => (bool)($result['ok'] ?? false)]);
    host_api_send($result);
}

if ($action === 'file_chown') {
    host_api_require_any_permission(['ssh.files.write', 'ssh.manage']);
    csrf_check();
    $hostId = trim((string)($_POST['host_id'] ?? 'local'));
    $path = trim((string)($_POST['path'] ?? '/'));
    $owner = trim((string)($_POST['owner'] ?? ''));
    $target = host_api_target_payload($hostId);
    if (!$target) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'msg' => '目标主机不存在'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $result = host_agent_fs_chown($target, $path, $owner);
    ssh_manager_audit('file_chown', ['host_id' => $hostId, 'path' => $path, 'owner' => $owner, 'ok' => (bool)($result['ok'] ?? false)]);
    host_api_send($result);
}

if ($action === 'file_chgrp') {
    host_api_require_any_permission(['ssh.files.write', 'ssh.manage']);
    csrf_check();
    $hostId = trim((string)($_POST['host_id'] ?? 'local'));
    $path = trim((string)($_POST['path'] ?? '/'));
    $group = trim((string)($_POST['group'] ?? ''));
    $target = host_api_target_payload($hostId);
    if (!$target) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'msg' => '目标主机不存在'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $result = host_agent_fs_chgrp($target, $path, $group);
    ssh_manager_audit('file_chgrp', ['host_id' => $hostId, 'path' => $path, 'group' => $group, 'ok' => (bool)($result['ok'] ?? false)]);
    host_api_send($result);
}

if ($action === 'share_path_stat') {
    auth_require_permission('ssh.files');
    $hostId = trim((string)($_REQUEST['host_id'] ?? 'local'));
    $path = trim((string)($_REQUEST['path'] ?? '/'));
    $target = host_api_target_payload($hostId);
    if (!$target) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'msg' => '目标主机不存在'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $result = host_agent_fs_stat($target, $path);
    share_service_audit('share_path_stat', ['host_id' => $hostId, 'path' => $path, 'ok' => (bool)($result['ok'] ?? false)]);
    host_api_send($result);
}

if ($action === 'share_path_apply_acl') {
    host_api_require_any_permission(['ssh.files.write', 'ssh.manage']);
    csrf_check();
    $hostId = trim((string)($_POST['host_id'] ?? 'local'));
    $path = trim((string)($_POST['path'] ?? '/'));
    $owner = trim((string)($_POST['owner'] ?? ''));
    $group = trim((string)($_POST['group'] ?? ''));
    $mode = trim((string)($_POST['mode'] ?? ''));
    $recursive = ($_POST['recursive'] ?? '0') === '1';
    $target = host_api_target_payload($hostId);
    if (!$target) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'msg' => '目标主机不存在'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $result = host_agent_fs_apply_acl($target, $path, $owner, $group, $mode, $recursive);
    share_service_audit('share_path_apply_acl', [
        'host_id' => $hostId,
        'path' => $path,
        'owner' => $owner,
        'group' => $group,
        'mode' => $mode,
        'recursive' => $recursive,
        'ok' => (bool)($result['ok'] ?? false),
    ]);
    host_api_send($result);
}

if ($action === 'file_archive') {
    host_api_require_any_permission(['ssh.files.write', 'ssh.manage']);
    csrf_check();
    $hostId = trim((string)($_POST['host_id'] ?? 'local'));
    $path = trim((string)($_POST['path'] ?? '/'));
    $archivePath = trim((string)($_POST['archive_path'] ?? ($path . '.tar.gz')));
    $target = host_api_target_payload($hostId);
    if (!$target) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'msg' => '目标主机不存在'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $result = host_agent_fs_archive($target, $path, $archivePath);
    ssh_manager_audit('file_archive', ['host_id' => $hostId, 'path' => $path, 'archive_path' => $archivePath, 'ok' => (bool)($result['ok'] ?? false)]);
    host_api_send($result);
}

if ($action === 'file_extract') {
    host_api_require_any_permission(['ssh.files.write', 'ssh.manage']);
    csrf_check();
    $hostId = trim((string)($_POST['host_id'] ?? 'local'));
    $path = trim((string)($_POST['path'] ?? '/'));
    $destination = trim((string)($_POST['destination'] ?? dirname($path)));
    $target = host_api_target_payload($hostId);
    if (!$target) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'msg' => '目标主机不存在'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $result = host_agent_fs_extract($target, $path, $destination);
    ssh_manager_audit('file_extract', ['host_id' => $hostId, 'path' => $path, 'destination' => $destination, 'ok' => (bool)($result['ok'] ?? false)]);
    host_api_send($result);
}

if ($action === 'authorized_keys_list') {
    auth_require_permission('ssh.files');
    $hostId = trim((string)($_REQUEST['host_id'] ?? 'local'));
    $userName = trim((string)($_REQUEST['user'] ?? 'root')) ?: 'root';
    $target = host_api_target_payload($hostId);
    if (!$target) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'msg' => '目标主机不存在'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    host_api_send(host_agent_authorized_keys_list($target, $userName));
}

if ($action === 'authorized_keys_add') {
    host_api_require_any_permission(['ssh.files.write', 'ssh.manage']);
    csrf_check();
    $hostId = trim((string)($_POST['host_id'] ?? 'local'));
    $userName = trim((string)($_POST['user'] ?? 'root')) ?: 'root';
    $publicKey = trim((string)($_POST['public_key'] ?? ''));
    $keyId = trim((string)($_POST['key_id'] ?? ''));
    if ($publicKey === '' && $keyId !== '') {
        $key = ssh_manager_find_key($keyId, true);
        $publicKey = trim((string)($key['public_key'] ?? ''));
    }
    $target = host_api_target_payload($hostId);
    if (!$target) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'msg' => '目标主机不存在'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $result = host_agent_authorized_keys_add($target, $userName, $publicKey);
    ssh_manager_audit('authorized_keys_add', ['host_id' => $hostId, 'user_name' => $userName, 'key_id' => $keyId, 'ok' => (bool)($result['ok'] ?? false)]);
    host_api_send($result);
}

if ($action === 'authorized_keys_remove') {
    host_api_require_any_permission(['ssh.files.write', 'ssh.manage']);
    csrf_check();
    $hostId = trim((string)($_POST['host_id'] ?? 'local'));
    $userName = trim((string)($_POST['user'] ?? 'root')) ?: 'root';
    $lineHash = trim((string)($_POST['line_hash'] ?? ''));
    $target = host_api_target_payload($hostId);
    if (!$target) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'msg' => '目标主机不存在'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $result = host_agent_authorized_keys_remove($target, $userName, $lineHash);
    ssh_manager_audit('authorized_keys_remove', ['host_id' => $hostId, 'user_name' => $userName, 'line_hash' => $lineHash, 'ok' => (bool)($result['ok'] ?? false)]);
    host_api_send($result);
}

if ($action === 'batch_test_hosts') {
    host_api_require_any_permission(['ssh.batch', 'ssh.manage']);
    csrf_check();
    $targets = host_api_targets_from_ids(host_api_request_list('host_ids'));
    $results = [];
    foreach ($targets as $hostId => $target) {
        $results[] = ['host_id' => $hostId] + host_agent_remote_test($target);
    }
    ssh_manager_audit('batch_test_hosts', ['host_count' => count($results)]);
    host_api_send(['ok' => true, 'data' => ['ok' => true, 'results' => $results]]);
}

if ($action === 'batch_exec_hosts') {
    host_api_require_any_permission(['ssh.batch', 'ssh.manage']);
    csrf_check();
    $command = trim((string)($_POST['command'] ?? ''));
    $targets = host_api_targets_from_ids(host_api_request_list('host_ids'));
    $results = [];
    foreach ($targets as $hostId => $target) {
        $results[] = ['host_id' => $hostId] + host_agent_remote_exec_command($target, $command);
    }
    ssh_manager_audit('batch_exec_hosts', ['host_count' => count($results), 'command' => $command]);
    host_api_send(['ok' => true, 'data' => ['ok' => true, 'results' => $results]]);
}

if ($action === 'batch_distribute_key') {
    host_api_require_any_permission(['ssh.batch', 'ssh.manage']);
    csrf_check();
    $keyId = trim((string)($_POST['key_id'] ?? ''));
    $userName = trim((string)($_POST['user'] ?? 'root')) ?: 'root';
    $key = ssh_manager_find_key($keyId, true);
    $publicKey = trim((string)($key['public_key'] ?? ''));
    $targets = host_api_targets_from_ids(host_api_request_list('host_ids'));
    $results = [];
    foreach ($targets as $hostId => $target) {
        $results[] = ['host_id' => $hostId] + host_agent_authorized_keys_add($target, $userName, $publicKey);
    }
    ssh_manager_audit('batch_distribute_key', ['host_count' => count($results), 'key_id' => $keyId, 'user_name' => $userName]);
    host_api_send(['ok' => true, 'data' => ['ok' => true, 'results' => $results]]);
}

if ($action === 'system_overview') {
    auth_require_permission('ssh.view');
    host_api_send(host_agent_system_overview());
}

if ($action === 'process_list') {
    auth_require_permission('ssh.view');
    host_api_send(host_agent_process_list(
        trim((string)($_REQUEST['keyword'] ?? '')),
        trim((string)($_REQUEST['sort'] ?? 'cpu')),
        (int)($_REQUEST['limit'] ?? 100)
    ));
}

if ($action === 'process_kill') {
    host_api_require_any_permission(['ssh.manage', 'ssh.service.manage']);
    csrf_check();
    $pid = (int)($_POST['pid'] ?? 0);
    $signal = trim((string)($_POST['signal'] ?? 'TERM'));
    $result = host_agent_process_kill($pid, $signal);
    ssh_manager_audit('host_process_kill', ['pid' => $pid, 'signal' => $signal, 'ok' => (bool)($result['ok'] ?? false)]);
    host_api_send($result);
}

if ($action === 'service_list') {
    auth_require_permission('ssh.view');
    host_api_send(host_agent_service_list(trim((string)($_REQUEST['keyword'] ?? '')), (int)($_REQUEST['limit'] ?? 120)));
}

if ($action === 'service_action_generic') {
    host_api_require_any_permission(['ssh.manage', 'ssh.service.manage']);
    csrf_check();
    $service = trim((string)($_POST['service'] ?? ''));
    $serviceAction = trim((string)($_POST['service_action'] ?? ''));
    $result = host_agent_service_action($service, $serviceAction);
    ssh_manager_audit('host_service_action', ['service' => $service, 'service_action' => $serviceAction, 'ok' => (bool)($result['ok'] ?? false)]);
    host_api_send($result);
}

if ($action === 'service_logs') {
    auth_require_permission('ssh.view');
    $service = trim((string)($_REQUEST['service'] ?? ''));
    host_api_send(host_agent_service_logs($service, (int)($_REQUEST['limit'] ?? 120)));
}

if ($action === 'network_overview') {
    auth_require_permission('ssh.view');
    host_api_send(host_agent_network_overview((int)($_REQUEST['limit'] ?? 120)));
}

if ($action === 'user_list') {
    auth_require_permission('ssh.view');
    host_api_send(host_agent_user_list(trim((string)($_REQUEST['keyword'] ?? ''))));
}

if ($action === 'user_save') {
    host_api_require_any_permission(['ssh.manage', 'ssh.service.manage']);
    csrf_check();
    $payload = [
        'username' => trim((string)($_POST['username'] ?? '')),
        'shell' => trim((string)($_POST['shell'] ?? '/bin/sh')),
        'home' => trim((string)($_POST['home'] ?? '')),
        'groups' => trim((string)($_POST['groups'] ?? '')),
        'gecos' => trim((string)($_POST['gecos'] ?? '')),
        'password' => (string)($_POST['password'] ?? ''),
    ];
    $result = host_agent_user_save($payload);
    ssh_manager_audit('host_user_save', ['username' => $payload['username'], 'groups' => $payload['groups'], 'ok' => (bool)($result['ok'] ?? false)]);
    host_api_send($result);
}

if ($action === 'user_delete') {
    host_api_require_any_permission(['ssh.manage', 'ssh.service.manage']);
    csrf_check();
    $username = trim((string)($_POST['username'] ?? ''));
    $removeHome = ($_POST['remove_home'] ?? '0') === '1';
    $result = host_agent_user_delete($username, $removeHome);
    ssh_manager_audit('host_user_delete', ['username' => $username, 'remove_home' => $removeHome, 'ok' => (bool)($result['ok'] ?? false)]);
    host_api_send($result);
}

if ($action === 'user_password') {
    host_api_require_any_permission(['ssh.manage', 'ssh.service.manage']);
    csrf_check();
    $username = trim((string)($_POST['username'] ?? ''));
    $result = host_agent_user_password($username, (string)($_POST['password'] ?? ''));
    ssh_manager_audit('host_user_password', ['username' => $username, 'ok' => (bool)($result['ok'] ?? false)]);
    host_api_send($result);
}

if ($action === 'user_lock') {
    host_api_require_any_permission(['ssh.manage', 'ssh.service.manage']);
    csrf_check();
    $username = trim((string)($_POST['username'] ?? ''));
    $locked = ($_POST['locked'] ?? '1') === '1';
    $result = host_agent_user_lock($username, $locked);
    ssh_manager_audit('host_user_lock', ['username' => $username, 'locked' => $locked, 'ok' => (bool)($result['ok'] ?? false)]);
    host_api_send($result);
}

if ($action === 'group_list') {
    auth_require_permission('ssh.view');
    host_api_send(host_agent_group_list(trim((string)($_REQUEST['keyword'] ?? ''))));
}

if ($action === 'group_save') {
    host_api_require_any_permission(['ssh.manage', 'ssh.service.manage']);
    csrf_check();
    $payload = [
        'groupname' => trim((string)($_POST['groupname'] ?? '')),
        'members' => trim((string)($_POST['members'] ?? '')),
    ];
    $result = host_agent_group_save($payload);
    ssh_manager_audit('host_group_save', ['groupname' => $payload['groupname'], 'members' => $payload['members'], 'ok' => (bool)($result['ok'] ?? false)]);
    host_api_send($result);
}

if ($action === 'group_delete') {
    host_api_require_any_permission(['ssh.manage', 'ssh.service.manage']);
    csrf_check();
    $groupname = trim((string)($_POST['groupname'] ?? ''));
    $result = host_agent_group_delete($groupname);
    ssh_manager_audit('host_group_delete', ['groupname' => $groupname, 'ok' => (bool)($result['ok'] ?? false)]);
    host_api_send($result);
}

if ($action === 'sftp_status') {
    auth_require_permission('ssh.view');
    host_api_send(host_agent_sftp_status());
}

if ($action === 'sftp_policy_list') {
    auth_require_permission('ssh.view');
    host_api_send(host_agent_sftp_policy_list());
}

if ($action === 'sftp_policy_save') {
    host_api_require_any_permission(['ssh.manage', 'ssh.config.manage']);
    csrf_check();
    $payload = [
        'username' => trim((string)($_POST['username'] ?? '')),
        'enabled' => ($_POST['enabled'] ?? '1') === '1',
        'sftp_only' => ($_POST['sftp_only'] ?? '1') === '1',
        'chroot_directory' => trim((string)($_POST['chroot_directory'] ?? '')),
        'force_internal_sftp' => ($_POST['force_internal_sftp'] ?? '1') === '1',
        'allow_password' => ($_POST['allow_password'] ?? '1') === '1',
        'allow_pubkey' => ($_POST['allow_pubkey'] ?? '1') === '1',
    ];
    $result = host_agent_sftp_policy_save($payload);
    ssh_manager_audit('host_sftp_policy_save', ['username' => $payload['username'], 'ok' => (bool)($result['ok'] ?? false)]);
    share_service_audit('sftp_policy_save', ['service' => 'sftp', 'username' => $payload['username'], 'ok' => (bool)($result['ok'] ?? false)]);
    if (!empty($result['ok'])) {
        host_api_share_history_capture('sftp', 'save_policy', ['username' => $payload['username']]);
    }
    host_api_send($result);
}

if ($action === 'sftp_policy_delete') {
    host_api_require_any_permission(['ssh.manage', 'ssh.config.manage']);
    csrf_check();
    $username = trim((string)($_POST['username'] ?? ''));
    $result = host_agent_sftp_policy_delete($username);
    ssh_manager_audit('host_sftp_policy_delete', ['username' => $username, 'ok' => (bool)($result['ok'] ?? false)]);
    share_service_audit('sftp_policy_delete', ['service' => 'sftp', 'username' => $username, 'ok' => (bool)($result['ok'] ?? false)]);
    if (!empty($result['ok'])) {
        host_api_share_history_capture('sftp', 'delete_policy', ['username' => $username]);
    }
    host_api_send($result);
}

if ($action === 'smb_status') {
    auth_require_permission('ssh.view');
    host_api_send(host_agent_smb_status());
}

if ($action === 'smb_share_list') {
    auth_require_permission('ssh.view');
    host_api_send(host_agent_smb_share_list());
}

if ($action === 'smb_share_save') {
    host_api_require_any_permission(['ssh.manage', 'ssh.service.manage']);
    csrf_check();
    $payload = [
        'name' => trim((string)($_POST['name'] ?? '')),
        'path' => trim((string)($_POST['path'] ?? '')),
        'comment' => trim((string)($_POST['comment'] ?? '')),
        'browseable' => ($_POST['browseable'] ?? '1') === '1',
        'read_only' => ($_POST['read_only'] ?? '0') === '1',
        'guest_ok' => ($_POST['guest_ok'] ?? '0') === '1',
        'valid_users' => trim((string)($_POST['valid_users'] ?? '')),
        'write_users' => trim((string)($_POST['write_users'] ?? '')),
    ];
    $result = host_agent_smb_share_save($payload);
    ssh_manager_audit('host_smb_share_save', ['name' => $payload['name'], 'path' => $payload['path'], 'ok' => (bool)($result['ok'] ?? false)]);
    share_service_audit('smb_share_save', ['service' => 'smb', 'name' => $payload['name'], 'path' => $payload['path'], 'ok' => (bool)($result['ok'] ?? false)]);
    if (!empty($result['ok'])) {
        host_api_share_history_capture('smb', 'save_share', ['name' => $payload['name'], 'path' => $payload['path']]);
    }
    host_api_send($result);
}

if ($action === 'smb_share_delete') {
    host_api_require_any_permission(['ssh.manage', 'ssh.service.manage']);
    csrf_check();
    $name = trim((string)($_POST['name'] ?? ''));
    $result = host_agent_smb_share_delete($name);
    ssh_manager_audit('host_smb_share_delete', ['name' => $name, 'ok' => (bool)($result['ok'] ?? false)]);
    share_service_audit('smb_share_delete', ['service' => 'smb', 'name' => $name, 'ok' => (bool)($result['ok'] ?? false)]);
    if (!empty($result['ok'])) {
        host_api_share_history_capture('smb', 'delete_share', ['name' => $name]);
    }
    host_api_send($result);
}

if ($action === 'smb_install') {
    host_api_require_any_permission(['ssh.manage', 'ssh.service.manage']);
    csrf_check();
    $result = host_agent_smb_install();
    ssh_manager_audit('host_smb_install', ['ok' => (bool)($result['ok'] ?? false)]);
    share_service_audit('smb_install', ['service' => 'smb', 'ok' => (bool)($result['ok'] ?? false)]);
    if (!empty($result['ok'])) {
        host_api_share_history_capture('smb', 'install');
    }
    host_api_send($result);
}

if ($action === 'smb_action') {
    host_api_require_any_permission(['ssh.manage', 'ssh.service.manage']);
    csrf_check();
    $serviceAction = trim((string)($_POST['service_action'] ?? ''));
    $result = host_agent_smb_action($serviceAction);
    ssh_manager_audit('host_smb_action', ['service_action' => $serviceAction, 'ok' => (bool)($result['ok'] ?? false)]);
    share_service_audit('smb_action', ['service' => 'smb', 'service_action' => $serviceAction, 'ok' => (bool)($result['ok'] ?? false)]);
    if (!empty($result['ok'])) {
        host_api_share_history_capture('smb', 'service_action', ['service_action' => $serviceAction]);
    }
    host_api_send($result);
}

if ($action === 'ftp_status') {
    auth_require_permission('ssh.view');
    host_api_send(host_agent_ftp_status());
}

if ($action === 'ftp_settings_save') {
    host_api_require_any_permission(['ssh.manage', 'ssh.service.manage']);
    csrf_check();
    $payload = [
        'listen_port' => trim((string)($_POST['listen_port'] ?? '21')),
        'anonymous_enable' => ($_POST['anonymous_enable'] ?? '0') === '1',
        'local_enable' => ($_POST['local_enable'] ?? '1') === '1',
        'write_enable' => ($_POST['write_enable'] ?? '1') === '1',
        'chroot_local_user' => ($_POST['chroot_local_user'] ?? '1') === '1',
        'local_root' => trim((string)($_POST['local_root'] ?? '')),
        'pasv_enable' => ($_POST['pasv_enable'] ?? '1') === '1',
        'pasv_min_port' => trim((string)($_POST['pasv_min_port'] ?? '40000')),
        'pasv_max_port' => trim((string)($_POST['pasv_max_port'] ?? '40100')),
        'allowed_users' => trim((string)($_POST['allowed_users'] ?? '')),
    ];
    $result = host_agent_ftp_settings_save($payload);
    ssh_manager_audit('host_ftp_settings_save', ['listen_port' => $payload['listen_port'], 'ok' => (bool)($result['ok'] ?? false)]);
    share_service_audit('ftp_settings_save', ['service' => 'ftp', 'listen_port' => $payload['listen_port'], 'ok' => (bool)($result['ok'] ?? false)]);
    if (!empty($result['ok'])) {
        host_api_share_history_capture('ftp', 'save_settings', ['listen_port' => $payload['listen_port']]);
    }
    host_api_send($result);
}

if ($action === 'ftp_install') {
    host_api_require_any_permission(['ssh.manage', 'ssh.service.manage']);
    csrf_check();
    $result = host_agent_ftp_install();
    ssh_manager_audit('host_ftp_install', ['ok' => (bool)($result['ok'] ?? false)]);
    share_service_audit('ftp_install', ['service' => 'ftp', 'ok' => (bool)($result['ok'] ?? false)]);
    if (!empty($result['ok'])) {
        host_api_share_history_capture('ftp', 'install');
    }
    host_api_send($result);
}

if ($action === 'ftp_action') {
    host_api_require_any_permission(['ssh.manage', 'ssh.service.manage']);
    csrf_check();
    $serviceAction = trim((string)($_POST['service_action'] ?? ''));
    $result = host_agent_ftp_action($serviceAction);
    ssh_manager_audit('host_ftp_action', ['service_action' => $serviceAction, 'ok' => (bool)($result['ok'] ?? false)]);
    share_service_audit('ftp_action', ['service' => 'ftp', 'service_action' => $serviceAction, 'ok' => (bool)($result['ok'] ?? false)]);
    if (!empty($result['ok'])) {
        host_api_share_history_capture('ftp', 'service_action', ['service_action' => $serviceAction]);
    }
    host_api_send($result);
}

if ($action === 'smb_uninstall') {
    host_api_require_any_permission(['ssh.manage', 'ssh.service.manage']);
    csrf_check();
    $result = host_agent_smb_uninstall();
    ssh_manager_audit('host_smb_uninstall', ['ok' => (bool)($result['ok'] ?? false)]);
    share_service_audit('smb_uninstall', ['service' => 'smb', 'ok' => (bool)($result['ok'] ?? false)]);
    if (!empty($result['ok'])) {
        host_api_share_history_capture('smb', 'uninstall');
    }
    host_api_send($result);
}

if ($action === 'ftp_uninstall') {
    host_api_require_any_permission(['ssh.manage', 'ssh.service.manage']);
    csrf_check();
    $result = host_agent_ftp_uninstall();
    ssh_manager_audit('host_ftp_uninstall', ['ok' => (bool)($result['ok'] ?? false)]);
    share_service_audit('ftp_uninstall', ['service' => 'ftp', 'ok' => (bool)($result['ok'] ?? false)]);
    if (!empty($result['ok'])) {
        host_api_share_history_capture('ftp', 'uninstall');
    }
    host_api_send($result);
}

if ($action === 'nfs_status') {
    auth_require_permission('ssh.view');
    host_api_send(host_agent_nfs_status());
}

if ($action === 'nfs_export_save') {
    host_api_require_any_permission(['ssh.manage', 'ssh.service.manage']);
    csrf_check();
    $payload = [
        'path' => trim((string)($_POST['path'] ?? '')),
        'clients' => trim((string)($_POST['clients'] ?? '')),
        'options' => trim((string)($_POST['options'] ?? '')),
        'async_mode' => ($_POST['async_mode'] ?? '0') === '1',
        'mountd_port' => trim((string)($_POST['mountd_port'] ?? '')),
        'statd_port' => trim((string)($_POST['statd_port'] ?? '')),
        'lockd_port' => trim((string)($_POST['lockd_port'] ?? '')),
    ];
    $result = host_agent_nfs_export_save($payload);
    ssh_manager_audit('host_nfs_export_save', ['path' => $payload['path'], 'clients' => $payload['clients'], 'ok' => (bool)($result['ok'] ?? false)]);
    share_service_audit('nfs_export_save', ['service' => 'nfs', 'path' => $payload['path'], 'clients' => $payload['clients'], 'ok' => (bool)($result['ok'] ?? false)]);
    if (!empty($result['ok'])) {
        host_api_share_history_capture('nfs', 'save_export', ['path' => $payload['path'], 'clients' => $payload['clients']]);
    }
    host_api_send($result);
}

if ($action === 'nfs_export_delete') {
    host_api_require_any_permission(['ssh.manage', 'ssh.service.manage']);
    csrf_check();
    $pathValue = trim((string)($_POST['path'] ?? ''));
    $result = host_agent_nfs_export_delete($pathValue);
    ssh_manager_audit('host_nfs_export_delete', ['path' => $pathValue, 'ok' => (bool)($result['ok'] ?? false)]);
    share_service_audit('nfs_export_delete', ['service' => 'nfs', 'path' => $pathValue, 'ok' => (bool)($result['ok'] ?? false)]);
    if (!empty($result['ok'])) {
        host_api_share_history_capture('nfs', 'delete_export', ['path' => $pathValue]);
    }
    host_api_send($result);
}

if ($action === 'nfs_install') {
    host_api_require_any_permission(['ssh.manage', 'ssh.service.manage']);
    csrf_check();
    $result = host_agent_nfs_install();
    ssh_manager_audit('host_nfs_install', ['ok' => (bool)($result['ok'] ?? false)]);
    share_service_audit('nfs_install', ['service' => 'nfs', 'ok' => (bool)($result['ok'] ?? false)]);
    if (!empty($result['ok'])) {
        host_api_share_history_capture('nfs', 'install');
    }
    host_api_send($result);
}

if ($action === 'nfs_uninstall') {
    host_api_require_any_permission(['ssh.manage', 'ssh.service.manage']);
    csrf_check();
    $result = host_agent_nfs_uninstall();
    ssh_manager_audit('host_nfs_uninstall', ['ok' => (bool)($result['ok'] ?? false)]);
    share_service_audit('nfs_uninstall', ['service' => 'nfs', 'ok' => (bool)($result['ok'] ?? false)]);
    if (!empty($result['ok'])) {
        host_api_share_history_capture('nfs', 'uninstall');
    }
    host_api_send($result);
}

if ($action === 'nfs_action') {
    host_api_require_any_permission(['ssh.manage', 'ssh.service.manage']);
    csrf_check();
    $serviceAction = trim((string)($_POST['service_action'] ?? ''));
    $result = host_agent_nfs_action($serviceAction);
    ssh_manager_audit('host_nfs_action', ['service_action' => $serviceAction, 'ok' => (bool)($result['ok'] ?? false)]);
    share_service_audit('nfs_action', ['service' => 'nfs', 'service_action' => $serviceAction, 'ok' => (bool)($result['ok'] ?? false)]);
    if (!empty($result['ok'])) {
        host_api_share_history_capture('nfs', 'service_action', ['service_action' => $serviceAction]);
    }
    host_api_send($result);
}

if ($action === 'afp_status') {
    auth_require_permission('ssh.view');
    host_api_send(host_agent_afp_status());
}

if ($action === 'afp_share_save') {
    host_api_require_any_permission(['ssh.manage', 'ssh.service.manage']);
    csrf_check();
    $payload = [
        'name' => trim((string)($_POST['name'] ?? '')),
        'path' => trim((string)($_POST['path'] ?? '')),
        'port' => trim((string)($_POST['port'] ?? '')),
        'valid_users' => trim((string)($_POST['valid_users'] ?? '')),
        'rwlist' => trim((string)($_POST['rwlist'] ?? '')),
    ];
    $result = host_agent_afp_share_save($payload);
    ssh_manager_audit('host_afp_share_save', ['name' => $payload['name'], 'path' => $payload['path'], 'ok' => (bool)($result['ok'] ?? false)]);
    share_service_audit('afp_share_save', ['service' => 'afp', 'name' => $payload['name'], 'path' => $payload['path'], 'ok' => (bool)($result['ok'] ?? false)]);
    if (!empty($result['ok'])) {
        host_api_share_history_capture('afp', 'save_share', ['name' => $payload['name'], 'path' => $payload['path']]);
    }
    host_api_send($result);
}

if ($action === 'afp_share_delete') {
    host_api_require_any_permission(['ssh.manage', 'ssh.service.manage']);
    csrf_check();
    $name = trim((string)($_POST['name'] ?? ''));
    $result = host_agent_afp_share_delete($name);
    ssh_manager_audit('host_afp_share_delete', ['name' => $name, 'ok' => (bool)($result['ok'] ?? false)]);
    share_service_audit('afp_share_delete', ['service' => 'afp', 'name' => $name, 'ok' => (bool)($result['ok'] ?? false)]);
    if (!empty($result['ok'])) {
        host_api_share_history_capture('afp', 'delete_share', ['name' => $name]);
    }
    host_api_send($result);
}

if ($action === 'afp_install') {
    host_api_require_any_permission(['ssh.manage', 'ssh.service.manage']);
    csrf_check();
    $result = host_agent_afp_install();
    ssh_manager_audit('host_afp_install', ['ok' => (bool)($result['ok'] ?? false)]);
    share_service_audit('afp_install', ['service' => 'afp', 'ok' => (bool)($result['ok'] ?? false)]);
    if (!empty($result['ok'])) {
        host_api_share_history_capture('afp', 'install');
    }
    host_api_send($result);
}

if ($action === 'afp_uninstall') {
    host_api_require_any_permission(['ssh.manage', 'ssh.service.manage']);
    csrf_check();
    $result = host_agent_afp_uninstall();
    ssh_manager_audit('host_afp_uninstall', ['ok' => (bool)($result['ok'] ?? false)]);
    share_service_audit('afp_uninstall', ['service' => 'afp', 'ok' => (bool)($result['ok'] ?? false)]);
    if (!empty($result['ok'])) {
        host_api_share_history_capture('afp', 'uninstall');
    }
    host_api_send($result);
}

if ($action === 'afp_action') {
    host_api_require_any_permission(['ssh.manage', 'ssh.service.manage']);
    csrf_check();
    $serviceAction = trim((string)($_POST['service_action'] ?? ''));
    $result = host_agent_afp_action($serviceAction);
    ssh_manager_audit('host_afp_action', ['service_action' => $serviceAction, 'ok' => (bool)($result['ok'] ?? false)]);
    share_service_audit('afp_action', ['service' => 'afp', 'service_action' => $serviceAction, 'ok' => (bool)($result['ok'] ?? false)]);
    if (!empty($result['ok'])) {
        host_api_share_history_capture('afp', 'service_action', ['service_action' => $serviceAction]);
    }
    host_api_send($result);
}

if ($action === 'async_status') {
    auth_require_permission('ssh.view');
    host_api_send(host_agent_async_status());
}

if ($action === 'async_module_save') {
    host_api_require_any_permission(['ssh.manage', 'ssh.service.manage']);
    csrf_check();
    $payload = [
        'name' => trim((string)($_POST['name'] ?? '')),
        'path' => trim((string)($_POST['path'] ?? '')),
        'port' => trim((string)($_POST['port'] ?? '')),
        'comment' => trim((string)($_POST['comment'] ?? '')),
        'read_only' => ($_POST['read_only'] ?? '0') === '1',
        'auth_users' => trim((string)($_POST['auth_users'] ?? '')),
    ];
    $result = host_agent_async_module_save($payload);
    ssh_manager_audit('host_async_module_save', ['name' => $payload['name'], 'path' => $payload['path'], 'ok' => (bool)($result['ok'] ?? false)]);
    share_service_audit('async_module_save', ['service' => 'async', 'name' => $payload['name'], 'path' => $payload['path'], 'ok' => (bool)($result['ok'] ?? false)]);
    if (!empty($result['ok'])) {
        host_api_share_history_capture('async', 'save_module', ['name' => $payload['name'], 'path' => $payload['path']]);
    }
    host_api_send($result);
}

if ($action === 'async_module_delete') {
    host_api_require_any_permission(['ssh.manage', 'ssh.service.manage']);
    csrf_check();
    $name = trim((string)($_POST['name'] ?? ''));
    $result = host_agent_async_module_delete($name);
    ssh_manager_audit('host_async_module_delete', ['name' => $name, 'ok' => (bool)($result['ok'] ?? false)]);
    share_service_audit('async_module_delete', ['service' => 'async', 'name' => $name, 'ok' => (bool)($result['ok'] ?? false)]);
    if (!empty($result['ok'])) {
        host_api_share_history_capture('async', 'delete_module', ['name' => $name]);
    }
    host_api_send($result);
}

if ($action === 'async_install') {
    host_api_require_any_permission(['ssh.manage', 'ssh.service.manage']);
    csrf_check();
    $result = host_agent_async_install();
    ssh_manager_audit('host_async_install', ['ok' => (bool)($result['ok'] ?? false)]);
    share_service_audit('async_install', ['service' => 'async', 'ok' => (bool)($result['ok'] ?? false)]);
    if (!empty($result['ok'])) {
        host_api_share_history_capture('async', 'install');
    }
    host_api_send($result);
}

if ($action === 'async_uninstall') {
    host_api_require_any_permission(['ssh.manage', 'ssh.service.manage']);
    csrf_check();
    $result = host_agent_async_uninstall();
    ssh_manager_audit('host_async_uninstall', ['ok' => (bool)($result['ok'] ?? false)]);
    share_service_audit('async_uninstall', ['service' => 'async', 'ok' => (bool)($result['ok'] ?? false)]);
    if (!empty($result['ok'])) {
        host_api_share_history_capture('async', 'uninstall');
    }
    host_api_send($result);
}

if ($action === 'async_action') {
    host_api_require_any_permission(['ssh.manage', 'ssh.service.manage']);
    csrf_check();
    $serviceAction = trim((string)($_POST['service_action'] ?? ''));
    $result = host_agent_async_action($serviceAction);
    ssh_manager_audit('host_async_action', ['service_action' => $serviceAction, 'ok' => (bool)($result['ok'] ?? false)]);
    share_service_audit('async_action', ['service' => 'async', 'service_action' => $serviceAction, 'ok' => (bool)($result['ok'] ?? false)]);
    if (!empty($result['ok'])) {
        host_api_share_history_capture('async', 'service_action', ['service_action' => $serviceAction]);
    }
    host_api_send($result);
}

if ($action === 'share_audit_query') {
    auth_require_permission('ssh.audit');
    $query = share_service_audit_query([
        'limit' => (int)($_GET['limit'] ?? 200),
        'action' => (string)($_GET['action_name'] ?? ''),
        'service' => (string)($_GET['service_name'] ?? ''),
        'user' => (string)($_GET['user_name'] ?? ''),
        'keyword' => (string)($_GET['keyword'] ?? ''),
        'page' => (int)($_GET['page'] ?? 1),
    ]);
    host_api_send(['ok' => true, 'data' => ['ok' => true] + $query]);
}

if ($action === 'share_audit_export') {
    host_api_require_any_permission(['ssh.audit.export', 'ssh.manage']);
    $json = share_service_audit_export_json([
        'limit' => (int)($_GET['limit'] ?? 200),
        'action' => (string)($_GET['action_name'] ?? ''),
        'service' => (string)($_GET['service_name'] ?? ''),
        'user' => (string)($_GET['user_name'] ?? ''),
        'keyword' => (string)($_GET['keyword'] ?? ''),
    ]);
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="share-service-audit-export.json"');
    echo $json;
    exit;
}

if ($action === 'share_history_list') {
    auth_require_permission('ssh.audit');
    host_api_send(['ok' => true, 'items' => share_service_history_list([
        'service' => (string)($_GET['service_name'] ?? ''),
        'keyword' => (string)($_GET['keyword'] ?? ''),
        'limit' => (int)($_GET['limit'] ?? 100),
    ])]);
}

if ($action === 'share_history_restore') {
    host_api_require_any_permission(['ssh.manage', 'ssh.config.manage']);
    csrf_check();
    $id = trim((string)($_POST['history_id'] ?? ''));
    $entry = share_service_history_find($id);
    if (!$entry) {
        host_api_send(['ok' => false, 'msg' => '历史快照不存在']);
    }
    $service = trim((string)($entry['service'] ?? ''));
    $snapshot = is_array($entry['snapshot'] ?? null) ? $entry['snapshot'] : [];
    $result = host_agent_share_snapshot_restore($service, (array)($snapshot['files'] ?? []));
    share_service_audit('history_restore', ['service' => $service, 'history_id' => $id, 'ok' => (bool)($result['ok'] ?? false)]);
    if (!empty($result['ok'])) {
        host_api_share_history_capture($service, 'restore', ['history_id' => $id]);
    }
    host_api_send($result);
}

if ($action === 'audit_query') {
    auth_require_permission('ssh.audit');
    $query = ssh_manager_audit_query([
        'limit' => (int)($_GET['limit'] ?? 200),
        'action' => (string)($_GET['action_name'] ?? ''),
        'host_id' => (string)($_GET['host_id'] ?? ''),
        'user' => (string)($_GET['user_name'] ?? ''),
        'keyword' => (string)($_GET['keyword'] ?? ''),
        'page' => (int)($_GET['page'] ?? 1),
    ]);
    host_api_send(['ok' => true, 'data' => ['ok' => true] + $query]);
}

if ($action === 'audit_export') {
    host_api_require_any_permission(['ssh.audit.export', 'ssh.manage']);
    $json = ssh_manager_audit_export_json([
        'limit' => (int)($_GET['limit'] ?? 200),
        'action' => (string)($_GET['action_name'] ?? ''),
        'host_id' => (string)($_GET['host_id'] ?? ''),
        'keyword' => (string)($_GET['keyword'] ?? ''),
    ]);
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="ssh-audit-export.json"');
    echo $json;
    exit;
}

if ($action === 'terminal_open') {
    auth_require_permission('ssh.terminal');
    csrf_check();
    $hostId = trim((string)($_POST['host_id'] ?? 'local'));
    $target = host_api_target_payload($hostId);
    if (!$target) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'msg' => '目标主机不存在'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $persist = ($_POST['persist'] ?? '1') === '1';
    $idleMinutes = max(5, min(10080, (int)($_POST['idle_minutes'] ?? 120)));
    $result = host_agent_terminal_open($target, $persist, $idleMinutes);
    ssh_manager_audit('terminal_open', ['host_id' => $hostId, 'persist' => $persist, 'idle_minutes' => $idleMinutes, 'ok' => (bool)($result['ok'] ?? false)]);
    host_api_send($result);
}

if ($action === 'terminal_list') {
    auth_require_permission('ssh.terminal');
    $result = host_agent_terminal_list();
    host_api_send($result);
}

if ($action === 'terminal_read') {
    auth_require_permission('ssh.terminal');
    $result = host_agent_terminal_read(trim((string)($_GET['id'] ?? '')));
    host_api_send($result);
}

if ($action === 'terminal_write') {
    auth_require_permission('ssh.terminal');
    csrf_check();
    $result = host_agent_terminal_write(trim((string)($_POST['id'] ?? '')), (string)($_POST['data'] ?? ''));
    host_api_send($result);
}

if ($action === 'terminal_close') {
    auth_require_permission('ssh.terminal');
    csrf_check();
    $result = host_agent_terminal_close(trim((string)($_POST['id'] ?? '')));
    host_api_send($result);
}

http_response_code(404);
echo json_encode(['ok' => false, 'msg' => '未知 action'], JSON_UNESCAPED_UNICODE);
