<?php
/**
 * 系统设置 admin/settings.php
 */

// ── 所有需要在 HTML 之前输出的操作（文件下载/导出）──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/shared/functions.php';

    $current_admin = auth_get_current_user();
    if (!$current_admin || ($current_admin['role'] ?? '') !== 'admin') {
        header('Location: /login.php'); exit;
    }
    csrf_check();
    $action = $_POST['action'] ?? '';

        // ── 保存基础设置 ──
        if ($action === 'save_settings') {
            $cfg = load_config();
            $site_name_input = trim($_POST['site_name'] ?? '');
            if ($site_name_input === '') {
                flash_set('error', '站点名称不能为空');
                header('Location: settings.php'); exit;
            }
            if (mb_strlen($site_name_input) > 60) {
                flash_set('error', '站点名称不能超过 60 个字符');
                header('Location: settings.php'); exit;
            }
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
            @set_time_limit(0);
            backup_create('auto_settings');
            $cfg['site_name']          = $site_name_input;
            $cfg['nav_domain']         = trim($_POST['nav_domain']        ?? '');
            $cfg['token_expire_hours'] = max(1, (int)($_POST['token_expire_hours'] ?? 8));
            $cfg['remember_me_days']   = max(1, (int)($_POST['remember_me_days']   ?? 60));
            $cfg['login_fail_limit']   = max(1, (int)($_POST['login_fail_limit']   ?? 5));
            $cfg['login_lock_minutes'] = max(1, (int)($_POST['login_lock_minutes'] ?? 15));
            $cfg['cookie_secure']      = in_array($_POST['cookie_secure'] ?? 'off', ['auto','on','off'])
                                         ? $_POST['cookie_secure'] : 'off';
            $cfg['cookie_domain']      = trim($_POST['cookie_domain'] ?? '');
            $cfg['nginx_access_log_enabled'] = ($_POST['nginx_access_log_enabled'] ?? '0') === '1' ? '1' : '0';
            $taskTimeoutRaw = trim($_POST['task_execution_timeout'] ?? '');
            $cfg['task_execution_timeout'] = ($taskTimeoutRaw === '') ? 7200 : max(0, (int)$taskTimeoutRaw);
            $cfg['theme'] = in_array($_POST['theme'] ?? 'dark', ['dark','light','auto']) ? ($_POST['theme'] ?? 'dark') : 'dark';

            save_config($cfg);
            audit_log('save_settings', ['site_name' => $cfg['site_name']]);
            flash_set('success', '设置已保存');
            header('Location: settings.php'); exit;
        }

        /* ------ 清空计划任务 ------ */
        if ($action === 'clear_scheduled_tasks') {
            require_once __DIR__ . '/shared/cron_lib.php';
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
            @set_time_limit(0);
            backup_create('auto_clear_scheduled_tasks');
            $result = scheduled_tasks_clear_manual_tasks();
            audit_log('clear_scheduled_tasks', ['removed' => (int)($result['removed'] ?? 0)]);
            flash_set('success', '已清空 ' . (int)($result['removed'] ?? 0) . ' 条普通计划任务，DDNS 系统调度器已自动保留/重建');
            header('Location: settings.php'); exit;
        }

        /* ------ 清空 DDNS 任务 ------ */
        if ($action === 'clear_ddns_tasks') {
            require_once __DIR__ . '/shared/ddns_lib.php';
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
            @set_time_limit(0);
            backup_create('auto_clear_ddns_tasks');
            $result = ddns_clear_all_tasks();
            audit_log('clear_ddns_tasks', ['removed' => (int)($result['removed'] ?? 0)]);
            flash_set('success', '已清空 ' . (int)($result['removed'] ?? 0) . ' 条 DDNS 任务，并同步清理日志与系统调度器');
            header('Location: settings.php'); exit;
        }
}

$page_title = '系统设置';
require_once __DIR__ . '/shared/header.php';

$cfg = auth_get_config();

?>

<!-- 基础设置 -->
<div class="card">
  <div class="card-title">⚙️ 基础设置</div>
  <form method="POST" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_settings">
    <div class="form-grid">
      <div class="form-group"><label>站点名称</label>
        <input type="text" name="site_name" value="<?= htmlspecialchars($cfg['site_name']??'后台中心') ?>" required maxlength="60" placeholder="后台中心">
        <div class="form-hint" style="margin-top:6px">显示在浏览器标签页、登录页与后台侧边栏，最多 60 个字符。</div></div>
      <div class="form-group"><label>后台访问域名（可选）</label>
        <input type="text" name="nav_domain" value="<?= htmlspecialchars($cfg['nav_domain']??'') ?>" placeholder="admin.yourdomain.com"></div>
      <div class="form-group"><label>Token有效期（小时）</label>
        <input type="number" name="token_expire_hours" value="<?= (int)($cfg['token_expire_hours']??8) ?>" min="1"></div>
      <div class="form-group"><label>记住我有效期（天）</label>
        <input type="number" name="remember_me_days" value="<?= (int)($cfg['remember_me_days']??60) ?>" min="1"></div>
      <div class="form-group"><label>登录失败锁定次数</label>
        <input type="number" name="login_fail_limit" value="<?= (int)($cfg['login_fail_limit']??5) ?>" min="1"></div>
      <div class="form-group"><label>IP锁定时长（分钟）</label>
        <input type="number" name="login_lock_minutes" value="<?= (int)($cfg['login_lock_minutes']??15) ?>" min="1"></div>
      <div class="form-group"><label>计划任务执行超时（秒）</label>
        <input type="number" name="task_execution_timeout" value="<?= (int)($cfg['task_execution_timeout']??7200) ?>" min="0">
        <div class="form-hint" style="margin-top:6px"><code>0</code> 表示不限制时长。超过此时长的任务将被强制终止（先 SIGTERM，10 秒后未退出则 SIGKILL）。默认 <code>7200</code> 秒（2 小时）。</div></div>
      <div class="form-group">
        <label>Cookie Secure 模式</label>
        <select name="cookie_secure" style="width:100%;background:var(--bg);border:1px solid var(--bd);border-radius:8px;padding:10px 12px;color:var(--tx);font-size:14px;outline:none">
          <option value="off"  <?= ($cfg['cookie_secure']??'off')==='off'  ? 'selected' : '' ?>>🔓 off — 关闭（默认，内网 HTTP / 本地调试）</option>
          <option value="auto" <?= ($cfg['cookie_secure']??'off')==='auto' ? 'selected' : '' ?>>🔍 auto — 自动检测（HTTPS 时开启，HTTP 时关闭）</option>
          <option value="on"   <?= ($cfg['cookie_secure']??'off')==='on'   ? 'selected' : '' ?>>🔒 on — 强制开启（生产环境全程 HTTPS）</option>
        </select>
      </div>
      <div class="form-group">
        <label>Cookie Domain（跨子域 SSO）</label>
        <input type="text" name="cookie_domain" value="<?= htmlspecialchars($cfg['cookie_domain']??'') ?>" placeholder="留空=自动（推荐 IP 访问时留空）">
      </div>
      <div class="form-group">
        <label>Nginx 访问日志</label>
        <select name="nginx_access_log_enabled" style="width:100%;background:var(--bg);border:1px solid var(--bd);border-radius:8px;padding:10px 12px;color:var(--tx);font-size:14px;outline:none">
          <option value="0" <?= ($cfg['nginx_access_log_enabled']??'0')==='0'?'selected':'' ?>>关闭（默认，降低日志 IO）</option>
          <option value="1" <?= ($cfg['nginx_access_log_enabled']??'0')==='1'?'selected':'' ?>>开启（调试访问问题）</option>
        </select>
        <div class="form-hint" style="margin-top:6px">保存后需重启 Docker 容器生效；后台不执行 Nginx Reload。</div>
      </div>
      <div class="form-group">
        <label>主题模式</label>
        <select name="theme" style="width:100%;background:var(--bg);border:1px solid var(--bd);border-radius:8px;padding:10px 12px;color:var(--tx);font-size:14px;outline:none">
          <option value="dark" <?= ($cfg['theme']??'dark')==='dark'?'selected':'' ?>>🌙 深色模式</option>
          <option value="light" <?= ($cfg['theme']??'dark')==='light'?'selected':'' ?>>☀️ 浅色模式</option>
          <option value="auto" <?= ($cfg['theme']??'dark')==='auto'?'selected':'' ?>>🖥️ 跟随系统</option>
        </select>
      </div>
    </div>
    <div class="form-actions"><button type="submit" class="btn btn-primary">保存设置</button></div>
  </form>
</div>

<div class="card">
  <div class="card-title" style="color:#ff9f43">⚠ 危险操作</div>
  <div class="form-hint" style="margin-bottom:12px">
    下列操作会先自动创建备份，再执行清空。清空计划任务不会删除 <code>data/tasks/</code> 目录中的其他共享文件，只会删除系统管理的任务脚本、任务日志和锁文件。
  </div>
  <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
    <form method="POST" data-confirm-title="清空计划任务" data-confirm-message="确认清空全部普通计划任务？\n\n会删除系统生成的任务脚本、任务日志、锁文件，并重新生成 crontab。">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="clear_scheduled_tasks">
      <button class="btn btn-danger" type="button" onclick="submitConfirmForm(this)">🗑 清空计划任务</button>
    </form>
    <form method="POST" data-confirm-title="清空 DDNS 任务" data-confirm-message="确认清空全部 DDNS 任务？\n\n会删除 DDNS 任务定义、每个任务日志、全局 DDNS 日志，并移除自动生成的 DDNS 调度器。">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="clear_ddns_tasks">
      <button class="btn btn-danger" type="button" onclick="submitConfirmForm(this)">🗑 清空 DDNS 任务</button>
    </form>
  </div>
</div>

<?php require_once __DIR__ . '/shared/footer.php'; ?>
