<?php
declare(strict_types=1);

require_once __DIR__ . '/shared/domain_expiry_lib.php';

$page_title = '域名有效期';
require_once __DIR__ . '/shared/header.php';

$rows = domain_expiry_rows();
$summary = domain_expiry_summary();
$csrf = $GLOBALS['_riverops_csrf_token'] ?? csrf_token();
?>

<div class="stat-grid">
  <div class="stat-card"><div class="stat-val" id="sum-total"><?= (int)$summary['total'] ?></div><div class="stat-label">监控域名</div></div>
  <div class="stat-card"><div class="stat-val" id="sum-critical"><?= (int)$summary['expired'] + (int)$summary['critical'] ?></div><div class="stat-label">紧急/过期</div></div>
  <div class="stat-card"><div class="stat-val" id="sum-warning"><?= (int)$summary['warning'] ?></div><div class="stat-label">30 天内</div></div>
  <div class="stat-card"><div class="stat-val" id="sum-error"><?= (int)$summary['error'] + (int)$summary['unknown'] + (int)($summary['unsupported'] ?? 0) ?></div><div class="stat-label">失败/未知</div></div>
</div>

<div class="card">
  <div class="card-title">域名来源</div>
  <div class="toolbar" style="margin-bottom:0">
    <input type="text" id="manual-domain" placeholder="example.com" style="max-width:260px">
    <button type="button" class="btn btn-primary" onclick="addManualDomain()">添加域名</button>
    <button type="button" class="btn btn-secondary" onclick="refreshDue(false)">刷新到期数据</button>
    <button type="button" class="btn btn-secondary" onclick="refreshDue(true)">强制刷新全部</button>
    <button type="button" class="btn btn-secondary" onclick="loadRows()">刷新列表</button>
    <button type="button" class="btn btn-secondary" id="ignored-toggle" onclick="toggleIgnoredRows()">显示已忽略</button>
    <button type="button" class="btn btn-secondary" onclick="openPlatformConfigModal()">官方平台秘钥</button>
  </div>
  <p style="color:var(--tm);font-size:12px;line-height:1.8;margin-top:12px;margin-bottom:0">
    系统会自动收集 DNS Zone、DDNS 目标域名推断出的根域名，也可以在这里手动添加。页面读取本地缓存；点击刷新时才访问 RDAP。
  </p>
</div>

<div class="card">
  <div class="card-title">有效期列表</div>
  <div id="expiry-status" style="display:none;margin-bottom:12px;padding:10px 12px;border:1px solid var(--bd);border-radius:8px;background:var(--sf2);color:var(--tx2);font-size:12px"></div>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>域名</th>
          <th>有效期</th>
          <th>剩余</th>
          <th>状态</th>
          <th>来源</th>
          <th>注册商</th>
          <th>最近检查</th>
          <th style="min-width:260px">操作</th>
        </tr>
      </thead>
      <tbody id="expiry-tbody"></tbody>
    </table>
  </div>
</div>

<style>
.domain-platform-modal{display:none;position:fixed;inset:0;z-index:960;background:rgba(0,0,0,.72);backdrop-filter:blur(6px);align-items:center;justify-content:center;padding:18px}
.domain-platform-modal.open{display:flex}
.domain-platform-card{width:min(980px,96vw);max-height:92vh;overflow:hidden;background:var(--sf);border:1px solid var(--bd2);border-radius:var(--r2);box-shadow:0 30px 80px rgba(0,0,0,.5);display:flex;flex-direction:column}
.domain-platform-head{padding:16px 20px;border-bottom:1px solid var(--bd);display:flex;align-items:center;justify-content:space-between;gap:12px;background:var(--sf)}
.domain-platform-title{font-family:var(--mono);font-size:13px;color:var(--ac);font-weight:700;letter-spacing:.05em}
.domain-platform-close{background:none;border:none;color:var(--tm);font-size:20px;cursor:pointer;line-height:1;padding:2px 6px;border-radius:4px}
.domain-platform-close:hover{color:var(--tx)}
.domain-platform-body{padding:18px 20px;overflow:auto}
.domain-platform-hint{color:var(--tm);font-size:12px;line-height:1.8;margin-bottom:14px}
.platform-config-table{display:grid;gap:8px}
.platform-config-head,.platform-config-row{display:grid;grid-template-columns:190px minmax(0,1fr) 148px;gap:10px;align-items:start}
.platform-config-head{color:var(--tm);font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;font-family:var(--mono);padding:0 2px}
.platform-config-row{padding:12px;background:var(--sf2);border:1px solid var(--bd);border-radius:var(--r)}
.platform-secret-fields{display:grid;gap:8px}
.platform-secret-field{display:grid;grid-template-columns:120px minmax(0,1fr);gap:8px;align-items:center}
.platform-secret-field label{padding-top:0}
.platform-row-actions{display:flex;gap:6px;align-items:center;justify-content:flex-end;flex-wrap:wrap}
.platform-test-msg{grid-column:1 / -1;color:var(--tm);font-size:11px;line-height:1.6;font-family:var(--mono)}
.platform-test-msg.ok{color:var(--green)}
.platform-test-msg.err{color:var(--red)}
.domain-platform-actions{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-top:16px;padding-top:14px;border-top:1px solid var(--bd)}
@media (max-width: 760px){
  .platform-config-head{display:none}
  .platform-config-row{grid-template-columns:1fr}
  .platform-secret-field{grid-template-columns:1fr}
  .platform-row-actions{justify-content:flex-start}
  .domain-platform-actions{align-items:stretch;flex-direction:column}
}
</style>

<div id="platform-config-modal" class="domain-platform-modal" aria-hidden="true">
  <div class="domain-platform-card">
    <div class="domain-platform-head">
      <span class="domain-platform-title">官方平台秘钥配置</span>
      <button class="domain-platform-close" type="button" onclick="closePlatformConfigModal()" aria-label="关闭">×</button>
    </div>
    <div class="domain-platform-body">
      <div class="domain-platform-hint">
        适用于 qzz.io / cc.cd 等公共子级命名空间。刷新有效期时会优先通过官方平台 API 获取当前子域名的真实到期时间，不会查询父域名。
      </div>
      <div class="platform-config-table">
        <div class="platform-config-head">
          <div>官方站点</div>
          <div>秘钥配置</div>
          <div style="text-align:right">操作</div>
        </div>
        <div id="platform-config-rows"></div>
      </div>
      <div class="domain-platform-actions">
        <button type="button" class="btn btn-secondary" onclick="addPlatformConfigRow()">添加一行</button>
        <div style="display:flex;gap:8px;justify-content:flex-end">
          <button type="button" class="btn btn-secondary" onclick="closePlatformConfigModal()">取消</button>
          <button type="button" class="btn btn-primary" onclick="savePlatformConfigs()">保存</button>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
var DOMAIN_EXPIRY_ROWS = <?= json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS) ?>;
var DOMAIN_EXPIRY_CSRF = <?= json_encode($csrf) ?>;
var DOMAIN_EXPIRY_SHOW_IGNORED = false;
var DOMAIN_PLATFORM_PROVIDERS = [];
var DOMAIN_PLATFORM_CONFIGS = [];

function escapeHtml(str) {
  return String(str || '').replace(/[&<>"']/g, function (s) {
    return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[s];
  });
}

function setStatus(text, tone) {
  var box = document.getElementById('expiry-status');
  if (!box) return;
  if (!text) {
    box.style.display = 'none';
    box.textContent = '';
    return;
  }
  box.style.display = '';
  box.textContent = text;
  box.style.color = tone === 'error' ? 'var(--red)' : (tone === 'success' ? 'var(--green)' : 'var(--tx2)');
}

function updateSummary(summary) {
  if (!summary) return;
  document.getElementById('sum-total').textContent = summary.total || 0;
  document.getElementById('sum-critical').textContent = (summary.expired || 0) + (summary.critical || 0);
  document.getElementById('sum-warning').textContent = summary.warning || 0;
  document.getElementById('sum-error').textContent = (summary.error || 0) + (summary.unknown || 0) + (summary.unsupported || 0);
}

function renderRows() {
  var tbody = document.getElementById('expiry-tbody');
  tbody.innerHTML = '';
  if (!DOMAIN_EXPIRY_ROWS.length) {
    var empty = document.createElement('tr');
    empty.innerHTML = '<td colspan="8" style="color:var(--tm);padding:18px 12px">暂无域名。配置 DNS Zone、DDNS 任务，或手动添加域名后即可监控。</td>';
    tbody.appendChild(empty);
    return;
  }
  DOMAIN_EXPIRY_ROWS.forEach(function(row) {
    var domainArg = JSON.stringify(row.domain).replace(/"/g, '&quot;');
    var days = row.days_left === null || typeof row.days_left === 'undefined'
      ? '—'
      : (Number(row.days_left) < 0 ? '已过期 ' + Math.abs(Number(row.days_left)) + ' 天' : Number(row.days_left) + ' 天');
    var detail = row.error ? '<div style="margin-top:4px;color:var(--red);font-size:11px;max-width:280px;white-space:normal">' + escapeHtml(row.error) + '</div>' : '';
    var rdapDetail = row.rdap_domain && row.rdap_domain !== row.domain
      ? '<div style="margin-top:4px;color:var(--tm);font-size:11px;max-width:280px;white-space:normal">实际查询：' + escapeHtml(row.rdap_domain) + '</div>'
      : '';
    var tr = document.createElement('tr');
    var ignoreAction = row.ignored
      ? '<button type="button" class="btn btn-sm btn-secondary" onclick="unignoreDomain(' + domainArg + ')">取消忽略</button> '
      : '<button type="button" class="btn btn-sm btn-secondary" onclick="ignoreDomain(' + domainArg + ')">忽略</button> ';
    var ignoredBadge = row.ignored ? ' <span class="badge badge-gray">已忽略</span>' : '';
    tr.innerHTML = ''
      + '<td style="font-family:var(--mono);font-weight:700">' + escapeHtml(row.domain) + ignoredBadge + '</td>'
      + '<td style="font-family:var(--mono);white-space:nowrap">' + escapeHtml(row.expires_at || '—') + '</td>'
      + '<td style="font-family:var(--mono);white-space:nowrap">' + escapeHtml(days) + '</td>'
      + '<td><span class="badge ' + escapeHtml(row.badge || 'badge-gray') + '">' + escapeHtml(row.status_label || '未知') + '</span>' + rdapDetail + detail + '</td>'
      + '<td>' + escapeHtml(row.source || '缓存') + '</td>'
      + '<td>' + escapeHtml(row.registrar || '—') + '</td>'
      + '<td style="font-family:var(--mono);white-space:nowrap">' + escapeHtml(row.checked_at || '—') + '</td>'
      + '<td style="white-space:nowrap">'
      + '<button type="button" class="btn btn-sm btn-secondary" onclick="refreshOne(' + domainArg + ', true)">刷新</button> '
      + ignoreAction
      + '<button type="button" class="btn btn-sm btn-danger" onclick="deleteManualDomain(' + domainArg + ')">删除手动</button>'
      + '</td>';
    tbody.appendChild(tr);
  });
}

async function postAjax(action, payload) {
  payload = payload || {};
  payload.action = action;
  payload._csrf = DOMAIN_EXPIRY_CSRF;
  var resp = await fetch('domain_expiry_ajax.php', {
    method: 'POST',
    headers: {'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
    body: JSON.stringify(payload)
  });
  return await resp.json();
}

function providerByKey(provider) {
  for (var i = 0; i < DOMAIN_PLATFORM_PROVIDERS.length; i++) {
    if (DOMAIN_PLATFORM_PROVIDERS[i].provider === provider) return DOMAIN_PLATFORM_PROVIDERS[i];
  }
  return null;
}

function platformFieldLabel(field) {
  if (field === 'token') return 'Bearer Token';
  if (field === 'api_key') return 'API Key';
  if (field === 'api_secret') return 'API Secret';
  return field;
}

function platformFieldType(field) {
  return 'text';
}

function platformProviderOptions(selected) {
  return DOMAIN_PLATFORM_PROVIDERS.map(function(provider) {
    return '<option value="' + escapeHtml(provider.provider) + '"' + (provider.provider === selected ? ' selected' : '') + '>' + escapeHtml(provider.label + ' / ' + provider.site) + '</option>';
  }).join('');
}

function normalizePlatformRowsForEmptyState(rows) {
  if (rows.length) return rows;
  return [
    {provider: 'digitalplat', enabled: true},
    {provider: 'dnshe', enabled: true}
  ];
}

function renderPlatformConfigRows() {
  var box = document.getElementById('platform-config-rows');
  box.innerHTML = '';
  normalizePlatformRowsForEmptyState(DOMAIN_PLATFORM_CONFIGS).forEach(function(config, idx) {
    var provider = providerByKey(config.provider) || DOMAIN_PLATFORM_PROVIDERS[0];
    if (!provider) return;
    var row = document.createElement('div');
    row.className = 'platform-config-row';
    row.dataset.index = String(idx);
    row.innerHTML = ''
      + '<div>'
      + '  <select class="platform-provider" onchange="onPlatformProviderChange(this)">'
      +      platformProviderOptions(provider.provider)
      + '  </select>'
      + '  <div class="form-hint" style="margin-top:6px">' + escapeHtml(provider.hint || '') + '</div>'
      + '</div>'
      + '<div class="platform-secret-fields"></div>'
      + '<div class="platform-row-actions">'
      + '  <button type="button" class="btn btn-sm btn-secondary" onclick="testPlatformConfig(this)">测试</button>'
      + '  <button type="button" class="btn btn-sm btn-danger" onclick="removePlatformConfigRow(this)">删除</button>'
      + '</div>'
      + '<div class="platform-test-msg"></div>';
    box.appendChild(row);
    renderPlatformSecretFields(row, config);
  });
}

function renderPlatformSecretFields(row, config) {
  var providerKey = row.querySelector('.platform-provider').value;
  var provider = providerByKey(providerKey);
  var fieldsBox = row.querySelector('.platform-secret-fields');
  fieldsBox.innerHTML = '';
  if (!provider) return;
  provider.fields.forEach(function(field) {
    var value = config && config[field] ? config[field] : '';
    var placeholder = '请输入 ' + platformFieldLabel(field);
    var wrap = document.createElement('div');
    wrap.className = 'platform-secret-field';
    wrap.innerHTML = ''
      + '<label>' + escapeHtml(platformFieldLabel(field)) + '</label>'
      + '<input type="' + platformFieldType(field) + '" data-field="' + escapeHtml(field) + '" autocomplete="off" spellcheck="false" value="' + escapeHtml(value) + '" placeholder="' + escapeHtml(placeholder) + '">';
    fieldsBox.appendChild(wrap);
  });
}

function onPlatformProviderChange(select) {
  var row = select.closest('.platform-config-row');
  var msg = row.querySelector('.platform-test-msg');
  if (msg) {
    msg.className = 'platform-test-msg';
    msg.textContent = '';
  }
  renderPlatformSecretFields(row, {});
}

function collectPlatformConfigFromRow(row) {
  var provider = row.querySelector('.platform-provider').value;
  var config = {provider: provider, enabled: true};
  row.querySelectorAll('input[data-field]').forEach(function(input) {
    config[input.dataset.field] = input.value.trim();
  });
  return config;
}

function collectPlatformConfigs() {
  var seen = {};
  var configs = [];
  document.querySelectorAll('#platform-config-rows .platform-config-row').forEach(function(row) {
    var config = collectPlatformConfigFromRow(row);
    if (!config.provider || seen[config.provider]) return;
    seen[config.provider] = true;
    configs.push(config);
  });
  return configs;
}

async function openPlatformConfigModal() {
  var modal = document.getElementById('platform-config-modal');
  modal.classList.add('open');
  modal.setAttribute('aria-hidden', 'false');
  var res = await fetch('domain_expiry_ajax.php?action=platform_configs', {headers: {'X-Requested-With': 'XMLHttpRequest'}});
  var json = await res.json();
  if (!json.ok) {
    showToast(json.msg || '加载官方平台配置失败', 'error');
    return;
  }
  DOMAIN_PLATFORM_PROVIDERS = (json.data && json.data.providers) || [];
  DOMAIN_PLATFORM_CONFIGS = (json.data && json.data.configs) || [];
  renderPlatformConfigRows();
}

function closePlatformConfigModal() {
  var modal = document.getElementById('platform-config-modal');
  modal.classList.remove('open');
  modal.setAttribute('aria-hidden', 'true');
}

function addPlatformConfigRow() {
  var provider = DOMAIN_PLATFORM_PROVIDERS[0] ? DOMAIN_PLATFORM_PROVIDERS[0].provider : 'digitalplat';
  DOMAIN_PLATFORM_CONFIGS = collectPlatformConfigs();
  DOMAIN_PLATFORM_CONFIGS.push({provider: provider, enabled: true});
  renderPlatformConfigRows();
}

function removePlatformConfigRow(btn) {
  var row = btn.closest('.platform-config-row');
  row.parentNode.removeChild(row);
}

async function savePlatformConfigs() {
  var res = await postAjax('platform_configs_save', {configs: collectPlatformConfigs()});
  if (!res.ok) {
    showToast(res.msg || '保存失败', 'error');
    return;
  }
  DOMAIN_PLATFORM_CONFIGS = (res.data && res.data.configs) || [];
  renderPlatformConfigRows();
  showToast(res.msg || '官方平台秘钥已保存', 'success');
}

async function testPlatformConfig(btn) {
  var row = btn.closest('.platform-config-row');
  var msg = row.querySelector('.platform-test-msg');
  msg.className = 'platform-test-msg';
  msg.textContent = '正在测试...';
  btn.disabled = true;
  var config = collectPlatformConfigFromRow(row);
  var res = await postAjax('platform_config_test', {provider: config.provider, config: config});
  btn.disabled = false;
  msg.className = 'platform-test-msg ' + (res.ok ? 'ok' : 'err');
  msg.textContent = res.msg || (res.ok ? '测试成功' : '测试失败');
}

async function loadRows() {
  setStatus('正在加载列表...');
  var url = 'domain_expiry_ajax.php?action=list' + (DOMAIN_EXPIRY_SHOW_IGNORED ? '&include_ignored=1' : '');
  var resp = await fetch(url, {headers: {'X-Requested-With': 'XMLHttpRequest'}});
  var res = await resp.json();
  if (!res.ok) {
    setStatus(res.msg || '加载失败', 'error');
    return;
  }
  DOMAIN_EXPIRY_ROWS = (res.data && res.data.rows) || [];
  updateSummary(res.data && res.data.summary);
  renderRows();
  setStatus('');
}

function toggleIgnoredRows() {
  DOMAIN_EXPIRY_SHOW_IGNORED = !DOMAIN_EXPIRY_SHOW_IGNORED;
  var btn = document.getElementById('ignored-toggle');
  if (btn) btn.textContent = DOMAIN_EXPIRY_SHOW_IGNORED ? '隐藏已忽略' : '显示已忽略';
  loadRows();
}

async function addManualDomain() {
  var input = document.getElementById('manual-domain');
  var domain = input.value.trim();
  if (!domain) {
    showToast('请填写域名', 'warning');
    return;
  }
  setStatus('正在添加域名...');
  var res = await postAjax('manual_add', {domain: domain});
  if (!res.ok) {
    setStatus(res.msg || '添加失败', 'error');
    showToast(res.msg || '添加失败', 'error');
    return;
  }
  input.value = '';
  DOMAIN_EXPIRY_ROWS = (res.data && res.data.rows) || [];
  updateSummary(res.data && res.data.summary);
  renderRows();
  setStatus(res.msg || '域名已添加', 'success');
}

async function refreshOne(domain, force) {
  setStatus('正在刷新 ' + domain + ' ...');
  var res = await postAjax('refresh_one', {domain: domain, force: !!force});
  if (!res.ok) {
    setStatus(res.msg || '刷新失败', 'error');
    await loadRows();
    return;
  }
  await loadRows();
  setStatus(domain + ' 已刷新', 'success');
}

async function refreshDue(force) {
  setStatus(force ? '正在强制刷新全部域名...' : '正在刷新需要更新的域名...');
  var res = await postAjax('refresh_due', {force: !!force, limit: 100});
  if (!res.ok) {
    setStatus(res.msg || '刷新失败', 'error');
    return;
  }
  DOMAIN_EXPIRY_ROWS = (res.data && res.data.rows) || [];
  updateSummary(res.data && res.data.summary);
  renderRows();
  setStatus(res.msg || '刷新完成', 'success');
}

async function ignoreDomain(domain) {
  RiverOpsConfirm.open({
    title: '忽略域名',
    message: '确认从有效期监控列表中忽略 ' + domain + '？',
    confirmText: '忽略',
    cancelText: '取消',
    danger: true,
    onConfirm: async function() {
      var res = await postAjax('ignore', {domain: domain});
      if (!res.ok) {
        showToast(res.msg || '操作失败', 'error');
        return;
      }
      await loadRows();
      showToast(res.msg || '已忽略', 'success');
    }
  });
}

async function unignoreDomain(domain) {
  var res = await postAjax('unignore', {domain: domain, include_ignored: DOMAIN_EXPIRY_SHOW_IGNORED});
  if (!res.ok) {
    showToast(res.msg || '操作失败', 'error');
    return;
  }
  DOMAIN_EXPIRY_ROWS = (res.data && res.data.rows) || [];
  updateSummary(res.data && res.data.summary);
  renderRows();
  showToast(res.msg || '已取消忽略', 'success');
}

async function deleteManualDomain(domain) {
  RiverOpsConfirm.open({
    title: '删除手动域名',
    message: '只会从手动列表删除 ' + domain + '。如果它来自 DNS Zone 或 DDNS，仍会自动出现在列表中。',
    confirmText: '删除',
    cancelText: '取消',
    danger: true,
    onConfirm: async function() {
      var res = await postAjax('manual_delete', {domain: domain});
      if (!res.ok) {
        showToast(res.msg || '删除失败', 'error');
        return;
      }
      DOMAIN_EXPIRY_ROWS = (res.data && res.data.rows) || [];
      updateSummary(res.data && res.data.summary);
      renderRows();
      showToast(res.msg || '操作完成', 'success');
    }
  });
}

document.addEventListener('DOMContentLoaded', renderRows);
document.addEventListener('DOMContentLoaded', function() {
  var modal = document.getElementById('platform-config-modal');
  var mouseDownTarget = null;
  modal.addEventListener('mousedown', function(e) { mouseDownTarget = e.target; });
  modal.addEventListener('click', function(e) {
    if (e.target === modal && mouseDownTarget === modal) closePlatformConfigModal();
  });
  document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && modal.classList.contains('open')) closePlatformConfigModal();
  });
});
</script>

<?php require_once __DIR__ . '/shared/footer.php'; ?>
