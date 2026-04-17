<?php
/**
 * 软件包管理 admin/packages.php
 * 通过 host-agent 管理宿主机上的软件包
 */

declare(strict_types=1);

$page_title = '软件包管理';
$page_permission = 'ssh.view';
require_once __DIR__ . '/shared/header.php';
require_once __DIR__ . '/shared/host_agent_lib.php';

$canManage = auth_user_has_permission('ssh.package.manage', $current_admin)
    || auth_user_has_permission('ssh.manage', $current_admin);

// 获取包管理器信息
$managerInfo = ['ok' => false, 'manager' => 'unknown', 'service_manager' => 'unknown'];
try {
    $managerInfo = host_agent_package_manager();
} catch (Throwable $e) {
    $managerInfo['msg'] = 'host-agent 未运行或无法连接';
}

$manager = (string)($managerInfo['manager'] ?? 'unknown');
$svcManager = (string)($managerInfo['service_manager'] ?? 'unknown');

// 已安装包列表
$installedList = ['ok' => false, 'packages' => [], 'total' => 0];
if ($manager !== 'unknown') {
    try {
        $installedList = host_agent_package_list(200);
    } catch (Throwable $e) {
        $installedList['msg'] = '获取已安装包列表失败';
    }
}

$installedPackages = (array)($installedList['packages'] ?? []);
$installedTotal = (int)($installedList['total'] ?? count($installedPackages));

// 常用软件推荐（基于映射表）
$commonPackages = [
    ['alias' => 'nginx', 'icon' => '🌐', 'desc' => '高性能 Web 服务器'],
    ['alias' => 'mysql', 'icon' => '🐬', 'desc' => 'MySQL 数据库服务器'],
    ['alias' => 'redis', 'icon' => '🔴', 'desc' => '内存键值存储'],
    ['alias' => 'nodejs', 'icon' => '⬢', 'desc' => 'JavaScript 运行时'],
    ['alias' => 'docker', 'icon' => '🐳', 'desc' => '容器化平台'],
    ['alias' => 'git', 'icon' => '🌿', 'desc' => '分布式版本控制'],
    ['alias' => 'htop', 'icon' => '📊', 'desc' => '交互式进程查看器'],
    ['alias' => 'openssh-server', 'icon' => '🔐', 'desc' => 'SSH 远程登录服务'],
];

function pkg_manager_label(string $m): string {
    $map = [
        'apt' => 'APT (Debian/Ubuntu)',
        'dnf' => 'DNF (RHEL 8+)',
        'yum' => 'YUM (RHEL 7)',
        'apk' => 'APK (Alpine)',
        'pacman' => 'Pacman (Arch)',
        'zypper' => 'Zypper (openSUSE)',
        'emerge' => 'Portage (Gentoo)',
        'brew' => 'Homebrew (macOS)',
        'port' => 'MacPorts (macOS)',
        'unknown' => '未检测到',
    ];
    return $map[$m] ?? $m;
}

function svc_manager_label(string $m): string {
    $map = [
        'systemd' => 'systemd',
        'openrc' => 'OpenRC',
        'runit' => 'runit',
        'sysvinit' => 'SysVinit',
        'launchd' => 'launchd (macOS)',
        'unknown' => '未检测到',
    ];
    return $map[$m] ?? $m;
}
?>

<div class="card">
  <div class="card-title">📦 软件包管理</div>
  <p style="color:var(--tm);font-size:13px;margin-bottom:16px">
    通过 host-agent 管理宿主机的软件包。检测到的包管理器：<strong><?= htmlspecialchars(pkg_manager_label($manager)) ?></strong>，
    服务管理器：<strong><?= htmlspecialchars(svc_manager_label($svcManager)) ?></strong>
  </p>

  <?php if ($manager === 'unknown'): ?>
  <div class="alert alert-warning">
    ⚠️ 未检测到支持的包管理器。host-agent 可能未安装，或宿主机使用了不支持的发行版。
  </div>
  <?php endif; ?>

  <!-- 搜索区域 -->
  <div style="display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap">
    <input type="text" id="pkg-search-input" placeholder="搜索软件包..." class="form-control" style="flex:1;min-width:200px" <?= $manager === 'unknown' ? 'disabled' : '' ?>>
    <button class="btn btn-primary" id="pkg-search-btn" onclick="searchPackages()" <?= $manager === 'unknown' ? 'disabled' : '' ?>>🔍 搜索</button>
    <?php if ($canManage): ?>
    <button class="btn btn-secondary" id="pkg-upgrade-all-btn" onclick="upgradeAllPackages()" <?= $manager === 'unknown' ? 'disabled' : '' ?>>⬆️ 全系统升级</button>
    <?php endif; ?>
  </div>

  <!-- 搜索结果 -->
  <div id="pkg-search-results" style="display:none">
    <div class="card" style="margin-bottom:16px">
      <div class="card-title" style="font-size:14px">🔍 搜索结果</div>
      <div id="pkg-search-list" class="pkg-list"></div>
    </div>
  </div>

  <!-- 常用软件推荐 -->
  <div class="card" style="margin-bottom:16px">
    <div class="card-title" style="font-size:14px">⭐ 常用软件</div>
    <div class="quick-actions" style="gap:8px">
      <?php foreach ($commonPackages as $cp): ?>
      <div class="pkg-recommend-item" data-alias="<?= htmlspecialchars($cp['alias']) ?>">
        <span class="pkg-recommend-icon"><?= $cp['icon'] ?></span>
        <div>
          <div class="pkg-recommend-name"><?= htmlspecialchars($cp['alias']) ?></div>
          <div class="pkg-recommend-desc"><?= htmlspecialchars($cp['desc']) ?></div>
        </div>
        <?php if ($canManage && $manager !== 'unknown'): ?>
        <button class="btn btn-sm btn-primary pkg-install-btn" data-alias="<?= htmlspecialchars($cp['alias']) ?>">安装</button>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- 已安装包列表 -->
  <div class="card">
    <div class="card-title" style="font-size:14px;display:flex;justify-content:space-between;align-items:center">
      <span>📋 已安装包（共 <?= $installedTotal ?> 个，显示前 <?= count($installedPackages) ?> 个）</span>
      <button class="btn btn-sm btn-secondary" onclick="loadInstalledPackages()">🔄 刷新</button>
    </div>
    <div id="pkg-installed-list" class="pkg-list">
      <?php if (empty($installedPackages)): ?>
      <p style="color:var(--tm);padding:12px 0">暂无已安装包数据，或 host-agent 未连接。</p>
      <?php else: ?>
        <?php foreach (array_slice($installedPackages, 0, 50) as $pkg): ?>
        <div class="pkg-item">
          <span class="pkg-name"><?= htmlspecialchars((string)($pkg['name'] ?? '')) ?></span>
          <span class="pkg-version"><?= htmlspecialchars((string)($pkg['version'] ?? '')) ?></span>
          <?php if ($canManage): ?>
          <button class="btn btn-sm btn-danger pkg-remove-btn" data-pkg="<?= htmlspecialchars((string)($pkg['name'] ?? '')) ?>">卸载</button>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- 操作输出弹窗 -->
<div id="pkg-modal" class="modal" style="display:none">
  <div class="modal-content" style="max-width:640px">
    <div class="modal-header">
      <span id="pkg-modal-title">操作结果</span>
      <button class="btn btn-sm" onclick="closePkgModal()">✕</button>
    </div>
    <div class="modal-body">
      <pre id="pkg-modal-output" style="background:#1a1a2e;padding:12px;border-radius:8px;font-size:12px;max-height:400px;overflow:auto"></pre>
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary" onclick="closePkgModal()">关闭</button>
    </div>
  </div>
</div>

<style>
.pkg-list { max-height:400px; overflow:auto; }
.pkg-item, .pkg-search-item {
  display:flex; align-items:center; gap:12px;
  padding:8px 12px; border-bottom:1px solid rgba(255,255,255,0.05);
}
.pkg-item:last-child, .pkg-search-item:last-child { border-bottom:none; }
.pkg-name { flex:1; font-weight:500; font-size:14px; }
.pkg-version { color:var(--tm); font-size:12px; min-width:80px; }
.pkg-desc { color:var(--tm); font-size:12px; flex:2; }
.pkg-recommend-item {
  display:flex; align-items:center; gap:10px;
  padding:10px 14px; border-radius:10px;
  background:rgba(255,255,255,0.03); border:1px solid rgba(255,255,255,0.06);
  min-width:200px; flex:1;
}
.pkg-recommend-icon { font-size:20px; }
.pkg-recommend-name { font-weight:500; font-size:13px; }
.pkg-recommend-desc { color:var(--tm); font-size:11px; margin-top:2px; }
.pkg-install-btn { margin-left:auto; }
.modal {
  position:fixed; top:0; left:0; width:100%; height:100%;
  background:rgba(0,0,0,0.6); z-index:1000;
  display:flex; align-items:center; justify-content:center;
}
.modal-content {
  background:var(--card-bg); border-radius:12px; border:1px solid rgba(255,255,255,0.08);
  width:90%; max-width:560px;
}
.modal-header {
  display:flex; justify-content:space-between; align-items:center;
  padding:16px 20px; border-bottom:1px solid rgba(255,255,255,0.06);
  font-weight:600;
}
.modal-body { padding:16px 20px; }
.modal-footer {
  display:flex; justify-content:flex-end; gap:8px;
  padding:12px 20px; border-top:1px solid rgba(255,255,255,0.06);
}
</style>

<script>
var HOST_CSRF = <?= json_encode(csrf_token()) ?>;

function showPkgModal(title, output) {
    document.getElementById('pkg-modal-title').textContent = title;
    document.getElementById('pkg-modal-output').textContent = output || '(无输出)';
    document.getElementById('pkg-modal').style.display = 'flex';
}

function closePkgModal() {
    document.getElementById('pkg-modal').style.display = 'none';
}

document.getElementById('pkg-modal').addEventListener('click', function(e) {
    if (e.target === this) closePkgModal();
});

async function apiPost(action, data) {
    const form = new URLSearchParams();
    form.append('action', action);
    form.append('_csrf', HOST_CSRF);
    for (const [k, v] of Object.entries(data || {})) {
        form.append(k, v);
    }
    const r = await fetch('host_api.php', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: form,
    });
    return r.json();
}

async function pollTask(taskId, onProgress) {
    while (true) {
        await new Promise(function(resolve) { setTimeout(resolve, 1500); });
        const status = await apiPost('task_status', { task_id: taskId });
        if (!status.ok) {
            if (onProgress) onProgress({ status: 'error', error: status.msg });
            return status;
        }
        if (onProgress) onProgress(status);
        if (status.status !== 'pending' && status.status !== 'running') {
            return status.result ? (status.result.ok !== undefined ? status.result : status) : status;
        }
    }
}

function formatTaskOutput(status) {
    var out = status.output || '';
    var result = status.result || {};
    var lines = [];
    if (out) lines.push(out);
    if (result.msg) lines.push(result.msg);
    if (result.output) lines.push(result.output);
    return lines.join('\n\n');
}

async function searchPackages() {
    const input = document.getElementById('pkg-search-input');
    const keyword = input.value.trim();
    if (!keyword) { showToast('请输入搜索关键词', 'warning'); return; }

    const btn = document.getElementById('pkg-search-btn');
    btn.disabled = true; btn.textContent = '搜索中...';

    try {
        const result = await apiPost('package_search', { keyword, limit: 50 });
        const container = document.getElementById('pkg-search-results');
        const list = document.getElementById('pkg-search-list');
        container.style.display = 'block';

        if (!result.ok || !result.packages || result.packages.length === 0) {
            list.innerHTML = '<p style="color:var(--tm);padding:12px">未找到匹配的软件包。</p>';
            return;
        }

        list.innerHTML = result.packages.map(function(p) {
            const name = navStatusEscape(p.name || '');
            const desc = navStatusEscape(p.description || '');
            const ver = navStatusEscape(p.version || '');
            return '<div class="pkg-search-item">' +
                '<span class="pkg-name">' + name + '</span>' +
                '<span class="pkg-desc">' + desc + '</span>' +
                (ver ? '<span class="pkg-version">' + ver + '</span>' : '') +
                '<?php if ($canManage): ?>' +
                '<button class="btn btn-sm btn-primary" onclick="installPackage(\'' + name + '\')">安装</button>' +
                '<?php endif; ?>' +
                '</div>';
        }).join('');
    } catch (e) {
        showToast('搜索失败: ' + e.message, 'error');
    } finally {
        btn.disabled = false; btn.textContent = '🔍 搜索';
    }
}

async function installPackage(pkg) {
    if (!confirm('确认安装软件包「' + pkg + '」？')) return;
    showToast('正在安装 ' + pkg + '...', 'info');
    try {
        const result = await apiPost('package_install', { pkg, async: '1' });
        if (result.task_id) {
            showPkgModal('⏳ 正在安装 ' + pkg, '任务已提交，ID: ' + result.task_id + '\n正在轮询进度...');
            const final = await pollTask(result.task_id, function(status) {
                var output = formatTaskOutput(status);
                document.getElementById('pkg-modal-output').textContent = output || '状态: ' + status.status;
            });
            showPkgModal(final.ok ? '✓ 安装成功' : '✗ 安装失败', (final.msg || '') + '\n\n' + (final.output || ''));
            if (final.ok) setTimeout(function(){ location.reload(); }, 1500);
        } else {
            showPkgModal(result.ok ? '安装成功' : '安装失败', result.msg + '\n\n' + (result.output || ''));
            if (result.ok) setTimeout(function(){ location.reload(); }, 1500);
        }
    } catch (e) {
        showToast('安装失败: ' + e.message, 'error');
    }
}

async function removePackage(pkg) {
    if (!confirm('确认卸载软件包「' + pkg + '」？此操作不可恢复。')) return;
    showToast('正在卸载 ' + pkg + '...', 'info');
    try {
        const result = await apiPost('package_remove', { pkg, async: '1' });
        if (result.task_id) {
            showPkgModal('⏳ 正在卸载 ' + pkg, '任务已提交，ID: ' + result.task_id + '\n正在轮询进度...');
            const final = await pollTask(result.task_id, function(status) {
                var output = formatTaskOutput(status);
                document.getElementById('pkg-modal-output').textContent = output || '状态: ' + status.status;
            });
            showPkgModal(final.ok ? '✓ 卸载成功' : '✗ 卸载失败', (final.msg || '') + '\n\n' + (final.output || ''));
            if (final.ok) setTimeout(function(){ location.reload(); }, 1500);
        } else {
            showPkgModal(result.ok ? '卸载成功' : '卸载失败', result.msg + '\n\n' + (result.output || ''));
            if (result.ok) setTimeout(function(){ location.reload(); }, 1500);
        }
    } catch (e) {
        showToast('卸载失败: ' + e.message, 'error');
    }
}

async function upgradeAllPackages() {
    if (!confirm('确认执行全系统升级？这可能需要较长时间。')) return;
    showToast('正在执行全系统升级...', 'info');
    try {
        const result = await apiPost('package_upgrade_all', { async: '1' });
        if (result.task_id) {
            showPkgModal('⏳ 全系统升级中', '任务已提交，ID: ' + result.task_id + '\n正在轮询进度...');
            const final = await pollTask(result.task_id, function(status) {
                var output = formatTaskOutput(status);
                document.getElementById('pkg-modal-output').textContent = output || '状态: ' + status.status;
            });
            showPkgModal(final.ok ? '✓ 升级完成' : '✗ 升级失败', (final.msg || '') + '\n\n' + (final.output || ''));
        } else {
            showPkgModal(result.ok ? '升级完成' : '升级失败', result.msg + '\n\n' + (result.output || ''));
        }
    } catch (e) {
        showToast('升级失败: ' + e.message, 'error');
    }
}

async function loadInstalledPackages() {
    showToast('正在刷新已安装包列表...', 'info');
    location.reload();
}

// 绑定常用软件安装按钮
document.querySelectorAll('.pkg-install-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
        installPackage(this.dataset.alias);
    });
});

// 绑定已安装包卸载按钮
document.querySelectorAll('.pkg-remove-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
        removePackage(this.dataset.pkg);
    });
});

// 回车搜索
document.getElementById('pkg-search-input').addEventListener('keypress', function(e) {
    if (e.key === 'Enter') searchPackages();
});
</script>

<?php require_once __DIR__ . '/shared/footer.php'; ?>
