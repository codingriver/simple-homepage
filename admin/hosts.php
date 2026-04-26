<?php
declare(strict_types=1);

$page_permission = 'ssh.view';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/shared/functions.php';
    require_once __DIR__ . '/shared/host_agent_lib.php';
    require_once __DIR__ . '/shared/ssh_manager_lib.php';

    $current_user = auth_require_permission('ssh.view');
    csrf_check();
    $action = trim((string)($_POST['action'] ?? ''));
    $canConfigManagePost = auth_user_has_permission('ssh.manage', $current_user) || auth_user_has_permission('ssh.config.manage', $current_user);
    $canServiceManagePost = auth_user_has_permission('ssh.manage', $current_user) || auth_user_has_permission('ssh.service.manage', $current_user);

    if ($action === 'save_ssh_config' && $canConfigManagePost) {
        $result = host_agent_ssh_config_save((string)($_POST['ssh_config'] ?? ''));
        $msg = trim((string)($result['msg'] ?? ''));
        flash_set($result['ok'] ? 'success' : 'error', $msg !== '' ? $msg : ($result['ok'] ? 'SSH 配置已保存' : 'SSH 配置保存失败'));
        header('Location: hosts.php');
        exit;
    }

    if ($action === 'save_ssh_structured' && $canConfigManagePost) {
        $result = host_agent_ssh_structured_save([
            'port' => trim((string)($_POST['ssh_port'] ?? '22')),
            'listen_address' => trim((string)($_POST['listen_address'] ?? '')),
            'password_auth' => ($_POST['password_auth'] ?? '1') === '1',
            'pubkey_auth' => ($_POST['pubkey_auth'] ?? '1') === '1',
            'permit_root_login' => trim((string)($_POST['permit_root_login'] ?? 'prohibit-password')),
            'allow_users' => trim((string)($_POST['allow_users'] ?? '')),
            'allow_groups' => trim((string)($_POST['allow_groups'] ?? '')),
            'x11_forwarding' => ($_POST['x11_forwarding'] ?? '0') === '1',
            'max_auth_tries' => trim((string)($_POST['max_auth_tries'] ?? '6')),
            'client_alive_interval' => trim((string)($_POST['client_alive_interval'] ?? '0')),
            'client_alive_count_max' => trim((string)($_POST['client_alive_count_max'] ?? '3')),
        ]);
        flash_set($result['ok'] ? 'success' : 'error', trim((string)($result['msg'] ?? '')) !== '' ? (string)$result['msg'] : ($result['ok'] ? '结构化 SSH 配置已保存' : '结构化 SSH 配置保存失败'));
        header('Location: hosts.php');
        exit;
    }

    if ($action === 'validate_ssh_config' && $canConfigManagePost) {
        $result = host_agent_ssh_validate_config((string)($_POST['ssh_config'] ?? ''));
        flash_set($result['ok'] ? 'success' : 'error', trim((string)($result['msg'] ?? '')) !== '' ? (string)$result['msg'] : ($result['ok'] ? 'SSH 配置校验通过' : 'SSH 配置校验失败'));
        header('Location: hosts.php');
        exit;
    }

    if ($action === 'restore_ssh_backup' && $canConfigManagePost) {
        $result = host_agent_ssh_restore_last_backup();
        flash_set($result['ok'] ? 'success' : 'error', (string)($result['msg'] ?? '恢复失败'));
        header('Location: hosts.php');
        exit;
    }

    if ($action === 'ssh_service_action' && $canServiceManagePost) {
        $result = host_agent_ssh_service_action((string)($_POST['service_action'] ?? ''));
        flash_set($result['ok'] ? 'success' : 'error', trim((string)($result['msg'] ?? '')) !== '' ? (string)$result['msg'] : ($result['ok'] ? 'SSH 服务操作已执行' : 'SSH 服务操作失败'));
        header('Location: hosts.php');
        exit;
    }

    if ($action === 'ssh_toggle_enable' && $canServiceManagePost) {
        $result = host_agent_ssh_toggle_enable((string)($_POST['enabled'] ?? '1') === '1');
        flash_set($result['ok'] ? 'success' : 'error', (string)($result['msg'] ?? 'SSH 自启操作失败'));
        header('Location: hosts.php');
        exit;
    }

    if ($action === 'ssh_install_service' && $canServiceManagePost) {
        $result = host_agent_ssh_install_service();
        flash_set($result['ok'] ? 'success' : 'error', (string)($result['msg'] ?? 'SSH 安装失败'));
        header('Location: hosts.php');
        exit;
    }

    if ($action === 'save_remote_host' && auth_user_has_permission('ssh.manage', $current_user)) {
        $result = ssh_manager_upsert_host([
            'name' => $_POST['host_name'] ?? '',
            'hostname' => $_POST['hostname'] ?? '',
            'port' => $_POST['port'] ?? 22,
            'username' => $_POST['username'] ?? 'root',
            'auth_type' => $_POST['auth_type'] ?? 'key',
            'key_id' => $_POST['key_id'] ?? '',
            'password' => $_POST['password'] ?? '',
            'group_name' => $_POST['group_name'] ?? '',
            'tags' => $_POST['tags'] ?? '',
            'favorite' => ($_POST['favorite'] ?? '') === '1',
            'notes' => $_POST['notes'] ?? '',
        ], trim((string)($_POST['host_id'] ?? '')) ?: null);
        flash_set($result['ok'] ? 'success' : 'error', (string)($result['msg'] ?? '远程主机保存失败'));
        header('Location: hosts.php');
        exit;
    }

    if ($action === 'delete_remote_host' && auth_user_has_permission('ssh.manage', $current_user)) {
        $ok = ssh_manager_delete_host(trim((string)($_POST['host_id'] ?? '')));
        flash_set($ok ? 'success' : 'error', $ok ? '远程主机已删除' : '远程主机不存在');
        header('Location: hosts.php');
        exit;
    }

    if ($action === 'save_ssh_key' && auth_user_has_permission('ssh.keys', $current_user)) {
        $result = ssh_manager_upsert_key([
            'name' => $_POST['key_name'] ?? '',
            'username' => $_POST['key_username'] ?? '',
            'private_key' => $_POST['private_key'] ?? '',
            'passphrase' => $_POST['passphrase'] ?? '',
        ], trim((string)($_POST['key_id'] ?? '')) ?: null);
        flash_set($result['ok'] ? 'success' : 'error', (string)($result['msg'] ?? 'SSH 密钥保存失败'));
        header('Location: hosts.php');
        exit;
    }

    if ($action === 'delete_ssh_key' && auth_user_has_permission('ssh.keys', $current_user)) {
        $ok = ssh_manager_delete_key(trim((string)($_POST['key_id'] ?? '')));
        flash_set($ok ? 'success' : 'error', $ok ? 'SSH 密钥已删除' : 'SSH 密钥不存在');
        header('Location: hosts.php');
        exit;
    }
}

$page_title = 'SSH 配置';
require_once __DIR__ . '/shared/header.php';
require_once __DIR__ . '/shared/host_agent_lib.php';
require_once __DIR__ . '/shared/ssh_manager_lib.php';

$canManage = auth_user_has_permission('ssh.manage', $current_admin);
$canConfigManage = $canManage || auth_user_has_permission('ssh.config.manage', $current_admin);
$canServiceManage = $canManage || auth_user_has_permission('ssh.service.manage', $current_admin);
$canKeys = auth_user_has_permission('ssh.keys', $current_admin);
$canFiles = auth_user_has_permission('ssh.files', $current_admin);
$canFileWrite = $canFiles && ($canManage || auth_user_has_permission('ssh.files.write', $current_admin));
$canAudit = auth_user_has_permission('ssh.audit', $current_admin);
$canAuditExport = $canAudit && ($canManage || auth_user_has_permission('ssh.audit.export', $current_admin));

$agent = host_agent_status_summary();
$sshStatus = null;
$sshConfig = null;
$sshMeta = [];
$sshConfigData = [];
if (!empty($agent['healthy'])) {
    $sshStatus = host_agent_ssh_status();
    $sshConfig = host_agent_ssh_config_read();
    $sshMeta = is_array($sshStatus['data'] ?? null) ? $sshStatus['data'] : [];
    $sshConfigData = is_array($sshConfig['data'] ?? null) ? $sshConfig['data'] : [];
}

$remoteHosts = ssh_manager_list_hosts();
$hostGroups = ssh_manager_host_groups();
$sshKeys = ssh_manager_list_keys();
$auditLogs = $canAudit ? ssh_manager_audit_tail(100) : [];
$remoteHostMap = [];
foreach ($remoteHosts as $remoteHost) {
    $remoteHostMap[(string)($remoteHost['id'] ?? '')] = (string)($remoteHost['name'] ?? '');
}

$editHostId = trim((string)($_GET['edit_host'] ?? ''));
$editHost = $editHostId !== '' ? ssh_manager_find_host($editHostId) : null;
$csrfValue = csrf_token();

function host_manager_badge(?bool $value): array {
    if ($value === true) {
        return ['class' => 'badge-green', 'text' => '是'];
    }
    if ($value === false) {
        return ['class' => 'badge-red', 'text' => '否'];
    }
    return ['class' => 'badge-gray', 'text' => '未知'];
}

$runningBadge = host_manager_badge(isset($sshMeta['running']) ? (bool)$sshMeta['running'] : null);
$enabledBadge = host_manager_badge(array_key_exists('enabled', $sshMeta) ? ($sshMeta['enabled'] === null ? null : (bool)$sshMeta['enabled']) : null);
$structured = is_array($sshConfigData['structured'] ?? null) ? $sshConfigData['structured'] : [];
?>

<style>
.ssh-tab-btn.active { background: var(--ac); color: #fff; border-color: var(--ac); }
.ssh-tab-panel { display: none; }
.ssh-tab-panel.active { display: block; }
</style>

<?php if (empty($agent['healthy'])): ?>
<div class="alert alert-warn" style="margin-bottom:16px">当前还不能使用 SSH 模块能力。请先前往 <a href="settings.php#host-agent">系统设置 / Host-Agent</a> 完成安装和健康检查。</div>
<?php endif; ?>

<div id="host-ssh-status" style="display:none;margin-bottom:16px;padding:12px 14px;border:1px solid var(--bd);border-radius:10px;background:var(--bg)"></div>

<!-- 页签栏 -->
<div class="card" style="margin-bottom:16px;padding:12px 16px">
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <button type="button" class="btn btn-secondary ssh-tab-btn active" data-tab="config" onclick="sshSwitchTab('config')">SSH 配置</button>
    <button type="button" class="btn btn-secondary ssh-tab-btn" data-tab="hosts" onclick="sshSwitchTab('hosts')">主机列表</button>
    <button type="button" class="btn btn-secondary ssh-tab-btn" data-tab="sessions" onclick="sshSwitchTab('sessions')">会话管理</button>
    <button type="button" class="btn btn-secondary ssh-tab-btn" data-tab="logs" onclick="sshSwitchTab('logs')">登录日志</button>
  </div>
</div>

<!-- Tab 1: SSH 配置 -->
<section id="ssh-tab-config" class="ssh-tab-panel active">
<?php if (empty($agent['healthy'])): ?>
  <div class="alert alert-warn">当前还不能使用 SSH 模块能力。请先前往 <a href="settings.php#host-agent">系统设置 / Host-Agent</a> 完成安装和健康检查。</div>
<?php else: ?>

<div class="card" style="margin-bottom:16px">
  <div class="card-title">本机 SSH 服务状态</div>
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px">
    <div style="border:1px solid var(--bd);border-radius:10px;padding:14px;background:var(--bg)">
      <div style="font-size:11px;color:var(--tm);margin-bottom:6px">已安装</div>
      <span class="badge <?= !empty($sshMeta['installed']) ? 'badge-green' : 'badge-red' ?>"><?= !empty($sshMeta['installed']) ? '是' : '否' ?></span>
    </div>
    <div style="border:1px solid var(--bd);border-radius:10px;padding:14px;background:var(--bg)">
      <div style="font-size:11px;color:var(--tm);margin-bottom:6px">运行中</div>
      <span class="badge <?= $runningBadge['class'] ?>"><?= $runningBadge['text'] ?></span>
    </div>
    <div style="border:1px solid var(--bd);border-radius:10px;padding:14px;background:var(--bg)">
      <div style="font-size:11px;color:var(--tm);margin-bottom:6px">开机启用</div>
      <span class="badge <?= $enabledBadge['class'] ?>"><?= $enabledBadge['text'] ?></span>
    </div>
    <div style="border:1px solid var(--bd);border-radius:10px;padding:14px;background:var(--bg)">
      <div style="font-size:11px;color:var(--tm);margin-bottom:6px">服务管理器</div>
      <div style="font-weight:700"><?= htmlspecialchars((string)($sshMeta['service_manager'] ?? '-')) ?></div>
    </div>
  </div>
  <div class="form-hint" style="margin-top:12px">
    配置文件：<code><?= htmlspecialchars((string)($sshMeta['config_path'] ?? '-')) ?></code>
    <?php if (!empty($sshMeta['updated_at'])): ?>，最近刷新：<?= htmlspecialchars((string)$sshMeta['updated_at']) ?><?php endif; ?>
  </div>
  <?php if ($canServiceManage): ?>
  <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-top:12px">
    <?php foreach (['start' => '启动', 'stop' => '停止', 'reload' => '重载', 'restart' => '重启'] as $value => $label): ?>
    <form method="POST" style="display:inline" onsubmit="return confirm('确认执行 SSH 服务<?= $label ?>？')">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="ssh_service_action">
      <input type="hidden" name="service_action" value="<?= htmlspecialchars($value) ?>">
      <button type="submit" class="btn btn-secondary"><?= htmlspecialchars($label) ?></button>
    </form>
    <?php endforeach; ?>
    <form method="POST" style="display:inline">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="ssh_toggle_enable">
      <input type="hidden" name="enabled" value="<?= !empty($sshMeta['enabled']) ? '0' : '1' ?>">
      <button type="submit" class="btn btn-secondary"><?= !empty($sshMeta['enabled']) ? '禁用开机启动' : '启用开机启动' ?></button>
    </form>
    <?php if (empty($sshMeta['installed'])): ?>
    <form method="POST" style="display:inline" onsubmit="return confirm('确认尝试安装 openssh-server？')">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="ssh_install_service">
      <button type="submit" class="btn btn-primary">安装 SSH 服务</button>
    </form>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>

<div class="card" style="margin-bottom:16px">
  <div class="card-title">结构化 SSH 配置</div>
  <form method="POST">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_ssh_structured">
    <div class="form-grid">
      <div class="form-group">
        <label>端口</label>
        <input type="number" name="ssh_port" min="1" max="65535" value="<?= htmlspecialchars((string)($structured['port'] ?? '22')) ?>" <?= $canConfigManage ? '' : 'disabled' ?>>
      </div>
      <div class="form-group">
        <label>ListenAddress</label>
        <input type="text" name="listen_address" value="<?= htmlspecialchars((string)($structured['listenaddress'] ?? '')) ?>" placeholder="留空表示默认" <?= $canConfigManage ? '' : 'disabled' ?>>
      </div>
      <div class="form-group">
        <label>PermitRootLogin</label>
        <select name="permit_root_login" <?= $canConfigManage ? '' : 'disabled' ?>>
          <?php foreach (['yes','no','prohibit-password','forced-commands-only'] as $value): ?>
          <option value="<?= htmlspecialchars($value) ?>" <?= (($structured['permitrootlogin'] ?? 'prohibit-password') === $value) ? 'selected' : '' ?>><?= htmlspecialchars($value) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label>密码登录</label>
        <select name="password_auth" <?= $canConfigManage ? '' : 'disabled' ?>>
          <option value="1" <?= (($structured['passwordauthentication'] ?? 'yes') === 'yes') ? 'selected' : '' ?>>开启</option>
          <option value="0" <?= (($structured['passwordauthentication'] ?? 'yes') !== 'yes') ? 'selected' : '' ?>>关闭</option>
        </select>
      </div>
      <div class="form-group">
        <label>公钥登录</label>
        <select name="pubkey_auth" <?= $canConfigManage ? '' : 'disabled' ?>>
          <option value="1" <?= (($structured['pubkeyauthentication'] ?? 'yes') === 'yes') ? 'selected' : '' ?>>开启</option>
          <option value="0" <?= (($structured['pubkeyauthentication'] ?? 'yes') !== 'yes') ? 'selected' : '' ?>>关闭</option>
        </select>
      </div>
      <div class="form-group">
        <label>AllowUsers</label>
        <input type="text" name="allow_users" value="<?= htmlspecialchars((string)($structured['allowusers'] ?? '')) ?>" placeholder="多个用户用空格分隔" <?= $canConfigManage ? '' : 'disabled' ?>>
      </div>
      <div class="form-group">
        <label>AllowGroups</label>
        <input type="text" name="allow_groups" value="<?= htmlspecialchars((string)($structured['allowgroups'] ?? '')) ?>" placeholder="多个分组用空格分隔" <?= $canConfigManage ? '' : 'disabled' ?>>
      </div>
      <div class="form-group">
        <label>X11Forwarding</label>
        <select name="x11_forwarding" <?= $canConfigManage ? '' : 'disabled' ?>>
          <option value="1" <?= (($structured['x11forwarding'] ?? 'no') === 'yes') ? 'selected' : '' ?>>开启</option>
          <option value="0" <?= (($structured['x11forwarding'] ?? 'no') !== 'yes') ? 'selected' : '' ?>>关闭</option>
        </select>
      </div>
      <div class="form-group">
        <label>MaxAuthTries</label>
        <input type="number" name="max_auth_tries" min="1" max="20" value="<?= htmlspecialchars((string)($structured['maxauthtries'] ?? '6')) ?>" <?= $canConfigManage ? '' : 'disabled' ?>>
      </div>
      <div class="form-group">
        <label>ClientAliveInterval</label>
        <input type="number" name="client_alive_interval" min="0" max="3600" value="<?= htmlspecialchars((string)($structured['clientaliveinterval'] ?? '0')) ?>" <?= $canConfigManage ? '' : 'disabled' ?>>
      </div>
      <div class="form-group">
        <label>ClientAliveCountMax</label>
        <input type="number" name="client_alive_count_max" min="0" max="100" value="<?= htmlspecialchars((string)($structured['clientalivecountmax'] ?? '3')) ?>" <?= $canConfigManage ? '' : 'disabled' ?>>
      </div>
    </div>
    <?php if ($canConfigManage): ?>
    <div class="form-actions">
      <button type="submit" class="btn btn-primary">保存结构化配置</button>
      <button type="button" class="btn btn-secondary" onclick="openSshEditorModal()">编辑原始配置</button>
    </div>
    <?php endif; ?>
  </form>
</div>

<?php endif; ?>
</section>

<!-- Tab 2: 主机列表 -->
<section id="ssh-tab-hosts" class="ssh-tab-panel">
<?php if ($canManage): ?>
<div class="card" style="margin-bottom:16px">
  <div style="display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap;margin-bottom:12px">
    <button type="button" class="btn btn-primary" onclick="openHostModal()">添加主机</button>
    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
      <input type="text" id="remote-host-search" placeholder="搜索主机名 / 地址 / 标签 / 分组" style="min-width:220px;flex:1" oninput="filterRemoteHosts()">
      <select id="remote-host-group-filter" style="min-width:160px" onchange="filterRemoteHosts()">
        <option value="">全部分组</option>
        <?php foreach ($hostGroups as $groupName): ?>
        <option value="<?= htmlspecialchars($groupName) ?>"><?= htmlspecialchars($groupName) ?></option>
        <?php endforeach; ?>
      </select>
      <label style="display:flex;align-items:center;gap:6px;font-size:13px"><input type="checkbox" id="remote-host-favorite-only" onchange="filterRemoteHosts()"> 仅看收藏</label>
    </div>
  </div>
  <div class="table-wrap"><table>
    <thead><tr><th>主机</th><th>地址</th><th>认证</th><th>分组</th><th>操作</th></tr></thead>
    <tbody>
      <?php foreach ($remoteHosts as $host): ?>
      <tr class="remote-host-row" data-host-name="<?= htmlspecialchars((string)($host['name'] ?? '')) ?>" data-host-search="<?= htmlspecialchars(strtolower(((string)($host['name'] ?? '')) . ' ' . ((string)($host['hostname'] ?? '')) . ' ' . ((string)($host['group_name'] ?? '')) . ' ' . implode(' ', (array)($host['tags'] ?? [])))) ?>" data-group-name="<?= htmlspecialchars((string)($host['group_name'] ?? '')) ?>" data-favorite="<?= !empty($host['favorite']) ? '1' : '0' ?>">
        <td>
          <strong><?= htmlspecialchars((string)($host['name'] ?? '')) ?></strong>
          <?php if (!empty($host['favorite'])): ?><span class="badge badge-green" style="margin-left:6px">收藏</span><?php endif; ?>
          <?php if (!empty($host['tags'])): ?><div class="form-hint">标签：<?= htmlspecialchars(implode(', ', (array)$host['tags'])) ?></div><?php endif; ?>
        </td>
        <td style="font-family:var(--mono)"><?= htmlspecialchars((string)($host['username'] ?? 'root')) ?>@<?= htmlspecialchars((string)($host['hostname'] ?? '')) ?>:<?= (int)($host['port'] ?? 22) ?></td>
        <td><?= htmlspecialchars((string)($host['auth_type'] ?? 'key')) ?></td>
        <td><?= htmlspecialchars((string)($host['group_name'] ?? '')) ?></td>
        <td style="white-space:nowrap">
          <button type="button" class="btn btn-sm btn-secondary" onclick="openHostModal('<?= htmlspecialchars((string)($host['id'] ?? '')) ?>')">编辑</button>
          <button type="button" class="btn btn-sm btn-secondary" onclick="testRemoteHost('<?= htmlspecialchars((string)($host['id'] ?? '')) ?>')">测试</button>
          <?php if ($canConfigManage || $canServiceManage): ?><button type="button" class="btn btn-sm btn-secondary" onclick="openRemoteConsoleModal('<?= htmlspecialchars((string)($host['id'] ?? '')) ?>')">SSH</button><?php endif; ?>
          <form method="POST" style="display:inline" onsubmit="return confirm('确认删除远程主机？')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete_remote_host">
            <input type="hidden" name="host_id" value="<?= htmlspecialchars((string)($host['id'] ?? '')) ?>">
            <button type="submit" class="btn btn-sm btn-danger">删除</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if (!$remoteHosts): ?>
      <tr><td colspan="5" style="color:var(--tm)">暂无远程主机。</td></tr>
      <?php endif; ?>
    </tbody>
  </table></div>
</div>
<?php else: ?>
<div class="form-hint">当前角色没有远程主机管理权限。</div>
<?php endif; ?>
</section>

<!-- Tab 3: 会话管理 -->
<section id="ssh-tab-sessions" class="ssh-tab-panel">
<div class="card" style="margin-bottom:16px;text-align:center;padding:48px 24px">
  <div style="font-size:48px;margin-bottom:16px">🕓</div>
  <div style="font-size:16px;font-weight:700;margin-bottom:8px">建设中</div>
  <div style="color:var(--tm);font-size:13px;max-width:480px;margin:0 auto;line-height:1.8">
    当前尚未接入系统 SSH 会话数据源（who / utmp）。<br>
    如需此功能，需在 host-agent 新增 <code>/ssh/sessions</code> 端点。
  </div>
</div>
</section>

<!-- Tab 4: 登录日志 -->
<section id="ssh-tab-logs" class="ssh-tab-panel">
<?php if (!$canAudit): ?>
  <div class="form-hint">当前角色没有查看审计日志权限。</div>
<?php else: ?>
<div class="card" style="margin-bottom:16px">
  <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:12px">
    <select id="audit-action-filter" style="min-width:180px" onchange="filterAuditRows()">
      <option value="">全部动作</option>
      <?php foreach (array_values(array_unique(array_map(static fn(array $log): string => (string)($log['action'] ?? ''), $auditLogs))) as $actionName): ?>
      <?php if ($actionName !== ''): ?>
      <option value="<?= htmlspecialchars($actionName) ?>"><?= htmlspecialchars($actionName) ?></option>
      <?php endif; ?>
      <?php endforeach; ?>
    </select>
    <select id="audit-host-filter" style="min-width:180px" onchange="filterAuditRows()">
      <option value="">全部主机</option>
      <option value="local">本机</option>
      <?php foreach ($remoteHosts as $host): ?>
      <option value="<?= htmlspecialchars((string)($host['id'] ?? '')) ?>"><?= htmlspecialchars((string)($host['name'] ?? '')) ?></option>
      <?php endforeach; ?>
    </select>
    <input type="text" id="audit-keyword-filter" placeholder="搜索动作或上下文" style="min-width:220px;flex:1" oninput="filterAuditRows()">
    <?php if ($canAuditExport): ?><button type="button" class="btn btn-secondary" onclick="exportAuditLogs()">导出日志</button><?php endif; ?>
  </div>
  <div class="table-wrap"><table>
    <thead><tr><th>时间</th><th>用户</th><th>动作</th><th>上下文</th></tr></thead>
    <tbody>
      <?php foreach ($auditLogs as $log): ?>
      <?php
      $context = is_array($log['context'] ?? null) ? $log['context'] : [];
      $logHostId = trim((string)($context['host_id'] ?? ''));
      $contextJson = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
      ?>
      <tr class="audit-row" data-action="<?= htmlspecialchars((string)($log['action'] ?? '')) ?>" data-host-id="<?= htmlspecialchars($logHostId) ?>" data-search="<?= htmlspecialchars(strtolower(((string)($log['action'] ?? '')) . ' ' . ((string)$contextJson))) ?>">
        <td style="font-family:var(--mono)"><?= htmlspecialchars((string)($log['time'] ?? '')) ?></td>
        <td><?= htmlspecialchars((string)($log['user'] ?? '')) ?></td>
        <td><?= htmlspecialchars((string)($log['action'] ?? '')) ?></td>
        <td><pre style="margin:0;white-space:pre-wrap;background:none;border:none;padding:0;color:var(--tx2);font-size:12px"><?= htmlspecialchars((string)$contextJson) ?></pre></td>
      </tr>
      <?php endforeach; ?>
      <?php if (!$auditLogs): ?>
      <tr><td colspan="4" style="color:var(--tm)">暂无审计日志。</td></tr>
      <?php endif; ?>
    </tbody>
  </table></div>
</div>
<?php endif; ?>
</section>

<!-- SSH 密钥管理（放在页面底部，不放入 Tab） -->
<?php if ($canKeys): ?>
<div class="card" style="margin-bottom:16px">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
    <div class="card-title" style="margin-bottom:0">SSH 密钥管理</div>
    <button type="button" class="btn btn-primary" onclick="openSshKeyModal()">添加密钥</button>
  </div>
  <div class="table-wrap"><table>
    <thead><tr><th>名称</th><th>指纹</th><th>默认用户</th><th>操作</th></tr></thead>
    <tbody>
      <?php foreach ($sshKeys as $key): ?>
      <tr data-key-id="<?= htmlspecialchars((string)($key['id'] ?? '')) ?>">
        <td><strong><?= htmlspecialchars((string)($key['name'] ?? '')) ?></strong></td>
        <td style="font-family:var(--mono);font-size:12px"><?= htmlspecialchars((string)($key['fingerprint'] ?? '-')) ?></td>
        <td><?= htmlspecialchars((string)($key['username'] ?? '-')) ?></td>
        <td style="white-space:nowrap">
          <?php if ($canKeys): ?><button type="button" class="btn btn-sm btn-secondary" onclick="openSshKeyModal('<?= htmlspecialchars((string)($key['id'] ?? '')) ?>')">编辑</button><?php endif; ?>
          <?php if ($canKeys): ?>
          <form method="POST" style="display:inline" onsubmit="return confirm('确认删除 SSH 密钥？')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete_ssh_key">
            <input type="hidden" name="key_id" value="<?= htmlspecialchars((string)($key['id'] ?? '')) ?>">
            <button type="submit" class="btn btn-sm btn-danger">删除</button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if (!$sshKeys): ?>
      <tr><td colspan="4" style="color:var(--tm)">暂无 SSH 密钥。</td></tr>
      <?php endif; ?>
    </tbody>
  </table></div>
</div>
<?php endif; ?>

<!-- 弹窗：SSH 密钥管理 -->
<div id="ssh-key-modal" style="display:none;position:fixed;inset:0;z-index:980;background:rgba(0,0,0,.72);align-items:center;justify-content:center" onclick="if(event.target===this)closeSshKeyModal()">
  <div style="background:var(--sf);border:1px solid var(--bd2);border-radius:var(--r2);width:min(560px,96vw);max-height:90vh;overflow:auto">
    <div style="padding:14px 18px;border-bottom:1px solid var(--bd);display:flex;justify-content:space-between;align-items:center;gap:12px">
      <strong id="ssh-key-modal-title">添加 SSH 密钥</strong>
      <button type="button" class="btn btn-secondary" onclick="closeSshKeyModal()">关闭</button>
    </div>
    <div style="padding:18px">
      <form method="POST" id="ssh-key-modal-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_ssh_key">
        <input type="hidden" name="key_id" id="modal-key-id" value="">
        <div class="form-grid">
          <div class="form-group"><label>密钥名称</label><input type="text" name="key_name" id="modal-key-name" required></div>
          <div class="form-group"><label>默认用户名</label><input type="text" name="key_username" id="modal-key-username"></div>
          <div class="form-group full"><label>私钥内容<span id="modal-key-private-hint"></span></label><textarea name="private_key" id="modal-key-private" spellcheck="false" style="min-height:180px;background:var(--bg);border:1px solid var(--bd);border-radius:10px;padding:12px;color:var(--tx);font-family:var(--mono)"></textarea></div>
          <div class="form-group full"><label>私钥口令（可选）</label><input type="password" name="passphrase" id="modal-key-passphrase" value=""></div>
        </div>
        <div class="form-actions">
          <button type="submit" class="btn btn-primary">保存 SSH 密钥</button>
          <button type="button" class="btn btn-secondary" onclick="closeSshKeyModal()">取消</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Ace Editor 弹窗：编辑 sshd_config -->
<div id="ssh-editor-modal" style="display:none;position:fixed;inset:0;z-index:980;background:rgba(0,0,0,.72);align-items:center;justify-content:center" onclick="if(event.target===this)closeSshEditorModal()">
  <div style="background:var(--sf);border:1px solid var(--bd2);border-radius:var(--r2);width:min(1080px,96vw);max-height:90vh;display:flex;flex-direction:column">
    <div style="padding:14px 18px;border-bottom:1px solid var(--bd);display:flex;justify-content:space-between;align-items:center;gap:12px">
      <strong>编辑 sshd_config</strong>
      <button type="button" class="btn btn-secondary" onclick="closeSshEditorModal()">关闭</button>
    </div>
    <div style="padding:14px 18px;flex:1;display:flex;flex-direction:column;overflow:hidden">
      <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:10px">
        <select id="ssh-editor-theme" style="min-width:140px">
          <option value="tomorrow_night">Tomorrow Night</option>
          <option value="monokai">Monokai</option>
        </select>
        <select id="ssh-editor-font-size" style="min-width:80px">
          <option value="12">12px</option>
          <option value="13" selected>13px</option>
          <option value="14">14px</option>
        </select>
        <button type="button" class="btn btn-secondary" onclick="sshEditor.find()">查找</button>
      </div>
      <div id="ssh-ace-editor" style="flex:1;min-height:50vh;border:1px solid var(--bd);border-radius:10px"></div>
      <form method="POST" id="ssh-editor-form" style="display:none">
        <?= csrf_field() ?>
        <input type="hidden" name="action" id="ssh-editor-action" value="save_ssh_config">
        <textarea name="ssh_config" id="ssh-editor-hidden-content" style="display:none"></textarea>
      </form>
      <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-top:14px">
        <button type="button" class="btn btn-secondary" onclick="validateSshEditorConfig()">校验配置</button>
        <button type="button" class="btn btn-primary" onclick="saveSshEditorConfig()">保存并应用</button>
        <button type="button" class="btn btn-secondary" onclick="restoreSshEditorBackup()">恢复最近一次备份</button>
      </div>
    </div>
  </div>
</div>

<!-- 弹窗：添加 / 编辑主机 -->
<div id="host-modal" style="display:none;position:fixed;inset:0;z-index:980;background:rgba(0,0,0,.72);align-items:center;justify-content:center" onclick="if(event.target===this)closeHostModal()">
  <div style="background:var(--sf);border:1px solid var(--bd2);border-radius:var(--r2);width:min(560px,96vw);max-height:90vh;overflow:auto">
    <div style="padding:14px 18px;border-bottom:1px solid var(--bd);display:flex;justify-content:space-between;align-items:center;gap:12px">
      <strong id="host-modal-title">添加主机</strong>
      <button type="button" class="btn btn-secondary" onclick="closeHostModal()">关闭</button>
    </div>
    <div style="padding:18px">
      <form method="POST" id="host-modal-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_remote_host">
        <input type="hidden" name="host_id" id="modal-host-id" value="">
        <div class="form-grid">
          <div class="form-group"><label>主机名称</label><input type="text" name="host_name" id="modal-host-name" required></div>
          <div class="form-group"><label>主机地址</label><input type="text" name="hostname" id="modal-host-hostname" required></div>
          <div class="form-group"><label>用户名</label><input type="text" name="username" id="modal-host-username" value="root" required></div>
          <div class="form-group"><label>端口</label><input type="number" name="port" id="modal-host-port" min="1" max="65535" value="22"></div>
          <div class="form-group">
            <label>认证方式</label>
            <select name="auth_type" id="modal-host-auth-type" onchange="syncModalAuthType()">
              <option value="key">密钥</option>
              <option value="password">密码</option>
            </select>
          </div>
          <div class="form-group" id="modal-host-key-wrap"><label>SSH 密钥</label>
            <select name="key_id" id="modal-host-key-id">
              <option value="">请选择密钥</option>
              <?php foreach ($sshKeys as $key): ?>
              <option value="<?= htmlspecialchars((string)$key['id']) ?>"><?= htmlspecialchars((string)($key['name'] ?? '')) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group" id="modal-host-password-wrap" style="display:none"><label>密码</label><input type="password" name="password" id="modal-host-password" value=""></div>
          <div class="form-group"><label>主机分组</label><input type="text" name="group_name" id="modal-host-group" list="host-group-list" value=""></div>
          <div class="form-group"><label>标签</label><input type="text" name="tags" id="modal-host-tags" placeholder="多个标签用逗号分隔"></div>
          <div class="form-group"><label>收藏</label><select name="favorite" id="modal-host-favorite"><option value="0">否</option><option value="1">是</option></select></div>
          <div class="form-group full"><label>备注</label><input type="text" name="notes" id="modal-host-notes" value=""></div>
        </div>
        <div class="form-actions">
          <button type="submit" class="btn btn-primary">保存主机</button>
          <button type="button" class="btn btn-secondary" onclick="closeHostModal()">取消</button>
        </div>
      </form>
    </div>
  </div>
</div>
<datalist id="host-group-list">
  <?php foreach ($hostGroups as $groupName): ?>
  <option value="<?= htmlspecialchars($groupName) ?>"></option>
  <?php endforeach; ?>
</datalist>

<!-- 弹窗：远程 SSH 控制台 -->
<div id="remote-console-modal" style="display:none;position:fixed;inset:0;z-index:980;background:rgba(0,0,0,.72);align-items:center;justify-content:center" onclick="if(event.target===this)closeRemoteConsoleModal()">
  <div style="background:var(--sf);border:1px solid var(--bd2);border-radius:var(--r2);width:min(720px,96vw);max-height:90vh;overflow:auto">
    <div style="padding:14px 18px;border-bottom:1px solid var(--bd);display:flex;justify-content:space-between;align-items:center;gap:12px">
      <strong id="remote-console-modal-title">远程 SSH 控制台</strong>
      <button type="button" class="btn btn-secondary" onclick="closeRemoteConsoleModal()">关闭</button>
    </div>
    <div style="padding:18px">
      <input type="hidden" id="remote-console-host-id" value="">
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:12px">
        <div style="border:1px solid var(--bd);border-radius:10px;padding:14px;background:var(--bg)">
          <div style="font-size:11px;color:var(--tm);margin-bottom:6px">已安装</div>
          <div id="remote-console-installed">-</div>
        </div>
        <div style="border:1px solid var(--bd);border-radius:10px;padding:14px;background:var(--bg)">
          <div style="font-size:11px;color:var(--tm);margin-bottom:6px">运行中</div>
          <div id="remote-console-running">-</div>
        </div>
        <div style="border:1px solid var(--bd);border-radius:10px;padding:14px;background:var(--bg)">
          <div style="font-size:11px;color:var(--tm);margin-bottom:6px">开机启用</div>
          <div id="remote-console-enabled">-</div>
        </div>
        <div style="border:1px solid var(--bd);border-radius:10px;padding:14px;background:var(--bg)">
          <div style="font-size:11px;color:var(--tm);margin-bottom:6px">服务管理器</div>
          <div id="remote-console-service-manager" style="font-weight:700">-</div>
        </div>
      </div>
      <div class="form-hint" style="margin-bottom:12px">配置文件：<code id="remote-console-config-path">-</code></div>
      <?php if ($canServiceManage): ?>
      <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:14px">
        <?php foreach (['start' => '启动', 'stop' => '停止', 'reload' => '重载', 'restart' => '重启'] as $value => $label): ?>
        <button type="button" class="btn btn-secondary" onclick="runRemoteConsoleAction('<?= htmlspecialchars($value) ?>')"><?= htmlspecialchars($label) ?></button>
        <?php endforeach; ?>
        <button type="button" class="btn btn-secondary" onclick="toggleRemoteConsoleEnable(true)">启用自启</button>
        <button type="button" class="btn btn-secondary" onclick="toggleRemoteConsoleEnable(false)">禁用自启</button>
        <button type="button" class="btn btn-primary" onclick="installRemoteConsoleService()">安装 SSH</button>
      </div>
      <?php endif; ?>
      <?php if ($canConfigManage): ?>
      <div class="card" style="padding:14px;margin-bottom:12px;background:var(--bg)">
        <div class="card-title" style="margin-bottom:10px">远程结构化配置</div>
        <div class="form-grid">
          <div class="form-group"><label>端口</label><input type="number" id="remote-console-port" min="1" max="65535" value="22"></div>
          <div class="form-group"><label>ListenAddress</label><input type="text" id="remote-console-listen-address" placeholder="留空表示默认"></div>
          <div class="form-group"><label>PermitRootLogin</label>
            <select id="remote-console-permit-root-login">
              <?php foreach (['yes','no','prohibit-password','forced-commands-only'] as $value): ?>
              <option value="<?= htmlspecialchars($value) ?>"><?= htmlspecialchars($value) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group"><label>密码登录</label><select id="remote-console-password-auth"><option value="1">开启</option><option value="0">关闭</option></select></div>
          <div class="form-group"><label>公钥登录</label><select id="remote-console-pubkey-auth"><option value="1">开启</option><option value="0">关闭</option></select></div>
          <div class="form-group"><label>AllowUsers</label><input type="text" id="remote-console-allow-users" placeholder="多个用户用空格分隔"></div>
          <div class="form-group"><label>AllowGroups</label><input type="text" id="remote-console-allow-groups" placeholder="多个分组用空格分隔"></div>
          <div class="form-group"><label>X11Forwarding</label><select id="remote-console-x11-forwarding"><option value="1">开启</option><option value="0">关闭</option></select></div>
          <div class="form-group"><label>MaxAuthTries</label><input type="number" id="remote-console-max-auth-tries" min="1" max="20" value="6"></div>
          <div class="form-group"><label>ClientAliveInterval</label><input type="number" id="remote-console-client-alive-interval" min="0" max="3600" value="0"></div>
          <div class="form-group"><label>ClientAliveCountMax</label><input type="number" id="remote-console-client-alive-count-max" min="0" max="100" value="3"></div>
        </div>
        <div class="form-actions">
          <button type="button" class="btn btn-primary" onclick="saveRemoteConsoleStructured()">保存结构化配置</button>
        </div>
      </div>
      <?php endif; ?>
      <?php if ($canFiles): ?>
      <div class="card" style="padding:14px;margin-bottom:12px;background:var(--bg)">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;flex-wrap:wrap;gap:8px">
          <div class="card-title" style="margin-bottom:0">目标主机 authorized_keys</div>
          <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
            <select id="remote-console-ak-user" style="width:130px">
              <option value="root">root</option>
            </select>
            <button type="button" class="btn btn-sm btn-secondary" onclick="loadRemoteAuthorizedKeys()">刷新</button>
            <?php if ($canFileWrite && $sshKeys): ?>
            <select id="remote-console-ak-key" style="width:160px">
              <option value="">选择要导入的密钥</option>
              <?php foreach ($sshKeys as $key): ?>
              <option value="<?= htmlspecialchars((string)($key['id'] ?? '')) ?>"><?= htmlspecialchars((string)($key['name'] ?? '')) ?></option>
              <?php endforeach; ?>
            </select>
            <button type="button" class="btn btn-sm btn-primary" onclick="importRemoteAuthorizedKey()">导入</button>
            <?php endif; ?>
          </div>
        </div>
        <div class="table-wrap">
          <table>
            <thead><tr><th>类型</th><th>密钥/注释</th><th>操作</th></tr></thead>
            <tbody id="remote-console-ak-tbody"><tr><td colspan="3" style="color:var(--tm)">点击刷新读取</td></tr></tbody>
          </table>
        </div>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<script src="assets/ace/ace.js"></script>
<script>
var HOST_CSRF = <?= json_encode($csrfValue) ?>;
var HOST_CAN_CONFIG_MANAGE = <?= $canConfigManage ? 'true' : 'false' ?>;
var HOST_CAN_SERVICE_MANAGE = <?= $canServiceManage ? 'true' : 'false' ?>;
var HOST_SSH_CONFIG_CONTENT = <?= json_encode((string)($sshConfigData['content'] ?? ''), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
var HOST_STATUS = navCreateAsyncStatus({
  progressTexts: {
    connecting: '正在连接 host-agent…',
    loading: '正在获取数据…',
    processing: '数据较多，继续处理中…'
  },
  getRefs: function(scope) {
    return {
      wrap: document.getElementById(scope + '-status')
    };
  }
});

function hostProgressRefs(scope) {
  return { wrap: document.getElementById(scope + '-status') };
}
function hostSetProgress(scope, title, detail, percent, tone) { HOST_STATUS.set(scope, title, detail, percent, tone); }
function hostHideProgress(scope) { HOST_STATUS.hide(scope); }
function hostStartProgress(scope, title, detail) { return HOST_STATUS.start(scope, title, detail); }
function hostFinishProgress(id, ok, detail) { HOST_STATUS.finish(id, ok, detail); }
async function hostRunRequest(scope, title, detail, runner) { return HOST_STATUS.run(scope, title, detail, runner); }

function buildHostApiBody(action, params) {
  var body = new URLSearchParams();
  body.append('action', action);
  body.append('_csrf', HOST_CSRF);
  Object.keys(params || {}).forEach(function(key) {
    var value = params[key];
    if (value === undefined || value === null) return;
    if (Array.isArray(value)) { value.forEach(function(item) { body.append(key + '[]', item); }); return; }
    body.append(key, value);
  });
  return body;
}
async function postHostApi(action, params) {
  var body = buildHostApiBody(action, params || {});
  var res = await fetch('host_api.php', {
    method: 'POST', credentials: 'same-origin',
    headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' },
    body: body
  });
  return res.json();
}
async function getHostApi(action, params) {
  var query = new URLSearchParams(Object.assign({ action: action }, params || {}));
  var res = await fetch('host_api.php?' + query.toString(), {
    credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' }
  });
  return res.json();
}

function escapeHtml(value) {
  return String(value || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}
function setBooleanBadge(elementId, value) {
  var el = document.getElementById(elementId);
  if (!el) return;
  var cls = 'badge-gray', text = '未知';
  if (value === true) { cls = 'badge-green'; text = '是'; }
  else if (value === false) { cls = 'badge-red'; text = '否'; }
  el.innerHTML = '<span class="badge ' + cls + '">' + text + '</span>';
}

/* ── Tab 切换 ── */
function sshSwitchTab(tab) {
  document.querySelectorAll('.ssh-tab-btn').forEach(function(btn) {
    btn.classList.toggle('active', btn.getAttribute('data-tab') === tab);
  });
  document.querySelectorAll('.ssh-tab-panel').forEach(function(panel) {
    panel.classList.toggle('active', panel.id === 'ssh-tab-' + tab);
  });
  try { localStorage.setItem('ssh_active_tab', tab); } catch(e) {}
}
(function initTab() {
  var saved = '';
  try { saved = localStorage.getItem('ssh_active_tab') || ''; } catch(e) {}
  if (saved && document.getElementById('ssh-tab-' + saved)) sshSwitchTab(saved);
})();

/* ── Ace Editor 弹窗 ── */
var sshEditor = null;
function openSshEditorModal() {
  var modal = document.getElementById('ssh-editor-modal');
  modal.style.display = 'flex';
  if (!sshEditor) {
    sshEditor = ace.edit('ssh-ace-editor');
    sshEditor.setTheme('ace/theme/tomorrow_night');
    sshEditor.session.setMode('ace/mode/text');
    sshEditor.session.setUseWrapMode(true);
    sshEditor.session.setTabSize(2);
    sshEditor.session.setUseSoftTabs(true);
    sshEditor.setOptions({ fontSize: '13px', showPrintMargin: false, useWorker: false });
    document.getElementById('ssh-editor-theme').addEventListener('change', function() {
      var t = this.value; var safe = ['tomorrow_night','monokai']; if (safe.indexOf(t) < 0) t = 'tomorrow_night';
      sshEditor.setTheme('ace/theme/' + t);
    });
    document.getElementById('ssh-editor-font-size').addEventListener('change', function() {
      sshEditor.setOptions({ fontSize: this.value + 'px' });
    });
  }
  sshEditor.setValue(HOST_SSH_CONFIG_CONTENT || '', -1);
  sshEditor.focus();
}
function closeSshEditorModal() {
  document.getElementById('ssh-editor-modal').style.display = 'none';
}
function saveSshEditorConfig() {
  if (!sshEditor) return;
  document.getElementById('ssh-editor-action').value = 'save_ssh_config';
  document.getElementById('ssh-editor-hidden-content').value = sshEditor.getValue();
  document.getElementById('ssh-editor-form').submit();
}
function validateSshEditorConfig() {
  if (!sshEditor) return;
  document.getElementById('ssh-editor-action').value = 'validate_ssh_config';
  document.getElementById('ssh-editor-hidden-content').value = sshEditor.getValue();
  document.getElementById('ssh-editor-form').submit();
}
function restoreSshEditorBackup() {
  if (!confirm('确认恢复最近一次 SSH 配置备份？')) return;
  document.getElementById('ssh-editor-action').value = 'restore_ssh_backup';
  document.getElementById('ssh-editor-hidden-content').value = '';
  document.getElementById('ssh-editor-form').submit();
}

/* ── 主机弹窗 ── */
var HOST_EDIT_DATA = <?= json_encode((function($hosts) {
  $out = [];
  foreach ($hosts as $h) {
    $out[(string)($h['id'] ?? '')] = [
      'id' => $h['id'] ?? '', 'name' => $h['name'] ?? '', 'hostname' => $h['hostname'] ?? '',
      'port' => $h['port'] ?? 22, 'username' => $h['username'] ?? 'root',
      'auth_type' => $h['auth_type'] ?? 'key', 'key_id' => $h['key_id'] ?? '',
      'group_name' => $h['group_name'] ?? '', 'tags' => implode(',', (array)($h['tags'] ?? [])),
      'favorite' => !empty($h['favorite']) ? '1' : '0', 'notes' => $h['notes'] ?? ''
    ];
  }
  return $out;
})($remoteHosts), JSON_UNESCAPED_UNICODE) ?>;
var SSH_KEYS_DATA = <?= json_encode((function($keys) {
  $out = [];
  foreach ($keys as $k) {
    $out[(string)($k['id'] ?? '')] = [
      'id' => $k['id'] ?? '', 'name' => $k['name'] ?? '', 'username' => $k['username'] ?? '',
      'fingerprint' => $k['fingerprint'] ?? ''
    ];
  }
  return $out;
})($sshKeys), JSON_UNESCAPED_UNICODE) ?>;
function openHostModal(hostId) {
  var modal = document.getElementById('host-modal');
  modal.style.display = 'flex';
  var title = document.getElementById('host-modal-title');
  var form = document.getElementById('host-modal-form');
  if (hostId && HOST_EDIT_DATA[hostId]) {
    var h = HOST_EDIT_DATA[hostId];
    title.textContent = '编辑主机';
    document.getElementById('modal-host-id').value = h.id || '';
    document.getElementById('modal-host-name').value = h.name || '';
    document.getElementById('modal-host-hostname').value = h.hostname || '';
    document.getElementById('modal-host-username').value = h.username || 'root';
    document.getElementById('modal-host-port').value = h.port || 22;
    document.getElementById('modal-host-auth-type').value = h.auth_type || 'key';
    document.getElementById('modal-host-key-id').value = h.key_id || '';
    document.getElementById('modal-host-group').value = h.group_name || '';
    document.getElementById('modal-host-tags').value = h.tags || '';
    document.getElementById('modal-host-favorite').value = h.favorite || '0';
    document.getElementById('modal-host-notes').value = h.notes || '';
  } else {
    title.textContent = '添加主机';
    form.reset();
    document.getElementById('modal-host-id').value = '';
    document.getElementById('modal-host-port').value = '22';
    document.getElementById('modal-host-username').value = 'root';
    document.getElementById('modal-host-favorite').value = '0';
  }
  syncModalAuthType();
}
function closeHostModal() {
  document.getElementById('host-modal').style.display = 'none';
}

/* ── SSH 密钥弹窗 ── */
function openSshKeyModal(keyId) {
  var modal = document.getElementById('ssh-key-modal');
  modal.style.display = 'flex';
  var title = document.getElementById('ssh-key-modal-title');
  var form = document.getElementById('ssh-key-modal-form');
  if (keyId && SSH_KEYS_DATA[keyId]) {
    var k = SSH_KEYS_DATA[keyId];
    title.textContent = '编辑 SSH 密钥';
    document.getElementById('modal-key-id').value = k.id || '';
    document.getElementById('modal-key-name').value = k.name || '';
    document.getElementById('modal-key-username').value = k.username || '';
    document.getElementById('modal-key-private').value = '';
    document.getElementById('modal-key-passphrase').value = '';
    document.getElementById('modal-key-private-hint').textContent = '（留空则保留原有私钥）';
  } else {
    title.textContent = '添加 SSH 密钥';
    form.reset();
    document.getElementById('modal-key-id').value = '';
    document.getElementById('modal-key-private-hint').textContent = '';
  }
}
function closeSshKeyModal() {
  document.getElementById('ssh-key-modal').style.display = 'none';
}

function syncModalAuthType() {
  var type = document.getElementById('modal-host-auth-type').value;
  var usePassword = type === 'password';
  document.getElementById('modal-host-key-wrap').style.display = usePassword ? 'none' : '';
  document.getElementById('modal-host-password-wrap').style.display = usePassword ? '' : 'none';
}

/* ── 远程 SSH 控制台弹窗 ── */
function openRemoteConsoleModal(hostId) {
  var modal = document.getElementById('remote-console-modal');
  modal.style.display = 'flex';
  document.getElementById('remote-console-host-id').value = hostId || '';
  var name = (HOST_EDIT_DATA[hostId] || {}).name || hostId;
  document.getElementById('remote-console-modal-title').textContent = '远程 SSH 控制台 — ' + name;
  var userSelect = document.getElementById('remote-console-ak-user');
  if (userSelect) { userSelect.value = (HOST_EDIT_DATA[hostId] || {}).username || 'root'; }
  loadRemoteConsolePanel();
}
function closeRemoteConsoleModal() {
  document.getElementById('remote-console-modal').style.display = 'none';
}
async function loadRemoteConsolePanel() {
  var hostId = document.getElementById('remote-console-host-id').value;
  if (!hostId) return;
  var requests = [getHostApi('ssh_target_status', { host_id: hostId })];
  if (HOST_CAN_CONFIG_MANAGE) requests.push(getHostApi('ssh_target_config_read', { host_id: hostId }));
  var remoteData = await hostRunRequest('host-ssh', '获取远程 SSH 状态', '正在读取远程服务状态和配置…', function() {
    return Promise.all(requests).then(function(items) { return { ok: true, items: items }; });
  });
  var results = remoteData.items || [];
  var status = results[0];
  var config = results[1] || { ok: true, content: '', structured: {} };
  if (!status.ok) { showToast(status.msg || '远程 SSH 状态读取失败', 'error'); return; }
  setBooleanBadge('remote-console-installed', !!status.installed);
  setBooleanBadge('remote-console-running', typeof status.running === 'boolean' ? status.running : null);
  setBooleanBadge('remote-console-enabled', typeof status.enabled === 'boolean' ? status.enabled : null);
  document.getElementById('remote-console-service-manager').textContent = status.service_manager || '-';
  document.getElementById('remote-console-config-path').textContent = status.config_path || '-';
  if (config.ok && HOST_CAN_CONFIG_MANAGE) {
    var s = config.structured || {};
    document.getElementById('remote-console-port').value = s.port || '22';
    document.getElementById('remote-console-listen-address').value = s.listenaddress || '';
    document.getElementById('remote-console-permit-root-login').value = s.permitrootlogin || 'prohibit-password';
    document.getElementById('remote-console-password-auth').value = (s.passwordauthentication === 'no') ? '0' : '1';
    document.getElementById('remote-console-pubkey-auth').value = (s.pubkeyauthentication === 'no') ? '0' : '1';
    document.getElementById('remote-console-allow-users').value = s.allowusers || '';
    document.getElementById('remote-console-allow-groups').value = s.allowgroups || '';
    document.getElementById('remote-console-x11-forwarding').value = (s.x11forwarding === 'yes') ? '1' : '0';
    document.getElementById('remote-console-max-auth-tries').value = s.maxauthtries || '6';
    document.getElementById('remote-console-client-alive-interval').value = s.clientaliveinterval || '0';
    document.getElementById('remote-console-client-alive-count-max').value = s.clientalivecountmax || '3';
  }
}
async function runRemoteConsoleAction(action) {
  var hostId = document.getElementById('remote-console-host-id').value;
  if (!hostId) return;
  var data = await hostRunRequest('host-ssh', '执行远程 SSH 操作', '正在对远程 SSH 服务执行 ' + action + ' …', function() {
    return postHostApi('ssh_target_service_action', { host_id: hostId, service_action: action });
  });
  showToast(data.msg || (data.ok ? '执行成功' : '执行失败'), data.ok ? 'success' : 'error');
  if (data.ok) loadRemoteConsolePanel();
}
async function toggleRemoteConsoleEnable(enabled) {
  var hostId = document.getElementById('remote-console-host-id').value;
  if (!hostId) return;
  var data = await hostRunRequest('host-ssh', '更新 SSH 开机启动', '正在修改远程 SSH 自启状态…', function() {
    return postHostApi('ssh_target_toggle_enable', { host_id: hostId, enabled: enabled ? '1' : '0' });
  });
  showToast(data.msg || (data.ok ? '设置成功' : '设置失败'), data.ok ? 'success' : 'error');
  if (data.ok) loadRemoteConsolePanel();
}
async function installRemoteConsoleService() {
  if (!confirm('确认尝试在远程主机安装 openssh-server？')) return;
  var hostId = document.getElementById('remote-console-host-id').value;
  if (!hostId) return;
  var data = await hostRunRequest('host-ssh', '安装远程 SSH 服务', '正在尝试安装 openssh-server …', function() {
    return postHostApi('ssh_target_install_service', { host_id: hostId });
  });
  showToast(data.msg || (data.ok ? '安装完成' : '安装失败'), data.ok ? 'success' : 'error');
  if (data.ok) loadRemoteConsolePanel();
}
async function saveRemoteConsoleStructured() {
  if (!HOST_CAN_CONFIG_MANAGE) { showToast('当前角色没有 SSH 配置编辑权限', 'warning'); return; }
  var hostId = document.getElementById('remote-console-host-id').value;
  if (!hostId) return;
  var data = await hostRunRequest('host-ssh', '保存结构化 SSH 配置', '正在写入结构化配置并校验…', function() {
    return postHostApi('ssh_target_structured_save', {
      host_id: hostId,
      ssh_port: document.getElementById('remote-console-port').value,
      listen_address: document.getElementById('remote-console-listen-address').value,
      permit_root_login: document.getElementById('remote-console-permit-root-login').value,
      password_auth: document.getElementById('remote-console-password-auth').value,
      pubkey_auth: document.getElementById('remote-console-pubkey-auth').value,
      allow_users: document.getElementById('remote-console-allow-users').value,
      allow_groups: document.getElementById('remote-console-allow-groups').value,
      x11_forwarding: document.getElementById('remote-console-x11-forwarding').value,
      max_auth_tries: document.getElementById('remote-console-max-auth-tries').value,
      client_alive_interval: document.getElementById('remote-console-client-alive-interval').value,
      client_alive_count_max: document.getElementById('remote-console-client-alive-count-max').value
    });
  });
  showToast(data.msg || (data.ok ? '远程结构化 SSH 配置已保存' : '保存失败'), data.ok ? 'success' : 'error');
  if (data.ok) loadRemoteConsolePanel();
}

/* ── 远程 authorized_keys 管理 ── */
async function loadRemoteAuthorizedKeys() {
  var hostId = document.getElementById('remote-console-host-id').value;
  if (!hostId) return;
  var userName = (document.getElementById('remote-console-ak-user') || {}).value || 'root';
  var tbody = document.getElementById('remote-console-ak-tbody');
  tbody.innerHTML = '<tr><td colspan="3" style="color:var(--tm)">读取中…</td></tr>';
  var data = await hostRunRequest('host-ssh', '读取 authorized_keys', '正在读取 ' + userName + ' 的 authorized_keys …', function() {
    return postHostApi('authorized_keys_list', { host_id: hostId, user: userName });
  });
  if (!data.ok) { tbody.innerHTML = '<tr><td colspan="3" style="color:var(--tm)">' + escapeHtml(data.msg || '读取失败') + '</td></tr>'; return; }
  var lines = data.data || [];
  if (!lines.length) { tbody.innerHTML = '<tr><td colspan="3" style="color:var(--tm)">暂无记录</td></tr>'; return; }
  tbody.innerHTML = lines.map(function(line) {
    var type = escapeHtml(line.type || 'ssh-rsa');
    var comment = escapeHtml(line.comment || line.fingerprint || line.key_preview || '');
    return '<tr>' +
      '<td style="font-size:12px">' + type + '</td>' +
      '<td style="font-family:var(--mono);font-size:12px;max-width:320px;overflow:hidden;text-overflow:ellipsis" title="' + comment + '">' + comment + '</td>' +
      '<td style="white-space:nowrap">' +
        '<button type="button" class="btn btn-sm btn-danger" onclick="removeRemoteAuthorizedKey(\'' + escapeHtml(line.line_hash || '') + '\')">删除</button>' +
      '</td>' +
    '</tr>';
  }).join('');
}
async function importRemoteAuthorizedKey() {
  var hostId = document.getElementById('remote-console-host-id').value;
  if (!hostId) return;
  var keySelect = document.getElementById('remote-console-ak-key');
  var keyId = keySelect ? keySelect.value : '';
  if (!keyId) { showToast('请先选择要导入的密钥', 'warning'); return; }
  var userName = (document.getElementById('remote-console-ak-user') || {}).value || 'root';
  var data = await hostRunRequest('host-ssh', '导入 authorized_keys', '正在将密钥导入到 ' + userName + ' 的 authorized_keys …', function() {
    return postHostApi('authorized_keys_add', { host_id: hostId, user: userName, key_id: keyId });
  });
  showToast(data.msg || (data.ok ? '导入成功' : '导入失败'), data.ok ? 'success' : 'error');
  if (data.ok) loadRemoteAuthorizedKeys();
}
async function removeRemoteAuthorizedKey(lineHash) {
  if (!lineHash || !confirm('确认删除该 authorized_keys 条目？')) return;
  var hostId = document.getElementById('remote-console-host-id').value;
  if (!hostId) return;
  var userName = (document.getElementById('remote-console-ak-user') || {}).value || 'root';
  var data = await hostRunRequest('host-ssh', '删除 authorized_keys', '正在删除条目…', function() {
    return postHostApi('authorized_keys_remove', { host_id: hostId, user: userName, line_hash: lineHash });
  });
  showToast(data.msg || (data.ok ? '删除成功' : '删除失败'), data.ok ? 'success' : 'error');
  if (data.ok) loadRemoteAuthorizedKeys();
}
function escapeHtml(str) {
  if (str == null) return '';
  return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

/* ── 主机列表筛选 ── */
function filterRemoteHosts() {
  var keyword = ((document.getElementById('remote-host-search') || {}).value || '').toLowerCase().trim();
  var groupName = ((document.getElementById('remote-host-group-filter') || {}).value || '').trim();
  var favoriteOnly = !!((document.getElementById('remote-host-favorite-only') || {}).checked);
  document.querySelectorAll('.remote-host-row').forEach(function(row) {
    var search = (row.getAttribute('data-host-search') || '').toLowerCase();
    var matchesKeyword = !keyword || search.indexOf(keyword) !== -1;
    var matchesGroup = !groupName || row.getAttribute('data-group-name') === groupName;
    var matchesFavorite = !favoriteOnly || row.getAttribute('data-favorite') === '1';
    row.style.display = matchesKeyword && matchesGroup && matchesFavorite ? '' : 'none';
  });
}

/* ── 远程主机测试 ── */
async function testRemoteHost(hostId) {
  const data = await hostRunRequest('host-ssh', '测试远程连接', '正在连接远程主机并验证认证信息…', function() {
    return postHostApi('remote_test', { host_id: hostId });
  });
  showToast(data.msg || (data.ok ? '测试成功' : '测试失败'), data.ok ? 'success' : 'error');
}

/* ── 审计日志筛选 ── */
function filterAuditRows() {
  var actionValue = (document.getElementById('audit-action-filter') || {}).value || '';
  var hostValue = (document.getElementById('audit-host-filter') || {}).value || '';
  var keywordValue = ((document.getElementById('audit-keyword-filter') || {}).value || '').toLowerCase().trim();
  document.querySelectorAll('.audit-row').forEach(function(row) {
    var matchesAction = !actionValue || row.getAttribute('data-action') === actionValue;
    var matchesHost = !hostValue || row.getAttribute('data-host-id') === hostValue;
    var haystack = (row.getAttribute('data-search') || '').toLowerCase();
    var matchesKeyword = !keywordValue || haystack.indexOf(keywordValue) !== -1;
    row.style.display = matchesAction && matchesHost && matchesKeyword ? '' : 'none';
  });
}

function exportAuditLogs() {
  var rows = Array.from(document.querySelectorAll('.audit-row')).filter(function(r) { return r.style.display !== 'none'; });
  var lines = rows.map(function(r) {
    return Array.from(r.querySelectorAll('td')).map(function(td) { return td.textContent.trim(); }).join('\t');
  });
  var blob = new Blob([lines.join('\n')], { type: 'text/tab-separated-values;charset=utf-8' });
  var link = document.createElement('a');
  link.href = URL.createObjectURL(blob);
  link.download = 'ssh_audit_logs_' + new Date().toISOString().slice(0,10) + '.tsv';
  document.body.appendChild(link); link.click();
  setTimeout(function() { URL.revokeObjectURL(link.href); link.remove(); }, 1000);
}

/* ── 初始化 ── */
filterRemoteHosts();
filterAuditRows();
</script>

<?php require_once __DIR__ . '/shared/footer.php'; ?>
