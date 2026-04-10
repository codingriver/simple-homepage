<?php
/**
 * AJAX：获取计划任务状态
 * GET ?ids=id1,id2
 * 返回 JSON {server_time:string,tasks:{id:{...}}}
 */
require_once dirname(__DIR__) . '/shared/functions.php';
$user = auth_get_current_user();
if (!$user || ($user['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'forbidden']);
    exit;
}
require_once dirname(__DIR__) . '/shared/cron_lib.php';

$rawIds = trim((string)($_GET['ids'] ?? ''));
$ids = [];
if ($rawIds !== '') {
    $ids = preg_split('/\s*,\s*/', $rawIds) ?: [];
}

header('Content-Type: application/json; charset=UTF-8');
echo json_encode(scheduled_tasks_status_payload($ids), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
