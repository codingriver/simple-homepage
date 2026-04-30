<?php
declare(strict_types=1);

require_once __DIR__ . '/shared/ddns_lib.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/shared/functions.php';
    $user = auth_get_current_user();
    if (!$user || ($user['role'] ?? '') !== 'admin') {
        header('Location: /login.php'); exit;
    }
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'clear_ddns_tasks') {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        @set_time_limit(0);
        backup_create('auto_clear_ddns_tasks');
        $result = ddns_clear_all_tasks();
        audit_log('clear_ddns_tasks', ['removed' => (int)($result['removed'] ?? 0)]);
        flash_set('success', '已清空 ' . (int)($result['removed'] ?? 0) . ' 条 DDNS 任务，并同步清理日志与系统调度器');
        header('Location: ddns.php'); exit;
    }
}

$page_title = 'DDNS 动态解析';
require_once __DIR__ . '/shared/header.php';

$allTasks = ddns_load_tasks()['tasks'] ?? [];
$rows = array_map('ddns_task_row', $allTasks);
$csrf = $GLOBALS['_nav_csrf_token'] ?? csrf_token();
?>

<div class="toolbar">
  <button type="button" class="btn btn-primary" onclick="openDdnsModal()">＋ 新建任务</button>
  <button type="button" class="btn btn-secondary" onclick="refreshRows()">↺ 刷新列表</button>
  <a class="btn btn-secondary" href="scheduled_tasks.php">⏱ 查看计划任务</a>
  <span style="color:var(--tm);font-size:12px">每行只显示任务状态；详细配置在弹窗内完成。</span>
</div>

<div class="card" style="margin-bottom:16px">
  <div class="card-title">调度说明</div>
  <p style="color:var(--tx2);font-size:12px;line-height:1.8">
    DDNS 任务已自动接入计划任务系统。系统会按不同的 Cron 分组生成多个 <code>sys_ddns_dispatcher_xxx</code> 调度器，
    每个调度器只负责执行同一调度表达式下的 DDNS 任务；你无需再手动创建额外 cron。
  </p>
</div>

<div class="card">
  <?php if (empty($rows)): ?>
    <p style="color:var(--tm);font-size:13px">暂无 DDNS 任务，点击「新建任务」创建第一条。</p>
  <?php endif; ?>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>状态</th>
          <th>任务名称</th>
          <th>来源</th>
          <th>域名</th>
          <th>类型</th>
          <th>最近解析值</th>
          <th>调度</th>
          <th>最近执行状态</th>
          <th style="min-width:260px">操作</th>
        </tr>
      </thead>
      <tbody id="ddns-tbody"></tbody>
    </table>
  </div>
</div>

<div id="ddns-modal" style="display:none;position:fixed;inset:0;z-index:900;background:rgba(0,0,0,.65);backdrop-filter:blur(4px);align-items:center;justify-content:center" >
  <div style="background:var(--sf);border:1px solid var(--bd2);border-radius:var(--r2);width:min(760px,96vw);box-shadow:0 24px 64px rgba(0,0,0,.5);display:flex;flex-direction:column;max-height:92vh;">
    <div style="padding:18px 22px 14px;border-bottom:1px solid var(--bd);display:flex;align-items:center;justify-content:space-between">
      <span id="ddns-modal-title" style="font-weight:700;font-size:15px;font-family:var(--mono);color:var(--ac)">新建 DDNS 任务</span>
      <button onclick="closeDdnsModal()" style="background:none;border:none;color:var(--tm);cursor:pointer;font-size:18px;line-height:1;padding:2px 6px">✕</button>
    </div>
    <div style="padding:20px 22px;overflow-y:auto;flex:1">
      <form id="ddns-form" onsubmit="return false">
        <div class="form-grid">
          <div class="form-group">
            <label>任务名称 *</label>
            <input type="text" id="fm-name" placeholder="例：CF 电信优选">
          </div>
          <div class="form-group" style="justify-content:flex-end;padding-bottom:4px">
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px;text-transform:none;letter-spacing:0;font-weight:500;color:var(--tx)">
              <input type="checkbox" id="fm-enabled" checked style="width:16px;height:16px;accent-color:var(--ac)">
              启用此任务
            </label>
          </div>
          <div class="form-group">
            <label>来源类型</label>
            <select id="fm-source-type" onchange="toggleSourceFields()">
              <option value="vps789_cfip">vps789 Cloudflare 优选 IP</option>
              <option value="api4ce_cfip">4ce Cloudflare 优选 IP（三网）</option>
              <option value="uouin_cfip">uouin Cloudflare 优选 IP（三网）</option>
              <option value="cf090227_line">090227 Cloudflare 优选 IP（三网）</option>
              <option value="addressesapi_164746">addressesapi Cloudflare 优选 IP（全局带速度）</option>
              <option value="ipdb030101_bestcf">ipdb030101 Cloudflare 优选 IP（全局）</option>
              <option value="ymyuuu_ipdb_bestcf">ymyuuu-ipdb Cloudflare 优选 IP（全局）</option>
              <option value="cf164746_global">164746 Cloudflare 优选 IP（全局）</option>
              <option value="local_ipv4">本机公网 IPv4</option>
              <option value="local_ipv6">本机公网 IPv6</option>
            </select>
          </div>
          <div class="form-group vps789-only line-only">
            <label>线路</label>
            <select id="fm-line"><option value="CT">CT 电信</option><option value="CU">CU 联通</option><option value="CM">CM 移动</option></select>
          </div>
          <div class="form-group vps789-only">
            <label>选择策略</label>
            <select id="fm-pick-strategy"><option value="best_score">最低评分</option><option value="first">第一名</option></select>
          </div>
          <div class="form-group vps789-only">
            <label>最大延迟 / ms</label>
            <input type="number" id="fm-max-latency" value="250" min="0">
          </div>
          <div class="form-group vps789-only">
            <label>最大丢包 / %</label>
            <input type="number" id="fm-max-loss" value="5" min="0" step="0.1">
          </div>
          <div class="form-group full">
            <label>来源说明</label>
            <div id="fm-source-hint" style="padding:10px 12px;border:1px solid var(--bd);border-radius:10px;background:var(--bg);font-size:12px;color:var(--tx2);line-height:1.7">推荐优先使用 4ce；失败时可配置自动回退到 uouin 或 164746。</div>
          </div>
          <div class="form-group vps789-only">
            <label>回退来源（可选）</label>
            <select id="fm-fallback-type">
              <option value="">不启用回退</option>
              <option value="api4ce_cfip">4ce Cloudflare 优选 IP</option>
              <option value="uouin_cfip">uouin Cloudflare 优选 IP</option>
              <option value="cf090227_line">090227 Cloudflare 优选 IP（三网）</option>
              <option value="addressesapi_164746">addressesapi Cloudflare 优选 IP（全局带速度）</option>
              <option value="ipdb030101_bestcf">ipdb030101 Cloudflare 优选 IP（全局）</option>
              <option value="ymyuuu_ipdb_bestcf">ymyuuu-ipdb Cloudflare 优选 IP（全局）</option>
              <option value="cf164746_global">164746 Cloudflare 优选 IP（全局）</option>
              <option value="vps789_cfip">vps789 Cloudflare 优选 IP</option>
            </select>
          </div>
          <div class="form-group">
            <label>目标域名 *</label>
            <input type="text" id="fm-domain" placeholder="cf.example.com">
          </div>
          <div class="form-group">
            <label>记录类型</label>
            <select id="fm-record-type"><option value="A">A</option><option value="AAAA">AAAA</option></select>
          </div>
          <div class="form-group">
            <label>TTL</label>
            <input type="number" id="fm-ttl" value="120" min="1">
          </div>
          <div class="form-group" style="justify-content:flex-end;padding-bottom:4px">
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px;text-transform:none;letter-spacing:0;font-weight:500;color:var(--tx)">
              <input type="checkbox" id="fm-skip-unchanged" checked style="width:16px;height:16px;accent-color:var(--ac)">
              值未变化时跳过更新
            </label>
          </div>
          <div class="form-group full">
            <label>Cron 表达式 *</label>
            <input type="text" id="fm-cron" value="*/30 * * * *" placeholder="*/30 * * * *" style="font-family:var(--mono)">
            <span class="form-hint">继续使用 crontab；请为 <code>php /var/www/nav/cli/ddns_sync.php</code> 增加计划任务。</span>
          </div>
          <div class="form-group full">
            <label>来源测试结果</label>
            <div id="fm-test-result" style="padding:10px 12px;border:1px solid var(--bd);border-radius:10px;background:var(--bg);font-family:var(--mono);font-size:12px;color:var(--tx2);word-break:break-all;white-space:pre-wrap;line-height:1.7">未测试</div>
          </div>
        </div>
        <div class="form-actions">
          <button type="button" class="btn btn-secondary" onclick="testSource()">测试来源</button>
          <button type="button" class="btn btn-primary" onclick="saveTask(false)">保存</button>
          <button type="button" class="btn btn-primary" onclick="saveTask(true)">保存并立即执行</button>
          <button type="button" class="btn btn-secondary" onclick="closeDdnsModal()">取消</button>
        </div>
      </form>
    </div>
  </div>
</div>

<div id="ddns-log-modal" style="display:none;position:fixed;inset:0;z-index:950;background:rgba(0,0,0,.7);backdrop-filter:blur(4px);align-items:center;justify-content:center;padding:12px" >
  <div style="background:var(--sf);border:1px solid var(--bd2);border-radius:var(--r2);width:min(1280px,99vw);height:min(88vh,920px);box-shadow:0 24px 64px rgba(0,0,0,.6);display:flex;flex-direction:column;overflow:hidden;">
    <div style="padding:16px 20px 12px;border-bottom:1px solid var(--bd);display:flex;align-items:center;justify-content:space-between;gap:12px;flex-shrink:0;flex-wrap:wrap">
      <span id="ddns-log-modal-title" style="font-weight:700;font-size:14px;font-family:var(--mono);color:var(--blue)">DDNS 日志</span>
      <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;justify-content:flex-end">
        <input type="text" id="ddns-log-search" placeholder="搜索当前页日志..." oninput="applyDdnsLogView()" style="width:min(320px,48vw);background:var(--bg);border:1px solid var(--bd);border-radius:8px;padding:8px 10px;color:var(--tx);font-size:12px;font-family:var(--mono)">
        <button type="button" class="btn btn-sm btn-secondary" onclick="clearDdnsLogSearch()">清空搜索</button>
        <button type="button" class="btn btn-sm btn-danger" onclick="clearCurrentDdnsLog()">清空日志</button>
        <button onclick="closeDdnsLogModal()" style="background:none;border:none;color:var(--tm);cursor:pointer;font-size:18px;line-height:1;padding:2px 6px">✕</button>
      </div>
    </div>
    <div id="ddns-log-body" style="flex:1;overflow:auto;padding:0;font-family:var(--mono);font-size:12px;line-height:1.6;color:var(--tx2);background:var(--bg);">
      <div style="padding:16px 20px;color:var(--tm)">加载中…</div>
    </div>
    <div style="padding:12px 20px;border-top:1px solid var(--bd);display:flex;align-items:center;gap:12px;flex-shrink:0;flex-wrap:wrap">
      <span id="ddns-log-info" style="font-size:12px;color:var(--tm);font-family:var(--mono)"></span>
      <div style="margin-left:auto;display:flex;gap:6px;align-items:center;flex-wrap:wrap">
        <button class="btn btn-sm btn-secondary" id="ddns-log-prev" onclick="ddnsLogLoadPage(ddnsLogState.page-1, false)">◀ 上一页</button>
        <span id="ddns-log-page-label" style="font-size:12px;font-family:var(--mono);color:var(--tx2)"></span>
        <button class="btn btn-sm btn-secondary" id="ddns-log-next" onclick="ddnsLogLoadPage(ddnsLogState.page+1, false)">下一页 ▶</button>
        <button class="btn btn-sm btn-secondary" onclick="ddnsLogLoadPage(1, false)" title="第一页">⏮</button>
        <button class="btn btn-sm btn-secondary" id="ddns-log-last-btn" onclick="ddnsLogLoadPage(ddnsLogState.pages, false)" title="最后一页">⏭</button>
      </div>
    </div>
  </div>
</div>

<script>
var DDNS_ROWS = <?= json_encode($rows, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_APOS) ?>;
var DDNS_TASKS = {};
<?php foreach ($allTasks as $task): ?>
DDNS_TASKS[<?= json_encode($task['id'] ?? '') ?>] = <?= json_encode($task, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_APOS) ?>;
<?php endforeach; ?>
var DDNS_CSRF = <?= json_encode($csrf) ?>;
var DDNS_EDIT_ID = '';
var DDNS_SOURCE_HINTS = {
  vps789_cfip: 'vps789：现有稳定来源，支持三网线路；适合继续沿用。',
  api4ce_cfip: '4ce：JSON API，结构最适合当前 DDNS，推荐作为主来源。',
  uouin_cfip: 'uouin：HTML 表格抓取型来源，支持三网，适合作为备用源。',
  cf090227_line: '090227：纯文本 IP 列表，支持三网线路；无延迟/丢包数据，建议配合 first 策略使用。',
  addressesapi_164746: 'addressesapi：全局优选 IP，带速度数据（MB/s）；按速度排序，建议配合 best_score 策略。',
  ipdb030101_bestcf: 'ipdb030101：全局优选 IP 纯文本列表；无元数据，建议配合 first 策略使用。',
  ymyuuu_ipdb_bestcf: 'ymyuuu-ipdb：GitHub Raw 反代优选 IP 列表；无元数据，建议配合 first 策略使用。',
  cf164746_global: '164746：全局优选榜单，不区分三网；适合做全局 fallback。',
  local_ipv4: '本机公网 IPv4：直接获取当前出口 IPv4，不依赖第三方优选源。',
  local_ipv6: '本机公网 IPv6：直接获取当前出口 IPv6，不依赖第三方优选源。'
};

function escapeHtml(str) {
  return String(str || '').replace(/[&<>"']/g, function (s) {
    return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[s];
  });
}

function statusBadge(status, timeText) {
  var label = '失败';
  var cls = 'badge badge-red';
  if (status === 'success') {
    label = '成功';
    cls = 'badge badge-green';
  } else if (status === 'running') {
    label = '运行中';
    cls = 'badge badge-blue';
  }
  var timeHtml = timeText ? '<div style="margin-top:4px;font-size:11px;color:var(--tm);font-family:var(--mono)">' + escapeHtml(timeText) + '</div>' : '';
  return '<div>'
    + '<span class="' + cls + '">' + label + '</span>'
    + timeHtml
      + '</div>';
}

var DDNS_RUN_POLLERS = {};

function renderRows() {
  var tbody = document.getElementById('ddns-tbody');
  if (!tbody) return;
  tbody.innerHTML = '';
  if (!DDNS_ROWS.length) {
    var empty = document.createElement('tr');
    empty.innerHTML = '<td colspan="9" style="color:var(--tm);padding:18px 12px">暂无 DDNS 任务，点击上方“新建任务”开始。</td>';
    tbody.appendChild(empty);
    return;
  }
  DDNS_ROWS.forEach(function(row){
    var tr = document.createElement('tr');
    var jsonId = JSON.stringify(row.id).replace(/"/g, '&quot;');
    var jsonName = JSON.stringify(row.name).replace(/"/g, '&quot;');
    tr.innerHTML = ''
      + '<td>' + (row.enabled ? '<span class="badge badge-green">启用</span>' : '<span class="badge badge-gray">禁用</span>') + '</td>'
      + '<td style="font-weight:600">' + escapeHtml(row.name) + '</td>'
      + '<td>' + escapeHtml(row.source_label) + '</td>'
      + '<td style="font-family:var(--mono)">' + escapeHtml(row.domain) + '</td>'
      + '<td><code>' + escapeHtml(row.record_type) + '</code></td>'
      + '<td style="font-family:var(--mono);max-width:180px;word-break:break-all">' + escapeHtml(row.last_value || '—') + '</td>'
      + '<td style="font-family:var(--mono)">' + escapeHtml(row.cron) + '</td>'
      + '<td>' + statusBadge(row.last_status, (row.last_status === 'running' ? row.started_at : row.last_run_at) || '') + '</td>'
      + '<td style="white-space:nowrap">'
      + '<button type="button" class="btn btn-sm btn-secondary" style="min-width:58px;text-align:center;justify-content:center" onclick="toggleTask(' + jsonId + ')">' + (row.enabled ? '禁用' : '启用') + '</button> '
      + '<button type="button" class="btn btn-sm btn-secondary" style="min-width:58px;text-align:center;justify-content:center;' + (row.last_status === 'running' ? 'opacity:.55;cursor:not-allowed' : '') + '" onclick="' + (row.last_status === 'running' ? 'return false' : 'runTask(' + jsonId + ')') + '">' + (row.last_status === 'running' ? '运行中' : '执行') + '</button> '
      + '<button type="button" class="btn btn-sm btn-secondary" style="min-width:58px;text-align:center;justify-content:center" onclick="openDdnsLogModal(' + jsonId + ', ' + jsonName + ')">日志</button> '
      + '<button type="button" class="btn btn-sm btn-secondary" style="min-width:58px;text-align:center;justify-content:center" onclick="openDdnsModal(' + jsonId + ')">编辑</button> '
      + '<button type="button" class="btn btn-sm btn-danger" style="min-width:58px;text-align:center;justify-content:center" onclick="deleteTask(' + jsonId + ', ' + jsonName + ')">删除</button>'
      + '</td>';
    tbody.appendChild(tr);
  });
}

function toggleSourceFields() {
  var type = document.getElementById('fm-source-type').value;
  var needsLine = ['vps789_cfip', 'api4ce_cfip', 'uouin_cfip', 'cf090227_line'].indexOf(type) !== -1;
  var needsFilters = ['vps789_cfip', 'api4ce_cfip', 'uouin_cfip', 'cf090227_line', 'addressesapi_164746', 'ipdb030101_bestcf', 'ymyuuu_ipdb_bestcf', 'cf164746_global'].indexOf(type) !== -1;
  document.querySelectorAll('.vps789-only').forEach(function(el){ el.style.display = needsFilters ? '' : 'none'; });
  document.querySelectorAll('.line-only').forEach(function(el){ el.style.display = needsLine ? '' : 'none'; });
  var hint = document.getElementById('fm-source-hint');
  if (hint) hint.textContent = DDNS_SOURCE_HINTS[type] || '未配置来源说明';
  var fallback = document.getElementById('fm-fallback-type');
  if (fallback) {
    Array.prototype.forEach.call(fallback.options, function(opt) {
      opt.disabled = !!opt.value && opt.value === type;
    });
    if (fallback.value === type) fallback.value = '';
  }
}

function openDdnsModal(id) {
  DDNS_EDIT_ID = id || '';
  var task = id ? DDNS_TASKS[id] : null;
  document.getElementById('ddns-modal-title').textContent = id ? '编辑 DDNS 任务' : '新建 DDNS 任务';
  document.getElementById('fm-name').value = task ? (task.name || '') : '';
  document.getElementById('fm-enabled').checked = task ? !!task.enabled : true;
  document.getElementById('fm-source-type').value = task ? ((task.source || {}).type || 'vps789_cfip') : 'vps789_cfip';
  document.getElementById('fm-line').value = task ? ((task.source || {}).line || 'CT') : 'CT';
  document.getElementById('fm-pick-strategy').value = task ? ((task.source || {}).pick_strategy || 'best_score') : 'best_score';
  document.getElementById('fm-max-latency').value = task ? ((task.source || {}).max_latency || 250) : 250;
  document.getElementById('fm-max-loss').value = task ? ((task.source || {}).max_loss_rate || 5) : 5;
  document.getElementById('fm-fallback-type').value = task ? ((task.source || {}).fallback_type || '') : '';
  document.getElementById('fm-domain').value = task ? ((task.target || {}).domain || '') : '';
  document.getElementById('fm-record-type').value = task ? ((task.target || {}).record_type || 'A') : 'A';
  document.getElementById('fm-ttl').value = task ? ((task.target || {}).ttl || 120) : 120;
  document.getElementById('fm-skip-unchanged').checked = task ? !!((task.target || {}).skip_when_unchanged) : true;
  document.getElementById('fm-cron').value = task ? ((task.schedule || {}).cron || '*/30 * * * *') : '*/30 * * * *';
  setSourceTestResultState(['未测试'], 'loading');
  toggleSourceFields();
  document.getElementById('ddns-modal').style.display = 'flex';
}

function closeDdnsModal() {
  document.getElementById('ddns-modal').style.display = 'none';
}

var ddnsLogState = { id: '', name: '', page: 1, pages: 1, lines: [] };

function clearDdnsLogSearch() {
  var input = document.getElementById('ddns-log-search');
  if (!input) return;
  input.value = '';
  applyDdnsLogView();
}

function renderDdnsLogRows(lines, keyword) {
  if (!lines || !lines.length) {
    return '<div style="padding:16px 20px;color:var(--tm)">暂无日志记录</div>';
  }
  var lowerKeyword = String(keyword || '').trim().toLowerCase();
  var html = [];
  var matched = 0;
  lines.forEach(function(line, idx) {
    var text = String(line || '');
    if (lowerKeyword && text.toLowerCase().indexOf(lowerKeyword) === -1) return;
    matched++;
    var safe = escapeHtml(text);
    var cls = 'color:var(--tx2)';
    if (/\[ERROR\]|失败|fail/i.test(text)) cls = 'color:var(--red)';
    else if (/跳过|skip/i.test(text)) cls = 'color:var(--yellow)';
    else if (/成功|success|更新/i.test(text)) cls = 'color:var(--green)';
    html.push(
      '<div style="display:grid;grid-template-columns:72px 1fr;gap:0;border-bottom:1px solid rgba(255,255,255,.04)">' +
        '<div style="padding:6px 12px;color:var(--tm);background:rgba(255,255,255,.02);border-right:1px solid rgba(255,255,255,.05);text-align:right;user-select:none">' + (idx + 1) + '</div>' +
        '<div style="padding:6px 14px;white-space:pre-wrap;word-break:break-word;' + cls + '">' + safe + '</div>' +
      '</div>'
    );
  });
  if (!matched) {
    return '<div style="padding:16px 20px;color:var(--yellow)">当前页没有匹配“' + escapeHtml(keyword) + '”的日志</div>';
  }
  return html.join('');
}

function applyDdnsLogView() {
  var body = document.getElementById('ddns-log-body');
  var input = document.getElementById('ddns-log-search');
  if (!body) return;
  body.innerHTML = renderDdnsLogRows(ddnsLogState.lines || [], input ? input.value : '');
}

function openDdnsLogModal(id, name) {
  ddnsLogState = { id: id, name: name, page: 1, pages: 1, lines: [] };
  document.getElementById('ddns-log-modal-title').textContent = 'DDNS 日志 — ' + name;
  var search = document.getElementById('ddns-log-search');
  if (search) search.value = '';
  document.getElementById('ddns-log-modal').style.display = 'flex';
  ddnsLogLoadPage(1, true);
}

function closeDdnsLogModal() {
  document.getElementById('ddns-log-modal').style.display = 'none';
}

/* 弹窗背景点击关闭防护（阻止 mousedown 在内容区、mouseup 在背景层的误触） */
(function(){
  var mdTarget = null;
  var ddnsModal = document.getElementById('ddns-modal');
  if (ddnsModal) {
    ddnsModal.addEventListener('mousedown', function(e){ mdTarget = e.target; });
    ddnsModal.addEventListener('click', function(e){
      if (e.target === ddnsModal && mdTarget === ddnsModal) closeDdnsModal();
      mdTarget = null;
    });
  }
  var ddnsLogModal = document.getElementById('ddns-log-modal');
  if (ddnsLogModal) {
    ddnsLogModal.addEventListener('mousedown', function(e){ mdTarget = e.target; });
    ddnsLogModal.addEventListener('click', function(e){
      if (e.target === ddnsLogModal && mdTarget === ddnsLogModal) closeDdnsLogModal();
      mdTarget = null;
    });
  }
})();

async function clearCurrentDdnsLog() {
  if (!ddnsLogState.id) return;
  NavConfirm.open({
    title: '清空日志',
    message: '确定清空当前 DDNS 任务日志？此操作不可恢复。',
    confirmText: '清空',
    cancelText: '取消',
    danger: true,
    onConfirm: async function() {
      var res = await postAjax('log_clear', {id: ddnsLogState.id});
      if (!res.ok) {
        showToast(res.msg || '清空日志失败', 'error');
        return;
      }
      showToast(res.msg || '日志已清空', 'success');
      ddnsLogLoadPage(1, false);
    }
  });
}

async function ddnsLogLoadPage(p, jumpToLast) {
  if (p < 1 || p > ddnsLogState.pages) return;
  ddnsLogState.page = p;
  var body = document.getElementById('ddns-log-body');
  body.innerHTML = '<div style="padding:16px 20px;color:var(--tm)">加载中…</div>';
  var res = await postAjax('log', {id: ddnsLogState.id, page: p});
  if (!res.ok) {
    body.innerHTML = '<div style="padding:16px 20px;color:var(--red)">' + escapeHtml(res.msg || '读取日志失败') + '</div>';
    return;
  }
  var d = res.data || {};
  ddnsLogState.pages = d.pages || 1;
  ddnsLogState.page = d.page || 1;
  if (jumpToLast && d.pages > 1) {
    ddnsLogLoadPage(d.pages, false);
    return;
  }
  ddnsLogState.lines = Array.isArray(d.lines) ? d.lines : [];
  document.getElementById('ddns-log-info').textContent = '共 ' + (d.total || 0) + ' 行，每页 100 行；按最新日志优先显示；支持当前页搜索';
  document.getElementById('ddns-log-page-label').textContent = '第 ' + (d.page || 1) + ' / ' + (d.pages || 1) + ' 页';
  document.getElementById('ddns-log-prev').disabled = (d.page || 1) <= 1;
  document.getElementById('ddns-log-next').disabled = (d.page || 1) >= (d.pages || 1);
  document.getElementById('ddns-log-last-btn').disabled = (d.page || 1) >= (d.pages || 1);
  applyDdnsLogView();
  body.scrollTop = 0;
}

function currentTaskPayload() {
  return {
    name: document.getElementById('fm-name').value.trim(),
    enabled: document.getElementById('fm-enabled').checked,
    source: {
      type: document.getElementById('fm-source-type').value,
      line: document.getElementById('fm-line').value,
      pick_strategy: document.getElementById('fm-pick-strategy').value,
      max_latency: Number(document.getElementById('fm-max-latency').value || 0),
      max_loss_rate: Number(document.getElementById('fm-max-loss').value || 0),
      fallback_type: document.getElementById('fm-fallback-type').value
    },
    target: {
      domain: document.getElementById('fm-domain').value.trim(),
      record_type: document.getElementById('fm-record-type').value,
      ttl: Number(document.getElementById('fm-ttl').value || 120),
      skip_when_unchanged: document.getElementById('fm-skip-unchanged').checked
    },
    schedule: {
      cron: document.getElementById('fm-cron').value.trim()
    }
  };
}

async function postAjax(action, payload) {
  payload = payload || {};
  payload.action = action;
  payload._csrf = DDNS_CSRF;
  var resp = await fetch('ddns_ajax.php', {
    method: 'POST',
    headers: {'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
    body: JSON.stringify(payload)
  });
  return await resp.json();
}

function formatSourceLabel(type) {
  return ({
    vps789_cfip: 'vps789 Cloudflare 优选 IP',
    api4ce_cfip: '4ce Cloudflare 优选 IP',
    uouin_cfip: 'uouin Cloudflare 优选 IP',
    cf090227_line: '090227 Cloudflare 优选 IP（三网）',
    addressesapi_164746: 'addressesapi Cloudflare 优选 IP（全局带速度）',
    ipdb030101_bestcf: 'ipdb030101 Cloudflare 优选 IP（全局）',
    ymyuuu_ipdb_bestcf: 'ymyuuu-ipdb Cloudflare 优选 IP（全局）',
    cf164746_global: '164746 Cloudflare 优选 IP（全局）',
    local_ipv4: '本机公网 IPv4',
    local_ipv6: '本机公网 IPv6'
  })[String(type || '')] || String(type || '');
}

function formatSpeedValue(speed) {
  var n = Number(speed || 0);
  if (!isFinite(n) || n <= 0) return '';
  if (n >= 1024 * 1024) return (n / 1024 / 1024).toFixed(2).replace(/\.00$/, '') + ' GB/s';
  if (n >= 1024) return (n / 1024).toFixed(2).replace(/\.00$/, '') + ' MB/s';
  return n.toFixed(2).replace(/\.00$/, '') + ' KB/s';
}

function setSourceTestResultState(lines, tone) {
  var resultBox = document.getElementById('fm-test-result');
  if (!resultBox) return;
  resultBox.textContent = Array.isArray(lines) ? lines.join('\n') : String(lines || '');
  var map = {
    loading: { color: 'var(--tx2)', border: 'var(--bd)', bg: 'var(--bg)' },
    success: { color: 'var(--green)', border: 'rgba(80,200,120,.35)', bg: 'rgba(80,200,120,.08)' },
    warning: { color: 'var(--yellow)', border: 'rgba(255,193,7,.35)', bg: 'rgba(255,193,7,.08)' },
    error: { color: 'var(--red)', border: 'rgba(255,92,92,.35)', bg: 'rgba(255,92,92,.08)' }
  };
  var style = map[tone] || map.loading;
  resultBox.style.color = style.color;
  resultBox.style.borderColor = style.border;
  resultBox.style.background = style.bg;
}

function formatSourceTestResult(data) {
  if (!data || typeof data !== 'object') {
    return { lines: ['状态：成功', '说明：测试成功，但没有返回可展示的数据'], tone: 'success' };
  }
  var meta = (data.meta && typeof data.meta === 'object') ? data.meta : {};
  var rows = [];
  rows.push('状态：成功');
  if (data.message) rows.push('说明：' + data.message);
  if (data.value) rows.push('IP：' + data.value);
  if (meta.line && ['CT', 'CU', 'CM'].indexOf(meta.line) !== -1) {
    rows.push('线路：' + meta.line);
  }
  if (typeof meta.latency !== 'undefined' && meta.latency !== '') {
    rows.push('延迟：' + meta.latency + ' ms');
  }
  if (typeof meta.loss_rate !== 'undefined' && meta.loss_rate !== '') {
    rows.push('丢包：' + meta.loss_rate + ' %');
  }
  var speedText = formatSpeedValue(meta.speed);
  if (speedText) {
    rows.push('速度：' + speedText);
  }
  if (typeof meta.score !== 'undefined' && meta.score !== '') {
    rows.push('评分：' + meta.score);
  }
  if (meta.fallback) {
    rows.push('回退：是');
    if (meta.fallback_type) rows.push('回退来源：' + formatSourceLabel(meta.fallback_type));
    if (meta.primary_error) rows.push('主源失败原因：' + meta.primary_error);
  }
  return { lines: rows, tone: meta.fallback ? 'warning' : 'success' };
}

async function testSource() {
  setSourceTestResultState(['测试中...'], 'loading');
  var res = await postAjax('test_source', {task: currentTaskPayload()});
  if (!res.ok) {
    setSourceTestResultState(['状态：失败', '原因：' + (res.msg || '测试失败')], 'error');
    return;
  }
  var formatted = formatSourceTestResult(res.data || {});
  setSourceTestResultState(formatted.lines, formatted.tone);
}

function upsertRow(row) {
  var found = false;
  DDNS_ROWS = DDNS_ROWS.map(function(item){
    if (item.id === row.id) { found = true; return row; }
    return item;
  });
  if (!found) DDNS_ROWS.push(row);
  renderRows();
}

async function saveTask(runAfterSave) {
  var res = await postAjax('save', {id: DDNS_EDIT_ID, task: currentTaskPayload()});
  if (!res.ok) {
    showToast(res.msg || '保存失败', 'error');
    return;
  }
  var task = res.data.task;
  DDNS_TASKS[task.id] = task;
  upsertRow(res.data.row);
  showToast(res.msg || '任务已保存', 'success');
  closeDdnsModal();
  if (runAfterSave) {
    await runTask(task.id, true);
  }
}

async function refreshRows() {
  var resp = await fetch('ddns_ajax.php?action=list', {headers: {'X-Requested-With': 'XMLHttpRequest'}});
  var res = await resp.json();
  if (!res.ok) {
    showToast(res.msg || '刷新失败', 'error');
    return;
  }
  DDNS_ROWS = res.data.rows || [];
  renderRows();
}

function scheduleRunPoll(id, remaining) {
  clearTimeout(DDNS_RUN_POLLERS[id]);
  if (remaining <= 0) return;
  DDNS_RUN_POLLERS[id] = setTimeout(async function() {
    await refreshRows();
    var row = DDNS_ROWS.find(function(item){ return item.id === id; });
    if (row && row.last_status === 'running') {
      scheduleRunPoll(id, remaining - 1);
    }
  }, 1000);
}

async function runTask(id, silent) {
  var row = DDNS_ROWS.find(function(item){ return item.id === id; });
  if (row) {
    row.last_status = 'running';
    renderRows();
  }
  var res = await postAjax('run', {id: id});
  if (res.data && res.data.row) {
    upsertRow(res.data.row);
  }
  if (res.ok && res.data && res.data.row && res.data.row.last_status === 'running') {
    scheduleRunPoll(id, 20);
  } else if (!res.ok) {
    await refreshRows();
  }
  if (!silent) {
    showToast(res.msg || (res.ok ? '已开始后台执行' : '执行失败'), res.ok ? 'success' : 'error');
  }
}

async function toggleTask(id) {
  var res = await postAjax('toggle', {id: id});
  if (!res.ok) {
    showToast(res.msg || '操作失败', 'error');
    return;
  }
  if (res.data && res.data.row) upsertRow(res.data.row);
  showToast(res.msg || '操作完成', 'success');
}

async function deleteTask(id, name) {
  NavConfirm.open({
    title: '删除任务',
    message: '确认删除任务「' + name + '」吗？',
    confirmText: '删除',
    cancelText: '取消',
    danger: true,
    onConfirm: async function() {
      var res = await postAjax('delete', {id: id});
      if (!res.ok) {
        showToast(res.msg || '删除失败', 'error');
        return;
      }
      delete DDNS_TASKS[id];
      DDNS_ROWS = DDNS_ROWS.filter(function(row){ return row.id !== id; });
      renderRows();
      showToast(res.msg || '任务已删除', 'success');
    }
  });
}

document.addEventListener('DOMContentLoaded', function(){
  renderRows();
  toggleSourceFields();
  DDNS_ROWS.forEach(function(row) {
    if (row.last_status === 'running') {
      scheduleRunPoll(row.id, 20);
    }
  });
  document.addEventListener('keydown', function(e){ if (e.key === 'Escape') { closeDdnsModal(); closeDdnsLogModal(); } });
});
</script>

<div class="card">
  <div class="card-title" style="color:#ff9f43">⚠ 危险操作</div>
  <div class="form-hint" style="margin-bottom:12px">
    下列操作会先自动创建备份，再执行清空。会删除 DDNS 任务定义、每个任务日志、全局 DDNS 日志，并移除自动生成的 DDNS 调度器。
  </div>
  <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
    <form method="POST" data-confirm-title="清空 DDNS 任务" data-confirm-message="确认清空全部 DDNS 任务？\n\n会删除 DDNS 任务定义、每个任务日志、全局 DDNS 日志，并移除自动生成的 DDNS 调度器。">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="clear_ddns_tasks">
      <button class="btn btn-danger" type="button" onclick="submitConfirmForm(this)">🗑 清空 DDNS 任务</button>
    </form>
  </div>
</div>

<?php require_once __DIR__ . '/shared/footer.php'; ?>
