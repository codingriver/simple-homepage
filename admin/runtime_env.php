<?php
declare(strict_types=1);

$page_title = '运行环境';
require_once __DIR__ . '/shared/header.php';
require_once __DIR__ . '/shared/runtime_env_lib.php';

$node = runtime_env_detect_node();
$CSRF = csrf_token();
?>

<div class="toolbar">
  <button type="button" class="btn btn-primary" onclick="detectNode()">检测环境</button>
  <button type="button" class="btn btn-secondary" onclick="testNode()">测试 Node/npm</button>
  <span style="color:var(--tm);font-size:12px">管理员可在容器内安装、切换和卸载运行环境；失败时会保留命令、退出码和日志。</span>
</div>

<div class="card" style="margin-bottom:16px">
  <h3 style="margin-top:0">Node.js</h3>
  <div id="node-status" class="runtime-status"></div>
</div>

<div class="grid-2" style="align-items:start">
  <div class="card">
    <h3 style="margin-top:0">安装 / 更新</h3>
    <div class="form-group">
      <label>npm registry</label>
      <input type="text" id="node-registry" value="<?= htmlspecialchars((string)($node['registry'] ?? '')) ?>" placeholder="https://registry.npmmirror.com">
    </div>
    <div class="form-group">
      <label>musl 下载源</label>
      <input type="text" id="node-download-base" value="<?= htmlspecialchars((string)($node['download_base'] ?? '')) ?>" placeholder="https://unofficial-builds.nodejs.org/download/release">
      <span class="form-hint">Alpine/musl 容器使用 unofficial-builds 的 linux-*-musl 包；glibc 版 Linux 包不适合直接用于 Alpine。</span>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;margin:10px 0 18px">
      <button type="button" class="btn btn-secondary" onclick="saveNodeConfig()">保存配置</button>
      <button type="button" class="btn btn-secondary" onclick="loadRemoteVersions()">加载可安装版本</button>
      <button type="button" class="btn btn-primary" onclick="installApk()">apk 安装系统版本</button>
    </div>

    <div class="form-group">
      <label>指定版本</label>
      <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
        <input type="text" id="node-version" list="node-version-list" placeholder="例：22.20.0" style="font-family:var(--mono);flex:1;min-width:180px">
        <datalist id="node-version-list"></datalist>
        <button type="button" class="btn btn-primary" onclick="installVersion()">安装并切换</button>
      </div>
      <span class="form-hint">会安装到 <code>data/runtime/node/versions/&lt;version&gt;</code>，并将 <code>current</code> 指向该版本。</span>
    </div>
  </div>

  <div class="card">
    <h3 style="margin-top:0">已安装版本</h3>
    <div id="node-versions"></div>
  </div>
</div>

<div class="card" id="runtime-job-card" style="margin-top:16px;display:none">
  <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:10px;flex-wrap:wrap">
    <div>
      <h3 id="runtime-job-title" style="margin:0">安装进度</h3>
      <div id="runtime-job-message" style="font-size:12px;color:var(--tm);margin-top:5px"></div>
    </div>
    <div id="runtime-job-percent" style="font-family:var(--mono);font-size:18px;font-weight:700;color:var(--ac)">0%</div>
  </div>
  <div style="height:10px;border-radius:999px;background:rgba(255,255,255,.06);overflow:hidden;border:1px solid var(--bd)">
    <div id="runtime-job-bar" style="height:100%;width:0%;background:linear-gradient(90deg,var(--ac),#64ffd9);transition:width .25s ease"></div>
  </div>
  <div id="runtime-job-meta" style="font-size:12px;color:var(--tx2);font-family:var(--mono);margin-top:8px"></div>
</div>

<div class="card" style="margin-top:16px">
  <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:8px">
    <h3 style="margin:0">安装日志</h3>
    <button type="button" class="btn btn-sm btn-secondary" onclick="refreshLog()">刷新日志</button>
  </div>
  <pre id="runtime-log" style="min-height:220px;max-height:420px;overflow:auto;background:rgba(0,0,0,.22);border:1px solid var(--bd);border-radius:10px;padding:12px;font-family:var(--mono);font-size:12px;white-space:pre-wrap"></pre>
</div>

<style>
.runtime-status{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
.runtime-kv{border:1px solid var(--bd);border-radius:10px;padding:10px;background:rgba(255,255,255,.025)}
.runtime-kv span{display:block;font-size:11px;color:var(--tm);margin-bottom:5px}
.runtime-kv code{font-family:var(--mono);font-size:12px;word-break:break-all}
.runtime-version-row{display:flex;align-items:center;justify-content:space-between;gap:10px;border:1px solid var(--bd);border-radius:10px;padding:10px;margin-bottom:8px;background:rgba(255,255,255,.025)}
.runtime-version-actions{display:flex;gap:6px;flex-wrap:wrap;justify-content:flex-end}
@media(max-width:760px){.runtime-status{grid-template-columns:1fr}.runtime-version-row{align-items:flex-start;flex-direction:column}.runtime-version-actions{justify-content:flex-start}}
</style>

<script>
var CSRF_TOKEN = <?= json_encode($CSRF, JSON_UNESCAPED_SLASHES) ?>;
var NODE_STATE = <?= json_encode($node, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS) ?>;
var CURRENT_JOB_ID = '';
var JOB_POLL_TIMER = 0;

function runtimeEscape(value) {
  return String(value == null ? '' : value)
    .replace(/&/g, '&amp;').replace(/</g, '&lt;')
    .replace(/>/g, '&gt;').replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

function formatBytes(bytes) {
  bytes = Number(bytes || 0);
  if (bytes < 1024) return bytes + ' B';
  if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
  return (bytes / 1024 / 1024).toFixed(1) + ' MB';
}

function renderNode(data) {
  NODE_STATE = data || {};
  document.getElementById('node-status').innerHTML = [
    ['平台', NODE_STATE.platform || '-'],
    ['当前版本', NODE_STATE.current_version ? 'v' + NODE_STATE.current_version : '未选择'],
    ['node', (NODE_STATE.node_version || '未安装') + (NODE_STATE.node_bin ? ' · ' + NODE_STATE.node_bin : '')],
    ['npm', (NODE_STATE.npm_version || '未安装') + (NODE_STATE.npm_bin ? ' · ' + NODE_STATE.npm_bin : '')],
    ['系统 node', NODE_STATE.system_node || '未发现'],
    ['系统 npm', NODE_STATE.system_npm || '未发现']
  ].map(function(row) {
    return '<div class="runtime-kv"><span>' + runtimeEscape(row[0]) + '</span><code>' + runtimeEscape(row[1]) + '</code></div>';
  }).join('');

  var versions = NODE_STATE.versions || [];
  document.getElementById('node-versions').innerHTML = versions.length ? versions.map(function(v) {
    var current = NODE_STATE.current_version === v.version;
    return '<div class="runtime-version-row">'
      + '<div><div style="font-weight:700;font-family:var(--mono)">v' + runtimeEscape(v.version) + (current ? ' <span class="badge badge-green">当前</span>' : '') + '</div>'
      + '<div style="font-size:12px;color:var(--tm);margin-top:4px">' + runtimeEscape(v.path || '') + ' · ' + formatBytes(v.size) + '</div></div>'
      + '<div class="runtime-version-actions">'
      + '<button type="button" class="btn btn-sm btn-secondary" onclick="switchVersion(\'' + runtimeEscape(v.version) + '\')">切换</button>'
      + '<button type="button" class="btn btn-sm btn-danger" onclick="uninstallVersion(\'' + runtimeEscape(v.version) + '\')">卸载</button>'
      + '</div></div>';
  }).join('') : '<p style="color:var(--tm);font-size:13px">暂无通过后台安装的 Node.js 版本。</p>';

  document.getElementById('runtime-log').textContent = NODE_STATE.log || '';
}

function ajax(action, data, method) {
  data = data || {};
  method = method || 'POST';
  data.action = action;
  var opts = { method: method, headers: { 'X-Requested-With': 'XMLHttpRequest' } };
  var url = 'runtime_env_ajax.php';
  if (method === 'GET') {
    url += '?' + new URLSearchParams(data).toString();
  } else {
    data._csrf = CSRF_TOKEN;
    opts.headers['Content-Type'] = 'application/json';
    opts.body = JSON.stringify(data);
  }
  return fetch(url, opts).then(function(r) {
    return r.json().then(function(json) {
      if (!r.ok || !json.ok) {
        var msg = json && json.msg ? json.msg : '请求失败';
        if (json && json.data) {
          if (json.data.stderr) msg += '：' + json.data.stderr;
          else if (json.data.suggestion) msg += '：' + json.data.suggestion;
        }
        throw new Error(msg);
      }
      return json;
    });
  });
}

function detectNode() {
  ajax('detect', {}, 'GET').then(function(res) {
    renderNode(res.data);
    showToast('检测完成', 'success');
  }).catch(function(err) { showToast(err.message, 'error'); });
}

function saveNodeConfig() {
  ajax('save_config', { config: {
    registry: document.getElementById('node-registry').value,
    download_base: document.getElementById('node-download-base').value
  }}).then(function(res) {
    renderNode(res.data);
    showToast(res.msg || '配置已保存', 'success');
  }).catch(function(err) { showToast(err.message, 'error'); });
}

function loadRemoteVersions() {
  ajax('versions', {}, 'GET').then(function(res) {
    var list = document.getElementById('node-version-list');
    list.innerHTML = (res.data.versions || []).map(function(v) {
      return '<option value="' + runtimeEscape(v.version) + '">v' + runtimeEscape(v.version) + (v.lts ? ' LTS' : '') + '</option>';
    }).join('');
    showToast('已加载 ' + (res.data.versions || []).length + ' 个可安装版本', 'success');
  }).catch(function(err) { showToast(err.message, 'error'); });
}

function installApk() {
  NavConfirm.open({
    title: 'apk 安装 Node.js/npm',
    message: '将执行 apk add --no-cache nodejs npm。安装过程可能需要下载依赖，是否继续？',
    confirmText: '开始安装',
    danger: false,
    onConfirm: function() {
      startInstallJob('install_apk', {}, '已提交 apk 安装任务');
    }
  });
}

function installVersion() {
  var version = (document.getElementById('node-version').value || '').trim();
  if (!version) {
    showToast('请填写 Node.js 版本号', 'error');
    return;
  }
  startInstallJob('install_version', { version: version }, '已提交 Node.js ' + version + ' 安装任务');
}

function switchVersion(version) {
  ajax('switch_version', { version: version }).then(function(res) {
    renderNode(res.data);
    showToast(res.msg || '已切换', 'success');
  }).catch(function(err) { showToast(err.message, 'error'); });
}

function uninstallVersion(version) {
  NavConfirm.open({
    title: '卸载 Node.js',
    message: '确定卸载 Node.js v' + version + '？该版本目录会被删除。',
    confirmText: '卸载',
    danger: true,
    onConfirm: function() {
      ajax('uninstall_version', { version: version }).then(function(res) {
        renderNode(res.data);
        showToast(res.msg || '已卸载', 'success');
      }).catch(function(err) { showToast(err.message, 'error'); });
    }
  });
}

function testNode() {
  ajax('test').then(function(res) {
    showToast(res.msg || '测试成功', 'success');
  }).catch(function(err) { showToast(err.message, 'error'); });
}

function refreshLog() {
  ajax('tail_log', {}, 'GET').then(function(res) {
    document.getElementById('runtime-log').textContent = (res.data && res.data.log) || '';
  }).catch(function(err) { showToast(err.message, 'error'); });
}

function setInstallButtonsDisabled(disabled) {
  Array.from(document.querySelectorAll('button')).forEach(function(btn) {
    if (btn.textContent.indexOf('安装') !== -1 || btn.textContent.indexOf('切换') !== -1 || btn.textContent.indexOf('卸载') !== -1) {
      btn.disabled = !!disabled;
      btn.style.opacity = disabled ? '.6' : '';
      btn.style.cursor = disabled ? 'not-allowed' : '';
    }
  });
}

function renderJob(job) {
  if (!job) return;
  var card = document.getElementById('runtime-job-card');
  var title = document.getElementById('runtime-job-title');
  var msg = document.getElementById('runtime-job-message');
  var percentEl = document.getElementById('runtime-job-percent');
  var bar = document.getElementById('runtime-job-bar');
  var meta = document.getElementById('runtime-job-meta');
  var percent = Math.max(0, Math.min(100, Number(job.percent || 0)));
  card.style.display = '';
  title.textContent = (job.phase || '安装进度') + ' · ' + (job.status || 'running');
  msg.textContent = job.message || '正在处理...';
  percentEl.textContent = Math.round(percent) + '%';
  bar.style.width = percent + '%';
  bar.style.background = job.status === 'failed'
    ? 'linear-gradient(90deg,var(--red),#ff8a96)'
    : 'linear-gradient(90deg,var(--ac),#64ffd9)';
  var metaParts = [];
  if (job.id) metaParts.push('job=' + job.id);
  if (job.pid) metaParts.push('pid=' + job.pid);
  if (job.download_total) {
    metaParts.push('download=' + formatBytes(job.downloaded || 0) + '/' + formatBytes(job.download_total || 0));
  } else if (job.downloaded) {
    metaParts.push('download=' + formatBytes(job.downloaded));
  }
  if (job.exit_code !== undefined && job.exit_code !== null) metaParts.push('exit=' + job.exit_code);
  meta.textContent = metaParts.join(' · ');
  if (job.log !== undefined) {
    var logEl = document.getElementById('runtime-log');
    logEl.textContent = job.log || '';
    logEl.scrollTop = logEl.scrollHeight;
  }
}

function pollJob(jobId) {
  if (!jobId) return;
  CURRENT_JOB_ID = jobId;
  if (JOB_POLL_TIMER) {
    clearTimeout(JOB_POLL_TIMER);
    JOB_POLL_TIMER = 0;
  }
  ajax('job_status', { job_id: jobId }, 'GET').then(function(res) {
    var job = res.data && res.data.job ? res.data.job : {};
    renderJob(job);
    if (job.status === 'success') {
      setInstallButtonsDisabled(false);
      if (job.node) renderNode(job.node);
      showToast(job.message || '安装完成', 'success');
      return;
    }
    if (job.status === 'failed') {
      setInstallButtonsDisabled(false);
      var msg = job.message || '安装失败';
      if (job.stderr) msg += '：' + job.stderr;
      if (job.suggestion) msg += '；' + job.suggestion;
      showToast(msg, 'error');
      return;
    }
    JOB_POLL_TIMER = window.setTimeout(function() { pollJob(jobId); }, 1000);
  }).catch(function(err) {
    setInstallButtonsDisabled(false);
    showToast(err.message, 'error');
  });
}

function startInstallJob(action, data, startMessage) {
  showToast(startMessage || '安装任务已提交', 'info');
  setInstallButtonsDisabled(true);
  ajax(action, data || {}).then(function(res) {
    var jobId = res.data && res.data.job_id ? res.data.job_id : '';
    if (!jobId) {
      setInstallButtonsDisabled(false);
      showToast('安装任务启动失败：未返回 job_id', 'error');
      return;
    }
    renderJob({ id: jobId, status: 'running', phase: '启动中', percent: 2, message: res.msg || '后台安装任务已启动', log: '' });
    pollJob(jobId);
  }).catch(function(err) {
    setInstallButtonsDisabled(false);
    refreshLog();
    showToast(err.message, 'error');
  });
}

renderNode(NODE_STATE);
</script>

<?php require_once __DIR__ . '/shared/footer.php'; ?>
