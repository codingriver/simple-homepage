<?php
/**
 * 统一日志中心 admin/logs.php
 */
$page_title = '日志中心';
require_once __DIR__ . '/shared/header.php';
?>

<style>
.logs-wrap {
  display: flex;
  gap: 16px;
  min-height: calc(100vh - 160px);
}
.logs-sidebar {
  width: 240px;
  flex-shrink: 0;
  background: var(--sf);
  border: 1px solid var(--bd);
  border-radius: var(--r);
  padding: 12px 0;
  overflow-y: auto;
  max-height: calc(100vh - 160px);
}
.logs-category {
  padding: 8px 16px;
  font-size: 12px;
  font-weight: 700;
  color: var(--tm);
  text-transform: uppercase;
  letter-spacing: 0.5px;
}
.logs-item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 10px 16px;
  cursor: pointer;
  transition: background .15s;
  border-left: 3px solid transparent;
}
.logs-item:hover {
  background: var(--sf3);
}
.logs-item.active {
  background: var(--ac-dim);
  border-left-color: var(--ac);
}
.logs-item-label {
  font-size: 13px;
  color: var(--tx);
}
.logs-item.active .logs-item-label {
  color: var(--ac2);
}
.logs-item-meta {
  font-size: 11px;
  color: var(--tm);
  font-family: var(--mono);
}
.logs-main {
  flex: 1;
  display: flex;
  flex-direction: column;
  background: var(--sf);
  border: 1px solid var(--bd);
  border-radius: var(--r);
  overflow: hidden;
}
.logs-toolbar {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 12px 16px;
  border-bottom: 1px solid var(--bd);
  flex-wrap: wrap;
}
.logs-title {
  font-size: 14px;
  font-weight: 600;
  color: var(--tx);
  min-width: 140px;
}
.logs-title small {
  font-size: 11px;
  color: var(--tm);
  font-weight: 400;
  margin-left: 6px;
}
.logs-search {
  position: relative;
  flex: 1;
  min-width: 180px;
  max-width: 320px;
}
.logs-search input {
  width: 100%;
  background: var(--bg);
  border: 1px solid var(--bd);
  border-radius: 8px;
  padding: 8px 12px 8px 32px;
  color: var(--tx);
  font-size: 13px;
  outline: none;
}
.logs-search input:focus {
  border-color: var(--ac);
}
.logs-search svg {
  position: absolute;
  left: 10px;
  top: 50%;
  transform: translateY(-50%);
  width: 14px;
  height: 14px;
  fill: var(--tm);
  pointer-events: none;
}
.logs-toolbar select {
  background: var(--bg);
  border: 1px solid var(--bd);
  border-radius: 8px;
  padding: 8px 10px;
  color: var(--tx);
  font-size: 13px;
  outline: none;
}
.logs-toolbar .btn {
  white-space: nowrap;
}
.logs-editor-wrap {
  flex: 1;
  position: relative;
  min-height: 400px;
}
#logEditor {
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
}
.logs-empty {
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  color: var(--tm);
  font-size: 13px;
}
.logs-load-more {
  position: absolute;
  top: 8px;
  left: 50%;
  transform: translateX(-50%);
  z-index: 10;
  display: none;
}
.logs-status {
  font-size: 12px;
  color: var(--tm);
  white-space: nowrap;
}
</style>

<div class="logs-wrap">
  <!-- 左侧日志源列表 -->
  <aside class="logs-sidebar" id="logsSidebar">
    <div class="logs-category">系统日志</div>
    <div id="systemLogs"></div>
    <div class="logs-category" style="margin-top:8px">应用日志</div>
    <div id="appLogs"></div>
  </aside>

  <!-- 右侧主区域 -->
  <main class="logs-main">
    <div class="logs-toolbar">
      <div class="logs-title" id="currentLogTitle">请选择日志</div>
      <div class="logs-status" id="logStatus"></div>
      <div class="logs-search">
        <svg viewBox="0 0 16 16"><path d="M11.742 10.344a6.5 6.5 0 1 0-1.397 1.398h-.001l3.85 3.85a1 1 0 0 0 1.415-1.414l-3.85-3.85zm-5.442.656a5 5 0 1 1 0-10 5 5 0 0 1 0 10z"/></svg>
        <input type="text" id="logKeyword" placeholder="过滤当前内容…" oninput="filterLog()">
      </div>
      <select id="logLimit" onchange="reloadCurrentLog()">
        <option value="100">100 行</option>
        <option value="500" selected>500 行</option>
        <option value="1000">1000 行</option>
        <option value="5000">5000 行</option>
      </select>
      <button class="btn btn-secondary btn-sm" onclick="reloadCurrentLog()">🔄 刷新</button>
      <button class="btn btn-sm" id="btnDownload" onclick="downloadLog()" style="background:var(--ac-dim);border:1px solid var(--ac);color:var(--ac2);display:none">⬇️ 下载</button>
      <button class="btn btn-sm" id="btnClear" onclick="clearCurrentLog()" style="background:rgba(255,85,102,.1);border:1px solid rgba(255,85,102,.35);color:#ff5566;display:none">🗑 清空</button>
    </div>

    <div class="logs-editor-wrap">
      <div class="logs-empty" id="logEmpty">请在左侧选择一个日志文件</div>
      <div id="logEditor" style="display:none"></div>
      <button class="btn btn-secondary btn-sm logs-load-more" id="loadMoreBtn" onclick="loadMore()">⬆️ 加载更早</button>
    </div>
  </main>
</div>

<script>
(function(){
  var editor = null;
  var sources = {};
  var currentKey = null;
  var currentLines = []; // 原始行数组
  var loadedOffset = 0;
  var loadedLimit = 500;
  var isLoading = false;

  function initAce() {
    if (editor) return;
    if (typeof ace === 'undefined') {
      setTimeout(initAce, 100);
      return;
    }
    editor = ace.edit('logEditor', {
      theme: 'ace/theme/tomorrow_night',
      mode: 'ace/mode/text',
      readOnly: true,
      wrap: true,
      showPrintMargin: false,
      highlightActiveLine: false,
      showGutter: true,
      fontSize: 13,
      fontFamily: 'ui-monospace, "SF Mono", Consolas, "JetBrains Mono", monospace'
    });
    editor.container.style.background = '#080b10';
    editor.renderer.setScrollMargin(8, 8);
  }

  function formatBytes(b) {
    if (b === 0) return '0 B';
    var u = ['B','KB','MB','GB'];
    var i = Math.floor(Math.log(b) / Math.log(1024));
    return (b / Math.pow(1024, i)).toFixed(1) + ' ' + u[i];
  }

  function setStatus(text) {
    document.getElementById('logStatus').textContent = text || '';
  }

  function renderSidebar() {
    var systemHtml = '';
    var appHtml = '';
    Object.keys(sources).forEach(function(key) {
      var s = sources[key];
      var meta = s.lines + ' 行 · ' + formatBytes(s.size);
      var html = '<div class="logs-item" data-key="' + esc(key) + '" onclick="selectLog(\'' + esc(key) + '\')">'
        + '<span class="logs-item-label">' + esc(s.label) + '</span>'
        + '<span class="logs-item-meta">' + esc(meta) + '</span>'
        + '</div>';
      if (s.category === 'system') {
        systemHtml += html;
      } else {
        appHtml += html;
      }
    });
    document.getElementById('systemLogs').innerHTML = systemHtml;
    document.getElementById('appLogs').innerHTML = appHtml;
  }

  function esc(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
  }

  window.selectLog = function(key) {
    if (isLoading) return;
    currentKey = key;
    currentLines = [];
    loadedOffset = 0;
    loadedLimit = parseInt(document.getElementById('logLimit').value, 10) || 500;

    document.querySelectorAll('.logs-item').forEach(function(el) {
      el.classList.toggle('active', el.dataset.key === key);
    });

    var s = sources[key];
    document.getElementById('currentLogTitle').innerHTML = esc(s.label)
      + '<small>' + (s.exists ? (s.lines + ' 行 · ' + formatBytes(s.size)) : '文件不存在') + '</small>';

    document.getElementById('btnDownload').style.display = s.exists ? 'inline-flex' : 'none';
    document.getElementById('btnClear').style.display = (s.exists && s.clearable) ? 'inline-flex' : 'none';

    document.getElementById('logEmpty').style.display = 'none';
    document.getElementById('logEditor').style.display = '';
    initAce();
    editor && editor.setValue('加载中…', -1);

    loadLogChunk(key, 'tail', loadedLimit);
  };

  function loadLogChunk(key, direction, limit) {
    if (isLoading) return;
    isLoading = true;
    setStatus('加载中…');
    var url = 'logs_api.php?action=read&type=' + encodeURIComponent(key)
      + '&direction=' + encodeURIComponent(direction)
      + '&limit=' + encodeURIComponent(limit);
    if (direction === 'forward') {
      url += '&offset=' + encodeURIComponent(Math.max(0, loadedOffset - limit));
    }
    fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function(r){ return r.json(); })
      .then(function(d){
        isLoading = false;
        if (!d.ok) {
          setStatus('加载失败');
          editor && editor.setValue('加载失败：' + (d.msg || ''), -1);
          return;
        }
        var lines = d.lines || [];
        if (direction === 'tail') {
          currentLines = lines;
          loadedOffset = d.offset;
        } else {
          currentLines = lines.concat(currentLines);
          loadedOffset = d.offset;
        }
        applyLinesToEditor();
        setStatus('已加载 ' + currentLines.length + ' / ' + d.total_lines + ' 行');
        document.getElementById('loadMoreBtn').style.display = (loadedOffset > 0) ? 'block' : 'none';
        // 如果是 prepend，保持滚动位置在新增内容底部
        if (direction === 'forward' && lines.length > 0) {
          editor && editor.gotoLine(lines.length + 1, 0, false);
        }
      })
      .catch(function(){
        isLoading = false;
        setStatus('请求异常');
        editor && editor.setValue('请求异常', -1);
      });
  }

  window.reloadCurrentLog = function() {
    if (!currentKey) return;
    currentLines = [];
    loadedOffset = 0;
    loadLogChunk(currentKey, 'tail', parseInt(document.getElementById('logLimit').value, 10) || 500);
  };

  window.loadMore = function() {
    if (!currentKey || isLoading || loadedOffset <= 0) return;
    var limit = parseInt(document.getElementById('logLimit').value, 10) || 500;
    loadLogChunk(currentKey, 'forward', limit);
  };

  function applyLinesToEditor() {
    if (!editor) return;
    var kw = (document.getElementById('logKeyword').value || '').trim().toLowerCase();
    var displayLines = currentLines;
    if (kw) {
      displayLines = currentLines.filter(function(line) {
        return line.toLowerCase().indexOf(kw) !== -1;
      });
    }
    var text = displayLines.join('\n');
    editor.setValue(text, -1);
    editor.scrollToLine(0, false, false);
    if (kw) {
      setStatus('过滤后 ' + displayLines.length + ' / ' + currentLines.length + ' 行');
    }
  }

  window.filterLog = function() {
    applyLinesToEditor();
  };

  window.clearCurrentLog = function() {
    if (!currentKey) return;
    if (!confirm('确认清空「' + sources[currentKey].label + '」？此操作不可恢复。')) return;
    var form = new FormData();
    form.append('action', 'clear');
    form.append('type', currentKey);
    form.append('_csrf', window.DEBUG_CSRF || '');
    setStatus('清空中…');
    fetch('logs_api.php', {
      method: 'POST',
      body: form,
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    }).then(function(r){ return r.json(); }).then(function(d){
      if (d.ok) {
        showToast('已清空', 'success');
        reloadCurrentLog();
        refreshSidebar();
      } else {
        showToast(d.msg || '清空失败', 'error');
        setStatus('清空失败');
      }
    }).catch(function(){
      showToast('请求失败', 'error');
      setStatus('请求失败');
    });
  };

  window.downloadLog = function() {
    if (!currentKey) return;
    window.location.href = 'logs_api.php?action=download&type=' + encodeURIComponent(currentKey);
  };

  function refreshSidebar() {
    fetch('logs_api.php?action=list', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function(r){ return r.json(); })
      .then(function(d){
        if (d.ok && d.sources) {
          sources = d.sources;
          renderSidebar();
          if (currentKey) {
            var s = sources[currentKey];
            document.getElementById('currentLogTitle').innerHTML = esc(s.label)
              + '<small>' + (s.exists ? (s.lines + ' 行 · ' + formatBytes(s.size)) : '文件不存在') + '</small>';
            document.querySelectorAll('.logs-item').forEach(function(el) {
              el.classList.toggle('active', el.dataset.key === currentKey);
            });
          }
        }
      });
  }

  // 初始化
  refreshSidebar();
})();
</script>
<script>window.DEBUG_CSRF = <?= json_encode(csrf_token()) ?>;</script>

<!-- Ace Editor -->
<script>
(function(){
  var cdnBase = 'https://cdnjs.cloudflare.com/ajax/libs/ace/1.32.0/';
  var localBase = '/assets/ace/';
  function loadScript(src, onload, onerror) {
    var s = document.createElement('script');
    s.src = src;
    s.onload = onload;
    s.onerror = onerror;
    document.body.appendChild(s);
  }
  loadScript(cdnBase + 'ace.min.js', function(){
    loadScript(cdnBase + 'theme-tomorrow_night.min.js', function(){}, function(){
      loadScript(localBase + 'theme-tomorrow_night.min.js', function(){});
    });
    loadScript(cdnBase + 'mode-text.min.js', function(){}, function(){
      loadScript(localBase + 'mode-text.min.js', function(){});
    });
  }, function(){
    loadScript(localBase + 'ace.min.js', function(){
      loadScript(localBase + 'theme-tomorrow_night.min.js', function(){});
      loadScript(localBase + 'mode-text.min.js', function(){});
    });
  });
})();
</script>

<?php require_once __DIR__ . '/shared/footer.php'; ?>
