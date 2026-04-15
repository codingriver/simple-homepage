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
        header('Location: hosts.php#local');
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
        header('Location: hosts.php#local');
        exit;
    }

    if ($action === 'validate_ssh_config' && $canConfigManagePost) {
        $result = host_agent_ssh_validate_config((string)($_POST['ssh_config'] ?? ''));
        flash_set($result['ok'] ? 'success' : 'error', trim((string)($result['msg'] ?? '')) !== '' ? (string)$result['msg'] : ($result['ok'] ? 'SSH 配置校验通过' : 'SSH 配置校验失败'));
        header('Location: hosts.php#local');
        exit;
    }

    if ($action === 'restore_ssh_backup' && $canConfigManagePost) {
        $result = host_agent_ssh_restore_last_backup();
        flash_set($result['ok'] ? 'success' : 'error', (string)($result['msg'] ?? '恢复失败'));
        header('Location: hosts.php#local');
        exit;
    }

    if ($action === 'ssh_service_action' && $canServiceManagePost) {
        $result = host_agent_ssh_service_action((string)($_POST['service_action'] ?? ''));
        flash_set($result['ok'] ? 'success' : 'error', trim((string)($result['msg'] ?? '')) !== '' ? (string)$result['msg'] : ($result['ok'] ? 'SSH 服务操作已执行' : 'SSH 服务操作失败'));
        header('Location: hosts.php#local');
        exit;
    }

    if ($action === 'ssh_toggle_enable' && $canServiceManagePost) {
        $result = host_agent_ssh_toggle_enable((string)($_POST['enabled'] ?? '1') === '1');
        flash_set($result['ok'] ? 'success' : 'error', (string)($result['msg'] ?? 'SSH 自启操作失败'));
        header('Location: hosts.php#local');
        exit;
    }

    if ($action === 'ssh_install_service' && $canServiceManagePost) {
        $result = host_agent_ssh_install_service();
        flash_set($result['ok'] ? 'success' : 'error', (string)($result['msg'] ?? 'SSH 安装失败'));
        header('Location: hosts.php#local');
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
        header('Location: hosts.php#remote');
        exit;
    }

    if ($action === 'delete_remote_host' && auth_user_has_permission('ssh.manage', $current_user)) {
        $ok = ssh_manager_delete_host(trim((string)($_POST['host_id'] ?? '')));
        flash_set($ok ? 'success' : 'error', $ok ? '远程主机已删除' : '远程主机不存在');
        header('Location: hosts.php#remote');
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
        header('Location: hosts.php#keys');
        exit;
    }

    if ($action === 'delete_ssh_key' && auth_user_has_permission('ssh.keys', $current_user)) {
        $ok = ssh_manager_delete_key(trim((string)($_POST['key_id'] ?? '')));
        flash_set($ok ? 'success' : 'error', $ok ? 'SSH 密钥已删除' : 'SSH 密钥不存在');
        header('Location: hosts.php#keys');
        exit;
    }
}

$page_title = '主机管理';
require_once __DIR__ . '/shared/header.php';
require_once __DIR__ . '/shared/host_agent_lib.php';
require_once __DIR__ . '/shared/ssh_manager_lib.php';

$canManage = auth_user_has_permission('ssh.manage', $current_admin);
$canConfigManage = $canManage || auth_user_has_permission('ssh.config.manage', $current_admin);
$canServiceManage = $canManage || auth_user_has_permission('ssh.service.manage', $current_admin);
$canKeys = auth_user_has_permission('ssh.keys', $current_admin);
$canFiles = auth_user_has_permission('ssh.files', $current_admin);
$canFileWrite = $canFiles && ($canManage || auth_user_has_permission('ssh.files.write', $current_admin));
$canTerminal = auth_user_has_permission('ssh.terminal', $current_admin);
$canAudit = auth_user_has_permission('ssh.audit', $current_admin);
$canAuditExport = $canAudit && ($canManage || auth_user_has_permission('ssh.audit.export', $current_admin));
$canBatch = $canManage || auth_user_has_permission('ssh.batch', $current_admin);

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
$remoteManageHostId = trim((string)($_GET['ssh_host_id'] ?? ''));
if ($remoteManageHostId === '' && !empty($remoteHosts[0]['id'])) {
    $remoteManageHostId = (string)$remoteHosts[0]['id'];
}
$selectedHostId = trim((string)($_GET['host_id'] ?? 'local'));
$editHostId = trim((string)($_GET['edit_host'] ?? ''));
$editKeyId = trim((string)($_GET['edit_key'] ?? ''));
$editHost = $editHostId !== '' ? ssh_manager_find_host($editHostId) : null;
$editKey = $editKeyId !== '' ? ssh_manager_find_key($editKeyId, true) : null;
$csrfValue = csrf_token();
$globalCfg = auth_get_config();
$terminalPersistDefault = ($globalCfg['ssh_terminal_persist'] ?? '1') === '1';
$terminalIdleMinutesDefault = max(5, min(10080, (int)($globalCfg['ssh_terminal_idle_minutes'] ?? 120)));

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

<div class="card" style="margin-bottom:16px">
  <div class="card-title">主机管理</div>
  <div style="color:var(--tm);font-size:12px;line-height:1.9">
    SSH 模块已集中到这里管理：本机 SSH 服务、远程主机清单、密钥、文件管理、Web 终端、审计日志都从同一个入口处理。
    当前用户角色：<strong><?= htmlspecialchars(auth_role_labels()[$current_admin['role'] ?? 'user'] ?? ($current_admin['role'] ?? 'user')) ?></strong>。
  </div>
</div>

<div class="card" style="margin-bottom:16px">
  <div class="card-title">Host-Agent 状态</div>
  <div class="alert <?= !empty($agent['healthy']) ? 'alert-success' : (!empty($agent['docker_socket_mounted']) ? 'alert-info' : 'alert-warn') ?>">
    <?= htmlspecialchars((string)($agent['message'] ?? '')) ?>
  </div>
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;margin-top:12px">
    <div style="border:1px solid var(--bd);border-radius:10px;padding:14px;background:var(--bg)">
      <div style="font-size:11px;color:var(--tm);margin-bottom:6px">安装模式</div>
      <div style="font-weight:700"><?= htmlspecialchars((string)($agent['install_mode'] ?? '-')) ?></div>
    </div>
    <div style="border:1px solid var(--bd);border-radius:10px;padding:14px;background:var(--bg)">
      <div style="font-size:11px;color:var(--tm);margin-bottom:6px">容器名</div>
      <div style="font-weight:700;font-family:var(--mono)"><?= htmlspecialchars((string)($agent['container_name'] ?? '-')) ?></div>
    </div>
    <div style="border:1px solid var(--bd);border-radius:10px;padding:14px;background:var(--bg)">
      <div style="font-size:11px;color:var(--tm);margin-bottom:6px">服务地址</div>
      <div style="font-weight:700;font-family:var(--mono);word-break:break-all"><?= htmlspecialchars((string)($agent['service_url'] ?? '-')) ?></div>
    </div>
  </div>
  <?php if (empty($agent['healthy'])): ?>
    <div class="form-hint" style="margin-top:12px">
      当前还不能使用 SSH 模块能力。请先前往 <a href="settings.php#host-agent">系统设置 / Host-Agent</a> 完成安装和健康检查。
    </div>
  <?php endif; ?>
</div>

<?php if (!empty($agent['healthy'])): ?>
<div class="card" id="local" style="margin-bottom:16px">
  <div class="card-title">本机 SSH 服务</div>
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
    </div>
    <?php endif; ?>
  </form>
</div>

<div class="card" style="margin-bottom:16px">
  <div class="card-title">sshd_config 文本配置</div>
  <div class="form-hint" style="margin-bottom:10px">
    保存前会执行配置校验；最近一次备份可一键回滚。
  </div>
  <form method="POST">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_ssh_config">
    <textarea name="ssh_config" spellcheck="false" style="width:100%;min-height:360px;background:var(--bg);border:1px solid var(--bd);border-radius:10px;padding:14px;color:var(--tx);font-family:var(--mono);font-size:12px;line-height:1.7" <?= $canConfigManage ? '' : 'readonly' ?>><?= htmlspecialchars((string)($sshConfigData['content'] ?? '')) ?></textarea>
    <?php if ($canConfigManage): ?>
    <div class="form-actions">
      <button type="submit" class="btn btn-primary">保存 SSH 配置</button>
      <button type="submit" name="action" value="validate_ssh_config" class="btn btn-secondary">先校验配置</button>
      <button type="submit" name="action" value="restore_ssh_backup" class="btn btn-secondary" onclick="return confirm('确认恢复最近一次 SSH 配置备份？')">恢复最近一次备份</button>
    </div>
    <?php endif; ?>
  </form>
</div>

<div class="card" id="remote" style="margin-bottom:16px">
  <div class="card-title">远程主机 SSH 管理</div>
  <?php if ($canManage): ?>
  <form method="POST" style="margin-bottom:16px">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_remote_host">
    <input type="hidden" name="host_id" value="<?= htmlspecialchars((string)($editHost['id'] ?? '')) ?>">
    <div class="form-grid">
      <div class="form-group"><label>主机名称</label><input type="text" name="host_name" value="<?= htmlspecialchars((string)($editHost['name'] ?? '')) ?>" required></div>
      <div class="form-group"><label>主机地址</label><input type="text" name="hostname" value="<?= htmlspecialchars((string)($editHost['hostname'] ?? '')) ?>" required></div>
      <div class="form-group"><label>端口</label><input type="number" name="port" min="1" max="65535" value="<?= htmlspecialchars((string)($editHost['port'] ?? '22')) ?>"></div>
      <div class="form-group"><label>用户名</label><input type="text" name="username" value="<?= htmlspecialchars((string)($editHost['username'] ?? 'root')) ?>" required></div>
      <div class="form-group"><label>认证方式</label>
        <select name="auth_type" id="host-auth-type">
          <option value="key" <?= (($editHost['auth_type'] ?? 'key') === 'key') ? 'selected' : '' ?>>密钥</option>
          <option value="password" <?= (($editHost['auth_type'] ?? '') === 'password') ? 'selected' : '' ?>>密码</option>
        </select>
      </div>
      <div class="form-group" id="host-key-wrap"><label>SSH 密钥</label>
        <select name="key_id">
          <option value="">请选择密钥</option>
          <?php foreach ($sshKeys as $key): ?>
          <option value="<?= htmlspecialchars((string)$key['id']) ?>" <?= (($editHost['key_id'] ?? '') === ($key['id'] ?? '')) ? 'selected' : '' ?>><?= htmlspecialchars((string)($key['name'] ?? '')) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group" id="host-password-wrap"><label>密码</label><input type="password" name="password" value=""></div>
      <div class="form-group"><label>主机分组</label><input type="text" name="group_name" list="host-group-list" value="<?= htmlspecialchars((string)($editHost['group_name'] ?? '')) ?>"></div>
      <div class="form-group"><label>标签</label><input type="text" name="tags" value="<?= htmlspecialchars(implode(',', (array)($editHost['tags'] ?? []))) ?>" placeholder="多个标签用逗号分隔"></div>
      <div class="form-group"><label>收藏</label><select name="favorite"><option value="0" <?= empty($editHost['favorite']) ? 'selected' : '' ?>>否</option><option value="1" <?= !empty($editHost['favorite']) ? 'selected' : '' ?>>是</option></select></div>
      <div class="form-group full"><label>备注</label><input type="text" name="notes" value="<?= htmlspecialchars((string)($editHost['notes'] ?? '')) ?>"></div>
    </div>
    <div class="form-actions">
      <button type="submit" class="btn btn-primary">保存远程主机</button>
      <?php if ($editHost): ?><a href="hosts.php#remote" class="btn btn-secondary">取消编辑</a><?php endif; ?>
    </div>
  </form>
  <?php endif; ?>
  <datalist id="host-group-list">
    <?php foreach ($hostGroups as $groupName): ?>
    <option value="<?= htmlspecialchars($groupName) ?>"></option>
    <?php endforeach; ?>
  </datalist>
  <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:12px">
    <input type="text" id="remote-host-search" placeholder="搜索主机名 / 地址 / 标签 / 分组" style="min-width:220px;flex:1">
    <select id="remote-host-group-filter" style="min-width:160px">
      <option value="">全部分组</option>
      <?php foreach ($hostGroups as $groupName): ?>
      <option value="<?= htmlspecialchars($groupName) ?>"><?= htmlspecialchars($groupName) ?></option>
      <?php endforeach; ?>
    </select>
    <label style="display:flex;align-items:center;gap:6px;font-size:13px"><input type="checkbox" id="remote-host-favorite-only"> 仅看收藏</label>
  </div>
  <?php if ($canBatch): ?>
  <div class="card" style="padding:14px;margin-bottom:12px;background:var(--bg)">
    <div class="card-title" style="margin-bottom:10px">批量操作</div>
    <div class="form-hint" style="margin-bottom:10px">先勾选主机，再执行批量测试、批量命令或批量分发公钥。</div>
    <div id="host-batch-status" style="display:none;margin-bottom:10px;padding:12px 14px;border:1px solid var(--bd);border-radius:10px;background:var(--sf)"></div>
    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
      <button type="button" class="btn btn-secondary" onclick="toggleAllRemoteHosts(true)">全选</button>
      <button type="button" class="btn btn-secondary" onclick="toggleAllRemoteHosts(false)">清空</button>
      <button type="button" class="btn btn-secondary" onclick="batchTestHosts()">批量测试连接</button>
      <select id="batch-key-select" style="min-width:180px">
        <option value="">选择公钥分发</option>
        <?php foreach ($sshKeys as $key): ?>
        <?php if (!empty($key['public_key'])): ?>
        <option value="<?= htmlspecialchars((string)($key['id'] ?? '')) ?>"><?= htmlspecialchars((string)($key['name'] ?? '')) ?></option>
        <?php endif; ?>
        <?php endforeach; ?>
      </select>
      <input type="text" id="batch-key-user" value="root" style="width:110px">
      <button type="button" class="btn btn-secondary" onclick="batchDistributeKey()">批量分发公钥</button>
    </div>
    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-top:10px">
      <input type="text" id="batch-command-input" placeholder="批量执行命令，例如 uptime" style="flex:1;min-width:280px;font-family:var(--mono)">
      <button type="button" class="btn btn-primary" onclick="batchExecHosts()">批量执行命令</button>
    </div>
    <pre id="batch-results" style="display:none;margin-top:10px;min-height:120px;background:#0b1220;border:1px solid rgba(255,255,255,.08);border-radius:10px;padding:12px;color:#d8f5d0;overflow:auto;font-family:var(--mono);font-size:12px;line-height:1.6"></pre>
  </div>
  <?php endif; ?>
  <div class="table-wrap"><table>
    <thead><tr><th>主机</th><th>地址</th><th>认证</th><th>备注</th><th>操作</th></tr></thead>
    <tbody>
      <?php foreach ($remoteHosts as $host): ?>
      <tr class="remote-host-row" data-host-name="<?= htmlspecialchars((string)($host['name'] ?? '')) ?>" data-host-search="<?= htmlspecialchars(strtolower(((string)($host['name'] ?? '')) . ' ' . ((string)($host['hostname'] ?? '')) . ' ' . ((string)($host['group_name'] ?? '')) . ' ' . implode(' ', (array)($host['tags'] ?? [])))) ?>" data-group-name="<?= htmlspecialchars((string)($host['group_name'] ?? '')) ?>" data-favorite="<?= !empty($host['favorite']) ? '1' : '0' ?>">
        <td>
          <?php if ($canBatch): ?><label style="display:flex;align-items:center;gap:8px"><input type="checkbox" class="remote-host-check" value="<?= htmlspecialchars((string)($host['id'] ?? '')) ?>"><span></span></label><?php endif; ?>
          <strong><?= htmlspecialchars((string)($host['name'] ?? '')) ?></strong>
          <?php if (!empty($host['favorite'])): ?><span class="badge badge-green" style="margin-left:6px">收藏</span><?php endif; ?>
          <?php if (!empty($host['group_name'])): ?><div class="form-hint">分组：<?= htmlspecialchars((string)$host['group_name']) ?></div><?php endif; ?>
          <?php if (!empty($host['tags'])): ?><div class="form-hint">标签：<?= htmlspecialchars(implode(', ', (array)$host['tags'])) ?></div><?php endif; ?>
        </td>
        <td style="font-family:var(--mono)"><?= htmlspecialchars((string)($host['username'] ?? 'root')) ?>@<?= htmlspecialchars((string)($host['hostname'] ?? '')) ?>:<?= (int)($host['port'] ?? 22) ?></td>
        <td><?= htmlspecialchars((string)($host['auth_type'] ?? 'key')) ?></td>
        <td><?= htmlspecialchars((string)($host['notes'] ?? '')) ?></td>
        <td style="white-space:nowrap">
          <?php if ($canManage): ?><a href="hosts.php?edit_host=<?= urlencode((string)($host['id'] ?? '')) ?>#remote" class="btn btn-sm btn-secondary">编辑</a><?php endif; ?>
          <?php if ($canManage || $canBatch): ?><button type="button" class="btn btn-sm btn-secondary" onclick="testRemoteHost('<?= htmlspecialchars((string)($host['id'] ?? '')) ?>')">测试连接</button><?php endif; ?>
          <?php if ($canConfigManage || $canServiceManage): ?><button type="button" class="btn btn-sm btn-secondary" onclick="selectSshManageHost('<?= htmlspecialchars((string)($host['id'] ?? '')) ?>')">SSH 管理</button><?php endif; ?>
          <button type="button" class="btn btn-sm btn-secondary" onclick="selectTargetHost('<?= htmlspecialchars((string)($host['id'] ?? '')) ?>')">用于文件/终端</button>
          <?php if ($canManage): ?>
          <form method="POST" style="display:inline" onsubmit="return confirm('确认删除远程主机？')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete_remote_host">
            <input type="hidden" name="host_id" value="<?= htmlspecialchars((string)($host['id'] ?? '')) ?>">
            <button type="submit" class="btn btn-sm btn-danger">删除</button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if (!$remoteHosts): ?>
      <tr><td colspan="5" style="color:var(--tm)">暂无远程主机。</td></tr>
      <?php endif; ?>
    </tbody>
  </table></div>
</div>

<div class="card" id="remote-console" style="margin-bottom:16px">
  <div class="card-title">远程 SSH 控制台</div>
  <?php if (!$canConfigManage && !$canServiceManage): ?>
    <div class="form-hint">当前角色没有远程 SSH 管理权限。</div>
  <?php elseif (!$remoteHosts): ?>
    <div class="form-hint">请先新增远程主机，然后再管理远程 SSH 服务、配置、自启和安装。</div>
  <?php else: ?>
    <div class="form-hint" style="margin-bottom:12px">
      远程 SSH 管理默认要求远程用户具备 root 或免密 sudo 权限；否则可以做连接测试，但配置保存、服务控制、安装和开机启动管理会失败。
    </div>
    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:12px">
      <select id="remote-manage-host-select" style="min-width:240px">
        <?php foreach ($remoteHosts as $host): ?>
        <option value="<?= htmlspecialchars((string)$host['id']) ?>" <?= $remoteManageHostId === (string)$host['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string)($host['name'] ?? '')) ?></option>
        <?php endforeach; ?>
      </select>
      <button type="button" class="btn btn-secondary" onclick="loadRemoteSshPanel()">刷新状态</button>
    </div>
    <div id="host-ssh-status" style="display:none;margin-bottom:12px;padding:12px 14px;border:1px solid var(--bd);border-radius:10px;background:var(--bg)"></div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-bottom:12px">
      <div style="border:1px solid var(--bd);border-radius:10px;padding:14px;background:var(--bg)">
        <div style="font-size:11px;color:var(--tm);margin-bottom:6px">已安装</div>
        <div id="remote-ssh-installed">-</div>
      </div>
      <div style="border:1px solid var(--bd);border-radius:10px;padding:14px;background:var(--bg)">
        <div style="font-size:11px;color:var(--tm);margin-bottom:6px">运行中</div>
        <div id="remote-ssh-running">-</div>
      </div>
      <div style="border:1px solid var(--bd);border-radius:10px;padding:14px;background:var(--bg)">
        <div style="font-size:11px;color:var(--tm);margin-bottom:6px">开机启用</div>
        <div id="remote-ssh-enabled">-</div>
      </div>
      <div style="border:1px solid var(--bd);border-radius:10px;padding:14px;background:var(--bg)">
        <div style="font-size:11px;color:var(--tm);margin-bottom:6px">服务管理器</div>
        <div id="remote-ssh-service-manager" style="font-weight:700">-</div>
      </div>
    </div>
    <div class="form-hint" style="margin-bottom:12px">
      配置文件：<code id="remote-ssh-config-path">-</code>
      <span id="remote-ssh-updated-at"></span>
    </div>
    <?php if ($canServiceManage): ?>
    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:14px">
      <?php foreach (['start' => '启动', 'stop' => '停止', 'reload' => '重载', 'restart' => '重启'] as $value => $label): ?>
      <button type="button" class="btn btn-secondary" onclick="runRemoteSshServiceAction('<?= htmlspecialchars($value) ?>')"><?= htmlspecialchars($label) ?></button>
      <?php endforeach; ?>
      <button type="button" class="btn btn-secondary" onclick="toggleRemoteSshEnable(true)">启用开机启动</button>
      <button type="button" class="btn btn-secondary" onclick="toggleRemoteSshEnable(false)">禁用开机启动</button>
      <button type="button" class="btn btn-primary" onclick="installRemoteSshService()">安装 SSH 服务</button>
    </div>
    <?php endif; ?>
    <?php if ($canConfigManage): ?>
    <div class="card" style="padding:14px;margin-bottom:12px;background:var(--bg)">
      <div class="card-title" style="margin-bottom:10px">远程结构化 SSH 配置</div>
      <div class="form-grid">
        <div class="form-group">
          <label>端口</label>
          <input type="number" id="remote-ssh-port" min="1" max="65535" value="22">
        </div>
        <div class="form-group">
          <label>ListenAddress</label>
          <input type="text" id="remote-listen-address" placeholder="留空表示默认">
        </div>
        <div class="form-group">
          <label>PermitRootLogin</label>
          <select id="remote-permit-root-login">
            <?php foreach (['yes','no','prohibit-password','forced-commands-only'] as $value): ?>
            <option value="<?= htmlspecialchars($value) ?>"><?= htmlspecialchars($value) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label>密码登录</label>
          <select id="remote-password-auth">
            <option value="1">开启</option>
            <option value="0">关闭</option>
          </select>
        </div>
        <div class="form-group">
          <label>公钥登录</label>
          <select id="remote-pubkey-auth">
            <option value="1">开启</option>
            <option value="0">关闭</option>
          </select>
        </div>
        <div class="form-group">
          <label>AllowUsers</label>
          <input type="text" id="remote-allow-users" placeholder="多个用户用空格分隔">
        </div>
        <div class="form-group">
          <label>AllowGroups</label>
          <input type="text" id="remote-allow-groups" placeholder="多个分组用空格分隔">
        </div>
        <div class="form-group">
          <label>X11Forwarding</label>
          <select id="remote-x11-forwarding">
            <option value="1">开启</option>
            <option value="0">关闭</option>
          </select>
        </div>
        <div class="form-group">
          <label>MaxAuthTries</label>
          <input type="number" id="remote-max-auth-tries" min="1" max="20" value="6">
        </div>
        <div class="form-group">
          <label>ClientAliveInterval</label>
          <input type="number" id="remote-client-alive-interval" min="0" max="3600" value="0">
        </div>
        <div class="form-group">
          <label>ClientAliveCountMax</label>
          <input type="number" id="remote-client-alive-count-max" min="0" max="100" value="3">
        </div>
      </div>
      <div class="form-actions">
        <button type="button" class="btn btn-primary" onclick="saveRemoteStructuredConfig()">保存结构化配置</button>
      </div>
    </div>
    <div class="card" style="padding:14px;background:var(--bg)">
      <div class="card-title" style="margin-bottom:10px">远程 sshd_config 文本配置</div>
      <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:10px">
        <label style="display:flex;align-items:center;gap:6px;font-size:13px"><input type="checkbox" id="remote-restart-after-save"> 保存后自动重启 SSH</label>
        <label style="display:flex;align-items:center;gap:6px;font-size:13px"><input type="checkbox" id="remote-rollback-on-failure" checked> 重启失败时自动回滚</label>
      </div>
      <div id="remote-ssh-warning-box" class="form-hint" style="margin-bottom:10px;color:#b45309"></div>
      <textarea id="remote-ssh-config" spellcheck="false" style="width:100%;min-height:320px;background:var(--bg);border:1px solid var(--bd);border-radius:10px;padding:14px;color:var(--tx);font-family:var(--mono);font-size:12px;line-height:1.7"></textarea>
      <div class="form-actions">
        <button type="button" class="btn btn-primary" onclick="applyRemoteRawConfig()">应用 SSH 配置</button>
        <button type="button" class="btn btn-secondary" onclick="validateRemoteRawConfig()">先校验配置</button>
        <button type="button" class="btn btn-secondary" onclick="restoreRemoteBackup()">恢复最近一次备份</button>
      </div>
      <pre id="remote-ssh-diff" style="margin-top:10px;min-height:120px;background:#0b1220;border:1px solid rgba(255,255,255,.08);border-radius:10px;padding:12px;color:#d8f5d0;overflow:auto;font-family:var(--mono);font-size:12px;line-height:1.6"></pre>
    </div>
    <?php else: ?>
    <div class="form-hint">当前角色没有 SSH 配置编辑权限，但仍可查看远程 SSH 服务状态。</div>
    <?php endif; ?>
  <?php endif; ?>
</div>

<div class="card" id="keys" style="margin-bottom:16px">
  <div class="card-title">SSH 密钥管理</div>
  <?php if ($canKeys): ?>
  <form method="POST" style="margin-bottom:16px">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_ssh_key">
    <input type="hidden" name="key_id" value="<?= htmlspecialchars((string)($editKey['id'] ?? '')) ?>">
    <div class="form-grid">
      <div class="form-group"><label>密钥名称</label><input type="text" name="key_name" value="<?= htmlspecialchars((string)($editKey['name'] ?? '')) ?>" required></div>
      <div class="form-group"><label>默认用户名</label><input type="text" name="key_username" value="<?= htmlspecialchars((string)($editKey['username'] ?? '')) ?>"></div>
      <div class="form-group full"><label>私钥内容<?= $editKey ? '（留空则保留原有私钥）' : '' ?></label><textarea name="private_key" spellcheck="false" style="min-height:180px;background:var(--bg);border:1px solid var(--bd);border-radius:10px;padding:12px;color:var(--tx);font-family:var(--mono)"><?= htmlspecialchars('') ?></textarea></div>
      <div class="form-group full"><label>私钥口令（可选）</label><input type="password" name="passphrase" value=""></div>
    </div>
    <div class="form-actions">
      <button type="submit" class="btn btn-primary">保存 SSH 密钥</button>
      <?php if ($editKey): ?><a href="hosts.php#keys" class="btn btn-secondary">取消编辑</a><?php endif; ?>
    </div>
  </form>
  <?php endif; ?>
  <div class="table-wrap"><table>
    <thead><tr><th>名称</th><th>指纹</th><th>默认用户</th><th>操作</th></tr></thead>
    <tbody>
      <?php foreach ($sshKeys as $key): ?>
      <tr>
        <td><strong><?= htmlspecialchars((string)($key['name'] ?? '')) ?></strong></td>
        <td style="font-family:var(--mono);font-size:12px"><?= htmlspecialchars((string)($key['fingerprint'] ?? '-')) ?></td>
        <td><?= htmlspecialchars((string)($key['username'] ?? '-')) ?></td>
        <td style="white-space:nowrap">
          <?php if ($canKeys): ?><a href="hosts.php?edit_key=<?= urlencode((string)($key['id'] ?? '')) ?>#keys" class="btn btn-sm btn-secondary">编辑</a><?php endif; ?>
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

<div class="card" id="files" style="margin-bottom:16px">
  <div class="card-title">文件管理</div>
  <?php if (!$canFiles): ?>
    <div class="form-hint">当前角色没有文件管理权限。</div>
  <?php else: ?>
    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:12px">
      <select id="file-host-select" style="min-width:220px">
        <option value="local" <?= $selectedHostId === 'local' ? 'selected' : '' ?>>本机</option>
        <?php foreach ($remoteHosts as $host): ?>
        <option value="<?= htmlspecialchars((string)$host['id']) ?>" <?= $selectedHostId === (string)$host['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string)($host['name'] ?? '')) ?></option>
        <?php endforeach; ?>
      </select>
      <input type="text" id="file-path" value="/" style="min-width:320px;font-family:var(--mono)">
      <button type="button" class="btn btn-secondary" onclick="loadFiles()">刷新目录</button>
      <?php if ($canFileWrite): ?><button type="button" class="btn btn-secondary" onclick="makeDir()">新建目录</button><?php endif; ?>
      <input type="file" id="file-upload-input" style="display:none" onchange="uploadSelectedFile()">
      <?php if ($canFileWrite): ?><button type="button" class="btn btn-secondary" onclick="document.getElementById('file-upload-input').click()">上传文件</button><?php endif; ?>
      <?php if ($canFileWrite): ?>
      <button type="button" class="btn btn-secondary" onclick="showFileOpsPrompt('chmod')">chmod</button>
      <button type="button" class="btn btn-secondary" onclick="showFileOpsPrompt('chown')">chown</button>
      <button type="button" class="btn btn-secondary" onclick="showFileOpsPrompt('chgrp')">chgrp</button>
      <button type="button" class="btn btn-secondary" onclick="archiveCurrentPath()">压缩</button>
      <button type="button" class="btn btn-secondary" onclick="extractCurrentFile()">解压</button>
      <?php endif; ?>
    </div>
    <div id="host-files-status" style="display:none;margin-bottom:12px;padding:12px 14px;border:1px solid var(--bd);border-radius:10px;background:var(--bg)"></div>
    <div class="table-wrap" style="margin-bottom:12px">
      <table id="file-table"><thead><tr><th>名称</th><th>类型</th><th>大小</th><th>修改时间</th><th>操作</th></tr></thead><tbody></tbody></table>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
      <div>
        <div class="form-hint" style="margin-bottom:8px">当前编辑文件路径</div>
        <input type="text" id="file-edit-path" value="" style="width:100%;font-family:var(--mono);margin-bottom:8px">
        <div id="file-editor-meta" class="form-hint" style="margin-bottom:8px">支持文本编辑，也支持二进制上传和下载。</div>
        <div id="file-stat-meta" class="form-hint" style="margin-bottom:8px"></div>
        <textarea id="file-editor" spellcheck="false" style="width:100%;min-height:260px;background:var(--bg);border:1px solid var(--bd);border-radius:10px;padding:12px;color:var(--tx);font-family:var(--mono)"></textarea>
        <div class="form-actions">
          <?php if ($canFileWrite): ?><button type="button" class="btn btn-primary" onclick="saveFile()">保存文件</button><?php endif; ?>
          <button type="button" class="btn btn-secondary" onclick="readCurrentFile()">重新读取</button>
          <button type="button" class="btn btn-secondary" onclick="downloadCurrentFile()">下载当前文件</button>
        </div>
      </div>
      <div>
        <div class="form-hint" style="margin-bottom:8px">authorized_keys 管理（默认 root）</div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:8px">
          <input type="text" id="authorized-user" value="root" style="flex:1;min-width:140px">
          <select id="authorized-key-select" style="flex:1;min-width:180px">
            <option value="">从已保存 SSH 密钥导入公钥</option>
            <?php foreach ($sshKeys as $key): ?>
            <?php if (!empty($key['public_key'])): ?>
            <option value="<?= htmlspecialchars((string)($key['id'] ?? '')) ?>"><?= htmlspecialchars((string)($key['name'] ?? '')) ?></option>
            <?php endif; ?>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="table-wrap" style="margin-bottom:8px">
          <table id="authorized-table">
            <thead><tr><th>类型</th><th>备注</th><th>选项</th><th>操作</th></tr></thead>
            <tbody></tbody>
          </table>
        </div>
        <textarea id="authorized-editor" spellcheck="false" style="width:100%;min-height:260px;background:var(--bg);border:1px solid var(--bd);border-radius:10px;padding:12px;color:var(--tx);font-family:var(--mono)"></textarea>
        <div class="form-actions">
          <button type="button" class="btn btn-secondary" onclick="loadAuthorizedKeys()">读取 authorized_keys</button>
          <button type="button" class="btn btn-secondary" onclick="appendSelectedAuthorizedKey()">导入已保存公钥</button>
          <?php if ($canFileWrite): ?><button type="button" class="btn btn-primary" onclick="saveAuthorizedKeys()">保存 authorized_keys</button><?php endif; ?>
        </div>
        <div class="form-hint" style="margin:10px 0 8px">known_hosts 管理（默认 root）</div>
        <textarea id="known-hosts-editor" spellcheck="false" style="width:100%;min-height:160px;background:var(--bg);border:1px solid var(--bd);border-radius:10px;padding:12px;color:var(--tx);font-family:var(--mono)"></textarea>
        <div class="form-actions">
          <button type="button" class="btn btn-secondary" onclick="loadKnownHosts()">读取 known_hosts</button>
          <?php if ($canFileWrite): ?><button type="button" class="btn btn-primary" onclick="saveKnownHosts()">保存 known_hosts</button><?php endif; ?>
        </div>
      </div>
    </div>
  <?php endif; ?>
</div>

<div class="card" id="terminal" style="margin-bottom:16px">
  <div class="card-title">Web 终端</div>
  <?php if (!$canTerminal): ?>
    <div class="form-hint">当前角色没有终端权限。</div>
  <?php else: ?>
    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:12px">
      <select id="terminal-host-select" style="min-width:220px">
        <option value="local">本机</option>
        <?php foreach ($remoteHosts as $host): ?>
        <option value="<?= htmlspecialchars((string)$host['id']) ?>"><?= htmlspecialchars((string)($host['name'] ?? '')) ?></option>
        <?php endforeach; ?>
      </select>
      <label style="display:flex;align-items:center;gap:6px;font-size:13px;color:var(--tx2)">
        <input type="checkbox" id="terminal-persist" <?= $terminalPersistDefault ? 'checked' : '' ?>>
        后台继续运行
      </label>
      <input type="number" id="terminal-idle-minutes" min="5" max="10080" value="<?= $terminalIdleMinutesDefault ?>" style="width:120px" title="空闲保留分钟">
      <button type="button" class="btn btn-primary" onclick="openTerminal()">打开终端</button>
      <button type="button" class="btn btn-secondary" onclick="refreshTerminalSessions(true)">恢复会话</button>
      <button type="button" class="btn btn-secondary" onclick="detachTerminal()">脱离终端</button>
      <button type="button" class="btn btn-secondary" onclick="closeTerminal()">关闭终端</button>
      <button type="button" class="btn btn-secondary" onclick="syncCurrentTerminalSize()">同步尺寸</button>
    </div>
    <div id="host-terminal-status" style="display:none;margin-bottom:12px;padding:12px 14px;border:1px solid var(--bd);border-radius:10px;background:var(--bg)"></div>
    <div class="form-hint" style="margin-bottom:10px">终端通过独立 shell 会话运行。开启“后台继续运行”后，浏览器关闭或切换页面不会中断会话，可稍后回来恢复。</div>
    <div id="terminal-tabs" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px"></div>
    <pre id="terminal-output" style="min-height:320px;background:#0b1220;border:1px solid rgba(255,255,255,.08);border-radius:10px;padding:14px;color:#d8f5d0;overflow:auto;font-family:var(--mono);font-size:12px;line-height:1.6"></pre>
    <div style="display:flex;gap:10px;margin-top:10px">
      <input type="text" id="terminal-input" style="flex:1;font-family:var(--mono)" placeholder="输入命令后按回车发送">
      <button type="button" class="btn btn-secondary" onclick="sendTerminalCommand()">发送</button>
      <button type="button" class="btn btn-secondary" onclick="sendTerminalRaw('\u0003')">Ctrl+C</button>
      <button type="button" class="btn btn-secondary" onclick="sendTerminalRaw('\t')">Tab</button>
      <button type="button" class="btn btn-secondary" onclick="sendTerminalRaw('\u001b[A')">↑</button>
      <button type="button" class="btn btn-secondary" onclick="sendTerminalRaw('\u001b[B')">↓</button>
    </div>
  <?php endif; ?>
</div>

<div class="card" id="audit">
  <div class="card-title">SSH 审计日志</div>
  <?php if (!$canAudit): ?>
    <div class="form-hint">当前角色没有查看审计日志权限。</div>
  <?php else: ?>
  <div style="display:flex;justify-content:flex-end;gap:8px;flex-wrap:wrap;margin-bottom:12px">
    <a href="ssh_audit.php" id="ssh-audit-link" class="btn btn-secondary">打开独立审计页</a>
  </div>
  <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:12px">
    <select id="audit-action-filter" style="min-width:180px">
      <option value="">全部动作</option>
      <?php foreach (array_values(array_unique(array_map(static fn(array $log): string => (string)($log['action'] ?? ''), $auditLogs))) as $actionName): ?>
      <?php if ($actionName !== ''): ?>
      <option value="<?= htmlspecialchars($actionName) ?>"><?= htmlspecialchars($actionName) ?></option>
      <?php endif; ?>
      <?php endforeach; ?>
    </select>
    <select id="audit-host-filter" style="min-width:180px">
      <option value="">全部主机</option>
      <option value="local">本机</option>
      <?php foreach ($remoteHosts as $host): ?>
      <option value="<?= htmlspecialchars((string)($host['id'] ?? '')) ?>"><?= htmlspecialchars((string)($host['name'] ?? '')) ?></option>
      <?php endforeach; ?>
    </select>
    <input type="text" id="audit-keyword-filter" placeholder="搜索动作或上下文" style="min-width:220px;flex:1">
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
  <?php endif; ?>
</div>
<?php endif; ?>

<script>
var HOST_CSRF = <?= json_encode($csrfValue) ?>;
var TERMINAL_SESSION_ID = '';
var TERMINAL_SESSIONS = {};
var TERMINAL_PAGE_ACTIVE = true;
var FILE_CURRENT_BASE64 = '';
var FILE_CURRENT_IS_BINARY = false;
var REMOTE_SSH_ORIGINAL_CONFIG = '';
var HOST_CAN_FILE_WRITE = <?= $canFileWrite ? 'true' : 'false' ?>;
var HOST_CAN_CONFIG_MANAGE = <?= $canConfigManage ? 'true' : 'false' ?>;
var TERMINAL_PERSIST_DEFAULT = <?= $terminalPersistDefault ? 'true' : 'false' ?>;
var TERMINAL_IDLE_MINUTES_DEFAULT = <?= (int)$terminalIdleMinutesDefault ?>;
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
var TERMINAL_TABS_SIGNATURE = '';

function hostProgressRefs(scope) {
  return {
    wrap: document.getElementById(scope + '-status')
  };
}

function hostSetProgress(scope, title, detail, percent, tone) {
  HOST_STATUS.set(scope, title, detail, percent, tone);
}

function hostHideProgress(scope) {
  HOST_STATUS.hide(scope);
}

function hostStartProgress(scope, title, detail) {
  return HOST_STATUS.start(scope, title, detail);
}

function hostFinishProgress(id, ok, detail) {
  HOST_STATUS.finish(id, ok, detail);
}

async function hostRunRequest(scope, title, detail, runner) {
  return HOST_STATUS.run(scope, title, detail, runner);
}

function buildHostApiBody(action, params) {
  var body = new URLSearchParams();
  body.append('action', action);
  body.append('_csrf', HOST_CSRF);
  Object.keys(params || {}).forEach(function(key) {
    var value = params[key];
    if (value === undefined || value === null) return;
    if (Array.isArray(value)) {
      value.forEach(function(item) {
        body.append(key + '[]', item);
      });
      return;
    }
    body.append(key, value);
  });
  return body;
}

function syncRemoteAuthType() {
  var type = document.getElementById('host-auth-type');
  if (!type) return;
  var usePassword = type.value === 'password';
  var keyWrap = document.getElementById('host-key-wrap');
  var pwdWrap = document.getElementById('host-password-wrap');
  if (keyWrap) keyWrap.style.display = usePassword ? 'none' : '';
  if (pwdWrap) pwdWrap.style.display = usePassword ? '' : 'none';
}

function escapeHtml(value) {
  return String(value || '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

function setBooleanBadge(elementId, value) {
  var el = document.getElementById(elementId);
  if (!el) return;
  var cls = 'badge-gray';
  var text = '未知';
  if (value === true) {
    cls = 'badge-green';
    text = '是';
  } else if (value === false) {
    cls = 'badge-red';
    text = '否';
  }
  el.innerHTML = '<span class="badge ' + cls + '">' + text + '</span>';
}

function arrayBufferToBase64(buffer) {
  var bytes = new Uint8Array(buffer);
  var chunkSize = 0x8000;
  var binary = '';
  for (var i = 0; i < bytes.length; i += chunkSize) {
    var chunk = bytes.subarray(i, i + chunkSize);
    binary += String.fromCharCode.apply(null, chunk);
  }
  return btoa(binary);
}

function base64ToBlob(base64) {
  var binary = atob(base64 || '');
  var bytes = new Uint8Array(binary.length);
  for (var i = 0; i < binary.length; i += 1) {
    bytes[i] = binary.charCodeAt(i);
  }
  return new Blob([bytes]);
}

async function postHostApi(action, params) {
  var body = buildHostApiBody(action, params || {});
  var res = await fetch('host_api.php', {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' },
    body: body
  });
  return res.json();
}

async function getHostApi(action, params) {
  var query = new URLSearchParams(Object.assign({ action: action }, params || {}));
  var res = await fetch('host_api.php?' + query.toString(), {
    credentials: 'same-origin',
    headers: { 'X-Requested-With': 'XMLHttpRequest' }
  });
  return res.json();
}

function knownHostsPath() {
  const user = (document.getElementById('authorized-user').value || 'root').trim() || 'root';
  return user === 'root' ? '/root/.ssh/known_hosts' : '/home/' + user + '/.ssh/known_hosts';
}

function getSelectedRemoteHostIds() {
  return Array.from(document.querySelectorAll('.remote-host-check:checked')).map(function(input) {
    return input.value;
  });
}

function renderBatchResults(results, title) {
  var pre = document.getElementById('batch-results');
  if (!pre) return;
  var lines = [title || '批量结果'];
  (results || []).forEach(function(item) {
    lines.push('[' + (item.host_id || '-') + '] ' + (item.ok ? 'OK' : 'FAIL') + ' ' + (item.msg || ''));
    if (item.stdout) lines.push(item.stdout.trim());
    if (item.stderr) lines.push(item.stderr.trim());
  });
  pre.style.display = '';
  pre.textContent = lines.join('\n');
}

function computeDiffLines(oldContent, newContent) {
  var oldLines = String(oldContent || '').trim().split(/\r?\n/);
  var newLines = String(newContent || '').trim().split(/\r?\n/);
  var max = Math.max(oldLines.length, newLines.length);
  var lines = [];
  for (var i = 0; i < max; i += 1) {
    var oldLine = oldLines[i] || '';
    var newLine = newLines[i] || '';
    if (oldLine === newLine) continue;
    if (oldLine) lines.push('- ' + oldLine);
    if (newLine) lines.push('+ ' + newLine);
    if (lines.length >= 200) break;
  }
  return lines;
}

function updateRemoteDiffPreview() {
  var current = (document.getElementById('remote-ssh-config') || {}).value || '';
  var diffEl = document.getElementById('remote-ssh-diff');
  if (!diffEl) return;
  var lines = computeDiffLines(REMOTE_SSH_ORIGINAL_CONFIG, current);
  diffEl.textContent = lines.length ? lines.join('\n') : '当前没有配置差异。';
}

function selectTargetHost(hostId) {
  var file = document.getElementById('file-host-select');
  var term = document.getElementById('terminal-host-select');
  var ssh = document.getElementById('remote-manage-host-select');
  if (file) file.value = hostId;
  if (term) term.value = hostId;
  if (ssh && hostId !== 'local') ssh.value = hostId;
  if (document.getElementById('file-table')) loadFiles();
  showToast('已切换到目标主机，可直接用于文件管理和终端', 'info');
}

function selectSshManageHost(hostId) {
  var ssh = document.getElementById('remote-manage-host-select');
  if (!ssh) return;
  ssh.value = hostId;
  loadRemoteSshPanel();
  location.hash = 'remote-console';
}

async function testRemoteHost(hostId) {
  const data = await hostRunRequest('host-ssh', '测试远程连接', '正在连接远程主机并验证认证信息…', function() {
    return postHostApi('remote_test', { host_id: hostId });
  });
  showToast(data.msg || (data.ok ? '测试成功' : '测试失败'), data.ok ? 'success' : 'error');
}

async function loadFiles() {
  const hostId = document.getElementById('file-host-select').value;
  const path = document.getElementById('file-path').value || '/';
  const data = await hostRunRequest('host-files', '读取目录', '正在加载目标主机目录内容…', function() {
    return getHostApi('file_list', { host_id: hostId, path: path });
  });
  const tbody = document.querySelector('#file-table tbody');
  tbody.innerHTML = '';
  if (!data.ok) {
    showToast(data.msg || '目录读取失败', 'error');
    return;
  }
  document.getElementById('file-path').value = data.cwd || path;
  (data.items || []).forEach(function(item) {
    const tr = document.createElement('tr');
    var primaryAction = item.type === 'dir'
      ? '<button type="button" class="btn btn-sm btn-secondary" data-open="' + escapeHtml(item.path) + '">进入</button>'
      : '<button type="button" class="btn btn-sm btn-secondary" data-read="' + escapeHtml(item.path) + '">编辑</button>';
    var deleteAction = HOST_CAN_FILE_WRITE
      ? ' <button type="button" class="btn btn-sm btn-danger" data-delete="' + escapeHtml(item.path) + '">删除</button>'
      : '';
    tr.innerHTML = '<td>' + escapeHtml(item.name) + '</td>'
      + '<td>' + escapeHtml(item.type) + '</td>'
      + '<td>' + (item.size || 0) + '</td>'
      + '<td>' + escapeHtml(item.mtime || '') + '</td>'
      + '<td style="white-space:nowrap">'
      + primaryAction
      + deleteAction
      + '</td>';
    tbody.appendChild(tr);
  });
  tbody.querySelectorAll('[data-open]').forEach(function(btn) {
    btn.addEventListener('click', function() {
      document.getElementById('file-path').value = this.getAttribute('data-open');
      loadFiles();
    });
  });
  tbody.querySelectorAll('[data-read]').forEach(function(btn) {
    btn.addEventListener('click', function() {
      document.getElementById('file-edit-path').value = this.getAttribute('data-read');
      readCurrentFile();
    });
  });
  tbody.querySelectorAll('[data-delete]').forEach(function(btn) {
    btn.addEventListener('click', function() {
      deleteFile(this.getAttribute('data-delete'));
    });
  });
}

async function readCurrentFile() {
  const hostId = document.getElementById('file-host-select').value;
  const path = document.getElementById('file-edit-path').value;
  if (!path) return;
  const data = await hostRunRequest('host-files', '读取文件', '正在获取文件内容…', function() {
    return postHostApi('file_read', { host_id: hostId, path: path });
  });
  if (!data.ok) {
    showToast(data.msg || '文件读取失败', 'error');
    return;
  }
  FILE_CURRENT_BASE64 = data.content_base64 || '';
  FILE_CURRENT_IS_BINARY = !!data.is_binary;
  document.getElementById('file-editor').value = data.is_binary ? '' : (data.content || '');
  document.getElementById('file-editor-meta').textContent = data.is_binary
    ? '当前文件为二进制，已禁止直接文本编辑；可下载查看或重新上传覆盖。'
    : '文本文件已读取，可直接编辑并保存。';
  await loadCurrentFileStat();
}

async function saveFile() {
  if (!HOST_CAN_FILE_WRITE) {
    showToast('当前角色没有文件写入权限', 'warning');
    return;
  }
  const hostId = document.getElementById('file-host-select').value;
  const path = document.getElementById('file-edit-path').value;
  if (!path) {
    showToast('请先填写文件路径', 'warning');
    return;
  }
  const content = document.getElementById('file-editor').value;
  const data = await hostRunRequest('host-files', '保存文件', '正在写入目标主机文件…', function() {
    return postHostApi('file_write', { host_id: hostId, path: path, content: content });
  });
  showToast(data.msg || (data.ok ? '保存成功' : '保存失败'), data.ok ? 'success' : 'error');
  if (data.ok) {
    FILE_CURRENT_IS_BINARY = false;
    FILE_CURRENT_BASE64 = btoa(unescape(encodeURIComponent(content)));
    document.getElementById('file-editor-meta').textContent = '文本文件已保存。';
    await loadCurrentFileStat();
    loadFiles();
  }
}

async function deleteFile(path) {
  if (!HOST_CAN_FILE_WRITE) {
    showToast('当前角色没有文件写入权限', 'warning');
    return;
  }
  if (!confirm('确认删除 ' + path + ' ?')) return;
  const hostId = document.getElementById('file-host-select').value;
  const data = await hostRunRequest('host-files', '删除文件', '正在删除 ' + path + ' …', function() {
    return postHostApi('file_delete', { host_id: hostId, path: path });
  });
  showToast(data.msg || (data.ok ? '删除成功' : '删除失败'), data.ok ? 'success' : 'error');
  if (data.ok) loadFiles();
}

async function makeDir() {
  if (!HOST_CAN_FILE_WRITE) {
    showToast('当前角色没有文件写入权限', 'warning');
    return;
  }
  const name = prompt('请输入目录路径', (document.getElementById('file-path').value || '/').replace(/\/$/, '') + '/new-dir');
  if (!name) return;
  const hostId = document.getElementById('file-host-select').value;
  const data = await hostRunRequest('host-files', '创建目录', '正在创建目录 ' + name + ' …', function() {
    return postHostApi('file_mkdir', { host_id: hostId, path: name });
  });
  showToast(data.msg || (data.ok ? '目录已创建' : '目录创建失败'), data.ok ? 'success' : 'error');
  if (data.ok) loadFiles();
}

async function uploadSelectedFile() {
  if (!HOST_CAN_FILE_WRITE) {
    showToast('当前角色没有文件写入权限', 'warning');
    return;
  }
  const input = document.getElementById('file-upload-input');
  if (!input.files || !input.files.length) return;
  const file = input.files[0];
  const reader = new FileReader();
  reader.onload = async function(event) {
    const basePath = document.getElementById('file-path').value || '/';
    const path = (basePath.replace(/\/$/, '') || '') + '/' + file.name;
    const base64 = arrayBufferToBase64(event.target.result);
    document.getElementById('file-edit-path').value = path;
    document.getElementById('file-editor').value = '';
    document.getElementById('file-editor-meta').textContent = '已上传二进制或原始文件内容；如需修改文本，请重新读取后编辑。';
    const data = await hostRunRequest('host-files', '上传文件', '正在上传 ' + file.name + ' …', function() {
      return postHostApi('file_write', { host_id: document.getElementById('file-host-select').value, path: path, content_base64: base64 });
    });
    showToast(data.msg || (data.ok ? '上传成功' : '上传失败'), data.ok ? 'success' : 'error');
    if (data.ok) {
      FILE_CURRENT_BASE64 = base64;
      FILE_CURRENT_IS_BINARY = true;
      loadFiles();
    }
    input.value = '';
  };
  reader.readAsArrayBuffer(file);
}

async function downloadCurrentFile() {
  const path = document.getElementById('file-edit-path').value;
  if (!path) {
    showToast('请先选择文件', 'warning');
    return;
  }
  const data = await hostRunRequest('host-files', '下载文件', '正在读取文件以生成下载…', function() {
    return postHostApi('file_read', { host_id: document.getElementById('file-host-select').value, path: path });
  });
  if (!data.ok) {
    showToast(data.msg || '文件下载失败', 'error');
    return;
  }
  const blob = base64ToBlob(data.content_base64 || '');
  const link = document.createElement('a');
  link.href = URL.createObjectURL(blob);
  link.download = path.split('/').pop() || 'download.txt';
  document.body.appendChild(link);
  link.click();
  setTimeout(function() {
    URL.revokeObjectURL(link.href);
    link.remove();
  }, 1000);
}

async function loadCurrentFileStat() {
  var path = document.getElementById('file-edit-path').value;
  if (!path) {
    document.getElementById('file-stat-meta').textContent = '';
    return;
  }
  var data = await hostRunRequest('host-files', '读取文件属性', '正在读取文件权限和属主信息…', function() {
    return getHostApi('file_stat', {
      host_id: document.getElementById('file-host-select').value,
      path: path
    });
  });
  if (!data.ok) {
    document.getElementById('file-stat-meta').textContent = data.msg || '';
    return;
  }
  document.getElementById('file-stat-meta').textContent =
    '权限 ' + (data.mode || '-') + ' · 属主 ' + (data.owner || '-') + ' · 属组 ' + (data.group || '-') + ' · ' + ((data.is_dir ? '目录' : '文件') || '');
}

async function showFileOpsPrompt(action) {
  if (!HOST_CAN_FILE_WRITE) {
    showToast('当前角色没有文件写入权限', 'warning');
    return;
  }
  var path = document.getElementById('file-edit-path').value || document.getElementById('file-path').value;
  if (!path) {
    showToast('请先选择文件或目录', 'warning');
    return;
  }
  var labelMap = { chmod: '权限模式，如 755', chown: '属主，如 root', chgrp: '属组，如 users' };
  var fieldMap = { chmod: 'mode', chown: 'owner', chgrp: 'group' };
  var value = prompt('请输入' + labelMap[action], '');
  if (!value) return;
  var payload = { host_id: document.getElementById('file-host-select').value, path: path };
  payload[fieldMap[action]] = value;
  var data = await hostRunRequest('host-files', '执行文件操作', '正在执行 ' + action + ' …', function() {
    return postHostApi('file_' + action, payload);
  });
  showToast(data.msg || (data.ok ? '操作成功' : '操作失败'), data.ok ? 'success' : 'error');
  if (data.ok) {
    await loadCurrentFileStat();
    loadFiles();
  }
}

async function archiveCurrentPath() {
  if (!HOST_CAN_FILE_WRITE) {
    showToast('当前角色没有文件写入权限', 'warning');
    return;
  }
  var path = document.getElementById('file-edit-path').value || document.getElementById('file-path').value;
  if (!path) {
    showToast('请先选择文件或目录', 'warning');
    return;
  }
  var archivePath = prompt('请输入压缩包完整路径', path.replace(/\/$/, '') + '.tar.gz');
  if (!archivePath) return;
  var data = await hostRunRequest('host-files', '压缩文件', '正在创建压缩包…', function() {
    return postHostApi('file_archive', {
      host_id: document.getElementById('file-host-select').value,
      path: path,
      archive_path: archivePath
    });
  });
  showToast(data.msg || (data.ok ? '压缩成功' : '压缩失败'), data.ok ? 'success' : 'error');
  if (data.ok) loadFiles();
}

async function extractCurrentFile() {
  if (!HOST_CAN_FILE_WRITE) {
    showToast('当前角色没有文件写入权限', 'warning');
    return;
  }
  var path = document.getElementById('file-edit-path').value;
  if (!path) {
    showToast('请先选择压缩文件', 'warning');
    return;
  }
  var destination = prompt('请输入解压目录', document.getElementById('file-path').value || '/');
  if (!destination) return;
  var data = await hostRunRequest('host-files', '解压文件', '正在解压到目标目录…', function() {
    return postHostApi('file_extract', {
      host_id: document.getElementById('file-host-select').value,
      path: path,
      destination: destination
    });
  });
  showToast(data.msg || (data.ok ? '解压成功' : '解压失败'), data.ok ? 'success' : 'error');
  if (data.ok) loadFiles();
}

function authorizedPath() {
  const user = (document.getElementById('authorized-user').value || 'root').trim() || 'root';
  return user === 'root' ? '/root/.ssh/authorized_keys' : '/home/' + user + '/.ssh/authorized_keys';
}

function renderAuthorizedKeysTable(entries) {
  const tbody = document.querySelector('#authorized-table tbody');
  tbody.innerHTML = '';
  (entries || []).forEach(function(entry) {
    const tr = document.createElement('tr');
    tr.innerHTML = '<td>' + escapeHtml(entry.type || (entry.valid ? '-' : '原始文本')) + '</td>'
      + '<td>' + escapeHtml(entry.comment || '-') + '</td>'
      + '<td style="font-family:var(--mono);font-size:12px">' + escapeHtml(entry.options || '-') + '</td>'
      + '<td><button type="button" class="btn btn-sm btn-danger" data-remove="' + escapeHtml(entry.line_hash || '') + '">删除</button></td>';
    tbody.appendChild(tr);
  });
  if (!(entries || []).length) {
    const tr = document.createElement('tr');
    tr.innerHTML = '<td colspan="4" style="color:var(--tm)">当前没有 authorized_keys 条目。</td>';
    tbody.appendChild(tr);
  }
  tbody.querySelectorAll('[data-remove]').forEach(function(button) {
    button.addEventListener('click', function() {
      removeAuthorizedKey(this.getAttribute('data-remove'));
    });
  });
}

async function loadAuthorizedKeys() {
  const data = await hostRunRequest('host-files', '读取 authorized_keys', '正在读取公钥授权列表…', function() {
    return getHostApi('authorized_keys_list', {
      host_id: document.getElementById('file-host-select').value,
      user: document.getElementById('authorized-user').value || 'root'
    });
  });
  if (!data.ok) {
    showToast(data.msg || 'authorized_keys 读取失败', 'error');
    return;
  }
  document.getElementById('authorized-editor').value = data.content || '';
  renderAuthorizedKeysTable(data.entries || []);
  showToast('authorized_keys 已读取', 'success');
}

async function appendSelectedAuthorizedKey() {
  const keyId = document.getElementById('authorized-key-select').value;
  if (!keyId) {
    showToast('请先选择一把已保存 SSH 密钥', 'warning');
    return;
  }
  const data = await hostRunRequest('host-files', '导入公钥', '正在把已保存公钥追加到 authorized_keys …', function() {
    return postHostApi('authorized_keys_add', {
      host_id: document.getElementById('file-host-select').value,
      user: document.getElementById('authorized-user').value || 'root',
      key_id: keyId
    });
  });
  showToast(data.msg || (data.ok ? 'authorized_keys 已更新' : 'authorized_keys 更新失败'), data.ok ? 'success' : 'error');
  if (data.ok) {
    document.getElementById('authorized-editor').value = data.content || '';
    renderAuthorizedKeysTable(data.entries || []);
  }
}

async function removeAuthorizedKey(lineHash) {
  if (!lineHash || !confirm('确认删除这个 authorized_keys 条目？')) return;
  const data = await hostRunRequest('host-files', '删除公钥条目', '正在更新 authorized_keys …', function() {
    return postHostApi('authorized_keys_remove', {
      host_id: document.getElementById('file-host-select').value,
      user: document.getElementById('authorized-user').value || 'root',
      line_hash: lineHash
    });
  });
  showToast(data.msg || (data.ok ? 'authorized_keys 条目已删除' : '删除失败'), data.ok ? 'success' : 'error');
  if (data.ok) {
    document.getElementById('authorized-editor').value = data.content || '';
    renderAuthorizedKeysTable(data.entries || []);
  }
}

async function saveAuthorizedKeys() {
  if (!HOST_CAN_FILE_WRITE) {
    showToast('当前角色没有文件写入权限', 'warning');
    return;
  }
  document.getElementById('file-edit-path').value = authorizedPath();
  document.getElementById('file-editor').value = document.getElementById('authorized-editor').value;
  await saveFile();
  await loadAuthorizedKeys();
}

async function loadKnownHosts() {
  const data = await hostRunRequest('host-files', '读取 known_hosts', '正在读取主机指纹缓存…', function() {
    return postHostApi('file_read', {
      host_id: document.getElementById('file-host-select').value,
      path: knownHostsPath()
    });
  });
  if (!data.ok) {
    document.getElementById('known-hosts-editor').value = '';
    showToast(data.msg || 'known_hosts 读取失败', 'warning');
    return;
  }
  document.getElementById('known-hosts-editor').value = data.content || '';
  showToast('known_hosts 已读取', 'success');
}

async function saveKnownHosts() {
  if (!HOST_CAN_FILE_WRITE) {
    showToast('当前角色没有文件写入权限', 'warning');
    return;
  }
  const data = await hostRunRequest('host-files', '保存 known_hosts', '正在写入主机指纹缓存…', function() {
    return postHostApi('file_write', {
      host_id: document.getElementById('file-host-select').value,
      path: knownHostsPath(),
      content: document.getElementById('known-hosts-editor').value
    });
  });
  showToast(data.msg || (data.ok ? 'known_hosts 已保存' : 'known_hosts 保存失败'), data.ok ? 'success' : 'error');
}

async function loadRemoteSshPanel() {
  var selector = document.getElementById('remote-manage-host-select');
  if (!selector || !selector.value) return;
  var hostId = selector.value;
  var requests = [getHostApi('ssh_target_status', { host_id: hostId })];
  if (HOST_CAN_CONFIG_MANAGE) {
    requests.push(getHostApi('ssh_target_config_read', { host_id: hostId }));
  }
  var remoteData = await hostRunRequest('host-ssh', '获取远程 SSH 状态', '正在读取远程服务状态和配置…', function() {
    return Promise.all(requests).then(function(items) {
      return { ok: true, items: items };
    });
  });
  var results = remoteData.items || [];
  var status = results[0];
  var config = results[1] || { ok: true, content: '', structured: {}, warnings: [] };
  if (!status.ok) {
    showToast(status.msg || '远程 SSH 状态读取失败', 'error');
    return;
  }
  setBooleanBadge('remote-ssh-installed', !!status.installed);
  setBooleanBadge('remote-ssh-running', typeof status.running === 'boolean' ? status.running : null);
  setBooleanBadge('remote-ssh-enabled', typeof status.enabled === 'boolean' ? status.enabled : null);
  document.getElementById('remote-ssh-service-manager').textContent = status.service_manager || '-';
  document.getElementById('remote-ssh-config-path').textContent = status.config_path || '-';
  document.getElementById('remote-ssh-updated-at').textContent = status.updated_at ? ('，最近刷新：' + status.updated_at) : '';
  if (config.ok && HOST_CAN_CONFIG_MANAGE && document.getElementById('remote-ssh-config')) {
    document.getElementById('remote-ssh-config').value = config.content || '';
    REMOTE_SSH_ORIGINAL_CONFIG = config.content || '';
    document.getElementById('remote-listen-address').value = (config.structured && config.structured.listenaddress) || '';
    document.getElementById('remote-ssh-port').value = (config.structured && config.structured.port) || '22';
    document.getElementById('remote-permit-root-login').value = (config.structured && config.structured.permitrootlogin) || 'prohibit-password';
    document.getElementById('remote-password-auth').value = (config.structured && config.structured.passwordauthentication) === 'no' ? '0' : '1';
    document.getElementById('remote-pubkey-auth').value = (config.structured && config.structured.pubkeyauthentication) === 'no' ? '0' : '1';
    document.getElementById('remote-allow-users').value = (config.structured && config.structured.allowusers) || '';
    document.getElementById('remote-allow-groups').value = (config.structured && config.structured.allowgroups) || '';
    document.getElementById('remote-x11-forwarding').value = (config.structured && config.structured.x11forwarding) === 'yes' ? '1' : '0';
    document.getElementById('remote-max-auth-tries').value = (config.structured && config.structured.maxauthtries) || '6';
    document.getElementById('remote-client-alive-interval').value = (config.structured && config.structured.clientaliveinterval) || '0';
    document.getElementById('remote-client-alive-count-max').value = (config.structured && config.structured.clientalivecountmax) || '3';
    document.getElementById('remote-ssh-warning-box').textContent = (config.warnings || []).join('；');
    updateRemoteDiffPreview();
  } else if (!config.ok && HOST_CAN_CONFIG_MANAGE) {
    showToast(config.msg || '远程 SSH 配置读取失败', 'error');
  }
}

async function runRemoteSshServiceAction(action) {
  var selector = document.getElementById('remote-manage-host-select');
  if (!selector || !selector.value) return;
  var data = await hostRunRequest('host-ssh', '执行远程 SSH 操作', '正在对远程 SSH 服务执行 ' + action + ' …', function() {
    return postHostApi('ssh_target_service_action', { host_id: selector.value, service_action: action });
  });
  showToast(data.msg || (data.ok ? '执行成功' : '执行失败'), data.ok ? 'success' : 'error');
  if (data.ok) loadRemoteSshPanel();
}

async function toggleRemoteSshEnable(enabled) {
  var selector = document.getElementById('remote-manage-host-select');
  if (!selector || !selector.value) return;
  var data = await hostRunRequest('host-ssh', '更新 SSH 开机启动', '正在修改远程 SSH 自启状态…', function() {
    return postHostApi('ssh_target_toggle_enable', { host_id: selector.value, enabled: enabled ? '1' : '0' });
  });
  showToast(data.msg || (data.ok ? 'SSH 开机启动设置成功' : 'SSH 开机启动设置失败'), data.ok ? 'success' : 'error');
  if (data.ok) loadRemoteSshPanel();
}

async function installRemoteSshService() {
  if (!confirm('确认尝试在远程主机安装 openssh-server？')) return;
  var selector = document.getElementById('remote-manage-host-select');
  if (!selector || !selector.value) return;
  var data = await hostRunRequest('host-ssh', '安装远程 SSH 服务', '正在尝试安装 openssh-server …', function() {
    return postHostApi('ssh_target_install_service', { host_id: selector.value });
  });
  showToast(data.msg || (data.ok ? 'SSH 服务安装完成' : 'SSH 服务安装失败'), data.ok ? 'success' : 'error');
  if (data.ok) loadRemoteSshPanel();
}

async function saveRemoteStructuredConfig() {
  if (!HOST_CAN_CONFIG_MANAGE) {
    showToast('当前角色没有 SSH 配置编辑权限', 'warning');
    return;
  }
  var selector = document.getElementById('remote-manage-host-select');
  if (!selector || !selector.value) return;
  var data = await hostRunRequest('host-ssh', '保存结构化 SSH 配置', '正在写入结构化配置并校验…', function() {
    return postHostApi('ssh_target_structured_save', {
      host_id: selector.value,
      ssh_port: document.getElementById('remote-ssh-port').value,
      listen_address: document.getElementById('remote-listen-address').value,
      permit_root_login: document.getElementById('remote-permit-root-login').value,
      password_auth: document.getElementById('remote-password-auth').value,
      pubkey_auth: document.getElementById('remote-pubkey-auth').value,
      allow_users: document.getElementById('remote-allow-users').value,
      allow_groups: document.getElementById('remote-allow-groups').value,
      x11_forwarding: document.getElementById('remote-x11-forwarding').value,
      max_auth_tries: document.getElementById('remote-max-auth-tries').value,
      client_alive_interval: document.getElementById('remote-client-alive-interval').value,
      client_alive_count_max: document.getElementById('remote-client-alive-count-max').value
    });
  });
  showToast(data.msg || (data.ok ? '远程结构化 SSH 配置已保存' : '保存失败'), data.ok ? 'success' : 'error');
  if (data.ok) {
    await loadRemoteSshPanel();
  }
}

async function applyRemoteRawConfig() {
  if (!HOST_CAN_CONFIG_MANAGE) {
    showToast('当前角色没有 SSH 配置编辑权限', 'warning');
    return;
  }
  var selector = document.getElementById('remote-manage-host-select');
  if (!selector || !selector.value) return;
  var data = await hostRunRequest('host-ssh', '应用远程 SSH 配置', '正在保存 sshd_config 并执行校验…', function() {
    return postHostApi('ssh_target_config_apply', {
      host_id: selector.value,
      content: document.getElementById('remote-ssh-config').value,
      restart_after_save: document.getElementById('remote-restart-after-save').checked ? '1' : '0',
      rollback_on_failure: document.getElementById('remote-rollback-on-failure').checked ? '1' : '0'
    });
  });
  document.getElementById('remote-ssh-diff').textContent = (data.diff_lines || []).join('\n') || '当前没有配置差异。';
  document.getElementById('remote-ssh-warning-box').textContent = (data.warnings || []).join('；');
  showToast(data.msg || (data.ok ? '远程 SSH 配置已保存' : '保存失败'), data.ok ? 'success' : 'error');
  if (data.ok) {
    await loadRemoteSshPanel();
  }
}

async function validateRemoteRawConfig() {
  if (!HOST_CAN_CONFIG_MANAGE) {
    showToast('当前角色没有 SSH 配置编辑权限', 'warning');
    return;
  }
  var selector = document.getElementById('remote-manage-host-select');
  if (!selector || !selector.value) return;
  var data = await hostRunRequest('host-ssh', '校验远程 SSH 配置', '正在执行远程配置校验…', function() {
    return postHostApi('ssh_target_config_validate', {
      host_id: selector.value,
      content: document.getElementById('remote-ssh-config').value
    });
  });
  showToast(data.msg || (data.ok ? '配置校验通过' : '配置校验失败'), data.ok ? 'success' : 'error');
}

async function restoreRemoteBackup() {
  if (!HOST_CAN_CONFIG_MANAGE) {
    showToast('当前角色没有 SSH 配置编辑权限', 'warning');
    return;
  }
  if (!confirm('确认恢复远程最近一次 SSH 配置备份？')) return;
  var selector = document.getElementById('remote-manage-host-select');
  if (!selector || !selector.value) return;
  var data = await hostRunRequest('host-ssh', '恢复 SSH 备份', '正在恢复最近一次配置备份…', function() {
    return postHostApi('ssh_target_restore_backup', { host_id: selector.value });
  });
  showToast(data.msg || (data.ok ? '远程 SSH 配置已恢复' : '恢复失败'), data.ok ? 'success' : 'error');
  if (data.ok) loadRemoteSshPanel();
}

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

function toggleAllRemoteHosts(checked) {
  document.querySelectorAll('.remote-host-check').forEach(function(input) {
    if (input.closest('tr') && input.closest('tr').style.display !== 'none') {
      input.checked = checked;
    }
  });
}

async function batchTestHosts() {
  var ids = getSelectedRemoteHostIds();
  if (!ids.length) {
    showToast('请先勾选远程主机', 'warning');
    return;
  }
  var data = await hostRunRequest('host-batch', '批量测试连接', '正在逐台验证远程主机连接…', function() {
    return postHostApi('batch_test_hosts', { host_ids: ids });
  });
  renderBatchResults(data.results || [], '批量测试连接结果');
  showToast(data.ok ? '批量测试已完成' : (data.msg || '批量测试失败'), data.ok ? 'success' : 'error');
}

async function batchExecHosts() {
  var ids = getSelectedRemoteHostIds();
  var command = (document.getElementById('batch-command-input').value || '').trim();
  if (!ids.length) {
    showToast('请先勾选远程主机', 'warning');
    return;
  }
  if (!command) {
    showToast('请输入要批量执行的命令', 'warning');
    return;
  }
  var data = await hostRunRequest('host-batch', '批量执行命令', '正在向选中主机分发命令…', function() {
    return postHostApi('batch_exec_hosts', { host_ids: ids, command: command });
  });
  renderBatchResults(data.results || [], '批量命令执行结果');
  showToast(data.ok ? '批量命令已执行' : (data.msg || '批量命令执行失败'), data.ok ? 'success' : 'error');
}

async function batchDistributeKey() {
  var ids = getSelectedRemoteHostIds();
  var keyId = (document.getElementById('batch-key-select').value || '').trim();
  if (!ids.length) {
    showToast('请先勾选远程主机', 'warning');
    return;
  }
  if (!keyId) {
    showToast('请先选择要分发的公钥', 'warning');
    return;
  }
  var data = await hostRunRequest('host-batch', '批量分发公钥', '正在把公钥写入远程 authorized_keys …', function() {
    return postHostApi('batch_distribute_key', {
      host_ids: ids,
      key_id: keyId,
      user: document.getElementById('batch-key-user').value || 'root'
    });
  });
  renderBatchResults(data.results || [], '批量分发公钥结果');
  showToast(data.ok ? '批量分发完成' : (data.msg || '批量分发失败'), data.ok ? 'success' : 'error');
}

async function openTerminal() {
  if (!TERMINAL_PAGE_ACTIVE) return;
  const hostId = document.getElementById('terminal-host-select').value;
  const persist = !!((document.getElementById('terminal-persist') || {}).checked);
  const idleInput = document.getElementById('terminal-idle-minutes');
  const idleMinutes = Math.max(5, parseInt(idleInput && idleInput.value ? idleInput.value : String(TERMINAL_IDLE_MINUTES_DEFAULT), 10) || TERMINAL_IDLE_MINUTES_DEFAULT);
  const data = await hostRunRequest('host-terminal', '打开终端', '正在创建新的 shell 会话…', function() {
    return postHostApi('terminal_open', { host_id: hostId, persist: persist ? '1' : '0', idle_minutes: String(idleMinutes) });
  });
  if (!data.ok) {
    showToast(data.msg || '终端打开失败', 'error');
    return;
  }
  var id = data.id || '';
  TERMINAL_SESSIONS[id] = {
    id: id,
    hostId: data.host_id || hostId,
    title: data.title || (hostId === 'local' ? '本机' : (document.querySelector('#terminal-host-select option:checked') || {}).textContent || hostId),
    output: '',
    running: !!data.running,
    persist: !!data.persist,
    idleMinutes: Number(data.idle_minutes || idleMinutes),
    updatedAt: data.updated_at || '',
    createdAt: data.created_at || '',
    ready: false,
    initPromise: null,
    timer: null,
    controller: null,
    readPromise: null,
    writePromise: null
  };
  TERMINAL_SESSION_ID = id;
  renderTerminalTabs();
  document.getElementById('terminal-output').textContent = '';
  TERMINAL_SESSIONS[id].initPromise = (async function() {
    await pollTerminal(id);
    await syncCurrentTerminalSize();
    if (TERMINAL_SESSIONS[id]) {
      TERMINAL_SESSIONS[id].ready = true;
      TERMINAL_SESSIONS[id].initPromise = null;
    }
  })();
  await TERMINAL_SESSIONS[id].initPromise;
  showToast('终端已打开', 'success');
}

function renderTerminalTabs() {
  var container = document.getElementById('terminal-tabs');
  if (!container) return;
  var signature = Object.keys(TERMINAL_SESSIONS).sort().map(function(id) {
    var session = TERMINAL_SESSIONS[id];
    return [id, session.title, session.running ? '1' : '0', session.persist ? '1' : '0', TERMINAL_SESSION_ID === id ? '1' : '0'].join(':');
  }).join('|');
  if (signature === TERMINAL_TABS_SIGNATURE) {
    return;
  }
  TERMINAL_TABS_SIGNATURE = signature;
  container.innerHTML = '';
  Object.keys(TERMINAL_SESSIONS).forEach(function(id) {
    var session = TERMINAL_SESSIONS[id];
    var button = document.createElement('button');
    button.type = 'button';
    button.className = 'btn btn-sm ' + (TERMINAL_SESSION_ID === id ? 'btn-primary' : 'btn-secondary');
    button.textContent = session.title + (session.running ? '' : ' (已结束)') + (session.persist ? ' [后台]' : '');
    button.setAttribute('data-session-id', id);
    button.addEventListener('click', function() {
      TERMINAL_SESSION_ID = id;
      document.getElementById('terminal-output').textContent = session.output || '';
      var persistInput = document.getElementById('terminal-persist');
      var idleInput = document.getElementById('terminal-idle-minutes');
      if (persistInput) persistInput.checked = !!session.persist;
      if (idleInput) idleInput.value = String(session.idleMinutes || TERMINAL_IDLE_MINUTES_DEFAULT);
      TERMINAL_TABS_SIGNATURE = '';
      renderTerminalTabs();
    });
    container.appendChild(button);
  });
}

async function refreshTerminalSessions(showNotice) {
  var data = showNotice
    ? await hostRunRequest('host-terminal', '恢复终端会话', '正在读取可恢复的后台会话…', function() {
        return getHostApi('terminal_list');
      })
    : await getHostApi('terminal_list');
  if (!data.ok) {
    if (showNotice) showToast(data.msg || '终端会话列表读取失败', 'error');
    return;
  }
  var nextSessions = {};
  (data.sessions || []).forEach(function(session) {
    var existing = TERMINAL_SESSIONS[session.id] || {};
    nextSessions[session.id] = {
      id: session.id,
      hostId: session.host_id || 'local',
      title: session.title || session.host_label || session.host_id || session.id,
      output: existing.output || '',
      running: !!session.running,
      persist: !!session.persist,
      idleMinutes: Number(session.idle_minutes || TERMINAL_IDLE_MINUTES_DEFAULT),
      updatedAt: session.updated_at || '',
      createdAt: session.created_at || '',
      ready: existing.ready !== undefined ? !!existing.ready : true,
      initPromise: existing.initPromise || null,
      timer: existing.timer || null,
      controller: existing.controller || null,
      readPromise: existing.readPromise || null,
      writePromise: existing.writePromise || null
    };
  });
  Object.keys(TERMINAL_SESSIONS).forEach(function(id) {
    if (!nextSessions[id] && TERMINAL_SESSIONS[id] && TERMINAL_SESSIONS[id].timer) {
      clearTimeout(TERMINAL_SESSIONS[id].timer);
    }
    if (!nextSessions[id] && TERMINAL_SESSIONS[id] && TERMINAL_SESSIONS[id].controller) {
      TERMINAL_SESSIONS[id].controller.abort();
    }
  });
  TERMINAL_SESSIONS = nextSessions;
  if ((!TERMINAL_SESSION_ID || !TERMINAL_SESSIONS[TERMINAL_SESSION_ID]) && Object.keys(TERMINAL_SESSIONS).length) {
    TERMINAL_SESSION_ID = Object.keys(TERMINAL_SESSIONS)[0];
  }
  if (TERMINAL_SESSION_ID && TERMINAL_SESSIONS[TERMINAL_SESSION_ID]) {
    document.getElementById('terminal-output').textContent = TERMINAL_SESSIONS[TERMINAL_SESSION_ID].output || '';
  } else {
    document.getElementById('terminal-output').textContent = '';
  }
  TERMINAL_TABS_SIGNATURE = '';
  renderTerminalTabs();
  var pollPromises = [];
  Object.keys(TERMINAL_SESSIONS).forEach(function(id) {
    if (TERMINAL_SESSIONS[id].running && !TERMINAL_SESSIONS[id].timer) {
      pollPromises.push(pollTerminal(id));
    }
  });
  if (showNotice && pollPromises.length) {
    await Promise.allSettled(pollPromises);
  }
  if (showNotice) {
    showToast((data.sessions || []).length ? '终端会话已恢复' : '当前没有可恢复的终端会话', 'info');
  }
}

async function pollTerminal(sessionId) {
  if (!TERMINAL_PAGE_ACTIVE || !sessionId || !TERMINAL_SESSIONS[sessionId]) return;
  let data = null;
  var session = TERMINAL_SESSIONS[sessionId];
  if (!session) return;
  if (session.controller) {
    session.controller.abort();
  }
  var controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
  session.controller = controller;
  var readPromise = (async function() {
    const res = await fetch('host_api.php?action=terminal_read&id=' + encodeURIComponent(sessionId), {
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      signal: controller ? controller.signal : undefined
    });
    return res.json();
  })();
  session.readPromise = readPromise;
  try {
    data = await readPromise;
  } catch (err) {
    if (!TERMINAL_PAGE_ACTIVE) return;
    if (err && (err.name === 'AbortError' || /aborted/i.test(String(err.message || '')))) {
      return;
    }
    data = { ok: false, running: false, msg: err && err.message ? err.message : '终端轮询失败' };
  }
  if (!TERMINAL_PAGE_ACTIVE || !TERMINAL_SESSIONS[sessionId]) return;
  if (TERMINAL_SESSIONS[sessionId].readPromise === readPromise) {
    TERMINAL_SESSIONS[sessionId].readPromise = null;
  }
  TERMINAL_SESSIONS[sessionId].controller = null;
  if (data.ok && data.output) {
    TERMINAL_SESSIONS[sessionId].output += data.output;
    if (TERMINAL_SESSION_ID === sessionId) {
      const pre = document.getElementById('terminal-output');
      pre.textContent = TERMINAL_SESSIONS[sessionId].output;
      pre.scrollTop = pre.scrollHeight;
    }
  }
  TERMINAL_SESSIONS[sessionId].running = !!(data.ok && data.running);
  TERMINAL_SESSIONS[sessionId].persist = data.persist !== undefined ? !!data.persist : TERMINAL_SESSIONS[sessionId].persist;
  TERMINAL_SESSIONS[sessionId].idleMinutes = Number(data.idle_minutes || TERMINAL_SESSIONS[sessionId].idleMinutes || TERMINAL_IDLE_MINUTES_DEFAULT);
  TERMINAL_SESSIONS[sessionId].updatedAt = data.updated_at || TERMINAL_SESSIONS[sessionId].updatedAt || '';
  renderTerminalTabs();
  if (data.ok && data.running) {
    if (TERMINAL_SESSIONS[sessionId].timer) {
      clearTimeout(TERMINAL_SESSIONS[sessionId].timer);
    }
    if (TERMINAL_PAGE_ACTIVE) {
      TERMINAL_SESSIONS[sessionId].timer = setTimeout(function() { pollTerminal(sessionId); }, 1000);
    }
  } else {
    if (TERMINAL_SESSIONS[sessionId].timer) {
      clearTimeout(TERMINAL_SESSIONS[sessionId].timer);
      TERMINAL_SESSIONS[sessionId].timer = null;
    }
    if (TERMINAL_SESSION_ID === sessionId) {
      document.getElementById('terminal-output').textContent = TERMINAL_SESSIONS[sessionId].output || '';
    }
  }
}

function teardownTerminalPolling(abortRequests) {
  TERMINAL_PAGE_ACTIVE = false;
  Object.keys(TERMINAL_SESSIONS).forEach(function(id) {
    var session = TERMINAL_SESSIONS[id];
    if (!session) return;
    if (session.timer) {
      clearTimeout(session.timer);
      session.timer = null;
    }
    if (abortRequests && session.controller) {
      session.controller.abort();
      session.controller = null;
    }
  });
}

async function waitForTerminalReads(timeoutMs) {
  var promises = Object.keys(TERMINAL_SESSIONS).map(function(id) {
    return TERMINAL_SESSIONS[id] && TERMINAL_SESSIONS[id].readPromise ? TERMINAL_SESSIONS[id].readPromise : null;
  }).filter(Boolean);
  if (!promises.length) return;
  await Promise.race([
    Promise.allSettled(promises),
    new Promise(function(resolve) {
      setTimeout(resolve, Math.max(100, timeoutMs || 1200));
    })
  ]);
}

async function navigateAwayFromTerminal(url) {
  teardownTerminalPolling(false);
  await waitForTerminalReads(1500);
  window.location.href = url;
}

async function waitForTerminalReady(sessionId, timeoutMs) {
  var deadline = Date.now() + Math.max(1000, timeoutMs || 5000);
  while (Date.now() < deadline) {
    var session = TERMINAL_SESSIONS[sessionId];
    if (!session) return false;
    if (session.ready) return true;
    if (session.initPromise) {
      await Promise.race([
        session.initPromise.catch(function() { return null; }),
        new Promise(function(resolve) { setTimeout(resolve, 250); })
      ]);
      continue;
    }
    if (session.readPromise) {
      await Promise.race([
        session.readPromise.catch(function() { return null; }),
        new Promise(function(resolve) { setTimeout(resolve, 200); })
      ]);
    } else {
      await pollTerminal(sessionId);
    }
    await new Promise(function(resolve) { setTimeout(resolve, 120); });
  }
  return !!(TERMINAL_SESSIONS[sessionId] && TERMINAL_SESSIONS[sessionId].ready);
}

function enqueueTerminalWrite(sessionId, data) {
  var session = TERMINAL_SESSIONS[sessionId];
  if (!session) {
    return Promise.resolve({ ok: false, msg: '终端会话不存在' });
  }
  var previous = session.writePromise || Promise.resolve();
  var next = previous
    .catch(function() { return null; })
    .then(function() {
      if (!TERMINAL_SESSIONS[sessionId]) {
        return { ok: false, msg: '终端会话不存在' };
      }
      return postHostApi('terminal_write', { id: sessionId, data: data });
    });
  session.writePromise = next;
  return next.finally(function() {
    if (TERMINAL_SESSIONS[sessionId] && TERMINAL_SESSIONS[sessionId].writePromise === next) {
      TERMINAL_SESSIONS[sessionId].writePromise = null;
    }
  });
}

async function sendTerminalCommand() {
  if (!TERMINAL_SESSION_ID || !TERMINAL_SESSIONS[TERMINAL_SESSION_ID]) {
    showToast('请先打开终端', 'warning');
    return;
  }
  await waitForTerminalReady(TERMINAL_SESSION_ID, 5000);
  const input = document.getElementById('terminal-input');
  const value = input.value;
  input.value = '';
  await enqueueTerminalWrite(TERMINAL_SESSION_ID, value + '\n');
}

async function sendTerminalRaw(data) {
  if (!TERMINAL_SESSION_ID || !TERMINAL_SESSIONS[TERMINAL_SESSION_ID]) {
    showToast('请先打开终端', 'warning');
    return;
  }
  await enqueueTerminalWrite(TERMINAL_SESSION_ID, data);
}

async function syncCurrentTerminalSize() {
  var pre = document.getElementById('terminal-output');
  if (!pre || !TERMINAL_SESSION_ID || !TERMINAL_SESSIONS[TERMINAL_SESSION_ID]) return;
  var cols = Math.max(80, Math.floor(pre.clientWidth / 8));
  var rows = Math.max(24, Math.floor(pre.clientHeight / 18));
  await sendTerminalRaw('stty cols ' + cols + ' rows ' + rows + '\n');
}

async function closeTerminal() {
  if (!TERMINAL_SESSION_ID) return;
  var id = TERMINAL_SESSION_ID;
  if (TERMINAL_SESSIONS[id] && TERMINAL_SESSIONS[id].timer) {
    clearTimeout(TERMINAL_SESSIONS[id].timer);
  }
  await postHostApi('terminal_close', { id: id });
  delete TERMINAL_SESSIONS[id];
  TERMINAL_SESSION_ID = Object.keys(TERMINAL_SESSIONS)[0] || '';
  document.getElementById('terminal-output').textContent = TERMINAL_SESSION_ID && TERMINAL_SESSIONS[TERMINAL_SESSION_ID] ? TERMINAL_SESSIONS[TERMINAL_SESSION_ID].output : '';
  renderTerminalTabs();
  showToast('终端已关闭', 'info');
}

function detachTerminal() {
  if (!TERMINAL_SESSION_ID || !TERMINAL_SESSIONS[TERMINAL_SESSION_ID]) {
    showToast('当前没有活动终端', 'warning');
    return;
  }
  var id = TERMINAL_SESSION_ID;
  if (TERMINAL_SESSIONS[id].timer) {
    clearTimeout(TERMINAL_SESSIONS[id].timer);
    TERMINAL_SESSIONS[id].timer = null;
  }
  TERMINAL_SESSION_ID = '';
  document.getElementById('terminal-output').textContent = '';
  renderTerminalTabs();
  showToast('已从当前终端脱离，后台会话继续保留', 'info');
}

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

async function exportAuditLogs() {
  var url = 'host_api.php?action=audit_export'
    + '&limit=200'
    + '&action_name=' + encodeURIComponent((document.getElementById('audit-action-filter') || {}).value || '')
    + '&host_id=' + encodeURIComponent((document.getElementById('audit-host-filter') || {}).value || '')
    + '&keyword=' + encodeURIComponent((document.getElementById('audit-keyword-filter') || {}).value || '');
  var res = await fetch(url, {
    credentials: 'same-origin',
    headers: { 'X-Requested-With': 'XMLHttpRequest' }
  });
  if (!res.ok) {
    showToast('审计日志导出失败', 'error');
    return;
  }
  var blob = await res.blob();
  var link = document.createElement('a');
  link.href = URL.createObjectURL(blob);
  link.download = 'ssh-audit-export.json';
  document.body.appendChild(link);
  link.click();
  setTimeout(function() {
    URL.revokeObjectURL(link.href);
    link.remove();
  }, 1000);
  showToast('审计日志已导出', 'success');
}

document.addEventListener('DOMContentLoaded', function() {
  syncRemoteAuthType();
  var authType = document.getElementById('host-auth-type');
  if (authType) authType.addEventListener('change', syncRemoteAuthType);
  var remoteManage = document.getElementById('remote-manage-host-select');
  if (remoteManage) remoteManage.addEventListener('change', loadRemoteSshPanel);
  ['remote-host-search', 'remote-host-group-filter', 'remote-host-favorite-only'].forEach(function(id) {
    var el = document.getElementById(id);
    if (!el) return;
    el.addEventListener('input', filterRemoteHosts);
    el.addEventListener('change', filterRemoteHosts);
  });
  var fileHostSelect = document.getElementById('file-host-select');
  if (fileHostSelect) {
    fileHostSelect.addEventListener('change', function() {
      loadFiles();
      loadAuthorizedKeys();
      loadKnownHosts();
    });
  }
  var authorizedUser = document.getElementById('authorized-user');
  if (authorizedUser) {
    authorizedUser.addEventListener('change', function() {
      loadAuthorizedKeys();
      loadKnownHosts();
    });
  }
  var remoteRawConfig = document.getElementById('remote-ssh-config');
  if (remoteRawConfig) {
    remoteRawConfig.addEventListener('input', updateRemoteDiffPreview);
  }
  var terminalInput = document.getElementById('terminal-input');
  if (terminalInput) {
    terminalInput.addEventListener('keydown', function(e) {
      if (e.key === 'Enter') {
        e.preventDefault();
        sendTerminalCommand();
      }
    });
  }
  if (document.getElementById('file-table')) {
    loadFiles();
    loadAuthorizedKeys();
    loadKnownHosts();
  }
  if (document.querySelector('.remote-host-row')) {
    filterRemoteHosts();
  }
  if (remoteManage && remoteManage.value) {
    loadRemoteSshPanel();
  }
  if (document.getElementById('terminal-persist')) {
    document.getElementById('terminal-persist').checked = TERMINAL_PERSIST_DEFAULT;
  }
  if (document.getElementById('terminal-idle-minutes')) {
    document.getElementById('terminal-idle-minutes').value = String(TERMINAL_IDLE_MINUTES_DEFAULT);
  }
  if (document.getElementById('terminal-tabs')) {
    refreshTerminalSessions(false);
  }
  ['audit-action-filter', 'audit-host-filter', 'audit-keyword-filter'].forEach(function(id) {
    var el = document.getElementById(id);
    if (el) el.addEventListener('input', filterAuditRows);
    if (el) el.addEventListener('change', filterAuditRows);
  });
  if (document.querySelector('.audit-row')) {
    filterAuditRows();
  }
  window.addEventListener('resize', function() {
    if (TERMINAL_SESSION_ID) {
      syncCurrentTerminalSize();
    }
  });
  var sshAuditLink = document.getElementById('ssh-audit-link');
  if (sshAuditLink) {
    sshAuditLink.addEventListener('click', function(event) {
      if (!Object.keys(TERMINAL_SESSIONS).length) return;
      event.preventDefault();
      navigateAwayFromTerminal(sshAuditLink.getAttribute('href') || 'ssh_audit.php');
    });
  }
  window.addEventListener('pagehide', function() { teardownTerminalPolling(false); });
  window.addEventListener('beforeunload', function() { teardownTerminalPolling(false); });
});
</script>

<?php require_once __DIR__ . '/shared/footer.php'; ?>
