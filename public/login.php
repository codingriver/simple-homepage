<?php
/**
 * 登录页 login.php
 * 功能：用户名密码验证、记住我、IP锁定保护、登录日志
 */
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../shared/auth.php';

// 检测是否需要安装
auth_check_setup();

// 已登录则跳转首页
if (auth_get_current_user()) {
    header('Location: index.php');
    exit;
}

$error    = '';
$redirect = $_GET['redirect'] ?? $_POST['redirect'] ?? '';

// 安全校验 redirect 参数，允许同域、相对路径、以及内网地址跳转
$safe_redirect = '';
if ($redirect) {
    $parsed = parse_url($redirect);
    $host   = $parsed['host'] ?? '';
    $cfg    = auth_get_config();
    $nav    = $cfg['nav_domain'] ?? NAV_DOMAIN;
    // 允许：① 相对路径（无 host）② 同导航站域名 ③ 私有 IP（内网部署）④ .local/.lan/.internal 内网域名
    $is_private_host = $host && (
        is_private_ip($host) ||
        preg_match('/\.(local|lan|internal|intranet|corp)$/i', $host) ||
        preg_match('/^(localhost|127\.0\.0\.1|::1)$/', $host)
    );
    if (!$host || $host === $nav || $is_private_host) {
        $safe_redirect = $redirect;
    }
}

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
<style>
:root{--bg:#080b10;--sf:#0e1218;--bd:#1e2733;--ac:#00d4aa;--ach:#00e8bb;
--tx:#cdd6e8;--tm:#556070;--er:#ff5566;--r:10px;
--fn:'Outfit','PingFang SC','Microsoft YaHei',sans-serif;
--mono:'JetBrains Mono','Consolas',monospace}
*{box-sizing:border-box;margin:0;padding:0}
body{background:var(--bg);color:var(--tx);font-family:var(--fn);min-height:100vh;
display:flex;align-items:center;justify-content:center;padding:20px;
background-image:
  linear-gradient(rgba(0,212,170,0.018) 1px,transparent 1px),
  linear-gradient(90deg,rgba(0,212,170,0.018) 1px,transparent 1px);
background-size:40px 40px}
.card{background:var(--sf);border:1px solid var(--bd);border-radius:14px;
padding:40px 36px;width:100%;max-width:400px;
box-shadow:0 24px 60px rgba(0,0,0,.5);
animation:fadeIn .25s ease}
@keyframes fadeIn{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:translateY(0)}}
.logo{text-align:center;margin-bottom:28px}
.icon{width:52px;height:52px;background:linear-gradient(135deg,#00d4aa,#4dffd4);
border-radius:14px;display:inline-flex;align-items:center;justify-content:center;
font-size:24px;margin-bottom:10px;box-shadow:0 0 24px rgba(0,212,170,0.3)}
h1{font-size:20px;font-weight:700;font-family:var(--mono);color:var(--tx)}
.sub{color:var(--tm);font-size:13px;margin-top:4px}
.fg{margin-bottom:14px}
label{display:block;font-size:10px;color:var(--tm);margin-bottom:5px;
font-weight:700;text-transform:uppercase;letter-spacing:.08em;font-family:var(--mono)}
input[type=text],input[type=password]{width:100%;background:var(--bg);
border:1px solid var(--bd);border-radius:8px;padding:10px 12px;color:var(--tx);
font-size:14px;font-family:var(--fn);outline:none;transition:border-color .2s,box-shadow .2s}
input:focus{border-color:var(--ac);box-shadow:0 0 0 3px rgba(0,212,170,.1)}
input::placeholder{color:var(--tm)}
.rm{display:flex;align-items:center;gap:7px;font-size:13px;color:var(--tm);
cursor:pointer;margin-bottom:16px}
.rm input{width:auto;accent-color:var(--ac)}
.rm span{margin-left:auto;font-size:11px;font-family:var(--mono)}
.err{background:rgba(255,85,102,.08);border:1px solid rgba(255,85,102,.25);
border-radius:8px;padding:10px 13px;color:var(--er);font-size:13px;
margin-bottom:14px;display:flex;align-items:center;gap:7px}
.btn{width:100%;background:var(--ac);color:#000;border:none;border-radius:8px;
padding:12px;font-size:14px;font-weight:700;cursor:pointer;font-family:var(--fn);
transition:all .18s;letter-spacing:.01em}
.btn:hover{background:var(--ach);box-shadow:0 0 16px rgba(0,212,170,.3)}
.btn:active{transform:scale(.98)}
.rescue{margin-top:20px;padding:12px;background:rgba(255,204,68,.06);
border:1px solid rgba(255,204,68,.2);border-radius:8px;font-size:11px;color:#ffcc44}
.rescue code{display:block;margin-top:6px;font-family:var(--mono);font-size:11px;
background:var(--bg);padding:6px 8px;border-radius:4px;color:var(--tx)}
</style></head><body>
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
