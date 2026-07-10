<?php
/**
 * 登录页 login.php
 * 功能：用户名密码验证、记住我、IP锁定保护、登录日志
 */
require_once __DIR__ . '/../shared/auth.php';
auth_start_php_session();
require_once __DIR__ . '/../shared/request_timing.php';
require_once __DIR__ . '/../admin/shared/functions.php';

// 检测是否需要安装
auth_check_setup();

$error         = '';
$redirect       = $_GET['redirect'] ?? $_POST['redirect'] ?? '';
$safe_redirect  = auth_sanitize_redirect((string)$redirect);
$show_kick_ui   = false; // 是否显示踢人选择界面
$kick_username  = '';
$kick_sessions  = [];
$kick_max       = 3;
$kick_default_jtis = [];

if (($_GET['complete'] ?? '') === '1') {
    $pending_token = $_SESSION['post_login_token'] ?? '';
    $pending_remember = !empty($_SESSION['post_login_remember']);
    unset($_SESSION['post_login_token'], $_SESSION['post_login_remember']);

    if (is_string($pending_token) && $pending_token !== '') {
        auth_set_cookie($pending_token, $pending_remember);
    }

    $loc = $safe_redirect !== '' ? $safe_redirect : '/admin/index.php';
    $loc_json = json_encode($loc, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store');
    ?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta http-equiv="refresh" content="1;url=<?= htmlspecialchars($loc, ENT_QUOTES, 'UTF-8') ?>">
<title>登录中...</title>
</head>
<body>
<script>
setTimeout(function() {
  window.location.replace(<?= $loc_json ?>);
}, 100);
</script>
</body>
</html>
<?php
    exit;
}

// 已登录则跳转到目标地址（若有），否则回首页
$current_user = auth_get_current_user();
if ($current_user) {
    header('Location: ' . ($safe_redirect !== '' ? $safe_redirect : '/admin/index.php'));
    exit;
}
// 被屏蔽时提示用户重新登录（全局变量由 auth_get_current_user() 设置）
if (!$current_user && !empty($GLOBALS['_nav_auth_blocked']) && !empty($_COOKIE[SESSION_COOKIE_NAME])) {
    $error = '当前网络已被限制访问此账户，请重新登录';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF 验证
    csrf_check();
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password']        ?? '';
    $remember = !empty($_POST['remember_me']);
    $ip       = get_client_ip();
    $kick_jtis = $_POST['kick_jti'] ?? [];
    if (!is_array($kick_jtis)) {
        $kick_jtis = [$kick_jtis];
    }
    $kick_jtis = array_values(array_filter(array_map(static fn($v) => trim((string)$v), $kick_jtis), static fn($v) => $v !== ''));
    $kick_oldest = !empty($_POST['kick_oldest']);

    // 检查 IP 是否被锁定
    if (ip_is_locked($ip)) {
        $error = 'IP 已被临时锁定，请稍后再试';
        auth_write_log('IP_LOCKED', $username, $ip);
    } else {
        $user_info = auth_check_password($username, $password);
        if ($user_info) {
            // 检查用户是否被当前 IP / 域名屏蔽
            $clientIp = get_client_ip();
            $host = $_SERVER['HTTP_HOST'] ?? '';
            $blockedIps = auth_user_blocked_ips($username);
            $blockedDomains = auth_user_blocked_domains($username);
            if (!empty($blockedIps) && is_ip_in_list($clientIp, $blockedIps)) {
                $error = '当前 IP 已被禁止访问此账户';
                auth_write_log('LOGIN_BLOCKED_IP', $username, $clientIp);
            } elseif (!empty($blockedDomains) && is_domain_in_list($host, $blockedDomains)) {
                $error = '当前域名已被禁止访问此账户';
                auth_write_log('LOGIN_BLOCKED_DOMAIN', $username, $clientIp);
            } else {
                ip_reset_fails($ip);
                $maxSessions = auth_user_max_sessions($username);
                $activeCount = auth_user_active_session_count($username);

                // 如果携带了 kick_jti，先执行踢人；若前端没有提交具体设备，
                // 则在用户确认后回退为踢掉足够数量的最久未活跃设备。
                if (empty($kick_jtis) && $kick_oldest) {
                    $sessionsForKick = auth_session_list($username);
                    $neededKickCount = max(1, $activeCount - $maxSessions + 1);
                    $oldestSessions = array_slice(array_reverse($sessionsForKick), 0, $neededKickCount);
                    foreach ($oldestSessions as $oldestSession) {
                        if (is_array($oldestSession) && !empty($oldestSession['jti'])) {
                            $kick_jtis[] = (string)$oldestSession['jti'];
                        }
                    }
                }
                if (!empty($kick_jtis)) {
                    $ownedJtis = array_column(auth_session_list($username), 'jti');
                    $revokedCount = 0;
                    foreach (array_unique($kick_jtis) as $kick_jti) {
                        if (!in_array($kick_jti, $ownedJtis, true)) {
                            continue;
                        }
                        if (auth_session_revoke($kick_jti)) {
                            $revokedCount++;
                        }
                    }
                    if ($revokedCount === 0) {
                        $error = '所选设备已失效，请重新选择要下线的设备';
                    }
                    $activeCount = auth_user_active_session_count($username);
                }

                // 检查是否仍超限
                if ($activeCount >= $maxSessions) {
                    $show_kick_ui  = true;
                    $kick_username = $username;
                    $kick_sessions = auth_session_list($username);
                    $kick_max      = $maxSessions;
                    $neededKickCount = max(1, $activeCount - $maxSessions + 1);
                    $oldestSessions = array_slice(array_reverse($kick_sessions), 0, $neededKickCount);
                    $kick_default_jtis = array_values(array_filter(array_map(static fn($s) => (string)($s['jti'] ?? ''), $oldestSessions)));
                    if ($error === '') {
                        $error = '该账户已达到最大同时在线设备数，请确认下线旧设备后继续登录';
                    }
                    // 保留表单值以便再次提交
                } else {
                    // 登录成功
                    $token = auth_generate_token($username, $user_info['role'] ?? 'user', $remember);
                    auth_set_cookie($token, $remember);
                    $_SESSION['post_login_token'] = $token;
                    $_SESSION['post_login_remember'] = $remember;
                    auth_write_log('SUCCESS', $username, $ip, $remember ? 'remember_me' : '');
                    $completeUrl = '/login.php?complete=1&redirect=' . rawurlencode($safe_redirect ?: '/admin/index.php');
                    header('Location: ' . $completeUrl);
                    exit;
                }
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
$site_name   = $cfg['site_name'] ?? '后台中心';
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
  <div class="err">⚠️ 最大同时在线设备数为 <?= (int)$kick_max ?> 台，可多选旧设备下线；已默认选择 <?= count($kick_default_jtis) ?> 台最久未活跃设备</div>
  <form method="POST">
    <?= csrf_field() ?>
    <input type="hidden" name="redirect" value="<?= htmlspecialchars($safe_redirect) ?>">
    <input type="hidden" name="username" value="<?= htmlspecialchars($kick_username) ?>">
    <input type="hidden" name="password" value="<?= htmlspecialchars($_POST['password'] ?? '') ?>">
    <input type="hidden" name="remember_me" value="<?= !empty($_POST['remember_me']) ? '1' : '' ?>">
    <input type="hidden" name="kick_oldest" value="1">
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
            <td style="padding:6px 4px"><input type="checkbox" name="kick_jti[]" value="<?= htmlspecialchars($s['jti']) ?>" <?= in_array((string)($s['jti'] ?? ''), $kick_default_jtis, true) ? 'checked' : '' ?>></td>
            <td style="padding:6px 4px;color:var(--tm)"><?= htmlspecialchars($s['ip'] ?? '-') ?></td>
            <td style="padding:6px 4px;color:var(--tm);word-break:break-all;max-width:200px"><?= htmlspecialchars($s['user_agent'] ?? '-') ?></td>
            <td style="padding:6px 4px;color:var(--tm)"><?= htmlspecialchars($s['last_active'] ?? $s['created_at'] ?? '-') ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <button type="submit" class="btn">下线所选设备并登录</button>
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
