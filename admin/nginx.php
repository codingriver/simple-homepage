<?php
require_once __DIR__ . '/shared/functions.php';

$targets = nginx_editable_targets();

// 保留默认值，供 AJAX 回退使用
$encOptions = ['utf-8' => 'UTF-8', 'gb18030' => 'GB18030', 'iso-8859-1' => 'ISO-8859-1'];
$encoding = 'utf-8';
$lang = 'nginx';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $current_user = auth_get_current_user();
  if (!$current_user || ($current_user['role'] ?? '') !== 'admin') {
    header('Location: /login.php');
    exit;
  }
  csrf_check();
  $action = trim((string)($_POST['action'] ?? ''));

  // ── 下载 Nginx 代理配置 ──
  if ($action === 'gen_nginx') {
      $cfg        = load_config();
      $sites_data = load_sites();
      $domain     = $cfg['nav_domain'] ?? 'nav.yourdomain.com';
      $lines      = ['# Nginx 代理配置 — 由导航站自动生成于 ' . date('Y-m-d H:i:s'), ''];
      foreach ($sites_data['groups'] as $grp) {
          foreach ($grp['sites'] ?? [] as $s) {
              if (($s['type'] ?? '') !== 'proxy') continue;
              $target = $s['proxy_target'] ?? '';
              $name   = $s['name'] ?? $s['id'];
              if (($s['proxy_mode'] ?? 'path') === 'path') {
                  $slug = $s['slug'] ?? $s['id'];
                  $lines[] = "# {$name}";
                  $lines[] = "location /p/{$slug}/ {";
                  $lines[] = "    proxy_pass {$target}/;";
                  $lines[] = "    proxy_set_header Host \$host;";
                  $lines[] = "    proxy_set_header X-Real-IP \$remote_addr;";
                  $lines[] = "}";
                  $lines[] = '';
              } else {
                  $pd = $s['proxy_domain'] ?? '';
                  if (!$pd) continue;
                  $lines[] = "# {$name} (子域名模式)";
                  $lines[] = 'server {';
                  $lines[] = "    listen 443 ssl http2;";
                  $lines[] = "    server_name {$pd};";
                  $lines[] = "    location / { proxy_pass {$target}; }";
                  $lines[] = '}';
                  $lines[] = '';
              }
          }
      }
      $content = implode("\n", $lines);
      header('Content-Type: text/plain; charset=utf-8');
      header('Content-Disposition: attachment; filename="nav_proxy_' . date('Ymd_His') . '.conf"');
      header('Content-Length: ' . strlen($content));
      echo $content; exit;
  }

  // ── 生成代理配置并 reload（同时保存模板模式） ──
  if ($action === 'nginx_reload' || $action === 'nginx_apply_and_reload') {
      // 如模板模式有变化，先保存
      $newMode = ($_POST['proxy_params_mode'] ?? 'simple') === 'full' ? 'full' : 'simple';
      $cfg = load_config();
      if (($cfg['proxy_params_mode'] ?? 'simple') !== $newMode) {
          $cfg['proxy_params_mode'] = $newMode;
          save_config($cfg);
          audit_log('save_proxy_params_mode', ['mode' => $newMode]);
      }

      $result = nginx_apply_proxy_conf(true);
      if (!$result['ok']) {
          flash_set('error', $result['msg']);
          header('Location: nginx.php'); exit;
      }
      nginx_mark_applied();
      audit_log('nginx_apply', ['reload' => true, 'ok' => true]);
      flash_set('success', $result['msg']);
      header('Location: nginx.php'); exit;
  }

  $ptarget = trim((string)($_POST['target'] ?? 'main'));
  if (!isset($targets[$ptarget])) {
    flash_set('error', '未知配置目标');
    header('Location: nginx.php');
    exit;
  }
  $penc = strtolower(trim((string)($_POST['encoding'] ?? $encoding)));
  if (!isset($encOptions[$penc])) $penc = 'utf-8';
  $plang = strtolower(trim((string)($_POST['language_mode'] ?? $lang)));

  $isAjax = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';

  if ($action === 'save' || $action === 'save_and_reload') {
    $utf8 = (string)($_POST['content'] ?? '');
    $write = $utf8;
    if ($penc !== 'utf-8') {
      $converted = @iconv('UTF-8', strtoupper($penc) . '//IGNORE', $utf8);
      if ($converted === false) {
        if ($isAjax) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok' => false, 'msg' => '编码转换失败，请检查字符与编码是否兼容'], JSON_UNESCAPED_UNICODE); exit; }
        flash_set('error', '编码转换失败，请检查字符与编码是否兼容');
        header('Location: nginx.php');
        exit;
      }
      $write = $converted;
    }

    $saved = nginx_write_target($ptarget, $write);
    if (!$saved['ok']) {
      if ($isAjax) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok' => false, 'msg' => $saved['msg']], JSON_UNESCAPED_UNICODE); exit; }
      flash_set('error', $saved['msg']);
      header('Location: nginx.php');
      exit;
    }

    if ($action === 'save_and_reload') {
      $test = nginx_test_config();
      if (!$test['ok']) {
        if ($isAjax) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok' => false, 'msg' => '语法检测失败，已保存但未 Reload：' . $test['msg']], JSON_UNESCAPED_UNICODE); exit; }
        flash_set('error', '语法检测失败，已保存但未 Reload：' . $test['msg']);
        header('Location: nginx.php');
        exit;
      }
      $reload = nginx_reload();
      if (!$reload['ok']) {
        if ($isAjax) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok' => false, 'msg' => '已保存但 Reload 失败：' . $reload['msg']], JSON_UNESCAPED_UNICODE); exit; }
        flash_set('error', '已保存但 Reload 失败：' . $reload['msg']);
        header('Location: nginx.php');
        exit;
      }
      nginx_mark_applied();
      audit_log('nginx_save_reload', ['target' => $ptarget]);
      if ($isAjax) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok' => true, 'msg' => '保存并 Reload 成功'], JSON_UNESCAPED_UNICODE); exit; }
      flash_set('success', '保存并 Reload 成功');
      header('Location: nginx.php');
      exit;
    }

    audit_log('nginx_save', ['target' => $ptarget]);
    if ($isAjax) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok' => true, 'msg' => '配置已保存：' . ($targets[$ptarget]['label'] ?? $ptarget)], JSON_UNESCAPED_UNICODE); exit; }
    flash_set('success', '配置已保存：' . ($targets[$ptarget]['label'] ?? $ptarget));
    header('Location: nginx.php');
    exit;
  }

  if ($action === 'syntax_test') {
    $test = nginx_test_config_isolated();
    $msg = $test['msg'];
    if (trim((string)$test['test_output']) !== '') $msg .= '｜' . $test['test_output'];
    if ($isAjax) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok' => $test['ok'], 'msg' => $msg], JSON_UNESCAPED_UNICODE); exit; }
    flash_set($test['ok'] ? 'success' : 'error', $msg);
    header('Location: nginx.php');
    exit;
  }

  if ($action === 'syntax_preview') {
    $previewContent = (string)($_POST['content'] ?? '');
    $test = nginx_test_config_preview($ptarget, $previewContent);
    $msg = $test['msg'];
    if (trim((string)$test['test_output']) !== '') $msg .= '｜' . $test['test_output'];
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => $test['ok'], 'msg' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
  }

  // ── 查看兼容模式错误日志 ──
  if ($action === 'view_compat_error') {
      $lines = [];
      $nginx_log = DATA_DIR . '/logs/nginx_compat_error.log';
      $phpfpm_log = DATA_DIR . '/logs/phpfpm_compat_error.log';
      if (file_exists($nginx_log) && filesize($nginx_log) > 0) {
          $lines[] = '【Nginx 错误】';
          $lines[] = file_get_contents($nginx_log);
      }
      if (file_exists($phpfpm_log) && filesize($phpfpm_log) > 0) {
          $lines[] = '【PHP-FPM 错误】';
          $lines[] = file_get_contents($phpfpm_log);
      }
      $content = empty($lines) ? '无错误记录' : implode("\n", $lines);
      if ($isAjax) {
          header('Content-Type: application/json; charset=utf-8');
          echo json_encode(['ok' => true, 'content' => $content], JSON_UNESCAPED_UNICODE);
          exit;
      }
      flash_set('info', nl2br(htmlspecialchars($content)));
      header('Location: nginx.php');
      exit;
  }

  // ── 切换为正常模式（恢复 data 配置）──
  if ($action === 'restore_data_config') {
      // 恢复全部 4 个 symlink（Nginx + Nginx 站点 + PHP-FPM + PHP ini）
      $restore_cmds = [
          'if [ -f /etc/nginx/nginx.conf.data ]; then rm -f /etc/nginx/nginx.conf; mv /etc/nginx/nginx.conf.data /etc/nginx/nginx.conf; fi',
          'if [ -f /etc/nginx/http.d/nav.conf.data ]; then rm -f /etc/nginx/http.d/nav.conf; mv /etc/nginx/http.d/nav.conf.data /etc/nginx/http.d/nav.conf; fi',
          'if [ -f /usr/local/etc/php-fpm.d/nav.conf.data ]; then rm -f /usr/local/etc/php-fpm.d/nav.conf; mv /usr/local/etc/php-fpm.d/nav.conf.data /usr/local/etc/php-fpm.d/nav.conf; fi',
          'if [ -f /usr/local/etc/php/conf.d/99-nav-custom.ini.data ]; then rm -f /usr/local/etc/php/conf.d/99-nav-custom.ini; mv /usr/local/etc/php/conf.d/99-nav-custom.ini.data /usr/local/etc/php/conf.d/99-nav-custom.ini; fi',
      ];
      foreach ($restore_cmds as $cmd) {
          admin_run_command($cmd);
      }

      // 验证 symlink 是否已正确恢复（防止因目录权限不足导致静默失败）
      $expected_links = [
          '/etc/nginx/nginx.conf' => '/var/www/nav/data/nginx/nginx.conf',
          '/etc/nginx/http.d/nav.conf' => '/var/www/nav/data/nginx/http.d/nav.conf',
          '/usr/local/etc/php-fpm.d/nav.conf' => '/var/www/nav/data/php-fpm/nav.conf',
          '/usr/local/etc/php/conf.d/99-nav-custom.ini' => '/var/www/nav/data/php/custom.ini',
      ];
      $links_ok = true;
      foreach ($expected_links as $link => $target) {
          if (!is_link($link) || readlink($link) !== $target) {
              $links_ok = false;
              break;
          }
      }
      if (!$links_ok) {
          // 权限不足，回退到内置配置保持现状
          admin_run_command('cp /var/www/nav/docker/nginx.conf /etc/nginx/nginx.conf');
          admin_run_command('cp /var/www/nav/nginx-conf/docker-site.conf /etc/nginx/http.d/nav.conf');
          admin_run_command('envsubst \'${NAV_PORT}\' < /etc/nginx/http.d/nav.conf > /tmp/nav.conf.tmp && mv /tmp/nav.conf.tmp /etc/nginx/http.d/nav.conf');
          admin_run_command('cp /var/www/nav/docker/php-fpm.conf /usr/local/etc/php-fpm.d/nav.conf');
          admin_run_command('cp /var/www/nav/docker/php-custom.ini /usr/local/etc/php/conf.d/99-nav-custom.ini');
          @touch(DATA_DIR . '/.compat_mode');
          if ($isAjax) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok' => false, 'msg' => '恢复 data 配置失败：目录权限不足，请确保容器内 /etc/nginx/ 等目录对 navwww 可写'], JSON_UNESCAPED_UNICODE); exit; }
          flash_set('error', '恢复 data 配置失败：目录权限不足，请确保容器内 /etc/nginx/ 等目录对 navwww 可写');
          header('Location: nginx.php');
          exit;
      }

      $nginx_test = nginx_test_config();
      $phpfpm_test = php_fpm_test_config();
      $all_ok = $nginx_test['ok'] && $phpfpm_test['ok'];

      if ($all_ok) {
          @unlink(DATA_DIR . '/.compat_mode');
          $reload = nginx_reload();
          $phpreload = php_fpm_reload();
          if ($reload['ok'] && $phpreload['ok']) {
              if ($isAjax) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok' => true, 'msg' => 'data 配置校验通过，Nginx 与 PHP-FPM 已恢复'], JSON_UNESCAPED_UNICODE); exit; }
              flash_set('success', '已切换为正常模式，Nginx 与 PHP-FPM 正在使用 data 目录配置');
          } else {
              $err = [];
              if (!$reload['ok']) $err[] = 'Nginx: ' . $reload['msg'];
              if (!$phpreload['ok']) $err[] = 'PHP-FPM: ' . $phpreload['msg'];
              if ($isAjax) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok' => false, 'msg' => '校验通过但 reload 失败：' . implode('；', $err)], JSON_UNESCAPED_UNICODE); exit; }
              flash_set('error', '配置校验通过但 reload 失败：' . implode('；', $err));
          }
      } else {
          // 任意失败，全部回退到内置配置
          admin_run_command('cp /var/www/nav/docker/nginx.conf /etc/nginx/nginx.conf');
          admin_run_command('cp /var/www/nav/nginx-conf/docker-site.conf /etc/nginx/http.d/nav.conf');
          admin_run_command('envsubst \'${NAV_PORT}\' < /etc/nginx/http.d/nav.conf > /tmp/nav.conf.tmp && mv /tmp/nav.conf.tmp /etc/nginx/http.d/nav.conf');
          admin_run_command('cp /var/www/nav/docker/php-fpm.conf /usr/local/etc/php-fpm.d/nav.conf');
          admin_run_command('cp /var/www/nav/docker/php-custom.ini /usr/local/etc/php/conf.d/99-nav-custom.ini');

          @touch(DATA_DIR . '/.compat_mode');
          $err_output = '';
          if (!$nginx_test['ok']) {
              $err_output .= "【Nginx 错误】\n" . $nginx_test['test_output'] . "\n";
              file_put_contents(DATA_DIR . '/logs/nginx_compat_error.log', $nginx_test['test_output']);
          }
          if (!$phpfpm_test['ok']) {
              $err_output .= "【PHP-FPM 错误】\n" . $phpfpm_test['test_output'] . "\n";
              file_put_contents(DATA_DIR . '/logs/phpfpm_compat_error.log', $phpfpm_test['test_output']);
          }
          if ($isAjax) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok' => false, 'msg' => '切换失败，已回退到兼容模式', 'test_output' => $err_output], JSON_UNESCAPED_UNICODE); exit; }
          flash_set('error', '切换失败，已回退到兼容模式。错误详情：' . $err_output);
      }
      header('Location: nginx.php');
      exit;
  }
}

$page_title = 'Nginx 管理';
require_once __DIR__ . '/shared/header.php';

$cap = nginx_reload_capability();

// 兼容模式检测
$compat_mode = file_exists(DATA_DIR . '/.compat_mode');
$compat_error = '';
$nginx_err_log = DATA_DIR . '/logs/nginx_compat_error.log';
$phpfpm_err_log = DATA_DIR . '/logs/phpfpm_compat_error.log';
if ($compat_mode) {
    if (file_exists($nginx_err_log)) $compat_error .= file_get_contents($nginx_err_log) . "\n";
    if (file_exists($phpfpm_err_log)) $compat_error .= file_get_contents($phpfpm_err_log) . "\n";
}

// 读取所有可编辑目标内容
$editorDataMap = [];
foreach ($targets as $k => $meta) {
  $rr = nginx_read_target($k);
  $path = (string)($meta['path'] ?? '');
  $mtime = '';
  if ($path && is_file($path)) {
    $mt = @filemtime($path);
    if ($mt !== false) $mtime = date('Y-m-d H:i:s', $mt);
  }
  if (!$rr['ok']) {
    $editorDataMap[$k] = [
      'ok' => false,
      'label' => (string)($meta['label'] ?? $k),
      'path' => $path,
      'mtime' => $mtime,
      'content' => '',
      'error' => (string)$rr['msg'],
    ];
    continue;
  }
  $editorDataMap[$k] = [
    'ok' => true,
    'label' => (string)($meta['label'] ?? $k),
    'path' => (string)$rr['path'],
    'mtime' => $mtime,
    'content' => (string)$rr['content'],
    'error' => '',
  ];
}

// 代理配置状态
$proxy_conf_path = nginx_proxy_conf_path();
$conf_exists = file_exists($proxy_conf_path);
$conf_mtime = $conf_exists ? date('Y-m-d H:i:s', filemtime($proxy_conf_path)) : null;
$proxy_count = 0;
foreach (load_sites()['groups'] ?? [] as $g)
  foreach ($g['sites'] ?? [] as $s)
    if (($s['type'] ?? '') === 'proxy') $proxy_count++;

$cfg = load_config();
$ppm = ($cfg['proxy_params_mode'] ?? 'simple') === 'full' ? 'full' : 'simple';
?>
<style>
.ngx-status-bar {
  display: flex;
  align-items: center;
  gap: 16px;
  flex-wrap: wrap;
  background: var(--sf);
  border: 1px solid var(--bd);
  border-radius: 10px;
  padding: 10px 16px;
  margin-bottom: 14px;
  font-size: 13px;
}
.ngx-status-bar > span {
  display: inline-flex;
  align-items: center;
  gap: 6px;
}
.ngx-config-item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 12px 0;
  border-bottom: 1px solid var(--bd);
}
.ngx-config-item:last-child {
  border-bottom: none;
}
.ngx-config-item .meta {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
  min-width: 0;
}
.ngx-config-item .meta .path {
  font-size: 12px;
  color: var(--tm);
  font-family: var(--mono);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.ngx-ppm-btn {
  padding: 6px 14px;
  font-size: 13px;
  border-radius: 8px;
  border: 1px solid var(--bd);
  background: var(--sf);
  color: var(--tx);
  cursor: pointer;
  transition: all .2s;
}
.ngx-ppm-btn.active {
  border-color: var(--ac);
  background: rgba(99,179,237,.12);
  color: var(--ac);
}
.ngx-ppm-btn:hover:not(.active) {
  border-color: var(--ac2);
}
</style>

<!-- 状态栏 -->
<div class="ngx-status-bar">
  <span>
    <span class="badge <?= $cap['ok'] ? 'badge-green' : 'badge-yellow' ?>"><?= htmlspecialchars($cap['method']) ?></span>
    <?php if ($cap['ok']): ?>
    <span style="color:var(--green)">已就绪</span>
    <?php else: ?>
    <span style="color:var(--yellow)">未就绪</span>
    <?php endif; ?>
  </span>
  <span style="color:var(--tm);font-family:var(--mono)"><?= htmlspecialchars($cap['nginx_bin']) ?></span>
  <span style="color:var(--tm)"><?= $proxy_count ?> 个代理站点</span>
</div>

<?php if ($compat_mode): ?>
<style>
.compat-banner {
  background: rgba(255, 193, 7, 0.08);
  border: 1px solid rgba(255, 193, 7, 0.3);
  border-radius: 10px;
  padding: 14px 18px;
  margin-bottom: 14px;
  font-size: 13px;
}
.compat-banner .title {
  color: #ffc107;
  font-weight: 600;
  margin-bottom: 8px;
  display: flex;
  align-items: center;
  gap: 6px;
}
.compat-banner .actions {
  display: flex;
  gap: 10px;
  flex-wrap: wrap;
  margin-top: 10px;
}
.compat-banner .actions button {
  padding: 6px 14px;
  font-size: 12px;
  border-radius: 6px;
  border: 1px solid var(--bd);
  background: var(--sf);
  color: var(--tx);
  cursor: pointer;
}
.compat-banner .actions button:hover {
  border-color: var(--ac);
}
.compat-error-modal pre {
  background: var(--bg);
  padding: 12px;
  border-radius: 8px;
  font-size: 12px;
  max-height: 400px;
  overflow: auto;
  white-space: pre-wrap;
  word-break: break-all;
}
</style>
<div class="compat-banner">
  <div class="title">⚠️ 当前处于 Nginx 兼容模式</div>
  <div>data 目录下的系统配置（Nginx / PHP-FPM / PHP）校验失败，容器已自动切换到<strong>内置默认配置</strong>启动。你的 data 配置未被修改，可点击下方按钮查看错误并尝试恢复。</div>
  <div class="actions">
    <button type="button" onclick="showCompatError()">🔍 查看校验错误</button>
    <button type="button" onclick="restoreDataConfig()">🔄 切换为正常模式</button>
  </div>
</div>
<div id="compat-error-modal" style="display:none" class="modal-overlay">
  <div class="modal">
    <div class="modal-header">系统配置校验错误详情 <button class="close" onclick="document.getElementById('compat-error-modal').style.display='none'">&times;</button></div>
    <div class="modal-body compat-error-modal"><pre id="compat-error-content">加载中...</pre></div>
    <div class="modal-footer">
      <button class="btn btn-secondary" onclick="document.getElementById('compat-error-modal').style.display='none'">关闭</button>
    </div>
  </div>
</div>
<script>
function showCompatError() {
  fetch('nginx.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
    body: 'action=view_compat_error&_csrf=' + encodeURIComponent(window._csrf)
  })
  .then(r => r.json())
  .then(data => {
    document.getElementById('compat-error-content').textContent = data.ok ? data.content : data.msg;
    document.getElementById('compat-error-modal').style.display = 'flex';
  });
}
function restoreDataConfig() {
  if (!confirm('确定要切换为正常模式吗？\n系统将尝试使用 data 目录下的配置，如果校验失败会自动回退到兼容模式。')) return;
  fetch('nginx.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
    body: 'action=restore_data_config&_csrf=' + encodeURIComponent(window._csrf)
  })
  .then(r => r.json())
  .then(data => {
    alert(data.msg);
    if (data.ok) location.reload();
  });
}
</script>
<?php endif; ?>

<!-- 代理配置生成 -->
<div class="card" id="proxy">
  <div class="card-title">🔀 代理配置生成
    <span style="font-size:11px;color:var(--tm);font-weight:400;margin-left:8px">基于站点数据自动生成</span>
  </div>

  <div id="nginx-sudo-banner" style="min-height:0"></div>

  <div style="display:flex;gap:16px;flex-wrap:wrap;align-items:flex-start;margin-bottom:16px">
    <div style="background:var(--bg);border:1px solid var(--bd);border-radius:8px;padding:14px 18px;flex:1;min-width:200px">
      <div style="font-size:11px;color:var(--tm);margin-bottom:4px">配置文件</div>
      <div style="font-size:13px;font-family:monospace"><?= htmlspecialchars($proxy_conf_path) ?></div>
      <div style="margin-top:6px">
        <?php if ($conf_exists): ?>
        <span class="badge badge-green">已生成</span>
        <span style="font-size:11px;color:var(--tm);margin-left:6px">上次更新：<?= $conf_mtime ?></span>
        <?php else: ?>
        <span class="badge badge-gray">未生成</span>
        <?php endif; ?>
      </div>
    </div>
    <div style="background:var(--bg);border:1px solid var(--bd);border-radius:8px;padding:14px 18px;min-width:120px;text-align:center">
      <div style="font-size:11px;color:var(--tm);margin-bottom:4px">Proxy 站点数</div>
      <div style="font-size:28px;font-weight:700;color:var(--ac2)"><?= $proxy_count ?></div>
    </div>
  </div>

  <!-- 反代参数模板 -->
  <div style="margin-bottom:16px">
    <div style="font-size:12px;color:var(--tm);margin-bottom:8px;font-weight:600">反代参数模板</div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <button type="button" class="ngx-ppm-btn <?= $ppm === 'simple' ? 'active' : '' ?>" data-ppm="simple">⚡ 精简模式</button>
      <button type="button" class="ngx-ppm-btn <?= $ppm === 'full' ? 'active' : '' ?>" data-ppm="full">🔥 完整模式</button>
    </div>
    <div id="ppm-desc" style="font-size:12px;color:var(--tm);margin-top:8px;line-height:1.5">
      <?php if ($ppm === 'simple'): ?>
      精简模式：14 条参数，超时 60s，适合普通 Web 应用（默认推荐）。
      <?php else: ?>
      完整模式：60+ 条参数，超时 86400s，适合视频流、大文件、长连接等复杂场景。
      <?php endif; ?>
    </div>
    <div id="ppm-hint" style="font-size:12px;color:var(--tm);margin-top:6px;line-height:1.5">
      💡 切换模板后需点击「生成配置并 Reload」才能生效。
    </div>
  </div>

  <!-- 操作按钮 -->
  <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
    <form method="POST" id="nginx-reload-form" style="display:inline"><?= csrf_field() ?>
      <input type="hidden" name="action" value="nginx_reload">
      <input type="hidden" name="proxy_params_mode" id="proxy-params-mode-input" value="<?= $ppm ?>">
      <button class="btn btn-primary" id="nginx-reload-btn">🔄 生成配置并 Reload</button>
    </form>
    <form method="POST" style="display:inline"><?= csrf_field() ?>
      <input type="hidden" name="action" value="gen_nginx">
      <button class="btn btn-secondary">⬇ 下载配置</button>
    </form>
    <span id="nginx-reload-note" class="form-hint" style="margin:0">按钮始终可点击；失败时会显示具体原因。</span>
  </div>
</div>

<!-- Nginx 配置编辑 -->
<div class="card">
  <div class="card-title">📝 Nginx 配置编辑</div>
  <?php foreach ($editorDataMap as $k => $item): ?>
  <div class="ngx-config-item">
    <div class="meta">
      <span style="font-weight:600;color:var(--tx)"><?= htmlspecialchars($item['label']) ?></span>
      <span class="path"><?= htmlspecialchars($item['path']) ?><?php if ($item['mtime']): ?> · <?= htmlspecialchars($item['mtime']) ?><?php endif; ?></span>
      <?php if (!$item['ok']): ?>
      <span class="badge badge-red" style="font-size:11px">读取失败</span>
      <?php endif; ?>
    </div>
    <button type="button" class="btn btn-sm btn-secondary" data-edit-target="<?= htmlspecialchars($k) ?>" <?= !$item['ok'] ? 'disabled' : '' ?>>编辑</button>
  </div>
  <?php endforeach; ?>
</div>

<script src="assets/ace/ace.js"></script>
<script src="assets/ace/ext-searchbox.js"></script>
<?php require_once __DIR__ . '/shared/ace_editor_modal.php'; ?>
<script>
(function(){
  var editorDataMap = <?= json_encode($editorDataMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

  // ── 打开指定目标的编辑器 ──
  function openEditorForTarget(target) {
    var item = editorDataMap[target];
    if (!item || !item.ok) return;
    var title = item.label + ' · ' + item.path;
    NavAceEditor.open({
      title: title,
      mode: 'nginx',
      value: item.content || '',
      wrapMode: true,
      buttons: {
        left: [
          { type: 'dirty' },
          { text: '检查语法', class: 'btn-secondary', action: 'syntax' }
        ],
        right: [
          { text: '关闭', class: 'btn-secondary', action: 'close' },
          { text: '保存', class: 'btn-secondary', action: 'save' },
          { text: '保存并 Reload', class: 'btn-secondary', action: 'save_reload' }
        ]
      },
      onAction: function(action, value) {
        if (action === 'close') {
          NavAceEditor.close();
          return;
        }
        if (action === 'save' || action === 'save_reload' || action === 'syntax') {
          if (action === 'save_reload') {
            NavConfirm.open({
              title: '保存并 Reload Nginx',
              message: '确认保存并 Reload Nginx？',
              confirmText: '确认',
              cancelText: '取消',
              danger: false,
              onConfirm: function() { doNginxSave(action, value, target); }
            });
            return;
          }
          doNginxSave(action, value, target);
        }
      }
    });
  }

  // ── 保存 / 语法检查 AJAX ──
  function doNginxSave(action, value, target) {
    var serverAction = action;
    if (action === 'save_reload') serverAction = 'save_and_reload';
    if (action === 'syntax') serverAction = 'syntax_preview';

    var payload = new URLSearchParams();
    payload.append('action', serverAction);
    payload.append('content', value);
    payload.append('target', target);
    payload.append('encoding', 'utf-8');
    payload.append('language_mode', 'nginx');
    payload.append('_csrf', window._csrf);

    var allBtns = document.querySelectorAll('#nav-ace-toolbar-actions button, #nav-ace-actions-left button, #nav-ace-actions-right button');
    allBtns.forEach(function(b) { b.disabled = true; });

    fetch('nginx.php', {
      method: 'POST',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      body: payload
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
      allBtns.forEach(function(b) { b.disabled = false; });
      if (data.ok) {
        NavAceEditor.markClean();
        showToast(data.msg, 'success');
        // 更新本地缓存内容
        if (editorDataMap[target]) {
          editorDataMap[target].content = value;
        }
      } else {
        showToast(data.msg || '操作失败', 'error');
      }
    })
    .catch(function() {
      allBtns.forEach(function(b) { b.disabled = false; });
      showToast('请求失败，请检查网络', 'error');
    });
  }

  // ── 绑定编辑按钮 ──
  document.querySelectorAll('[data-edit-target]').forEach(function(btn) {
    btn.addEventListener('click', function() {
      var target = this.getAttribute('data-edit-target');
      openEditorForTarget(target);
    });
  });

  // ── 反代参数模板切换 ──
  var ppmDescEl = document.getElementById('ppm-desc');
  var ppmHintEl = document.getElementById('ppm-hint');
  var ppmInput = document.getElementById('proxy-params-mode-input');
  var initialPpm = <?= json_encode($ppm, JSON_UNESCAPED_UNICODE) ?>;
  var ppmDescriptions = {
    simple: '精简模式：14 条参数，超时 60s，适合普通 Web 应用（默认推荐）。',
    full:   '完整模式：60+ 条参数，超时 86400s，适合视频流、大文件、长连接等复杂场景。'
  };

  function updatePpmUI(mode) {
    document.querySelectorAll('[data-ppm]').forEach(function(btn) {
      var isActive = btn.getAttribute('data-ppm') === mode;
      btn.classList.toggle('active', isActive);
    });
    if (ppmDescEl) ppmDescEl.textContent = ppmDescriptions[mode] || '';
    if (ppmInput) ppmInput.value = mode;
    if (ppmHintEl) {
      if (mode !== initialPpm) {
        ppmHintEl.innerHTML = '⚠️ <b>模板已切换</b>，需点击「生成配置并 Reload」才能生效。';
        ppmHintEl.style.color = '#fbbf24';
      } else {
        ppmHintEl.innerHTML = '💡 切换模板后需点击「生成配置并 Reload」才能生效。';
        ppmHintEl.style.color = 'var(--tm)';
      }
    }
  }

  document.querySelectorAll('[data-ppm]').forEach(function(btn) {
    btn.addEventListener('click', function() {
      updatePpmUI(this.getAttribute('data-ppm'));
    });
  });

  // ── 生成配置表单提交状态 ──
  (function initNginxReloadForm() {
    var form = document.getElementById('nginx-reload-form');
    var btn = document.getElementById('nginx-reload-btn');
    var note = document.getElementById('nginx-reload-note');
    var submitting = false;

    if (!form) return;
    form.addEventListener('submit', function() {
      if (submitting) return false;
      submitting = true;
      if (btn) { btn.disabled = true; btn.textContent = '处理中...'; }
      if (note) note.textContent = '正在生成配置并触发 Nginx Reload，请稍候...';
    });
  })();

  // ── Nginx sudo 环境异步检测 ──
  (function initNginxLazy() {
    var nginxReloadBtn = document.getElementById('nginx-reload-btn');
    var nginxReloadNote = document.getElementById('nginx-reload-note');

    function setNginxReloadUi(state, note) {
      if (nginxReloadBtn) {
        if (state === 'submitting') {
          nginxReloadBtn.disabled = true;
          nginxReloadBtn.textContent = '处理中...';
        } else {
          nginxReloadBtn.disabled = false;
          nginxReloadBtn.textContent = '🔄 生成配置并 Reload';
        }
      }
      if (nginxReloadNote && note) {
        nginxReloadNote.textContent = note;
      }
    }

    var nginxLoaded = false;
    function loadNginxSudoOnce() {
      if (nginxLoaded) return;
      nginxLoaded = true;
      var el = document.getElementById('nginx-sudo-banner');
      if (!el) return;
      fetch('settings_ajax.php?action=nginx_sudo', { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function(r) { return r.json(); })
        .then(function(d) {
          if (!d.ok) {
            if (nginxReloadNote) nginxReloadNote.textContent = '环境检测失败，但仍可尝试提交，失败时会显示具体原因。';
            return;
          }
          if (d.reload_ok) {
            el.innerHTML = '';
            if (nginxReloadNote) nginxReloadNote.textContent = d.message || '环境检测通过，可以直接生成配置并 Reload。';
            return;
          }
          var html = '<div class="alert alert-warn">⚠️ ' + escHtml(d.message || '未检测到可用的 Nginx reload 执行权限。');
          if (d.sudo_hint) {
            html += '<br>请在服务器上执行以下命令配置白名单：<pre style="margin-top:8px;background:var(--bg);padding:10px;border-radius:6px;font-size:12px;overflow-x:auto">' + escHtml(d.sudo_hint) + '</pre>';
          }
          html += '</div>';
          el.innerHTML = html;
          if (nginxReloadNote) nginxReloadNote.textContent = '环境检测未通过，点击按钮后会返回明确错误；也可以先按上方提示补齐执行权限。';
        })
        .catch(function() {
          if (nginxReloadNote) nginxReloadNote.textContent = '环境检测请求失败，但仍可尝试提交，失败时会显示具体原因。';
        });
    }

    var proxyCard = document.getElementById('proxy');
    if (window.IntersectionObserver && proxyCard) {
      var io = new IntersectionObserver(function(entries) {
        entries.forEach(function(e) { if (e.isIntersecting) loadNginxSudoOnce(); });
      }, { rootMargin: '80px' });
      io.observe(proxyCard);
    } else if (proxyCard) {
      loadNginxSudoOnce();
    }
  })();

  // ── 离开页面前确认未保存内容 ──
  window.addEventListener('beforeunload', function(e) {
    if (typeof NavAceEditor !== 'undefined' && NavAceEditor.isDirty && NavAceEditor.isDirty()) {
      e.preventDefault();
      e.returnValue = '';
    }
  });
})();
</script>

<?php require_once __DIR__ . '/shared/footer.php'; ?>
