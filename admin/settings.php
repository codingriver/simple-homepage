<?php
/**
 * 系统设置 admin/settings.php
 */

// ── 所有需要在 HTML 之前输出的操作（文件下载/导出）──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/shared/functions.php';
    require_once __DIR__ . '/shared/host_agent_lib.php';

    $current_admin = auth_get_current_user();
    if (!$current_admin || ($current_admin['role'] ?? '') !== 'admin') {
        header('Location: /login.php'); exit;
    }
    csrf_check();
    $action = $_POST['action'] ?? '';

        // ── 导出配置（与备份下载、backup_create 同一载荷：含计划任务与 DNS 账户）──
        if ($action === 'export_sites' || $action === 'export_config') {
            $export = backup_collect_payload('export');
            $json = json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="nav_export_' . date('Ymd_His') . '.json"');
            header('Content-Length: ' . strlen($json));
            echo $json; exit;
        }

        // ── 导入配置（兼容备份格式和旧 sites-only 格式）──
        if ($action === 'import_sites' || $action === 'import_config') {
            if (empty($_FILES['import_file']['tmp_name'])) {
                flash_set('error', '请选择要导入的文件');
                header('Location: settings.php'); exit;
            }
            if ($_FILES['import_file']['size'] > 4 * 1024 * 1024) {
                flash_set('error', '文件过大，配置文件不应超过 4MB');
                header('Location: settings.php'); exit;
            }
            $raw = file_get_contents($_FILES['import_file']['tmp_name']);
            if ($raw === false || strlen($raw) === 0) {
                flash_set('error', '文件读取失败或文件为空');
                header('Location: settings.php'); exit;
            }
            try {
                $obj = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                flash_set('error', 'JSON 格式解析错误：' . $e->getMessage());
                header('Location: settings.php'); exit;
            }
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
            @set_time_limit(0);
            backup_create('auto_import');
            // 识别格式：新备份格式 {created_at, trigger, sites:{groups:[]}, config:{}}
            //           旧格式 {groups:[]}
            if (isset($obj['sites']['groups']) && is_array($obj['sites']['groups'])) {
                // 新统一格式（与备份恢复一致，可选含 scheduled_tasks、dns_config）
                $merged_cfg = !empty($obj['config']) && is_array($obj['config'])
                    ? array_merge(auth_default_config(), $obj['config'])
                    : auth_default_config();
                $apply = [
                    'sites'  => $obj['sites'],
                    'config' => $merged_cfg,
                ];
                if (isset($obj['scheduled_tasks']) && is_array($obj['scheduled_tasks'])) {
                    $apply['scheduled_tasks'] = $obj['scheduled_tasks'];
                }
                if (isset($obj['dns_config']) && is_array($obj['dns_config'])) {
                    $apply['dns_config'] = $obj['dns_config'];
                }
                backup_apply_restored_sections($apply);
                $gc = count($obj['sites']['groups']);
                $parts = [$gc . ' 个分组'];
                if (isset($apply['scheduled_tasks']) && is_array($apply['scheduled_tasks'])) {
                    $tc = count($apply['scheduled_tasks']['tasks'] ?? []);
                    $parts[] = $tc . ' 条计划任务';
                }
                if (isset($apply['dns_config'])) {
                    $parts[] = 'DNS 账户已同步';
                }
                flash_set('success', '导入成功（完整格式）：' . implode('，', $parts) . '；旧配置已自动备份');
            } elseif (isset($obj['groups']) && is_array($obj['groups'])) {
                // 旧 sites-only 格式
                file_put_contents(SITES_FILE, json_encode($obj, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
                flash_set('success', '导入成功（站点格式），共 ' . count($obj['groups']) . ' 个分组，旧配置已自动备份');
            } else {
                flash_set('error', '文件结构无效：无法识别的配置格式，请使用导出配置或备份文件');
                header('Location: settings.php'); exit;
            }
            header('Location: settings.php'); exit;
        }

        // ── 下载 Nginx 配置 ──
        if ($action === 'gen_nginx') {
            $cfg        = load_config();
            $sites_data = load_sites();
            $domain     = $cfg['nav_domain'] ?? 'nav.yourdomain.com';
            $lines      = ['# Nginx 代理配置 — 由导航站自动生成于 ' . date('Y-m-d H:i:s'), ''];
            foreach ($sites_data['groups'] as $grp) {
                foreach ($grp['sites'] ?? [] as $s) {
                    if (($s['type'] ?? '') !== 'proxy') continue;
                    $target = $s['proxy_target'] ?? '';
                    $name   = $s['name'] ?? $s['id'];
                    if (($s['proxy_mode'] ?? 'path') === 'path') {
                        $slug = $s['slug'] ?? $s['id'];
                        $lines[] = "# {$name}";
                        $lines[] = "location /p/{$slug}/ {";
                        $lines[] = "    proxy_pass {$target}/;";
                        $lines[] = "    proxy_set_header Host \$host;";
                        $lines[] = "    proxy_set_header X-Real-IP \$remote_addr;";
                        $lines[] = "}";
                        $lines[] = '';
                    } else {
                        $pd = $s['proxy_domain'] ?? '';
                        if (!$pd) continue;
                        $lines[] = "# {$name} (子域名模式)";
                        $lines[] = 'server {';
                        $lines[] = "    listen 443 ssl http2;";
                        $lines[] = "    server_name {$pd};";
                        $lines[] = "    location / { proxy_pass {$target}; }";
                        $lines[] = '}';
                        $lines[] = '';
                    }
                }
            }
            $content = implode("\n", $lines);
            header('Content-Type: text/plain; charset=utf-8');
            header('Content-Disposition: attachment; filename="nav_proxy_' . date('Ymd_His') . '.conf"');
            header('Content-Length: ' . strlen($content));
            echo $content; exit;
        }

        // ── 手动备份 ──
        if ($action === 'manual_backup') {
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
            @set_time_limit(0);
            backup_create('manual');
            flash_set('success', '备份已创建');
            header('Location: settings.php'); exit;
        }

        if ($action === 'clear_scheduled_tasks') {
            require_once __DIR__ . '/shared/cron_lib.php';
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
            @set_time_limit(0);
            backup_create('auto_clear_scheduled_tasks');
            $result = scheduled_tasks_clear_manual_tasks();
            flash_set('success', '已清空 ' . (int)($result['removed'] ?? 0) . ' 条普通计划任务，DDNS 系统调度器已自动保留/重建');
            header('Location: settings.php'); exit;
        }

        if ($action === 'clear_ddns_tasks') {
            require_once __DIR__ . '/shared/ddns_lib.php';
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
            @set_time_limit(0);
            backup_create('auto_clear_ddns_tasks');
            $result = ddns_clear_all_tasks();
            flash_set('success', '已清空 ' . (int)($result['removed'] ?? 0) . ' 条 DDNS 任务，并同步清理日志与系统调度器');
            header('Location: settings.php'); exit;
        }

        if ($action === 'host_agent_install') {
            $result = host_agent_install();
            flash_set($result['ok'] ? 'success' : 'error', (string)($result['msg'] ?? 'host-agent 安装失败'));
            header('Location: settings.php#host-agent'); exit;
        }

        // ── Nginx 写入 + reload（带预检与失败回滚）──
        if ($action === 'nginx_apply' || $action === 'nginx_reload' || $action === 'nginx_apply_and_reload') {
            $do_reload = ($action === 'nginx_reload' || $action === 'nginx_apply_and_reload');
            $result = nginx_apply_proxy_conf($do_reload);

            if (!$result['ok']) {
                flash_set('error', $result['msg']);
                header('Location: settings.php#nginx'); exit;
            }

            if ($do_reload) {
                nginx_mark_applied();
            }

            flash_set('success', $result['msg']);
            $redirect = ($action === 'nginx_apply_and_reload') ? 'settings.php' : 'settings.php#nginx';
            header('Location: ' . $redirect); exit;
        }

        // ── 保存反代参数模式 ──
        if ($action === 'save_proxy_params_mode') {
            $cfg = load_config();
            $cfg['proxy_params_mode'] = ($_POST['proxy_params_mode'] ?? 'simple') === 'full' ? 'full' : 'simple';
            save_config($cfg);
            // 统计 proxy 站点数量，>0 时提示立即 reload
            $proxy_cnt_check = 0;
            foreach (load_sites()['groups'] ?? [] as $_g)
                foreach ($_g['sites'] ?? [] as $_s)
                    if (($_s['type'] ?? '') === 'proxy') $proxy_cnt_check++;
            if ($proxy_cnt_check > 0) {
                flash_set('warn', "反代参数模式已切换为【" . ($cfg['proxy_params_mode'] === 'full' ? '完整模式' : '精简模式') . "】，当前有 {$proxy_cnt_check} 个代理站点，需要重新生成配置并 Reload Nginx 才能生效。");
            } else {
                flash_set('success', '反代参数模式已保存（当前无代理站点，无需 Reload）');
            }
            header('Location: settings.php#nginx'); exit;
        }

        // ── 保存 Webhook 设置 ──
        if ($action === 'save_webhook') {
            $cfg = load_config();
            $cfg['webhook_enabled'] = ($_POST['webhook_enabled'] ?? '0') === '1' ? '1' : '0';
            $cfg['webhook_type']    = in_array($_POST['webhook_type'] ?? 'custom', ['telegram','feishu','dingtalk','custom'])
                                      ? $_POST['webhook_type'] : 'custom';
            $cfg['webhook_url']     = trim($_POST['webhook_url']     ?? '');
            $cfg['webhook_tg_chat'] = trim($_POST['webhook_tg_chat'] ?? '');
            $events_raw = $_POST['webhook_events'] ?? [];
            $allowed_events = ['SUCCESS','FAIL','IP_LOCKED','LOGOUT','SETUP'];
            $events = array_values(array_intersect((array)$events_raw, $allowed_events));
            $cfg['webhook_events']  = implode(',', $events ?: ['FAIL','IP_LOCKED']);
            save_config($cfg);
            flash_set('success', 'Webhook 设置已保存');
            header('Location: settings.php#webhook'); exit;
        }

        // ── 测试 Webhook ──
        if ($action === 'test_webhook') {
            $result = webhook_test();
            flash_set($result['ok'] ? 'success' : 'error', $result['msg']);
            header('Location: settings.php#webhook'); exit;
        }

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
            if (isset($_POST['proxy_params_mode'])) {
                $cfg['proxy_params_mode']  = ($_POST['proxy_params_mode'] ?? 'simple') === 'full' ? 'full' : 'simple';
            }
            $cfg['ssh_terminal_persist'] = ($_POST['ssh_terminal_persist'] ?? '1') === '1' ? '1' : '0';
            $cfg['ssh_terminal_idle_minutes'] = max(5, min(10080, (int)($_POST['ssh_terminal_idle_minutes'] ?? 120)));
            // ── 卡片尺寸（支持自定义）──
            $card_size_raw = $_POST['card_size'] ?? '140';
            if ($card_size_raw === 'custom' || !empty($_POST['card_size_custom'])) {
                $card_size = (int)($_POST['card_size_custom'] ?? 140);
            } else {
                $card_size = (int)$card_size_raw;
            }
            $cfg['card_size'] = max(50, min(600, $card_size ?: 140));
            // ── 卡片高度（支持自定义）──
            $card_height_raw = $_POST['card_height'] ?? '0';
            if ($card_height_raw === 'custom' || !empty($_POST['card_height_custom'])) {
                $card_height = (int)($_POST['card_height_custom'] ?? 0);
            } else {
                $card_height = max(0, (int)$card_height_raw);
            }
            $cfg['card_height']    = max(0, min(800, $card_height));
            $cfg['card_show_desc'] = isset($_POST['card_show_desc']) ? '1' : '0';
            $cfg['card_layout']    = in_array($_POST['card_layout'] ?? 'grid', ['grid','list','compact','large']) ? $_POST['card_layout'] : 'grid';
            $cfg['card_direction'] = in_array($_POST['card_direction'] ?? 'col', ['col','row','row-reverse','col-center']) ? $_POST['card_direction'] : 'col';
            $bg_color = trim($_POST['bg_color'] ?? '');
            if ($bg_color && !preg_match('/^#[0-9a-fA-F]{3,8}$/', $bg_color)) {
                flash_set('error', '背景色格式无效'); header('Location: settings.php'); exit;
            }
            $cfg['bg_color'] = $bg_color;
            if (!empty($_FILES['bg_image']['tmp_name'])) {
                $file = $_FILES['bg_image'];
                if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                    flash_set('error', '背景图上传失败，请重试');
                    header('Location: settings.php'); exit;
                }
                if (!is_uploaded_file($file['tmp_name'])) {
                    flash_set('error', '背景图上传来源无效');
                    header('Location: settings.php'); exit;
                }
                if (($file['size'] ?? 0) <= 0 || ($file['size'] ?? 0) > 8 * 1024 * 1024) {
                    flash_set('error', '背景图大小需在 8MB 以内');
                    header('Location: settings.php'); exit;
                }
                $mime = function_exists('mime_content_type') ? mime_content_type($file['tmp_name']) : false;
                $mime_map = [
                    'image/jpeg' => 'jpg',
                    'image/png'  => 'png',
                    'image/gif'  => 'gif',
                    'image/webp' => 'webp',
                ];
                if (!$mime || !isset($mime_map[$mime]) || @getimagesize($file['tmp_name']) === false) {
                    flash_set('error', '背景图内容无效，只支持 jpg/png/gif/webp 图片');
                    header('Location: settings.php'); exit;
                }
                if (!is_dir(BG_DIR)) mkdir(BG_DIR, 0755, true);
                $fname = 'bg_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $mime_map[$mime];
                if (!move_uploaded_file($file['tmp_name'], BG_DIR . '/' . $fname)) {
                    flash_set('error', '背景图保存失败，请检查目录权限');
                    header('Location: settings.php'); exit;
                }
                if (!empty($cfg['bg_image']) && file_exists(BG_DIR . '/' . $cfg['bg_image'])) {
                    @unlink(BG_DIR . '/' . $cfg['bg_image']);
                }
                $cfg['bg_image'] = $fname;
                $cfg['bg_color'] = '';
            }
            if (!empty($_POST['clear_bg_image'])) {
                if (!empty($cfg['bg_image']) && file_exists(BG_DIR . '/' . $cfg['bg_image'])) {
                    @unlink(BG_DIR . '/' . $cfg['bg_image']);
                }
                $cfg['bg_image'] = '';
            }
            save_config($cfg);
            flash_set('success', '设置已保存');
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
        <input type="text" name="site_name" value="<?= htmlspecialchars($cfg['site_name']??'导航中心') ?>" required maxlength="60" placeholder="导航中心">
        <div class="form-hint" style="margin-top:6px">显示在浏览器标签页、登录页、首页标题栏及后台侧边栏，最多 60 个字符。</div></div>
      <div class="form-group"><label>导航站域名</label>
        <input type="text" name="nav_domain" value="<?= htmlspecialchars($cfg['nav_domain']??'') ?>" placeholder="nav.yourdomain.com"></div>
      <div class="form-group"><label>Token有效期（小时）</label>
        <input type="number" name="token_expire_hours" value="<?= (int)($cfg['token_expire_hours']??8) ?>" min="1"></div>
      <div class="form-group"><label>记住我有效期（天）</label>
        <input type="number" name="remember_me_days" value="<?= (int)($cfg['remember_me_days']??60) ?>" min="1"></div>
      <div class="form-group"><label>登录失败锁定次数</label>
        <input type="number" name="login_fail_limit" value="<?= (int)($cfg['login_fail_limit']??5) ?>" min="1"></div>
      <div class="form-group"><label>IP锁定时长（分钟）</label>
        <input type="number" name="login_lock_minutes" value="<?= (int)($cfg['login_lock_minutes']??15) ?>" min="1"></div>
      <div class="form-group"><label>SSH Web 终端默认后台继续</label>
        <select name="ssh_terminal_persist" style="width:100%;background:var(--bg);border:1px solid var(--bd);border-radius:8px;padding:10px 12px;color:var(--tx);font-size:14px;outline:none">
          <option value="1" <?= ($cfg['ssh_terminal_persist'] ?? '1') === '1' ? 'selected' : '' ?>>开启</option>
          <option value="0" <?= ($cfg['ssh_terminal_persist'] ?? '1') !== '1' ? 'selected' : '' ?>>关闭</option>
        </select>
        <div class="form-hint" style="margin-top:6px">开启后，Web 终端在浏览器页面关闭后仍会继续后台运行，可在主机管理页恢复会话。</div></div>
      <div class="form-group"><label>SSH Web 终端空闲保留时长（分钟）</label>
        <input type="number" name="ssh_terminal_idle_minutes" value="<?= (int)($cfg['ssh_terminal_idle_minutes']??120) ?>" min="5" max="10080">
        <div class="form-hint" style="margin-top:6px">超过该时长且无人继续查看或输入时，会自动清理终端会话。</div></div>
      <div class="form-group">
        <label>Cookie Secure 模式</label>
        <select name="cookie_secure" style="width:100%;background:var(--bg);border:1px solid var(--bd);border-radius:8px;padding:10px 12px;color:var(--tx);font-size:14px;outline:none">
          <option value="off"  <?= ($cfg['cookie_secure']??'off')==='off'  ? 'selected' : '' ?>>🔓 off — 关闭（默认，内网 HTTP / 本地调试）</option>
          <option value="auto" <?= ($cfg['cookie_secure']??'off')==='auto' ? 'selected' : '' ?>>🔍 auto — 自动检测（HTTPS 时开启，HTTP 时关闭）</option>
          <option value="on"   <?= ($cfg['cookie_secure']??'off')==='on'   ? 'selected' : '' ?>>🔒 on — 强制开启（生产环境全程 HTTPS）</option>
        </select>
        <div class="form-hint" style="margin-top:6px">
          <b>off</b>：Cookie 在 HTTP/HTTPS 均可发送，适合内网调试和 IP 直接访问。<br>
          <b>auto</b>：自动识别协议，兼容 VPS-A 网关反代（X-Forwarded-Proto）。<br>
          <b>on</b>：Cookie 仅 HTTPS 发送，HTTP 访问将<span style="color:#ff6b6b">无法登录</span>。
        </div>
      </div>
      <div class="form-group">
        <label>Cookie Domain（跨子域 SSO）</label>
        <input type="text" name="cookie_domain" value="<?= htmlspecialchars($cfg['cookie_domain']??'') ?>" placeholder="留空=自动（推荐 IP 访问时留空）">
        <div class="form-hint" style="margin-top:6px">
          <b>留空</b>：Cookie 绑定当前访问的 host，IP 访问和单域名访问均正常工作。<br>
          <b>填写 .yourdomain.com</b>（前面有点）：Cookie 共享到所有子域，实现 SSO 单点登录。<br>
          <span style="color:#ff6b6b">⚠️ 填写域名后用 IP 访问将无法登录，只能二选一。</span>
        </div>
      </div>
      <div class="form-group"><label>自定义背景色（十六进制）</label>
        <input type="text" name="bg_color" value="<?= htmlspecialchars($cfg['bg_color']??'') ?>" placeholder="#1a1d27"></div>
      <!-- ══ 卡片外观设置（合并容器）══ -->
      <div class="form-group" style="grid-column:1/-1">
        <label style="font-weight:700;font-size:13px;color:var(--ac2);margin-bottom:10px;display:block">🃏 卡片外观设置</label>
        <div style="background:var(--bg);border:1px solid var(--bd);border-radius:10px;padding:16px;display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:12px">

          <!-- 宽度 -->
          <div>
            <label style="font-size:12px;color:var(--tm);margin-bottom:6px;display:block">最小宽度（px）</label>
            <div style="display:flex;gap:6px">
              <select id="card_size_sel" name="card_size" onchange="syncCustom('card_size')" style="flex:1;background:var(--sf);border:1px solid var(--bd);border-radius:7px;padding:8px 10px;color:var(--tx);font-size:13px;outline:none">
                <?php
                $cs = (int)($cfg['card_size']??140);
                $cs_opts=[50=>'极紧 50',80=>'极小 80',100=>'紧凑 100',120=>'小 120',140=>'默认 140',160=>'中 160',185=>'大 185',220=>'超大 220',260=>'巨大 260','custom'=>'自定义…'];
                $cs_in_opts = array_key_exists($cs, $cs_opts);
                foreach($cs_opts as $v=>$l): ?>
                <option value="<?=$v?>" <?= ($v===$cs || (!$cs_in_opts && $v==='custom')) ? 'selected':'' ?>><?=$l?><?php if($v!='custom') echo ' px'; ?></option>
                <?php endforeach; ?>
              </select>
              <input type="number" id="card_size_custom" name="card_size_custom" min="50" max="600"
                     value="<?= !$cs_in_opts ? $cs : '' ?>"
                     placeholder="自定义"
                     style="width:80px;background:var(--sf);border:1px solid var(--bd);border-radius:7px;padding:8px 10px;color:var(--tx);font-size:13px;outline:none;display:<?= !$cs_in_opts?'block':'none' ?>">
            </div>
          </div>

          <!-- 高度 -->
          <div>
            <label style="font-size:12px;color:var(--tm);margin-bottom:6px;display:block">固定高度（px，0=自动）</label>
            <div style="display:flex;gap:6px">
              <select id="card_height_sel" name="card_height" onchange="syncCustom('card_height')" style="flex:1;background:var(--sf);border:1px solid var(--bd);border-radius:7px;padding:8px 10px;color:var(--tx);font-size:13px;outline:none">
                <?php
                $ch = (int)($cfg['card_height']??0);
                $ch_opts=[0=>'自动',50=>'超紧 50',70=>'极小 70',90=>'紧凑 90',110=>'小 110',130=>'标准 130',160=>'中 160',200=>'大 200','custom'=>'自定义…'];
                $ch_in_opts = array_key_exists($ch, $ch_opts);
                foreach($ch_opts as $v=>$l): ?>
                <option value="<?=$v?>" <?= ($v===$ch || (!$ch_in_opts && $v==='custom')) ? 'selected':'' ?>><?=$l?><?php if(is_int($v)&&$v>0) echo ' px'; ?></option>
                <?php endforeach; ?>
              </select>
              <input type="number" id="card_height_custom" name="card_height_custom" min="0" max="800"
                     value="<?= !$ch_in_opts && $ch>0 ? $ch : '' ?>"
                     placeholder="自定义"
                     style="width:80px;background:var(--sf);border:1px solid var(--bd);border-radius:7px;padding:8px 10px;color:var(--tx);font-size:13px;outline:none;display:<?= !$ch_in_opts && $ch>0?'block':'none' ?>">
            </div>
          </div>

          <!-- 布局方案 -->
          <div>
            <label style="font-size:12px;color:var(--tm);margin-bottom:6px;display:block">卡片布局方案</label>
            <select name="card_layout" style="width:100%;background:var(--sf);border:1px solid var(--bd);border-radius:7px;padding:8px 10px;color:var(--tx);font-size:13px;outline:none">
              <?php $cl=$cfg['card_layout']??'grid'; foreach(['grid'=>'🔲 网格（默认）','compact'=>'⚡ 紧凑小图标','list'=>'📋 列表（宽行）','large'=>'🖼 大卡片（封面风格）'] as $v=>$l): ?>
              <option value="<?=$v?>" <?= $cl===$v?'selected':'' ?>><?=$l?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <!-- 内容排列方向 -->
          <div>
            <label style="font-size:12px;color:var(--tm);margin-bottom:6px;display:block">内容排列方向</label>
            <select name="card_direction" style="width:100%;background:var(--sf);border:1px solid var(--bd);border-radius:7px;padding:8px 10px;color:var(--tx);font-size:13px;outline:none">
              <?php $cd=$cfg['card_direction']??'col'; foreach(['col'=>'⬇ 上下（图标在上）','row'=>'➡ 左右（图标在左）','row-reverse'=>'⬅ 左右反（图标在右）','col-center'=>'⬇ 上下居中'] as $v=>$l): ?>
              <option value="<?=$v?>" <?= $cd===$v?'selected':'' ?>><?=$l?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <!-- 显示描述 -->
          <div style="display:flex;align-items:center;gap:10px">
            <label style="font-size:12px;color:var(--tm)">显示卡片描述</label>
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
              <input type="checkbox" name="card_show_desc" value="1" <?= ($cfg['card_show_desc']??'1')==='1'?'checked':'' ?>
                     style="width:16px;height:16px;accent-color:var(--ac)">
              <span style="font-size:12px;color:var(--tx)">显示</span>
            </label>
          </div>

        </div><!-- /卡片外观内容 -->
      </div><!-- /form-group -->
      <div class="form-group"><label>背景图上传（jpg/png/webp）</label>
        <input type="file" name="bg_image" accept="image/*" style="color:var(--tx)">
        <?php if (!empty($cfg['bg_image'])): ?>
        <div class="form-hint">当前：<?= htmlspecialchars($cfg['bg_image']) ?>
          <label style="margin-left:8px"><input type="checkbox" name="clear_bg_image" value="1"> 删除</label></div>
        <?php endif; ?>
      </div>
    </div>
    <div class="form-actions"><button type="submit" class="btn btn-primary">保存设置</button></div>
  </form>
</div>

<!-- 数据管理 -->
<div class="card">
  <div class="card-title">📦 数据管理</div>
  <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
    <form method="POST"><?= csrf_field() ?>
      <input type="hidden" name="action" value="export_config">
      <button class="btn btn-secondary">⬇ 导出配置</button>
    </form>
    <form method="POST" enctype="multipart/form-data" id="importForm"><?= csrf_field() ?>
      <input type="hidden" name="action" value="import_config">
      <input type="file" name="import_file" accept=".json" id="importFile" style="display:none"
             onchange="handleImportFile(this)">
      <button type="button" class="btn btn-secondary" onclick="document.getElementById('importFile').click()">⬆ 导入配置</button>
    </form>
    <form method="POST"><?= csrf_field() ?>
      <input type="hidden" name="action" value="manual_backup">
      <button class="btn btn-secondary">💾 立即备份</button>
    </form>
    <form method="POST"><?= csrf_field() ?>
      <input type="hidden" name="action" value="gen_nginx">
      <button class="btn btn-secondary">⬇ 下载 Nginx 配置</button>
    </form>
    <a href="backups.php" class="btn btn-secondary">📋 备份管理</a>
  </div>
  <div class="form-hint" style="margin-top:10px">「导出配置」与「备份下载」为同一 JSON 结构（站点、系统配置、计划任务含脚本、DNS 解析账户）。不含用户账户、任务日志、<code>data/tasks/</code> 共享工作目录下的额外文件等。导入时自动识别格式。</div>
</div>

<div class="card">
  <div class="card-title" style="color:#ff9f43">⚠ 危险操作</div>
  <div class="form-hint" style="margin-bottom:12px">
    下列操作会先自动创建备份，再执行清空。清空计划任务不会删除 <code>data/tasks/</code> 目录中的其他共享文件，只会删除系统管理的任务脚本、任务日志和锁文件。
  </div>
  <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
    <form method="POST" onsubmit="return confirm('确认清空全部普通计划任务？\\n\\n会删除系统生成的任务脚本、任务日志、锁文件，并重新生成 crontab。\\n不会删除 data/tasks 目录里的其他共享文件。');">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="clear_scheduled_tasks">
      <button class="btn btn-danger" type="submit">🗑 清空计划任务</button>
    </form>
    <form method="POST" onsubmit="return confirm('确认清空全部 DDNS 任务？\\n\\n会删除 DDNS 任务定义、每个任务日志、全局 DDNS 日志，并移除自动生成的 DDNS 调度器。');">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="clear_ddns_tasks">
      <button class="btn btn-danger" type="submit">🗑 清空 DDNS 任务</button>
    </form>
  </div>
</div>

<div class="card" id="host-agent">
  <div class="card-title">🧩 Host-Agent
    <span style="font-size:11px;color:var(--tm);font-weight:400;margin-left:8px">宿主机能力桥接</span>
  </div>
  <div id="host-agent-banner" class="form-hint" style="margin-bottom:12px">
    正在检测 docker.sock 挂载和 host-agent 运行状态...
  </div>
  <div style="display:flex;gap:16px;flex-wrap:wrap;align-items:stretch;margin-bottom:12px">
    <div style="background:var(--bg);border:1px solid var(--bd);border-radius:10px;padding:14px 16px;min-width:220px;flex:1">
      <div style="font-size:11px;color:var(--tm);margin-bottom:4px">安装模式</div>
      <div id="host-agent-mode" style="font-size:14px;font-weight:700;color:var(--tx)">待检测</div>
      <div class="form-hint" style="margin-top:8px">默认不强制启用。只有你主动给当前应用容器临时挂载 <code>/var/run/docker.sock</code> 时，后台才会允许一键安装独立的 <code>host-agent</code> 容器；确认正常后建议移除该挂载。</div>
    </div>
    <div style="background:var(--bg);border:1px solid var(--bd);border-radius:10px;padding:14px 16px;min-width:220px;flex:1">
      <div style="font-size:11px;color:var(--tm);margin-bottom:4px">运行状态</div>
      <div id="host-agent-status-text" style="font-size:14px;font-weight:700;color:var(--tx)">待检测</div>
      <div id="host-agent-container-name" class="form-hint" style="margin-top:8px;font-family:var(--mono)"></div>
    </div>
    <div style="background:var(--bg);border:1px solid var(--bd);border-radius:10px;padding:14px 16px;min-width:220px;flex:1">
      <div style="font-size:11px;color:var(--tm);margin-bottom:4px">服务入口</div>
      <div id="host-agent-service-url" style="font-size:13px;font-family:var(--mono);word-break:break-all;color:var(--tx)">待检测</div>
      <div class="form-hint" style="margin-top:8px">后续本机文件管理、终端、本机 SSH 配置修改与服务控制都将优先通过 host-agent 处理，不再依赖 SSH 连接宿主机。</div>
    </div>
  </div>
  <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:12px">
    <form method="POST" id="host-agent-install-form" style="display:inline" onsubmit="return confirmHostAgentInstall()">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="host_agent_install">
      <button class="btn btn-primary" id="host-agent-install-btn" type="submit">🚀 一键安装 Host-Agent</button>
    </form>
    <button type="button" class="btn btn-secondary" id="host-agent-refresh-btn" onclick="loadHostAgentStatus(true)">🔄 刷新状态</button>
  </div>
  <div id="host-agent-socket-note" class="form-hint"></div>
  <pre id="host-agent-compose-snippet" style="margin-top:10px;background:var(--bg);border:1px solid var(--bd);border-radius:8px;padding:12px;font-size:12px;display:none;overflow:auto"></pre>
</div>

<script>
// ── 导入配置前端校验（兼容备份格式和旧 sites-only 格式）──
function handleImportFile(input) {
    if (!input.files || !input.files.length) return;
    var file = input.files[0];
    if (file.size > 4 * 1024 * 1024) {
        showToast('文件过大，配置文件不应超过 4MB', 'error');
        input.value = '';
        return;
    }
    var reader = new FileReader();
    reader.onload = function(e) {
        try {
            var obj = JSON.parse(e.target.result);
            var groupCount = 0;
            var formatLabel = '';
            if (obj && obj.sites && Array.isArray(obj.sites.groups)) {
                // 新统一格式（备份/导出）
                groupCount = obj.sites.groups.length;
                var extras = [];
                var tasks = obj.scheduled_tasks && obj.scheduled_tasks.tasks;
                if (Array.isArray(tasks) && tasks.length) {
                    extras.push('计划任务 ' + tasks.length + ' 条（含脚本）');
                }
                if (obj.dns_config && typeof obj.dns_config === 'object') {
                    extras.push('DNS 账户');
                }
                formatLabel = '完整备份格式，' + groupCount + ' 个分组及系统配置' +
                    (extras.length ? '，另含 ' + extras.join('、') : '');
            } else if (obj && Array.isArray(obj.groups)) {
                // 旧 sites-only 格式
                groupCount = obj.groups.length;
                formatLabel = '站点格式，含 ' + groupCount + ' 个分组';
            } else {
                showToast('无法识别的配置格式，请使用导出配置或备份文件', 'error');
                input.value = '';
                return;
            }
            if (!confirm('确认导入？' + formatLabel + '，当前配置将被覆盖（自动备份）')) {
                input.value = '';
                return;
            }
            document.getElementById('importForm').submit();
        } catch(err) {
            showToast('JSON 格式解析错误：' + err.message, 'error');
            input.value = '';
        }
    };
    reader.onerror = function() {
        showToast('文件读取失败，请重试', 'error');
        input.value = '';
    };
    reader.readAsText(file, 'utf-8');
}
// ── 卡片尺寸自定义输入联动 ──
function syncCustom(field) {
    var sel = document.getElementById(field + '_sel');
    var inp = document.getElementById(field + '_custom');
    if (!sel || !inp) return;
    var isCustom = sel.value === 'custom';
    inp.style.display = isCustom ? 'block' : 'none';
    if (isCustom) inp.focus();
}
// 初始化（页面加载时）
['card_size','card_height'].forEach(function(f){ syncCustom(f); });

// ── 反代参数模式选择卡片联动 ──
function selectPPM(val) {
    var radios = document.querySelectorAll('input[name="proxy_params_mode"]');
    radios.forEach(function(radio) {
        if (val) {
            radio.checked = radio.value === val;
        }
        var card = radio.closest('[data-ppm-card]');
        if (!card) return;
        var isSelected = !!radio.checked;
        card.style.borderColor = isSelected ? 'var(--ac)' : 'var(--bd)';
        card.style.background  = isSelected ? 'rgba(99,179,237,.08)' : 'var(--sf)';
    });
}

document.addEventListener('DOMContentLoaded', function() {
    var radios = document.querySelectorAll('input[name="proxy_params_mode"]');
    radios.forEach(function(radio) {
        radio.addEventListener('change', function() {
            selectPPM();
        });
        var card = radio.closest('[data-ppm-card]');
        if (!card) return;
        card.addEventListener('click', function() {
            radio.checked = true;
            selectPPM();
        });
    });
    selectPPM();
});

</script>

<!-- Nginx 反代管理 -->
<div class="card" id="nginx">
  <div class="card-title">🔀 Nginx 反代管理
    <span style="font-size:11px;color:var(--tm);font-weight:400;margin-left:8px">方案A：自动生成配置 + reload</span>
  </div>

  <div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:12px;background:var(--bg);border:1px solid var(--bd);border-radius:8px;padding:12px 14px">
    <div style="font-size:12px;color:var(--tx2)">Nginx 已拆分为独立后台模块，可在专用编辑器中查看/编辑主配置、HTTP 模块和全部反代配置。</div>
    <a href="nginx.php" class="btn btn-secondary">打开 Nginx 管理</a>
  </div>

  <!-- Reload 执行环境：首屏不 exec，进入本区域时异步检测 -->
  <div id="nginx-sudo-banner" style="min-height:0"></div>

  <!-- 当前配置预览 -->
  <?php
  $proxy_conf_path = nginx_proxy_conf_path();
  $conf_exists     = file_exists($proxy_conf_path);
  $conf_mtime      = $conf_exists ? date('Y-m-d H:i:s', filemtime($proxy_conf_path)) : null;
  // 统计当前 proxy 站点数量
  $proxy_count = 0;
  foreach (load_sites()['groups'] ?? [] as $g)
      foreach ($g['sites'] ?? [] as $s)
          if (($s['type'] ?? '') === 'proxy') $proxy_count++;
  ?>

  <div style="display:flex;gap:16px;flex-wrap:wrap;align-items:flex-start;margin-bottom:18px">
    <div style="background:var(--bg);border:1px solid var(--bd);border-radius:8px;padding:14px 18px;flex:1;min-width:200px">
      <div style="font-size:11px;color:var(--tm);margin-bottom:4px">配置文件</div>
      <div style="font-size:13px;font-family:monospace"><?= htmlspecialchars($proxy_conf_path) ?></div>
      <div style="margin-top:6px">
        <?php if ($conf_exists): ?>
        <span class="badge badge-green">已生成</span>
        <span style="font-size:11px;color:var(--tm);margin-left:6px">上次更新：<?= $conf_mtime ?></span>
        <?php else: ?>
        <span class="badge badge-gray">未生成</span>
        <?php endif; ?>
      </div>
    </div>
    <div style="background:var(--bg);border:1px solid var(--bd);border-radius:8px;padding:14px 18px;min-width:120px">
      <div style="font-size:11px;color:var(--tm);margin-bottom:4px">Proxy 站点数</div>
      <div style="font-size:28px;font-weight:700;color:var(--ac2)"><?= $proxy_count ?></div>
    </div>
  </div>

  <!-- 反代参数模式选择 -->
  <?php $ppm = $cfg['proxy_params_mode'] ?? 'simple'; ?>
  <div style="margin-bottom:16px;background:var(--bg);border:1px solid var(--bd);border-radius:10px;padding:14px 18px">
    <div style="font-size:12px;color:var(--tm);margin-bottom:10px;font-weight:600">📦 反代参数模板</div>
    <form method="POST" id="proxy-params-mode-form" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save_proxy_params_mode">
      <label data-ppm-card="simple" style="display:flex;align-items:flex-start;gap:10px;cursor:pointer;flex:1;min-width:220px;background:<?= $ppm==='simple'?'rgba(99,179,237,.08)':'var(--sf)' ?>;border:2px solid <?= $ppm==='simple'?'var(--ac)':'var(--bd)' ?>;border-radius:8px;padding:12px;transition:all .2s">
        <input type="radio" name="proxy_params_mode" value="simple" <?= $ppm==='simple'?'checked':'' ?> id="ppm_simple" style="margin-top:2px;accent-color:var(--ac)">
        <div>
          <div style="font-size:13px;font-weight:700;color:var(--tx)">⚡ 精简模式 <span style="font-size:11px;font-weight:400;color:var(--tm);">（14 条参数 · 超时 60s）</span></div>
          <div style="font-size:11px;color:var(--tm);margin-top:4px;line-height:1.6">HTTP/1.1、WebSocket 升级、Host / IP / Proto 透传、连接 10s + 读写 60s 超时、基础缓冲。<br>适合普通 Web 应用，<b>默认推荐</b>，小白首选。</div>
        </div>
      </label>
      <label data-ppm-card="full" style="display:flex;align-items:flex-start;gap:10px;cursor:pointer;flex:1;min-width:220px;background:<?= $ppm==='full'?'rgba(99,179,237,.08)':'var(--sf)' ?>;border:2px solid <?= $ppm==='full'?'var(--ac)':'var(--bd)' ?>;border-radius:8px;padding:12px;transition:all .2s">
        <input type="radio" name="proxy_params_mode" value="full" <?= $ppm==='full'?'checked':'' ?> id="ppm_full" style="margin-top:2px;accent-color:var(--ac)">
        <div>
          <div style="font-size:13px;font-weight:700;color:var(--tx)">🔥 完整模式 <span style="font-size:11px;font-weight:400;color:var(--tm);">（60+ 条参数 · 超时 86400s）</span></div>
          <div style="font-size:11px;color:var(--tm);margin-top:4px;line-height:1.6">WebSocket 全头透传、断点续传、Cookie / Auth / CORS 透传、流媒体无缓冲、无限超时（86400s）、全量响应头直通。<br>适合视频流、大文件、SSH 隧道、长连接等复杂场景。</div>
        </div>
      </label>
      <div style="display:flex;flex-direction:column;gap:8px;align-self:center">
        <button type="submit" class="btn btn-primary" style="white-space:nowrap">💾 保存模式</button>
        <?php if ($proxy_count > 0): ?>
        <span style="font-size:11px;color:var(--tm);text-align:center">保存后需 Reload<br>才能生效</span>
        <?php endif; ?>
      </div>
    </form>
    <div class="form-hint" style="margin-top:8px">
      切换模式后需点击下方「生成配置并 Reload Nginx」重新生成配置文件才会生效。<?php if ($proxy_count > 0): ?> <span style="color:#fbbf24">当前有 <?= $proxy_count ?> 个代理站点，切换后请及时 Reload。</span><?php endif; ?>
    </div>
  </div>

  <!-- 操作按钮 -->
  <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px">
    <form method="POST" id="nginx-reload-form" style="display:inline"><?= csrf_field() ?>
      <input type="hidden" name="action" value="nginx_reload">
      <button class="btn btn-primary" id="nginx-reload-btn">
        🔄 生成配置并 Reload Nginx
      </button>
    </form>
    <form method="POST" style="display:inline"><?= csrf_field() ?>
      <input type="hidden" name="action" value="nginx_apply">
      <button class="btn btn-secondary">📝 仅生成配置文件（不 reload）</button>
    </form>
    <form method="POST" style="display:inline"><?= csrf_field() ?>
      <input type="hidden" name="action" value="gen_nginx">
      <button class="btn btn-secondary">⬇ 下载配置文件</button>
    </form>
  </div>

  <!-- 配置文件预览 -->
  <?php if ($conf_exists): ?>
  <details style="margin-top:4px">
    <summary style="cursor:pointer;font-size:13px;color:var(--tm);user-select:none">
      查看当前配置文件内容 ▸
    </summary>
    <pre style="margin-top:10px;background:var(--bg);border:1px solid var(--bd);
border-radius:8px;padding:14px;font-size:11px;font-family:monospace;color:#a5f3a5;
overflow-x:auto;max-height:300px;overflow-y:auto"><?=
      htmlspecialchars(@file_get_contents($proxy_conf_path) ?: '（读取失败）')
    ?></pre>
  </details>
  <?php endif; ?>

  <div class="alert alert-info" style="margin-top:16px">
    ℹ️ 点击「生成配置并 Reload」将自动写入
    <code style="font-size:11px">/etc/nginx/conf.d/nav-proxy.conf</code>
    并执行 Nginx 语法检测与 Reload。
    语法检测失败时会中止 reload 并显示错误信息。
  </div>
  <div id="nginx-reload-note" class="form-hint" style="margin-top:10px">按钮始终可点击；环境检测未通过时，提交后会显示明确错误原因。</div>
</div>

<!-- 登录日志（惰性：进入视口或 #logs 锚点时再请求） -->
<div class="card" id="logs">
  <div class="card-title">📋 登录日志
    <span id="logs_total_label" style="font-size:12px;color:var(--tm);font-weight:400;margin-left:8px"></span></div>
  <div id="logs_lazy_state" style="color:var(--tm);font-size:13px">向下滚动到此处或从控制台链接进入时将自动加载…</div>
  <div id="logs_lazy_content" style="display:none"></div>
</div>

<!-- Webhook 通知 -->
<div class="card" id="webhook">
  <div class="card-title">🔔 Webhook 通知</div>
  <form method="POST">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_webhook">
    <div class="form-grid">
      <div class="form-group" style="grid-column:1/-1;display:flex;align-items:center;gap:14px">
        <label style="margin:0">启用 Webhook 通知</label>
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
          <input type="checkbox" name="webhook_enabled" value="1" <?= ($cfg['webhook_enabled']??'0')==='1'?'checked':'' ?>
                 style="width:16px;height:16px;accent-color:var(--ac)">
          <span style="font-size:13px">启用</span>
        </label>
      </div>
      <div class="form-group">
        <label>通知类型</label>
        <select name="webhook_type" id="wh_type" onchange="syncWebhookType()" style="width:100%;background:var(--bg);border:1px solid var(--bd);border-radius:8px;padding:10px 12px;color:var(--tx);font-size:14px;outline:none">
          <?php $wt=$cfg['webhook_type']??'custom'; foreach(['telegram'=>'Telegram Bot','feishu'=>'飞书 Webhook','dingtalk'=>'钉钉 Webhook','custom'=>'自定义 POST JSON'] as $v=>$l): ?>
          <option value="<?=$v?>" <?= $wt===$v?'selected':'' ?>><?=$l?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label>Webhook URL</label>
        <input type="url" name="webhook_url" value="<?= htmlspecialchars($cfg['webhook_url']??'') ?>" placeholder="https://..." style="width:100%">
      </div>
      <div class="form-group" id="wh_tg_chat" style="display:<?= ($cfg['webhook_type']??'custom')==='telegram'?'block':'none' ?>">
        <label>Telegram Chat ID</label>
        <input type="text" name="webhook_tg_chat" value="<?= htmlspecialchars($cfg['webhook_tg_chat']??'') ?>" placeholder="-1001234567890">
        <div class="form-hint" style="margin-top:5px">从 @userinfobot 获取，群组 ID 通常为负数</div>
      </div>
      <div class="form-group" style="grid-column:1/-1">
        <label style="margin-bottom:8px;display:block">订阅事件</label>
        <div style="display:flex;gap:16px;flex-wrap:wrap">
          <?php
          $wevents = array_filter(array_map('trim', explode(',', $cfg['webhook_events']??'FAIL,IP_LOCKED')));
          $event_labels = ['SUCCESS'=>'✅ 登录成功','FAIL'=>'❌ 登录失败','IP_LOCKED'=>'🔒 IP被锁定','LOGOUT'=>'🚪 退出登录','SETUP'=>'🎉 初始安装'];
          foreach ($event_labels as $ev => $el): ?>
          <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:13px">
            <input type="checkbox" name="webhook_events[]" value="<?=$ev?>" <?= in_array($ev,$wevents)?'checked':'' ?>
                   style="accent-color:var(--ac)">
            <?= $el ?>
          </label>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
    <div class="form-actions" style="display:flex;gap:10px">
      <button type="submit" class="btn btn-primary">保存 Webhook 设置</button>
      <button type="button" class="btn btn-secondary" onclick="testWebhook()">📨 发送测试消息</button>
    </div>
    <div class="form-hint" style="margin-top:10px">
      <b>Telegram</b>：先创建 Bot（@BotFather），URL 填 <code>https://api.telegram.org/bot{TOKEN}/sendMessage</code>，Chat ID 填目标会话 ID。<br>
      <b>飞书 / 钉钉</b>：在群机器人设置中创建 Webhook，复制 URL 填入即可。<br>
      <b>自定义</b>：向指定 URL POST 一个 JSON，包含 event/username/ip/time/text 字段。
    </div>
  </form>
  <!-- 隐藏的测试表单 -->
  <form id="webhookTestForm" method="POST" style="display:none">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="test_webhook">
  </form>
</div>

<!-- 站点健康检测 -->
<div class="card" id="health">
  <div class="card-title">💚 站点健康检测
    <span style="font-size:11px;color:var(--tm);font-weight:400;margin-left:8px">检测所有站点可用性</span>
  </div>
  <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:16px">
    <button class="btn btn-primary" onclick="runHealthCheck()">🔍 立即检测所有站点</button>
    <button class="btn btn-secondary" onclick="loadHealthStatus()">🔄 刷新缓存状态</button>
    <span id="health_last_check" style="font-size:12px;color:var(--tm)"></span>
  </div>
  <div id="health_results" style="display:none">
    <div class="table-wrap"><table id="health_table">
      <tr><th>站点名称</th><th>类型</th><th>目标地址</th><th>状态</th><th>响应码</th><th>耗时</th><th>检测时间</th></tr>
    </table></div>
  </div>
  <div id="health_empty" style="color:var(--tm);font-size:13px">点击「立即检测」获取各站点可用性状态。</div>
  <!-- 测试按钮隐藏表单 -->
  <form id="healthCheckForm" method="POST" action="health_check.php" style="display:none">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="check_all">
  </form>
</div>

<script>
// ── Webhook 类型联动 ──
function syncWebhookType() {
    var t = document.getElementById('wh_type').value;
    document.getElementById('wh_tg_chat').style.display = t === 'telegram' ? 'block' : 'none';
}
function testWebhook() {
    if (!confirm('发送一条测试 Webhook 消息？')) return;
    document.getElementById('webhookTestForm').submit();
}

// ── 健康检测 ──
function runHealthCheck() {
    var btn = event.target;
    btn.disabled = true;
    btn.textContent = '检测中...';
    document.getElementById('health_empty').textContent = '正在检测，请稍候...';
    document.getElementById('health_empty').style.display = 'block';
    document.getElementById('health_results').style.display = 'none';

    var form = document.getElementById('healthCheckForm');
    fetch('health_check.php', {
        method: 'POST',
        credentials: 'same-origin',
        body: new FormData(form),
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    }).then(function(r){ return r.json(); }).then(function(d){
        btn.disabled = false;
        btn.textContent = '🔍 立即检测所有站点';
        if (d.ok) renderHealthResults(d.data);
        else showToast(d.msg || '检测失败', 'error');
    }).catch(function(){
        btn.disabled = false;
        btn.textContent = '🔍 立即检测所有站点';
        showToast('请求失败，请重试', 'error');
    });
}

function loadHealthStatus() {
    fetch('health_check.php?ajax=status', {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    }).then(function(r){ return r.json(); }).then(function(d){
        if (d.ok && d.data && Object.keys(d.data).length) renderHealthResults(d.data);
        else document.getElementById('health_empty').textContent = '暂无缓存数据，请点击「立即检测」。';
    });
}

function loadHealthSitesMeta(cb) {
    if (window.__healthSitesMeta) { cb(window.__healthSitesMeta); return; }
    fetch('settings_ajax.php?action=health_sites_meta', {
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    }).then(function(r){ return r.json(); }).then(function(d){
        if (d.ok && d.sites) { window.__healthSitesMeta = d.sites; cb(d.sites); }
        else { cb([]); }
    }).catch(function(){ cb([]); });
}

function renderHealthResults(data) {
    loadHealthSitesMeta(function(sites) {
        var tbody = '';
        var checked_any = false;
        sites.forEach(function(s) {
            if (!s.url) return;
            var h = data[s.url];
            if (!h) return;
            checked_any = true;
            var dot = h.status === 'up'
                ? '<span style="color:#4ade80;font-size:16px" title="在线">●</span>'
                : '<span style="color:#f87171;font-size:16px" title="离线">●</span>';
            var ms   = h.ms   != null ? h.ms + ' ms'  : '-';
            var code = h.code ? h.code : '-';
            var t    = h.checked_at ? new Date(h.checked_at * 1000).toLocaleTimeString() : '-';
            var url_short = s.url.length > 40 ? s.url.substring(0,40)+'…' : s.url;
            tbody += '<tr>'
                + '<td>' + escHtml(s.name) + '</td>'
                + '<td><span class="badge badge-' + (s.type==='proxy'?'yellow':s.type==='internal'?'purple':'gray') + '">' + escHtml(s.type) + '</span></td>'
                + '<td style="font-size:11px;font-family:monospace" title="' + escHtml(s.url) + '">' + escHtml(url_short) + '</td>'
                + '<td>' + dot + ' ' + (h.status==='up'?'在线':'离线') + '</td>'
                + '<td style="font-family:monospace">' + code + '</td>'
                + '<td style="font-family:monospace">' + ms + '</td>'
                + '<td style="font-size:11px;color:var(--tm)">' + t + '</td>'
                + '</tr>';
        });

        if (!checked_any) {
            document.getElementById('health_empty').textContent = '没有可检测的站点（站点需配置有效的 URL）。';
            document.getElementById('health_empty').style.display = 'block';
            document.getElementById('health_results').style.display = 'none';
            return;
        }
        document.getElementById('health_table').tBodies[0]
            ? document.getElementById('health_table').tBodies[0].innerHTML = tbody
            : document.getElementById('health_table').innerHTML += '<tbody>' + tbody + '</tbody>';
        document.getElementById('health_results').style.display = 'block';
        document.getElementById('health_empty').style.display = 'none';
        document.getElementById('health_last_check').textContent = '上次刷新：' + new Date().toLocaleTimeString();
    });
}

function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

var HOST_AGENT_STATUS = null;

function updateHostAgentUi(data) {
    HOST_AGENT_STATUS = data || {};
    var banner = document.getElementById('host-agent-banner');
    var mode = document.getElementById('host-agent-mode');
    var status = document.getElementById('host-agent-status-text');
    var name = document.getElementById('host-agent-container-name');
    var url = document.getElementById('host-agent-service-url');
    var note = document.getElementById('host-agent-socket-note');
    var snippet = document.getElementById('host-agent-compose-snippet');
    var installBtn = document.getElementById('host-agent-install-btn');
    if (!banner || !mode || !status || !name || !url || !note || !snippet || !installBtn) return;

    mode.textContent = data.install_mode === 'simulate' ? 'simulate（开发/测试安全模式）' : 'host（宿主机真实安装模式）';
    status.textContent = data.healthy ? '已运行并健康' : (data.running ? '已运行，等待健康检查' : (data.installed ? '已安装未运行' : '未安装'));
    name.textContent = '容器名：' + (data.container_name || '-');
    url.textContent = data.service_url || '-';
    banner.className = data.healthy ? 'alert alert-success' : (data.docker_socket_mounted ? 'alert alert-info' : 'alert alert-warn');
    banner.textContent = data.message || '未获取到 host-agent 状态。';

    if (!data.docker_accessible) {
        note.textContent = data.docker_mount_hint || '';
        snippet.style.display = 'block';
        snippet.textContent = 'docker-compose.yml 临时挂载示例：\n'
            + 'services:\n'
            + '  simple-homepage:\n'
            + '    volumes:\n'
            + '      - ./data:/var/www/nav/data\n'
            + '      # 仅在后台一键安装 / 升级 host-agent 时临时挂载\n'
            + '      - ' + (data.docker_socket_path || '/var/run/docker.sock') + ':' + (data.docker_socket_path || '/var/run/docker.sock') + '\n';
        installBtn.disabled = true;
    } else {
        note.textContent = '已检测到可用 docker.sock。安装完成并确认功能正常后，请从当前应用容器移除该挂载；后续只有升级或重装 host-agent 时才需要再次挂回。';
        snippet.style.display = 'none';
        installBtn.disabled = false;
    }
}

function loadHostAgentStatus(force) {
    if (window.__hostAgentLoaded && !force) return;
    window.__hostAgentLoaded = true;
    fetch('settings_ajax.php?action=host_agent_status', {
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    }).then(function(r){ return r.json(); })
      .then(updateHostAgentUi)
      .catch(function(err){
          updateHostAgentUi({
              docker_socket_mounted: false,
              docker_socket_path: '/var/run/docker.sock',
              install_mode: 'host',
              installed: false,
              running: false,
              healthy: false,
              container_name: 'host-agent',
              service_url: '-',
              message: 'host-agent 状态检测失败：' + (err && err.message ? err.message : 'unknown error'),
              docker_mount_hint: '请先检查当前容器是否挂载了 docker.sock，并确保后台容器对该 socket 拥有读写权限。'
          });
      });
}

function confirmHostAgentInstall() {
    if (!HOST_AGENT_STATUS || !HOST_AGENT_STATUS.docker_accessible) {
        alert('当前容器尚未具备可用的 docker.sock 访问能力，无法一键安装 host-agent。');
        return false;
    }
    return confirm('确认一键安装 host-agent？\n\n1. 后台会通过 docker.sock 创建一个独立的 host-agent 容器\n2. 安装完成后请先确认 host-agent 功能正常\n3. 验证通过后请从当前应用容器移除 docker.sock 挂载\n4. 后续只有升级或重装 host-agent 时才需要再次挂回');
}

// ── 惰性：登录日志、Nginx sudo 检测（进入视口或锚点时再请求，首屏不读日志、不 exec）──
(function initSettingsLazy() {
    var logsLoaded = false;
    var nginxReloadForm = document.getElementById('nginx-reload-form');
    var nginxReloadBtn = document.getElementById('nginx-reload-btn');
    var nginxReloadNote = document.getElementById('nginx-reload-note');
    var nginxSubmitting = false;

    function setNginxReloadUi(state, note) {
        if (nginxReloadBtn) {
            if (state === 'submitting') {
                nginxReloadBtn.disabled = true;
                nginxReloadBtn.textContent = '处理中...';
            } else {
                nginxReloadBtn.disabled = false;
                nginxReloadBtn.textContent = '🔄 生成配置并 Reload Nginx';
            }
        }
        if (nginxReloadNote && note) {
            nginxReloadNote.textContent = note;
        }
    }

    if (nginxReloadForm) {
        nginxReloadForm.addEventListener('submit', function() {
            if (nginxSubmitting) return false;
            nginxSubmitting = true;
            setNginxReloadUi('submitting', '正在生成配置并触发 Nginx Reload，请稍候...');
        });
    }

    function loadLoginLogsOnce() {
        if (logsLoaded) return;
        logsLoaded = true;
        var st = document.getElementById('logs_lazy_state');
        if (st) st.textContent = '加载中…';
        fetch('login_logs.php', { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function(r){ return r.json(); })
            .then(function(d){
                if (!d.ok) { if (st) st.textContent = d.msg || '加载失败'; return; }
                var lbl = document.getElementById('logs_total_label');
                if (lbl) lbl.textContent = '共 ' + d.total + ' 条（最多保留 ' + d.max + ' 条）';
                var wrap = document.getElementById('logs_lazy_content');
                if (st) st.style.display = 'none';
                if (wrap) {
                    wrap.style.display = 'block';
                    if (!d.rows || !d.rows.length) {
                        wrap.innerHTML = '<p style="color:var(--tm);font-size:13px">暂无日志</p>';
                        return;
                    }
                    var bc = { SUCCESS:'badge-green', FAIL:'badge-red', IP_LOCKED:'badge-yellow', LOGOUT:'badge-blue', SETUP:'badge-purple' };
                    var rows = '';
                    d.rows.forEach(function(row) {
                        var m = row.match(/^\[(.+?)\]\s+(\S+)\s+user=(\S+)\s+ip=(\S+)(?:\s+note=(\S+))?/);
                        var t = m ? m[1] : '-', ty = m ? m[2] : '-', u = m ? m[3] : '-', ip = m ? m[4] : '-', note = m ? (m[5]||'') : '';
                        var bcc = bc[ty] || 'badge-gray';
                        rows += '<tr><td style="font-family:monospace;font-size:11px;white-space:nowrap">' + escHtml(t) + '</td>'
                            + '<td><span class="badge ' + bcc + '">' + escHtml(ty) + '</span></td>'
                            + '<td>' + escHtml(u) + '</td>'
                            + '<td style="font-family:monospace;font-size:11px">' + escHtml(ip) + '</td>'
                            + '<td style="font-size:11px;color:var(--tm)">' + escHtml(note) + '</td></tr>';
                    });
                    wrap.innerHTML = '<div class="table-wrap"><table><tr><th>时间</th><th>类型</th><th>用户</th><th>IP</th><th>备注</th></tr>' + rows + '</table></div>';
                }
            })
            .catch(function(){
                if (st) st.textContent = '加载失败，请刷新重试';
            });
    }

    var nginxLoaded = false;
    function loadNginxSudoOnce() {
        if (nginxLoaded) return;
        nginxLoaded = true;
        var el = document.getElementById('nginx-sudo-banner');
        if (!el) return;
        setNginxReloadUi('checking', '正在检测 Nginx reload 运行环境...');
        fetch('settings_ajax.php?action=nginx_sudo', { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function(r){ return r.json(); })
            .then(function(d){
                if (!d.ok) {
                    setNginxReloadUi('ready', '环境检测失败，但仍可尝试提交，失败时会显示具体原因。');
                    return;
                }
                if (d.reload_ok) {
                    el.innerHTML = '';
                    setNginxReloadUi('ready', d.message || '环境检测通过，可以直接生成配置并 Reload。');
                    return;
                }
                var html = '<div class="alert alert-warn">⚠️ ' + escHtml(d.message || '未检测到可用的 Nginx reload 执行权限。');
                if (d.sudo_hint) {
                    html += '<br>请在服务器上执行以下命令配置白名单：<pre style="margin-top:8px;background:var(--bg);padding:10px;border-radius:6px;font-size:12px;overflow-x:auto">' + escHtml(d.sudo_hint) + '</pre>';
                }
                html += '</div>';
                el.innerHTML = html;
                setNginxReloadUi('warn', '环境检测未通过，点击按钮后会返回明确错误；也可以先按上方提示补齐执行权限。');
            })
            .catch(function(){
                setNginxReloadUi('ready', '环境检测请求失败，但仍可尝试提交，失败时会显示具体原因。');
            });
    }

    var logs = document.getElementById('logs');
    var nginx = document.getElementById('nginx');
    if (window.IntersectionObserver) {
        if (logs) {
            var io1 = new IntersectionObserver(function(entries){
                entries.forEach(function(e){ if (e.isIntersecting) loadLoginLogsOnce(); });
            }, { rootMargin: '80px' });
            io1.observe(logs);
        }
        if (nginx) {
            var io2 = new IntersectionObserver(function(entries){
                entries.forEach(function(e){ if (e.isIntersecting) loadNginxSudoOnce(); });
            }, { rootMargin: '80px' });
            io2.observe(nginx);
        }
    } else {
        if (logs) loadLoginLogsOnce();
        if (nginx) loadNginxSudoOnce();
    }
    if (location.hash === '#logs') loadLoginLogsOnce();
    if (location.hash === '#nginx') loadNginxSudoOnce();
    loadHostAgentStatus(false);
})();
</script>

<?php require_once __DIR__ . '/shared/footer.php'; ?>
