<?php
/**
 * 退出登录 logout.php
 * 清除登录 Cookie，记录日志，跳转登录页
 * 安全：仅接受 POST+CSRF，防止 CSRF 强制退出
 */
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../shared/auth.php';
require_once __DIR__ . '/../admin/shared/functions.php';

$user = auth_get_current_user();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method Not Allowed');
}
csrf_check();

if ($user) {
    auth_write_log('LOGOUT', $user['username'] ?? '-', get_client_ip());
}

auth_clear_cookie();
header('Location: login.php');
exit;
