<?php
declare(strict_types=1);

$page_permission = 'ssh.view';
$page_title = 'Docker 管理';

require_once __DIR__ . '/shared/header.php';
require_once __DIR__ . '/shared/host_agent_lib.php';

$agent = host_agent_status_summary();
$canManage = auth_user_has_permission('ssh.manage', $current_admin) || auth_user_has_permission('ssh.service.manage', $current_admin);
$csrfValue = csrf_token();
?>

<div class="card" style="margin-bottom:16px">
  <div class="card-title">Docker 宿主管理</div>
  <div style="color:var(--tm);font-size:12px;line-height:1.9">
    这里单独管理宿主机 Docker：容器、镜像、卷和网络都统一从这个页面处理。当前实现基于 <code>host-agent</code> 代理宿主机 Docker API，不和 SSH、文件系统页面混在一起。
  </div>
</div>

<div class="card" style="margin-bottom:16px">
  <div class="card-title">Host-Agent 状态</div>
  <div class="alert <?= !empty($agent['healthy']) ? 'alert-success' : (!empty($agent['docker_socket_mounted']) ? 'alert-info' : 'alert-warn') ?>">
    <?= htmlspecialchars((string)($agent['message'] ?? '')) ?>
  </div>
  <div class="form-hint" style="margin-top:8px">
    当前模式：<?= htmlspecialchars((string)($agent['install_mode'] ?? '-')) ?>。
    <?php if (empty($agent['healthy'])): ?>
      请先到 <a href="settings.php#host-agent">系统设置 / Host-Agent</a> 完成安装和健康检查。
    <?php endif; ?>
  </div>
</div>

<?php if (!empty($agent['healthy'])): ?>
<div class="card" style="margin-bottom:16px">
  <div style="display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap">
    <div class="card-title" style="margin:0">Docker 概览</div>
    <button type="button" class="btn btn-secondary" onclick="dockerLoadAll()">刷新全部</button>
  </div>
  <div id="docker-request-status" style="display:none;margin-top:12px;padding:12px 14px;border:1px solid var(--bd);border-radius:10px;background:var(--bg)">
    <div style="display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap">
      <div>
        <div id="docker-request-title" style="font-weight:700">获取数据中</div>
        <div id="docker-request-text" style="font-size:12px;color:var(--tm);margin-top:4px">正在准备请求…</div>
      </div>
      <div id="docker-request-meta" style="font-family:var(--mono);font-size:12px;color:var(--tm)">0%</div>
    </div>
    <div style="margin-top:10px;height:8px;border-radius:999px;background:rgba(255,255,255,.06);overflow:hidden">
      <div id="docker-request-bar" style="height:100%;width:0%;background:linear-gradient(90deg,var(--ac),#64ffd9);transition:width .25s ease"></div>
    </div>
  </div>
  <div id="docker-summary" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-top:12px">
    <div style="color:var(--tm)">加载中…</div>
  </div>
</div>

<div class="card" style="margin-bottom:16px">
  <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px">
    <button type="button" class="btn btn-secondary docker-tab-btn active" data-tab="containers" onclick="dockerSwitchTab('containers')">容器</button>
    <button type="button" class="btn btn-secondary docker-tab-btn" data-tab="images" onclick="dockerSwitchTab('images')">镜像</button>
    <button type="button" class="btn btn-secondary docker-tab-btn" data-tab="volumes" onclick="dockerSwitchTab('volumes')">卷</button>
    <button type="button" class="btn btn-secondary docker-tab-btn" data-tab="networks" onclick="dockerSwitchTab('networks')">网络</button>
  </div>

  <section id="docker-tab-containers" class="docker-tab-panel">
    <div style="display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap;margin-bottom:12px">
      <div style="display:flex;gap:8px;flex-wrap:wrap">
        <input type="text" id="docker-container-keyword" placeholder="搜索容器名 / 镜像 / 状态" style="min-width:260px">
        <label style="display:flex;align-items:center;gap:6px;font-size:13px;color:var(--tm)">
          <input type="checkbox" id="docker-container-show-all" checked>
          显示已停止容器
        </label>
      </div>
      <button type="button" class="btn btn-secondary" onclick="dockerLoadContainers()">刷新容器</button>
    </div>
    <div class="table-wrap"><table>
      <thead><tr><th>名称</th><th>镜像</th><th>状态</th><th>端口</th><th>创建时间</th><th>操作</th></tr></thead>
      <tbody id="docker-containers-tbody"><tr><td colspan="6" style="color:var(--tm)">加载中…</td></tr></tbody>
    </table></div>
  </section>

  <section id="docker-tab-images" class="docker-tab-panel" style="display:none">
    <div style="display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap;margin-bottom:12px">
      <input type="text" id="docker-image-keyword" placeholder="搜索镜像标签 / ID" style="min-width:260px">
      <button type="button" class="btn btn-secondary" onclick="dockerLoadImages()">刷新镜像</button>
    </div>
    <div class="table-wrap"><table>
      <thead><tr><th>标签</th><th>ID</th><th>大小</th><th>创建时间</th></tr></thead>
      <tbody id="docker-images-tbody"><tr><td colspan="4" style="color:var(--tm)">加载中…</td></tr></tbody>
    </table></div>
  </section>

  <section id="docker-tab-volumes" class="docker-tab-panel" style="display:none">
    <div style="display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap;margin-bottom:12px">
      <input type="text" id="docker-volume-keyword" placeholder="搜索卷名 / 挂载点" style="min-width:260px">
      <button type="button" class="btn btn-secondary" onclick="dockerLoadVolumes()">刷新卷</button>
    </div>
    <div class="table-wrap"><table>
      <thead><tr><th>名称</th><th>驱动</th><th>挂载点</th><th>作用域</th></tr></thead>
      <tbody id="docker-volumes-tbody"><tr><td colspan="4" style="color:var(--tm)">加载中…</td></tr></tbody>
    </table></div>
  </section>

  <section id="docker-tab-networks" class="docker-tab-panel" style="display:none">
    <div style="display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap;margin-bottom:12px">
      <input type="text" id="docker-network-keyword" placeholder="搜索网络名 / 驱动" style="min-width:260px">
      <button type="button" class="btn btn-secondary" onclick="dockerLoadNetworks()">刷新网络</button>
    </div>
    <div class="table-wrap"><table>
      <thead><tr><th>名称</th><th>ID</th><th>驱动</th><th>作用域</th><th>容器数</th></tr></thead>
      <tbody id="docker-networks-tbody"><tr><td colspan="5" style="color:var(--tm)">加载中…</td></tr></tbody>
    </table></div>
  </section>
</div>

<div id="docker-modal" style="display:none;position:fixed;inset:0;z-index:980;background:rgba(0,0,0,.72);align-items:center;justify-content:center" onclick="if(event.target===this)dockerCloseModal()">
  <div style="background:var(--sf);border:1px solid var(--bd2);border-radius:var(--r2);width:min(1080px,96vw);max-height:90vh;display:flex;flex-direction:column">
    <div style="padding:14px 18px;border-bottom:1px solid var(--bd);display:flex;justify-content:space-between;align-items:center;gap:12px">
      <strong id="docker-modal-title">详情</strong>
      <button type="button" class="btn btn-secondary" onclick="dockerCloseModal()">关闭</button>
    </div>
    <div id="docker-modal-status" style="display:none;padding:12px 16px;border-bottom:1px solid var(--bd);background:rgba(255,255,255,.02)">
      <div style="display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap">
        <div>
          <div id="docker-modal-status-title" style="font-weight:700">获取数据中</div>
          <div id="docker-modal-status-text" style="font-size:12px;color:var(--tm);margin-top:4px">正在等待返回…</div>
        </div>
        <div id="docker-modal-status-meta" style="font-family:var(--mono);font-size:12px;color:var(--tm)">0%</div>
      </div>
      <div style="margin-top:10px;height:8px;border-radius:999px;background:rgba(255,255,255,.06);overflow:hidden">
        <div id="docker-modal-status-bar" style="height:100%;width:0%;background:linear-gradient(90deg,var(--ac),#64ffd9);transition:width .25s ease"></div>
      </div>
    </div>
    <pre id="docker-modal-body" style="margin:0;padding:16px;overflow:auto;min-height:300px;background:var(--bg);font-family:var(--mono);font-size:12px;line-height:1.55"></pre>
  </div>
</div>

<script>
var DOCKER_CSRF = <?= json_encode($csrfValue) ?>;
var DOCKER_CAN_MANAGE = <?= $canManage ? 'true' : 'false' ?>;
var DOCKER_STATE = { containers: [], images: [], volumes: [], networks: [] };
var DOCKER_STATUS = navCreateAsyncStatus({
  progressTexts: {
    connecting: '正在连接 host-agent…',
    loading: '正在获取数据…',
    processing: '数据较多，继续拉取中…'
  },
  keepErrorScopes: {
    modal: true
  },
  getRefs: function(scope) {
    return scope === 'modal'
      ? {
          wrap: document.getElementById('docker-modal-status'),
          title: document.getElementById('docker-modal-status-title'),
          text: document.getElementById('docker-modal-status-text'),
          meta: document.getElementById('docker-modal-status-meta'),
          bar: document.getElementById('docker-modal-status-bar')
        }
      : {
          wrap: document.getElementById('docker-request-status'),
          title: document.getElementById('docker-request-title'),
          text: document.getElementById('docker-request-text'),
          meta: document.getElementById('docker-request-meta'),
          bar: document.getElementById('docker-request-bar')
        };
  }
});

function dockerProgressState(scope) {
  return scope === 'modal'
    ? {
        wrap: document.getElementById('docker-modal-status'),
        title: document.getElementById('docker-modal-status-title'),
        text: document.getElementById('docker-modal-status-text'),
        meta: document.getElementById('docker-modal-status-meta'),
        bar: document.getElementById('docker-modal-status-bar')
      }
    : {
        wrap: document.getElementById('docker-request-status'),
        title: document.getElementById('docker-request-title'),
        text: document.getElementById('docker-request-text'),
        meta: document.getElementById('docker-request-meta'),
        bar: document.getElementById('docker-request-bar')
      };
}

function dockerSetProgress(scope, title, detail, percent) {
  DOCKER_STATUS.set(scope, title, detail, percent);
}

function dockerHideProgress(scope) {
  DOCKER_STATUS.hide(scope);
}

function dockerStartProgress(scope, title, detail) {
  return DOCKER_STATUS.start(scope, title, detail);
}

function dockerFinishProgress(id, ok, detail) {
  DOCKER_STATUS.finish(id, ok, detail);
}

function dockerApi(action, params, method) {
  method = method || 'GET';
  if (method === 'POST') {
    var body = new URLSearchParams();
    body.append('action', action);
    body.append('_csrf', DOCKER_CSRF);
    Object.keys(params || {}).forEach(function(key) {
      if (params[key] === undefined || params[key] === null) return;
      body.append(key, String(params[key]));
    });
    return fetch('docker_api.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
      body: body.toString()
    }).then(function(r) { return r.json(); });
  }
  var query = new URLSearchParams({ action: action });
  Object.keys(params || {}).forEach(function(key) {
    if (params[key] === undefined || params[key] === null) return;
    query.set(key, String(params[key]));
  });
  return fetch('docker_api.php?' + query.toString(), {
    credentials: 'same-origin',
    headers: { 'X-Requested-With': 'XMLHttpRequest' }
  }).then(function(r) { return r.json(); });
}

function dockerEscape(value) {
  return String(value || '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

function dockerFmtBytes(bytes) {
  var num = Number(bytes || 0);
  if (!num) return '0 B';
  var units = ['B', 'KB', 'MB', 'GB', 'TB'];
  var idx = 0;
  while (num >= 1024 && idx < units.length - 1) {
    num /= 1024;
    idx += 1;
  }
  return num.toFixed(num >= 10 || idx === 0 ? 0 : 1) + ' ' + units[idx];
}

function dockerFmtTime(ts) {
  var num = Number(ts || 0);
  if (!num) return '-';
  var d = new Date(num * 1000);
  return isNaN(d.getTime()) ? '-' : d.toLocaleString();
}

function dockerSummaryCard(title, value, extra) {
  return '<div style="border:1px solid var(--bd);border-radius:10px;padding:14px;background:var(--bg)">'
    + '<div style="font-size:11px;color:var(--tm);margin-bottom:6px">' + dockerEscape(title) + '</div>'
    + '<div style="font-weight:700;line-height:1.6">' + dockerEscape(value) + '</div>'
    + (extra ? '<div style="font-size:12px;color:var(--tm);margin-top:4px">' + dockerEscape(extra) + '</div>' : '')
    + '</div>';
}

function dockerActionArg(value) {
  return "decodeURIComponent('" + encodeURIComponent(String(value || '')).replace(/'/g, '%27') + "')";
}

async function dockerRunRequest(scope, title, detail, runner) {
  return DOCKER_STATUS.run(scope, title, detail, runner, { successText: '数据已加载完成' });
}

async function dockerLoadSummary() {
  var data = await dockerRunRequest('page', '获取 Docker 概览', '正在读取宿主机 Docker 基础信息…', function() {
    return dockerApi('summary');
  });
  var wrap = document.getElementById('docker-summary');
  if (!data.ok) {
    wrap.innerHTML = '<div style="color:var(--red)">' + dockerEscape(data.msg || 'Docker 概览读取失败') + '</div>';
    return;
  }
  var cards = [
    dockerSummaryCard('宿主机', data.name || '-', (data.os || '-') + ' / ' + (data.kernel || '-')),
    dockerSummaryCard('Docker 版本', data.server_version || '-', 'API ' + (data.api_version || '-')),
    dockerSummaryCard('容器', String(data.containers || 0), '运行 ' + String(data.containers_running || 0) + '，停止 ' + String(data.containers_stopped || 0)),
    dockerSummaryCard('镜像', String(data.images || 0), '驱动 ' + (data.driver || '-')),
    dockerSummaryCard('CPU / 内存', String(data.ncpu || 0) + ' 核', dockerFmtBytes(data.mem_total || 0)),
    dockerSummaryCard('架构', data.architecture || '-', '')
  ];
  wrap.innerHTML = cards.join('');
}

function dockerContainerActions(item) {
  var idArg = dockerActionArg(item.id);
  if (!DOCKER_CAN_MANAGE) return '<button type="button" class="btn btn-sm btn-secondary" onclick="dockerShowLogs(' + idArg + ')">日志</button>'
    + ' <button type="button" class="btn btn-sm btn-secondary" onclick="dockerShowInspect(' + idArg + ')">详情</button>'
    + ' <button type="button" class="btn btn-sm btn-secondary" onclick="dockerShowStats(' + idArg + ')">资源</button>';
  var state = String(item.state || '').toLowerCase();
  var buttons = [
    '<button type="button" class="btn btn-sm btn-secondary" onclick="dockerShowLogs(' + idArg + ')">日志</button>',
    '<button type="button" class="btn btn-sm btn-secondary" onclick="dockerShowInspect(' + idArg + ')">详情</button>',
    '<button type="button" class="btn btn-sm btn-secondary" onclick="dockerShowStats(' + idArg + ')">资源</button>'
  ];
  if (state === 'running') {
    buttons.push('<button type="button" class="btn btn-sm btn-secondary" onclick="dockerContainerAction(' + idArg + ', \'restart\')">重启</button>');
    buttons.push('<button type="button" class="btn btn-sm btn-danger" onclick="dockerContainerAction(' + idArg + ', \'stop\')">停止</button>');
  } else {
    buttons.push('<button type="button" class="btn btn-sm btn-primary" onclick="dockerContainerAction(' + idArg + ', \'start\')">启动</button>');
  }
  buttons.push('<button type="button" class="btn btn-sm btn-danger" onclick="dockerDeleteContainer(' + idArg + ')">删除</button>');
  return buttons.join(' ');
}

function dockerRenderContainers() {
  var keyword = (document.getElementById('docker-container-keyword').value || '').trim().toLowerCase();
  var tbody = document.getElementById('docker-containers-tbody');
  var items = DOCKER_STATE.containers.filter(function(item) {
    if (!keyword) return true;
    var text = [item.name, item.image, item.state, item.status, (item.ports || []).join(' ')].join(' ').toLowerCase();
    return text.indexOf(keyword) !== -1;
  });
  tbody.innerHTML = items.length ? items.map(function(item) {
    return '<tr data-container-id="' + dockerEscape(item.id) + '">'
      + '<td style="font-weight:700">' + dockerEscape(item.name || item.id.slice(0, 12)) + '</td>'
      + '<td style="font-family:var(--mono);max-width:280px;word-break:break-all">' + dockerEscape(item.image || '-') + '</td>'
      + '<td><span class="badge ' + ((item.state || '') === 'running' ? 'badge-green' : 'badge-gray') + '">' + dockerEscape(item.status || item.state || '-') + '</span></td>'
      + '<td style="font-family:var(--mono);font-size:12px">' + dockerEscape((item.ports || []).join(', ') || '-') + '</td>'
      + '<td>' + dockerEscape(dockerFmtTime(item.created)) + '</td>'
      + '<td>' + dockerContainerActions(item) + '</td>'
      + '</tr>';
  }).join('') : '<tr><td colspan="6" style="color:var(--tm)">暂无容器数据</td></tr>';
}

async function dockerLoadContainers() {
  var data = await dockerRunRequest('page', '获取容器列表', '容器较多时会需要更久，请稍候…', function() {
    return dockerApi('containers', { all: document.getElementById('docker-container-show-all').checked ? '1' : '0' });
  }).catch(function(err) {
    return { ok: false, msg: err && err.message ? err.message : '容器列表读取失败' };
  });
  var tbody = document.getElementById('docker-containers-tbody');
  if (!data.ok) {
    tbody.innerHTML = '<tr><td colspan="6" style="color:var(--red)">' + dockerEscape(data.msg || '容器列表读取失败') + '</td></tr>';
    return;
  }
  DOCKER_STATE.containers = (data.items || []).slice().sort(function(a, b) { return Number(b.created || 0) - Number(a.created || 0); });
  dockerRenderContainers();
}

function dockerRenderImages() {
  var keyword = (document.getElementById('docker-image-keyword').value || '').trim().toLowerCase();
  var tbody = document.getElementById('docker-images-tbody');
  var items = DOCKER_STATE.images.filter(function(item) {
    if (!keyword) return true;
    return [(item.tags || []).join(' '), item.id].join(' ').toLowerCase().indexOf(keyword) !== -1;
  });
  tbody.innerHTML = items.length ? items.map(function(item) {
    return '<tr>'
      + '<td style="font-family:var(--mono);max-width:360px;word-break:break-all">' + dockerEscape((item.tags || []).join(', ') || '<none>') + '</td>'
      + '<td style="font-family:var(--mono)">' + dockerEscape((item.id || '').slice(0, 24)) + '</td>'
      + '<td>' + dockerEscape(dockerFmtBytes(item.size || 0)) + '</td>'
      + '<td>' + dockerEscape(dockerFmtTime(item.created)) + '</td>'
      + '</tr>';
  }).join('') : '<tr><td colspan="4" style="color:var(--tm)">暂无镜像数据</td></tr>';
}

async function dockerLoadImages() {
  var data = await dockerRunRequest('page', '获取镜像列表', '正在读取镜像数据，镜像很多时会更慢…', function() {
    return dockerApi('images');
  }).catch(function(err) {
    return { ok: false, msg: err && err.message ? err.message : '镜像列表读取失败' };
  });
  var tbody = document.getElementById('docker-images-tbody');
  if (!data.ok) {
    tbody.innerHTML = '<tr><td colspan="4" style="color:var(--red)">' + dockerEscape(data.msg || '镜像列表读取失败') + '</td></tr>';
    return;
  }
  DOCKER_STATE.images = (data.items || []).slice().sort(function(a, b) { return Number(b.created || 0) - Number(a.created || 0); });
  dockerRenderImages();
}

function dockerRenderVolumes() {
  var keyword = (document.getElementById('docker-volume-keyword').value || '').trim().toLowerCase();
  var tbody = document.getElementById('docker-volumes-tbody');
  var items = DOCKER_STATE.volumes.filter(function(item) {
    if (!keyword) return true;
    return [item.name, item.mountpoint, item.driver].join(' ').toLowerCase().indexOf(keyword) !== -1;
  });
  tbody.innerHTML = items.length ? items.map(function(item) {
    return '<tr>'
      + '<td style="font-family:var(--mono)">' + dockerEscape(item.name || '-') + '</td>'
      + '<td>' + dockerEscape(item.driver || '-') + '</td>'
      + '<td style="font-family:var(--mono);max-width:360px;word-break:break-all">' + dockerEscape(item.mountpoint || '-') + '</td>'
      + '<td>' + dockerEscape(item.scope || '-') + '</td>'
      + '</tr>';
  }).join('') : '<tr><td colspan="4" style="color:var(--tm)">暂无卷数据</td></tr>';
}

async function dockerLoadVolumes() {
  var data = await dockerRunRequest('page', '获取卷列表', '正在读取卷信息…', function() {
    return dockerApi('volumes');
  }).catch(function(err) {
    return { ok: false, msg: err && err.message ? err.message : '卷列表读取失败' };
  });
  var tbody = document.getElementById('docker-volumes-tbody');
  if (!data.ok) {
    tbody.innerHTML = '<tr><td colspan="4" style="color:var(--red)">' + dockerEscape(data.msg || '卷列表读取失败') + '</td></tr>';
    return;
  }
  DOCKER_STATE.volumes = data.items || [];
  dockerRenderVolumes();
}

function dockerRenderNetworks() {
  var keyword = (document.getElementById('docker-network-keyword').value || '').trim().toLowerCase();
  var tbody = document.getElementById('docker-networks-tbody');
  var items = DOCKER_STATE.networks.filter(function(item) {
    if (!keyword) return true;
    return [item.name, item.id, item.driver, item.scope].join(' ').toLowerCase().indexOf(keyword) !== -1;
  });
  tbody.innerHTML = items.length ? items.map(function(item) {
    return '<tr>'
      + '<td style="font-family:var(--mono)">' + dockerEscape(item.name || '-') + '</td>'
      + '<td style="font-family:var(--mono)">' + dockerEscape((item.id || '').slice(0, 24)) + '</td>'
      + '<td>' + dockerEscape(item.driver || '-') + '</td>'
      + '<td>' + dockerEscape(item.scope || '-') + '</td>'
      + '<td>' + dockerEscape(String(item.containers_count || 0)) + '</td>'
      + '</tr>';
  }).join('') : '<tr><td colspan="5" style="color:var(--tm)">暂无网络数据</td></tr>';
}

async function dockerLoadNetworks() {
  var data = await dockerRunRequest('page', '获取网络列表', '正在读取 Docker 网络信息…', function() {
    return dockerApi('networks');
  }).catch(function(err) {
    return { ok: false, msg: err && err.message ? err.message : '网络列表读取失败' };
  });
  var tbody = document.getElementById('docker-networks-tbody');
  if (!data.ok) {
    tbody.innerHTML = '<tr><td colspan="5" style="color:var(--red)">' + dockerEscape(data.msg || '网络列表读取失败') + '</td></tr>';
    return;
  }
  DOCKER_STATE.networks = data.items || [];
  dockerRenderNetworks();
}

async function dockerShowLogs(id) {
  dockerOpenModal('容器日志', '');
  var data = await dockerRunRequest('modal', '获取容器日志', '正在读取容器标准输出与错误输出…', function() {
    return dockerApi('container_logs', { id: id, tail: 300 });
  }).catch(function(err) {
    return { ok: false, msg: err && err.message ? err.message : '容器日志读取失败' };
  });
  if (!data.ok) {
    document.getElementById('docker-modal-body').textContent = data.msg || '容器日志读取失败';
    return;
  }
  dockerOpenModal('容器日志', (data.lines || []).join('\n') || '(empty)');
}

async function dockerShowInspect(id) {
  dockerOpenModal('容器详情', '');
  var data = await dockerRunRequest('modal', '获取容器详情', '正在读取 inspect 结果…', function() {
    return dockerApi('container_inspect', { id: id });
  }).catch(function(err) {
    return { ok: false, msg: err && err.message ? err.message : '容器详情读取失败' };
  });
  if (!data.ok) {
    document.getElementById('docker-modal-body').textContent = data.msg || '容器详情读取失败';
    return;
  }
  dockerOpenModal('容器详情', JSON.stringify(data.item || {}, null, 2));
}

async function dockerShowStats(id) {
  dockerOpenModal('容器资源', '');
  var data = await dockerRunRequest('modal', '获取容器资源', '正在计算 CPU / 内存占用…', function() {
    return dockerApi('container_stats', { id: id });
  }).catch(function(err) {
    return { ok: false, msg: err && err.message ? err.message : '容器资源读取失败' };
  });
  if (!data.ok) {
    document.getElementById('docker-modal-body').textContent = data.msg || '容器资源读取失败';
    return;
  }
  dockerOpenModal('容器资源', JSON.stringify(data.item || {}, null, 2));
}

function dockerOpenModal(title, body) {
  document.getElementById('docker-modal-title').textContent = title;
  document.getElementById('docker-modal-body').textContent = String(body || '');
  document.getElementById('docker-modal').style.display = 'flex';
}

function dockerCloseModal() {
  document.getElementById('docker-modal').style.display = 'none';
  dockerHideProgress('modal');
}

async function dockerContainerAction(id, action) {
  if (!DOCKER_CAN_MANAGE) return;
  var data = await dockerRunRequest('page', '执行容器操作', '正在对容器执行 ' + action + ' …', function() {
    return dockerApi('container_action', { id: id, container_action: action }, 'POST');
  }).catch(function(err) {
    return { ok: false, msg: err && err.message ? err.message : '容器操作失败' };
  });
  if (!data.ok) {
    showToast(data.msg || '容器操作失败', 'error');
    return;
  }
  showToast(data.msg || '操作已执行', 'success');
  await dockerLoadSummary();
  await dockerLoadContainers();
}

async function dockerDeleteContainer(id) {
  if (!DOCKER_CAN_MANAGE) return;
  if (!window.confirm('确认删除该容器？如容器仍在运行，将强制删除。')) return;
  var data = await dockerRunRequest('page', '删除容器', '正在删除容器并等待 Docker 返回结果…', function() {
    return dockerApi('container_delete', { id: id, force: '1' }, 'POST');
  }).catch(function(err) {
    return { ok: false, msg: err && err.message ? err.message : '容器删除失败' };
  });
  if (!data.ok) {
    showToast(data.msg || '容器删除失败', 'error');
    return;
  }
  showToast(data.msg || '容器已删除', 'success');
  await dockerLoadSummary();
  await dockerLoadContainers();
}

function dockerSwitchTab(tab) {
  document.querySelectorAll('.docker-tab-btn').forEach(function(btn) {
    btn.classList.toggle('active', btn.getAttribute('data-tab') === tab);
  });
  document.querySelectorAll('.docker-tab-panel').forEach(function(panel) {
    panel.style.display = panel.id === 'docker-tab-' + tab ? '' : 'none';
  });
}

async function dockerLoadAll() {
  await Promise.all([dockerLoadSummary(), dockerLoadContainers(), dockerLoadImages(), dockerLoadVolumes(), dockerLoadNetworks()]);
}

document.getElementById('docker-container-keyword').addEventListener('input', dockerRenderContainers);
document.getElementById('docker-container-show-all').addEventListener('change', dockerLoadContainers);
document.getElementById('docker-image-keyword').addEventListener('input', dockerRenderImages);
document.getElementById('docker-volume-keyword').addEventListener('input', dockerRenderVolumes);
document.getElementById('docker-network-keyword').addEventListener('input', dockerRenderNetworks);

dockerLoadAll();
</script>
<?php endif; ?>
