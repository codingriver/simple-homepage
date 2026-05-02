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
$page_permission = isset($page_permission) ? trim((string)$page_permission) : '';
$current_admin = $page_permission !== '' ? auth_require_permission($page_permission) : auth_require_admin();
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
    ['file' => 'scheduled_tasks.php', 'icon' => '⏱', 'label' => '计划任务'],

    ['sep'],
    ['file' => 'settings.php',      'icon' => '⚙️', 'label' => '系统设置'],
    ['file' => 'notifications.php', 'icon' => '🔔', 'label' => '通知管理'],
    ['file' => 'health_check.php',  'icon' => '💚', 'label' => '健康检测'],
    ['file' => 'backups.php',       'icon' => '💾', 'label' => '备份恢复'],
    ['file' => 'api_tokens.php','icon' => '🔑', 'label' => 'API Token'],
    ['file' => 'users.php',    'icon' => '👥', 'label' => '用户管理'],
    ['file' => 'sessions.php', 'icon' => '📱', 'label' => '会话管理'],
    ['file' => 'logs.php',     'icon' => '📄', 'label' => '日志中心'],
    ['file' => 'debug.php',    'icon' => '🛠', 'label' => '调试工具'],
];
$nav_items = array_values(array_filter($nav_items, static function(array $item): bool {
    if (!isset($item['file'])) {
        return true;
    }

    return true;
}));
?>
<!DOCTYPE html><html lang="zh-CN"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= htmlspecialchars($page_title ?? '后台') ?> — <?= htmlspecialchars($site_name_admin) ?></title>
<?php $adminCssVer = file_exists(__DIR__ . '/admin.css') ? filemtime(__DIR__ . '/admin.css') : time(); ?>
<link rel="stylesheet" href="shared/admin.css?v=<?= $adminCssVer ?>">
<script src="/gesture-guard.js" defer></script>
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

function navStatusEscape(value) {
    return String(value || '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function navCreateAsyncStatus(options) {
    options = options || {};
    var seq = 0;
    var tasks = {};
    var progressTexts = options.progressTexts || {};
    var keepErrorScopes = options.keepErrorScopes || {};

    function refsFor(scope) {
        return typeof options.getRefs === 'function' ? (options.getRefs(scope) || {}) : {};
    }

    function renderFallback(refs, title, detail, percent, tone) {
        if (!refs.wrap) return;
        refs.wrap.style.display = '';
        refs.wrap.innerHTML = '<div style="display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap">'
            + '<div><div style="font-weight:700">' + navStatusEscape(title || '处理中') + '</div>'
            + '<div style="font-size:12px;color:var(--tm);margin-top:4px">' + navStatusEscape(detail || '正在执行…') + '</div></div>'
            + '<div style="font-family:var(--mono);font-size:12px;color:' + (tone === 'error' ? 'var(--red)' : 'var(--tm)') + '">' + Math.max(0, Math.min(100, Math.round(percent || 0))) + '%</div></div>'
            + '<div style="margin-top:10px;height:8px;border-radius:999px;background:rgba(255,255,255,.06);overflow:hidden"><div style="height:100%;width:' + Math.max(0, Math.min(100, percent || 0)) + '%;background:linear-gradient(90deg,' + (tone === 'error' ? 'var(--red),#ff8a96' : 'var(--ac),#64ffd9') + ');transition:width .25s ease"></div></div>';
    }

    function set(scope, title, detail, percent, tone) {
        var refs = refsFor(scope);
        if (refs.wrap && refs.title && refs.text && refs.meta && refs.bar) {
            refs.wrap.style.display = '';
            refs.title.textContent = title || '处理中';
            refs.text.textContent = detail || '正在等待返回…';
            refs.meta.textContent = Math.max(0, Math.min(100, Math.round(percent || 0))) + '%';
            refs.bar.style.width = Math.max(0, Math.min(100, percent || 0)) + '%';
            if (refs.bar.dataset) refs.bar.dataset.tone = tone || '';
            return;
        }
        renderFallback(refs, title, detail, percent, tone);
    }

    function hide(scope) {
        var refs = refsFor(scope);
        if (!refs.wrap) return;
        refs.wrap.style.display = 'none';
        if (refs.bar) refs.bar.style.width = '0%';
        if (refs.title) refs.title.textContent = '';
        if (refs.text) refs.text.textContent = '';
        if (refs.meta) refs.meta.textContent = '';
        if (!refs.title || !refs.text || !refs.meta || !refs.bar) {
            refs.wrap.innerHTML = '';
        }
    }

    function start(scope, title, detail) {
        var id = 'task_' + (++seq);
        var startedAt = Date.now();
        var ticker = setInterval(function() {
            var elapsed = Date.now() - startedAt;
            var percent = elapsed < 800 ? 12 + elapsed / 80 : elapsed < 2400 ? 22 + (elapsed - 800) / 40 : Math.min(92, 62 + (elapsed - 2400) / 180);
            var phase = elapsed < 1000
                ? (progressTexts.connecting || '正在连接服务端…')
                : elapsed < 2600
                    ? (progressTexts.loading || '正在获取数据…')
                    : (progressTexts.processing || '数据较多，继续处理中…');
            set(scope, title || '处理中', detail || phase, percent);
        }, 180);
        tasks[id] = { scope: scope, title: title || '处理中', ticker: ticker };
        set(scope, title || '处理中', detail || '正在准备请求…', 8);
        return id;
    }

    function finish(id, ok, detail) {
        var task = tasks[id];
        if (!task) return;
        clearInterval(task.ticker);
        set(task.scope, task.title, detail || (ok ? '已完成' : '执行失败'), 100, ok ? 'success' : 'error');
        setTimeout(function() {
            if (!ok && keepErrorScopes[task.scope]) return;
            hide(task.scope);
        }, ok ? 500 : 1800);
        delete tasks[id];
    }

    async function run(scope, title, detail, runner, optionsRun) {
        var taskId = start(scope, title, detail);
        try {
            var result = await runner();
            var treatUndefinedAsOk = !(optionsRun && optionsRun.undefinedOk === false);
            var ok = !!(result && (treatUndefinedAsOk ? (result.ok === undefined || result.ok) : result.ok));
            finish(taskId, ok, ok ? ((optionsRun && optionsRun.successText) || '处理完成') : ((result && result.msg) || ((optionsRun && optionsRun.failureText) || '执行失败')));
            return result;
        } catch (err) {
            finish(taskId, false, '请求异常：' + ((err && err.message) ? err.message : 'unknown error'));
            throw err;
        }
    }

    return {
        set: set,
        hide: hide,
        start: start,
        finish: finish,
        run: run
    };
}
</script>

<!-- 全站统一确认弹窗 -->
<div id="nav-confirm-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.65);z-index:10000;align-items:center;justify-content:center;">
  <div style="background:var(--sf);border:1px solid var(--bd);border-radius:14px;padding:24px 28px;min-width:320px;max-width:480px;width:90%;box-shadow:0 12px 40px rgba(0,0,0,.4);">
    <div id="nav-confirm-title" style="font-weight:700;font-size:15px;margin-bottom:10px;color:var(--tx);"></div>
    <div id="nav-confirm-message" style="font-size:13px;color:var(--tx2);line-height:1.6;margin-bottom:20px;white-space:pre-line"></div>
    <div style="display:flex;justify-content:flex-end;gap:10px;">
      <button type="button" id="nav-confirm-cancel" class="btn btn-secondary" style="font-size:13px;padding:6px 14px;">取消</button>
      <button type="button" id="nav-confirm-ok" class="btn btn-primary" style="font-size:13px;padding:6px 14px;">确认</button>
    </div>
  </div>
</div>
<script>
var NavConfirm = (function(){
    var modal = null, titleEl = null, msgEl = null, okBtn = null, cancelBtn = null;
    var onConfirmCb = null, onCancelCb = null;
    var mouseDownTarget = null;

    function init() {
        if (modal) return;
        modal = document.getElementById('nav-confirm-modal');
        if (!modal) return;
        titleEl = document.getElementById('nav-confirm-title');
        msgEl = document.getElementById('nav-confirm-message');
        okBtn = document.getElementById('nav-confirm-ok');
        cancelBtn = document.getElementById('nav-confirm-cancel');

        okBtn.addEventListener('click', function(){
            var cb = onConfirmCb;
            close();
            if (typeof cb === 'function') cb();
        });
        cancelBtn.addEventListener('click', function(){
            var cb = onCancelCb;
            close();
            if (typeof cb === 'function') cb();
        });
        modal.addEventListener('mousedown', function(e){ mouseDownTarget = e.target; });
        modal.addEventListener('click', function(e){
            if (e.target === modal && mouseDownTarget === modal) {
                var cb = onCancelCb;
                close();
                if (typeof cb === 'function') cb();
            }
        });
        document.addEventListener('keydown', function(e){
            if (e.key === 'Escape' && modal && modal.style.display === 'flex') {
                var cb = onCancelCb;
                close();
                if (typeof cb === 'function') cb();
            }
        });
    }

    function open(options) {
        init();
        if (!modal) return;
        options = options || {};
        onConfirmCb = options.onConfirm || null;
        onCancelCb = options.onCancel || null;

        titleEl.textContent = options.title || '确认操作';
        msgEl.textContent = String(options.message || '').split('\\n').join('\n');
        okBtn.textContent = options.confirmText || '确认';
        cancelBtn.textContent = options.cancelText || '取消';

        if (options.danger) {
            okBtn.className = 'btn btn-danger';
        } else {
            okBtn.className = 'btn btn-primary';
        }

        modal.style.display = 'flex';
    }

    function close() {
        if (modal) modal.style.display = 'none';
        onConfirmCb = null;
        onCancelCb = null;
    }

    return { open: open, close: close };
})();

function submitConfirmForm(btn, options) {
    options = options || {};
    var form = btn.closest('form');
    if (!form) return;
    var title = options.title || form.getAttribute('data-confirm-title') || '确认操作';
    var message = options.message || form.getAttribute('data-confirm-message') || '';
    NavConfirm.open({
        title: title,
        message: message,
        confirmText: options.confirmText || '确认',
        cancelText: options.cancelText || '取消',
        danger: options.danger !== false,
        onConfirm: function() { form.submit(); }
    });
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
  <a href="nginx.php" style="background:rgba(251,191,36,.18);border:1px solid rgba(251,191,36,.5);color:#fbbf24;border-radius:8px;padding:6px 14px;font-size:12px;cursor:pointer;white-space:nowrap;font-weight:600;text-decoration:none;display:inline-flex;align-items:center;flex-shrink:0">🔄 前往 Nginx 管理</a>
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
      有 <strong><?= count($_pending_proxy) ?></strong> 个代理站点配置已修改但尚未生效，请及时前往「Nginx 管理」点击「🔄 生成配置并 Reload」。
    <?php endif; ?>
  </span>
  <?php if (($current_admin['role'] ?? '') === 'admin'): ?>
  <a href="nginx.php" style="background:rgba(239,68,68,.2);border:1px solid rgba(239,68,68,.5);color:#f87171;border-radius:8px;padding:6px 14px;font-size:12px;cursor:pointer;white-space:nowrap;text-decoration:none;display:inline-flex;align-items:center">🔄 前往 Nginx 管理</a>
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
