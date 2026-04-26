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

// 主机列表用于配置弹窗
$hostList = [['id' => 'local', 'name' => '本机']];
foreach ($remoteHosts as $host) {
    $hostList[] = ['id' => (string)($host['id'] ?? ''), 'name' => (string)($host['name'] ?? '')];
}
?>

<style>
/* ── 文件系统紧凑布局 ── */
.fm-page { display: flex; flex-direction: column; gap: 4px; }
.fm-page > .card { margin-bottom: 0; padding: 10px 14px; }

/* 导航条 */
.fm-nav-bar {
  display: flex; align-items: center; gap: 6px; flex-wrap: wrap;
}
.fm-nav-bar .btn {
  padding: 4px 10px; font-size: 12px; min-height: 28px; line-height: 1;
}
.fm-breadcrumbs {
  display: flex; align-items: center; gap: 4px; flex: 1;
  font-family: var(--mono); font-size: 13px; min-width: 200px; flex-wrap: wrap;
}
.fm-breadcrumbs .fm-crumb {
  color: var(--blue); cursor: pointer; padding: 2px 6px; border-radius: 4px;
  background: none; border: none; font-family: var(--mono); font-size: 13px;
}
.fm-breadcrumbs .fm-crumb:hover { background: var(--ac-dim); }
.fm-breadcrumbs .fm-crumb.active { color: var(--tx); cursor: default; }
.fm-breadcrumbs .fm-sep { color: var(--tm); user-select: none; }

/* 工具栏 */
.fm-toolbar {
  display: flex; align-items: center; gap: 6px; flex-wrap: wrap;
  padding: 4px 0;
}
.fm-toolbar .btn {
  padding: 4px 10px; font-size: 12px; min-height: 28px; line-height: 1;
}
.fm-toolbar .btn:disabled {
  opacity: 0.35; cursor: not-allowed;
}
.fm-selection-count {
  font-size: 12px; color: var(--tm); margin-left: auto; margin-right: 8px;
}
.fm-selection-count.has-selection { color: var(--ac); font-weight: 600; }
.fm-search-wrap { display: flex; align-items: center; gap: 6px; }
.fm-search-wrap input {
  width: 200px; padding: 4px 10px; font-size: 12px; min-height: 28px;
}

/* 文件表格紧凑 */
#fm-table th, #fm-table td {
  padding: 6px 10px; font-size: 13px;
}
#fm-table th {
  font-weight: 600; color: var(--tx2); background: var(--sf2);
  white-space: nowrap; cursor: pointer; user-select: none;
}
#fm-table th:hover { color: var(--tx); }
#fm-table td { border-bottom: 1px solid var(--bd); }
#fm-table tr:hover td { background: rgba(0,212,170,.04); }
#fm-table .fm-name { color: var(--tx); font-family: var(--mono); font-size: 12px; }
#fm-table .fm-name.is-dir { color: var(--yellow); }
#fm-table .fm-perm {
  font-family: var(--mono); font-size: 12px; color: var(--tx2); cursor: help;
}
#fm-table .fm-user, #fm-table .fm-group {
  font-family: var(--mono); font-size: 12px; color: var(--tx2);
}
#fm-table .fm-size {
  font-family: var(--mono); font-size: 12px; color: var(--tx2); text-align: right;
}
#fm-table .fm-size-calc {
  color: var(--blue); cursor: pointer;
}
#fm-table .fm-size-calc:hover { text-decoration: underline; }
#fm-table .fm-mtime {
  font-family: var(--mono); font-size: 12px; color: var(--tm); white-space: nowrap;
}

/* 操作列 */
.fm-actions { display: flex; gap: 4px; align-items: center; white-space: nowrap; }
.fm-actions .btn { padding: 3px 8px; font-size: 11px; min-height: 22px; line-height: 1; }

/* 更多下拉菜单 */
.fm-more-menu {
  position: absolute; background: var(--sf2); border: 1px solid var(--bd2);
  border-radius: var(--r); box-shadow: 0 8px 24px rgba(0,0,0,.45);
  min-width: 140px; z-index: 50; display: none;
}
.fm-more-menu.open { display: block; }
.fm-more-menu button {
  display: block; width: 100%; text-align: left; padding: 7px 14px;
  background: none; border: none; color: var(--tx); cursor: pointer;
  font-size: 13px; font-family: var(--fn);
}
.fm-more-menu button:hover { background: var(--sf3); }
.fm-more-menu button.danger { color: var(--red); }
.fm-more-menu .fm-menu-sep { border: none; border-top: 1px solid var(--bd); margin: 4px 8px; opacity: .4; }

/* 分页 */
.fm-pagination {
  display: flex; align-items: center; justify-content: flex-end;
  gap: 8px; padding: 8px 0 0; font-size: 12px; color: var(--tm);
}
.fm-pagination .btn { padding: 3px 10px; font-size: 12px; min-height: 26px; }
.fm-pagination input {
  width: 48px; text-align: center; padding: 3px 6px;
  font-size: 12px; min-height: 26px;
}

/* 回收站面板 */
#fm-trash-panel { margin-top: 8px; }
#fm-trash-panel .card { padding: 10px 14px; }
#fm-trash-table th, #fm-trash-table td { padding: 5px 10px; font-size: 12px; }

/* 状态提示 */
#fm-directory-status, #fm-editor-status, #fm-whitelist-hint {
  margin-bottom: 8px;
}

/* 配置弹窗 */
#fm-config-modal .ngx-modal-card { max-width: 720px; height: auto; max-height: 88vh; }
#fm-config-modal .ngx-modal-body { flex-direction: row; gap: 20px; overflow: auto; }
.fm-config-hosts { flex: 0 0 160px; }
.fm-config-hosts .fm-host-item {
  display: flex; align-items: center; gap: 6px; padding: 6px 8px;
  border-radius: 6px; cursor: pointer; font-size: 13px; color: var(--tx);
}
.fm-config-hosts .fm-host-item:hover { background: var(--sf3); }
.fm-config-hosts .fm-host-item.active {
  background: var(--ac-dim); color: var(--ac); font-weight: 600;
}
.fm-config-hosts .fm-host-item input { margin: 0; }
.fm-config-right { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 14px; }
.fm-config-section-title {
  font-size: 12px; font-weight: 600; color: var(--tx2); margin-bottom: 6px;
}
.fm-config-list {
  display: flex; flex-direction: column; gap: 4px;
}
.fm-config-list-item {
  display: flex; align-items: center; gap: 6px; padding: 5px 8px;
  border-radius: 6px; cursor: pointer; font-size: 13px;
}
.fm-config-list-item:hover { background: var(--sf3); }
.fm-config-list-item .fm-item-icon { width: 18px; text-align: center; flex-shrink: 0; }
.fm-config-list-item .fm-item-name { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.fm-config-list-item .fm-item-path { color: var(--tm); font-family: var(--mono); font-size: 11px; margin-left: auto; flex-shrink: 0; }
.fm-config-list-item .fm-item-del {
  background: none; border: none; color: var(--red); cursor: pointer;
  font-size: 16px; line-height: 1; padding: 0 2px; opacity: 0.6;
}
.fm-config-list-item .fm-item-del:hover { opacity: 1; }

/* 编辑器弹窗内图片预览 */
#fm-editor-image-wrap {
  display: none; flex: 1 1 auto; min-height: 0;
  align-items: center; justify-content: center; overflow: auto;
  border: 1px solid var(--bd2); border-radius: 8px; background: var(--bg);
}
#fm-editor-image-wrap img { max-width: 95%; max-height: 85vh; border-radius: 8px; box-shadow: 0 8px 32px rgba(0,0,0,.4); }

/* 图片灯箱 */
#fm-lightbox { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,.85); z-index: 10000; align-items: center; justify-content: center; cursor: zoom-out; }

/* 响应式 */
@media (max-width: 900px) {
  .fm-toolbar .btn span { display: none; }
  .fm-search-wrap input { width: 140px; }
  #fm-table .fm-user, #fm-table .fm-group { display: none; }
}
@media (max-width: 640px) {
  #fm-table .fm-perm, #fm-table .fm-mtime { display: none; }
  .fm-config-hosts { flex: 0 0 120px; }
}
</style>

<div class="fm-page">
  <!-- 导航条 -->
  <div class="card">
    <div class="fm-nav-bar">
      <button type="button" class="btn btn-secondary" onclick="historyBack()" title="后退">←</button>
      <button type="button" class="btn btn-secondary" onclick="historyForward()" title="前进">→</button>
      <button type="button" class="btn btn-secondary" onclick="goParentDir()" title="上级">↑</button>
      <button type="button" class="btn btn-secondary" onclick="loadFiles()" title="刷新">⟳</button>
      <div class="fm-breadcrumbs" id="fm-breadcrumbs"></div>
    </div>
  </div>

  <!-- 工具栏 -->
  <div class="card">
    <div class="fm-toolbar">
      <button type="button" class="btn btn-primary" id="fm-btn-create">创建▼</button>
      <input type="file" id="fm-upload-input" style="display:none" onchange="uploadSelectedFile()">
      <?php if ($canWrite): ?><button type="button" class="btn btn-secondary" onclick="document.getElementById('fm-upload-input').click()">上传</button><?php endif; ?>
      <?php if ($canWrite): ?><button type="button" class="btn btn-secondary" onclick="downloadFromUrl()">远程下载</button><?php endif; ?>
      <button type="button" class="btn btn-secondary" id="fm-btn-copy" disabled>复制</button>
      <button type="button" class="btn btn-secondary" id="fm-btn-move" disabled>移动</button>
      <?php if ($canWrite): ?><button type="button" class="btn btn-secondary" onclick="archiveCurrentPath()">压缩</button><?php endif; ?>
      <button type="button" class="btn btn-secondary" id="fm-btn-perm" disabled>编辑权限</button>
      <button type="button" class="btn btn-danger" id="fm-btn-delete" disabled>删除</button>
      <button type="button" class="btn btn-secondary" onclick="toggleTrashView()">回收站</button>
      <span class="fm-selection-count" id="fm-selection-count"></span>
      <button type="button" class="btn btn-secondary" onclick="openFmConfigModal()">⚙️ 配置</button>
      <div class="fm-search-wrap">
        <input type="text" id="fm-search" placeholder="在当前目录下查找" onkeydown="if(event.key==='Enter')searchFiles()">
        <button type="button" class="btn btn-secondary" onclick="searchFiles()">🔍</button>
      </div>
    </div>

    <!-- 创建下拉菜单 -->
    <div class="fm-more-menu" id="fm-create-menu" style="margin-top:2px">
      <?php if ($canWrite): ?><button type="button" onclick="createFolder()">📁 新建目录</button><?php endif; ?>
      <?php if ($canWrite): ?><button type="button" onclick="createFile()">📄 新建文件</button><?php endif; ?>
      <button type="button" onclick="saveFavorite()">⭐ 收藏当前目录</button>
      <?php if ($canWebdavManage): ?><button type="button" onclick="createWebdavShare()">🔗 创建 WebDAV 共享</button><?php endif; ?>
      <?php if ($canAudit): ?><button type="button" onclick="location.href='file_audit.php'">📋 文件审计</button><?php endif; ?>
    </div>

    <!-- 白名单提示 -->
    <div id="fm-whitelist-hint" class="form-hint" data-has-roots="<?= !empty($fsAllowedRoots) ? '1' : '0' ?>" style="display:none;margin-top:8px;padding:6px 10px;border:1px dashed var(--bd);border-radius:8px;background:var(--bg);color:var(--tm);font-size:12px">
      <b>白名单限制</b>：当前仅允许访问以下路径前缀 — <?= implode('、', array_map(function($r){ return '<code style="background:var(--sf);padding:1px 4px;border-radius:3px">' . htmlspecialchars($r) . '</code>'; }, $fsAllowedRoots)) ?>
    </div>

    <!-- 回收站面板 -->
    <div id="fm-trash-panel" style="display:none">
      <div class="card" style="margin-top:8px">
        <div class="card-title" style="display:flex;justify-content:space-between;align-items:center;font-size:13px">
          <span>🗑 回收站</span>
          <div style="display:flex;gap:6px">
            <button type="button" class="btn btn-secondary" onclick="loadTrash()" style="font-size:11px;padding:4px 8px">刷新</button>
            <button type="button" class="btn btn-danger" onclick="autoCleanTrash()" style="font-size:11px;padding:4px 8px">清理30天前</button>
            <button type="button" class="btn btn-secondary" onclick="toggleTrashView()" style="font-size:11px;padding:4px 8px">关闭</button>
          </div>
        </div>
        <div class="table-wrap" style="margin-top:8px">
          <table id="fm-trash-table">
            <thead><tr><th>原始路径</th><th>删除时间</th><th>操作人</th><th>操作</th></tr></thead>
            <tbody></tbody>
          </table>
        </div>
        <div id="fm-trash-empty" class="form-hint" style="display:none;margin-top:8px">回收站为空</div>
      </div>
    </div>

    <!-- 目录状态 -->
    <div id="fm-directory-status" style="display:none;margin-top:8px;padding:10px 12px;border:1px solid var(--bd);border-radius:10px;background:var(--bg)"></div>

    <!-- WebDAV 共享提示 -->
    <div id="fm-webdav-shares" class="form-hint" style="display:none;margin-top:8px;padding:8px 10px;border:1px dashed var(--bd);border-radius:10px;background:var(--bg);font-size:12px"></div>

    <!-- 文件列表 -->
    <div class="table-wrap" style="margin-top:8px">
      <table id="fm-table">
        <thead>
          <tr>
            <th style="width:32px"><input type="checkbox" id="fm-check-all"></th>
            <th onclick="sortTable('name')">名称 <span id="sort-name"></span></th>
            <th style="width:70px" onclick="sortTable('perm')">权限 <span id="sort-perm"></span></th>
            <th style="width:100px" onclick="sortTable('owner')">用户 <span id="sort-owner"></span></th>
            <th style="width:100px" onclick="sortTable('group')">用户组 <span id="sort-group"></span></th>
            <th style="width:80px" onclick="sortTable('size')">大小 <span id="sort-size"></span></th>
            <th style="width:150px" onclick="sortTable('mtime')">修改时间 <span id="sort-mtime"></span></th>
            <th style="width:140px">操作</th>
          </tr>
        </thead>
        <tbody></tbody>
      </table>
    </div>

    <!-- 分页 -->
    <div class="fm-pagination" id="fm-pagination">
      <span id="fm-page-info">共 0 条</span>
      <button type="button" class="btn btn-secondary" id="fm-page-prev" disabled>&lt;</button>
      <span id="fm-page-current">1</span> / <span id="fm-page-total">1</span>
      <button type="button" class="btn btn-secondary" id="fm-page-next" disabled>&gt;</button>
      <input type="number" id="fm-page-input" min="1" value="1" onkeydown="if(event.key==='Enter')goPage(this.value)">
      <select id="fm-page-size" onchange="changePageSize()" style="padding:3px 6px;font-size:12px;background:var(--sf);border:1px solid var(--bd);border-radius:6px;color:var(--tx)">
        <option value="50">50条/页</option>
        <option value="100" selected>100条/页</option>
        <option value="200">200条/页</option>
      </select>
    </div>
  </div>
</div>

<!-- 图片灯箱 -->
<div id="fm-lightbox" onclick="closeImageLightbox()">
  <img id="fm-lightbox-image" alt="放大预览" style="max-width:90vw;max-height:90vh;border-radius:10px;box-shadow:0 20px 60px rgba(0,0,0,.5)">
</div>

<!-- ========== 配置弹窗 ========== -->
<div id="fm-config-modal" class="ngx-modal" onclick="if(event.target===this)closeFmConfigModal()">
  <div class="ngx-modal-card">
    <div class="ngx-modal-head">
      <div class="ngx-modal-title">文件系统配置</div>
      <button type="button" class="btn btn-secondary ngx-close-btn" onclick="closeFmConfigModal()">×</button>
    </div>
    <div class="ngx-modal-body">
      <!-- 左侧：主机选择 -->
      <div class="fm-config-hosts">
        <div class="fm-config-section-title">目标主机</div>
        <div id="fm-config-hosts-list">
          <?php foreach ($hostList as $h): ?>
          <label class="fm-host-item" data-host-id="<?= htmlspecialchars($h['id']) ?>">
            <input type="radio" name="fm_config_host" value="<?= htmlspecialchars($h['id']) ?>" <?= $selectedHostId === $h['id'] ? 'checked' : '' ?>>
            <span><?= htmlspecialchars($h['name']) ?></span>
          </label>
          <?php endforeach; ?>
        </div>
      </div>
      <!-- 右侧：快捷/收藏/最近 -->
      <div class="fm-config-right">
        <div>
          <div class="fm-config-section-title">快捷入口</div>
          <div class="fm-config-list" id="fm-config-quick">
            <?php foreach ($quickPaths as $item): ?>
            <div class="fm-config-list-item" data-host-id="<?= htmlspecialchars((string)($item['host_id'] ?? 'local')) ?>" data-path="<?= htmlspecialchars((string)($item['path'] ?? '/')) ?>">
              <span class="fm-item-icon">📁</span>
              <span class="fm-item-name"><?= htmlspecialchars((string)($item['name'] ?? '')) ?></span>
              <span class="fm-item-path"><?= htmlspecialchars((string)($item['path'] ?? '/')) ?></span>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
        <div>
          <div class="fm-config-section-title">收藏目录</div>
          <div class="fm-config-list" id="fm-config-favorites">
            <?php if (empty($favorites)): ?>
            <div class="form-hint" style="font-size:12px">还没有收藏目录。</div>
            <?php else: ?>
            <?php foreach ($favorites as $item): ?>
            <div class="fm-config-list-item" data-host-id="<?= htmlspecialchars((string)($item['host_id'] ?? 'local')) ?>" data-path="<?= htmlspecialchars((string)($item['path'] ?? '/')) ?>">
              <span class="fm-item-icon">⭐</span>
              <span class="fm-item-name"><?= htmlspecialchars((string)($item['name'] ?? '')) ?></span>
              <?php if ($canWrite): ?><button type="button" class="fm-item-del" onclick="deleteFavorite('<?= htmlspecialchars((string)($item['id'] ?? '')) ?>')">×</button><?php endif; ?>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>
        <div>
          <div class="fm-config-section-title">最近访问</div>
          <div class="fm-config-list" id="fm-config-recent">
            <?php if (empty($recentItems)): ?>
            <div class="form-hint" style="font-size:12px">暂无最近访问。</div>
            <?php else: ?>
            <?php foreach ($recentItems as $item): ?>
            <div class="fm-config-list-item" data-host-id="<?= htmlspecialchars((string)($item['host_id'] ?? 'local')) ?>" data-path="<?= htmlspecialchars((string)($item['path'] ?? '/')) ?>">
              <span class="fm-item-icon">🕐</span>
              <span class="fm-item-name"><?= htmlspecialchars((string)($item['name'] ?? '')) ?></span>
              <span class="fm-item-path"><?= htmlspecialchars((string)($item['path'] ?? '/')) ?></span>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
    <div class="ngx-editor-actions" style="padding:10px 14px;border-top:1px solid var(--bd)">
      <span></span>
      <div style="display:flex;gap:8px">
        <button type="button" class="btn btn-secondary" onclick="closeFmConfigModal()">取消</button>
        <button type="button" class="btn btn-primary" onclick="confirmHostSwitch()">确认切换</button>
      </div>
    </div>
  </div>
</div>

<!-- ========== 图片预览弹窗 ========== -->
<div id="fm-editor-modal" class="ngx-modal" onclick="if(event.target===this)closeFmEditorModal()">
  <div class="ngx-modal-card">
    <div class="ngx-modal-head">
      <div class="ngx-modal-title" id="fm-editor-title">图片预览</div>
      <button type="button" class="btn btn-secondary ngx-close-btn" onclick="closeFmEditorModal()">×</button>
    </div>
    <div class="ngx-modal-body">
      <div id="fm-editor-image-wrap">
        <img id="fm-editor-image" alt="图片预览">
      </div>
      <div class="ngx-editor-actions">
        <div class="ngx-editor-actions-left"></div>
        <div class="ngx-editor-actions-right">
          <button type="button" class="btn btn-secondary" onclick="closeFmEditorModal()">关闭</button>
          <button type="button" class="btn btn-secondary" onclick="downloadCurrentFile()">下载</button>
          <?php if ($canWrite): ?><button type="button" class="btn btn-danger" onclick="deleteCurrentFile()">删除</button><?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<script src="assets/ace/ace.js"></script>
<script src="assets/ace/ext-searchbox.js"></script>
<?php require_once __DIR__ . '/shared/ace_editor_modal.php'; ?>
<script>
var FM_CSRF = <?= json_encode($csrfValue) ?>;
var FM_CAN_WRITE = <?= $canWrite ? 'true' : 'false' ?>;
var FM_CAN_WEBDAV_MANAGE = <?= $canWebdavManage ? 'true' : 'false' ?>;
var FM_ITEMS = [];
var FM_CLIPBOARD = null;
var FM_LOAD_SEQ = 0;
var FM_READ_SEQ = 0;
var FM_HISTORY = [];
var FM_HISTORY_IDX = -1;
var FM_SORT = { col: 'name', asc: true };
var FM_PAGE = { current: 1, size: 100, total: 1 };
var FM_SELECTED_HOST = <?= json_encode($selectedHostId) ?>;
var FM_SELECTED_PATH = <?= json_encode($selectedPath) ?>;

var FM_STATUS = navCreateAsyncStatus({
  getRefs: function(scope) {
    return { wrap: document.getElementById(scope + '-status') };
  }
});

// NavAceEditor 统一弹窗已接管文本编辑，参见 admin/shared/ace_editor_modal.php

function fmStatusRefs(scope) {
  return { wrap: document.getElementById(scope + '-status') };
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

function currentHostId() { return FM_SELECTED_HOST; }
function currentPath() { return FM_SELECTED_PATH; }

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

/* ── 历史栈 ── */
function pushHistory(hostId, path) {
  // 如果当前不是栈顶，截断
  if (FM_HISTORY_IDX < FM_HISTORY.length - 1) {
    FM_HISTORY = FM_HISTORY.slice(0, FM_HISTORY_IDX + 1);
  }
  // 避免连续重复
  var last = FM_HISTORY[FM_HISTORY.length - 1];
  if (last && last.hostId === hostId && last.path === path) return;
  FM_HISTORY.push({ hostId: hostId, path: path });
  if (FM_HISTORY.length > 100) FM_HISTORY.shift();
  FM_HISTORY_IDX = FM_HISTORY.length - 1;
}

function historyBack() {
  if (FM_HISTORY_IDX <= 0) return;
  FM_HISTORY_IDX -= 1;
  var item = FM_HISTORY[FM_HISTORY_IDX];
  if (item) {
    FM_SELECTED_HOST = item.hostId;
    FM_SELECTED_PATH = item.path;
    loadFiles(false);
  }
}

function historyForward() {
  if (FM_HISTORY_IDX >= FM_HISTORY.length - 1) return;
  FM_HISTORY_IDX += 1;
  var item = FM_HISTORY[FM_HISTORY_IDX];
  if (item) {
    FM_SELECTED_HOST = item.hostId;
    FM_SELECTED_PATH = item.path;
    loadFiles(false);
  }
}

/* ── 面包屑 ── */
function renderBreadcrumbs(path) {
  var el = document.getElementById('fm-breadcrumbs');
  if (!el) return;
  var normalized = String(path || '/');
  if (!normalized.startsWith('/')) normalized = '/' + normalized;
  var parts = normalized.split('/').filter(Boolean);
  var html = '<button type="button" class="fm-crumb" data-path="/">🏠</button>';
  var current = '';
  parts.forEach(function(part, idx) {
    current += '/' + part;
    var isLast = idx === parts.length - 1;
    html += ' <span class="fm-sep">/</span> ';
    if (isLast) {
      html += '<span class="fm-crumb active">' + escapeHtml(part) + '</span>';
    } else {
      html += '<button type="button" class="fm-crumb" data-path="' + escapeHtml(current) + '">' + escapeHtml(part) + '</button>';
    }
  });
  el.innerHTML = html;
  el.querySelectorAll('.fm-crumb[data-path]').forEach(function(btn) {
    btn.addEventListener('click', function() {
      FM_SELECTED_PATH = this.getAttribute('data-path');
      loadFiles();
    });
  });
}

/* ── 分页 ── */
function renderPagination() {
  var totalItems = FM_ITEMS.length;
  FM_PAGE.total = Math.max(1, Math.ceil(totalItems / FM_PAGE.size));
  if (FM_PAGE.current > FM_PAGE.total) FM_PAGE.current = FM_PAGE.total;
  if (FM_PAGE.current < 1) FM_PAGE.current = 1;
  document.getElementById('fm-page-info').textContent = '共 ' + totalItems + ' 条';
  document.getElementById('fm-page-current').textContent = FM_PAGE.current;
  document.getElementById('fm-page-total').textContent = FM_PAGE.total;
  document.getElementById('fm-page-input').value = FM_PAGE.current;
  document.getElementById('fm-page-prev').disabled = FM_PAGE.current <= 1;
  document.getElementById('fm-page-next').disabled = FM_PAGE.current >= FM_PAGE.total;
}

document.getElementById('fm-page-prev').addEventListener('click', function() {
  if (FM_PAGE.current > 1) { FM_PAGE.current--; renderFileRows(); }
});
document.getElementById('fm-page-next').addEventListener('click', function() {
  if (FM_PAGE.current < FM_PAGE.total) { FM_PAGE.current++; renderFileRows(); }
});
function goPage(n) {
  var p = parseInt(n, 10);
  if (isNaN(p)) return;
  FM_PAGE.current = Math.max(1, Math.min(FM_PAGE.total, p));
  renderFileRows();
}
function changePageSize() {
  FM_PAGE.size = parseInt(document.getElementById('fm-page-size').value, 10) || 100;
  FM_PAGE.current = 1;
  renderFileRows();
}

/* ── 排序 ── */
function sortTable(col) {
  if (FM_SORT.col === col) {
    FM_SORT.asc = !FM_SORT.asc;
  } else {
    FM_SORT.col = col;
    FM_SORT.asc = true;
  }
  // 更新表头箭头
  ['name','perm','owner','group','size','mtime'].forEach(function(c) {
    var el = document.getElementById('sort-' + c);
    if (!el) return;
    el.textContent = (FM_SORT.col === c) ? (FM_SORT.asc ? '▲' : '▼') : '';
  });
  // 排序
  FM_ITEMS.sort(function(a, b) {
    var av = a[col] || '';
    var bv = b[col] || '';
    if (col === 'size') {
      av = parseInt(a.size || 0, 10);
      bv = parseInt(b.size || 0, 10);
    }
    if (av < bv) return FM_SORT.asc ? -1 : 1;
    if (av > bv) return FM_SORT.asc ? 1 : -1;
    return 0;
  });
  FM_PAGE.current = 1;
  renderFileRows();
}

/* ── 文件大小格式化 ── */
function formatSize(bytes) {
  var n = parseInt(bytes, 10);
  if (isNaN(n) || n < 0) return '-';
  if (n === 0) return '0 B';
  var units = ['B','KB','MB','GB','TB'];
  var i = 0;
  while (n >= 1024 && i < units.length - 1) { n /= 1024; i++; }
  return n.toFixed(i === 0 ? 0 : 2) + ' ' + units[i];
}

/* ── 权限格式化 ── */
function formatPerm(mode, type) {
  if (!mode) return '-';
  var oct = String(mode);
  var rwx = '';
  if (type === 'dir') rwx = 'd';
  else if (type === 'link') rwx = 'l';
  else rwx = '-';
  // 简单转换：八进制三位转 rwx
  var map = ['---','--x','-w-','-wx','r--','r-x','rw-','rwx'];
  for (var i = 0; i < 3 && i < oct.length; i++) {
    var d = parseInt(oct[i], 10);
    rwx += map[d] || '---';
  }
  return rwx;
}

/* ── 渲染文件列表 ── */
function renderFileRows() {
  var tbody = document.querySelector('#fm-table tbody');
  tbody.innerHTML = '';
  var start = (FM_PAGE.current - 1) * FM_PAGE.size;
  var pageItems = FM_ITEMS.slice(start, start + FM_PAGE.size);

  pageItems.forEach(function(item) {
    var tr = document.createElement('tr');
    var isDir = item.type === 'dir';
    var isArchive = item.type === 'file' && /\.(tar\.gz|tgz|tar\.bz2|tbz2|tar\.xz|txz|tar\.lz|tlz|tar\.zst|tar|zip|7z|rar)$/i.test(item.name || '');
    var permRwx = formatPerm(item.mode, item.type);

    var actions = '';
    if (isDir) {
      actions += '<button type="button" class="btn btn-sm btn-secondary" data-open="' + escapeHtml(item.path) + '">进入</button>';
    } else {
      actions += '<button type="button" class="btn btn-sm btn-secondary" data-read="' + escapeHtml(item.path) + '">编辑</button>';
    }
    actions += ' <button type="button" class="btn btn-sm btn-secondary" data-dl="' + escapeHtml(item.path) + '">下载</button>';
    if (isArchive) {
      actions += ' <button type="button" class="btn btn-sm btn-secondary" data-extract="' + escapeHtml(item.path) + '">解压</button>';
    }
    actions += ' <button type="button" class="btn btn-sm btn-secondary fm-more-btn" data-path="' + escapeHtml(item.path) + '" data-type="' + escapeHtml(item.type) + '">更多▼</button>';

    var sizeHtml = isDir
      ? '<span class="fm-size-calc" data-path="' + escapeHtml(item.path) + '">计算</span>'
      : formatSize(item.size);

    tr.innerHTML =
      '<td><input type="checkbox" class="fm-item-check" value="' + escapeHtml(item.path) + '"></td>'
      + '<td class="fm-name ' + (isDir ? 'is-dir' : '') + '">' + (isDir ? '📁' : '📄') + ' ' + escapeHtml(item.name) + '</td>'
      + '<td class="fm-perm" title="' + escapeHtml(permRwx) + '">' + escapeHtml(item.mode || '-') + '</td>'
      + '<td class="fm-user">' + escapeHtml(item.owner || '-') + '</td>'
      + '<td class="fm-group">' + escapeHtml(item.group || '-') + '</td>'
      + '<td class="fm-size">' + sizeHtml + '</td>'
      + '<td class="fm-mtime">' + escapeHtml(item.mtime || '') + '</td>'
      + '<td><div class="fm-actions">' + actions + '</div></td>';
    tbody.appendChild(tr);
  });

  bindRowEvents();
  renderPagination();
  updateSelectionUI();
}

function bindRowEvents() {
  document.querySelectorAll('#fm-table [data-open]').forEach(function(btn) {
    btn.addEventListener('click', function() {
      FM_SELECTED_PATH = this.getAttribute('data-open');
      loadFiles();
    });
  });
  document.querySelectorAll('#fm-table [data-read]').forEach(function(btn) {
    btn.addEventListener('click', function() {
      openFileEditor(this.getAttribute('data-read'));
    });
  });
  document.querySelectorAll('#fm-table [data-dl]').forEach(function(btn) {
    btn.addEventListener('click', function() {
      downloadFile(this.getAttribute('data-dl'));
    });
  });
  // data-delete / data-rename 已移入"更多"下拉菜单，无需在此绑定
  document.querySelectorAll('#fm-table [data-extract]').forEach(function(btn) {
    btn.addEventListener('click', function() {
      extractArchive(this.getAttribute('data-extract'));
    });
  });
  document.querySelectorAll('#fm-table .fm-more-btn').forEach(function(btn) {
    btn.addEventListener('click', function(e) {
      e.stopPropagation();
      openRowMoreMenu(this, this.getAttribute('data-path'), this.getAttribute('data-type'));
    });
  });
  document.querySelectorAll('#fm-table .fm-size-calc').forEach(function(el) {
    el.addEventListener('click', function() {
      calcDirSize(this, this.getAttribute('data-path'));
    });
  });
  document.querySelectorAll('.fm-item-check').forEach(function(cb) {
    cb.addEventListener('change', updateSelectionUI);
  });
}

/* ── 更多菜单 ── */
var FM_MORE_MENU_TARGET = null;
function openRowMoreMenu(btn, path, type) {
  closeAllMenus();
  var menu = document.getElementById('fm-row-more-menu');
  if (!menu) {
    menu = document.createElement('div');
    menu.id = 'fm-row-more-menu';
    menu.className = 'fm-more-menu';
    document.body.appendChild(menu);
  }
  FM_MORE_MENU_TARGET = path;
  var html = '';
  if (FM_CAN_WRITE) {
    html += '<button type="button" onclick="renamePath(\'' + escapeHtml(path) + '\');closeAllMenus();">重命名</button>';
    html += '<button type="button" onclick="copyPathToClipboard(\'' + escapeHtml(path) + '\');closeAllMenus();">复制路径</button>';
    html += '<button type="button" onclick="chmodSelectedSingle(\'' + escapeHtml(path) + '\');closeAllMenus();">编辑权限</button>';
    html += '<div class="fm-menu-sep"></div>';
    html += '<button type="button" class="danger" onclick="deleteFile(\'' + escapeHtml(path) + '\');closeAllMenus();">删除</button>';
  } else {
    html += '<button type="button" onclick="copyPathToClipboard(\'' + escapeHtml(path) + '\');closeAllMenus();">复制路径</button>';
  }
  menu.innerHTML = html;
  menu.classList.add('open');
  var rect = btn.getBoundingClientRect();
  menu.style.top = (rect.bottom + window.scrollY + 4) + 'px';
  menu.style.left = (rect.left + window.scrollX - 80) + 'px';
}

function closeAllMenus() {
  var menu = document.getElementById('fm-row-more-menu');
  if (menu) menu.classList.remove('open');
  var createMenu = document.getElementById('fm-create-menu');
  if (createMenu) createMenu.classList.remove('open');
}

document.addEventListener('click', function(e) {
  if (!e.target.closest('.fm-more-menu') && !e.target.closest('.fm-more-btn') && !e.target.closest('#fm-btn-create')) {
    closeAllMenus();
  }
});

/* ── 创建下拉 ── */
document.getElementById('fm-btn-create').addEventListener('click', function(e) {
  e.stopPropagation();
  var menu = document.getElementById('fm-create-menu');
  menu.classList.toggle('open');
});

/* ── 复制路径 ── */
function copyPathToClipboard(path) {
  navigator.clipboard.writeText(path).then(function() {
    showToast('路径已复制到剪贴板', 'success');
  }, function() {
    showToast('复制失败', 'error');
  });
}

/* ── 选中态 ── */
function selectedPaths() {
  return Array.from(document.querySelectorAll('.fm-item-check:checked')).map(function(input) {
    return input.value;
  });
}

function selectedItems() {
  var checked = selectedPaths();
  return FM_ITEMS.filter(function(item) { return checked.indexOf(item.path) !== -1; });
}

function updateSelectionUI() {
  var checked = document.querySelectorAll('.fm-item-check:checked');
  var count = checked.length;
  var countEl = document.getElementById('fm-selection-count');
  countEl.textContent = count > 0 ? '已选择 ' + count + ' 项' : '';
  countEl.classList.toggle('has-selection', count > 0);

  var disabled = count === 0;
  document.getElementById('fm-btn-copy').disabled = disabled;
  document.getElementById('fm-btn-move').disabled = disabled;
  document.getElementById('fm-btn-perm').disabled = disabled;
  document.getElementById('fm-btn-delete').disabled = disabled;
}

document.getElementById('fm-check-all').addEventListener('change', function() {
  var on = !!this.checked;
  document.querySelectorAll('.fm-item-check').forEach(function(input) {
    input.checked = on;
  });
  updateSelectionUI();
});

/* ── 加载目录 ── */
async function loadFiles(doPushHistory) {
  if (doPushHistory !== false) {
    pushHistory(FM_SELECTED_HOST, FM_SELECTED_PATH);
  }
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
  FM_SELECTED_PATH = data.cwd || currentPath();
  FM_PAGE.current = 1;
  renderBreadcrumbs(FM_SELECTED_PATH);
  renderFileRows();
  loadWebdavShares();
  await fileApiPost('recent_touch', { host_id: currentHostId(), path: FM_SELECTED_PATH });
  loadRecentInConfig();
}

async function loadWebdavShares() {
  var wrap = document.getElementById('fm-webdav-shares');
  if (!wrap) return;
  if (currentHostId() !== 'local') {
    wrap.style.display = 'none';
    return;
  }
  var data = await fileApiGet('webdav_shares_for_path', { host_id: currentHostId(), path: currentPath() });
  if (!data.ok || !data.items || !data.items.length) {
    wrap.style.display = 'none';
    return;
  }
  var relationLabelMap = { exact: '当前目录就是共享根目录', inside: '当前目录位于共享根目录内', child: '当前目录下包含共享子目录' };
  wrap.innerHTML = '当前目录已共享给 ' + data.items.map(function(item) {
    return '<a href="webdav.php?edit=' + encodeURIComponent(item.id || '') + '" style="font-weight:700">' + escapeHtml(item.username || '') + '</a>'
      + ' <span style="color:var(--tm)">' + escapeHtml(relationLabelMap[item.relation] || '共享目录关联') + '</span>'
      + ' <span style="font-family:var(--mono);color:var(--tm)">' + escapeHtml(item.root || '/') + '</span>'
      + ' <span class="badge ' + (item.enabled ? 'badge-green' : 'badge-gray') + '">' + (item.enabled ? '启用' : '禁用') + '</span>'
      + ' <span style="color:var(--tm)">' + (item.readonly ? '只读' : '读写') + '</span>';
  }).join('，');
  wrap.style.display = 'block';
}

function goParentDir() {
  var path = currentPath();
  if (!path || path === '/') {
    FM_SELECTED_PATH = '/';
    loadFiles();
    return;
  }
  var trimmed = path.replace(/\/+$/, '');
  var next = trimmed.split('/').slice(0, -1).join('/') || '/';
  FM_SELECTED_PATH = next;
  loadFiles();
}

/* ── 目录大小计算 ── */
async function calcDirSize(el, path) {
  el.textContent = '计算中…';
  var data = await fileApiGet('stat', { host_id: currentHostId(), path: path });
  if (data.ok && data.size !== undefined) {
    el.textContent = formatSize(data.size);
    el.classList.remove('fm-size-calc');
    el.style.cursor = 'default';
  } else {
    el.textContent = '计算';
  }
}

/* ── 文件操作 ── */
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
  var data = await fmRun('fm-directory', '创建文件', '正在创建文件 ' + path + ' …', function() {
    return fileApiPost('write', { host_id: currentHostId(), path: path, content: '' });
  });
  showToast(data.msg || (data.ok ? '文件已创建' : '文件创建失败'), data.ok ? 'success' : 'error');
  if (data.ok) {
    await loadFiles();
    openFileEditor(path);
  }
}

async function renamePath(path) {
  if (!FM_CAN_WRITE || !path) return;
  var target = prompt('请输入新的完整路径', path);
  if (!target || target === path) return;
  var data = await fmRun('fm-directory', '重命名路径', '正在将 ' + path + ' 重命名为 ' + target + ' …', function() {
    return fileApiPost('rename', { host_id: currentHostId(), source_path: path, target_path: target });
  });
  showToast(data.msg || (data.ok ? '重命名成功' : '重命名失败'), data.ok ? 'success' : 'error');
  if (data.ok) loadFiles();
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
    var data = await fmRun('fm-directory', '上传文件', '正在上传 ' + file.name + ' …', function() {
      return fileApiPost('write', { host_id: currentHostId(), path: path, content_base64: base64 });
    });
    showToast(data.msg || (data.ok ? '上传成功' : '上传失败'), data.ok ? 'success' : 'error');
    if (data.ok) {
      loadFiles();
      openFileEditor(path);
    }
    input.value = '';
  };
  reader.readAsArrayBuffer(file);
}

async function downloadFile(path) {
  if (!path) return;
  var data = await fmRun('fm-directory', '下载文件', '正在读取文件…', function() {
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

async function downloadCurrentFile() {
  downloadFile(FM_EDITOR_PATH || '');
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
  var path = FM_EDITOR_PATH || '';
  if (!path) { showToast('请先选择文件', 'warning'); return; }
  await deleteFile(path);
  if (document.getElementById('fm-editor-modal').classList.contains('open')) {
    closeFmEditorModal();
  }
}

async function deleteSelected() {
  if (!FM_CAN_WRITE) return;
  var paths = selectedPaths();
  if (!paths.length) { showToast('请先勾选文件或目录', 'warning'); return; }
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

/* 工具栏删除按钮 */
document.getElementById('fm-btn-delete').addEventListener('click', deleteSelected);

function updateClipboardMeta() {
  // 剪贴板状态不再显示独立区域，可改为 toast 提示
}

function copySelected() {
  var paths = selectedPaths();
  if (!paths.length) { showToast('请先勾选文件或目录', 'warning'); return; }
  FM_CLIPBOARD = { mode: 'copy', items: paths, hostId: currentHostId(), basePath: currentPath() };
  showToast('已复制 ' + paths.length + ' 项到剪贴板', 'success');
}

function cutSelected() {
  var paths = selectedPaths();
  if (!paths.length) { showToast('请先勾选文件或目录', 'warning'); return; }
  FM_CLIPBOARD = { mode: 'cut', items: paths, hostId: currentHostId(), basePath: currentPath() };
  showToast('已剪切 ' + paths.length + ' 项到剪贴板', 'success');
}

async function pasteClipboard() {
  if (!FM_CAN_WRITE || !FM_CLIPBOARD || !FM_CLIPBOARD.items || !FM_CLIPBOARD.items.length) {
    showToast('剪贴板为空', 'warning'); return;
  }
  if (FM_CLIPBOARD.hostId !== currentHostId()) {
    showToast('当前版本暂不支持跨主机粘贴', 'warning'); return;
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
  showToast(failed.length ? ('粘贴完成，失败 ' + failed.length + ' 项') : '粘贴完成', failed.length ? 'warning' : 'success');
  loadFiles();
}

/* 工具栏复制/移动/粘贴按钮 */
document.getElementById('fm-btn-copy').addEventListener('click', copySelected);
document.getElementById('fm-btn-move').addEventListener('click', cutSelected);

async function applyFileOp(action) {
  if (!FM_CAN_WRITE) return;
  var paths = selectedPaths();
  if (!paths.length) {
    showToast('请先选择文件或目录', 'warning'); return;
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
  loadFiles();
}

function chmodSelectedSingle(path) {
  if (!path) return;
  // 临时选中该项
  document.querySelectorAll('.fm-item-check').forEach(function(cb) {
    cb.checked = (cb.value === path);
  });
  updateSelectionUI();
  applyFileOp('chmod');
}

document.getElementById('fm-btn-perm').addEventListener('click', function() {
  applyFileOp('chmod');
});

async function archiveCurrentPath() {
  if (!FM_CAN_WRITE) return;
  var path = selectedPaths()[0] || currentPath();
  if (!path) { showToast('请先选择文件或目录', 'warning'); return; }
  var archivePath = prompt('请输入压缩包完整路径', path.replace(/\/$/, '') + '.tar.gz');
  if (!archivePath) return;
  showToast('正在提交压缩任务…', 'info');
  var data = await fileApiPost('archive', { host_id: currentHostId(), path: path, archive_path: archivePath });
  if (data.task_id) {
    showToast('压缩任务已提交', 'info');
    var final = await pollTask(data.task_id);
    showToast(final.msg || (final.ok ? '压缩完成' : '压缩失败'), final.ok ? 'success' : 'error');
    if (final.ok) loadFiles();
  } else {
    showToast(data.msg || (data.ok ? '压缩成功' : '压缩失败'), data.ok ? 'success' : 'error');
    if (data.ok) loadFiles();
  }
}

async function extractArchive(path) {
  if (!FM_CAN_WRITE) return;
  if (!path) { showToast('请先选择压缩文件', 'warning'); return; }
  var destination = prompt('请输入解压目录', currentPath() || '/');
  if (!destination) return;
  showToast('正在提交解压任务…', 'info');
  var data = await fileApiPost('extract', { host_id: currentHostId(), path: path, destination: destination });
  if (data.task_id) {
    showToast('解压任务已提交', 'info');
    var final = await pollTask(data.task_id);
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
    method: 'POST', credentials: 'same-origin',
    headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' },
    body: form
  });
  var data = await res.json();
  if (data.task_id) {
    showToast('下载任务已提交', 'info');
    var final = await pollTask(data.task_id);
    showToast(final.msg || (final.ok ? '下载完成' : '下载失败'), final.ok ? 'success' : 'error');
    if (final.ok) loadFiles();
  } else {
    showToast(data.msg || (data.ok ? '下载成功' : '下载失败'), data.ok ? 'success' : 'error');
  }
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

async function saveFavorite() {
  if (!FM_CAN_WRITE) return;
  var path = currentPath();
  var name = prompt('请输入收藏名称', path.split('/').filter(Boolean).slice(-1)[0] || '根目录');
  if (name === null) return;
  var data = await fmRun('fm-directory', '保存收藏', '正在保存目录收藏…', function() {
    return fileApiPost('favorites_save', { host_id: currentHostId(), path: path, name: name });
  });
  showToast(data.msg || (data.ok ? '收藏成功' : '收藏失败'), data.ok ? 'success' : 'error');
  if (data.ok) loadFavoritesInConfig();
}

async function createWebdavShare() {
  if (!FM_CAN_WEBDAV_MANAGE) { showToast('当前用户没有 WebDAV 管理权限', 'warning'); return; }
  if (currentHostId() !== 'local') { showToast('当前仅支持为本机目录创建 WebDAV 共享', 'warning'); return; }
  var path = currentPath();
  var username = prompt('请输入 WebDAV 用户名', '');
  if (!username) return;
  var password = prompt('请输入 WebDAV 密码', '');
  if (!password) return;
  var readonly = confirm('是否创建为只读账号？\n选择“确定”=只读，选择“取消”=读写');
  var data = await fmRun('fm-directory', '创建 WebDAV 共享', '正在写入 WebDAV 账号和目录映射…', function() {
    return fileApiPost('webdav_share_create', {
      host_id: currentHostId(), path: path, username: username, password: password, readonly: readonly ? '1' : '0'
    });
  });
  showToast(data.msg || (data.ok ? 'WebDAV 共享已创建' : 'WebDAV 共享创建失败'), data.ok ? 'success' : 'error');
  if (data.ok) loadWebdavShares();
}

async function searchFiles() {
  var keyword = (document.getElementById('fm-search').value || '').trim();
  if (!keyword) { showToast('请输入搜索关键字', 'warning'); return; }
  var data = await fmRun('fm-directory', '搜索文件', '正在递归搜索匹配文件…', function() {
    return fileApiGet('search', { host_id: currentHostId(), path: currentPath(), keyword: keyword, limit: 200 });
  });
  if (!data.ok) { showToast(data.msg || '搜索失败', 'error'); return; }
  FM_ITEMS = data.items || [];
  FM_PAGE.current = 1;
  renderFileRows();
  showToast('搜索完成，共 ' + FM_ITEMS.length + ' 项', 'success');
}

async function deleteFavorite(id) {
  if (!FM_CAN_WRITE || !id) return;
  var data = await fmRun('fm-directory', '删除收藏', '正在删除目录收藏…', function() {
    return fileApiPost('favorites_delete', { id: id });
  });
  showToast(data.msg || (data.ok ? '删除成功' : '删除失败'), data.ok ? 'success' : 'error');
  if (data.ok) loadFavoritesInConfig();
}

/* ── 回收站 ── */
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
      + '<button type="button" class="btn btn-primary" style="font-size:11px;padding:4px 8px" onclick="restoreTrashItem(\'' + escapeHtml(item.entry_id) + '\')">恢复</button> '
      + '<button type="button" class="btn btn-danger" style="font-size:11px;padding:4px 8px" onclick="deleteTrashItem(\'' + escapeHtml(item.entry_id) + '\')">永久删除</button>'
      + '</td>'
      + '</tr>';
  }).join('');
}

async function restoreTrashItem(entryId) {
  if (!confirm('确定要恢复该文件/目录到原始位置吗？')) return;
  var data = await fileApiPost('trash_restore', { entry_id: entryId });
  showToast(data.msg || (data.ok ? '恢复成功' : '恢复失败'), data.ok ? 'success' : 'error');
  if (data.ok) { loadTrash(); loadFiles(); }
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

/* ── 配置弹窗 ── */
var FM_CONFIG_HOST_TMP = FM_SELECTED_HOST;

function openFmConfigModal() {
  var modal = document.getElementById('fm-config-modal');
  if (!modal) return;
  FM_CONFIG_HOST_TMP = FM_SELECTED_HOST;
  // 同步选中态
  document.querySelectorAll('#fm-config-hosts-list .fm-host-item').forEach(function(el) {
    var radio = el.querySelector('input[type="radio"]');
    var isActive = radio && radio.value === FM_SELECTED_HOST;
    el.classList.toggle('active', isActive);
    if (radio) radio.checked = isActive;
  });
  loadFavoritesInConfig();
  loadRecentInConfig();
  modal.classList.add('open');
}

function closeFmConfigModal() {
  document.getElementById('fm-config-modal').classList.remove('open');
}

function confirmHostSwitch() {
  FM_SELECTED_HOST = FM_CONFIG_HOST_TMP;
  FM_SELECTED_PATH = '/';
  closeFmConfigModal();
  loadFiles();
}

// 主机选择交互
document.querySelectorAll('#fm-config-hosts-list .fm-host-item').forEach(function(el) {
  el.addEventListener('click', function() {
    var radio = this.querySelector('input[type="radio"]');
    if (radio) {
      radio.checked = true;
      FM_CONFIG_HOST_TMP = radio.value;
    }
    document.querySelectorAll('#fm-config-hosts-list .fm-host-item').forEach(function(sib) {
      sib.classList.remove('active');
    });
    this.classList.add('active');
  });
});

// 配置弹窗内列表跳转
function bindConfigListClicks() {
  document.querySelectorAll('#fm-config-quick .fm-config-list-item, #fm-config-favorites .fm-config-list-item, #fm-config-recent .fm-config-list-item').forEach(function(el) {
    el.addEventListener('click', function(e) {
      if (e.target.closest('.fm-item-del')) return;
      var hostId = this.getAttribute('data-host-id') || 'local';
      var path = this.getAttribute('data-path') || '/';
      FM_SELECTED_HOST = hostId;
      FM_SELECTED_PATH = path;
      closeFmConfigModal();
      loadFiles();
    });
  });
}

async function loadFavoritesInConfig() {
  var data = await fileApiGet('favorites_list');
  var container = document.getElementById('fm-config-favorites');
  var items = data.items || [];
  if (!items.length) {
    container.innerHTML = '<div class="form-hint" style="font-size:12px">还没有收藏目录。</div>';
    bindConfigListClicks();
    return;
  }
  container.innerHTML = items.map(function(item) {
    return '<div class="fm-config-list-item" data-host-id="' + escapeHtml(item.host_id || 'local') + '" data-path="' + escapeHtml(item.path || '/') + '">'
      + '<span class="fm-item-icon">⭐</span>'
      + '<span class="fm-item-name">' + escapeHtml(item.name || '') + '</span>'
      + (FM_CAN_WRITE ? '<button type="button" class="fm-item-del" onclick="deleteFavorite(\'' + escapeHtml(item.id || '') + '\')">×</button>' : '')
      + '</div>';
  }).join('');
  bindConfigListClicks();
}

async function loadRecentInConfig() {
  var data = await fileApiGet('recent_list');
  var container = document.getElementById('fm-config-recent');
  var items = data.items || [];
  if (!items.length) {
    container.innerHTML = '<div class="form-hint" style="font-size:12px">暂无最近访问。</div>';
    bindConfigListClicks();
    return;
  }
  container.innerHTML = items.map(function(item) {
    return '<div class="fm-config-list-item" data-host-id="' + escapeHtml(item.host_id || 'local') + '" data-path="' + escapeHtml(item.path || '/') + '">'
      + '<span class="fm-item-icon">🕐</span>'
      + '<span class="fm-item-name">' + escapeHtml(item.name || '') + '</span>'
      + '<span class="fm-item-path">' + escapeHtml(item.path || '/') + '</span>'
      + '</div>';
  }).join('');
  bindConfigListClicks();
}

/* ── Ace Editor 编辑器弹窗 ── */
var FM_EDITOR_PATH = '';
var FM_EDITOR_CONTENT_BASE64 = '';
var FM_EDITOR_IS_BINARY = false;

function inferAceMode(path) {
  var ext = (path.split('.').pop() || '').toLowerCase();
  var map = {
    php: 'php', js: 'javascript', ts: 'javascript', json: 'json',
    yml: 'yaml', yaml: 'yaml', sh: 'sh', bash: 'sh',
    nginx: 'nginx', conf: 'nginx', ini: 'ini', env: 'ini',
    xml: 'xml', sql: 'sql', md: 'markdown', txt: 'text',
    html: 'html', htm: 'html', css: 'css',
    py: 'python', go: 'golang', rs: 'rust', c: 'c_cpp', cpp: 'c_cpp', h: 'c_cpp'
  };
  return map[ext] || 'text';
}

function isImageFile(path) {
  var ext = (path.split('.').pop() || '').toLowerCase();
  return ['png','jpg','jpeg','gif','webp','svg','bmp','ico'].indexOf(ext) !== -1;
}

async function openFileEditor(path) {
  if (!path) return;
  FM_EDITOR_PATH = path;

  // 图片文件直接预览（使用本页弹窗）
  if (isImageFile(path)) {
    var modal = document.getElementById('fm-editor-modal');
    document.getElementById('fm-editor-title').textContent = '图片预览 · ' + path;
    modal.classList.add('open');
    var seq = ++FM_READ_SEQ;
    var data = await fmRun('fm-editor', '读取图片', '正在读取图片…', function() {
      return fileApiPost('read', { host_id: currentHostId(), path: path });
    });
    if (seq !== FM_READ_SEQ || FM_EDITOR_PATH !== path) return;
    if (data.ok && data.content_base64) {
      document.getElementById('fm-editor-image').src = 'data:image/*;base64,' + data.content_base64;
    } else {
      showToast(data.msg || '图片读取失败', 'error');
      closeFmEditorModal();
    }
    return;
  }

  // 文本文件用 NavAceEditor 统一弹窗
  var seq = ++FM_READ_SEQ;
  var data = await fmRun('fm-editor', '读取文件', '正在读取文件内容…', function() {
    return fileApiPost('read', { host_id: currentHostId(), path: path });
  });
  if (seq !== FM_READ_SEQ || FM_EDITOR_PATH !== path) return;
  if (!data.ok) {
    showToast(data.msg || '文件读取失败', 'error');
    return;
  }
  FM_EDITOR_CONTENT_BASE64 = data.content_base64 || '';
  FM_EDITOR_IS_BINARY = !!data.is_binary;

  var lang = inferAceMode(path);
  var initialContent = '';
  if (data.is_binary) {
    initialContent = '// 当前文件为二进制，禁止直接文本编辑；可下载或重新上传覆盖。\n// 文件路径: ' + path;
  } else {
    initialContent = data.content || '';
  }

  var buttonsRight = [
    { text: '关闭', class: 'btn-secondary', action: 'close' },
    { text: '下载', class: 'btn-secondary', action: 'download' }
  ];
  if (FM_CAN_WRITE) {
    buttonsRight.push({ text: '删除', class: 'btn-danger', action: 'delete' });
    buttonsRight.push({ text: '保存', class: 'btn-primary', action: 'save' });
  }

  NavAceEditor.open({
    title: '文本编辑器 · ' + path,
    mode: lang,
    value: initialContent,
    wrapMode: true,
    buttons: {
      left: [{ type: 'dirty' }],
      right: buttonsRight
    },
    onAction: function(action, value) {
      if (action === 'close') {
        NavAceEditor.close();
      } else if (action === 'save') {
        handleSaveFile(value);
      } else if (action === 'download') {
        downloadCurrentFile();
      } else if (action === 'delete') {
        deleteCurrentFile();
      }
    }
  });
}

function closeFmEditorModal() {
  document.getElementById('fm-editor-modal').classList.remove('open');
}

async function handleSaveFile(content) {
  if (!FM_CAN_WRITE) return;
  var path = FM_EDITOR_PATH;
  if (!path) { showToast('请先选择文件', 'warning'); return; }
  if (FM_EDITOR_IS_BINARY) { showToast('二进制文件禁止文本编辑', 'warning'); return; }
  var contentBase64 = textToBase64(content);
  var data = await fmRun('fm-editor', '保存文件', '正在写入文件内容…', function() {
    return fileApiPost('write', { host_id: currentHostId(), path: path, content_base64: contentBase64 });
  });
  showToast(data.msg || (data.ok ? '保存成功' : '保存失败'), data.ok ? 'success' : 'error');
  if (data.ok) {
    FM_EDITOR_IS_BINARY = false;
    FM_EDITOR_CONTENT_BASE64 = contentBase64;
    NavAceEditor.markClean();
    loadFiles();
  }
}

async function saveFile() {
  // 保留旧函数名兼容，实际委托给 NavAceEditor
  var content = (typeof NavAceEditor !== 'undefined' && NavAceEditor.getValue) ? NavAceEditor.getValue() : '';
  await handleSaveFile(content);
}

/* ── 图片灯箱 ── */
function openImageLightbox() {
  var src = document.getElementById('fm-preview-image').src;
  if (!src) return;
  document.getElementById('fm-lightbox-image').src = src;
  document.getElementById('fm-lightbox').style.display = 'flex';
}
function closeImageLightbox() {
  document.getElementById('fm-lightbox').style.display = 'none';
}

/* ── 初始化 ── */
document.addEventListener('DOMContentLoaded', function() {
  bindConfigListClicks();
  // 白名单提示
  var whitelistHint = document.getElementById('fm-whitelist-hint');
  if (whitelistHint) {
    whitelistHint.style.display = (currentHostId() === 'local' && whitelistHint.dataset.hasRoots === '1') ? 'block' : 'none';
  }
  // 搜索框回车
  document.getElementById('fm-search').addEventListener('keydown', function(e) {
    if (e.key === 'Enter') { e.preventDefault(); searchFiles(); }
  });
  // Esc 关闭弹窗/灯箱
  document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
      closeImageLightbox();
      if (document.getElementById('fm-editor-modal').classList.contains('open')) {
        closeFmEditorModal();
      }
      if (document.getElementById('fm-config-modal').classList.contains('open')) {
        closeFmConfigModal();
      }
    }
  });
  // 页面加载完成后读取目录
  pushHistory(FM_SELECTED_HOST, FM_SELECTED_PATH);
  loadFiles(false);
});
</script>

<?php require_once __DIR__ . '/shared/footer.php'; ?>
