<?php
/**
 * 会话管理 API
 * admin/sessions_api.php
 */
require_once __DIR__ . '/shared/functions.php';

header('Content-Type: application/json; charset=utf-8');

$current_admin = auth_get_current_user();
if (!$current_admin || ($current_admin['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'msg' => '未登录或无权限'], JSON_UNESCAPED_UNICODE);
    exit;
}

$action = $_GET['action'] ?? '';

if ($action === 'list') {
    $filter = trim($_GET['username'] ?? '');
    $list = auth_session_list($filter !== '' ? $filter : null);
    echo json_encode(['ok' => true, 'sessions' => $list], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'revoke') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'msg' => '需要 POST 请求'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    csrf_check();
    $jti = trim($_POST['jti'] ?? '');
    if ($jti === '') {
        echo json_encode(['ok' => false, 'msg' => '缺少会话 ID'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $ok = auth_session_revoke($jti);
    echo json_encode(['ok' => $ok, 'msg' => $ok ? '会话已强制下线' : '会话不存在或已过期'], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['ok' => false, 'msg' => '未知操作'], JSON_UNESCAPED_UNICODE);
