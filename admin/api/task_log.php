<?php
/**
 * AJAX：获取任务日志分页
 * GET ?id=<task_id>&page=<n>
 * 返回 JSON {lines:[...], total:N, page:N, pages:N}
 */
require_once dirname(__DIR__) . '/shared/functions.php';
$user = auth_get_current_user();
if (!$user || ($user['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'forbidden']);
    exit;
}
require_once dirname(__DIR__) . '/shared/cron_lib.php';

$id   = trim((string)($_GET['id']   ?? ''));
$page = max(1, (int)($_GET['page']  ?? 1));

if ($id === '') {
    http_response_code(400);
    echo json_encode(['error' => 'missing id']);
    exit;
}

header('Content-Type: application/json; charset=UTF-8');
echo json_encode(task_log_page($id, $page), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
