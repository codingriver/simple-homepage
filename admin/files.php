<?php
declare(strict_types=1);

$page_permission = 'ssh.files';
$page_title = '文件系统';

require_once __DIR__ . '/shared/header.php';
require_once __DIR__ . '/shared/file_manager_lib.php';
require_once __DIR__ . '/shared/ssh_manager_lib.php';

$canManage = auth_user_has_permission('ssh.manage', $current_admin);
$canWrite = $canManage || auth_user_has_permission('ssh.files.write', $current_admin);
$canAudit = auth_user_has_permission('ssh.audit', $current_admin);
$canWebdavManage = ($current_admin['role'] ?? '') === 'admin';
$remoteHosts = ssh_manager_list_hosts();
$favorites = file_manager_favorites_list((string)($current_admin['username'] ?? ''));
$recentItems = file_manager_recent_list((string)($current_admin['username'] ?? ''));
$quickPaths = file_manager_quick_paths();
$selectedHostId = trim((string)($_GET['host_id'] ?? 'local')) ?: 'local';
$selectedPath = trim((string)($_GET['path'] ?? '/')) ?: '/';
$csrfValue = csrf_token();
$fsAllowedRoots = fs_allowed_roots();
?>

<style>
@media (max-width: 1100px) {
  #fm-layout {
    grid-template-columns: 1fr !important;
  }

  #fm-editor-card .form-actions {
    position: sticky;
    bottom: 0;
    z-index: 5;
    background: var(--sf);
    padding-bottom: 8px;
    flex-wrap: wrap;
  }
}

#fm-editor {
  margin-bottom: 12px;
}

#fm-editor-card .form-actions {
  position: relative;
  z-index: 1;
}
</style>

<div class="card" style="margin-bottom:16px">
  <div class="card-title">文件系统</div>
  <div style="color:var(--tm);font-size:12px;line-height:1.9">
    这里是独立文件系统工作台，负责本机和远程主机文件管理。当前已支持目录浏览、文本编辑、上传下载、新建删除、重命名、复制/剪切/粘贴、多选批量、权限、压缩解压、收藏目录、最近访问和预览增强。
  </div>
</div>

<div id="fm-layout" style="display:grid;grid-template-columns:minmax(240px,280px) minmax(480px,1fr) minmax(360px,440px);gap:16px;align-items:start">
  <div style="display:flex;flex-direction:column;gap:16px">
    <div class="card" id="fm-editor-card">
      <div class="card-title">目标主机</div>
      <select id="fm-host-select" style="width:100%;margin-bottom:10px">
        <option value="local" <?= $selectedHostId === 'local' ? 'selected' : '' ?>>本机</option>
        <?php foreach ($remoteHosts as $host): ?>
        <option value="<?= htmlspecialchars((string)($host['id'] ?? '')) ?>" <?= $selectedHostId === (string)($host['id'] ?? '') ? 'selected' : '' ?>><?= htmlspecialchars((string)($host['name'] ?? '')) ?></option>
        <?php endforeach; ?>
      </select>
      <div class="form-hint">默认管理的是当前容器视角文件系统；若目录是宿主机挂载卷，则实际操作的是挂载后的宿主机目录。</div>
    </div>

    <div class="card">
      <div class="card-title">快捷入口</div>
      <div id="fm-quick-list" style="display:flex;flex-direction:column;gap:8px">
        <?php foreach ($quickPaths as $item): ?>
        <button type="button" class="btn btn-secondary fm-jump-btn" data-host-id="<?= htmlspecialchars((string)($item['host_id'] ?? 'local')) ?>" data-path="<?= htmlspecialchars((string)($item['path'] ?? '/')) ?>" style="justify-content:flex-start">
          <?= htmlspecialchars((string)($item['name'] ?? '')) ?>
          <span style="margin-left:auto;font-family:var(--mono);font-size:11px;color:var(--tm)"><?= htmlspecialchars((string)($item['path'] ?? '/')) ?></span>
        </button>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="card">
      <div class="card-title">收藏目录</div>
      <div id="fm-favorites" style="display:flex;flex-direction:column;gap:8px">
        <?php if (!$favorites): ?>
        <div class="form-hint">还没有收藏目录。</div>
        <?php endif; ?>
        <?php foreach ($favorites as $item): ?>
        <div class="fm-favorite-row" style="display:flex;gap:8px;align-items:center">
          <button type="button" class="btn btn-secondary fm-jump-btn" data-host-id="<?= htmlspecialchars((string)($item['host_id'] ?? 'local')) ?>" data-path="<?= htmlspecialchars((string)($item['path'] ?? '/')) ?>" style="flex:1;justify-content:flex-start">
            <?= htmlspecialchars((string)($item['name'] ?? '')) ?>
          </button>
          <?php if ($canWrite): ?><button type="button" class="btn btn-sm btn-danger fm-favorite-delete" data-id="<?= htmlspecialchars((string)($item['id'] ?? '')) ?>">删</button><?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="card">
      <div class="card-title">最近访问</div>
      <div id="fm-recent" style="display:flex;flex-direction:column;gap:8px">
        <?php if (!$recentItems): ?>
        <div class="form-hint">暂无最近访问。</div>
        <?php endif; ?>
        <?php foreach ($recentItems as $item): ?>
        <button type="button" class="btn btn-secondary fm-jump-btn" data-host-id="<?= htmlspecialchars((string)($item['host_id'] ?? 'local')) ?>" data-path="<?= htmlspecialchars((string)($item['path'] ?? '/')) ?>" style="justify-content:flex-start">
          <?= htmlspecialchars((string)($item['name'] ?? '')) ?>
          <span style="margin-left:auto;font-family:var(--mono);font-size:11px;color:var(--tm)"><?= htmlspecialchars((string)($item['path'] ?? '/')) ?></span>
        </button>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <div style="display:flex;flex-direction:column;gap:16px">
    <div class="card">
      <div class="card-title">目录浏览</div>
      <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:10px">
        <input type="text" id="fm-path" value="<?= htmlspecialchars($selectedPath) ?>" style="flex:1;min-width:260px;font-family:var(--mono)">
        <button type="button" class="btn btn-secondary" onclick="loadFiles()">刷新</button>
        <button type="button" class="btn btn-secondary" onclick="goParentDir()">上级</button>
        <?php if ($canWrite): ?><button type="button" class="btn btn-secondary" onclick="createFolder()">新建目录</button><?php endif; ?>
        <?php if ($canWrite): ?><button type="button" class="btn btn-secondary" onclick="createFile()">新建文件</button><?php endif; ?>
        <?php if ($canWrite): ?><button type="button" class="btn btn-secondary" onclick="saveFavorite()">收藏当前目录</button><?php endif; ?>
        <?php if ($canWebdavManage): ?><button type="button" class="btn btn-secondary" onclick="createWebdavShare()">创建 WebDAV 共享</button><?php endif; ?>
        <?php if ($canAudit): ?><a href="file_audit.php" class="btn btn-secondary">文件审计</a><?php endif; ?>
      </div>
      <div id="fm-whitelist-hint" class="form-hint" data-has-roots="<?= !empty($fsAllowedRoots) ? '1' : '0' ?>" style="display:none;margin-bottom:10px;padding:8px 12px;border:1px dashed var(--bd);border-radius:8px;background:var(--bg);color:var(--tm);font-size:12px">
        <b>白名单限制</b>：当前仅允许访问以下路径前缀的目录 — <?= implode('、', array_map(function($r){ return '<code style="background:var(--sf);padding:1px 4px;border-radius:3px">' . htmlspecialchars($r) . '</code>'; }, $fsAllowedRoots)) ?>
      </div>
      <div id="fm-breadcrumbs" class="form-hint" style="margin-bottom:10px"></div>
      <div id="fm-directory-status" style="display:none;margin-bottom:10px;padding:12px 14px;border:1px solid var(--bd);border-radius:10px;background:var(--bg)"></div>
      <div id="fm-webdav-shares" class="form-hint" style="margin-bottom:10px;padding:10px 12px;border:1px dashed var(--bd);border-radius:10px;background:var(--bg)">正在检查当前目录的 WebDAV 共享...</div>
      <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:10px">
        <input type="text" id="fm-filter" placeholder="筛选当前目录中文件名" style="flex:1;min-width:220px">
        <input type="text" id="fm-search" placeholder="递归搜索文件名" style="flex:1;min-width:220px">
        <button type="button" class="btn btn-secondary" onclick="searchFiles()">搜索</button>
        <input type="file" id="fm-upload-input" style="display:none" onchange="uploadSelectedFile()">
        <?php if ($canWrite): ?><button type="button" class="btn btn-secondary" onclick="document.getElementById('fm-upload-input').click()">上传文件</button><?php endif; ?>
        <?php if ($canWrite): ?><button type="button" class="btn btn-secondary" onclick="renameSelected()">重命名</button><?php endif; ?>
        <?php if ($canWrite): ?><button type="button" class="btn btn-secondary" onclick="copySelected()">复制</button><?php endif; ?>
        <?php if ($canWrite): ?><button type="button" class="btn btn-secondary" onclick="cutSelected()">剪切</button><?php endif; ?>
        <?php if ($canWrite): ?><button type="button" class="btn btn-secondary" onclick="pasteClipboard()">粘贴</button><?php endif; ?>
        <?php if ($canWrite): ?><button type="button" class="btn btn-danger" onclick="deleteSelected()">批量删除</button><?php endif; ?>
        <?php if ($canWrite): ?><button type="button" class="btn btn-secondary" onclick="applyFileOp('chmod')">chmod</button><?php endif; ?>
        <?php if ($canWrite): ?><button type="button" class="btn btn-secondary" onclick="applyFileOp('chown')">chown</button><?php endif; ?>
        <?php if ($canWrite): ?><button type="button" class="btn btn-secondary" onclick="applyFileOp('chgrp')">chgrp</button><?php endif; ?>
        <?php if ($canWrite): ?><button type="button" class="btn btn-secondary" onclick="archiveCurrentPath()">压缩</button><?php endif; ?>
        <?php if ($canWrite): ?><button type="button" class="btn btn-secondary" onclick="extractCurrentFile()">解压</button><?php endif; ?>
        <?php if ($canWrite): ?><button type="button" class="btn btn-secondary" onclick="downloadFromUrl()">⬇️ URL下载</button><?php endif; ?>
        <button type="button" class="btn btn-secondary" onclick="toggleTrashView()">🗑 回收站</button>
      </div>
      <div id="fm-clipboard-meta" class="form-hint" style="margin-bottom:10px"></div>
      <div id="fm-trash-panel" style="display:none;margin-bottom:16px">
        <div class="card">
          <div class="card-title" style="display:flex;justify-content:space-between;align-items:center">
            <span>🗑 回收站</span>
            <div style="display:flex;gap:8px">
              <button type="button" class="btn btn-secondary" onclick="loadTrash()" style="font-size:12px;padding:6px 10px">刷新</button>
              <button type="button" class="btn btn-danger" onclick="autoCleanTrash()" style="font-size:12px;padding:6px 10px">清理30天前</button>
              <button type="button" class="btn btn-secondary" onclick="toggleTrashView()" style="font-size:12px;padding:6px 10px">关闭</button>
            </div>
          </div>
          <div class="table-wrap">
            <table id="fm-trash-table">
              <thead><tr><th>原始路径</th><th>删除时间</th><th>操作人</th><th>操作</th></tr></thead>
              <tbody></tbody>
            </table>
          </div>
          <div id="fm-trash-empty" class="form-hint" style="display:none">回收站为空</div>
        </div>
      </div>
      <div class="table-wrap">
        <table id="fm-table">
          <thead><tr><th style="width:36px"><input type="checkbox" id="fm-check-all"></th><th>名称</th><th>类型</th><th>大小</th><th>修改时间</th><th>操作</th></tr></thead>
          <tbody></tbody>
        </table>
      </div>
    </div>
  </div>

  <div style="display:flex;flex-direction:column;gap:16px">
    <div class="card">
      <div class="card-title">文件编辑</div>
      <div class="form-hint" style="margin-bottom:8px">当前编辑文件路径</div>
      <input type="text" id="fm-edit-path" value="" style="width:100%;font-family:var(--mono);margin-bottom:8px">
      <div id="fm-editor-meta" class="form-hint" style="margin-bottom:8px">支持文本编辑；二进制文件只允许下载和覆盖上传。</div>
      <div id="fm-stat-meta" class="form-hint" style="margin-bottom:8px"></div>
      <div id="fm-preview-type" class="form-hint" style="margin-bottom:8px"></div>
      <div id="fm-editor-status" style="display:none;margin-bottom:8px;padding:12px 14px;border:1px solid var(--bd);border-radius:10px;background:var(--bg)"></div>
      <div id="fm-preview-image-wrap" style="display:none;margin-bottom:8px;cursor:zoom-in" onclick="openImageLightbox()">
        <img id="fm-preview-image" alt="图片预览" style="max-width:100%;max-height:220px;border-radius:10px;border:1px solid var(--bd);background:var(--bg)">
      </div>
      <div id="fm-lightbox" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.85);z-index:10000;align-items:center;justify-content:center;cursor:zoom-out" onclick="closeImageLightbox()">
        <img id="fm-lightbox-image" alt="放大预览" style="max-width:90vw;max-height:90vh;border-radius:10px;box-shadow:0 20px 60px rgba(0,0,0,.5)">
      </div>
      <pre id="fm-preview-config" style="display:none;max-height:220px;overflow:auto;margin-bottom:8px;background:#0b1220;border:1px solid rgba(255,255,255,.08);border-radius:10px;padding:12px;color:#d8f5d0;font-family:var(--mono);font-size:12px;line-height:1.6"></pre>
      <textarea id="fm-editor" spellcheck="false" style="width:100%;min-height:360px;background:var(--bg);border:1px solid var(--bd);border-radius:10px;padding:12px;color:var(--tx);font-family:var(--mono)"></textarea>
      <div class="form-actions">
        <?php if ($canWrite): ?><button type="button" class="btn btn-primary" onclick="saveFile()">保存文件</button><?php endif; ?>
        <button type="button" class="btn btn-secondary" onclick="readCurrentFile()">重新读取</button>
        <button type="button" class="btn btn-secondary" onclick="downloadCurrentFile()">下载当前文件</button>
        <?php if ($canWrite): ?><button type="button" class="btn btn-danger" onclick="deleteCurrentFile()">删除当前文件</button><?php endif; ?>
      </div>
    </div>
  </div>
</div>

<script>
var FM_CSRF = <?= json_encode($csrfValue) ?>;
var FM_CAN_WRITE = <?= $canWrite ? 'true' : 'false' ?>;
var FM_ITEMS = [];
var FM_CURRENT_BASE64 = '';
var FM_CURRENT_IS_BINARY = false;
var FM_CLIPBOARD = null;
var FM_CAN_WEBDAV_MANAGE = <?= $canWebdavManage ? 'true' : 'false' ?>;
var FM_LOAD_SEQ = 0;
var FM_READ_SEQ = 0;
var FM_STAT_SEQ = 0;
var FM_STATUS = navCreateAsyncStatus({
  getRefs: function(scope) {
    return {
      wrap: document.getElementById(scope + '-status')
    };
  }
});

function fmStatusRefs(scope) {
  return {
    wrap: document.getElementById(scope + '-status')
  };
}

function fmSetStatus(scope, title, detail, percent, tone) {
  FM_STATUS.set(scope, title, detail, percent, tone);
}

function fmHideStatus(scope) {
  FM_STATUS.hide(scope);
}

function fmStartTask(scope, title, detail) {
  return FM_STATUS.start(scope, title, detail);
}

function fmFinishTask(id, ok, detail) {
  FM_STATUS.finish(id, ok, detail);
}

async function fmRun(scope, title, detail, runner) {
  return FM_STATUS.run(scope, title, detail, runner);
}

function fmBuildBody(action, params) {
  var body = new URLSearchParams();
  body.append('action', action);
  body.append('_csrf', FM_CSRF);
  Object.keys(params || {}).forEach(function(key) {
    var value = params[key];
    if (value === undefined || value === null) return;
    body.append(key, value);
  });
  return body;
}

async function fileApiGet(action, params) {
  var query = new URLSearchParams(Object.assign({ action: action }, params || {}));
  var res = await fetch('file_api.php?' + query.toString(), {
    credentials: 'same-origin',
    headers: { 'X-Requested-With': 'XMLHttpRequest' }
  });
  return res.json();
}

async function fileApiPost(action, params) {
  var res = await fetch('file_api.php', {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' },
    body: fmBuildBody(action, params || {})
  });
  return res.json();
}

function currentHostId() {
  return document.getElementById('fm-host-select').value || 'local';
}

function currentPath() {
  return document.getElementById('fm-path').value || '/';
}

function escapeHtml(value) {
  return String(value || '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

function arrayBufferToBase64(buffer) {
  var bytes = new Uint8Array(buffer);
  var chunkSize = 0x8000;
  var binary = '';
  for (var i = 0; i < bytes.length; i += chunkSize) {
    var chunk = bytes.subarray(i, i + chunkSize);
    binary += String.fromCharCode.apply(null, chunk);
  }
  return btoa(binary);
}

function base64ToBlob(base64) {
  var binary = atob(base64 || '');
  var bytes = new Uint8Array(binary.length);
  for (var i = 0; i < binary.length; i += 1) {
    bytes[i] = binary.charCodeAt(i);
  }
  return new Blob([bytes]);
}

function textToBase64(text) {
  var encoded = new TextEncoder().encode(String(text || ''));
  var chunkSize = 0x8000;
  var binary = '';
  for (var i = 0; i < encoded.length; i += chunkSize) {
    var chunk = encoded.subarray(i, i + chunkSize);
    binary += String.fromCharCode.apply(null, chunk);
  }
  return btoa(binary);
}

function renderBreadcrumbs(path) {
  var el = document.getElementById('fm-breadcrumbs');
  if (!el) return;
  var normalized = String(path || '/');
  if (!normalized.startsWith('/')) normalized = '/' + normalized;
  var parts = normalized.split('/').filter(Boolean);
  var html = '<button type="button" class="btn btn-sm btn-secondary fm-crumb" data-path="/">/</button>';
  var current = '';
  parts.forEach(function(part) {
    current += '/' + part;
    html += ' <span style="color:var(--tm)">/</span> <button type="button" class="btn btn-sm btn-secondary fm-crumb" data-path="' + escapeHtml(current) + '">' + escapeHtml(part) + '</button>';
  });
  el.innerHTML = html;
  el.querySelectorAll('.fm-crumb').forEach(function(btn) {
    btn.addEventListener('click', function() {
      document.getElementById('fm-path').value = this.getAttribute('data-path');
      loadFiles();
    });
  });
}

function renderFileRows() {
  var keyword = (document.getElementById('fm-filter').value || '').toLowerCase().trim();
  var tbody = document.querySelector('#fm-table tbody');
  tbody.innerHTML = '';
  FM_ITEMS
    .filter(function(item) {
      return !keyword || String(item.name || '').toLowerCase().indexOf(keyword) !== -1;
    })
    .forEach(function(item) {
      var tr = document.createElement('tr');
      var checkHtml = '<input type="checkbox" class="fm-item-check" value="' + escapeHtml(item.path) + '">';
      var isArchive = item.type === 'file' && /\.(tar\.gz|tgz|tar\.bz2|tbz2|tar\.xz|txz|tar\.lz|tlz|tar\.zst|tar|zip|7z|rar)$/i.test(item.name);
      var actions = item.type === 'dir'
        ? '<button type="button" class="btn btn-sm btn-secondary" data-open="' + escapeHtml(item.path) + '">进入</button>'
        : '<button type="button" class="btn btn-sm btn-secondary" data-read="' + escapeHtml(item.path) + '">编辑</button>';
      if (isArchive) {
        actions += ' <button type="button" class="btn btn-sm btn-secondary" data-extract="' + escapeHtml(item.path) + '">解压</button>';
      }
      if (FM_CAN_WRITE) {
        actions += ' <button type="button" class="btn btn-sm btn-secondary" data-rename="' + escapeHtml(item.path) + '">重命名</button>';
        actions += ' <button type="button" class="btn btn-sm btn-danger" data-delete="' + escapeHtml(item.path) + '">删除</button>';
      }
      tr.innerHTML = '<td>' + checkHtml + '</td>'
        + '<td>' + escapeHtml(item.name) + '</td>'
        + '<td>' + escapeHtml(item.type) + '</td>'
        + '<td>' + (item.size || 0) + '</td>'
        + '<td>' + escapeHtml(item.mtime || '') + '</td>'
        + '<td style="white-space:nowrap">' + actions + '</td>';
      tbody.appendChild(tr);
    });
  tbody.querySelectorAll('[data-open]').forEach(function(btn) {
    btn.addEventListener('click', function() {
      document.getElementById('fm-path').value = this.getAttribute('data-open');
      loadFiles();
    });
  });
  tbody.querySelectorAll('[data-read]').forEach(function(btn) {
    btn.addEventListener('click', function() {
      document.getElementById('fm-edit-path').value = this.getAttribute('data-read');
      readCurrentFile();
    });
  });
  tbody.querySelectorAll('[data-delete]').forEach(function(btn) {
    btn.addEventListener('click', function() {
      deleteFile(this.getAttribute('data-delete'));
    });
  });
  tbody.querySelectorAll('[data-rename]').forEach(function(btn) {
    btn.addEventListener('click', function() {
      renamePath(this.getAttribute('data-rename'));
    });
  });
  tbody.querySelectorAll('[data-extract]').forEach(function(btn) {
    btn.addEventListener('click', function() {
      extractArchive(this.getAttribute('data-extract'));
    });
  });
}

async function loadFiles() {
  var seq = ++FM_LOAD_SEQ;
  var data = await fmRun('fm-directory', '读取目录', '正在加载目录内容…', function() {
    return fileApiGet('list', { host_id: currentHostId(), path: currentPath() });
  });
  if (seq !== FM_LOAD_SEQ) return;
  if (!data.ok) {
    showToast(data.msg || '目录读取失败', 'error');
    return;
  }
  FM_ITEMS = data.items || [];
  document.getElementById('fm-path').value = data.cwd || currentPath();
  renderBreadcrumbs(data.cwd || currentPath());
  renderFileRows();
  loadWebdavShares();
  await fileApiPost('recent_touch', { host_id: currentHostId(), path: document.getElementById('fm-path').value || '/' });
  loadRecent();
}

async function loadWebdavShares() {
  var wrap = document.getElementById('fm-webdav-shares');
  if (!wrap) return;
  if (currentHostId() !== 'local') {
    wrap.textContent = '远程主机目录暂不支持本地 WebDAV 共享映射。';
    return;
  }
  var data = await fmRun('fm-directory', '检查 WebDAV 共享', '正在读取当前目录对应的共享配置…', function() {
    return fileApiGet('webdav_shares_for_path', { host_id: currentHostId(), path: currentPath() });
  });
  if (!data.ok) {
    wrap.textContent = data.msg || '当前目录 WebDAV 共享信息读取失败';
    return;
  }
  var items = data.items || [];
  if (!items.length) {
    wrap.innerHTML = '当前目录还没有绑定 WebDAV 共享账号。';
    return;
  }
  var relationLabelMap = {
    exact: '当前目录就是共享根目录',
    inside: '当前目录位于共享根目录内',
    child: '当前目录下包含共享子目录'
  };
  wrap.innerHTML = '当前目录已共享给 ' + items.map(function(item) {
    return '<a href="webdav.php?edit=' + encodeURIComponent(item.id || '') + '" style="font-weight:700">' + escapeHtml(item.username || '') + '</a>'
      + ' <span style="color:var(--tm)">' + escapeHtml(relationLabelMap[item.relation] || '共享目录关联') + '</span>'
      + ' <span style="font-family:var(--mono);color:var(--tm)">' + escapeHtml(item.root || '/') + '</span>'
      + ' <span class="badge ' + (item.enabled ? 'badge-green' : 'badge-gray') + '">' + (item.enabled ? '启用' : '禁用') + '</span>'
      + ' <span style="color:var(--tm)">' + (item.readonly ? '只读' : '读写') + '</span>';
  }).join('，');
}

function selectedPaths() {
  return Array.from(document.querySelectorAll('.fm-item-check:checked')).map(function(input) {
    return input.value;
  });
}

function selectedItems() {
  var checked = selectedPaths();
  return FM_ITEMS.filter(function(item) { return checked.indexOf(item.path) !== -1; });
}

function goParentDir() {
  var path = currentPath();
  if (!path || path === '/') {
    document.getElementById('fm-path').value = '/';
    loadFiles();
    return;
  }
  var trimmed = path.replace(/\/+$/, '');
  var next = trimmed.split('/').slice(0, -1).join('/') || '/';
  document.getElementById('fm-path').value = next;
  loadFiles();
}

async function createFolder() {
  if (!FM_CAN_WRITE) return;
  var path = prompt('请输入目录完整路径', currentPath().replace(/\/$/, '') + '/new-dir');
  if (!path) return;
  var data = await fmRun('fm-directory', '创建目录', '正在创建目录 ' + path + ' …', function() {
    return fileApiPost('mkdir', { host_id: currentHostId(), path: path });
  });
  showToast(data.msg || (data.ok ? '目录已创建' : '目录创建失败'), data.ok ? 'success' : 'error');
  if (data.ok) await loadFiles();
}

async function createFile() {
  if (!FM_CAN_WRITE) return;
  var path = prompt('请输入文件完整路径', currentPath().replace(/\/$/, '') + '/new-file.txt');
  if (!path) return;
  var data = await fmRun('fm-editor', '创建文件', '正在创建文件 ' + path + ' …', function() {
    return fileApiPost('write', { host_id: currentHostId(), path: path, content: '' });
  });
  showToast(data.msg || (data.ok ? '文件已创建' : '文件创建失败'), data.ok ? 'success' : 'error');
  if (data.ok) {
    document.getElementById('fm-edit-path').value = path;
    document.getElementById('fm-editor').value = '';
    document.getElementById('fm-editor-meta').textContent = '正在读取文件...';
    document.getElementById('fm-preview-type').textContent = '';
    document.getElementById('fm-preview-image-wrap').style.display = 'none';
    document.getElementById('fm-preview-config').style.display = 'none';
    await loadFiles();
    await readCurrentFile();
  }
}

function inferPreviewType(path, isBinary) {
  var ext = (path.split('.').pop() || '').toLowerCase();
  if (['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg'].indexOf(ext) !== -1) return 'image';
  if (!isBinary && ['json', 'yml', 'yaml', 'conf', 'ini', 'env', 'sh', 'php', 'nginx', 'service', 'txt', 'md'].indexOf(ext) !== -1) return 'config';
  return isBinary ? 'binary' : 'text';
}

function renderPreview(path, content, contentBase64, isBinary) {
  var type = inferPreviewType(path, isBinary);
  var typeEl = document.getElementById('fm-preview-type');
  var imgWrap = document.getElementById('fm-preview-image-wrap');
  var img = document.getElementById('fm-preview-image');
  var configPre = document.getElementById('fm-preview-config');
  imgWrap.style.display = 'none';
  configPre.style.display = 'none';
  typeEl.textContent = '';
  if (type === 'image' && contentBase64) {
    img.src = 'data:image/*;base64,' + contentBase64;
    imgWrap.style.display = '';
    typeEl.textContent = '图片预览';
    return;
  }
  if (type === 'config') {
    try {
      if ((path.split('.').pop() || '').toLowerCase() === 'json') {
        configPre.textContent = JSON.stringify(JSON.parse(content || '{}'), null, 2);
      } else {
        configPre.textContent = String(content || '');
      }
    } catch (err) {
      configPre.textContent = String(content || '');
    }
    configPre.style.display = '';
    typeEl.textContent = '配置预览';
    return;
  }
  if (type === 'binary') {
    typeEl.textContent = '二进制文件预览不可用';
    return;
  }
  typeEl.textContent = '文本文件';
}

async function readCurrentFile() {
  var seq = ++FM_READ_SEQ;
  var path = document.getElementById('fm-edit-path').value;
  if (!path) {
    showToast('请先选择文件', 'warning');
    return;
  }
  document.getElementById('fm-editor').value = '';
  document.getElementById('fm-editor-meta').textContent = '正在读取文件...';
  document.getElementById('fm-preview-type').textContent = '';
  document.getElementById('fm-preview-image-wrap').style.display = 'none';
  document.getElementById('fm-preview-config').style.display = 'none';
  var data = await fmRun('fm-editor', '读取文件', '正在读取文件内容…', function() {
    return fileApiPost('read', { host_id: currentHostId(), path: path });
  });
  if (seq !== FM_READ_SEQ || document.getElementById('fm-edit-path').value !== path) {
    return;
  }
  if (!data.ok) {
    showToast(data.msg || '文件读取失败', 'error');
    return;
  }
  FM_CURRENT_BASE64 = data.content_base64 || '';
  FM_CURRENT_IS_BINARY = !!data.is_binary;
  document.getElementById('fm-editor').value = data.is_binary ? '' : (data.content || '');
  document.getElementById('fm-editor-meta').textContent = data.is_binary
    ? '当前文件为二进制，禁止直接文本编辑；可下载或重新上传覆盖。'
    : '文本文件已读取，可直接编辑并保存。';
  await loadCurrentStat();
  renderPreview(path, data.content || '', data.content_base64 || '', !!data.is_binary);
}

async function saveFile() {
  if (!FM_CAN_WRITE) return;
  var path = document.getElementById('fm-edit-path').value;
  if (!path) {
    showToast('请先填写文件路径', 'warning');
    return;
  }
  var contentBase64 = textToBase64(document.getElementById('fm-editor').value);
  var data = await fmRun('fm-editor', '保存文件', '正在写入文件内容…', function() {
    return fileApiPost('write', {
      host_id: currentHostId(),
      path: path,
      content_base64: contentBase64
    });
  });
  showToast(data.msg || (data.ok ? '保存成功' : '保存失败'), data.ok ? 'success' : 'error');
  if (data.ok) {
    FM_CURRENT_IS_BINARY = false;
    FM_CURRENT_BASE64 = contentBase64;
    await loadCurrentStat();
    loadFiles();
  }
}

async function deleteFile(path) {
  if (!FM_CAN_WRITE || !path) return;
  if (!confirm('确认删除 ' + path + ' ?')) return;
  var data = await fmRun('fm-directory', '删除文件', '正在删除 ' + path + ' …', function() {
    return fileApiPost('delete', { host_id: currentHostId(), path: path });
  });
  showToast(data.msg || (data.ok ? '删除成功' : '删除失败'), data.ok ? 'success' : 'error');
  if (data.ok) loadFiles();
}

async function deleteCurrentFile() {
  var path = document.getElementById('fm-edit-path').value;
  if (!path) {
    showToast('请先选择文件', 'warning');
    return;
  }
  await deleteFile(path);
}

async function renamePath(path) {
  if (!FM_CAN_WRITE || !path) return;
  var target = prompt('请输入新的完整路径', path);
  if (!target || target === path) return;
  var data = await fmRun('fm-directory', '重命名路径', '正在将 ' + path + ' 重命名为 ' + target + ' …', function() {
    return fileApiPost('rename', { host_id: currentHostId(), source_path: path, target_path: target });
  });
  showToast(data.msg || (data.ok ? '重命名成功' : '重命名失败'), data.ok ? 'success' : 'error');
  if (data.ok) {
    if (document.getElementById('fm-edit-path').value === path) {
      document.getElementById('fm-edit-path').value = target;
    }
    loadFiles();
  }
}

async function renameSelected() {
  var items = selectedItems();
  if (items.length !== 1) {
    showToast('重命名只能选择一项', 'warning');
    return;
  }
  await renamePath(items[0].path);
}

async function uploadSelectedFile() {
  if (!FM_CAN_WRITE) return;
  var input = document.getElementById('fm-upload-input');
  if (!input.files || !input.files.length) return;
  var file = input.files[0];
  var reader = new FileReader();
  reader.onload = async function(event) {
    var basePath = currentPath() || '/';
    var path = (basePath.replace(/\/$/, '') || '') + '/' + file.name;
    var base64 = arrayBufferToBase64(event.target.result);
    var data = await fmRun('fm-editor', '上传文件', '正在上传 ' + file.name + ' …', function() {
      return fileApiPost('write', { host_id: currentHostId(), path: path, content_base64: base64 });
    });
    showToast(data.msg || (data.ok ? '上传成功' : '上传失败'), data.ok ? 'success' : 'error');
    if (data.ok) {
      document.getElementById('fm-edit-path').value = path;
      FM_CURRENT_BASE64 = base64;
      FM_CURRENT_IS_BINARY = true;
      loadFiles();
      loadCurrentStat();
    }
    input.value = '';
  };
  reader.readAsArrayBuffer(file);
}

function updateClipboardMeta() {
  var el = document.getElementById('fm-clipboard-meta');
  if (!el) return;
  if (!FM_CLIPBOARD || !FM_CLIPBOARD.items || !FM_CLIPBOARD.items.length) {
    el.textContent = '剪贴板为空。';
    return;
  }
  el.textContent = (FM_CLIPBOARD.mode === 'cut' ? '剪切' : '复制') + '中：' + FM_CLIPBOARD.items.length + ' 项，来源目录 ' + (FM_CLIPBOARD.basePath || '/');
}

function copySelected() {
  var paths = selectedPaths();
  if (!paths.length) {
    showToast('请先勾选文件或目录', 'warning');
    return;
  }
  FM_CLIPBOARD = { mode: 'copy', items: paths, hostId: currentHostId(), basePath: currentPath() };
  updateClipboardMeta();
  showToast('已复制到剪贴板', 'success');
}

function cutSelected() {
  var paths = selectedPaths();
  if (!paths.length) {
    showToast('请先勾选文件或目录', 'warning');
    return;
  }
  FM_CLIPBOARD = { mode: 'cut', items: paths, hostId: currentHostId(), basePath: currentPath() };
  updateClipboardMeta();
  showToast('已剪切到剪贴板', 'success');
}

async function pasteClipboard() {
  if (!FM_CAN_WRITE || !FM_CLIPBOARD || !FM_CLIPBOARD.items || !FM_CLIPBOARD.items.length) {
    showToast('剪贴板为空', 'warning');
    return;
  }
  if (FM_CLIPBOARD.hostId !== currentHostId()) {
    showToast('当前版本暂不支持跨主机粘贴', 'warning');
    return;
  }
  var destination = currentPath();
  var results = await fmRun('fm-directory', '粘贴文件', '正在逐项处理剪贴板内容…', async function() {
    var outputs = [];
    for (var i = 0; i < FM_CLIPBOARD.items.length; i += 1) {
      var source = FM_CLIPBOARD.items[i];
      var name = source.split('/').pop() || 'item';
      var targetName = name;
      if (FM_CLIPBOARD.mode === 'copy' && destination.replace(/\/$/, '') === source.split('/').slice(0, -1).join('/')) {
        targetName = 'copy-' + name;
      }
      var target = destination.replace(/\/$/, '') + '/' + targetName;
      var action = FM_CLIPBOARD.mode === 'cut' ? 'move' : 'copy';
      outputs.push(await fileApiPost(action, { host_id: currentHostId(), source_path: source, target_path: target }));
    }
    return { ok: outputs.every(function(item) { return item && item.ok; }), items: outputs, msg: outputs.every(function(item) { return item && item.ok; }) ? '粘贴完成' : '部分项目处理失败' };
  });
  results = results.items || [];
  var failed = results.filter(function(item) { return !item.ok; });
  if (!failed.length && FM_CLIPBOARD.mode === 'cut') {
    FM_CLIPBOARD = null;
  }
  updateClipboardMeta();
  showToast(failed.length ? ('粘贴完成，失败 ' + failed.length + ' 项') : '粘贴完成', failed.length ? 'warning' : 'success');
  loadFiles();
}

async function deleteSelected() {
  if (!FM_CAN_WRITE) return;
  var paths = selectedPaths();
  if (!paths.length) {
    showToast('请先勾选文件或目录', 'warning');
    return;
  }
  if (!confirm('确认批量删除 ' + paths.length + ' 项？')) return;
  var batchDelete = await fmRun('fm-directory', '批量删除', '正在删除 ' + paths.length + ' 个项目…', async function() {
    var failedCount = 0;
    for (var i = 0; i < paths.length; i += 1) {
      var data = await fileApiPost('delete', { host_id: currentHostId(), path: paths[i] });
      if (!data.ok) failedCount += 1;
    }
    return { ok: failedCount === 0, failed: failedCount, msg: failedCount ? ('失败 ' + failedCount + ' 项') : '批量删除完成' };
  });
  var failed = batchDelete.failed || 0;
  showToast(failed ? ('批量删除完成，失败 ' + failed + ' 项') : '批量删除完成', failed ? 'warning' : 'success');
  loadFiles();
}

async function downloadCurrentFile() {
  var path = document.getElementById('fm-edit-path').value;
  if (!path) {
    showToast('请先选择文件', 'warning');
    return;
  }
  var data = await fmRun('fm-editor', '下载文件', '正在读取文件以生成下载…', function() {
    return fileApiPost('read', { host_id: currentHostId(), path: path });
  });
  if (!data.ok) {
    showToast(data.msg || '文件下载失败', 'error');
    return;
  }
  var blob = base64ToBlob(data.content_base64 || '');
  var link = document.createElement('a');
  link.href = URL.createObjectURL(blob);
  link.download = path.split('/').pop() || 'download.bin';
  document.body.appendChild(link);
  link.click();
  setTimeout(function() {
    URL.revokeObjectURL(link.href);
    link.remove();
  }, 1000);
}

async function loadCurrentStat() {
  var seq = ++FM_STAT_SEQ;
  var path = document.getElementById('fm-edit-path').value;
  if (!path) {
    document.getElementById('fm-stat-meta').textContent = '';
    return;
  }
  var data = await fileApiGet('stat', { host_id: currentHostId(), path: path });
  if (seq !== FM_STAT_SEQ || document.getElementById('fm-edit-path').value !== path) {
    return;
  }
  document.getElementById('fm-stat-meta').textContent = data.ok
    ? ('权限 ' + (data.mode || '-') + ' · 属主 ' + (data.owner || '-') + ' · 属组 ' + (data.group || '-') + ' · ' + (data.is_dir ? '目录' : '文件'))
    : (data.msg || '');
}

async function applyFileOp(action) {
  if (!FM_CAN_WRITE) return;
  var paths = selectedPaths();
  if (!paths.length) {
    var singlePath = document.getElementById('fm-edit-path').value || currentPath();
    if (singlePath) paths = [singlePath];
  }
  if (!paths.length) {
    showToast('请先选择文件或目录', 'warning');
    return;
  }
  var labels = { chmod: '权限模式，如 755', chown: '属主，如 root', chgrp: '属组，如 users' };
  var fields = { chmod: 'mode', chown: 'owner', chgrp: 'group' };
  var value = prompt('请输入' + labels[action], '');
  if (!value) return;
  var opResult = await fmRun('fm-directory', '执行文件操作', '正在批量执行 ' + action + ' …', async function() {
    var failedCount = 0;
    for (var i = 0; i < paths.length; i += 1) {
      var payload = { host_id: currentHostId(), path: paths[i] };
      payload[fields[action]] = value;
      var data = await fileApiPost(action, payload);
      if (!data.ok) failedCount += 1;
    }
    return { ok: failedCount === 0, failed: failedCount, msg: failedCount ? ('失败 ' + failedCount + ' 项') : '操作成功' };
  });
  var failed = opResult.failed || 0;
  showToast(failed ? ('操作完成，失败 ' + failed + ' 项') : '操作成功', failed ? 'warning' : 'success');
  loadCurrentStat();
  loadFiles();
}

async function pollTask(taskId, onProgress) {
  while (true) {
    await new Promise(function(resolve) { setTimeout(resolve, 1500); });
    var res = await fetch('host_api.php?action=task_status&task_id=' + encodeURIComponent(taskId), {
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    });
    var status = await res.json();
    if (!status.ok) {
      if (onProgress) onProgress({ status: 'error', error: status.msg });
      return status;
    }
    if (onProgress) onProgress(status);
    if (status.status !== 'pending' && status.status !== 'running') {
      return status.result ? (status.result.ok !== undefined ? status.result : status) : status;
    }
  }
}

async function archiveCurrentPath() {
  if (!FM_CAN_WRITE) return;
  var path = selectedPaths()[0] || document.getElementById('fm-edit-path').value || currentPath();
  if (!path) {
    showToast('请先选择文件或目录', 'warning');
    return;
  }
  var archivePath = prompt('请输入压缩包完整路径', path.replace(/\/$/, '') + '.tar.gz');
  if (!archivePath) return;
  showToast('正在提交压缩任务…', 'info');
  var data = await fileApiPost('archive', { host_id: currentHostId(), path: path, archive_path: archivePath });
  if (data.task_id) {
    showToast('压缩任务已提交，ID: ' + data.task_id, 'info');
    var final = await pollTask(data.task_id, function(status) {
      var output = status.output || '';
      var msg = (status.result && status.result.msg) ? status.result.msg : '';
      showToast('压缩状态: ' + status.status + (msg ? ' | ' + msg : ''), 'info');
    });
    showToast(final.msg || (final.ok ? '压缩完成' : '压缩失败'), final.ok ? 'success' : 'error');
    if (final.ok) loadFiles();
  } else {
    showToast(data.msg || (data.ok ? '压缩成功' : '压缩失败'), data.ok ? 'success' : 'error');
    if (data.ok) loadFiles();
  }
}

async function extractCurrentFile() {
  if (!FM_CAN_WRITE) return;
  var path = selectedPaths()[0] || document.getElementById('fm-edit-path').value;
  if (!path) {
    showToast('请先选择压缩文件', 'warning');
    return;
  }
  var destination = prompt('请输入解压目录', currentPath() || '/');
  if (!destination) return;
  showToast('正在提交解压任务…', 'info');
  var data = await fileApiPost('extract', { host_id: currentHostId(), path: path, destination: destination });
  if (data.task_id) {
    showToast('解压任务已提交，ID: ' + data.task_id, 'info');
    var final = await pollTask(data.task_id, function(status) {
      var output = status.output || '';
      var msg = (status.result && status.result.msg) ? status.result.msg : '';
      showToast('解压状态: ' + status.status + (msg ? ' | ' + msg : ''), 'info');
    });
    showToast(final.msg || (final.ok ? '解压完成' : '解压失败'), final.ok ? 'success' : 'error');
    if (final.ok) loadFiles();
  } else {
    showToast(data.msg || (data.ok ? '解压成功' : '解压失败'), data.ok ? 'success' : 'error');
    if (data.ok) loadFiles();
  }
}

async function downloadFromUrl() {
  if (!FM_CAN_WRITE) return;
  var url = prompt('请输入要下载的 URL');
  if (!url) return;
  var destDir = prompt('请输入保存目录', currentPath() || '/tmp');
  if (!destDir) return;
  var filename = prompt('请输入保存文件名（留空使用 URL 中的文件名）', '');
  showToast('正在提交下载任务…', 'info');
  var form = new URLSearchParams();
  form.append('action', 'download_submit');
  form.append('_csrf', FM_CSRF);
  form.append('url', url);
  form.append('dest_dir', destDir);
  form.append('filename', filename || '');
  var res = await fetch('host_api.php', {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' },
    body: form
  });
  var data = await res.json();
  if (data.task_id) {
    showToast('下载任务已提交，ID: ' + data.task_id, 'info');
    var final = await pollTask(data.task_id, function(status) {
      var output = status.output || '';
      var msg = (status.result && status.result.msg) ? status.result.msg : '';
      showToast('下载状态: ' + status.status + (msg ? ' | ' + msg : ''), 'info');
    });
    showToast(final.msg || (final.ok ? '下载完成' : '下载失败'), final.ok ? 'success' : 'error');
    if (final.ok) loadFiles();
  } else {
    showToast(data.msg || (data.ok ? '下载成功' : '下载失败'), data.ok ? 'success' : 'error');
  }
}

async function saveFavorite() {
  if (!FM_CAN_WRITE) return;
  var path = currentPath();
  var name = prompt('请输入收藏名称', path.split('/').filter(Boolean).slice(-1)[0] || '根目录');
  if (name === null) return;
  var data = await fmRun('fm-directory', '保存收藏', '正在保存目录收藏…', function() {
    return fileApiPost('favorites_save', { host_id: currentHostId(), path: path, name: name });
  });
  showToast(data.msg || (data.ok ? '收藏成功' : '收藏失败'), data.ok ? 'success' : 'error');
  if (data.ok) loadFavorites();
}

async function createWebdavShare() {
  if (!FM_CAN_WEBDAV_MANAGE) {
    showToast('当前用户没有 WebDAV 管理权限', 'warning');
    return;
  }
  if (currentHostId() !== 'local') {
    showToast('当前仅支持为本机目录创建 WebDAV 共享', 'warning');
    return;
  }
  var path = currentPath();
  var username = prompt('请输入 WebDAV 用户名', '');
  if (!username) return;
  var password = prompt('请输入 WebDAV 密码', '');
  if (!password) return;
  var readonly = confirm('是否创建为只读账号？\n选择“确定”=只读，选择“取消”=读写');
  var data = await fmRun('fm-directory', '创建 WebDAV 共享', '正在写入 WebDAV 账号和目录映射…', function() {
    return fileApiPost('webdav_share_create', {
      host_id: currentHostId(),
      path: path,
      username: username,
      password: password,
      readonly: readonly ? '1' : '0'
    });
  });
  showToast(data.msg || (data.ok ? 'WebDAV 共享已创建' : 'WebDAV 共享创建失败'), data.ok ? 'success' : 'error');
  if (data.ok) {
    loadWebdavShares();
  }
}

async function searchFiles() {
  var keyword = (document.getElementById('fm-search').value || '').trim();
  if (!keyword) {
    showToast('请输入搜索关键字', 'warning');
    return;
  }
  var data = await fmRun('fm-directory', '搜索文件', '正在递归搜索匹配文件…', function() {
    return fileApiGet('search', { host_id: currentHostId(), path: currentPath(), keyword: keyword, limit: 200 });
  });
  if (!data.ok) {
    showToast(data.msg || '搜索失败', 'error');
    return;
  }
  FM_ITEMS = data.items || [];
  renderFileRows();
  showToast('搜索完成，共 ' + FM_ITEMS.length + ' 项', 'success');
}

async function deleteFavorite(id) {
  if (!FM_CAN_WRITE || !id) return;
  var data = await fmRun('fm-directory', '删除收藏', '正在删除目录收藏…', function() {
    return fileApiPost('favorites_delete', { id: id });
  });
  showToast(data.msg || (data.ok ? '删除成功' : '删除失败'), data.ok ? 'success' : 'error');
  if (data.ok) loadFavorites();
}

function renderShortcutList(containerId, items, withDelete) {
  var container = document.getElementById(containerId);
  if (!container) return;
  container.innerHTML = '';
  if (!items.length) {
    container.innerHTML = '<div class="form-hint">暂无数据。</div>';
    return;
  }
  items.forEach(function(item) {
    var wrap = document.createElement('div');
    wrap.style.cssText = 'display:flex;gap:8px;align-items:center';
    var button = document.createElement('button');
    button.type = 'button';
    button.className = 'btn btn-secondary fm-jump-btn';
    button.style.cssText = 'flex:1;justify-content:flex-start';
    button.textContent = item.name || item.path || '/';
    button.dataset.hostId = item.host_id || 'local';
    button.dataset.path = item.path || '/';
    wrap.appendChild(button);
    if (withDelete && FM_CAN_WRITE) {
      var del = document.createElement('button');
      del.type = 'button';
      del.className = 'btn btn-sm btn-danger';
      del.textContent = '删';
      del.addEventListener('click', function() {
        deleteFavorite(item.id || '');
      });
      wrap.appendChild(del);
    }
    container.appendChild(wrap);
  });
  bindJumpButtons();
}

async function loadFavorites() {
  var data = await fileApiGet('favorites_list');
  renderShortcutList('fm-favorites', data.items || [], true);
}

async function loadRecent() {
  var data = await fileApiGet('recent_list');
  renderShortcutList('fm-recent', data.items || [], false);
}

function bindJumpButtons() {
  document.querySelectorAll('.fm-jump-btn').forEach(function(btn) {
    btn.onclick = function() {
      document.getElementById('fm-host-select').value = this.dataset.hostId || 'local';
      document.getElementById('fm-path').value = this.dataset.path || '/';
      loadFiles();
    };
  });
}

document.addEventListener('DOMContentLoaded', function() {
  bindJumpButtons();
  updateClipboardMeta();
  document.querySelectorAll('#fm-editor-card .form-actions button').forEach(function(btn) {
    btn.addEventListener('pointerdown', function() {
      var editor = document.getElementById('fm-editor');
      if (editor && typeof editor.blur === 'function') {
        editor.blur();
      }
    });
  });
  function updateWhitelistHint() {
    var hint = document.getElementById('fm-whitelist-hint');
    if (!hint) return;
    hint.style.display = (currentHostId() === 'local' && hint.dataset.hasRoots === '1') ? 'block' : 'none';
  }
  document.getElementById('fm-host-select').addEventListener('change', function() {
    document.getElementById('fm-path').value = '/';
    updateWhitelistHint();
    loadFiles();
  });
  updateWhitelistHint();
  document.getElementById('fm-filter').addEventListener('input', renderFileRows);
  document.getElementById('fm-check-all').addEventListener('change', function() {
    document.querySelectorAll('.fm-item-check').forEach(function(input) {
      input.checked = !!document.getElementById('fm-check-all').checked;
    });
  });
  document.getElementById('fm-path').addEventListener('keydown', function(e) {
    if (e.key === 'Enter') {
      e.preventDefault();
      loadFiles();
    }
  });
  document.getElementById('fm-search').addEventListener('keydown', function(e) {
    if (e.key === 'Enter') {
      e.preventDefault();
      searchFiles();
    }
  });
  loadFiles();
  loadFavorites();
  loadRecent();
});

function toggleTrashView() {
  var panel = document.getElementById('fm-trash-panel');
  if (panel.style.display === 'none') {
    panel.style.display = 'block';
    loadTrash();
  } else {
    panel.style.display = 'none';
  }
}

async function loadTrash() {
  var tbody = document.querySelector('#fm-trash-table tbody');
  var emptyMsg = document.getElementById('fm-trash-empty');
  tbody.innerHTML = '<tr><td colspan="4" style="text-align:center;color:var(--tm)">加载中…</td></tr>';
  emptyMsg.style.display = 'none';
  var data = await fileApiGet('trash_list');
  if (!data.ok || !data.data || !data.data.items) {
    tbody.innerHTML = '<tr><td colspan="4" style="text-align:center;color:var(--tm)">加载失败</td></tr>';
    return;
  }
  var items = data.data.items;
  if (!items.length) {
    tbody.innerHTML = '';
    emptyMsg.style.display = 'block';
    return;
  }
  emptyMsg.style.display = 'none';
  tbody.innerHTML = items.map(function(item) {
    return '<tr>'
      + '<td style="font-family:var(--mono);font-size:12px">' + escapeHtml(item.original_path || '') + '</td>'
      + '<td>' + escapeHtml(item.deleted_at || '') + '</td>'
      + '<td>' + escapeHtml(item.operator || '') + '</td>'
      + '<td>'
      + '<button type="button" class="btn btn-primary" style="font-size:12px;padding:4px 8px" onclick="restoreTrashItem(\'' + escapeHtml(item.entry_id) + '\')">恢复</button> '
      + '<button type="button" class="btn btn-danger" style="font-size:12px;padding:4px 8px" onclick="deleteTrashItem(\'' + escapeHtml(item.entry_id) + '\')">永久删除</button>'
      + '</td>'
      + '</tr>';
  }).join('');
}

async function restoreTrashItem(entryId) {
  if (!confirm('确定要恢复该文件/目录到原始位置吗？')) return;
  var data = await fileApiPost('trash_restore', { entry_id: entryId });
  showToast(data.msg || (data.ok ? '恢复成功' : '恢复失败'), data.ok ? 'success' : 'error');
  if (data.ok) {
    loadTrash();
    loadFiles();
  }
}

async function deleteTrashItem(entryId) {
  if (!confirm('确定要永久删除吗？此操作不可恢复。')) return;
  var data = await fileApiPost('trash_delete', { entry_id: entryId });
  showToast(data.msg || (data.ok ? '已永久删除' : '删除失败'), data.ok ? 'success' : 'error');
  if (data.ok) loadTrash();
}

async function autoCleanTrash() {
  if (!confirm('确定要清理回收站中超过30天的条目吗？')) return;
  var data = await fileApiPost('trash_auto_clean', {});
  showToast(data.msg || (data.ok ? '清理完成' : '清理失败'), data.ok ? 'success' : 'error');
  if (data.ok) loadTrash();
}

function openImageLightbox() {
  var src = document.getElementById('fm-preview-image').src;
  if (!src) return;
  var box = document.getElementById('fm-lightbox');
  var img = document.getElementById('fm-lightbox-image');
  img.src = src;
  box.style.display = 'flex';
}

function closeImageLightbox() {
  document.getElementById('fm-lightbox').style.display = 'none';
}

document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') {
    closeImageLightbox();
  }
});
</script>

<?php require_once __DIR__ . '/shared/footer.php'; ?>
