<?php
declare(strict_types=1);

require_once __DIR__ . '/shared/functions.php';
require_once __DIR__ . '/shared/runtime_env_lib.php';

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'XMLHttpRequest') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'msg' => '需要 AJAX 请求'], JSON_UNESCAPED_UNICODE);
    exit;
}

$user = auth_get_current_user();
if (!$user || ($user['role'] ?? '') !== 'admin') {
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
$runtime_env_csrf = (string)($_SESSION['csrf_token'] ?? '');
session_write_close();

function runtime_env_ajax_response(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function runtime_env_ajax_require_csrf(array $input): void {
    global $runtime_env_csrf;
    $token = (string)($input['_csrf'] ?? '');
    if ($token === '' || $runtime_env_csrf === '' || !hash_equals($runtime_env_csrf, $token)) {
        runtime_env_ajax_response(['ok' => false, 'msg' => 'CSRF验证失败，请刷新重试'], 403);
    }
}

if ($action === 'detect') {
    runtime_env_ajax_response(['ok' => true, 'data' => runtime_env_detect_node()]);
}

if ($action === 'versions') {
    $result = runtime_env_fetch_node_versions();
    runtime_env_ajax_response($result, $result['ok'] ? 200 : 400);
}

if ($action === 'tail_log') {
    runtime_env_ajax_response(['ok' => true, 'data' => ['log' => runtime_env_tail_log()]]);
}

if ($action === 'job_status') {
    $jobId = (string)($input['job_id'] ?? '');
    $job = runtime_env_job_public_payload($jobId);
    if ($job === null) {
        runtime_env_ajax_response(['ok' => false, 'msg' => '安装任务不存在'], 404);
    }
    runtime_env_ajax_response(['ok' => true, 'data' => ['job' => $job]]);
}

if (in_array($action, ['save_config', 'install_apk', 'install_version', 'switch_version', 'uninstall_version', 'test'], true)) {
    runtime_env_ajax_require_csrf($input);
}

if ($action === 'save_config') {
    $result = runtime_env_save_node_config(is_array($input['config'] ?? null) ? $input['config'] : $input);
    runtime_env_ajax_response($result, $result['ok'] ? 200 : 400);
}

if ($action === 'install_apk') {
    $result = runtime_env_start_install_job('apk');
    runtime_env_ajax_response($result, $result['ok'] ? 200 : 400);
}

if ($action === 'install_version') {
    $result = runtime_env_start_install_job('version', [(string)($input['version'] ?? '')]);
    runtime_env_ajax_response($result, $result['ok'] ? 200 : 400);
}

if ($action === 'switch_version') {
    $result = runtime_env_set_node_current((string)($input['version'] ?? ''));
    runtime_env_ajax_response($result, $result['ok'] ? 200 : 400);
}

if ($action === 'uninstall_version') {
    $result = runtime_env_uninstall_node_version((string)($input['version'] ?? ''));
    runtime_env_ajax_response($result, $result['ok'] ? 200 : 400);
}

if ($action === 'test') {
    $result = runtime_env_test_node();
    runtime_env_ajax_response($result, $result['ok'] ? 200 : 400);
}

runtime_env_ajax_response(['ok' => false, 'msg' => '未知 action'], 404);
