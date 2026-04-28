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
            if (isset($_POST['proxy_params_mode'])) {
                $cfg['proxy_params_mode']  = ($_POST['proxy_params_mode'] ?? 'simple') === 'full' ? 'full' : 'simple';
            }
            $cfg['ssh_terminal_persist'] = ($_POST['ssh_terminal_persist'] ?? '1') === '1' ? '1' : '0';
            $cfg['ssh_terminal_idle_minutes'] = max(5, min(10080, (int)($_POST['ssh_terminal_idle_minutes'] ?? 120)));
            $taskTimeoutRaw = trim($_POST['task_execution_timeout'] ?? '');
            $cfg['task_execution_timeout'] = ($taskTimeoutRaw === '') ? 7200 : max(0, (int)$taskTimeoutRaw);
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
            $cfg['theme'] = in_array($_POST['theme'] ?? 'dark', ['dark','light','auto']) ? ($_POST['theme'] ?? 'dark') : 'dark';
            $cfg['custom_css'] = trim((string)($_POST['custom_css'] ?? ''));
            // ── 文件系统访问白名单 ──
            $fsRootsRaw = trim((string)($_POST['fs_allowed_roots'] ?? ''));
            if ($fsRootsRaw === '') {
                $cfg['fs_allowed_roots'] = [];
            } else {
                $fsRoots = [];
                foreach (explode("\n", $fsRootsRaw) as $line) {
                    $line = trim($line);
                    if ($line === '' || $line[0] !== '/') {
                        continue;
                    }
                    $fsRoots[] = $line;
                }
                $cfg['fs_allowed_roots'] = $fsRoots;
            }
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
            audit_log('save_settings', ['site_name' => $cfg['site_name']]);
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
      <div class="form-group"><label>计划任务执行超时（秒）</label>
        <input type="number" name="task_execution_timeout" value="<?= (int)($cfg['task_execution_timeout']??7200) ?>" min="0">
        <div class="form-hint" style="margin-top:6px"><code>0</code> 表示不限制时长。超过此时长的任务将被强制终止（先 SIGTERM，10 秒后未退出则 SIGKILL），防止死循环占用系统资源。默认 <code>7200</code> 秒（2 小时）。</div></div>
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
      <div class="form-group">
        <label>主题模式</label>
        <select name="theme" style="width:100%;background:var(--bg);border:1px solid var(--bd);border-radius:8px;padding:10px 12px;color:var(--tx);font-size:14px;outline:none">
          <option value="dark" <?= ($cfg['theme']??'dark')==='dark'?'selected':'' ?>>🌙 深色模式</option>
          <option value="light" <?= ($cfg['theme']??'dark')==='light'?'selected':'' ?>>☀️ 浅色模式</option>
          <option value="auto" <?= ($cfg['theme']??'dark')==='auto'?'selected':'' ?>>🖥️ 跟随系统</option>
        </select>
      </div>
      <div class="form-group" style="grid-column:1/-1">
        <label>自定义 CSS（注入首页 &lt;head&gt;）</label>
        <textarea name="custom_css" rows="4" style="width:100%;background:var(--bg);border:1px solid var(--bd);border-radius:8px;padding:10px 12px;color:var(--tx);font-size:13px;outline:none;font-family:monospace" placeholder="/* 例如：body { font-family: 'LXGW WenKai', sans-serif; } */"><?= htmlspecialchars($cfg['custom_css']??'') ?></textarea>
        <div class="form-hint" style="margin-top:6px">支持任意 CSS，会以内联 &lt;style&gt; 方式注入到导航首页。错误语法可能导致页面样式异常，请谨慎使用。</div>
      </div>
      <div class="form-group" style="grid-column:1/-1">
        <label>文件系统访问白名单根目录</label>
        <textarea name="fs_allowed_roots" rows="4" style="width:100%;background:var(--bg);border:1px solid var(--bd);border-radius:8px;padding:10px 12px;color:var(--tx);font-size:13px;outline:none;font-family:monospace" placeholder="留空表示不限制（默认）&#10;每行一个绝对路径，例如：&#10;/var/www/nav/data&#10;/etc/nginx&#10;/var/log"><?= htmlspecialchars(implode("\n", (array)($cfg['fs_allowed_roots'] ?? []))) ?></textarea>
        <div class="form-hint" style="margin-top:6px">
          <b>留空</b>：不限制文件管理器的访问范围（默认）。<br>
          <b>填写路径</b>：仅允许访问以这些路径开头的目录和文件。<br>
          <span style="color:#ff6b6b">⚠️ 限制过严可能导致文件管理器无法正常访问常用目录。</span>
        </div>
      </div>
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

<script>
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

</script>

<?php require_once __DIR__ . '/shared/footer.php'; ?>
