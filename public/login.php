<?php
/**
 * 登录页 login.php
 * 功能：用户名密码验证、记住我、IP锁定保护、登录日志
 */
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../shared/auth.php';
require_once __DIR__ . '/../shared/request_timing.php';
require_once __DIR__ . '/../admin/shared/functions.php';

// 检测是否需要安装
auth_check_setup();

// 已登录则跳转到目标地址（若有），否则回首页
if (auth_get_current_user()) {
    $redirect = $_GET['redirect'] ?? $_POST['redirect'] ?? '';
    $safe_redirect = auth_sanitize_redirect((string)$redirect);
    header('Location: ' . ($safe_redirect !== '' ? $safe_redirect : 'index.php'));
    exit;
}

$error         = '';
$redirect       = $_GET['redirect'] ?? $_POST['redirect'] ?? '';
$safe_redirect  = auth_sanitize_redirect((string)$redirect);
$show_kick_ui   = false; // 是否显示踢人选择界面
$kick_username  = '';
$kick_sessions  = [];
$kick_max       = 3;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF 验证
    csrf_check();
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password']        ?? '';
    $remember = !empty($_POST['remember_me']);
    $ip       = get_client_ip();
    $kick_jti = trim($_POST['kick_jti'] ?? '');

    // 检查 IP 是否被锁定
    if (ip_is_locked($ip)) {
        $error = 'IP 已被临时锁定，请稍后再试';
        auth_write_log('IP_LOCKED', $username, $ip);
    } else {
        $user_info = auth_check_password($username, $password);
        if ($user_info) {
            ip_reset_fails($ip);
            $maxSessions = auth_user_max_sessions($username);
            $activeCount = auth_user_active_session_count($username);

            // 如果携带了 kick_jti，先执行踢人
            if ($kick_jti !== '') {
                auth_session_revoke($kick_jti);
                $activeCount = auth_user_active_session_count($username);
            }

            // 检查是否仍超限
            if ($activeCount >= $maxSessions) {
                $show_kick_ui  = true;
                $kick_username = $username;
                $kick_sessions = auth_session_list($username);
                $kick_max      = $maxSessions;
                // 保留表单值以便再次提交
            } else {
                // 登录成功
                $token = auth_generate_token($username, $user_info['role'] ?? 'user', $remember);
                auth_set_cookie($token, $remember);
                auth_write_log('SUCCESS', $username, $ip, $remember ? 'remember_me' : '');
                $loc = $safe_redirect ?: 'index.php';
                header('Location: ' . $loc);
                exit;
            }
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
$theme       = $cfg['theme'] ?? 'dark';
?>
<!DOCTYPE html>
<html lang="zh-CN" data-theme="<?= htmlspecialchars($theme) ?>">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>登录 — <?= htmlspecialchars($site_name) ?></title>
<link rel="stylesheet" href="login.css">
<script src="/gesture-guard.js" defer></script>
<?php if (!empty($cfg['custom_css'] ?? '')): ?>
<style id="nav-custom-css"><?= $cfg['custom_css'] ?></style>
<?php endif; ?>
</head><body>
<div class="card">
  <div class="logo"><div class="icon">🧭</div>
    <h1><?= htmlspecialchars($site_name) ?></h1><div class="sub">请登录以继续</div></div>
  <?php if ($error): ?>
  <div class="err">⚠️ <?= htmlspecialchars($error) ?></div>
  <?php endif; ?>
  <?php if ($show_kick_ui): ?>
  <div class="err">⚠️ 该账户已达到最大同时在线设备数（<?= (int)$kick_max ?> 台），请选择一个设备强制下线后继续登录</div>
  <form method="POST">
    <?= csrf_field() ?>
    <input type="hidden" name="redirect" value="<?= htmlspecialchars($safe_redirect) ?>">
    <input type="hidden" name="username" value="<?= htmlspecialchars($kick_username) ?>">
    <input type="hidden" name="password" value="<?= htmlspecialchars($_POST['password'] ?? '') ?>">
    <input type="hidden" name="remember_me" value="<?= !empty($_POST['remember_me']) ? '1' : '' ?>">
    <div style="margin-bottom:14px">
      <table style="width:100%;font-size:13px;border-collapse:collapse">
        <thead>
          <tr style="text-align:left;border-bottom:1px solid var(--bd)">
            <th style="padding:6px 4px"></th>
            <th style="padding:6px 4px">IP</th>
            <th style="padding:6px 4px">User-Agent</th>
            <th style="padding:6px 4px">最后活跃</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($kick_sessions as $s): ?>
          <tr style="border-bottom:1px solid rgba(255,255,255,.06)">
            <td style="padding:6px 4px"><input type="radio" name="kick_jti" value="<?= htmlspecialchars($s['jti']) ?>" required></td>
            <td style="padding:6px 4px;color:var(--tm)"><?= htmlspecialchars($s['ip'] ?? '-') ?></td>
            <td style="padding:6px 4px;color:var(--tm);word-break:break-all;max-width:200px"><?= htmlspecialchars($s['user_agent'] ?? '-') ?></td>
            <td style="padding:6px 4px;color:var(--tm)"><?= htmlspecialchars($s['last_active'] ?? $s['created_at'] ?? '-') ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <button type="submit" class="btn">确认并登录</button>
  </form>
  <?php else: ?>
  <form method="POST">
    <?= csrf_field() ?>
    <input type="hidden" name="redirect" value="<?= htmlspecialchars($safe_redirect) ?>">
    <div class="fg"><label>用户名</label>
      <input type="text" name="username" required autofocus autocomplete="username"
             value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"></div>
    <div class="fg"><label>密码</label>
      <input type="password" name="password" autocomplete="current-password"></div>
    <label class="rm"><input type="checkbox" name="remember_me" value="1"
      <?= !empty($_POST['remember_me']) ? 'checked' : '' ?>>
      记住我<span><?= (int)(auth_get_config()['remember_me_days'] ?? 60) ?> 天免登录</span></label>
    <button type="submit" class="btn">登 录</button>
  </form>
  <?php endif; ?>
  <?php if (auth_dev_mode_enabled()): ?>
  <p style="margin-top:14px;font-size:12px;color:#64748b;line-height:1.5">
    <strong>开发模式</strong>：内置管理员 <code style="background:#f1f5f9;padding:2px 6px;border-radius:4px">qatest</code>
    / <code style="background:#f1f5f9;padding:2px 6px;border-radius:4px">qatest2026</code>
    （数据卷中已有同名用户时以文件为准；生产环境请勿设置 <code>NAV_DEV_MODE</code>）
  </p>
  <?php endif; ?>
  <?php if ($users_empty): ?>
  <div class="rescue">
    ⚠️ 账户数据异常，无法登录。请在容器内执行以下命令恢复：
    <code>php /var/www/nav/manage_users.php add admin 新密码</code>
    或重新触发安装向导：
    <code>php /var/www/nav/manage_users.php setup</code>
  </div>
  <?php endif; ?>
</div>
</body></html>
