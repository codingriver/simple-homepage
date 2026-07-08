<?php
/**
 * 后台控制台 admin/index.php
 */
$page_title = '控制台';
require_once __DIR__ . '/shared/header.php';
require_once __DIR__ . '/shared/domain_expiry_lib.php';

$stats   = get_stats();
$backups = backup_count();
$domainExpiry = domain_expiry_summary();
$domainUrgent = (int)$domainExpiry['expired'] + (int)$domainExpiry['critical'];
?>

<!-- 统计卡片 -->
<div class="stat-grid">
  <div class="stat-card"><div class="stat-val"><?= (int) ($stats['users'] ?? 0) ?></div><div class="stat-label">账户数量</div></div>
  <div class="stat-card"><div class="stat-val"><?= (int) ($stats['admins'] ?? 0) ?></div><div class="stat-label">管理员数量</div></div>
  <div class="stat-card"><div class="stat-val"><?= (int) $backups ?></div><div class="stat-label">备份记录</div></div>
  <div class="stat-card"><div class="stat-val"><?= $domainUrgent ?></div><div class="stat-label">域名紧急</div></div>
</div>

<!-- 快捷操作 -->
<div class="card">
  <div class="card-title">🚀 快捷操作</div>
  <div class="quick-actions">
    <a href="users.php" class="btn btn-primary quick-action">👥 用户管理</a>
    <a href="settings.php" class="btn btn-secondary quick-action">⚙️ 系统设置</a>
    <a href="backups.php" class="btn btn-secondary quick-action">💾 备份管理</a>
    <a href="scheduled_tasks.php" class="btn btn-secondary quick-action">⏱ 计划任务</a>
    <a href="domain_expiry.php" class="btn btn-secondary quick-action">📅 域名有效期</a>
    <a href="logs.php" class="btn btn-secondary quick-action">📄 日志中心</a>
  </div>
  <p style="color:var(--tm);font-size:13px;margin-top:12px;margin-bottom:0">
    登录记录与系统日志请在「日志中心」统一查看。域名有效期监控：
    <?php if ($domainExpiry['nearest']): ?>
      最近到期为 <code><?= htmlspecialchars((string)$domainExpiry['nearest']['domain']) ?></code>，剩余 <?= (int)$domainExpiry['nearest']['days_left'] ?> 天。
    <?php else: ?>
      暂无已缓存的到期数据。
    <?php endif; ?>
  </p>
</div>

<?php require_once __DIR__ . '/shared/footer.php'; ?>
