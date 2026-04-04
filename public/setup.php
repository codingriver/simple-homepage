<?php
/**
 * 安装向导 setup.php
 * 首次部署时引导管理员设置账户密码和站点名称。
 * 安装完成后写入 .installed 锁，后续访问返回 404。
 */
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../shared/auth.php';
require_once __DIR__ . '/../admin/shared/functions.php';
auth_bootstrap_initial_admin_if_needed();
if (!auth_needs_setup()) {
    http_response_code(404);
    exit('404 Not Found');
}

$errors = [];
$step   = 'form';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF 验证
    csrf_check();
    $username   = trim($_POST['username']   ?? '');
    $password   = $_POST['password']        ?? '';
    $password2  = $_POST['password2']       ?? '';
    $site_name  = trim($_POST['site_name']  ?? '');
    if ($site_name === '') $site_name = '导航中心';
    $nav_domain = trim($_POST['nav_domain'] ?? '');

    $errors = auth_validate_setup_credentials($username, $password, $password2, $site_name);

    if (empty($errors)) {
        auth_apply_initial_install($username, $password, $site_name, $nav_domain);
        $step = 'done';
        $nav_domain_preview = $nav_domain ?: 'nav.yourdomain.com';
    }
}
$nd = htmlspecialchars($_POST['nav_domain'] ?? 'nav.yourdomain.com') ?: 'nav.yourdomain.com';
$nginx_cfg = <<<NGINX
server {
    listen 80;
    server_name {$nd};
    return 301 https://\$host\$request_uri;
}
server {
    listen 443 ssl http2;
    server_name {$nd};
    ssl_certificate     /etc/letsencrypt/live/{$nd}/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/{$nd}/privkey.pem;
    root /var/www/nav/public;
    index index.php login.php;
    location ~* \.(json|sh|md|log)\$ { deny all; }
    location = /auth/verify.php {
        internal;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        fastcgi_pass_request_body off;
        fastcgi_param CONTENT_LENGTH "";
        include fastcgi_params;
    }
    location ^~ /admin/ {
        alias /var/www/nav/admin/;
        location ~ \.php\$ {
            fastcgi_pass unix:/run/php/php8.2-fpm.sock;
            fastcgi_param SCRIPT_FILENAME /var/www/nav/admin\$fastcgi_script_name;
            include fastcgi_params;
        }
    }
    location ~ \.php\$ {
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        include fastcgi_params;
    }
    location / { try_files \$uri \$uri/ /index.php?\$query_string; }
}
NGINX;
?>
<!DOCTYPE html>
<html lang="zh-CN"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>安装向导 — <?= htmlspecialchars($_POST['site_name'] ?? '导航中心') ?></title>
<style>
:root{--bg:#0f1117;--sf:#1a1d27;--bd:#2a2d3a;--ac:#6c63ff;--ach:#7c73ff;
--tx:#e2e4f0;--tm:#7b7f9e;--er:#ff6b6b;--r:12px;
--fn:'Segoe UI','PingFang SC','Microsoft YaHei',sans-serif}
*{box-sizing:border-box;margin:0;padding:0}
body{background:var(--bg);color:var(--tx);font-family:var(--fn);min-height:100vh;
padding:40px 16px;
background-image:radial-gradient(ellipse 80% 60% at 50% -10%,rgba(108,99,255,.18),transparent 70%)}
.wrap{max-width:560px;margin:0 auto}
.card{background:var(--sf);border:1px solid var(--bd);border-radius:var(--r);
padding:40px 36px;box-shadow:0 24px 60px rgba(0,0,0,.4)}
.logo{text-align:center;margin-bottom:28px}
.icon{width:54px;height:54px;background:linear-gradient(135deg,var(--ac),#a78bfa);
border-radius:14px;display:inline-flex;align-items:center;justify-content:center;
font-size:26px;margin-bottom:10px}
h1{font-size:22px;font-weight:700}
.sub{color:var(--tm);font-size:13px;margin-top:4px}
.steps{display:flex;gap:6px;margin-bottom:26px}
.s{flex:1;height:3px;border-radius:2px;background:var(--bd)}
.s.on{background:var(--ac)}
.fg{margin-bottom:14px}
label{display:block;font-size:12px;color:var(--tm);margin-bottom:4px;font-weight:500}
label em{color:var(--er);font-style:normal;margin-left:2px}
input[type=text],input[type=password]{width:100%;background:var(--bg);
border:1px solid var(--bd);border-radius:8px;padding:10px 12px;color:var(--tx);
font-size:14px;font-family:var(--fn);outline:none;transition:border-color .2s}
input:focus{border-color:var(--ac);box-shadow:0 0 0 3px rgba(108,99,255,.12)}
.ht{font-size:11px;color:var(--tm);margin-top:3px}
.errs{background:rgba(255,107,107,.1);border:1px solid rgba(255,107,107,.28);
border-radius:8px;padding:10px 14px;margin-bottom:16px}
.errs li{color:var(--er);font-size:13px;margin-left:16px;line-height:1.8}
.sep{border:none;border-top:1px solid var(--bd);margin:20px 0}
.btn{width:100%;background:var(--ac);color:#fff;border:none;border-radius:8px;
padding:12px;font-size:15px;font-weight:600;cursor:pointer;font-family:var(--fn);margin-top:4px}
.btn:hover{background:var(--ach)}
.done-icon{font-size:48px;text-align:center;margin-bottom:14px}
h2{font-size:18px;font-weight:700;text-align:center;margin-bottom:6px}
.ds{color:var(--tm);font-size:13px;text-align:center;margin-bottom:20px}
.nginx{background:var(--bg);border:1px solid var(--bd);border-radius:8px;
padding:12px;font-size:11px;font-family:monospace;color:#a5f3a5;
overflow-x:auto;white-space:pre;max-height:240px;overflow-y:auto;margin-bottom:14px}
.go{display:block;text-align:center;background:var(--ac);color:#fff;
border-radius:8px;padding:12px;font-size:15px;font-weight:600;
text-decoration:none;margin-top:8px}
.go:hover{background:var(--ach)}
</style></head>
<body><div class="wrap">
<?php if ($step === 'form'): ?>
<div class="card">
  <div class="logo"><div class="icon">🧭</div><h1><?= htmlspecialchars($_POST['site_name'] ?? '导航中心') ?></h1><div class="sub">首次安装向导</div></div>
  <div class="steps"><div class="s on"></div><div class="s"></div></div>
  <?php if (!empty($errors)): ?>
  <ul class="errs"><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul>
  <?php endif; ?>
  <form method="POST">
    <?= csrf_field() ?>
    <div class="fg"><label>管理员用户名<em>*</em></label>
      <input type="text" name="username" required autofocus pattern="[a-zA-Z0-9_-]{2,32}"
             value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
      <div class="ht">字母、数字、下划线、横杠，2-32 位</div></div>
    <div class="fg"><label>密码<em>*</em></label>
      <input type="password" name="password" required autocomplete="new-password">
      <div class="ht">至少 8 位</div></div>
    <div class="fg"><label>确认密码<em>*</em></label>
      <input type="password" name="password2" required autocomplete="new-password"></div>
    <hr class="sep">
    <div class="fg"><label>站点名称<em>*</em></label>
      <input type="text" name="site_name" required
             value="<?= htmlspecialchars($_POST['site_name'] ?? '导航中心') ?>"></div>
    <div class="fg"><label>导航站域名</label>
      <input type="text" name="nav_domain" placeholder="nav.yourdomain.com"
             value="<?= htmlspecialchars($_POST['nav_domain'] ?? '') ?>">
      <div class="ht">用于生成 Nginx 配置，可稍后在后台修改</div></div>
    <button type="submit" class="btn">开始使用 →</button>
  </form>
</div>
<?php else: ?>
<div class="card">
  <div class="steps"><div class="s on"></div><div class="s on"></div></div>
  <div class="done-icon">✅</div>
  <h2>安装完成！</h2>
  <div class="ds">账户已创建，安全密钥已自动生成并保存。<br>请用你设置的密码登录。</div>
  <a href="/login.php" class="go">前往登录 →</a>
</div>
<?php endif; ?>
</div></body></html>
