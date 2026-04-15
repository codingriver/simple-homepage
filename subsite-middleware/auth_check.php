<?php
/**
 * 子站验证中间件
 * ============================================================
 * 在每个「自建子站」的入口文件顶部 require 此文件即可。
 *
 * 用法：
 *   <?php
 *   require_once '/path/to/subsite-middleware/auth_check.php';
 *   // 之后就是你的页面逻辑
 *
 * 工作原理：
 *   1. 检查 URL 参数 _nav_token 是否有效 → 设置 Cookie 后 302 到干净 URL
 *   2. 检查 Cookie 中是否有有效 Token → 放行
 *   3. 都没有 → 302 跳转回导航登录页
 * ============================================================
 */

$auth_file = getenv('NAV_AUTH_PHP_PATH');
if (!$auth_file) {
    $auth_file = dirname(__DIR__) . '/shared/auth.php';
}
if (!file_exists($auth_file)) {
    $auth_file = __DIR__ . '/auth.php';
}
if (!file_exists($auth_file)) {
    throw new RuntimeException('找不到 shared/auth.php，请设置 NAV_AUTH_PHP_PATH 环境变量');
}
require_once $auth_file;

// 1. 处理 _nav_token：验证并写入 Cookie，然后 302 到干净 URL
if (!empty($_GET['_nav_token']) && empty($_COOKIE[SESSION_COOKIE_NAME])) {
    $payload = auth_verify_token((string) $_GET['_nav_token']);
    if ($payload) {
        auth_set_cookie($_GET['_nav_token']);
        $clean_url = strtok($_SERVER['REQUEST_URI'], '?');
        $params = $_GET;
        unset($params['_nav_token']);
        if ($params) {
            $clean_url .= '?' . http_build_query($params);
        }
        header('Location: ' . $clean_url);
        exit;
    }
}

// 2. 检查 Cookie 登录态
$user = auth_get_current_user();
if (!$user) {
    $redirect = urlencode($_SERVER['REQUEST_URI'] ?? '/');
    header('Location: ' . auth_nav_login_url() . '?redirect=' . $redirect);
    exit;
}

// 3. 暴露给子站页面
$GLOBALS['nav_user'] = $user;
