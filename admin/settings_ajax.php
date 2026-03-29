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
    $nginx_bin = nginx_bin();
    $sudo_ok = false;
    if (is_executable('/usr/local/bin/nginx-test')) {
        exec('/usr/local/bin/nginx-test 2>/dev/null', $_, $sc);
        $sudo_ok = ($sc === 0);
    } else {
        exec('sudo -n ' . escapeshellcmd($nginx_bin) . ' -v 2>/dev/null', $_, $sc);
        $sudo_ok = ($sc === 0);
    }
    $hint = 'NGINX_BIN=' . $nginx_bin . "\n"
        . 'echo "$(id -un) ALL=(ALL) NOPASSWD: $NGINX_BIN" > /etc/sudoers.d/nav-nginx' . "\n"
        . 'chmod 440 /etc/sudoers.d/nav-nginx';
    echo json_encode([
        'ok'        => true,
        'sudo_ok'   => $sudo_ok,
        'nginx_bin' => $nginx_bin,
        'sudo_hint' => $hint,
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
