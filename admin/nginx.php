<?php
require_once __DIR__ . '/shared/functions.php';

$targets = nginx_editable_targets();
$isAjax = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $current_user = auth_get_current_user();
    if (!$current_user || ($current_user['role'] ?? '') !== 'admin') {
        if ($isAjax) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'msg' => '无权限'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        header('Location: /login.php');
        exit;
    }
    csrf_check();

    $action = trim((string)($_POST['action'] ?? ''));

    if ($action === 'syntax_test') {
        $nginxTest = nginx_test_config();
        $phpFpmTest = php_fpm_test_config();
        $ok = $nginxTest['ok'] && $phpFpmTest['ok'];
        $parts = [
            $nginxTest['msg'] . (trim((string)$nginxTest['test_output']) !== '' ? "\n" . $nginxTest['test_output'] : ''),
            $phpFpmTest['msg'] . (trim((string)$phpFpmTest['test_output']) !== '' ? "\n" . $phpFpmTest['test_output'] : ''),
        ];
        $msg = implode("\n\n", $parts);
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => $ok, 'msg' => $msg], JSON_UNESCAPED_UNICODE);
            exit;
        }
        flash_set($ok ? 'success' : 'error', $msg);
        header('Location: nginx.php');
        exit;
    }

    if ($action === 'view_compat_error') {
        $lines = [];
        $nginxLog = DATA_DIR . '/logs/nginx_compat_error.log';
        $phpFpmLog = DATA_DIR . '/logs/phpfpm_compat_error.log';
        if (is_file($nginxLog) && filesize($nginxLog) > 0) {
            $lines[] = '【Nginx 错误】';
            $lines[] = (string)file_get_contents($nginxLog);
        }
        if (is_file($phpFpmLog) && filesize($phpFpmLog) > 0) {
            $lines[] = '【PHP-FPM 错误】';
            $lines[] = (string)file_get_contents($phpFpmLog);
        }
        $content = empty($lines) ? '无错误记录' : implode("\n", $lines);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true, 'content' => $content], JSON_UNESCAPED_UNICODE);
        exit;
    }

    header('Content-Type: application/json; charset=utf-8');
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'msg' => '后台不再支持修改或 Reload Nginx 配置。请修改配置文件后重启 Docker 容器生效。',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$page_title = '运行配置';
require_once __DIR__ . '/shared/header.php';

$compatMode = is_file(DATA_DIR . '/.compat_mode');
$nginxTest = nginx_test_config();
$phpFpmTest = php_fpm_test_config();

$configDataMap = [];
foreach ($targets as $key => $meta) {
    $read = nginx_read_target($key);
    $path = (string)($meta['path'] ?? '');
    $mtime = '';
    if ($path !== '' && is_file($path)) {
        $timestamp = @filemtime($path);
        if ($timestamp !== false) {
            $mtime = date('Y-m-d H:i:s', $timestamp);
        }
    }

    $configDataMap[$key] = [
        'ok' => (bool)$read['ok'],
        'label' => (string)($meta['label'] ?? $key),
        'path' => (string)($read['path'] ?: $path),
        'mtime' => $mtime,
        'content' => (string)($read['content'] ?? ''),
        'error' => (string)($read['msg'] ?? ''),
    ];
}
?>
<style>
.config-status-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
  gap: 12px;
  margin-bottom: 14px;
}
.config-status-item {
  background: var(--sf);
  border: 1px solid var(--bd);
  border-radius: 8px;
  padding: 12px 14px;
}
.config-status-item .label {
  color: var(--tm);
  font-size: 12px;
  margin-bottom: 6px;
}
.config-status-item .value {
  color: var(--tx);
  font-size: 13px;
  line-height: 1.5;
  white-space: pre-wrap;
}
.config-readonly-note {
  background: rgba(99,179,237,.08);
  border: 1px solid rgba(99,179,237,.28);
  border-radius: 8px;
  color: var(--tx2);
  font-size: 13px;
  line-height: 1.7;
  margin-bottom: 14px;
  padding: 12px 14px;
}
.config-list-item {
  align-items: center;
  border-bottom: 1px solid var(--bd);
  display: flex;
  gap: 12px;
  justify-content: space-between;
  padding: 12px 0;
}
.config-list-item:last-child {
  border-bottom: none;
}
.config-list-item .meta {
  min-width: 0;
}
.config-list-item .title {
  color: var(--tx);
  font-weight: 700;
  margin-bottom: 5px;
}
.config-list-item .path {
  color: var(--tm);
  font-family: var(--mono);
  font-size: 12px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.config-list-item .actions {
  display: flex;
  flex-shrink: 0;
  gap: 8px;
}
</style>

<?php if ($compatMode): ?>
<div class="config-readonly-note" style="border-color:rgba(251,191,36,.45);background:rgba(251,191,36,.10)">
  <strong style="color:#fbbf24">当前处于兼容模式。</strong>
  data 目录下的系统配置校验失败，容器已使用内置默认配置启动。后台仅支持查看错误；修复配置后请重启 Docker 容器。
  <button type="button" class="btn btn-sm btn-secondary" id="viewCompatError" style="margin-left:10px">查看错误</button>
</div>
<?php endif; ?>

<div class="config-readonly-note">
  后台不再支持修改 Nginx / PHP-FPM / PHP 配置，也不再支持在线 Reload。请在宿主机或挂载目录中修改配置文件，然后执行
  <code>docker restart simple-homepage</code> 让配置生效。
</div>

<div class="config-status-grid">
  <div class="config-status-item">
    <div class="label">Nginx 配置检测</div>
    <div class="value">
      <span class="badge <?= $nginxTest['ok'] ? 'badge-green' : 'badge-red' ?>"><?= $nginxTest['ok'] ? '通过' : '失败' ?></span>
      <?= htmlspecialchars($nginxTest['msg']) ?>
    </div>
  </div>
  <div class="config-status-item">
    <div class="label">PHP-FPM 配置检测</div>
    <div class="value">
      <span class="badge <?= $phpFpmTest['ok'] ? 'badge-green' : 'badge-red' ?>"><?= $phpFpmTest['ok'] ? '通过' : '失败' ?></span>
      <?= htmlspecialchars($phpFpmTest['msg']) ?>
    </div>
  </div>
  <div class="config-status-item">
    <div class="label">生效方式</div>
    <div class="value">修改配置后重启 Docker 容器</div>
  </div>
</div>

<div class="card">
  <div class="card-title">🧩 运行配置查看</div>
  <?php foreach ($configDataMap as $key => $item): ?>
  <div class="config-list-item">
    <div class="meta">
      <div class="title">
        <?= htmlspecialchars($item['label']) ?>
        <?php if (!$item['ok']): ?>
          <span class="badge badge-red" style="font-size:11px">读取失败</span>
        <?php endif; ?>
      </div>
      <div class="path">
        <?= htmlspecialchars($item['path']) ?><?php if ($item['mtime']): ?> · <?= htmlspecialchars($item['mtime']) ?><?php endif; ?>
      </div>
    </div>
    <div class="actions">
      <button type="button" class="btn btn-sm btn-secondary" data-view-target="<?= htmlspecialchars($key) ?>" <?= !$item['ok'] ? 'disabled' : '' ?>>查看</button>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<form method="POST" id="syntaxForm" style="display:none">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="syntax_test">
</form>

<script src="assets/ace/ace.js"></script>
<script src="assets/ace/ext-searchbox.js"></script>
<?php require_once __DIR__ . '/shared/ace_editor_modal.php'; ?>
<script>
(function(){
  var configDataMap = <?= json_encode($configDataMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>;

  function openReadonlyViewer(title, content, mode) {
    NavAceEditor.open({
      title: title,
      mode: mode || 'nginx',
      value: content || '',
      readOnly: true,
      wrapMode: true,
      confirmOnClose: false,
      buttons: {
        left: [],
        right: [{ text: '关闭', action: 'close' }]
      },
      onAction: function(action) {
        if (action === 'close') NavAceEditor.close();
      }
    });
  }

  document.querySelectorAll('[data-view-target]').forEach(function(btn) {
    btn.addEventListener('click', function() {
      var key = this.getAttribute('data-view-target');
      var item = configDataMap[key];
      if (!item || !item.ok) return;
      var mode = key === 'php_custom' ? 'ini' : 'nginx';
      openReadonlyViewer(item.label + ' · ' + item.path, item.content, mode);
    });
  });

  var compatBtn = document.getElementById('viewCompatError');
  if (compatBtn) {
    compatBtn.addEventListener('click', function() {
      openReadonlyViewer('兼容模式错误', '加载中...', 'text');
      var payload = new URLSearchParams();
      payload.append('action', 'view_compat_error');
      payload.append('_csrf', window._csrf);
      fetch('nginx.php', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: payload
      })
      .then(function(r) { return r.json(); })
      .then(function(data) {
        NavAceEditor.setValue(data.ok ? (data.content || '无错误记录') : (data.msg || '加载失败'), 'text');
      })
      .catch(function(err) {
        NavAceEditor.setValue('加载失败：' + (err && err.message ? err.message : String(err)), 'text');
      });
    });
  }
})();
</script>

<?php require_once __DIR__ . '/shared/footer.php'; ?>
