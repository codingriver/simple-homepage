<?php
declare(strict_types=1);

$page_title = 'WebDAV 共享总览';
require_once __DIR__ . '/shared/header.php';
require_once __DIR__ . '/shared/webdav_lib.php';

$items = webdav_shares_summary();
?>

<div class="card" style="margin-bottom:16px">
  <div class="card-title">WebDAV 共享总览</div>
  <div style="color:var(--tm);font-size:12px;line-height:1.9">
    这里按目录聚合查看 WebDAV 共享情况，包含共享目录、账号数、启用数、只读数和最近访问情况。
  </div>
</div>

<div class="card">
  <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:12px">
    <input type="text" id="webdav-share-filter" placeholder="搜索目录或账号" style="min-width:260px;flex:1">
    <a href="webdav.php" class="btn btn-secondary">返回 WebDAV</a>
  </div>
  <?php if (!$items): ?>
    <div class="form-hint">当前还没有 WebDAV 共享目录。</div>
  <?php else: ?>
    <div class="table-wrap"><table>
      <thead><tr><th>共享目录</th><th>账号统计</th><th>最近活动</th><th>账号列表</th><th>操作</th></tr></thead>
      <tbody>
        <?php foreach ($items as $item): ?>
        <?php
          $searchText = strtolower((string)($item['root'] ?? '') . ' ' . implode(' ', array_map(static fn(array $account): string => (string)($account['username'] ?? ''), (array)($item['accounts'] ?? []))));
        ?>
        <tr class="webdav-share-row" data-search="<?= htmlspecialchars($searchText) ?>">
          <td style="font-family:var(--mono);font-size:12px"><?= htmlspecialchars((string)($item['root'] ?? '/')) ?></td>
          <td style="font-size:12px;color:var(--tx2)">
            账号 <?= (int)($item['account_count'] ?? 0) ?><br>
            启用 <?= (int)($item['enabled_count'] ?? 0) ?><br>
            只读 <?= (int)($item['readonly_count'] ?? 0) ?>
          </td>
          <td style="font-size:12px;color:var(--tx2)">
            <?php if (!empty($item['last_time'])): ?>
              <div><?= htmlspecialchars((string)($item['last_time'] ?? '')) ?></div>
              <div><?= htmlspecialchars((string)($item['last_user'] ?? '')) ?> / <?= htmlspecialchars((string)($item['last_action'] ?? '')) ?></div>
            <?php else: ?>
              <span class="form-hint">暂无访问</span>
            <?php endif; ?>
          </td>
          <td style="font-size:12px;color:var(--tx2)">
            <?php foreach (($item['accounts'] ?? []) as $index => $account): ?>
              <?php if ($index > 0): ?><br><?php endif; ?>
              <a href="webdav.php?edit=<?= urlencode((string)($account['id'] ?? '')) ?>"><?= htmlspecialchars((string)($account['username'] ?? '')) ?></a>
              <span class="badge <?= !empty($account['enabled']) ? 'badge-green' : 'badge-gray' ?>"><?= !empty($account['enabled']) ? '启用' : '禁用' ?></span>
              <span style="color:var(--tm)"><?= !empty($account['readonly']) ? '只读' : '读写' ?></span>
            <?php endforeach; ?>
          </td>
          <td style="white-space:nowrap">
            <a class="btn btn-sm btn-secondary" href="files.php?host_id=local&path=<?= urlencode((string)($item['root'] ?? '/')) ?>">打开目录</a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
  var input = document.getElementById('webdav-share-filter');
  if (!input) return;
  input.addEventListener('input', function() {
    var keyword = (input.value || '').toLowerCase().trim();
    document.querySelectorAll('.webdav-share-row').forEach(function(row) {
      var text = row.getAttribute('data-search') || '';
      row.style.display = !keyword || text.indexOf(keyword) !== -1 ? '' : 'none';
    });
  });
});
</script>

<?php require_once __DIR__ . '/shared/footer.php'; ?>
