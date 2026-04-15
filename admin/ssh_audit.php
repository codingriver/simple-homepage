<?php
declare(strict_types=1);

$page_permission = 'ssh.audit';
$page_title = 'SSH 审计';

require_once __DIR__ . '/shared/header.php';
require_once __DIR__ . '/shared/ssh_manager_lib.php';

$filters = [
    'limit' => max(20, min(200, (int)($_GET['limit'] ?? 50))),
    'page' => max(1, (int)($_GET['page'] ?? 1)),
    'action' => trim((string)($_GET['action_name'] ?? '')),
    'host_id' => trim((string)($_GET['host_id'] ?? '')),
    'user' => trim((string)($_GET['user_name'] ?? '')),
    'keyword' => trim((string)($_GET['keyword'] ?? '')),
];
$query = ssh_manager_audit_query($filters);
$remoteHosts = ssh_manager_list_hosts();

function ssh_audit_page_url(int $page, array $filters): string {
    return 'ssh_audit.php?' . http_build_query([
        'page' => $page,
        'limit' => $filters['limit'] ?? 50,
        'action_name' => $filters['action'] ?? '',
        'host_id' => $filters['host_id'] ?? '',
        'user_name' => $filters['user'] ?? '',
        'keyword' => $filters['keyword'] ?? '',
    ]);
}
?>

<div class="card" style="margin-bottom:16px">
  <div class="card-title">SSH 审计</div>
  <div style="color:var(--tm);font-size:12px;line-height:1.9">
    这里集中查看 SSH 配置修改、服务操作、远程主机测试、批量执行、公钥分发、终端操作和相关文件操作审计。
  </div>
</div>

<div class="card">
  <form method="GET" style="display:flex;gap:10px;flex-wrap:wrap;align-items:end;margin-bottom:12px">
    <div class="form-group" style="margin:0;min-width:150px">
      <label>动作</label>
      <input type="text" name="action_name" value="<?= htmlspecialchars((string)$filters['action']) ?>" placeholder="如 ssh_config_save">
    </div>
    <div class="form-group" style="margin:0;min-width:160px">
      <label>主机</label>
      <select name="host_id">
        <option value="">全部主机</option>
        <option value="local" <?= $filters['host_id'] === 'local' ? 'selected' : '' ?>>本机</option>
        <?php foreach ($remoteHosts as $host): ?>
        <option value="<?= htmlspecialchars((string)($host['id'] ?? '')) ?>" <?= $filters['host_id'] === (string)($host['id'] ?? '') ? 'selected' : '' ?>><?= htmlspecialchars((string)($host['name'] ?? '')) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group" style="margin:0;min-width:150px">
      <label>用户</label>
      <input type="text" name="user_name" value="<?= htmlspecialchars((string)$filters['user']) ?>" placeholder="如 admin">
    </div>
    <div class="form-group" style="margin:0;min-width:220px;flex:1">
      <label>关键字</label>
      <input type="text" name="keyword" value="<?= htmlspecialchars((string)$filters['keyword']) ?>" placeholder="搜索上下文、命令、路径">
    </div>
    <div class="form-group" style="margin:0;min-width:100px">
      <label>每页条数</label>
      <input type="number" name="limit" min="20" max="200" value="<?= (int)$filters['limit'] ?>">
    </div>
    <button type="submit" class="btn btn-primary">筛选</button>
    <a href="ssh_audit.php" class="btn btn-secondary">清空</a>
    <button type="button" class="btn btn-secondary" onclick="exportSshAudit()">导出 JSON</button>
    <a href="hosts.php#audit" class="btn btn-secondary">返回主机管理</a>
  </form>

  <div class="form-hint" style="margin-bottom:12px">共 <?= (int)($query['total'] ?? 0) ?> 条记录，当前第 <?= (int)($query['page'] ?? 1) ?> 页。</div>

  <?php if (empty($query['items'])): ?>
    <div class="form-hint">暂无符合条件的 SSH 审计日志。</div>
  <?php else: ?>
    <div class="table-wrap"><table>
      <thead><tr><th>时间</th><th>用户</th><th>动作</th><th>上下文</th></tr></thead>
      <tbody>
        <?php foreach (($query['items'] ?? []) as $log): ?>
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
      <?php if ((int)($query['page'] ?? 1) > 1): ?>
        <a class="btn btn-secondary" href="<?= htmlspecialchars(ssh_audit_page_url(((int)$query['page']) - 1, $filters)) ?>">上一页</a>
      <?php endif; ?>
      <?php if (!empty($query['has_next'])): ?>
        <a class="btn btn-secondary" href="<?= htmlspecialchars(ssh_audit_page_url(((int)$query['page']) + 1, $filters)) ?>">下一页</a>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>

<script>
async function exportSshAudit() {
  var params = new URLSearchParams({
    action: 'audit_export',
    limit: '1000',
    action_name: <?= json_encode((string)$filters['action']) ?>,
    host_id: <?= json_encode((string)$filters['host_id']) ?>,
    user_name: <?= json_encode((string)$filters['user']) ?>,
    keyword: <?= json_encode((string)$filters['keyword']) ?>,
  });
  var res = await fetch('host_api.php?' + params.toString(), { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } });
  if (!res.ok) {
    showToast('SSH 审计导出失败', 'error');
    return;
  }
  var blob = await res.blob();
  var link = document.createElement('a');
  link.href = URL.createObjectURL(blob);
  link.download = 'ssh-audit-export.json';
  document.body.appendChild(link);
  link.click();
  setTimeout(function() {
    URL.revokeObjectURL(link.href);
    link.remove();
  }, 1000);
}
</script>

<?php require_once __DIR__ . '/shared/footer.php'; ?>
