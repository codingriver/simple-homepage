<?php
/**
 * 配置管理 admin/configs.php
 * 通过 host-agent 安全地编辑、校验、应用系统配置文件
 */

declare(strict_types=1);

$page_title = '配置管理';
$page_permission = 'ssh.view';
require_once __DIR__ . '/shared/header.php';
require_once __DIR__ . '/shared/host_agent_lib.php';

$canManage = auth_user_has_permission('ssh.config.manage', $current_admin)
    || auth_user_has_permission('ssh.manage', $current_admin);

// 获取配置定义列表
$defsResult = ['ok' => false, 'definitions' => []];
try {
    $defsResult = host_agent_config_definitions();
} catch (Throwable $e) {
    $defsResult['msg'] = 'host-agent 未运行或无法连接';
}

$definitions = (array)($defsResult['definitions'] ?? []);

// 获取包管理器信息（用于检测当前系统）
$managerInfo = ['ok' => false, 'manager' => 'unknown'];
try {
    $managerInfo = host_agent_package_manager();
} catch (Throwable $e) {
    // ignore
}
$manager = (string)($managerInfo['manager'] ?? 'unknown');
?>

<div class="card">
  <div class="card-title">⚙️ 配置管理</div>
  <p style="color:var(--tm);font-size:13px;margin-bottom:16px">
    通过 host-agent 安全地编辑系统配置文件。所有修改均会自动备份，支持校验和回滚。
  </p>

  <?php if (empty($definitions)): ?>
  <div class="alert alert-warning">
    ⚠️ 未获取到配置定义列表。host-agent 可能未运行。
  </div>
  <?php else: ?>

  <!-- 配置项列表 -->
  <div id="config-list" class="config-grid">
    <?php foreach ($definitions as $def): ?>
    <div class="config-card" data-id="<?= htmlspecialchars((string)($def['id'] ?? '')) ?>">
      <div class="config-card-icon"><?= htmlspecialchars((string)($def['icon'] ?? '📄')) ?></div>
      <div class="config-card-info">
        <div class="config-card-name"><?= htmlspecialchars((string)($def['label'] ?? '')) ?></div>
        <div class="config-card-format">格式：<?= htmlspecialchars((string)($def['format'] ?? 'text')) ?></div>
      </div>
      <button class="btn btn-sm btn-secondary config-edit-btn" data-id="<?= htmlspecialchars((string)($def['id'] ?? '')) ?>" data-label="<?= htmlspecialchars((string)($def['label'] ?? '')) ?>">
        编辑
      </button>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- 编辑器区域 -->
  <div id="config-editor" style="display:none;margin-top:20px">
    <div class="card" style="border:1px solid rgba(255,255,255,0.08)">
      <div class="card-title" style="display:flex;justify-content:space-between;align-items:center">
        <span id="editor-title">编辑配置</span>
        <button class="btn btn-sm" onclick="closeEditor()">✕ 关闭</button>
      </div>
      <div style="margin-bottom:12px">
        <div style="font-size:12px;color:var(--tm);margin-bottom:4px">配置文件路径</div>
        <div id="editor-path" style="font-family:var(--mono);font-size:13px;background:var(--bg);padding:8px 12px;border-radius:6px;border:1px solid var(--bd)">-</div>
      </div>
      <textarea id="editor-content" class="form-control" style="min-height:400px;font-family:var(--mono);font-size:13px;line-height:1.6;white-space:pre" spellcheck="false"></textarea>
      <div style="display:flex;gap:8px;margin-top:12px;flex-wrap:wrap">
        <?php if ($canManage): ?>
        <button class="btn btn-primary" id="editor-validate-btn" onclick="validateConfig()">✓ 校验</button>
        <button class="btn btn-success" id="editor-apply-btn" onclick="applyConfig()">💾 应用</button>
        <?php endif; ?>
        <button class="btn btn-secondary" id="editor-history-btn" onclick="loadHistory()">📋 备份历史</button>
      </div>
      <div id="editor-output" style="margin-top:12px;display:none">
        <pre style="background:#1a1a2e;padding:12px;border-radius:8px;font-size:12px;max-height:200px;overflow:auto"></pre>
      </div>
    </div>
  </div>

  <!-- 备份历史 -->
  <div id="config-history" style="display:none;margin-top:16px">
    <div class="card" style="border:1px solid rgba(255,255,255,0.08)">
      <div class="card-title" style="font-size:14px;display:flex;justify-content:space-between;align-items:center">
        <span>📋 备份历史</span>
        <button class="btn btn-sm" onclick="closeHistory()">✕</button>
      </div>
      <div id="history-list"></div>
    </div>
  </div>

  <?php endif; ?>
</div>

<style>
.config-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
  gap: 12px;
}
.config-card {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 14px 16px;
  background: rgba(255,255,255,0.03);
  border: 1px solid rgba(255,255,255,0.06);
  border-radius: 10px;
  cursor: pointer;
  transition: background .15s;
}
.config-card:hover {
  background: rgba(255,255,255,0.06);
}
.config-card-icon {
  font-size: 24px;
}
.config-card-info {
  flex: 1;
}
.config-card-name {
  font-weight: 500;
  font-size: 14px;
}
.config-card-format {
  color: var(--tm);
  font-size: 11px;
  margin-top: 2px;
}
.history-item {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 10px 12px;
  border-bottom: 1px solid rgba(255,255,255,0.05);
}
.history-item:last-child {
  border-bottom: none;
}
.history-time {
  flex: 1;
  font-size: 13px;
}
.history-path {
  color: var(--tm);
  font-size: 11px;
  font-family: var(--mono);
}
</style>

<script>
var HOST_CSRF = <?= json_encode(csrf_token()) ?>;
var CURRENT_CONFIG_ID = '';
var CURRENT_CONFIG_LABEL = '';

function showEditorOutput(text, type) {
    var el = document.getElementById('editor-output');
    el.style.display = 'block';
    var pre = el.querySelector('pre');
    pre.textContent = text;
    pre.style.border = type === 'error' ? '1px solid rgba(255,107,107,.3)' : (type === 'success' ? '1px solid rgba(74,222,128,.3)' : '1px solid rgba(96,165,250,.3)');
}

function hideEditorOutput() {
    document.getElementById('editor-output').style.display = 'none';
}

async function apiPost(action, data) {
    var form = new URLSearchParams();
    form.append('action', action);
    form.append('_csrf', HOST_CSRF);
    for (var k in (data || {})) {
        form.append(k, data[k]);
    }
    var r = await fetch('host_api.php', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: form,
    });
    return r.json();
}

async function pollTask(taskId, onProgress) {
    while (true) {
        await new Promise(function(resolve) { setTimeout(resolve, 1500); });
        var status = await apiPost('task_status', { task_id: taskId });
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

async function openEditor(configId, label) {
    CURRENT_CONFIG_ID = configId;
    CURRENT_CONFIG_LABEL = label;
    document.getElementById('editor-title').textContent = '编辑 ' + label + ' 配置';
    document.getElementById('config-editor').style.display = 'block';
    document.getElementById('editor-content').value = '加载中...';
    hideEditorOutput();
    closeHistory();

    try {
        var result = await apiPost('config_read', { config_id: configId });
        if (result.ok) {
            document.getElementById('editor-path').textContent = result.path || '-';
            document.getElementById('editor-content').value = result.content || '';
            if (!result.exists) {
                showEditorOutput('配置文件不存在，将创建新文件。', 'info');
            }
        } else {
            document.getElementById('editor-content').value = '';
            showEditorOutput('读取失败：' + (result.msg || '未知错误'), 'error');
        }
    } catch (e) {
        showEditorOutput('读取失败：' + e.message, 'error');
    }
}

function closeEditor() {
    document.getElementById('config-editor').style.display = 'none';
    CURRENT_CONFIG_ID = '';
    hideEditorOutput();
}

async function validateConfig() {
    if (!CURRENT_CONFIG_ID) return;
    var content = document.getElementById('editor-content').value;
    showToast('正在校验配置...', 'info');
    try {
        var result = await apiPost('config_validate', { config_id: CURRENT_CONFIG_ID, content: content });
        if (result.ok) {
            showEditorOutput('✓ ' + (result.msg || '校验通过') + '\n' + (result.validate_output || ''), 'success');
            showToast('校验通过', 'success');
        } else {
            showEditorOutput('✗ ' + (result.msg || '校验失败') + '\n' + (result.validate_output || ''), 'error');
            showToast('校验失败', 'error');
        }
    } catch (e) {
        showEditorOutput('校验出错：' + e.message, 'error');
    }
}

async function applyConfig() {
    if (!CURRENT_CONFIG_ID) return;
    if (!confirm('确认应用配置？\n\n如果校验失败，系统将自动回滚到修改前的状态。')) return;
    var content = document.getElementById('editor-content').value;
    showToast('正在应用配置...', 'info');
    try {
        var result = await apiPost('config_apply', { config_id: CURRENT_CONFIG_ID, content: content, async: '1' });
        if (result.task_id) {
            showEditorOutput('⏳ 任务已提交，ID: ' + result.task_id + '\n正在轮询进度...', 'info');
            var final = await pollTask(result.task_id, function(status) {
                var output = formatTaskOutput(status);
                showEditorOutput(output || '状态: ' + status.status, 'info');
            });
            if (final.ok) {
                showEditorOutput('✓ ' + (final.msg || '应用成功') + '\n备份：' + (final.backup_path || '-') + '\n' + (final.reload_output || ''), 'success');
                showToast('配置已应用', 'success');
            } else {
                showEditorOutput('✗ ' + (final.msg || '应用失败') + '\n' + (final.validate_output || '') + '\n备份：' + (final.backup_path || '无'), 'error');
                showToast('应用失败', 'error');
            }
        } else {
            if (result.ok) {
                showEditorOutput('✓ ' + (result.msg || '应用成功') + '\n备份：' + (result.backup_path || '-') + '\n' + (result.reload_output || ''), 'success');
                showToast('配置已应用', 'success');
            } else {
                showEditorOutput('✗ ' + (result.msg || '应用失败') + '\n' + (result.validate_output || '') + '\n备份：' + (result.backup_path || '无'), 'error');
                showToast('应用失败', 'error');
            }
        }
    } catch (e) {
        showEditorOutput('应用出错：' + e.message, 'error');
    }
}

async function loadHistory() {
    if (!CURRENT_CONFIG_ID) return;
    document.getElementById('config-history').style.display = 'block';
    document.getElementById('history-list').innerHTML = '<p style="padding:12px;color:var(--tm)">加载中...</p>';

    try {
        var result = await apiPost('config_history', { config_id: CURRENT_CONFIG_ID, limit: 20 });
        var list = document.getElementById('history-list');
        if (!result.ok || !result.backups || result.backups.length === 0) {
            list.innerHTML = '<p style="padding:12px;color:var(--tm)">暂无备份记录。</p>';
            return;
        }
        list.innerHTML = result.backups.map(function(b) {
            return '<div class="history-item">' +
                '<span class="history-time">' + navStatusEscape(b.time || '') + '</span>' +
                '<span class="history-path">' + navStatusEscape(b.path || '').split('/').pop() + '</span>' +
                '<button class="btn btn-sm btn-primary" onclick="restoreBackup(\'' + navStatusEscape(b.path || '') + '\')">恢复</button>' +
                '</div>';
        }).join('');
    } catch (e) {
        document.getElementById('history-list').innerHTML = '<p style="padding:12px;color:var(--tm)">加载失败：' + navStatusEscape(e.message) + '</p>';
    }
}

function closeHistory() {
    document.getElementById('config-history').style.display = 'none';
}

async function restoreBackup(backupPath) {
    if (!CURRENT_CONFIG_ID) return;
    if (!confirm('确认恢复到该备份？当前配置将被覆盖。')) return;
    showToast('正在恢复配置...', 'info');
    try {
        var result = await apiPost('config_restore', { config_id: CURRENT_CONFIG_ID, backup_path: backupPath, async: '1' });
        if (result.task_id) {
            showEditorOutput('⏳ 任务已提交，ID: ' + result.task_id + '\n正在轮询进度...', 'info');
            var final = await pollTask(result.task_id, function(status) {
                var output = formatTaskOutput(status);
                showEditorOutput(output || '状态: ' + status.status, 'info');
            });
            if (final.ok) {
                showEditorOutput('✓ ' + (final.msg || '恢复成功') + '\n当前已备份到：' + (final.backup_path || '-'), 'success');
                showToast('配置已恢复', 'success');
                openEditor(CURRENT_CONFIG_ID, CURRENT_CONFIG_LABEL);
            } else {
                showEditorOutput('✗ ' + (final.msg || '恢复失败'), 'error');
                showToast('恢复失败', 'error');
            }
        } else {
            if (result.ok) {
                showEditorOutput('✓ ' + (result.msg || '恢复成功') + '\n当前已备份到：' + (result.backup_path || '-'), 'success');
                showToast('配置已恢复', 'success');
                openEditor(CURRENT_CONFIG_ID, CURRENT_CONFIG_LABEL);
            } else {
                showEditorOutput('✗ ' + (result.msg || '恢复失败'), 'error');
                showToast('恢复失败', 'error');
            }
        }
    } catch (e) {
        showEditorOutput('恢复出错：' + e.message, 'error');
    }
}

// 绑定编辑按钮
document.querySelectorAll('.config-edit-btn').forEach(function(btn) {
    btn.addEventListener('click', function(e) {
        e.stopPropagation();
        openEditor(this.dataset.id, this.dataset.label);
    });
});
</script>

<?php require_once __DIR__ . '/shared/footer.php'; ?>
