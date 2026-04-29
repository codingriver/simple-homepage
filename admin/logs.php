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
      <!-- 日志内容通过 Ace Editor 弹窗展示 -->
    </div>
  </main>
</div>

<script src="assets/ace/ace.js"></script>
<script src="assets/ace/ext-searchbox.js"></script>
<?php require_once __DIR__ . '/shared/ace_editor_modal.php'; ?>
<script>
(function(){
  var sources = {};
  var currentKey = null;
  var currentLines = []; // 当前页原始行数组
  var isLoading = false;

  // 分页状态（暴露到 window，供底部栏内联事件使用）
  var logState = {
    totalLines: 0,
    totalPages: 1,
    currentPage: 1,
    limit: 500
  };
  window.logState = logState;

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

  function buildLogText() {
    var kw = (document.getElementById('logKeyword').value || '').trim().toLowerCase();
    var displayLines = currentLines;
    if (kw) {
      displayLines = currentLines.filter(function(line) {
        return line.toLowerCase().indexOf(kw) !== -1;
      });
    }
    return { text: displayLines.join('\n'), filtered: kw !== '', displayCount: displayLines.length };
  }

  function updateEditorContent() {
    var result = buildLogText();
    if (typeof NavAceEditor !== 'undefined' && NavAceEditor.setValue) {
      NavAceEditor.setValue(result.text);
    }
    updateFooterState();
  }

  // 构建底部栏 HTML
  function buildFooterHtml() {
    var s = sources[currentKey] || {};
    var showClear = s.exists && s.clearable;
    return '<div style="display:flex;align-items:center;justify-content:flex-end;gap:6px;width:100%">'
      + '<span class="footer-info" id="log-footer-info">共 0 行</span>'
      + '<span class="footer-sep"></span>'
      + '<select id="log-footer-limit" onchange="logChangeLimit(this.value)" style="width:90px">'
      + '<option value="200">200 行/页</option>'
      + '<option value="500" selected>500 行/页</option>'
      + '<option value="1000">1000 行/页</option>'
      + '<option value="2000">2000 行/页</option>'
      + '</select>'
      + '<span class="footer-sep"></span>'
      + '<button type="button" class="btn" id="log-btn-first" onclick="logGoPage(1)">⏮ 首页</button>'
      + '<button type="button" class="btn" id="log-btn-prev" onclick="logGoPage(logState.currentPage - 1)">◀ 上一页</button>'
      + '<span class="footer-info">第 <span id="log-page-current">1</span> / <span id="log-page-total">1</span> 页</span>'
      + '<input type="number" id="log-page-input" min="1" placeholder="页码" onkeydown="if(event.key===\'Enter\')logGoPage(this.value)">'
      + '<button type="button" class="btn" onclick="logGoPage(document.getElementById(\'log-page-input\').value)">跳转</button>'
      + '<button type="button" class="btn" id="log-btn-next" onclick="logGoPage(logState.currentPage + 1)">下一页 ▶</button>'
      + '<button type="button" class="btn" id="log-btn-last" onclick="logGoPage(logState.totalPages)">末页 ⏭</button>'
      + (showClear ? '<span class="footer-sep"></span><button type="button" class="btn" onclick="clearCurrentLog()" style="color:var(--red)">🗑 清空</button>' : '')
      + '</div>';
  }

  function updateFooterState() {
    var curEl = document.getElementById('log-page-current');
    var totEl = document.getElementById('log-page-total');
    var infoEl = document.getElementById('log-footer-info');
    var firstBtn = document.getElementById('log-btn-first');
    var prevBtn = document.getElementById('log-btn-prev');
    var nextBtn = document.getElementById('log-btn-next');
    var lastBtn = document.getElementById('log-btn-last');
    var limitSel = document.getElementById('log-footer-limit');

    if (curEl) curEl.textContent = logState.currentPage;
    if (totEl) totEl.textContent = logState.totalPages;
    if (infoEl) infoEl.textContent = '共 ' + logState.totalLines + ' 行';
    if (firstBtn) firstBtn.disabled = logState.currentPage <= 1;
    if (prevBtn) prevBtn.disabled = logState.currentPage <= 1;
    if (nextBtn) nextBtn.disabled = logState.currentPage >= logState.totalPages;
    if (lastBtn) lastBtn.disabled = logState.currentPage >= logState.totalPages;
    if (limitSel) limitSel.value = String(logState.limit);
  }

  window.logGoPage = function(page) {
    if (!currentKey || isLoading) return;
    page = parseInt(page, 10);
    if (isNaN(page) || page < 1) page = 1;
    if (page > logState.totalPages) page = logState.totalPages;
    if (page === logState.currentPage && currentLines.length > 0) return;
    logState.currentPage = page;
    loadLogPage(currentKey, page, logState.limit);
  };

  window.logChangeLimit = function(limit) {
    if (!currentKey || isLoading) return;
    limit = parseInt(limit, 10) || 500;
    // 尽量保持当前浏览位置：根据旧 limit 计算 offset，再换算新页码
    var oldOffset = (logState.currentPage - 1) * logState.limit;
    var newPage = Math.floor(oldOffset / limit) + 1;
    logState.limit = limit;
    logState.currentPage = newPage;
    loadLogPage(currentKey, newPage, limit);
  };

  window.selectLog = function(key) {
    if (isLoading) return;
    currentKey = key;
    currentLines = [];
    logState.currentPage = 1;
    logState.limit = parseInt(document.getElementById('logLimit').value, 10) || 500;

    document.querySelectorAll('.logs-item').forEach(function(el) {
      el.classList.toggle('active', el.dataset.key === key);
    });

    var s = sources[key];
    document.getElementById('currentLogTitle').innerHTML = esc(s.label)
      + '<small>' + (s.exists ? (s.lines + ' 行 · ' + formatBytes(s.size)) : '文件不存在') + '</small>';

    document.getElementById('btnDownload').style.display = s.exists ? 'inline-flex' : 'none';
    document.getElementById('btnClear').style.display = (s.exists && s.clearable) ? 'inline-flex' : 'none';
    document.getElementById('logEmpty').style.display = 'none';

    var logTitle = '日志查看 · ' + s.label;
    if (s.path) logTitle += ' · ' + s.path;
    NavAceEditor.open({
      title: logTitle,
      mode: 'text',
      value: '加载中…',
      readOnly: true,
      wrapMode: true,
      footerHtml: buildFooterHtml(),
      buttons: {
        left: [],
        right: [
          { text: '关闭', action: 'close' }
        ]
      },
      onAction: function(action) {
        if (action === 'close') {
          NavAceEditor.close();
        }
      }
    });

    loadLogPage(key, 1, logState.limit);
  };

  function loadLogPage(key, page, limit) {
    if (isLoading) return;
    isLoading = true;
    setStatus('加载中…');
    var url = 'logs_api.php?action=read&type=' + encodeURIComponent(key)
      + '&page=' + encodeURIComponent(page)
      + '&limit=' + encodeURIComponent(limit);
    fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function(r){ return r.json(); })
      .then(function(d){
        isLoading = false;
        if (!d.ok) {
          setStatus('加载失败');
          if (typeof NavAceEditor !== 'undefined' && NavAceEditor.setValue) {
            NavAceEditor.setValue('加载失败：' + (d.msg || ''));
          }
          return;
        }
        currentLines = d.lines || [];
        logState.totalLines = d.total_lines || 0;
        logState.totalPages = d.total_pages || 1;
        logState.currentPage = d.page || 1;
        logState.limit = d.limit || limit;
        updateEditorContent();
        // 切换页码后滚动条回到最顶部
        if (typeof NavAceEditor !== 'undefined' && NavAceEditor.gotoLine) {
          NavAceEditor.gotoLine(1, 0, false);
        }
        var result = buildLogText();
        if (result.filtered) {
          setStatus('过滤后 ' + result.displayCount + ' / ' + currentLines.length + ' 行');
        } else {
          setStatus('第 ' + logState.currentPage + ' / ' + logState.totalPages + ' 页 · 共 ' + logState.totalLines + ' 行');
        }
      })
      .catch(function(){
        isLoading = false;
        setStatus('请求异常');
        if (typeof NavAceEditor !== 'undefined' && NavAceEditor.setValue) {
          NavAceEditor.setValue('请求异常');
        }
      });
  }

  window.reloadCurrentLog = function() {
    if (!currentKey) return;
    logState.currentPage = 1;
    loadLogPage(currentKey, 1, logState.limit);
  };

  window.filterLog = function() {
    updateEditorContent();
    var result = buildLogText();
    if (result.filtered) {
      setStatus('过滤后 ' + result.displayCount + ' / ' + currentLines.length + ' 行');
    } else {
      setStatus('第 ' + logState.currentPage + ' / ' + logState.totalPages + ' 页 · 共 ' + logState.totalLines + ' 行');
    }
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

<?php require_once __DIR__ . '/shared/footer.php'; ?>
