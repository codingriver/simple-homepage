<?php
/**
 * 登录日志 JSON（仅 AJAX，惰性加载）
 */
require_once __DIR__ . '/shared/functions.php';

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

$data = auth_read_log(AUTH_LOG_MAX_LINES, 0);
echo json_encode([
    'ok'    => true,
    'total' => $data['total'],
    'rows'  => $data['rows'],
    'max'   => AUTH_LOG_MAX_LINES,
], JSON_UNESCAPED_UNICODE);
