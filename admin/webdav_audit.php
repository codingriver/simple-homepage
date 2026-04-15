<?php
declare(strict_types=1);

$page_title = 'WebDAV 审计';
require_once __DIR__ . '/shared/header.php';
require_once __DIR__ . '/shared/webdav_lib.php';

$page = max(1, (int)($_GET['page'] ?? 1));
$filters = [
    'limit' => max(20, min(200, (int)($_GET['limit'] ?? 50))),
    'page' => $page,
    'action' => trim((string)($_GET['action_name'] ?? '')),
    'user' => trim((string)($_GET['log_user'] ?? '')),
    'keyword' => trim((string)($_GET['keyword'] ?? '')),
];
$result = webdav_audit_query($filters);

function webdav_audit_page_url(int $page, array $filters): string {
    return 'webdav_audit.php?' . http_build_query([
        'page' => $page,
        'limit' => $filters['limit'] ?? 50,
        'action_name' => $filters['action'] ?? '',
        'log_user' => $filters['user'] ?? '',
        'keyword' => $filters['keyword'] ?? '',
    ]);
}
?>

<div class="card" style="margin-bottom:16px">
  <div class="card-title">WebDAV 审计</div>
  <div style="color:var(--tm);font-size:12px;line-height:1.9">这里集中查看 WebDAV 账号访问、写入、拒绝、服务开关和账号管理动作，支持筛选、分页和 JSON 导出。</div>
</div>

<div class="card">
  <form method="GET" style="display:flex;gap:10px;flex-wrap:wrap;align-items:end;margin-bottom:12px">
    <div class="form-group" style="margin:0;min-width:140px">
      <label>动作</label>
      <input type="text" name="action_name" value="<?= htmlspecialchars((string)$filters['action']) ?>" placeholder="如 put / delete">
    </div>
    <div class="form-group" style="margin:0;min-width:140px">
      <label>用户</label>
      <input type="text" name="log_user" value="<?= htmlspecialchars((string)$filters['user']) ?>" placeholder="如 webdavtest">
    </div>
    <div class="form-group" style="margin:0;min-width:220px;flex:1">
      <label>关键字</label>
      <input type="text" name="keyword" value="<?= htmlspecialchars((string)$filters['keyword']) ?>" placeholder="搜索路径、动作、上下文">
    </div>
    <div class="form-group" style="margin:0;min-width:100px">
      <label>每页条数</label>
      <input type="number" name="limit" min="20" max="200" value="<?= (int)$filters['limit'] ?>">
    </div>
    <button type="submit" class="btn btn-primary">筛选</button>
    <a href="webdav_audit.php" class="btn btn-secondary">清空</a>
    <a href="webdav.php?action=audit_export&limit=1000&action_name=<?= urlencode((string)$filters['action']) ?>&log_user=<?= urlencode((string)$filters['user']) ?>&keyword=<?= urlencode((string)$filters['keyword']) ?>" class="btn btn-secondary">导出 JSON</a>
    <a href="webdav.php" class="btn btn-secondary">返回 WebDAV</a>
  </form>

  <div class="form-hint" style="margin-bottom:12px">共 <?= (int)($result['total'] ?? 0) ?> 条记录，当前第 <?= (int)($result['page'] ?? 1) ?> 页。</div>

  <?php if (empty($result['items'])): ?>
    <div class="form-hint">暂无符合条件的审计日志。</div>
  <?php else: ?>
    <div class="table-wrap"><table>
      <thead><tr><th>时间</th><th>用户</th><th>动作</th><th>上下文</th></tr></thead>
      <tbody>
        <?php foreach (($result['items'] ?? []) as $log): ?>
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
      <?php if ((int)($result['page'] ?? 1) > 1): ?>
        <a class="btn btn-secondary" href="<?= htmlspecialchars(webdav_audit_page_url(((int)$result['page']) - 1, $filters)) ?>">上一页</a>
      <?php endif; ?>
      <?php if (!empty($result['has_next'])): ?>
        <a class="btn btn-secondary" href="<?= htmlspecialchars(webdav_audit_page_url(((int)$result['page']) + 1, $filters)) ?>">下一页</a>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/shared/footer.php'; ?>
