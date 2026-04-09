<?php
declare(strict_types=1);

require_once __DIR__ . '/shared/functions.php';
require_once __DIR__ . '/shared/ddns_lib.php';

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

if (session_status() === PHP_SESSION_NONE) session_start();
$ddns_ajax_csrf_token = (string)($_SESSION['csrf_token'] ?? '');
session_write_close();

function ddns_ajax_response(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function ddns_ajax_require_csrf(array $input): void {
    global $ddns_ajax_csrf_token;

    $token = (string)($input['_csrf'] ?? '');
    if ($token === '' || $ddns_ajax_csrf_token === '' || !hash_equals($ddns_ajax_csrf_token, $token)) {
        ddns_ajax_response(['ok' => false, 'msg' => 'CSRF验证失败，请刷新重试'], 403);
    }
}

if ($action === 'list') {
    $rows = array_map('ddns_task_row', ddns_load_tasks()['tasks'] ?? []);
    ddns_ajax_response(['ok' => true, 'data' => ['rows' => $rows]]);
}

if ($action === 'get') {
    $id = trim((string)($input['id'] ?? ''));
    $task = ddns_find_task(ddns_load_tasks(), $id);
    if (!$task) {
        ddns_ajax_response(['ok' => false, 'msg' => '任务不存在'], 404);
    }
    ddns_ajax_response(['ok' => true, 'data' => ['task' => $task]]);
}

if (in_array($action, ['save', 'delete', 'toggle', 'run', 'test_source', 'log_clear'], true)) {
    ddns_ajax_require_csrf($input);
}

if ($action === 'save') {
    $task = is_array($input['task'] ?? null) ? $input['task'] : [];
    $id = trim((string)($input['id'] ?? ''));
    $result = ddns_upsert_task($task, $id !== '' ? $id : null);
    if (!$result['ok']) {
        ddns_ajax_response(['ok' => false, 'msg' => $result['msg'] ?? '保存失败'], 400);
    }
    ddns_ajax_response([
        'ok' => true,
        'msg' => '任务已保存',
        'data' => [
            'task' => $result['task'],
            'row' => ddns_task_row($result['task']),
        ],
    ]);
}

if ($action === 'delete') {
    $id = trim((string)($input['id'] ?? ''));
    if ($id === '' || !ddns_delete_task($id)) {
        ddns_ajax_response(['ok' => false, 'msg' => '任务不存在'], 404);
    }
    ddns_ajax_response(['ok' => true, 'msg' => '任务已删除']);
}

if ($action === 'toggle') {
    $id = trim((string)($input['id'] ?? ''));
    $enabled = ddns_toggle_task($id);
    if ($enabled === null) {
        ddns_ajax_response(['ok' => false, 'msg' => '任务不存在'], 404);
    }
    $task = ddns_find_task(ddns_load_tasks(), $id);
    ddns_ajax_response([
        'ok' => true,
        'msg' => $enabled ? '已启用' : '已禁用',
        'data' => [
            'enabled' => $enabled,
            'row' => $task ? ddns_task_row($task) : null,
        ],
    ]);
}

if ($action === 'test_source') {
    @set_time_limit(0);
    $task = is_array($input['task'] ?? null) ? $input['task'] : [];
    $resolved = ddns_resolve_source(ddns_normalize_task($task));
    if (!$resolved['ok']) {
        ddns_ajax_response(['ok' => false, 'msg' => $resolved['msg'] ?? '测试失败'], 400);
    }
    ddns_ajax_response(['ok' => true, 'data' => $resolved]);
}

if ($action === 'run') {
    @set_time_limit(0);
    $id = trim((string)($input['id'] ?? ''));
    $result = ddns_run_task_by_id($id);
    $task = ddns_find_task(ddns_load_tasks(), $id);
    ddns_ajax_response([
        'ok' => $result['ok'],
        'msg' => $result['msg'] ?? ($result['ok'] ? '执行完成' : '执行失败'),
        'data' => [
            'result' => $result,
            'row' => $task ? ddns_task_row($task) : null,
        ],
    ], $result['ok'] ? 200 : 400);
}

if ($action === 'log') {
    $id = trim((string)($input['id'] ?? ''));
    $page = max(1, (int)($input['page'] ?? 1));
    if ($id === '') {
        ddns_ajax_response(['ok' => false, 'msg' => '缺少任务 ID'], 400);
    }
    $task = ddns_find_task(ddns_load_tasks(), $id);
    if (!$task) {
        ddns_ajax_response(['ok' => false, 'msg' => '任务不存在'], 404);
    }
    ddns_ajax_response(['ok' => true, 'data' => ddns_task_log_page($id, $page)]);
}

if ($action === 'log_clear') {
    $id = trim((string)($input['id'] ?? ''));
    if ($id === '') {
        ddns_ajax_response(['ok' => false, 'msg' => '缺少任务 ID'], 400);
    }
    $task = ddns_find_task(ddns_load_tasks(), $id);
    if (!$task) {
        ddns_ajax_response(['ok' => false, 'msg' => '任务不存在'], 404);
    }
    ddns_task_log_clear($id);
    ddns_ajax_response(['ok' => true, 'msg' => '日志已清空']);
}

http_response_code(404);
echo json_encode(['ok' => false, 'msg' => '未知 action'], JSON_UNESCAPED_UNICODE);
