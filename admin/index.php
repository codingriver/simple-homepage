<?php
/**
 * 后台控制台 admin/index.php
 * 显示统计数据、最近登录日志
 */
$page_title = '控制台';
require_once __DIR__ . '/shared/header.php';

$stats   = get_stats();
$log_data = auth_read_log(10);
$backups  = backup_list();
?>

<!-- 统计卡片 -->
<div class="stat-grid">
  <div class="stat-card"><div class="stat-val"><?= $stats['sites'] ?></div><div class="stat-label">站点数量</div></div>
  <div class="stat-card"><div class="stat-val"><?= $stats['groups'] ?></div><div class="stat-label">分组数量</div></div>
  <div class="stat-card"><div class="stat-val"><?= $stats['users'] ?></div><div class="stat-label">账户数量</div></div>
  <div class="stat-card"><div class="stat-val"><?= count($backups) ?></div><div class="stat-label">备份记录</div></div>
</div>

<!-- 快捷操作 -->
<div class="card">
  <div class="card-title">🚀 快捷操作</div>
  <div style="display:flex;gap:10px;flex-wrap:wrap">
    <a href="sites.php" class="btn btn-primary">＋ 添加站点</a>
    <a href="groups.php" class="btn btn-secondary">＋ 添加分组</a>
    <a href="settings.php" class="btn btn-secondary">⚙️ 系统设置</a>
    <a href="backups.php" class="btn btn-secondary">💾 备份管理</a>
    <a href="/index.php" class="btn btn-secondary" target="_blank">🌐 查看前台</a>
  </div>
</div>

<!-- 最近登录日志 -->
<div class="card">
  <div class="card-title">📋 最近登录记录 <a href="settings.php#logs" class="btn btn-sm btn-secondary" style="margin-left:auto">查看全部</a></div>
  <?php if (empty($log_data['rows'])): ?>
    <p style="color:var(--tm);font-size:13px">暂无登录记录</p>
  <?php else: ?>
  <div class="table-wrap"><table>
    <tr><th>时间</th><th>类型</th><th>用户</th><th>IP</th></tr>
    <?php foreach ($log_data['rows'] as $row):
      // 格式：[2026-03-13 14:30:22] SUCCESS    user=admin ip=1.2.3.4
      preg_match('/\[(.+?)\]\s+(\S+)\s+user=(\S+)\s+ip=(\S+)/', $row, $m);
      $time  = $m[1] ?? '-';
      $type  = $m[2] ?? '-';
      $uname = $m[3] ?? '-';
      $ip    = $m[4] ?? '-';
      $bc_map = ['SUCCESS'=>'badge-green','FAIL'=>'badge-red','IP_LOCKED'=>'badge-yellow','LOGOUT'=>'badge-blue'];
      $bc = isset($bc_map[$type]) ? $bc_map[$type] : 'badge-gray';
    ?>
    <tr>
      <td style="font-family:monospace;font-size:12px"><?= htmlspecialchars($time) ?></td>
      <td><span class="badge <?= $bc ?>"><?= htmlspecialchars($type) ?></span></td>
      <td><?= htmlspecialchars($uname) ?></td>
      <td style="font-family:monospace;font-size:12px"><?= htmlspecialchars($ip) ?></td>
    </tr>
    <?php endforeach; ?>
  </table></div>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/shared/footer.php'; ?>
