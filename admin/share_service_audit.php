<?php
declare(strict_types=1);

$page_permission = 'ssh.audit';
$page_title = '共享服务审计';

require_once __DIR__ . '/shared/header.php';
require_once __DIR__ . '/shared/share_service_lib.php';

$service = trim((string)($_GET['service_name'] ?? ''));
$keyword = trim((string)($_GET['keyword'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$filters = [
    'limit' => max(20, min(200, (int)($_GET['limit'] ?? 50))),
    'page' => $page,
    'action' => trim((string)($_GET['action_name'] ?? '')),
    'service' => $service,
    'user' => trim((string)($_GET['user_name'] ?? '')),
    'keyword' => $keyword,
];
$audit = share_service_audit_query($filters);
$history = share_service_history_list([
    'service' => $service,
    'keyword' => $keyword,
    'limit' => 100,
]);
$csrfValue = csrf_token();

function share_service_audit_page_url(int $page, array $filters): string {
    return 'share_service_audit.php?' . http_build_query([
        'page' => $page,
        'limit' => $filters['limit'] ?? 50,
        'action_name' => $filters['action'] ?? '',
        'service_name' => $filters['service'] ?? '',
        'user_name' => $filters['user'] ?? '',
        'keyword' => $filters['keyword'] ?? '',
    ]);
}
?>

<div class="card" style="margin-bottom:16px">
  <div class="card-title">共享服务审计</div>
  <div style="color:var(--tm);font-size:12px;line-height:1.9">这里集中查看 SFTP、SMB、FTP、NFS、AFP、Async / Rsync 的服务操作、配置变更和历史恢复记录。</div>
</div>

<div class="card" style="margin-bottom:16px">
  <form method="GET" style="display:flex;gap:10px;flex-wrap:wrap;align-items:end">
    <div class="form-group" style="margin:0;min-width:140px">
      <label>服务</label>
      <select name="service_name">
        <option value="">全部</option>
        <?php foreach (share_service_supported_map() as $id => $item): ?>
        <option value="<?= htmlspecialchars($id) ?>" <?= $service === $id ? 'selected' : '' ?>><?= htmlspecialchars((string)($item['label'] ?? strtoupper($id))) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group" style="margin:0;min-width:160px">
      <label>动作</label>
      <input type="text" name="action_name" value="<?= htmlspecialchars((string)$filters['action']) ?>" placeholder="如 smb_share_save">
    </div>
    <div class="form-group" style="margin:0;min-width:140px">
      <label>用户</label>
      <input type="text" name="user_name" value="<?= htmlspecialchars((string)$filters['user']) ?>" placeholder="操作人">
    </div>
    <div class="form-group" style="margin:0;min-width:220px;flex:1">
      <label>关键字</label>
      <input type="text" name="keyword" value="<?= htmlspecialchars($keyword) ?>" placeholder="搜索路径、用户、动作、上下文">
    </div>
    <div class="form-group" style="margin:0;min-width:100px">
      <label>每页条数</label>
      <input type="number" name="limit" min="20" max="200" value="<?= (int)$filters['limit'] ?>">
    </div>
    <button type="submit" class="btn btn-primary">筛选</button>
    <a href="share_service_audit.php" class="btn btn-secondary">清空</a>
    <a href="host_api.php?action=share_audit_export&limit=1000&action_name=<?= urlencode((string)$filters['action']) ?>&service_name=<?= urlencode($service) ?>&user_name=<?= urlencode((string)$filters['user']) ?>&keyword=<?= urlencode($keyword) ?>" class="btn btn-secondary">导出 JSON</a>
    <a href="host_runtime.php" class="btn btn-secondary">返回宿主机运维</a>
  </form>
</div>

<div class="card" style="margin-bottom:16px">
  <div class="card-title">审计记录</div>
  <div class="form-hint" style="margin-bottom:12px">共 <?= (int)($audit['total'] ?? 0) ?> 条记录，当前第 <?= (int)($audit['page'] ?? 1) ?> 页。</div>
  <?php if (empty($audit['items'])): ?>
    <div class="form-hint">暂无符合条件的共享服务审计记录。</div>
  <?php else: ?>
    <div class="table-wrap"><table>
      <thead><tr><th>时间</th><th>用户</th><th>动作</th><th>上下文</th></tr></thead>
      <tbody>
      <?php foreach (($audit['items'] ?? []) as $log): ?>
        <tr>
          <td style="font-family:var(--mono)"><?= htmlspecialchars((string)($log['time'] ?? '')) ?></td>
          <td><?= htmlspecialchars((string)($log['user'] ?? '')) ?></td>
          <td><?= htmlspecialchars((string)($log['action'] ?? '')) ?></td>
          <td><pre style="margin:0;white-space:pre-wrap;background:none;border:none;padding:0;color:var(--tx2);font-size:12px"><?= htmlspecialchars((string)json_encode($log['context'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:12px">
      <?php if ((int)($audit['page'] ?? 1) > 1): ?>
        <a class="btn btn-secondary" href="<?= htmlspecialchars(share_service_audit_page_url(((int)$audit['page']) - 1, $filters)) ?>">上一页</a>
      <?php endif; ?>
      <?php if (!empty($audit['has_next'])): ?>
        <a class="btn btn-secondary" href="<?= htmlspecialchars(share_service_audit_page_url(((int)$audit['page']) + 1, $filters)) ?>">下一页</a>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>

<div class="card">
  <div class="card-title">配置历史</div>
  <div class="form-hint" style="margin-bottom:12px">每次共享服务保存、删除、安装、卸载和恢复后，都会落地一份快照。可在这里直接恢复。</div>
  <?php if (!$history): ?>
    <div class="form-hint">暂无历史快照。</div>
  <?php else: ?>
    <div class="table-wrap"><table>
      <thead><tr><th>时间</th><th>服务</th><th>动作</th><th>操作者</th><th>元数据</th><th>操作</th></tr></thead>
      <tbody>
      <?php foreach ($history as $item): ?>
        <tr>
          <td style="font-family:var(--mono)"><?= htmlspecialchars((string)($item['created_at'] ?? '')) ?></td>
          <td><?= htmlspecialchars((string)($item['label'] ?? '')) ?></td>
          <td><?= htmlspecialchars((string)($item['action'] ?? '')) ?></td>
          <td><?= htmlspecialchars((string)($item['created_by'] ?? '')) ?></td>
          <td><pre style="margin:0;white-space:pre-wrap;background:none;border:none;padding:0;color:var(--tx2);font-size:12px"><?= htmlspecialchars((string)json_encode($item['meta'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre></td>
          <td><button type="button" class="btn btn-secondary btn-sm" onclick="restoreShareHistory('<?= htmlspecialchars((string)($item['id'] ?? '')) ?>')">恢复</button></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endif; ?>
</div>

<script>
var SHARE_AUDIT_CSRF = <?= json_encode($csrfValue) ?>;
async function restoreShareHistory(id) {
  if (!confirm('确认恢复这个共享服务历史快照？')) return;
  var body = new URLSearchParams();
  body.set('action', 'share_history_restore');
  body.set('_csrf', SHARE_AUDIT_CSRF);
  body.set('history_id', id);
  var res = await fetch('host_api.php', {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
    body: body.toString()
  });
  var data = await res.json();
  showToast(data.msg || (data.ok ? '历史快照已恢复' : '恢复失败'), data.ok ? 'success' : 'error');
  if (data.ok) {
    setTimeout(function() { window.location.reload(); }, 500);
  }
}
</script>

<?php require_once __DIR__ . '/shared/footer.php'; ?>
