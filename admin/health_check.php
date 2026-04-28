<?php
/**
 * 站点健康检测 admin/health_check.php
 */
require_once __DIR__ . '/shared/functions.php';

$current_admin = auth_get_current_user();
if (!$current_admin || ($current_admin['role'] ?? '') !== 'admin') {
    header('Location: /login.php'); exit;
}

// ── GET：返回缓存状态 ──
if (isset($_GET['ajax']) && $_GET['ajax'] === 'status') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true, 'data' => health_load_cache()], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── POST ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'check_all') {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        @set_time_limit(0);
        $data = health_check_all_sites();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'check_one') {
        $url = trim($_POST['url'] ?? '');
        if ($url === '') {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'msg' => '缺少 URL']); exit;
        }
        $r = health_check_single_site($url);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true, 'data' => $r], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ── 保存自动健康检测设置 ──
    if ($action === 'save_health_auto') {
        $cfg = load_config();
        $cfg['health_auto_enabled'] = ($_POST['health_auto_enabled'] ?? '0') === '1' ? '1' : '0';
        $cfg['health_auto_interval'] = max(1, min(1440, (int)($_POST['health_auto_interval'] ?? 5)));
        save_config($cfg);
        audit_log('save_health_auto', ['enabled' => $cfg['health_auto_enabled'], 'interval' => $cfg['health_auto_interval']]);
        flash_set('success', '自动健康检测配置已保存');
        header('Location: health_check.php'); exit;
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'msg' => '未知 action']); exit;
}

$page_title = '站点健康检测';
require_once __DIR__ . '/shared/header.php';

$cfg = auth_get_config();
?>

<div class="card" id="health">
  <div class="card-title">💚 站点健康检测
    <span style="font-size:11px;color:var(--tm);font-weight:400;margin-left:8px">检测所有站点可用性</span>
  </div>

  <!-- 自动检测配置 -->
  <form method="POST" style="margin-bottom:16px">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_health_auto">
    <div style="display:flex;gap:16px;flex-wrap:wrap;align-items:center">
      <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
        <input type="checkbox" name="health_auto_enabled" value="1" <?= ($cfg['health_auto_enabled'] ?? '0') === '1' ? 'checked' : '' ?>
               style="width:16px;height:16px;accent-color:var(--ac)">
        <span style="font-size:13px">启用自动健康检测</span>
      </label>
      <div style="display:flex;align-items:center;gap:8px">
        <span style="font-size:13px;color:var(--tx)">检测间隔</span>
        <input type="number" name="health_auto_interval" value="<?= htmlspecialchars((string)($cfg['health_auto_interval'] ?? 5)) ?>"
               min="1" max="1440" style="width:70px;background:var(--bg);border:1px solid var(--bd);border-radius:8px;padding:6px 10px;color:var(--tx);font-size:13px">
        <span style="font-size:13px;color:var(--tm)">分钟</span>
      </div>
      <button type="submit" class="btn btn-sm btn-secondary">保存自动检测配置</button>
    </div>
    <div class="form-hint" style="margin-top:8px">
      建议通过计划任务调用 <code>php cli/health_check_cron.php</code> 执行自动检测；检测到站点异常时会通过 Webhook 发送告警（需在<a href="notifications.php">通知管理</a>中订阅「健康告警」）。
    </div>
  </form>

  <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:16px">
    <button class="btn btn-primary" onclick="runHealthCheck()">🔍 立即检测所有站点</button>
    <button class="btn btn-secondary" onclick="loadHealthStatus()">🔄 刷新缓存状态</button>
    <span id="health_last_check" style="font-size:12px;color:var(--tm)"></span>
  </div>
  <div id="health_results" style="display:none">
    <div class="table-wrap"><table id="health_table">
      <tr><th>站点名称</th><th>类型</th><th>目标地址</th><th>状态</th><th>响应码</th><th>耗时</th><th>检测时间</th></tr>
    </table></div>
  </div>
  <div id="health_empty" style="color:var(--tm);font-size:13px">点击「立即检测」获取各站点可用性状态。</div>
  <!-- 测试按钮隐藏表单 -->
  <form id="healthCheckForm" method="POST" action="health_check.php" style="display:none">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="check_all">
  </form>
</div>

<script>
// ── 健康检测 ──
function runHealthCheck() {
    var btn = event.target;
    btn.disabled = true;
    btn.textContent = '检测中...';
    document.getElementById('health_empty').textContent = '正在检测，请稍候...';
    document.getElementById('health_empty').style.display = 'block';
    document.getElementById('health_results').style.display = 'none';

    var form = document.getElementById('healthCheckForm');
    fetch('health_check.php', {
        method: 'POST',
        credentials: 'same-origin',
        body: new FormData(form),
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    }).then(function(r){ return r.json(); }).then(function(d){
        btn.disabled = false;
        btn.textContent = '🔍 立即检测所有站点';
        if (d.ok) renderHealthResults(d.data);
        else showToast(d.msg || '检测失败', 'error');
    }).catch(function(){
        btn.disabled = false;
        btn.textContent = '🔍 立即检测所有站点';
        showToast('请求失败，请重试', 'error');
    });
}

function loadHealthStatus() {
    fetch('health_check.php?ajax=status', {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    }).then(function(r){ return r.json(); }).then(function(d){
        if (d.ok && d.data && Object.keys(d.data).length) renderHealthResults(d.data);
        else document.getElementById('health_empty').textContent = '暂无缓存数据，请点击「立即检测」。';
    });
}

function loadHealthSitesMeta(cb) {
    if (window.__healthSitesMeta) { cb(window.__healthSitesMeta); return; }
    fetch('settings_ajax.php?action=health_sites_meta', {
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    }).then(function(r){ return r.json(); }).then(function(d){
        if (d.ok && d.sites) { window.__healthSitesMeta = d.sites; cb(d.sites); }
        else { cb([]); }
    }).catch(function(){ cb([]); });
}

function renderHealthResults(data) {
    loadHealthSitesMeta(function(sites) {
        var tbody = '';
        var checked_any = false;
        sites.forEach(function(s) {
            if (!s.url) return;
            var h = data[s.url];
            if (!h) return;
            checked_any = true;
            var dot = h.status === 'up'
                ? '<span style="color:#4ade80;font-size:16px" title="在线">●</span>'
                : '<span style="color:#f87171;font-size:16px" title="离线">●</span>';
            var ms   = h.ms   != null ? h.ms + ' ms'  : '-';
            var code = h.code ? h.code : '-';
            var t    = h.checked_at ? new Date(h.checked_at * 1000).toLocaleTimeString() : '-';
            var url_short = s.url.length > 40 ? s.url.substring(0,40)+'…' : s.url;
            tbody += '<tr>'
                + '<td>' + escHtml(s.name) + '</td>'
                + '<td><span class="badge badge-' + (s.type==='proxy'?'yellow':s.type==='internal'?'purple':'gray') + '">' + escHtml(s.type) + '</span></td>'
                + '<td style="font-size:11px;font-family:monospace" title="' + escHtml(s.url) + '">' + escHtml(url_short) + '</td>'
                + '<td>' + dot + ' ' + (h.status==='up'?'在线':'离线') + '</td>'
                + '<td style="font-family:monospace">' + code + '</td>'
                + '<td style="font-family:monospace">' + ms + '</td>'
                + '<td style="font-size:11px;color:var(--tm)">' + t + '</td>'
                + '</tr>';
        });

        if (!checked_any) {
            document.getElementById('health_empty').textContent = '没有可检测的站点（站点需配置有效的 URL）。';
            document.getElementById('health_empty').style.display = 'block';
            document.getElementById('health_results').style.display = 'none';
            return;
        }
        document.getElementById('health_table').tBodies[0]
            ? document.getElementById('health_table').tBodies[0].innerHTML = tbody
            : document.getElementById('health_table').innerHTML += '<tbody>' + tbody + '</tbody>';
        document.getElementById('health_results').style.display = 'block';
        document.getElementById('health_empty').style.display = 'none';
        document.getElementById('health_last_check').textContent = '上次刷新：' + new Date().toLocaleTimeString();
    });
}

function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
</script>

<?php require_once __DIR__ . '/shared/footer.php'; ?>
