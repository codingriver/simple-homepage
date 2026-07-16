<?php
declare(strict_types=1);

require_once __DIR__ . '/shared/functions.php';
require_once __DIR__ . '/shared/backup_webdav_lib.php';

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'XMLHttpRequest') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'msg' => '需要 AJAX 请求'], JSON_UNESCAPED_UNICODE);
    exit;
}

$user = auth_get_current_user();
if (!$user) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'msg' => '未登录'], JSON_UNESCAPED_UNICODE);
    exit;
}
if (($user['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'msg' => '无权限'], JSON_UNESCAPED_UNICODE);
    exit;
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$input = $method === 'POST'
    ? (json_decode((string)file_get_contents('php://input'), true) ?: $_POST)
    : $_GET;
$action = trim((string)($input['action'] ?? ''));

auth_start_php_session();
$backup_webdav_csrf = (string)($_SESSION['csrf_token'] ?? '');
session_write_close();

function backup_webdav_ajax_response(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function backup_webdav_ajax_require_csrf(array $input): void {
    global $backup_webdav_csrf;
    $token = (string)($input['_csrf'] ?? '');
    if ($token === '' || $backup_webdav_csrf === '' || !hash_equals($backup_webdav_csrf, $token)) {
        backup_webdav_ajax_response(['ok' => false, 'msg' => 'CSRF验证失败，请刷新重试'], 403);
    }
}

if ($action === 'config') {
    backup_webdav_ajax_response(['ok' => true, 'data' => ['config' => backup_webdav_public_config()]]);
}

if ($action === 'remote_list') {
    $config = backup_webdav_load_config();
    if (!$config['enabled']) {
        backup_webdav_ajax_response(['ok' => false, 'msg' => '请先保存并启用 WebDAV 配置'], 400);
    }
    $result = backup_webdav_list_remote($config);
    backup_webdav_ajax_response($result, $result['ok'] ? 200 : 400);
}

if ($action === 'job_status') {
    $job = backup_webdav_job_public_payload((string)($input['job_id'] ?? ''));
    if ($job === null) {
        backup_webdav_ajax_response(['ok' => false, 'msg' => 'WebDAV 任务不存在'], 404);
    }
    backup_webdav_ajax_response(['ok' => true, 'data' => ['job' => $job]]);
}

if ($action === 'current_job') {
    backup_webdav_ajax_response(['ok' => true, 'data' => ['job' => backup_webdav_current_job()]]);
}

$writeActions = [
    'save_config',
    'test_connection',
    'create_upload',
    'upload_local',
    'download_remote',
    'restore_remote',
    'delete_remote',
];
if (in_array($action, $writeActions, true)) {
    backup_webdav_ajax_require_csrf($input);
}

if ($action === 'save_config') {
    $config = is_array($input['config'] ?? null) ? $input['config'] : $input;
    $result = backup_webdav_save_config($config);
    if ($result['ok']) {
        audit_log('backup_webdav_save_config', [
            'enabled' => (bool)($result['data']['config']['enabled'] ?? false),
            'tls_enabled' => (bool)($result['data']['config']['tls_enabled'] ?? false),
            'auth_enabled' => (bool)($result['data']['config']['auth_enabled'] ?? false),
            'ssrf_protection' => (bool)($result['data']['config']['ssrf_protection'] ?? false),
        ]);
    }
    backup_webdav_ajax_response($result, $result['ok'] ? 200 : 400);
}

$jobActionMap = [
    'test_connection' => 'test_connection',
    'create_upload' => 'create_upload',
    'upload_local' => 'upload_local',
    'download_remote' => 'download_remote',
    'restore_remote' => 'restore_remote',
    'delete_remote' => 'delete_remote',
];
if (isset($jobActionMap[$action])) {
    $config = backup_webdav_load_config();
    if (!$config['enabled'] || trim((string)$config['base_url']) === '') {
        backup_webdav_ajax_response(['ok' => false, 'msg' => '请先保存并启用 WebDAV 配置'], 400);
    }
    $params = [];
    if (in_array($action, ['upload_local', 'download_remote', 'restore_remote', 'delete_remote'], true)) {
        $params['filename'] = basename((string)($input['filename'] ?? ''));
    }
    $result = backup_webdav_start_job($jobActionMap[$action], $params, (string)($user['username'] ?? 'admin'));
    audit_log('backup_webdav_job_start', ['action' => $action, 'filename' => $params['filename'] ?? '', 'ok' => $result['ok']]);
    backup_webdav_ajax_response($result, $result['ok'] ? 200 : 400);
}

backup_webdav_ajax_response(['ok' => false, 'msg' => '未知 action'], 404);
