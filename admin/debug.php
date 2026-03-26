<?php
/**
 * 调试工具 admin/debug.php
 */

// ── AJAX 处理（在 HTML 之前）──
if (isset($_GET['ajax']) || $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/shared/functions.php';

    // AJAX 日志读取
    if (isset($_GET['ajax']) && $_GET['ajax'] === 'log') {
        $current_admin = auth_get_current_user();
        if (!$current_admin || ($current_admin['role'] ?? '') !== 'admin') {
            http_response_code(401);
            header('Content-Type: text/plain; charset=utf-8');
            echo '（未登录或无权限）'; exit;
        }
        $type  = in_array($_GET['type'] ?? '', ['nginx_access','nginx_error','nginx_main','php_fpm'])
                 ? $_GET['type'] : 'nginx_access';
        $lines = min(500, max(10, (int)($_GET['lines'] ?? 100)));
        header('Content-Type: text/plain; charset=utf-8');
        echo debug_read_log($type, $lines); exit;
    }

    // AJAX 清空日志
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['ajax'] ?? '') === 'clear_log')) {
        $current_admin = auth_get_current_user();
        if (!$current_admin || ($current_admin['role'] ?? '') !== 'admin') {
            http_response_code(401); echo json_encode(['ok'=>false,'msg'=>'未登录']); exit;
        }
        csrf_check();
        header('Content-Type: application/json; charset=utf-8');
        $log_map = [
            'nginx_access' => '/var/log/nginx/nav.access.log',
            'nginx_error'  => '/var/log/nginx/nav.error.log',
            'nginx_main'   => '/var/log/nginx/error.log',
            'php_fpm'      => '/var/log/php-fpm/error.log',
        ];
        $cleared = [];
        $failed  = [];
        foreach ($log_map as $key => $path) {
            if (!file_exists($path)) continue;
            if (file_put_contents($path, '') !== false) {
                $cleared[] = $key;
            } else {
                $failed[] = $key;
            }
        }
        echo json_encode([
            'ok'      => empty($failed),
            'cleared' => $cleared,
            'failed'  => $failed,
            'msg'     => empty($failed) ? '已清空 ' . count($cleared) . ' 个日志文件' : '部分日志清空失败：' . implode(', ', $failed),
        ]);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $current_admin = auth_get_current_user();
        if (!$current_admin || ($current_admin['role'] ?? '') !== 'admin') {
            header('Location: /login.php'); exit;
        }
        csrf_check();
        $action = $_POST['action'] ?? '';

        // ── display_errors 切换 ──
        if ($action === 'toggle_display_errors') {
            $enable = ($_POST['display_errors'] ?? '0') === '1';
            $result = debug_set_display_errors($enable);
            flash_set($result['ok'] ? 'success' : 'error',
                $result['ok'] ? 'display_errors 已' . ($enable ? '开启' : '关闭') : '操作失败：' . $result['msg']);
            header('Location: debug.php#debug'); exit;
        }

        // ── 清除 Cookie ──
        if ($action === 'clear_cookie') {
            auth_clear_cookie();
            flash_set('success', 'Cookie 已清除，即将跳转到登录页');
            header('Location: ../login.php'); exit;
        }
    }
}

$page_title = '调试工具';
require_once __DIR__ . '/shared/header.php';

$cfg = load_config();
?>

<!-- 调试工具 -->
<div class="card" id="debug">
  <div class="card-title">🛠 调试工具</div>
  <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-start">
    <form method="POST" style="display:inline" onsubmit="return confirm('确认清除当前浏览器的登录 Cookie？清除后将跳转到登录页。')"><?= csrf_field() ?>
      <input type="hidden" name="action" value="clear_cookie">
      <button class="btn" style="background:rgba(255,107,107,.12);border:1px solid rgba(255,107,107,.35);color:#ff6b6b">🍪 清除当前 Cookie</button>
    </form>

    <?php $de_on = debug_get_display_errors(); ?>
    <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap">
      <label style="font-size:13px;color:var(--tm)">display_errors</label>
      <form method="POST" style="display:inline" onsubmit="return confirm(this.querySelector('[name=display_errors]').value==='1'?'开启 display_errors 会将 PHP 错误直接输出到页面，仅调试时使用，确认开启？':'确认关闭 display_errors？')"><?= csrf_field() ?>
        <input type="hidden" name="action" value="toggle_display_errors">
        <input type="hidden" name="display_errors" value="<?= $de_on ? '0' : '1' ?>">
        <button type="submit" style="display:flex;align-items:center;gap:10px;background:<?= $de_on ? 'rgba(251,191,36,.1)' : 'rgba(30,32,44,.8)' ?>;border:2px solid <?= $de_on ? '#fbbf24' : 'var(--bd)' ?>;border-radius:50px;padding:6px 16px 6px 8px;cursor:pointer;transition:all .2s">
          <!-- Toggle 滑块 -->
          <span style="display:inline-flex;align-items:center;width:36px;height:20px;background:<?= $de_on ? '#fbbf24' : 'var(--bd)' ?>;border-radius:10px;position:relative;transition:background .2s">
            <span style="position:absolute;<?= $de_on ? 'right:2px' : 'left:2px' ?>;top:2px;width:16px;height:16px;background:#fff;border-radius:50%;transition:all .2s"></span>
          </span>
          <span style="font-size:13px;font-weight:600;color:<?= $de_on ? '#fbbf24' : 'var(--tm)' ?>">
            <?= $de_on ? '🔆 已开启（调试模式）' : '🌙 已关闭（生产模式）' ?>
          </span>
        </button>
      </form>
    </div>
    <div class="form-hint" style="margin-top:10px">
      <b>清除当前 Cookie</b>：清除本浏览器的登录状态，跳转到登录页。不影响其他用户或其他浏览器的登录状态。<br>
      <b>display_errors</b>：点击 Toggle 切换状态。开启后 PHP 错误直接输出到页面，方便调试；<span style="color:#ff6b6b">生产环境请保持关闭</span>。
    </div>
</div>

<!-- 日志查看器 -->
<div class="card" id="logs-viewer">
  <div class="card-title">📄 日志查看器</div>
  <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px">
    <button class="btn btn-secondary btn-sm log-tab active" data-log="nginx_access">Nginx 访问日志</button>
    <button class="btn btn-secondary btn-sm log-tab" data-log="nginx_error">Nginx 错误日志</button>
    <button class="btn btn-secondary btn-sm log-tab" data-log="nginx_main">Nginx 主错误日志</button>
    <button class="btn btn-secondary btn-sm log-tab" data-log="php_fpm">PHP-FPM 日志</button>
    <button class="btn btn-secondary btn-sm" onclick="refreshLog()">🔄 刷新</button>
    <button class="btn btn-sm" onclick="clearAllLogs()" style="background:rgba(255,107,107,.1);border:1px solid rgba(255,107,107,.3);color:#ff6b6b">🗑 清空所有日志</button>
    <select id="logLines" onchange="refreshLog()" style="background:var(--bg);border:1px solid var(--bd);border-radius:7px;padding:5px 10px;color:var(--tx);font-size:12px">
      <option value="50">最近 50 行</option>
      <option value="100" selected>最近 100 行</option>
      <option value="200">最近 200 行</option>
    </select>
  </div>
  <pre id="logContent" style="background:var(--bg);border:1px solid var(--bd);border-radius:8px;
padding:14px;font-size:11px;font-family:monospace;color:#a5f3a5;overflow-x:auto;
max-height:400px;overflow-y:auto;white-space:pre-wrap;word-break:break-all">加载中...</pre>
</div>

<script>
var currentLog = 'nginx_access';
var logTabs = document.querySelectorAll('.log-tab');
logTabs.forEach(function(btn) {
    btn.addEventListener('click', function() {
        logTabs.forEach(function(b){ b.classList.remove('active'); b.style.borderColor=''; b.style.color=''; });
        this.classList.add('active');
        this.style.borderColor = 'var(--ac)';
        this.style.color = 'var(--ac2)';
        currentLog = this.dataset.log;
        refreshLog();
    });
});
// 初始化第一个 tab 样式
logTabs[0] && (logTabs[0].style.borderColor = 'var(--ac)', logTabs[0].style.color = 'var(--ac2)');

function refreshLog() {
    var lines = document.getElementById('logLines').value;
    var pre   = document.getElementById('logContent');
    pre.textContent = '加载中...';
    fetch('debug.php?ajax=log&type=' + currentLog + '&lines=' + lines, {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    }).then(function(r){ return r.text(); }).then(function(t){
        pre.textContent = t;
    }).catch(function(){
        pre.textContent = '加载失败，请重试';
    });
}
function clearAllLogs() {
    if (!confirm('确认清空所有日志文件？此操作不可恢复。')) return;
    var pre = document.getElementById('logContent');
    pre.textContent = '清空中...';
    var form = new FormData();
    form.append('ajax', 'clear_log');
    form.append('_csrf', window.DEBUG_CSRF || '');
    fetch('debug.php', {
        method: 'POST',
        body: form,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    }).then(function(r){ return r.json(); }).then(function(d){
        if (d.ok) {
            pre.textContent = '✅ ' + d.msg + '\n\n日志已清空，刷新中...';
            setTimeout(refreshLog, 1000);
        } else {
            pre.textContent = '❌ ' + d.msg;
        }
    }).catch(function(){
        pre.textContent = '清空请求失败，请重试';
    });
}
refreshLog();
</script>
<script>window.DEBUG_CSRF = <?= json_encode(csrf_token()) ?>;</script>

<?php require_once __DIR__ . '/shared/footer.php'; ?>
