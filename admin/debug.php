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
        $type  = in_array($_GET['type'] ?? '', ['nginx_access','nginx_error','nginx_main','php_fpm','request_timing','dns','dns_python'], true)
                 ? $_GET['type'] : 'nginx_access';
        $lines = min(500, max(10, (int)($_GET['lines'] ?? 100)));
        header('Content-Type: text/plain; charset=utf-8');
        echo debug_read_log($type, $lines); exit;
    }

    if (isset($_GET['ajax']) && $_GET['ajax'] === 'github_main_commit') {
        $current_admin = auth_get_current_user();
        if (!$current_admin || ($current_admin['role'] ?? '') !== 'admin') {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'msg' => '未登录']); exit;
        }

        $url = 'https://api.github.com/repos/codingriver/simple-homepage/commits/main';
        $body = '';
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_TIMEOUT => 5,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_USERAGENT => 'SimpleHomepage-Debug/1.0',
                CURLOPT_HTTPHEADER => [
                    'Accept: application/vnd.github+json',
                ],
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            ]);
            $resp = curl_exec($ch);
            if ($resp !== false) {
                $body = (string)$resp;
            }
            curl_close($ch);
        } else {
            $ctx = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'timeout' => 5,
                    'ignore_errors' => true,
                    'header' => "Accept: application/vnd.github+json\r\nUser-Agent: SimpleHomepage-Debug/1.0\r\n",
                ],
            ]);
            $resp = @file_get_contents($url, false, $ctx);
            if ($resp !== false) {
                $body = (string)$resp;
            }
        }

        $data = json_decode($body, true);
        header('Content-Type: application/json; charset=utf-8');
        if (!is_array($data) || empty($data['sha'])) {
            echo json_encode(['ok' => false, 'msg' => '无法获取 GitHub main 最新提交']); exit;
        }
        echo json_encode(['ok' => true, 'sha' => (string)$data['sha']]); exit;
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
            'dns'          => DATA_DIR . '/logs/dns.log',
            'dns_python'   => DATA_DIR . '/logs/dns_python.log',
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
            header('Location: debug.php?de_toggled=1#debug'); exit;
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
$build_info = nav_read_build_info();
?>

<!-- 镜像构建元数据（CI 注入，便于与 GitHub 对照） -->
<div class="card" id="build-meta">
  <div class="card-title">📦 镜像构建信息</div>
  <?php if ($build_info && ($build_info['git_commit'] ?? '') !== '' && ($build_info['git_commit'] ?? '') !== 'unknown'): ?>
  <p style="color:var(--tm);font-size:13px;margin:0 0 12px">以下为构建镜像时写入的数据。可与 GitHub 上 <code>main</code> 最新提交对比，判断是否已拉取最新镜像。</p>
  <table style="width:100%;border-collapse:collapse;font-size:13px;font-family:var(--mono,monospace)">
    <tr><td style="padding:6px 8px;color:var(--tm);width:140px">git commit</td><td style="padding:6px 8px;word-break:break-all"><?= htmlspecialchars($build_info['git_commit']) ?></td></tr>
    <tr><td style="padding:6px 8px;color:var(--tm)">git ref</td><td style="padding:6px 8px;word-break:break-all"><?= htmlspecialchars($build_info['git_ref']) ?></td></tr>
    <tr><td style="padding:6px 8px;color:var(--tm)">build_date (UTC)</td><td style="padding:6px 8px"><?= htmlspecialchars($build_info['build_date']) ?></td></tr>
    <tr><td style="padding:6px 8px;color:var(--tm)">source</td><td style="padding:6px 8px;word-break:break-all"><?= htmlspecialchars($build_info['source']) ?></td></tr>
  </table>
  <p style="margin:12px 0 0;font-size:13px">
    <a href="<?= htmlspecialchars(rtrim($build_info['source'], '/')) ?>/commit/<?= htmlspecialchars($build_info['git_commit']) ?>" target="_blank" rel="noopener" class="btn btn-secondary btn-sm">在 GitHub 打开该提交</a>
    <span id="gh-compare-hint" style="margin-left:10px;color:var(--tm)"></span>
  </p>
  <script type="application/json" id="nav-build-info-json"><?= json_encode($build_info, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
  <script>
  (function(){
    var el = document.getElementById('gh-compare-hint');
    var raw = document.getElementById('nav-build-info-json');
    if (!el || !raw) return;
    var bi;
    try { bi = JSON.parse(raw.textContent || '{}'); } catch (e) { return; }
    if (!bi.git_commit || bi.git_commit === 'unknown') { el.textContent = ''; return; }
    fetch('debug.php?ajax=github_main_commit', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function(r){ return r.json(); })
      .then(function(j){
        if (!j || !j.ok || !j.sha) { el.textContent = '（无法获取 GitHub main 最新提交）'; return; }
        var remote = j.sha;
        var local = String(bi.git_commit);
        if (remote.indexOf(local) === 0 || local.indexOf(remote) === 0) {
          el.innerHTML = '<span style="color:var(--green)">✓ 与 GitHub <code>main</code> 最新提交一致</span>';
        } else {
          el.innerHTML = '<span style="color:var(--yellow)">⚠ 与当前 <code>main</code> 不一致：远程 <code>' + remote.substring(0, 7) + '</code>，镜像 <code>' + local.substring(0, 7) + '</code> — 请考虑 <code>docker pull</code> 重建</span>';
        }
      })
      .catch(function(){ el.textContent = '（无法连接 GitHub API 对比，请手动核对）'; });
  })();
  </script>
  <?php elseif ($build_info): ?>
  <p style="color:var(--tm);font-size:13px">已存在构建信息文件，但 <code>git_commit</code> 为 <code>unknown</code>（多为本地构建未传参数）。命令行可查看：<code>docker inspect &lt;容器名&gt; --format '{{json .Config.Labels}}'</code></p>
  <?php else: ?>
  <p style="color:var(--tm);font-size:13px">未找到 <code>/var/www/nav/.build-info.json</code>。使用 GitHub Actions 构建的镜像会包含该文件；本地 <code>docker build</code> 可传入 <code>--build-arg GIT_COMMIT=...</code>。</p>
  <?php endif; ?>
</div>

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
    <button class="btn btn-secondary btn-sm log-tab" data-log="request_timing">请求耗时 (recv/done)</button>
    <button class="btn btn-secondary btn-sm log-tab" data-log="dns">DNS 应用日志</button>
    <button class="btn btn-secondary btn-sm log-tab" data-log="dns_python">DNS Python 错误日志</button>
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
var debugUrl = new URL(window.location.href);
var skipAutoLog = debugUrl.searchParams.get('de_toggled') === '1';
if (skipAutoLog) {
    document.getElementById('logContent').textContent = 'display_errors 切换后 PHP-FPM 正在后台重载，请手动刷新日志。';
    debugUrl.searchParams.delete('de_toggled');
    history.replaceState(null, '', debugUrl.pathname + (debugUrl.search ? debugUrl.search : '') + debugUrl.hash);
} else {
    refreshLog();
}
</script>
<script>window.DEBUG_CSRF = <?= json_encode(csrf_token()) ?>;</script>

<?php require_once __DIR__ . '/shared/footer.php'; ?>
