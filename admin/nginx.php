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
    $test = nginx_test_config();
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
    'readonly' => !empty($meta['readonly']),
  ];
}

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
<div class="compat-banner">
  <div class="title">⚠️ 当前处于 Nginx 兼容模式</div>
  <div>data 目录下的系统配置（Nginx / PHP-FPM / PHP）校验失败，容器已自动切换到<strong>内置默认配置</strong>启动。你的 data 配置未被修改，可点击下方按钮查看错误并尝试恢复。</div>
  <div class="actions">
    <button type="button" onclick="showCompatError()">🔍 查看校验错误</button>
    <button type="button" onclick="restoreDataConfig()">🔄 切换为正常模式</button>
  </div>
</div>
<script>
function showCompatError() {
  NavAceEditor.open({
    title: 'Nginx 配置校验错误',
    mode: 'text',
    value: '加载中…',
    readOnly: true,
    wrapMode: true,
    buttons: {
      left: [],
      right: [{ text: '关闭', class: 'btn-secondary', action: 'close' }]
    },
    onAction: function(action) {
      if (action === 'close') NavAceEditor.close();
    }
  });
  fetch('nginx.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
    body: 'action=view_compat_error&_csrf=' + encodeURIComponent(window._csrf)
  })
  .then(r => r.json())
  .then(data => {
    NavAceEditor.setValue(data.ok ? (data.content || '（空）') : ('加载失败：' + (data.msg || '')));
  })
  .catch(function(err) {
    NavAceEditor.setValue('加载失败：' + (err && err.message ? err.message : String(err)));
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
    <?php if (!empty($item['readonly'])): ?>
    <button type="button" class="btn btn-sm btn-secondary" data-edit-target="<?= htmlspecialchars($k) ?>" <?= !$item['ok'] ? 'disabled' : '' ?>>查看</button>
    <?php else: ?>
    <button type="button" class="btn btn-sm btn-secondary" data-edit-target="<?= htmlspecialchars($k) ?>" <?= !$item['ok'] ? 'disabled' : '' ?>>编辑</button>
    <?php endif; ?>
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
    var isReadonly = !!item.readonly;
    NavAceEditor.open({
      title: title,
      mode: 'nginx',
      value: item.content || '',
      readOnly: isReadonly,
      wrapMode: true,
      buttons: isReadonly ? {
        left: [],
        right: [{ text: '关闭', class: 'btn-secondary', action: 'close' }]
      } : {
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
        if (!isReadonly && (action === 'save' || action === 'save_reload' || action === 'syntax')) {
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
