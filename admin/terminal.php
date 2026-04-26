<?php
declare(strict_types=1);

$page_permission = 'ssh.terminal';
require_once __DIR__ . '/shared/header.php';
require_once __DIR__ . '/shared/host_agent_lib.php';
require_once __DIR__ . '/shared/ssh_manager_lib.php';

$agent = host_agent_status_summary();
$remoteHosts = ssh_manager_list_hosts();
$csrfValue = csrf_token();
$globalCfg = auth_get_config();
$terminalPersistDefault = ($globalCfg['ssh_terminal_persist'] ?? '1') === '1';
$terminalIdleMinutesDefault = max(5, min(10080, (int)($globalCfg['ssh_terminal_idle_minutes'] ?? 120)));
?>

<link rel="stylesheet" href="assets/xterm/xterm.css">

<div class="card" style="margin-bottom:0;display:flex;flex-direction:column;height:calc(100vh - 100px)">
  <div class="card-title" style="font-size:10px;margin-bottom:8px">Web 终端</div>
  <?php if (empty($agent['healthy'])): ?>
    <div class="alert alert-warn">
      当前还不能使用终端功能。请先前往 <a href="settings.php#host-agent">系统设置 / Host-Agent</a> 完成安装和健康检查。
    </div>
  <?php else: ?>
    <div style="display:flex;gap:6px;flex-wrap:nowrap;align-items:center;margin-bottom:10px;overflow-x:auto">
      <select id="terminal-host-select" style="min-width:140px;flex-shrink:0">
        <option value="local">本机</option>
        <?php foreach ($remoteHosts as $host): ?>
        <option value="<?= htmlspecialchars((string)$host['id']) ?>"><?= htmlspecialchars((string)($host['name'] ?? '')) ?></option>
        <?php endforeach; ?>
      </select>
      <label style="display:flex;align-items:center;gap:4px;font-size:12px;color:var(--tx2);flex-shrink:0;white-space:nowrap">
        <input type="checkbox" id="terminal-persist" <?= $terminalPersistDefault ? 'checked' : '' ?>>
        后台继续运行
      </label>
      <input type="number" id="terminal-idle-minutes" min="5" max="10080" value="<?= $terminalIdleMinutesDefault ?>" style="width:72px;flex-shrink:0" title="空闲保留分钟">
      <button type="button" class="btn btn-primary" onclick="openTerminal()" style="flex-shrink:0">打开终端</button>
      <button type="button" class="btn btn-secondary" onclick="refreshTerminalSessions(true)" style="flex-shrink:0">恢复会话</button>
      <button type="button" class="btn btn-secondary" onclick="detachTerminal()" style="flex-shrink:0">脱离终端</button>
      <button type="button" class="btn btn-secondary" onclick="closeTerminal()" style="flex-shrink:0">关闭终端</button>
      <button type="button" class="btn btn-secondary" onclick="syncCurrentTerminalSize()" style="flex-shrink:0">同步尺寸</button>
    </div>
    <div id="host-terminal-status" style="display:none;margin-bottom:10px;padding:10px 12px;border:1px solid var(--bd);border-radius:10px;background:var(--bg)"></div>
    <div style="display:flex;flex-direction:column;flex:1;min-height:0">
      <div id="terminal-tabs" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:8px"></div>
      <div id="terminal-panes" style="flex:1;min-height:0;background:#0b1220;border:1px solid rgba(255,255,255,.08);border-radius:10px;position:relative;overflow:hidden"></div>
      <div style="display:flex;gap:10px;margin-top:10px;flex-shrink:0">
        <button type="button" class="btn btn-secondary" onclick="sendTerminalRaw('\u0003')">Ctrl+C</button>
        <button type="button" class="btn btn-secondary" onclick="sendTerminalRaw('\t')">Tab</button>
        <button type="button" class="btn btn-secondary" onclick="sendTerminalRaw('\u001b[A')">↑</button>
        <button type="button" class="btn btn-secondary" onclick="sendTerminalRaw('\u001b[B')">↓</button>
      </div>
    </div>
  <?php endif; ?>
</div>

<script src="assets/xterm/xterm.js"></script>
<script src="assets/xterm/xterm-addon-fit.js"></script>
<script>
var HOST_CSRF = <?= json_encode($csrfValue) ?>;
var TERMINAL_SESSION_ID = '';
var TERMINAL_SESSIONS = {};
var TERMINAL_PAGE_ACTIVE = true;
var TERMINAL_PERSIST_DEFAULT = <?= $terminalPersistDefault ? 'true' : 'false' ?>;
var TERMINAL_IDLE_MINUTES_DEFAULT = <?= (int)$terminalIdleMinutesDefault ?>;
var HOST_STATUS = navCreateAsyncStatus({
  progressTexts: {
    connecting: '正在连接 host-agent…',
    loading: '正在获取数据…',
    processing: '数据较多，继续处理中…'
  },
  getRefs: function(scope) {
    return {
      wrap: document.getElementById(scope + '-status')
    };
  }
});
var TERMINAL_TABS_SIGNATURE = '';

function hostProgressRefs(scope) {
  return {
    wrap: document.getElementById(scope + '-status')
  };
}

function hostSetProgress(scope, title, detail, percent, tone) {
  HOST_STATUS.set(scope, title, detail, percent, tone);
}

function hostHideProgress(scope) {
  HOST_STATUS.hide(scope);
}

function hostStartProgress(scope, title, detail) {
  return HOST_STATUS.start(scope, title, detail);
}

function hostFinishProgress(id, ok, detail) {
  HOST_STATUS.finish(id, ok, detail);
}

async function hostRunRequest(scope, title, detail, runner) {
  return HOST_STATUS.run(scope, title, detail, runner);
}

function buildHostApiBody(action, params) {
  var body = new URLSearchParams();
  body.append('action', action);
  body.append('_csrf', HOST_CSRF);
  Object.keys(params || {}).forEach(function(key) {
    var value = params[key];
    if (value === undefined || value === null) return;
    if (Array.isArray(value)) {
      value.forEach(function(item) {
        body.append(key + '[]', item);
      });
      return;
    }
    body.append(key, value);
  });
  return body;
}

async function postHostApi(action, params) {
  var body = buildHostApiBody(action, params || {});
  var res = await fetch('host_api.php', {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' },
    body: body
  });
  return res.json();
}

async function getHostApi(action, params) {
  var query = new URLSearchParams(Object.assign({ action: action }, params || {}));
  var res = await fetch('host_api.php?' + query.toString(), {
    credentials: 'same-origin',
    headers: { 'X-Requested-With': 'XMLHttpRequest' }
  });
  return res.json();
}

function createTerminalInstance(sessionId, title) {
  var container = document.getElementById('terminal-panes');
  var pane = document.createElement('div');
  pane.className = 'terminal-pane';
  pane.id = 'terminal-pane-' + sessionId;
  pane.style.cssText = 'position:absolute;inset:0;padding:4px';
  container.appendChild(pane);

  var term = new Terminal({
    fontSize: 13,
    fontFamily: 'var(--mono), "Courier New", monospace, Menlo, Monaco',
    theme: { background: '#0b1220', foreground: '#d8f5d0', cursor: '#d8f5d0', selectionBackground: 'rgba(96,165,250,.35)' },
    cursorBlink: true,
    scrollback: 5000,
    allowTransparency: false,
    cursorStyle: 'block'
  });

  var fitAddon = new FitAddon.FitAddon();
  term.loadAddon(fitAddon);
  term.open(pane);
  try { fitAddon.fit(); } catch(e) {}

  term.onData(function(data) {
    var session = TERMINAL_SESSIONS[sessionId];
    if (!session || !session.running) return;
    enqueueTerminalWrite(sessionId, data);
  });

  // 初始隐藏（如果不是当前 active session）
  if (TERMINAL_SESSION_ID !== sessionId) {
    pane.style.visibility = 'hidden';
  }

  return { term: term, pane: pane, fitAddon: fitAddon };
}

function switchTerminalPane(sessionId) {
  Object.keys(TERMINAL_SESSIONS).forEach(function(id) {
    var s = TERMINAL_SESSIONS[id];
    if (s && s.pane) {
      s.pane.style.visibility = (id === sessionId) ? 'visible' : 'hidden';
    }
  });
  // 焦点交给当前终端
  var active = TERMINAL_SESSIONS[sessionId];
  if (active && active.term) {
    setTimeout(function() { active.term.focus(); }, 50);
  }
}

async function openTerminal() {
  if (!TERMINAL_PAGE_ACTIVE) return;
  const hostId = document.getElementById('terminal-host-select').value;
  const persist = !!((document.getElementById('terminal-persist') || {}).checked);
  const idleInput = document.getElementById('terminal-idle-minutes');
  const idleMinutes = Math.max(5, parseInt(idleInput && idleInput.value ? idleInput.value : String(TERMINAL_IDLE_MINUTES_DEFAULT), 10) || TERMINAL_IDLE_MINUTES_DEFAULT);
  const data = await hostRunRequest('host-terminal', '打开终端', '正在创建新的 shell 会话…', function() {
    return postHostApi('terminal_open', { host_id: hostId, persist: persist ? '1' : '0', idle_minutes: String(idleMinutes) });
  });
  if (!data.ok) {
    showToast(data.msg || '终端打开失败', 'error');
    return;
  }
  var id = data.id || '';
  var title = data.title || (hostId === 'local' ? '本机' : (document.querySelector('#terminal-host-select option:checked') || {}).textContent || hostId);

  var instance = createTerminalInstance(id, title);

  TERMINAL_SESSIONS[id] = {
    id: id,
    hostId: data.host_id || hostId,
    title: title,
    running: !!data.running,
    persist: !!data.persist,
    idleMinutes: Number(data.idle_minutes || idleMinutes),
    updatedAt: data.updated_at || '',
    createdAt: data.created_at || '',
    ready: false,
    initPromise: null,
    timer: null,
    controller: null,
    readPromise: null,
    writePromise: null,
    term: instance.term,
    pane: instance.pane
  };

  TERMINAL_SESSION_ID = id;
  renderTerminalTabs();
  switchTerminalPane(id);

  TERMINAL_SESSIONS[id].initPromise = (async function() {
    await pollTerminal(id);
    await syncCurrentTerminalSize();
    if (TERMINAL_SESSIONS[id]) {
      TERMINAL_SESSIONS[id].ready = true;
      TERMINAL_SESSIONS[id].initPromise = null;
    }
  })();
  await TERMINAL_SESSIONS[id].initPromise;
  showToast('终端已打开', 'success');
}

function renderTerminalTabs() {
  var container = document.getElementById('terminal-tabs');
  if (!container) return;
  var signature = Object.keys(TERMINAL_SESSIONS).sort().map(function(id) {
    var session = TERMINAL_SESSIONS[id];
    return [id, session.title, session.running ? '1' : '0', session.persist ? '1' : '0', TERMINAL_SESSION_ID === id ? '1' : '0'].join(':');
  }).join('|');
  if (signature === TERMINAL_TABS_SIGNATURE) {
    return;
  }
  TERMINAL_TABS_SIGNATURE = signature;
  container.innerHTML = '';
  Object.keys(TERMINAL_SESSIONS).forEach(function(id) {
    var session = TERMINAL_SESSIONS[id];
    var button = document.createElement('button');
    button.type = 'button';
    button.className = 'btn btn-sm ' + (TERMINAL_SESSION_ID === id ? 'btn-primary' : 'btn-secondary');
    button.textContent = session.title + (session.running ? '' : ' (已结束)') + (session.persist ? ' [后台]' : '');
    button.setAttribute('data-session-id', id);
    button.addEventListener('click', function() {
      TERMINAL_SESSION_ID = id;
      var persistInput = document.getElementById('terminal-persist');
      var idleInput = document.getElementById('terminal-idle-minutes');
      if (persistInput) persistInput.checked = !!session.persist;
      if (idleInput) idleInput.value = String(session.idleMinutes || TERMINAL_IDLE_MINUTES_DEFAULT);
      TERMINAL_TABS_SIGNATURE = '';
      renderTerminalTabs();
      switchTerminalPane(id);
    });
    container.appendChild(button);
  });
}

async function refreshTerminalSessions(showNotice) {
  var data = showNotice
    ? await hostRunRequest('host-terminal', '恢复终端会话', '正在读取可恢复的后台会话…', function() {
        return getHostApi('terminal_list');
      })
    : await getHostApi('terminal_list');
  if (!data.ok) {
    if (showNotice) showToast(data.msg || '终端会话列表读取失败', 'error');
    return;
  }

  var nextSessions = {};
  (data.sessions || []).forEach(function(session) {
    var existing = TERMINAL_SESSIONS[session.id] || {};
    var instance = existing.term ? existing : createTerminalInstance(session.id, session.title || session.host_label || session.host_id || session.id);

    nextSessions[session.id] = {
      id: session.id,
      hostId: session.host_id || 'local',
      title: session.title || session.host_label || session.host_id || session.id,
      running: !!session.running,
      persist: !!session.persist,
      idleMinutes: Number(session.idle_minutes || TERMINAL_IDLE_MINUTES_DEFAULT),
      updatedAt: session.updated_at || '',
      createdAt: session.created_at || '',
      ready: existing.ready !== undefined ? !!existing.ready : true,
      initPromise: existing.initPromise || null,
      timer: existing.timer || null,
      controller: existing.controller || null,
      readPromise: existing.readPromise || null,
      writePromise: existing.writePromise || null,
      term: instance.term,
      pane: instance.pane
    };
  });

  // 清理已不存在的会话
  Object.keys(TERMINAL_SESSIONS).forEach(function(id) {
    if (!nextSessions[id]) {
      var s = TERMINAL_SESSIONS[id];
      if (s && s.timer) clearTimeout(s.timer);
      if (s && s.controller) s.controller.abort();
      if (s && s.term) s.term.dispose();
      if (s && s.pane) s.pane.remove();
    }
  });

  TERMINAL_SESSIONS = nextSessions;
  if ((!TERMINAL_SESSION_ID || !TERMINAL_SESSIONS[TERMINAL_SESSION_ID]) && Object.keys(TERMINAL_SESSIONS).length) {
    TERMINAL_SESSION_ID = Object.keys(TERMINAL_SESSIONS)[0];
  }

  TERMINAL_TABS_SIGNATURE = '';
  renderTerminalTabs();
  switchTerminalPane(TERMINAL_SESSION_ID);

  var pollPromises = [];
  Object.keys(TERMINAL_SESSIONS).forEach(function(id) {
    if (TERMINAL_SESSIONS[id].running && !TERMINAL_SESSIONS[id].timer) {
      pollPromises.push(pollTerminal(id));
    }
  });
  if (showNotice && pollPromises.length) {
    await Promise.allSettled(pollPromises);
  }
  if (showNotice) {
    showToast((data.sessions || []).length ? '终端会话已恢复' : '当前没有可恢复的终端会话', 'info');
  }
}

async function pollTerminal(sessionId) {
  if (!TERMINAL_PAGE_ACTIVE || !sessionId || !TERMINAL_SESSIONS[sessionId]) return;
  let data = null;
  var session = TERMINAL_SESSIONS[sessionId];
  if (!session) return;
  if (session.controller) {
    session.controller.abort();
  }
  var controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
  session.controller = controller;
  var readPromise = (async function() {
    const res = await fetch('host_api.php?action=terminal_read&id=' + encodeURIComponent(sessionId), {
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      signal: controller ? controller.signal : undefined
    });
    return res.json();
  })();
  session.readPromise = readPromise;
  try {
    data = await readPromise;
  } catch (err) {
    if (!TERMINAL_PAGE_ACTIVE) return;
    if (err && (err.name === 'AbortError' || /aborted/i.test(String(err.message || '')))) {
      return;
    }
    data = { ok: false, running: false, msg: err && err.message ? err.message : '终端轮询失败' };
  }
  if (!TERMINAL_PAGE_ACTIVE || !TERMINAL_SESSIONS[sessionId]) return;
  if (TERMINAL_SESSIONS[sessionId].readPromise === readPromise) {
    TERMINAL_SESSIONS[sessionId].readPromise = null;
  }
  TERMINAL_SESSIONS[sessionId].controller = null;

  if (data.ok && data.output) {
    var s = TERMINAL_SESSIONS[sessionId];
    if (s && s.term) {
      s.term.write(data.output);
    }
  }

  TERMINAL_SESSIONS[sessionId].running = !!(data.ok && data.running);
  TERMINAL_SESSIONS[sessionId].persist = data.persist !== undefined ? !!data.persist : TERMINAL_SESSIONS[sessionId].persist;
  TERMINAL_SESSIONS[sessionId].idleMinutes = Number(data.idle_minutes || TERMINAL_SESSIONS[sessionId].idleMinutes || TERMINAL_IDLE_MINUTES_DEFAULT);
  TERMINAL_SESSIONS[sessionId].updatedAt = data.updated_at || TERMINAL_SESSIONS[sessionId].updatedAt || '';
  renderTerminalTabs();

  if (data.ok && data.running) {
    if (TERMINAL_SESSIONS[sessionId].timer) {
      clearTimeout(TERMINAL_SESSIONS[sessionId].timer);
    }
    if (TERMINAL_PAGE_ACTIVE) {
      TERMINAL_SESSIONS[sessionId].timer = setTimeout(function() { pollTerminal(sessionId); }, 1000);
    }
  } else {
    if (TERMINAL_SESSIONS[sessionId].timer) {
      clearTimeout(TERMINAL_SESSIONS[sessionId].timer);
      TERMINAL_SESSIONS[sessionId].timer = null;
    }
  }
}

function teardownTerminalPolling(abortRequests) {
  TERMINAL_PAGE_ACTIVE = false;
  Object.keys(TERMINAL_SESSIONS).forEach(function(id) {
    var session = TERMINAL_SESSIONS[id];
    if (!session) return;
    if (session.timer) {
      clearTimeout(session.timer);
      session.timer = null;
    }
    if (abortRequests && session.controller) {
      session.controller.abort();
      session.controller = null;
    }
  });
}

async function waitForTerminalReads(timeoutMs) {
  var promises = Object.keys(TERMINAL_SESSIONS).map(function(id) {
    return TERMINAL_SESSIONS[id] && TERMINAL_SESSIONS[id].readPromise ? TERMINAL_SESSIONS[id].readPromise : null;
  }).filter(Boolean);
  if (!promises.length) return;
  await Promise.race([
    Promise.allSettled(promises),
    new Promise(function(resolve) {
      setTimeout(resolve, Math.max(100, timeoutMs || 1200));
    })
  ]);
}

async function navigateAwayFromTerminal(url) {
  teardownTerminalPolling(false);
  await waitForTerminalReads(1500);
  window.location.href = url;
}

async function waitForTerminalReady(sessionId, timeoutMs) {
  var deadline = Date.now() + Math.max(1000, timeoutMs || 5000);
  while (Date.now() < deadline) {
    var session = TERMINAL_SESSIONS[sessionId];
    if (!session) return false;
    if (session.ready) return true;
    if (session.initPromise) {
      await Promise.race([
        session.initPromise.catch(function() { return null; }),
        new Promise(function(resolve) { setTimeout(resolve, 250); })
      ]);
      continue;
    }
    if (session.readPromise) {
      await Promise.race([
        session.readPromise.catch(function() { return null; }),
        new Promise(function(resolve) { setTimeout(resolve, 200); })
      ]);
    } else {
      await pollTerminal(sessionId);
    }
    await new Promise(function(resolve) { setTimeout(resolve, 120); });
  }
  return !!(TERMINAL_SESSIONS[sessionId] && TERMINAL_SESSIONS[sessionId].ready);
}

function enqueueTerminalWrite(sessionId, data) {
  var session = TERMINAL_SESSIONS[sessionId];
  if (!session) {
    return Promise.resolve({ ok: false, msg: '终端会话不存在' });
  }
  var previous = session.writePromise || Promise.resolve();
  var next = previous
    .catch(function() { return null; })
    .then(function() {
      if (!TERMINAL_SESSIONS[sessionId]) {
        return { ok: false, msg: '终端会话不存在' };
      }
      return postHostApi('terminal_write', { id: sessionId, data: data });
    });
  session.writePromise = next;
  return next.finally(function() {
    if (TERMINAL_SESSIONS[sessionId] && TERMINAL_SESSIONS[sessionId].writePromise === next) {
      TERMINAL_SESSIONS[sessionId].writePromise = null;
    }
  });
}

async function sendTerminalRaw(data) {
  if (!TERMINAL_SESSION_ID || !TERMINAL_SESSIONS[TERMINAL_SESSION_ID]) {
    showToast('请先打开终端', 'warning');
    return;
  }
  await enqueueTerminalWrite(TERMINAL_SESSION_ID, data);
}

async function syncCurrentTerminalSize() {
  var session = TERMINAL_SESSIONS[TERMINAL_SESSION_ID];
  if (!session || !session.term) return;
  if (session.fitAddon) {
    try { session.fitAddon.fit(); } catch(e) {}
  }
  var cols = session.term.cols || 80;
  var rows = session.term.rows || 24;
  await sendTerminalRaw('stty cols ' + cols + ' rows ' + rows + '\n');
}

async function closeTerminal() {
  if (!TERMINAL_SESSION_ID) return;
  var id = TERMINAL_SESSION_ID;
  var session = TERMINAL_SESSIONS[id];
  if (session && session.timer) {
    clearTimeout(session.timer);
  }
  await postHostApi('terminal_close', { id: id });
  if (session && session.term) {
    session.term.dispose();
  }
  if (session && session.pane) {
    session.pane.remove();
  }
  delete TERMINAL_SESSIONS[id];
  TERMINAL_SESSION_ID = Object.keys(TERMINAL_SESSIONS)[0] || '';
  renderTerminalTabs();
  switchTerminalPane(TERMINAL_SESSION_ID);
  showToast('终端已关闭', 'info');
}

function detachTerminal() {
  if (!TERMINAL_SESSION_ID || !TERMINAL_SESSIONS[TERMINAL_SESSION_ID]) {
    showToast('当前没有活动终端', 'warning');
    return;
  }
  var id = TERMINAL_SESSION_ID;
  if (TERMINAL_SESSIONS[id].timer) {
    clearTimeout(TERMINAL_SESSIONS[id].timer);
    TERMINAL_SESSIONS[id].timer = null;
  }
  TERMINAL_SESSION_ID = '';
  renderTerminalTabs();
  switchTerminalPane('');
  showToast('已从当前终端脱离，后台会话继续保留', 'info');
}

window.addEventListener('resize', function() {
  Object.keys(TERMINAL_SESSIONS).forEach(function(id) {
    var s = TERMINAL_SESSIONS[id];
    if (s && s.fitAddon && s.pane && s.pane.style.visibility !== 'hidden') {
      try { s.fitAddon.fit(); } catch(e) {}
    }
  });
});

window.addEventListener('beforeunload', function() {
  teardownTerminalPolling(true);
});

document.addEventListener('visibilitychange', function() {
  if (document.hidden) return;
  refreshTerminalSessions(false);
});
</script>

<?php require_once __DIR__ . '/shared/footer.php'; ?>
