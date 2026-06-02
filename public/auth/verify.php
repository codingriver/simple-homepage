<?php
/**
 * Nginx auth_request 验证接口
 * 路径：/auth/verify.php
 *
 * 仅允许 Nginx internal 调用（location = /auth/verify.php { internal; }）
 * 返回 200 = 已登录，401 = 未登录/Token无效
 */
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../shared/request_timing.php';

// 从 Cookie 中验证 Token（不从 URL 参数读取，防止伪造）。
// 浏览器可能同时发送同名的 Domain Cookie 和 host-only Cookie，必须逐个验证。
$user = auth_get_current_user();
if (!$user) {
    $reason = auth_last_failure_reason();
    $host = (string)($_SERVER['HTTP_HOST'] ?? '-');
    $uri = (string)($_SERVER['REQUEST_URI'] ?? '-');
    auth_write_log(
        'AUTH_DENY',
        '-',
        get_client_ip(),
        'reason=' . $reason . ' host=' . $host . ' uri=' . $uri
    );
    http_response_code(401);
    exit;
}

// 验证通过，返回 200，并附带用户信息头（Nginx 可转发给上游）
http_response_code(200);
header('X-Auth-User: '  . ($user['username'] ?? ''));
header('X-Auth-Role: '  . ($user['role']     ?? ''));
header('Cache-Control: no-store');
