<?php
declare(strict_types=1);

$page_permission = 'ssh.audit';
$page_title = '文件审计';

require_once __DIR__ . '/shared/header.php';
require_once __DIR__ . '/shared/ssh_manager_lib.php';

$remoteHosts = ssh_manager_list_hosts();
$logs = array_values(array_filter(
    ssh_manager_audit_tail(200),
    static fn(array $log): bool => str_starts_with((string)($log['action'] ?? ''), 'fs_') || str_starts_with((string)($log['action'] ?? ''), 'file_favorite_')
));
?>

<div class="card" style="margin-bottom:16px">
  <div class="card-title">文件审计</div>
  <div style="color:var(--tm);font-size:12px;line-height:1.9">
    这里集中查看文件系统相关操作，包括写入、删除、重命名、复制移动、权限修改、压缩解压和收藏目录变更。
  </div>
</div>

<div class="card">
  <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:12px">
    <select id="fa-host-filter" style="min-width:180px">
      <option value="">全部主机</option>
      <option value="local">本机</option>
      <?php foreach ($remoteHosts as $host): ?>
      <option value="<?= htmlspecialchars((string)($host['id'] ?? '')) ?>"><?= htmlspecialchars((string)($host['name'] ?? '')) ?></option>
      <?php endforeach; ?>
    </select>
    <input type="text" id="fa-keyword-filter" placeholder="搜索动作、路径、上下文" style="min-width:260px;flex:1">
    <button type="button" class="btn btn-secondary" onclick="exportFileAudit()">导出日志</button>
    <a href="files.php" class="btn btn-secondary">返回文件系统</a>
  </div>

  <div class="table-wrap">
    <table>
      <thead><tr><th>时间</th><th>用户</th><th>动作</th><th>上下文</th></tr></thead>
      <tbody>
        <?php foreach ($logs as $log): ?>
        <?php $context = is_array($log['context'] ?? null) ? $log['context'] : []; ?>
        <tr class="fa-row" data-host-id="<?= htmlspecialchars((string)($context['host_id'] ?? '')) ?>" data-search="<?= htmlspecialchars(strtolower((string)($log['action'] ?? '') . ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))) ?>">
          <td style="font-family:var(--mono)"><?= htmlspecialchars((string)($log['time'] ?? '')) ?></td>
          <td><?= htmlspecialchars((string)($log['user'] ?? '')) ?></td>
          <td><?= htmlspecialchars((string)($log['action'] ?? '')) ?></td>
          <td><pre style="margin:0;white-space:pre-wrap;background:none;border:none;padding:0;color:var(--tx2);font-size:12px"><?= htmlspecialchars(json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre></td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$logs): ?>
        <tr><td colspan="4" style="color:var(--tm)">暂无文件审计日志。</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
function filterFileAuditRows() {
  var hostId = (document.getElementById('fa-host-filter').value || '').trim();
  var keyword = (document.getElementById('fa-keyword-filter').value || '').toLowerCase().trim();
  document.querySelectorAll('.fa-row').forEach(function(row) {
    var matchesHost = !hostId || row.getAttribute('data-host-id') === hostId;
    var matchesKeyword = !keyword || (row.getAttribute('data-search') || '').indexOf(keyword) !== -1;
    row.style.display = matchesHost && matchesKeyword ? '' : 'none';
  });
}

async function exportFileAudit() {
  var url = 'file_api.php?action=audit_export'
    + '&limit=500'
    + '&host_id=' + encodeURIComponent((document.getElementById('fa-host-filter') || {}).value || '')
    + '&keyword=' + encodeURIComponent((document.getElementById('fa-keyword-filter') || {}).value || '');
  var res = await fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } });
  if (!res.ok) {
    showToast('文件审计导出失败', 'error');
    return;
  }
  var blob = await res.blob();
  var link = document.createElement('a');
  link.href = URL.createObjectURL(blob);
  link.download = 'file-audit-export.json';
  document.body.appendChild(link);
  link.click();
  setTimeout(function() {
    URL.revokeObjectURL(link.href);
    link.remove();
  }, 1000);
}

document.addEventListener('DOMContentLoaded', function() {
  ['fa-host-filter', 'fa-keyword-filter'].forEach(function(id) {
    var el = document.getElementById(id);
    if (!el) return;
    el.addEventListener('input', filterFileAuditRows);
    el.addEventListener('change', filterFileAuditRows);
  });
  filterFileAuditRows();
});
</script>

<?php require_once __DIR__ . '/shared/footer.php'; ?>
