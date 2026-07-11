<?php
/**
 * 系统设置页惰性数据（避免首屏 exec / 大 JSON）
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

$action = $_GET['action'] ?? '';

if ($action === 'nginx_sudo') {
    $capability = nginx_test_capability();
    echo json_encode([
        'ok' => true,
        'reload_ok' => false,
        'sudo_ok' => false,
        'method' => $capability['method'],
        'message' => '后台不再支持 Nginx Reload。' . $capability['msg'],
        'nginx_bin' => $capability['nginx_bin'],
        'sudo_hint' => '',
        'test_output' => $capability['test_output'],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(404);
echo json_encode(['ok' => false, 'msg' => '未知 action'], JSON_UNESCAPED_UNICODE);
