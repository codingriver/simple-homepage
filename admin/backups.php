<?php
/**
 * 备份与恢复 admin/backups.php
 */

require_once __DIR__ . '/shared/functions.php';
require_once __DIR__ . '/shared/backup_webdav_lib.php';

// ── 所有 POST/GET 操作必须在 header.php 之前处理（避免 HTML 已输出导致 header() 失效）──
if (isset($_GET['download']) || isset($_GET['export']) || $_SERVER['REQUEST_METHOD'] === 'POST') {
    $current_user = auth_get_current_user();
    if (!$current_user || ($current_user['role'] ?? '') !== 'admin') {
        header('Location: /login.php'); exit;
    }

    // 导出配置（与备份同一 JSON 结构）
    if (isset($_GET['export'])) {
        $export = backup_collect_payload('export');
        $json = json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="riverops_export_' . date('Ymd_His') . '.json"');
        header('Content-Length: ' . strlen($json));
        echo $json; exit;
    }

    // 下载备份
    if (isset($_GET['download'])) {
        $file = basename($_GET['download']);
        if (!preg_match('/^backup_[\d_a-z]+\.json$/', $file)) {
            http_response_code(400); exit('Invalid filename');
        }
        $path = BACKUPS_DIR . '/' . $file;
        if (!file_exists($path)) { http_response_code(404); exit('Not found'); }
        $content = file_get_contents($path);
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $file . '"');
        header('Content-Length: ' . strlen($content));
        echo $content; exit;
    }

    // POST 操作
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $action = $_POST['action'] ?? '';

        if ($action === 'create') {
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
            @set_time_limit(0);
            backup_create('manual');
            audit_log('backup_create', ['trigger' => 'manual']);
            flash_set('success', '备份已创建');
            header('Location: backups.php'); exit;
        }

        // ── 导入配置（兼容备份格式和旧 sites-only 格式）──
        if ($action === 'import_config') {
            if (empty($_FILES['import_file']['tmp_name'])) {
                flash_set('error', '请选择要导入的文件');
                header('Location: backups.php'); exit;
            }
            if ($_FILES['import_file']['size'] > 4 * 1024 * 1024) {
                flash_set('error', '文件过大，配置文件不应超过 4MB');
                header('Location: backups.php'); exit;
            }
            $raw = file_get_contents($_FILES['import_file']['tmp_name']);
            if ($raw === false || strlen($raw) === 0) {
                flash_set('error', '文件读取失败或文件为空');
                header('Location: backups.php'); exit;
            }
            try {
                $obj = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                flash_set('error', 'JSON 格式解析错误：' . $e->getMessage());
                header('Location: backups.php'); exit;
            }
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
            @set_time_limit(0);
            backup_create('auto_import');
            $apply = [];
            $merged_cfg = !empty($obj['config']) && is_array($obj['config'])
                ? array_merge(auth_default_config(), $obj['config'])
                : null;
            if ($merged_cfg !== null) {
                $apply['config'] = $merged_cfg;
            }
            if (isset($obj['scheduled_tasks']) && is_array($obj['scheduled_tasks'])) {
                require_once __DIR__ . '/shared/cron_lib.php';
                $apply['scheduled_tasks'] = scheduled_tasks_filter_retired($obj['scheduled_tasks'])['data'];
            }
            if (isset($obj['dns_config']) && is_array($obj['dns_config'])) {
                $apply['dns_config'] = $obj['dns_config'];
            }
            if (isset($obj['ddns_tasks']) && is_array($obj['ddns_tasks'])) {
                $apply['ddns_tasks'] = $obj['ddns_tasks'];
            }
            if (isset($obj['domain_expiry']) && is_array($obj['domain_expiry'])) {
                $apply['domain_expiry'] = $obj['domain_expiry'];
            }
            if (empty($apply)) {
                flash_set('error', '文件结构无效：未识别到有效的备份数据（config / scheduled_tasks / dns_config / ddns_tasks / domain_expiry）');
                header('Location: backups.php'); exit;
            }
            backup_apply_restored_sections($apply);
            $parts = [];
            if (isset($apply['config'])) {
                $parts[] = '系统配置已同步';
            }
            if (isset($apply['scheduled_tasks']) && is_array($apply['scheduled_tasks'])) {
                $tc = count($apply['scheduled_tasks']['tasks'] ?? []);
                $parts[] = $tc . ' 条计划任务';
            }
            if (isset($apply['dns_config'])) {
                $parts[] = 'DNS 账户已同步';
            }
            if (isset($apply['ddns_tasks'])) {
                $dc = count($apply['ddns_tasks']['tasks'] ?? []);
                if ($dc > 0) {
                    $parts[] = $dc . ' 条 DDNS 任务';
                }
            }
            if (isset($apply['domain_expiry'])) {
                $parts[] = '域名有效期配置已同步';
            }
            audit_log('settings_import', ['format' => 'full', 'sections' => array_keys($apply)]);
            flash_set('success', '导入成功：' . implode('，', $parts) . '；旧配置已自动备份');
            header('Location: backups.php'); exit;
        }

        if ($action === 'restore') {
            $file = basename($_POST['filename'] ?? '');
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
            @set_time_limit(0);
            if (backup_restore($file)) {
                audit_log('backup_restore', ['file' => $file]);
                flash_set('success', "已恢复备份：{$file}，恢复前的状态已自动备份");
            } else {
                flash_set('error', '恢复失败，文件不存在或格式无效');
            }
            header('Location: backups.php'); exit;
        }

        if ($action === 'delete') {
            $file = basename($_POST['filename'] ?? '');
            if (backup_delete($file)) {
                audit_log('backup_delete', ['file' => $file]);
                flash_set('success', '备份已删除');
            } else {
                flash_set('error', '删除失败');
            }
            header('Location: backups.php'); exit;
        }
    }
}

$page_title = '备份与恢复';
require_once __DIR__ . '/shared/header.php';

$backups = backup_list();
$webdav_config = backup_webdav_public_config();

// 触发方式中文映射
function trigger_label(string $t): string {
    $map = [
        'manual'              => '手动',
        'auto_import'         => '自动-导入',
        'auto_settings'       => '自动-设置',
        'auto_before_restore' => '自动-恢复前',
        'webdav_manual'       => 'WebDAV-手动',
        'webdav_download'     => 'WebDAV-下载',
        'webdav_restore'      => 'WebDAV-恢复',
    ];
    return isset($map[$t]) ? $map[$t] : $t;
}

function trigger_badge(string $t): string {
    $map = [
        'manual'              => 'badge-blue',
        'auto_import'         => 'badge-yellow',
        'auto_settings'       => 'badge-purple',
        'auto_before_restore' => 'badge-red',
        'webdav_manual'       => 'badge-green',
        'webdav_download'     => 'badge-blue',
        'webdav_restore'      => 'badge-yellow',
    ];
    return isset($map[$t]) ? $map[$t] : 'badge-gray';
}
?>

<div class="toolbar">
  <form method="POST" style="display:inline">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="create">
    <button class="btn btn-primary">💾 立即备份</button>
  </form>
  <button type="button" class="btn btn-primary" id="webdavCreateUploadBtn" onclick="startWebdavJob('create_upload')"
          <?= empty($webdav_config['enabled']) ? 'disabled title="请先保存并启用 WebDAV 配置"' : '' ?>>☁️ 备份到 WebDAV</button>
  <a href="?export=1" class="btn btn-secondary">⬇ 导出配置</a>
  <form method="POST" enctype="multipart/form-data" id="importForm" style="display:inline">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="import_config">
    <input type="file" name="import_file" accept=".json" id="importFile" style="display:none"
           onchange="handleImportFile(this)">
    <button type="button" class="btn btn-secondary" onclick="document.getElementById('importFile').click()">⬆ 导入配置</button>
  </form>
  <span style="color:var(--tm);font-size:13px">共 <?= count($backups) ?> / <?= MAX_BACKUPS ?> 条备份</span>
</div>

<div class="card" id="webdav-settings">
  <div class="card-title">☁️ WebDAV 手动备份</div>
  <div class="alert alert-warn" style="margin-bottom:18px">
    ⚠️ WebDAV 被视为可信目标。远端备份是未加密 JSON，可能包含 DNS、DDNS、Webhook 和计划任务中的敏感配置；不会自动或定时备份。
  </div>
  <form id="webdavConfigForm" onsubmit="saveWebdavConfig(event)">
    <div class="form-grid">
      <div class="form-group" style="grid-column:1/-1;display:flex;flex-direction:row;align-items:center;gap:12px">
        <label style="margin:0">启用 WebDAV</label>
        <label style="display:flex;align-items:center;gap:7px;cursor:pointer">
          <input type="checkbox" id="webdavEnabled" <?= !empty($webdav_config['enabled']) ? 'checked' : '' ?>>
          <span style="font-size:13px">启用手动上传和恢复</span>
        </label>
      </div>
      <div class="form-group">
        <label>名称</label>
        <input type="text" id="webdavName" maxlength="80" value="<?= htmlspecialchars((string)$webdav_config['name']) ?>" placeholder="家庭 WebDAV">
      </div>
      <div class="form-group">
        <label>WebDAV URL</label>
        <input type="text" id="webdavBaseUrl" value="<?= htmlspecialchars((string)$webdav_config['base_url']) ?>" placeholder="http://192.168.1.10:5244/dav">
        <div class="form-hint">默认使用 HTTP；URL 中不要包含用户名和密码。</div>
      </div>
      <div class="form-group">
        <label>远端目录</label>
        <input type="text" id="webdavRemoteDir" value="<?= htmlspecialchars((string)$webdav_config['remote_dir']) ?>" placeholder="/RiverOps">
      </div>
      <div class="form-group">
        <label>实例标识</label>
        <input type="text" value="<?= htmlspecialchars((string)$webdav_config['instance_id']) ?>" readonly>
        <div class="form-hint">自动生成；远端清理只处理该实例自己的备份。</div>
      </div>
      <div class="form-group">
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
          <input type="checkbox" id="webdavTlsEnabled" <?= !empty($webdav_config['tls_enabled']) ? 'checked' : '' ?> onchange="syncWebdavFields()">
          启用 TLS（HTTPS）
        </label>
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;margin-top:10px">
          <input type="checkbox" id="webdavTlsVerify" <?= !empty($webdav_config['tls_verify']) ? 'checked' : '' ?>>
          校验证书
        </label>
      </div>
      <div class="form-group">
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
          <input type="checkbox" id="webdavSsrf" <?= !empty($webdav_config['ssrf_protection']) ? 'checked' : '' ?>>
          启用 SSRF 防护
        </label>
        <div class="form-hint">默认关闭；开启后仅允许解析到公网地址的目标。</div>
      </div>
      <div class="form-group" style="grid-column:1/-1">
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
          <input type="checkbox" id="webdavAuthEnabled" <?= !empty($webdav_config['auth_enabled']) ? 'checked' : '' ?> onchange="syncWebdavFields()">
          启用 WebDAV 认证
        </label>
      </div>
      <div class="form-group webdav-auth-field">
        <label>认证方式</label>
        <select id="webdavAuthMode" style="width:100%">
          <?php foreach (['basic' => 'Basic', 'digest' => 'Digest', 'auto' => '自动协商'] as $value => $label): ?>
          <option value="<?= $value ?>" <?= ($webdav_config['auth_mode'] ?? 'basic') === $value ? 'selected' : '' ?>><?= $label ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group webdav-auth-field">
        <label>用户名</label>
        <input type="text" id="webdavUsername" value="<?= htmlspecialchars((string)$webdav_config['username']) ?>" autocomplete="username">
      </div>
      <div class="form-group webdav-auth-field">
        <label>密码</label>
        <input type="password" id="webdavPassword" value="" autocomplete="new-password"
               placeholder="<?= !empty($webdav_config['password_set']) ? '已保存；留空表示不修改' : '未设置' ?>">
      </div>
      <div class="form-group">
        <label>远端保留数量</label>
        <input type="number" value="<?= BACKUP_WEBDAV_RETENTION ?>" readonly>
        <div class="form-hint">每次上传成功后自动删除最旧备份，固定保留最新 10 份。</div>
      </div>
    </div>
    <div class="form-actions" style="display:flex;gap:10px;flex-wrap:wrap">
      <button type="submit" class="btn btn-primary">保存 WebDAV 配置</button>
      <button type="button" class="btn btn-secondary" onclick="saveThenTestWebdav()">测试连接</button>
      <button type="button" class="btn btn-primary" onclick="startWebdavJob('create_upload')" <?= empty($webdav_config['enabled']) ? 'disabled' : '' ?>>备份到 WebDAV</button>
    </div>
  </form>
</div>

<div class="card" id="webdav-job-card" style="display:none">
  <div class="card-title">⏳ WebDAV 任务进度</div>
  <div style="display:flex;justify-content:space-between;gap:12px;align-items:center">
    <div>
      <div id="webdavJobPhase" style="font-weight:700">准备中</div>
      <div id="webdavJobMessage" style="font-size:12px;color:var(--tm);margin-top:5px">正在准备任务</div>
    </div>
    <div id="webdavJobPercent" style="font-family:monospace;color:var(--tm)">0%</div>
  </div>
  <div style="height:8px;background:rgba(255,255,255,.06);border-radius:999px;overflow:hidden;margin-top:12px">
    <div id="webdavJobBar" style="height:100%;width:0;background:linear-gradient(90deg,var(--ac),#64ffd9);transition:width .25s ease"></div>
  </div>
  <pre id="webdavJobLog" style="max-height:180px;overflow:auto;margin-top:12px;background:var(--bg);border:1px solid var(--bd);border-radius:8px;padding:10px;font-size:11px;white-space:pre-wrap"></pre>
</div>

<div class="card" id="webdav-remote-backups">
  <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:14px">
    <div class="card-title" style="margin:0">远端备份</div>
    <button type="button" class="btn btn-secondary" onclick="loadWebdavRemoteList()">刷新列表</button>
  </div>
  <div id="webdavRemoteMessage" style="color:var(--tm);font-size:13px">尚未加载远端列表</div>
  <div class="table-wrap" id="webdavRemoteTableWrap" style="display:none"></div>
</div>

<div class="card">
  <?php if (empty($backups)): ?>
    <p style="color:var(--tm);font-size:13px;padding:8px 0">暂无备份记录</p>
  <?php else: ?>
  <div class="table-wrap"><table>
    <tr><th>备份时间</th><th>触发方式</th><th>大小</th><th>操作</th></tr>
    <?php foreach ($backups as $bk): ?>
    <tr>
      <td style="font-family:monospace;font-size:12px;white-space:nowrap">
        <?= htmlspecialchars($bk['created_at']) ?></td>
      <td><span class="badge <?= trigger_badge($bk['trigger']) ?>">
        <?= htmlspecialchars(trigger_label($bk['trigger'])) ?></span></td>
      <td style="font-size:12px"><?= round($bk['size']/1024, 1) ?> KB</td>
      <td>
        <a href="?download=<?= urlencode($bk['filename']) ?>"
           class="btn btn-sm btn-secondary">⬇ 下载</a>
        <button type="button" class="btn btn-sm btn-secondary"
                onclick="startWebdavJob('upload_local', <?= htmlspecialchars(json_encode($bk['filename'], JSON_HEX_TAG | JSON_HEX_AMP), ENT_QUOTES) ?>)"
                <?= empty($webdav_config['enabled']) ? 'disabled title="请先启用 WebDAV"' : '' ?>>☁️ 上传</button>
        <form method="POST" style="display:inline" data-confirm-title="恢复备份" data-confirm-message="确认恢复此备份？当前配置将被覆盖（会自动备份当前状态）">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="restore">
          <input type="hidden" name="filename" value="<?= htmlspecialchars($bk['filename']) ?>">
          <button type="button" class="btn btn-sm btn-secondary" onclick="submitConfirmForm(this, {danger: false})">🔄 恢复</button>
        </form>
        <form method="POST" style="display:inline" data-confirm-title="删除备份" data-confirm-message="确认删除此备份？">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="filename" value="<?= htmlspecialchars($bk['filename']) ?>">
          <button type="button" class="btn btn-sm btn-danger" onclick="submitConfirmForm(this)">删除</button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
  </table></div>
  <?php endif; ?>
</div>

<div class="card">
  <div class="card-title">ℹ️ 备份方案说明</div>
  <ul style="color:var(--tm);font-size:13px;line-height:2;padding-left:18px">
    <li><strong>单文件 JSON</strong>：与「设置 → 导出配置」结构相同，一条记录对应一次快照。</li>
    <li><strong>包含内容</strong>：<code>config</code>（系统配置）、<code>scheduled_tasks</code>（计划任务定义，含每条任务的 <code>command</code> 脚本与 cron 表达式）、<code>dns_config</code>（域名解析服务商账户与凭据）、<code>ddns_tasks</code>（DDNS 动态解析任务）、<code>domain_expiry</code>（域名有效期配置）。</li>
    <li><strong>不含内容</strong>：用户账户（<code>users.json</code>）、登录日志、计划任务脚本与运行日志（<code>data/tasks/*.sh</code>、<code>data/tasks/*.log</code>）、DNS Zone 列表缓存，以及计划任务共享工作目录 <code>data/tasks/</code> 下的额外文件；如任务脚本依赖这些文件，请另行备份。</li>
    <li><strong>恢复与导入</strong>：写入计划任务后会重新生成系统 crontab；写入 DNS 配置后会清除本机 DNS Zone 缓存。</li>
    <li>最多保留 <?= MAX_BACKUPS ?> 条，超出时自动删除最旧的；恢复或导入前会先自动备份当前状态。</li>
    <li><strong>WebDAV</strong>：只允许管理员手动上传、下载、恢复和删除；远端保存未加密 JSON，每次上传成功后仅保留当前实例最新 <?= BACKUP_WEBDAV_RETENTION ?> 份。</li>
    <li>触发方式：手动 / 自动-导入 / 自动-设置 / 自动-恢复前（见列表中「触发方式」列）。</li>
  </ul>
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
            var sections = [];
            if (obj && obj.config) sections.push('系统配置');
            var tasks = obj && obj.scheduled_tasks && obj.scheduled_tasks.tasks;
            if (Array.isArray(tasks) && tasks.length) {
                sections.push('计划任务 ' + tasks.length + ' 条（含脚本）');
            }
            if (obj && obj.dns_config && typeof obj.dns_config === 'object') {
                sections.push('DNS 账户');
            }
            var ddnsTasks = obj && obj.ddns_tasks && obj.ddns_tasks.tasks;
            if (Array.isArray(ddnsTasks) && ddnsTasks.length) {
                sections.push('DDNS 任务 ' + ddnsTasks.length + ' 条');
            }
            if (obj && obj.domain_expiry && typeof obj.domain_expiry === 'object') {
                sections.push('域名有效期配置');
            }
            if (sections.length === 0) {
                showToast('无法识别的配置格式，请使用导出配置或备份文件', 'error');
                input.value = '';
                return;
            }
            var formatLabel = '包含：' + sections.join('、');
            RiverOpsConfirm.open({
                title: '导入配置',
                message: '确认导入？' + formatLabel + '，当前配置将被覆盖（自动备份）',
                confirmText: '确认导入',
                cancelText: '取消',
                danger: true,
                onConfirm: function() {
                    document.getElementById('importForm').submit();
                },
                onCancel: function() {
                    input.value = '';
                }
            });
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
</script>

<script>
var webdavPollTimer = null;

function webdavEscape(value) {
    return String(value == null ? '' : value).replace(/[&<>"']/g, function(ch) {
        return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[ch];
    });
}

async function webdavApi(action, options) {
    options = options || {};
    var method = options.method || 'GET';
    var fetchOptions = {
        method: method,
        headers: {'X-Requested-With': 'XMLHttpRequest'}
    };
    var url = 'backups_ajax.php?action=' + encodeURIComponent(action);
    if (method === 'POST') {
        fetchOptions.headers['Content-Type'] = 'application/json';
        fetchOptions.body = JSON.stringify(Object.assign({_csrf: window._csrf || ''}, options.data || {}, {action: action}));
    } else if (options.data) {
        Object.keys(options.data).forEach(function(key) {
            url += '&' + encodeURIComponent(key) + '=' + encodeURIComponent(options.data[key]);
        });
    }
    var response = await fetch(url, fetchOptions);
    var payload;
    try {
        payload = await response.json();
    } catch (error) {
        throw new Error('服务端返回了无法解析的响应');
    }
    if (!response.ok || !payload.ok) {
        throw new Error(payload.msg || ('请求失败（HTTP ' + response.status + '）'));
    }
    return payload;
}

function collectWebdavConfig() {
    return {
        enabled: document.getElementById('webdavEnabled').checked,
        name: document.getElementById('webdavName').value,
        base_url: document.getElementById('webdavBaseUrl').value,
        remote_dir: document.getElementById('webdavRemoteDir').value,
        ssrf_protection: document.getElementById('webdavSsrf').checked,
        tls_enabled: document.getElementById('webdavTlsEnabled').checked,
        tls_verify: document.getElementById('webdavTlsVerify').checked,
        auth_enabled: document.getElementById('webdavAuthEnabled').checked,
        auth_mode: document.getElementById('webdavAuthMode').value,
        username: document.getElementById('webdavUsername').value,
        password: document.getElementById('webdavPassword').value
    };
}

function applyWebdavConfig(config) {
    document.getElementById('webdavEnabled').checked = !!config.enabled;
    document.getElementById('webdavName').value = config.name || 'WebDAV';
    document.getElementById('webdavBaseUrl').value = config.base_url || '';
    document.getElementById('webdavRemoteDir').value = config.remote_dir || '/RiverOps';
    document.getElementById('webdavSsrf').checked = !!config.ssrf_protection;
    document.getElementById('webdavTlsEnabled').checked = !!config.tls_enabled;
    document.getElementById('webdavTlsVerify').checked = !!config.tls_verify;
    document.getElementById('webdavAuthEnabled').checked = !!config.auth_enabled;
    document.getElementById('webdavAuthMode').value = config.auth_mode || 'basic';
    document.getElementById('webdavUsername').value = config.username || '';
    document.getElementById('webdavPassword').value = '';
    document.getElementById('webdavPassword').placeholder = config.password_set ? '已保存；留空表示不修改' : '未设置';
    document.querySelectorAll('[onclick*="startWebdavJob"]').forEach(function(button) {
        button.disabled = !config.enabled;
    });
    syncWebdavFields();
}

async function persistWebdavConfig() {
    var payload = await webdavApi('save_config', {method: 'POST', data: {config: collectWebdavConfig()}});
    applyWebdavConfig(payload.data.config);
    showToast(payload.msg || 'WebDAV 配置已保存', 'success');
    return payload.data.config;
}

async function saveWebdavConfig(event) {
    if (event) event.preventDefault();
    try {
        await persistWebdavConfig();
    } catch (error) {
        showToast(error.message, 'error');
    }
}

async function saveThenTestWebdav() {
    try {
        var config = await persistWebdavConfig();
        if (!config.enabled) {
            throw new Error('请先启用 WebDAV');
        }
        await startWebdavJob('test_connection');
    } catch (error) {
        showToast(error.message, 'error');
    }
}

function syncWebdavFields() {
    var tls = document.getElementById('webdavTlsEnabled').checked;
    document.getElementById('webdavTlsVerify').disabled = !tls;
    var auth = document.getElementById('webdavAuthEnabled').checked;
    document.querySelectorAll('.webdav-auth-field input, .webdav-auth-field select').forEach(function(field) {
        field.disabled = !auth;
    });
    document.querySelectorAll('.webdav-auth-field').forEach(function(group) {
        group.style.opacity = auth ? '1' : '.55';
    });
}

async function startWebdavJob(action, filename) {
    try {
        var data = {};
        if (filename) data.filename = filename;
        var payload = await webdavApi(action, {method: 'POST', data: data});
        showToast(payload.msg || 'WebDAV 任务已启动', 'success');
        pollWebdavJob(payload.data.job_id);
        return payload;
    } catch (error) {
        showToast(error.message, 'error');
        return null;
    }
}

function renderWebdavJob(job) {
    var card = document.getElementById('webdav-job-card');
    card.style.display = '';
    var percent = Math.max(0, Math.min(100, parseInt(job.percent || 0, 10)));
    document.getElementById('webdavJobPhase').textContent = job.phase || '处理中';
    document.getElementById('webdavJobMessage').textContent = job.message || '正在处理';
    document.getElementById('webdavJobPercent').textContent = percent + '%';
    document.getElementById('webdavJobBar').style.width = percent + '%';
    document.getElementById('webdavJobBar').style.background = job.status === 'failed'
        ? 'linear-gradient(90deg,var(--red),#ff8a96)'
        : 'linear-gradient(90deg,var(--ac),#64ffd9)';
    document.getElementById('webdavJobLog').textContent = job.log || '';
}

function pollWebdavJob(jobId) {
    if (webdavPollTimer) clearTimeout(webdavPollTimer);
    webdavApi('job_status', {data: {job_id: jobId}}).then(function(payload) {
        var job = payload.data.job;
        renderWebdavJob(job);
        if (job.status === 'queued' || job.status === 'running') {
            webdavPollTimer = setTimeout(function() { pollWebdavJob(jobId); }, 1000);
            return;
        }
        showToast(job.message || (job.status === 'success' ? 'WebDAV 任务已完成' : 'WebDAV 任务失败'), job.status === 'success' ? 'success' : 'error');
        loadWebdavRemoteList();
        if (job.status === 'success' && ['create_upload', 'download_remote', 'restore_remote'].indexOf(job.action) >= 0) {
            setTimeout(function() { window.location.reload(); }, 900);
        }
    }).catch(function(error) {
        showToast(error.message, 'error');
    });
}

async function restoreCurrentWebdavJob() {
    try {
        var payload = await webdavApi('current_job');
        if (payload.data.job) pollWebdavJob(payload.data.job.id);
    } catch (error) {
        // 页面仍可使用本地备份，不因任务恢复失败阻断。
    }
}

function formatWebdavSize(bytes) {
    bytes = parseInt(bytes || 0, 10);
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
    return (bytes / 1024 / 1024).toFixed(1) + ' MB';
}

function webdavRemoteAction(action, filename) {
    var labels = {
        download_remote: ['下载远端备份', '确认将此远端备份保存到本地备份列表？', false],
        restore_remote: ['恢复远端备份', '确认恢复此远端备份？当前配置将被覆盖，系统会先创建本地保护快照。', true],
        delete_remote: ['删除远端备份', '确认永久删除此远端备份？此操作不可撤销。', true]
    };
    var info = labels[action];
    RiverOpsConfirm.open({
        title: info[0],
        message: info[1] + '\n\n' + filename,
        confirmText: '确认',
        danger: info[2],
        onConfirm: function() { startWebdavJob(action, filename); }
    });
}

async function loadWebdavRemoteList() {
    var message = document.getElementById('webdavRemoteMessage');
    var wrap = document.getElementById('webdavRemoteTableWrap');
    message.style.display = '';
    message.textContent = '正在读取远端备份列表…';
    wrap.style.display = 'none';
    wrap.innerHTML = '';
    try {
        var payload = await webdavApi('remote_list');
        var items = payload.data.items || [];
        if (!items.length) {
            message.textContent = '远端暂无当前实例的备份。';
            return;
        }
        wrap.innerHTML = '<table><thead><tr><th>备份时间</th><th>触发方式</th><th>大小</th><th>操作</th></tr></thead><tbody id="webdavRemoteRows"></tbody></table>';
        var rows = document.getElementById('webdavRemoteRows');
        items.forEach(function(item) {
            var tr = document.createElement('tr');
            tr.innerHTML = '<td style="font-family:monospace;font-size:12px;white-space:nowrap">' + webdavEscape(item.created_at) + '</td>'
                + '<td><span class="badge badge-green">' + webdavEscape(item.trigger) + '</span></td>'
                + '<td style="font-size:12px">' + webdavEscape(formatWebdavSize(item.size)) + '</td>'
                + '<td style="white-space:nowrap">'
                + '<button type="button" class="btn btn-sm btn-secondary" data-action="download_remote">⬇ 下载到本地</button> '
                + '<button type="button" class="btn btn-sm btn-secondary" data-action="restore_remote">🔄 恢复</button> '
                + '<button type="button" class="btn btn-sm btn-danger" data-action="delete_remote">删除</button>'
                + '</td>';
            tr.querySelectorAll('[data-action]').forEach(function(button) {
                button.addEventListener('click', function() { webdavRemoteAction(button.dataset.action, item.filename); });
            });
            rows.appendChild(tr);
        });
        message.style.display = 'none';
        wrap.style.display = '';
    } catch (error) {
        message.textContent = error.message;
    }
}

document.addEventListener('DOMContentLoaded', function() {
    syncWebdavFields();
    restoreCurrentWebdavJob();
});
</script>

<?php require_once __DIR__ . '/shared/footer.php'; ?>
