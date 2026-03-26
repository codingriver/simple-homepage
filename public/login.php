<?php
/**
 * 登录页 login.php
 * 功能：用户名密码验证、记住我、IP锁定保护、登录日志
 */
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../shared/auth.php';
require_once __DIR__ . '/../admin/shared/functions.php';

// 检测是否需要安装
auth_check_setup();

// 已登录则跳转首页
if (auth_get_current_user()) {
    header('Location: index.php');
    exit;
}

$error    = '';
$redirect = $_GET['redirect'] ?? $_POST['redirect'] ?? '';
$safe_redirect = auth_sanitize_redirect((string)$redirect);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF 验证
    csrf_check();
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password']        ?? '';
    $remember = !empty($_POST['remember_me']);
    $ip       = get_client_ip();

    // 检查 IP 是否被锁定
    if (ip_is_locked($ip)) {
        $error = 'IP 已被临时锁定，请稍后再试';
        auth_write_log('IP_LOCKED', $username, $ip);
    } else {
        $user_info = auth_check_password($username, $password);
        if ($user_info) {
            // 登录成功
            ip_reset_fails($ip);
            $token = auth_generate_token($username, $user_info['role'] ?? 'user', $remember);
            auth_set_cookie($token, $remember);
            auth_write_log('SUCCESS', $username, $ip, $remember ? 'remember_me' : '');
            $loc = $safe_redirect ?: 'index.php';
            header('Location: ' . $loc);
            exit;
        } else {
            // 登录失败
            ip_record_fail($ip);
            $error = '用户名或密码错误';
            auth_write_log('FAIL', $username, $ip);
            // 检查是否刚触发锁定
            if (ip_is_locked($ip)) {
                $cfg   = auth_get_config();
                $mins  = (int)($cfg['login_lock_minutes'] ?? 15);
                $error = "连续登录失败次数过多，IP 已被锁定 {$mins} 分钟";
            }
        }
    }
}

// 检查 users.json 是否为空（已安装但数据损坏）
$users_empty = empty(auth_load_users());
$cfg         = auth_get_config();
$site_name   = $cfg['site_name'] ?? '导航中心';
?>
<!DOCTYPE html><html lang="zh-CN"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>登录 — <?= htmlspecialchars($site_name) ?></title>
<link rel="stylesheet" href="login.css">
</head><body>
<div class="card">
  <div class="logo"><div class="icon">🧭</div>
    <h1><?= htmlspecialchars($site_name) ?></h1><div class="sub">请登录以继续</div></div>
  <?php if ($error): ?>
  <div class="err">⚠️ <?= htmlspecialchars($error) ?></div>
  <?php endif; ?>
  <form method="POST">
    <?= csrf_field() ?>
    <input type="hidden" name="redirect" value="<?= htmlspecialchars($safe_redirect) ?>">
    <div class="fg"><label>用户名</label>
      <input type="text" name="username" required autofocus autocomplete="username"
             value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"></div>
    <div class="fg"><label>密码</label>
      <input type="password" name="password" required autocomplete="current-password"></div>
    <label class="rm"><input type="checkbox" name="remember_me" value="1"
      <?= !empty($_POST['remember_me']) ? 'checked' : '' ?>>
      记住我<span><?= (int)(auth_get_config()['remember_me_days'] ?? 60) ?> 天免登录</span></label>
    <button type="submit" class="btn">登 录</button>
  </form>
  <?php if ($users_empty): ?>
  <div class="rescue">
    ⚠️ 账户数据异常，无法登录。请通过 SSH 执行以下命令恢复：
    <code>php /var/www/nav/data/manage_users.php add admin 新密码</code>
    或重新触发安装向导：
    <code>php /var/www/nav/data/manage_users.php setup</code>
  </div>
  <?php endif; ?>
</div>
</body></html>
