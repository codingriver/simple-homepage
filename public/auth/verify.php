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

// 从 Cookie 中验证 Token（不从 URL 参数读取，防止伪造）
$token = $_COOKIE[SESSION_COOKIE_NAME] ?? '';
if (!$token) {
    http_response_code(401);
    exit;
}

$payload = auth_verify_token($token);
if (!$payload) {
    http_response_code(401);
    exit;
}

// 验证通过，返回 200，并附带用户信息头（Nginx 可转发给上游）
http_response_code(200);
header('X-Auth-User: '  . ($payload['username'] ?? ''));
header('X-Auth-Role: '  . ($payload['role']     ?? ''));
header('Cache-Control: no-store');
