<?php
/**
 * 代理站点诊断接口
 */
declare(strict_types=1);

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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'msg' => '仅支持 POST'], JSON_UNESCAPED_UNICODE);
    exit;
}

csrf_check();

$gid = trim((string)($_POST['gid'] ?? ''));
$sid = trim((string)($_POST['sid'] ?? ''));
$action = trim((string)($_POST['action'] ?? 'server'));
if ($sid === '') {
    echo json_encode(['ok' => false, 'msg' => '缺少站点 ID'], JSON_UNESCAPED_UNICODE);
    exit;
}

$found = proxy_diagnose_find_site($gid, $sid);
if ($found === null) {
    echo json_encode(['ok' => false, 'msg' => '站点不存在'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}
@set_time_limit($action === 'browser' ? 75 : 30);

$result = null;
if ($action === 'browser') {
    $proxyUrl = proxy_diagnose_site_url($found['site']);
    $result = proxy_browser_diagnose_run([$proxyUrl], [
        'timeout_ms' => 45000,
        'nav_session' => proxy_browser_diagnose_current_session_cookie(),
    ]);
} else {
    $result = proxy_diagnose_site($found['site'], $found['group']);
}
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
