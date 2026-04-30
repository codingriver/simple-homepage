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
  height: calc(100vh - 160px);
}
.logs-sidebar {
  width: 240px;
  flex-shrink: 0;
  background: var(--sf);
  border: 1px solid var(--bd);
  border-radius: var(--r);
  padding: 12px 0;
  overflow-y: auto;
  height: calc(100vh - 160px);
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
  width: auto;
  min-width: 0;
}
#logPagination {
  margin-left: auto;
}
.logs-toolbar .btn {
  white-space: nowrap;
}
.logs-editor-wrap {
  flex: 1;
  position: relative;
  min-height: 400px;
  display: flex;
  flex-direction: column;
  overflow: hidden;
}
.logs-empty {
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  color: var(--tm);
  font-size: 13px;
  text-align: center;
  z-index: 2;
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
.log-preview {
  flex: 1;
  overflow-y: auto;
  padding: 12px 16px;
  font-family: var(--mono);
  font-size: 13px;
  line-height: 1.7;
  color: var(--tx);
  background: var(--sf2);
}
.log-preview::-webkit-scrollbar { width: 8px; }
.log-preview::-webkit-scrollbar-track { background: transparent; }
.log-preview::-webkit-scrollbar-thumb { background: var(--bd2); border-radius: 4px; }
.log-line {
  display: flex;
  gap: 12px;
  padding: 1px 0;
}
.log-line:hover {
  background: var(--sf3);
}
.log-line-num {
  color: var(--tm);
  font-size: 12px;
  text-align: right;
  min-width: 48px;
  flex-shrink: 0;
  user-select: none;
}
.log-line-text {
  word-break: break-all;
  white-space: pre-wrap;
}
.log-line-text mark {
  background: rgba(255, 204, 68, 0.25);
  color: var(--yellow);
  border-radius: 2px;
  padding: 0 2px;
}
.log-no-match {
  padding: 40px;
  text-align: center;
  color: var(--tm);
}
.log-pagination {
  display: none;
  align-items: center;
  gap: 6px;
  font-size: 12px;
  flex-wrap: wrap;
}
.log-pagination .btn {
  padding: 4px 10px;
  font-size: 12px;
}
.log-pagination input[type="number"] {
  width: 56px;
  background: var(--bg);
  border: 1px solid var(--bd);
  border-radius: 6px;
  padding: 4px 6px;
  color: var(--tx);
  font-size: 12px;
}
.log-pagination select {
  background: var(--bg);
  border: 1px solid var(--bd);
  border-radius: 6px;
  padding: 4px 6px;
  color: var(--tx);
  font-size: 12px;
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
      <div class="logs-search" id="logSearchWrap" style="display:none">
        <svg viewBox="0 0 16 16"><path d="M11.742 10.344a6.5 6.5 0 1 0-1.397 1.398h-.001l3.85 3.85a1 1 0 0 0 1.415-1.414l-3.85-3.85zm-5.442.656a5 5 0 1 1 0-10 5 5 0 0 1 0 10z"/></svg>
        <input type="text" id="logKeyword" placeholder="过滤当前内容…" oninput="filterLog()">
      </div>
      <select id="logLimit" onchange="reloadCurrentLog()" style="display:none">
        <option value="100">100 行</option>
        <option value="500" selected>500 行</option>
        <option value="1000">1000 行</option>
        <option value="5000">5000 行</option>
      </select>
      <button class="btn btn-secondary btn-sm" id="logRefreshBtn" onclick="reloadCurrentLog()" style="display:none">🔄 刷新</button>
      <button class="btn btn-sm" id="btnDownload" onclick="downloadLog()" style="background:var(--ac-dim);border:1px solid var(--ac);color:var(--ac2);display:none">⬇️ 下载</button>
      <button class="btn btn-sm" id="btnClear" onclick="clearCurrentLog()" style="background:rgba(255,85,102,.1);border:1px solid rgba(255,85,102,.35);color:#ff5566;display:none">🗑 清空</button>
      <button class="btn btn-sm" id="btnAceView" onclick="openAceLogViewer()" style="background:var(--sf3);border:1px solid var(--bd2);color:var(--tx2);display:none" title="在编辑器中打开">📑 编辑器</button>
      <div class="log-pagination" id="logPagination">
        <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap">
          <button type="button" class="btn" id="log-btn-first" onclick="logGoPage(1)">⏮ 首页</button>
          <button type="button" class="btn" id="log-btn-prev" onclick="logGoPage(logState.currentPage - 1)">◀ 上一页</button>
          <span class="footer-info">第 <span id="log-page-current">1</span> / <span id="log-page-total">1</span> 页</span>
          <input type="number" id="log-page-input" min="1" placeholder="页码" onkeydown="if(event.key==='Enter')logGoPage(this.value)">
          <button type="button" class="btn" onclick="logGoPage(document.getElementById('log-page-input').value)">跳转</button>
          <button type="button" class="btn" id="log-btn-next" onclick="logGoPage(logState.currentPage + 1)">下一页 ▶</button>
          <button type="button" class="btn" id="log-btn-last" onclick="logGoPage(logState.totalPages)">末页 ⏭</button>
        </div>
      </div>
    </div>

    <div class="logs-editor-wrap">
      <div class="logs-empty" id="logEmpty">
        <div style="font-size:32px;margin-bottom:12px">📄</div>
        <div>请在左侧选择一个日志文件</div>
        <div style="font-size:12px;color:var(--tm);margin-top:6px">点击左侧日志源开始浏览</div>
      </div>
      <div class="log-preview" id="logPreview" style="display:none"></div>
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

  function escRegExp(s) {
    return String(s).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  }

  function highlightText(text, kw) {
    if (!kw) return esc(text);
    var re = new RegExp('(' + escRegExp(kw) + ')', 'gi');
    return esc(text).replace(re, '<mark>$1</mark>');
  }

  function renderLogContent() {
    var preview = document.getElementById('logPreview');
    var kw = (document.getElementById('logKeyword').value || '').trim().toLowerCase();
    var offset = logState.offset || 0;
    var html = '';

    if (!currentLines.length) {
      preview.innerHTML = '<div class="log-no-match">暂无内容</div>';
      updateFooterState();
      return;
    }

    currentLines.forEach(function(line, i) {
      if (kw && line.toLowerCase().indexOf(kw) === -1) {
        return;
      }
      var lineNum = offset + i + 1;
      html += '<div class="log-line">'
        + '<span class="log-line-num">' + lineNum + '</span>'
        + '<span class="log-line-text">' + highlightText(line, kw) + '</span>'
        + '</div>';
    });

    if (!html) {
      html = '<div class="log-no-match">没有匹配的内容</div>';
    }

    preview.innerHTML = html;
    updateFooterState();
  }

  function updateFooterState() {
    var curEl = document.getElementById('log-page-current');
    var totEl = document.getElementById('log-page-total');
    var firstBtn = document.getElementById('log-btn-first');
    var prevBtn = document.getElementById('log-btn-prev');
    var nextBtn = document.getElementById('log-btn-next');
    var lastBtn = document.getElementById('log-btn-last');

    if (curEl) curEl.textContent = logState.currentPage;
    if (totEl) totEl.textContent = logState.totalPages;
    if (firstBtn) firstBtn.disabled = logState.currentPage <= 1;
    if (prevBtn) prevBtn.disabled = logState.currentPage <= 1;
    if (nextBtn) nextBtn.disabled = logState.currentPage >= logState.totalPages;
    if (lastBtn) lastBtn.disabled = logState.currentPage >= logState.totalPages;
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
      + '<small>' + (s.exists ? formatBytes(s.size) : '文件不存在') + '</small>';

    document.getElementById('logSearchWrap').style.display = '';
    document.getElementById('logLimit').style.display = '';
    document.getElementById('logRefreshBtn').style.display = '';
    document.getElementById('btnDownload').style.display = s.exists ? 'inline-flex' : 'none';
    document.getElementById('btnClear').style.display = (s.exists && s.clearable) ? 'inline-flex' : 'none';
    document.getElementById('btnAceView').style.display = s.exists ? 'inline-flex' : 'none';
    document.getElementById('logEmpty').style.display = 'none';
    document.getElementById('logPreview').style.display = 'block';
    document.getElementById('logPagination').style.display = 'flex';

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
          currentLines = [];
          logState.offset = 0;
          renderLogContent();
          return;
        }
        currentLines = d.lines || [];
        logState.totalLines = d.total_lines || 0;
        logState.totalPages = d.total_pages || 1;
        logState.currentPage = d.page || 1;
        logState.limit = d.limit || limit;
        logState.offset = d.offset || 0;
        renderLogContent();
        // 切换页码后滚动条回到最顶部
        var preview = document.getElementById('logPreview');
        if (preview) preview.scrollTop = 0;
        var result = buildLogText();
        if (result.filtered) {
          setStatus('过滤后 ' + result.displayCount + ' / ' + currentLines.length + ' 行');
        } else {
          setStatus('共 ' + logState.totalLines + ' 行');
        }
      })
      .catch(function(){
        isLoading = false;
        setStatus('请求异常');
        currentLines = [];
        logState.offset = 0;
        renderLogContent();
        var preview = document.getElementById('logPreview');
        if (preview) preview.scrollTop = 0;
      });
  }

  window.reloadCurrentLog = function() {
    if (!currentKey) return;
    logState.currentPage = 1;
    logState.limit = parseInt(document.getElementById('logLimit').value, 10) || 500;
    loadLogPage(currentKey, 1, logState.limit);
  };

  window.filterLog = function() {
    renderLogContent();
    var result = buildLogText();
    if (result.filtered) {
      setStatus('过滤后 ' + result.displayCount + ' / ' + currentLines.length + ' 行');
    } else {
      setStatus('共 ' + logState.totalLines + ' 行');
    }
  };

  // ── Ace 弹窗日志查看器状态（与右侧预览区独立）──
  var aceLogState = { key: '', page: 1, pages: 1, limit: 500 };

  window.aceLogGoPage = function(page) {
    if (!aceLogState.key) return;
    page = parseInt(page, 10);
    if (isNaN(page) || page < 1) page = 1;
    if (page > aceLogState.pages) page = aceLogState.pages;
    if (page === aceLogState.page) return;
    aceLogState.page = page;
    aceLogLoadPage(page);
  };

  function aceLogLoadPage(page) {
    if (!aceLogState.key) return;
    NavAceEditor.setValue('加载中…');
    var url = 'logs_api.php?action=read&type=' + encodeURIComponent(aceLogState.key)
      + '&page=' + encodeURIComponent(page)
      + '&limit=' + encodeURIComponent(aceLogState.limit);
    fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function(r){ return r.json(); })
      .then(function(d){
        if (!d.ok) {
          NavAceEditor.setValue('加载失败：' + (d.msg || ''));
          return;
        }
        aceLogState.pages = d.total_pages || 1;
        aceLogState.page = d.page || 1;
        aceLogState.limit = d.limit || aceLogState.limit;

        var lines = d.lines || [];
        var text = lines.join('\n');
        NavAceEditor.setValue(text);

        var infoEl = document.getElementById('ace-log-info');
        var pageLabelEl = document.getElementById('ace-log-page-label');
        var prevBtn = document.getElementById('ace-log-prev');
        var nextBtn = document.getElementById('ace-log-next');
        var lastBtn = document.getElementById('ace-log-last-btn');

        if (infoEl) infoEl.textContent = '共 ' + d.total_lines + ' 行，每页 ' + aceLogState.limit + ' 行';
        if (pageLabelEl) pageLabelEl.textContent = '第 ' + aceLogState.page + ' / ' + aceLogState.pages + ' 页';
        if (prevBtn) prevBtn.disabled = aceLogState.page <= 1;
        if (nextBtn) nextBtn.disabled = aceLogState.page >= aceLogState.pages;
        if (lastBtn) lastBtn.disabled = aceLogState.page >= aceLogState.pages;
      })
      .catch(function(){
        NavAceEditor.setValue('请求异常');
      });
  }

  window.openAceLogViewer = function() {
    if (!currentKey) return;
    var s = sources[currentKey] || {};
    var logTitle = '日志查看 · ' + s.label;
    if (s.path) logTitle += ' · ' + s.path;

    aceLogState.key = currentKey;
    aceLogState.page = logState.currentPage;
    aceLogState.pages = logState.totalPages;
    aceLogState.limit = logState.limit;

    var footerHtml = '<div style="display:flex;align-items:center;justify-content:flex-end;gap:6px;width:100%">'
      + '<span id="ace-log-info" style="font-size:12px;color:var(--tm);font-family:var(--mono)">加载中…</span>'
      + '<button type="button" class="btn btn-sm btn-secondary" id="ace-log-prev" onclick="aceLogGoPage(aceLogState.page - 1)">◀ 上一页</button>'
      + '<span id="ace-log-page-label" style="font-size:12px;font-family:var(--mono);color:var(--tx2)">第 ' + aceLogState.page + ' / ' + aceLogState.pages + ' 页</span>'
      + '<button type="button" class="btn btn-sm btn-secondary" id="ace-log-next" onclick="aceLogGoPage(aceLogState.page + 1)">下一页 ▶</button>'
      + '<button type="button" class="btn btn-sm btn-secondary" onclick="aceLogGoPage(1)" title="第一页">⏮</button>'
      + '<button type="button" class="btn btn-sm btn-secondary" id="ace-log-last-btn" onclick="aceLogGoPage(aceLogState.pages)" title="最后一页">⏭</button>'
      + '</div>';

    NavAceEditor.open({
      title: logTitle,
      mode: 'text',
      value: '加载中…',
      readOnly: true,
      wrapMode: true,
      footerHtml: footerHtml,
      buttons: { left: [], right: [] },
      onAction: function(action) {}
    });

    aceLogLoadPage(aceLogState.page);
  };

  window.clearCurrentLog = function() {
    if (!currentKey) return;
    NavConfirm.open({
      title: '清空日志',
      message: '确认清空「' + sources[currentKey].label + '」？此操作不可恢复。',
      confirmText: '清空',
      cancelText: '取消',
      danger: true,
      onConfirm: function() {
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
      }
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
              + '<small>' + (s.exists ? formatBytes(s.size) : '文件不存在') + '</small>';
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
