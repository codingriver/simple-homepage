<?php
/**
 * 后台控制台 admin/index.php
 * 统计与快捷入口（登录日志在系统设置中惰性加载，不在此拉取）
 */
$page_title = '控制台';
require_once __DIR__ . '/shared/header.php';

$stats   = get_stats();
$backups = backup_count();
?>

<!-- 统计卡片 -->
<div class="stat-grid">
  <div class="stat-card"><div class="stat-val"><?= $stats['sites'] ?></div><div class="stat-label">站点数量</div></div>
  <div class="stat-card"><div class="stat-val"><?= $stats['groups'] ?></div><div class="stat-label">分组数量</div></div>
  <div class="stat-card"><div class="stat-val"><?= $stats['users'] ?></div><div class="stat-label">账户数量</div></div>
  <div class="stat-card"><div class="stat-val"><?= (int) $backups ?></div><div class="stat-label">备份记录</div></div>
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
  <p style="color:var(--tm);font-size:13px;margin-top:12px;margin-bottom:0">登录记录请在「系统设置 → 登录日志」查看（进入该区域时加载）。</p>
</div>

<?php require_once __DIR__ . '/shared/footer.php'; ?>
