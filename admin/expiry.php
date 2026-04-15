<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/shared/functions.php';
    require_once __DIR__ . '/shared/expiry_lib.php';
    $user = auth_get_current_user();
    if (!$user || ($user['role'] ?? '') !== 'admin') {
        header('Location: /login.php');
        exit;
    }
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'run_scan') {
        $scan = expiry_scan_and_store(true);
        flash_set('success', '到期扫描完成：共检查 ' . count($scan['rows'] ?? []) . ' 个站点');
        header('Location: expiry.php');
        exit;
    }
}

$page_title = '到期管理';
require_once __DIR__ . '/shared/header.php';
require_once __DIR__ . '/shared/expiry_lib.php';

$scan = expiry_load_scan();
$rows = $scan['rows'] ?? expiry_site_rows();
?>

<div class="toolbar">
  <form method="POST" style="display:inline">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="run_scan">
    <button type="submit" class="btn btn-primary">🩺 立即扫描并通知</button>
  </form>
  <span style="color:var(--tm);font-size:12px">站点的域名到期日、证书到期日可在「站点管理」里维护；HTTPS 站点会尝试自动读取 SSL 到期时间。</span>
</div>

<div class="card" style="margin-bottom:16px">
  <div class="card-title">扫描概览</div>
  <div style="display:flex;gap:16px;flex-wrap:wrap;color:var(--tx2);font-size:13px">
    <div>最近扫描：<span style="font-family:var(--mono)"><?= htmlspecialchars((string)($scan['last_scan_at'] ?? '未扫描')) ?></span></div>
    <div>站点数：<strong><?= count($rows) ?></strong></div>
  </div>
</div>

<div class="card">
  <div class="card-title">到期列表</div>
  <?php if (!$rows): ?>
    <p style="color:var(--tm);font-size:13px">暂无可检查的站点。</p>
  <?php else: ?>
  <div class="table-wrap"><table>
    <thead><tr><th>站点</th><th>分组</th><th>域名</th><th>域名到期</th><th>SSL 到期</th><th>续费/说明</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $row): ?>
      <tr>
        <td style="font-weight:600"><?= htmlspecialchars((string)($row['name'] ?? '')) ?></td>
        <td><?= htmlspecialchars((string)($row['group_name'] ?? '')) ?></td>
        <td style="font-family:var(--mono);font-size:12px;color:var(--tx2)"><?= htmlspecialchars((string)($row['domain'] ?? '—')) ?></td>
        <td style="font-size:12px">
          <?= htmlspecialchars((string)($row['domain_expire_at'] ?: '—')) ?>
          <?php if (($row['domain_days_left'] ?? null) !== null): ?>
            <div style="color:<?= ($row['domain_days_left'] ?? 999) <= 7 ? 'var(--red)' : 'var(--tm)' ?>">剩余 <?= (int)$row['domain_days_left'] ?> 天</div>
          <?php endif; ?>
        </td>
        <td style="font-size:12px">
          <?= htmlspecialchars((string)($row['ssl_expire_at'] ?: '—')) ?>
          <?php if (($row['ssl_days_left'] ?? null) !== null): ?>
            <div style="color:<?= ($row['ssl_days_left'] ?? 999) <= 7 ? 'var(--red)' : 'var(--tm)' ?>">剩余 <?= (int)$row['ssl_days_left'] ?> 天</div>
          <?php endif; ?>
        </td>
        <td style="font-size:12px">
          <?php if (!empty($row['renew_url'])): ?>
            <a class="btn btn-sm btn-secondary" href="<?= htmlspecialchars((string)$row['renew_url']) ?>" target="_blank" rel="noopener noreferrer">打开续费说明</a>
          <?php else: ?>
            <span style="color:var(--tm)">—</span>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/shared/footer.php'; ?>
