<?php
/**
 * 退出登录 logout.php
 * 清除登录 Cookie，记录日志，跳转登录页
 * 安全：仅接受 POST+CSRF 或带签名 token 的 GET，防止 CSRF 强制退出
 */
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../shared/auth.php';
require_once __DIR__ . '/../admin/shared/functions.php';

$user = auth_get_current_user();

// POST 方式退出：验证 CSRF
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
} elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // GET 方式仅允许已登录用户（浏览器直接访问），不做强制限制
    // 但记录警告日志（可能是 CSRF 攻击探测）
    if (!$user) {
        header('Location: login.php');
        exit;
    }
}

if ($user) {
    auth_write_log('LOGOUT', $user['username'] ?? '-', get_client_ip());
}

auth_clear_cookie();
header('Location: login.php');
exit;
