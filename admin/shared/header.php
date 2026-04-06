<?php
/**
 * 后台公共头部 admin/shared/header.php
 * 输出 HTML head、侧边栏、顶部栏，并验证管理员权限
 *
 * 使用方式：在每个后台页面顶部
 *   $page_title = '页面标题';
 *   require_once __DIR__ . '/shared/header.php';
 */
require_once __DIR__ . '/functions.php';

// 验证管理员权限（未登录或非admin跳转）
$current_admin = auth_require_admin();
// 提前建立 Session，避免输出后再 session_start 导致 CSRF 失效
csrf_token();

// 读取 Flash（须在 session_write_close 之前：需写入以清除 flash）
$flash = flash_get();

// 缓存 CSRF，关闭 Session 后表单里的 csrf_field() 仍可用（避免已输出 HTML 再 session_start）
$GLOBALS['_nav_csrf_token'] = $_SESSION['csrf_token'] ?? csrf_token();

// 释放 Session 锁，避免同浏览器多标签刷新时互相阻塞
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

// 当前页面文件名（用于导航高亮）
$current_page = basename($_SERVER['PHP_SELF']);

// 与 auth_get_config 共用静态缓存，避免重复读 config.json
$cfg_admin = auth_get_config();
$site_name_admin = $cfg_admin['site_name'] ?? '导航中心';

// 检测未生效的 proxy 站点
$_pending_proxy = nginx_pending_sites();

// 导航菜单项定义
$nav_items = [
    ['file' => 'index.php',    'icon' => '📊', 'label' => '控制台'],
    ['file' => 'sites.php',    'icon' => '🔗', 'label' => '站点管理'],
    ['file' => 'groups.php',   'icon' => '📁', 'label' => '分组管理'],
    ['sep'],
    ['file' => 'nginx.php',    'icon' => '🧩', 'label' => 'Nginx 管理'],
    ['file' => 'dns.php',      'icon' => '🌐', 'label' => '域名解析'],
    ['file' => 'ddns.php',     'icon' => '📡', 'label' => 'DDNS 动态解析'],
    ['sep'],
    ['file' => 'settings.php', 'icon' => '⚙️', 'label' => '系统设置'],
    ['file' => 'backups.php',  'icon' => '💾', 'label' => '备份恢复'],
    ['file' => 'users.php',    'icon' => '👥', 'label' => '用户管理'],
    ['file' => 'debug.php',    'icon' => '🛠', 'label' => '调试工具'],
    ['file' => 'scheduled_tasks.php', 'icon' => '⏱', 'label' => '计划任务'],

];
?>
<!DOCTYPE html><html lang="zh-CN"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= htmlspecialchars($page_title ?? '后台') ?> — <?= htmlspecialchars($site_name_admin) ?></title>
<link rel="stylesheet" href="shared/admin.css">
</head>
<body>
<script>
// ── 全站 Toast 系统 ──
function showToast(msg, type) {
    type = type || 'info';
    var colors = {
        error:   'background:#3a1a1a;border:1px solid rgba(255,107,107,.4);color:#ff8080',
        success: 'background:#1a3a1a;border:1px solid rgba(74,222,128,.4);color:#4ade80',
        info:    'background:#1a2a3a;border:1px solid rgba(96,165,250,.4);color:#60a5fa',
        warning: 'background:#3a2a0a;border:1px solid rgba(251,191,36,.4);color:#fbbf24'
    };
    var t = document.createElement('div');
    t.textContent = msg;
    t.style.cssText = 'position:fixed;top:20px;right:20px;z-index:9999;padding:12px 20px;border-radius:10px;'
        + 'font-size:13px;max-width:360px;box-shadow:0 4px 16px rgba(0,0,0,.3);'
        + 'animation:fadeIn .2s ease;pointer-events:none;'
        + (colors[type] || colors.info);
    document.body.appendChild(t);
    setTimeout(function(){ t.style.opacity='0';t.style.transition='opacity .3s'; setTimeout(function(){t.remove();},300); }, 3500);
}
</script>

<!-- 侧边栏 -->
<aside class="sidebar" id="sidebar">
  <a class="sidebar-logo" href="index.php">
    <div class="dot"></div>🧭 <?= htmlspecialchars($site_name_admin) ?>
  </a>
  <nav class="sidebar-nav">
    <?php foreach ($nav_items as $item): ?>
      <?php if (isset($item['sep'])): ?>
        <hr class="nav-sep">
      <?php else: ?>
        <a href="<?= htmlspecialchars($item['file'] ?? '') ?>"
           class="nav-item<?= $current_page === ($item['file'] ?? '') ? ' active' : '' ?>">
          <span class="nav-icon"><?= $item['icon'] ?? '' ?></span>
          <?= htmlspecialchars($item['label'] ?? '') ?>
        </a>
      <?php endif; ?>
    <?php endforeach; ?>
  </nav>
  <div class="sidebar-footer">
    👤 <?= htmlspecialchars($current_admin['username']) ?>
    <br>
    <form method="POST" action="/logout.php" style="display:inline">
      <?= csrf_field() ?>
      <button type="submit" style="color:var(--red);margin-top:4px;display:inline-block;background:none;border:none;padding:0;cursor:pointer">退出登录</button>
    </form>
    · <a href="/index.php">返回首页</a>
  </div>
</aside>

<!-- 主内容区 -->
<div class="main-wrap">
  <div class="topbar">
    <div class="topbar-left">
      <button type="button" class="sidebar-toggle" id="sidebarToggle" aria-label="打开导航菜单" aria-controls="sidebar" aria-expanded="false">☰</button>
      <span class="topbar-title"><?= htmlspecialchars($page_title ?? '后台') ?></span>
    </div>
    <div class="topbar-right">
      <span><?= date('Y-m-d H:i') ?></span>
    </div>
  </div>
  <div class="sidebar-backdrop" id="sidebarBackdrop"></div>
  <div class="content">

<!-- Flash 消息 -->
<?php if ($flash): ?>
<?php
  $flash_icon = match($flash['type']) {
      'success' => '✅',
      'warn'    => '⚠️',
      'error'   => '❌',
      default   => 'ℹ️',
  };
  // warn 类型映射到 alert-warn CSS 类
  $flash_css = $flash['type'] === 'warn' ? 'alert-warn' : 'alert-' . $flash['type'];
  // toast 类型映射
  $flash_toast_type = $flash['type'] === 'warn' ? 'warning' : $flash['type'];
?>
<div class="alert <?= htmlspecialchars($flash_css) ?>" style="<?= $flash['type']==='warn' ? 'display:flex;align-items:flex-start;gap:12px;flex-wrap:wrap' : '' ?>">
  <span style="flex:1"><?= $flash_icon ?> <?= htmlspecialchars($flash['msg']) ?></span>
<?php if ($flash['type'] === 'warn' && ($current_admin['role'] ?? '') === 'admin'): ?>
  <form method="POST" action="settings.php" style="margin:0;flex-shrink:0">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="nginx_apply_and_reload">
    <button type="submit" style="background:rgba(251,191,36,.18);border:1px solid rgba(251,191,36,.5);color:#fbbf24;border-radius:8px;padding:6px 14px;font-size:12px;cursor:pointer;white-space:nowrap;font-weight:600">🔄 立即生成配置并 Reload Nginx</button>
  </form>
<?php endif; ?>
</div>
<script>showToast(<?= json_encode($flash['msg']) ?>, <?= json_encode($flash_toast_type) ?>);</script>
<?php endif; ?>

<?php if (!empty($_pending_proxy)): ?>
<div id="proxy-pending-bar" style="background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.35);border-radius:10px;padding:10px 16px;margin-bottom:14px;display:flex;align-items:center;gap:12px;flex-wrap:wrap">
  <span style="color:#f87171;font-size:13px;flex:1">
    ⚠️
    <?php if (count($_pending_proxy) <= 3): ?>
      以下代理站点配置已修改但尚未生效：
      <strong><?= implode('、', array_map(function($s){ return htmlspecialchars($s['name']); }, $_pending_proxy)) ?></strong>
    <?php else: ?>
      有 <strong><?= count($_pending_proxy) ?></strong> 个代理站点配置已修改但尚未生效，请及时 Reload Nginx 使其生效。
    <?php endif; ?>
  </span>
  <?php if (($current_admin['role'] ?? '') === 'admin'): ?>
  <form method="POST" action="settings.php" style="margin:0">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="nginx_apply_and_reload">
    <button type="submit" style="background:rgba(239,68,68,.2);border:1px solid rgba(239,68,68,.5);color:#f87171;border-radius:8px;padding:6px 14px;font-size:12px;cursor:pointer;white-space:nowrap">🔄 生成配置并 Reload Nginx</button>
  </form>
  <?php endif; ?>
</div>
<?php endif; ?>

<script>
(function(){
  var sidebar = document.getElementById('sidebar');
  var toggle = document.getElementById('sidebarToggle');
  var backdrop = document.getElementById('sidebarBackdrop');
  if (!sidebar || !toggle || !backdrop) return;

  function setOpen(open) {
    sidebar.classList.toggle('open', open);
    backdrop.classList.toggle('open', open);
    toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    document.body.classList.toggle('sidebar-open', open);
  }

  toggle.addEventListener('click', function(){
    setOpen(!sidebar.classList.contains('open'));
  });
  backdrop.addEventListener('click', function(){ setOpen(false); });
  window.addEventListener('resize', function(){ if (window.innerWidth > 768) setOpen(false); });
  sidebar.querySelectorAll('a, button').forEach(function(el){
    el.addEventListener('click', function(){ if (window.innerWidth <= 768) setOpen(false); });
  });
})();
</script>
