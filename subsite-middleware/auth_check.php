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
 *   1. 检查 Cookie 中是否有有效 Token → 放行
 *   2. 检查 URL 参数 _nav_token 是否有效 → 设置 Cookie 后放行
 *   3. 都没有 → 302 跳转回导航登录页
 * ============================================================
 */

// 找到 shared/auth.php（根据实际部署路径调整）
$auth_file = dirname(__DIR__) . '/shared/auth.php';
if (!file_exists($auth_file)) {
    // 兜底：尝试同级目录
    $auth_file = __DIR__ . '/auth.php';
}
require_once $auth_file;

$user = auth_get_current_user();

if (!$user) {
    // 未登录，跳回导航登录页，携带当前 URL 方便登录后回跳
    $redirect = urlencode($_SERVER['REQUEST_URI'] ?? '/');
    header('Location: ' . auth_nav_login_url() . '?redirect=' . $redirect);
    exit;
}

// 如果 Token 来自 URL 参数，则写入 Cookie（之后刷新无需再带参数）
if (!empty($_GET['_nav_token']) && empty($_COOKIE[SESSION_COOKIE_NAME])) {
    auth_set_cookie($_GET['_nav_token']);

    // 去掉 URL 中的 _nav_token 参数，做一次干净的跳转
    $clean_url = strtok($_SERVER['REQUEST_URI'], '?');
    $params = $_GET;
    unset($params['_nav_token']);
    if ($params) {
        $clean_url .= '?' . http_build_query($params);
    }
    header('Location: ' . $clean_url);
    exit;
}

// $user 现在可用：['username' => '...', 'exp' => timestamp, ...]
// 子站可以通过 $GLOBALS['nav_user'] 读取当前登录用户
$GLOBALS['nav_user'] = $user;
