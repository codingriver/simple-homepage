<?php
declare(strict_types=1);

require_once __DIR__ . '/shared/functions.php';
require_once __DIR__ . '/shared/file_manager_lib.php';
require_once __DIR__ . '/shared/host_agent_lib.php';
require_once __DIR__ . '/shared/webdav_lib.php';

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'XMLHttpRequest') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'msg' => '需要 AJAX 请求'], JSON_UNESCAPED_UNICODE);
    exit;
}

$user = auth_get_current_user();
if (!$user || !auth_user_has_permission('ssh.files', $user)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'msg' => '无权限'], JSON_UNESCAPED_UNICODE);
    exit;
}

$action = trim((string)($_REQUEST['action'] ?? ''));

function file_api_send(array $result): void {
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

function file_api_require_write(): void {
    global $user;
    if (auth_user_has_permission('ssh.manage', $user) || auth_user_has_permission('ssh.files.write', $user)) {
        return;
    }
    auth_require_permission('ssh.files.write');
}

function file_api_target_or_404(string $hostId): array {
    $target = file_manager_target_payload($hostId);
    if ($target) {
        return $target;
    }
    http_response_code(404);
    echo json_encode(['ok' => false, 'msg' => '目标主机不存在'], JSON_UNESCAPED_UNICODE);
    exit;
}

function file_api_resolve_local_webdav_root(string $path): string {
    $normalized = trim($path);
    if ($normalized === '') {
        $normalized = '/';
    }
    if ($normalized[0] !== '/') {
        $normalized = '/' . $normalized;
    }
    if (host_agent_install_mode() === 'simulate') {
        return '/var/www/nav/data/host-agent-sim-root' . $normalized;
    }
    return $normalized;
}

if ($action === 'list') {
    $hostId = trim((string)($_GET['host_id'] ?? 'local')) ?: 'local';
    $path = trim((string)($_GET['path'] ?? '/')) ?: '/';
    $result = host_agent_fs_list(file_api_target_or_404($hostId), $path);
    file_api_send($result);
}

if ($action === 'read') {
    $hostId = trim((string)($_POST['host_id'] ?? 'local')) ?: 'local';
    $path = trim((string)($_POST['path'] ?? '/')) ?: '/';
    file_api_send(host_agent_fs_read(file_api_target_or_404($hostId), $path));
}

if ($action === 'write') {
    file_api_require_write();
    csrf_check();
    $hostId = trim((string)($_POST['host_id'] ?? 'local')) ?: 'local';
    $path = trim((string)($_POST['path'] ?? '/')) ?: '/';
    $content = (string)($_POST['content'] ?? '');
    $contentBase64 = array_key_exists('content_base64', $_POST) ? (string)$_POST['content_base64'] : null;
    $result = host_agent_fs_write(file_api_target_or_404($hostId), $path, $content, $contentBase64);
    ssh_manager_audit('fs_write', ['host_id' => $hostId, 'path' => $path, 'ok' => (bool)($result['ok'] ?? false)]);
    file_api_send($result);
}

if ($action === 'mkdir') {
    file_api_require_write();
    csrf_check();
    $hostId = trim((string)($_POST['host_id'] ?? 'local')) ?: 'local';
    $path = trim((string)($_POST['path'] ?? '/')) ?: '/';
    $result = host_agent_fs_mkdir(file_api_target_or_404($hostId), $path);
    ssh_manager_audit('fs_mkdir', ['host_id' => $hostId, 'path' => $path, 'ok' => (bool)($result['ok'] ?? false)]);
    file_api_send($result);
}

if ($action === 'rename') {
    file_api_require_write();
    csrf_check();
    $hostId = trim((string)($_POST['host_id'] ?? 'local')) ?: 'local';
    $sourcePath = trim((string)($_POST['source_path'] ?? ''));
    $targetPath = trim((string)($_POST['target_path'] ?? ''));
    $result = host_agent_fs_rename(file_api_target_or_404($hostId), $sourcePath, $targetPath);
    ssh_manager_audit('fs_rename', ['host_id' => $hostId, 'source_path' => $sourcePath, 'target_path' => $targetPath, 'ok' => (bool)($result['ok'] ?? false)]);
    file_api_send($result);
}

if ($action === 'copy') {
    file_api_require_write();
    csrf_check();
    $hostId = trim((string)($_POST['host_id'] ?? 'local')) ?: 'local';
    $sourcePath = trim((string)($_POST['source_path'] ?? ''));
    $targetPath = trim((string)($_POST['target_path'] ?? ''));
    $result = host_agent_fs_copy(file_api_target_or_404($hostId), $sourcePath, $targetPath);
    ssh_manager_audit('fs_copy', ['host_id' => $hostId, 'source_path' => $sourcePath, 'target_path' => $targetPath, 'ok' => (bool)($result['ok'] ?? false)]);
    file_api_send($result);
}

if ($action === 'move') {
    file_api_require_write();
    csrf_check();
    $hostId = trim((string)($_POST['host_id'] ?? 'local')) ?: 'local';
    $sourcePath = trim((string)($_POST['source_path'] ?? ''));
    $targetPath = trim((string)($_POST['target_path'] ?? ''));
    $result = host_agent_fs_move(file_api_target_or_404($hostId), $sourcePath, $targetPath);
    ssh_manager_audit('fs_move', ['host_id' => $hostId, 'source_path' => $sourcePath, 'target_path' => $targetPath, 'ok' => (bool)($result['ok'] ?? false)]);
    file_api_send($result);
}

if ($action === 'delete') {
    file_api_require_write();
    csrf_check();
    $hostId = trim((string)($_POST['host_id'] ?? 'local')) ?: 'local';
    $path = trim((string)($_POST['path'] ?? '/')) ?: '/';
    $result = host_agent_fs_delete(file_api_target_or_404($hostId), $path);
    ssh_manager_audit('fs_delete', ['host_id' => $hostId, 'path' => $path, 'ok' => (bool)($result['ok'] ?? false)]);
    file_api_send($result);
}

if ($action === 'stat') {
    $hostId = trim((string)($_GET['host_id'] ?? 'local')) ?: 'local';
    $path = trim((string)($_GET['path'] ?? '/')) ?: '/';
    file_api_send(host_agent_fs_stat(file_api_target_or_404($hostId), $path));
}

if ($action === 'search') {
    $hostId = trim((string)($_REQUEST['host_id'] ?? 'local')) ?: 'local';
    $path = trim((string)($_REQUEST['path'] ?? '/')) ?: '/';
    $keyword = trim((string)($_REQUEST['keyword'] ?? ''));
    $limit = max(1, min(1000, (int)($_REQUEST['limit'] ?? 200)));
    file_api_send(host_agent_fs_search(file_api_target_or_404($hostId), $path, $keyword, $limit));
}

if ($action === 'chmod') {
    file_api_require_write();
    csrf_check();
    $hostId = trim((string)($_POST['host_id'] ?? 'local')) ?: 'local';
    $path = trim((string)($_POST['path'] ?? '/')) ?: '/';
    $mode = trim((string)($_POST['mode'] ?? ''));
    $result = host_agent_fs_chmod(file_api_target_or_404($hostId), $path, $mode);
    ssh_manager_audit('fs_chmod', ['host_id' => $hostId, 'path' => $path, 'mode' => $mode, 'ok' => (bool)($result['ok'] ?? false)]);
    file_api_send($result);
}

if ($action === 'chown') {
    file_api_require_write();
    csrf_check();
    $hostId = trim((string)($_POST['host_id'] ?? 'local')) ?: 'local';
    $path = trim((string)($_POST['path'] ?? '/')) ?: '/';
    $owner = trim((string)($_POST['owner'] ?? ''));
    $result = host_agent_fs_chown(file_api_target_or_404($hostId), $path, $owner);
    ssh_manager_audit('fs_chown', ['host_id' => $hostId, 'path' => $path, 'owner' => $owner, 'ok' => (bool)($result['ok'] ?? false)]);
    file_api_send($result);
}

if ($action === 'chgrp') {
    file_api_require_write();
    csrf_check();
    $hostId = trim((string)($_POST['host_id'] ?? 'local')) ?: 'local';
    $path = trim((string)($_POST['path'] ?? '/')) ?: '/';
    $group = trim((string)($_POST['group'] ?? ''));
    $result = host_agent_fs_chgrp(file_api_target_or_404($hostId), $path, $group);
    ssh_manager_audit('fs_chgrp', ['host_id' => $hostId, 'path' => $path, 'group' => $group, 'ok' => (bool)($result['ok'] ?? false)]);
    file_api_send($result);
}

if ($action === 'archive') {
    file_api_require_write();
    csrf_check();
    $hostId = trim((string)($_POST['host_id'] ?? 'local')) ?: 'local';
    $path = trim((string)($_POST['path'] ?? '/')) ?: '/';
    $archivePath = trim((string)($_POST['archive_path'] ?? ($path . '.tar.gz')));
    // 默认走异步任务，避免大文件压缩阻塞
    $result = host_agent_fs_archive_async(file_api_target_or_404($hostId), $path, $archivePath);
    ssh_manager_audit('fs_archive', ['host_id' => $hostId, 'path' => $path, 'archive_path' => $archivePath, 'ok' => (bool)($result['ok'] ?? false), 'async' => true]);
    file_api_send($result);
}

if ($action === 'extract') {
    file_api_require_write();
    csrf_check();
    $hostId = trim((string)($_POST['host_id'] ?? 'local')) ?: 'local';
    $path = trim((string)($_POST['path'] ?? '/')) ?: '/';
    $destination = trim((string)($_POST['destination'] ?? dirname($path))) ?: dirname($path);
    // 默认走异步任务，避免大文件解压阻塞
    $result = host_agent_fs_extract_async(file_api_target_or_404($hostId), $path, $destination);
    ssh_manager_audit('fs_extract', ['host_id' => $hostId, 'path' => $path, 'destination' => $destination, 'ok' => (bool)($result['ok'] ?? false), 'async' => true]);
    file_api_send($result);
}

if ($action === 'favorites_list') {
    file_api_send(['ok' => true, 'data' => ['ok' => true, 'items' => file_manager_favorites_list((string)($user['username'] ?? ''))]]);
}

if ($action === 'favorites_save') {
    file_api_require_write();
    csrf_check();
    file_api_send(file_manager_save_favorite((string)($user['username'] ?? ''), [
        'host_id' => (string)($_POST['host_id'] ?? 'local'),
        'path' => (string)($_POST['path'] ?? ''),
        'name' => (string)($_POST['name'] ?? ''),
    ]));
}

if ($action === 'favorites_delete') {
    file_api_require_write();
    csrf_check();
    $id = trim((string)($_POST['id'] ?? ''));
    $ok = file_manager_delete_favorite((string)($user['username'] ?? ''), $id);
    file_api_send(['ok' => $ok, 'msg' => $ok ? '收藏目录已删除' : '收藏目录不存在']);
}

if ($action === 'recent_list') {
    file_api_send(['ok' => true, 'data' => ['ok' => true, 'items' => file_manager_recent_list((string)($user['username'] ?? ''))]]);
}

if ($action === 'recent_touch') {
    csrf_check();
    file_manager_touch_recent((string)($user['username'] ?? ''), trim((string)($_POST['host_id'] ?? 'local')) ?: 'local', trim((string)($_POST['path'] ?? '/')) ?: '/');
    file_api_send(['ok' => true, 'msg' => '最近访问已记录']);
}

if ($action === 'webdav_share_create') {
    $currentUser = auth_get_current_user();
    if (!$currentUser || ($currentUser['role'] ?? '') !== 'admin') {
        http_response_code(403);
        echo json_encode(['ok' => false, 'msg' => '仅管理员可创建 WebDAV 共享'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    csrf_check();
    $hostId = trim((string)($_POST['host_id'] ?? 'local')) ?: 'local';
    if ($hostId !== 'local') {
        file_api_send(['ok' => false, 'msg' => '当前仅支持为本机目录创建 WebDAV 共享']);
    }
    $path = trim((string)($_POST['path'] ?? '/')) ?: '/';
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $readonly = ($_POST['readonly'] ?? '0') === '1';
    $result = webdav_account_upsert([
        'username' => $username,
        'password' => $password,
        'root' => file_api_resolve_local_webdav_root($path),
        'readonly' => $readonly,
        'enabled' => true,
        'notes' => 'created from file manager',
    ], null);
    file_api_send($result);
}

if ($action === 'webdav_shares_for_path') {
    $hostId = trim((string)($_GET['host_id'] ?? 'local')) ?: 'local';
    if ($hostId !== 'local') {
        file_api_send(['ok' => true, 'data' => ['ok' => true, 'items' => []]]);
    }
    $path = trim((string)($_GET['path'] ?? '/')) ?: '/';
    $accounts = array_map(static function(array $account): array {
        return [
            'id' => (string)($account['id'] ?? ''),
            'username' => (string)($account['username'] ?? ''),
            'readonly' => !empty($account['readonly']),
            'enabled' => !empty($account['enabled']),
            'root' => webdav_display_local_root((string)($account['root'] ?? '/')),
            'relation' => (string)($account['relation'] ?? ''),
            'notes' => (string)($account['notes'] ?? ''),
        ];
    }, webdav_accounts_for_local_path($path));
    file_api_send(['ok' => true, 'data' => ['ok' => true, 'items' => $accounts]]);
}

if ($action === 'audit_query') {
    auth_require_permission('ssh.audit');
    $prefix = trim((string)($_GET['prefix'] ?? 'fs_'));
    $query = ssh_manager_audit_query([
        'limit' => (int)($_GET['limit'] ?? 200),
        'host_id' => (string)($_GET['host_id'] ?? ''),
        'keyword' => (string)($_GET['keyword'] ?? ''),
        'page' => (int)($_GET['page'] ?? 1),
    ]);
    $logs = array_values(array_filter((array)($query['items'] ?? []), static function(array $log) use ($prefix): bool {
        return $prefix === '' || str_starts_with((string)($log['action'] ?? ''), $prefix);
    }));
    file_api_send(['ok' => true, 'data' => ['ok' => true, 'logs' => $logs, 'total' => (int)($query['total'] ?? count($logs)), 'page' => (int)($query['page'] ?? 1)]]);
}

if ($action === 'audit_export') {
    auth_require_permission('ssh.audit');
    $prefix = trim((string)($_GET['prefix'] ?? 'fs_'));
    $query = ssh_manager_audit_query([
        'limit' => (int)($_GET['limit'] ?? 500),
        'host_id' => (string)($_GET['host_id'] ?? ''),
        'keyword' => (string)($_GET['keyword'] ?? ''),
    ]);
    $logs = array_values(array_filter((array)($query['items'] ?? []), static function(array $log) use ($prefix): bool {
        return $prefix === '' || str_starts_with((string)($log['action'] ?? ''), $prefix);
    }));
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="file-audit-export.json"');
    echo json_encode($logs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

http_response_code(404);
echo json_encode(['ok' => false, 'msg' => '未知 action'], JSON_UNESCAPED_UNICODE);
