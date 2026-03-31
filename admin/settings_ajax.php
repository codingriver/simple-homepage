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
    $capability = nginx_reload_capability();
    echo json_encode([
        'ok' => true,
        'reload_ok' => $capability['ok'],
        'sudo_ok' => $capability['ok'] && $capability['method'] === 'sudo',
        'method' => $capability['method'],
        'message' => $capability['msg'],
        'nginx_bin' => $capability['nginx_bin'],
        'sudo_hint' => $capability['hint'],
        'test_output' => $capability['test_output'],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'health_sites_meta') {
    $sites = [];
    foreach (load_sites()['groups'] ?? [] as $g) {
        foreach ($g['sites'] ?? [] as $s) {
            $sites[] = [
                'name' => $s['name'] ?? '',
                'type' => $s['type'] ?? 'external',
                'url'  => ($s['type'] ?? '') === 'proxy' ? ($s['proxy_target'] ?? '') : ($s['url'] ?? ''),
            ];
        }
    }
    echo json_encode(['ok' => true, 'sites' => $sites], JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(404);
echo json_encode(['ok' => false, 'msg' => '未知 action'], JSON_UNESCAPED_UNICODE);
