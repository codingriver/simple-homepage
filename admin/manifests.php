<?php
/**
 * 声明式配置管理 admin/manifests.php
 * 通过 JSON Manifest 声明期望状态，host-agent 自动对齐
 */

declare(strict_types=1);

$page_title = '声明式管理';
$page_permission = 'ssh.view';
require_once __DIR__ . '/shared/header.php';
require_once __DIR__ . '/shared/host_agent_lib.php';

$canManage = auth_user_has_permission('ssh.manage', $current_admin);

// 示例 Manifest
$sampleManifest = json_encode([
    'packages' => [
        'nginx' => ['state' => 'installed'],
        'redis' => ['state' => 'installed'],
    ],
    'services' => [
        'nginx' => ['state' => 'running', 'enabled' => true],
        'redis' => ['state' => 'running', 'enabled' => true],
    ],
    'configs' => [
        'nginx' => [
            'worker_processes' => 'auto',
            'worker_connections' => '4096',
        ],
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
?>

<div class="card">
  <div class="card-title">📋 声明式管理</div>
  <p style="color:var(--tm);font-size:13px;margin-bottom:16px">
    提交 JSON Manifest 声明期望状态，host-agent 将自动检测差异并执行对齐操作。
    支持 <strong>预演</strong>（只查看差异不执行）和 <strong>应用</strong>（真正执行）。
  </p>

  <div style="display:flex;gap:12px;margin-bottom:16px;flex-wrap:wrap">
    <div style="flex:1;min-width:300px">
      <div style="font-size:12px;color:var(--tm);margin-bottom:6px">Manifest JSON</div>
      <textarea id="manifest-editor" class="form-control" style="min-height:480px;font-family:var(--mono);font-size:12px;line-height:1.5;white-space:pre" spellcheck="false"><?= htmlspecialchars($sampleManifest) ?></textarea>
      <div style="display:flex;gap:8px;margin-top:10px;flex-wrap:wrap">
        <?php if ($canManage): ?>
        <button class="btn btn-primary" onclick="dryRunManifest()">🔍 预演 (Dry-run)</button>
        <button class="btn btn-success" onclick="applyManifest()">🚀 应用</button>
        <?php endif; ?>
        <button class="btn btn-secondary" onclick="validateManifest()">✓ 校验格式</button>
        <button class="btn btn-secondary" onclick="resetManifest()">↺ 重置示例</button>
      </div>
    </div>

    <div style="flex:1;min-width:300px">
      <div style="font-size:12px;color:var(--tm);margin-bottom:6px">执行结果</div>
      <div id="manifest-result" style="background:var(--bg);border:1px solid var(--bd);border-radius:8px;padding:12px;min-height:480px;overflow:auto">
        <p style="color:var(--tm);font-size:13px">点击「预演」或「应用」查看结果。</p>
      </div>
    </div>
  </div>

  <!-- 帮助文档 -->
  <div class="card" style="margin-top:12px">
    <div class="card-title" style="font-size:14px">📖 Manifest 语法参考</div>
    <div style="font-size:12px;color:var(--tm);line-height:1.8">
      <pre style="background:var(--bg);padding:12px;border-radius:8px;overflow:auto">
{
  "packages": {
    "nginx":   { "state": "installed" },     // installed | absent
    "redis":   { "state": "installed" }
  },
  "services": {
    "nginx":   { "state": "running", "enabled": true },   // running | stopped
    "redis":   { "state": "running", "enabled": true }    // enabled: true | false
  },
  "configs": {
    "nginx":   { "worker_processes": "auto", "worker_connections": "4096" }
  },
  "users": {
    "deploy":  { "state": "present", "groups": ["www-data"] }  // present | absent
  }
}
      </pre>
      <p style="margin-top:8px">
        • <strong>packages</strong>：跨发行版包名自动映射（如 nginx 在 apt 是 nginx，在 dnf 也是 nginx）<br>
        • <strong>services</strong>：state 控制运行状态，enabled 控制开机自启<br>
        • <strong>configs</strong>：支持 key-value 形式修改，自动适配不同配置文件格式<br>
        • <strong>预演 (Dry-run)</strong>：只显示差异不执行，安全查看变更内容<br>
        • <strong>应用</strong>：真正执行所有变更，配置修改会自动备份并支持回滚
      </p>
    </div>
  </div>
</div>

<script>
var HOST_CSRF = <?= json_encode(csrf_token()) ?>;
var SAMPLE_MANIFEST = <?= json_encode($sampleManifest) ?>;

function showManifestResult(html, type) {
    var el = document.getElementById('manifest-result');
    el.innerHTML = html;
    el.style.borderColor = type === 'error' ? 'rgba(255,107,107,.3)' : (type === 'success' ? 'rgba(74,222,128,.3)' : 'var(--bd)');
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

function renderChanges(changes, dryRun) {
    if (!changes || changes.length === 0) {
        return '<p style="color:var(--tm)">无变更。</p>';
    }
    var html = '<table style="width:100%;font-size:12px;border-collapse:collapse">';
    html += '<tr style="border-bottom:1px solid rgba(255,255,255,0.1)"><th style="text-align:left;padding:6px">类型</th><th style="text-align:left;padding:6px">名称</th><th style="text-align:left;padding:6px">操作</th><th style="text-align:left;padding:6px">结果</th></tr>';
    for (var i = 0; i < changes.length; i++) {
        var c = changes[i];
        var result = c.dry_run ? '<span style="color:#60a5fa">[将执行]</span>' : (c.ok ? '<span style="color:#4ade80">✓ 成功</span>' : '<span style="color:#ff8080">✗ 失败</span>');
        var msg = c.msg ? '<br><span style="color:var(--tm);font-size:11px">' + navStatusEscape(c.msg) + '</span>' : '';
        html += '<tr style="border-bottom:1px solid rgba(255,255,255,0.05)">' +
            '<td style="padding:6px">' + navStatusEscape(c.type) + '</td>' +
            '<td style="padding:6px">' + navStatusEscape(c.name) + '</td>' +
            '<td style="padding:6px">' + navStatusEscape(c.action) + '</td>' +
            '<td style="padding:6px">' + result + msg + '</td>' +
            '</tr>';
    }
    html += '</table>';
    return html;
}

async function dryRunManifest() {
    var json = document.getElementById('manifest-editor').value.trim();
    if (!json) { showToast('Manifest 不能为空', 'warning'); return; }
    showToast('正在预演...', 'info');
    try {
        var result = await apiPost('manifest_dry_run', { manifest_json: json, async: '1' });
        if (result.task_id) {
            showManifestResult('<p style="color:var(--tm)">⏳ 任务已提交，ID: ' + navStatusEscape(result.task_id) + '<br>正在轮询进度...</p>', 'info');
            var final = await pollTask(result.task_id, function(status) {
                var output = formatTaskOutput(status);
                showManifestResult('<pre style="font-size:12px">' + navStatusEscape(output || '状态: ' + status.status) + '</pre>', 'info');
            });
            if (final.ok) {
                var html = '<div style="color:#4ade80;font-weight:500;margin-bottom:8px">✓ 预演完成</div>';
                html += renderChanges(final.changes, true);
                if (final.errors && final.errors.length > 0) {
                    html += '<div style="color:#ff8080;margin-top:8px">错误：<br>' + final.errors.map(function(e){ return navStatusEscape(e); }).join('<br>') + '</div>';
                }
                showManifestResult(html, 'info');
            } else {
                var html = '<div style="color:#ff8080;font-weight:500;margin-bottom:8px">✗ 预演失败</div>';
                html += '<pre style="font-size:12px">' + navStatusEscape(final.msg || '') + '</pre>';
                if (final.errors && final.errors.length > 0) {
                    html += '<pre style="font-size:12px;color:#ff8080">' + final.errors.map(function(e){ return navStatusEscape(e); }).join('\n') + '</pre>';
                }
                showManifestResult(html, 'error');
            }
        } else {
            if (result.ok) {
                var html = '<div style="color:#4ade80;font-weight:500;margin-bottom:8px">✓ 预演完成</div>';
                html += renderChanges(result.changes, true);
                if (result.errors && result.errors.length > 0) {
                    html += '<div style="color:#ff8080;margin-top:8px">错误：<br>' + result.errors.map(function(e){ return navStatusEscape(e); }).join('<br>') + '</div>';
                }
                showManifestResult(html, 'info');
            } else {
                var html = '<div style="color:#ff8080;font-weight:500;margin-bottom:8px">✗ 预演失败</div>';
                html += '<pre style="font-size:12px">' + navStatusEscape(result.msg || '') + '</pre>';
                if (result.errors && result.errors.length > 0) {
                    html += '<pre style="font-size:12px;color:#ff8080">' + result.errors.map(function(e){ return navStatusEscape(e); }).join('\n') + '</pre>';
                }
                showManifestResult(html, 'error');
            }
        }
    } catch (e) {
        showManifestResult('<p style="color:#ff8080">请求失败：' + navStatusEscape(e.message) + '</p>', 'error');
    }
}

async function applyManifest() {
    var json = document.getElementById('manifest-editor').value.trim();
    if (!json) { showToast('Manifest 不能为空', 'warning'); return; }
    if (!confirm('确认应用 Manifest？\n\n这将根据声明自动安装/卸载软件包、启停服务、修改配置。')) return;
    showToast('正在应用...', 'info');
    try {
        var result = await apiPost('manifest_apply', { manifest_json: json, async: '1' });
        if (result.task_id) {
            showManifestResult('<p style="color:var(--tm)">⏳ 任务已提交，ID: ' + navStatusEscape(result.task_id) + '<br>正在轮询进度...</p>', 'info');
            var final = await pollTask(result.task_id, function(status) {
                var output = formatTaskOutput(status);
                showManifestResult('<pre style="font-size:12px">' + navStatusEscape(output || '状态: ' + status.status) + '</pre>', 'info');
            });
            if (final.ok) {
                var html = '<div style="color:#4ade80;font-weight:500;margin-bottom:8px">✓ 应用完成</div>';
                html += renderChanges(final.changes, false);
                if (final.errors && final.errors.length > 0) {
                    html += '<div style="color:#ff8080;margin-top:8px">警告：<br>' + final.errors.map(function(e){ return navStatusEscape(e); }).join('<br>') + '</div>';
                }
                showManifestResult(html, 'success');
                showToast('Manifest 已应用', 'success');
            } else {
                var html = '<div style="color:#ff8080;font-weight:500;margin-bottom:8px">✗ 应用失败</div>';
                html += '<pre style="font-size:12px">' + navStatusEscape(final.msg || '') + '</pre>';
                if (final.errors && final.errors.length > 0) {
                    html += '<pre style="font-size:12px;color:#ff8080">' + final.errors.map(function(e){ return navStatusEscape(e); }).join('\n') + '</pre>';
                }
                showManifestResult(html, 'error');
            }
        } else {
            if (result.ok) {
                var html = '<div style="color:#4ade80;font-weight:500;margin-bottom:8px">✓ 应用完成</div>';
                html += renderChanges(result.changes, false);
                if (result.errors && result.errors.length > 0) {
                    html += '<div style="color:#ff8080;margin-top:8px">警告：<br>' + result.errors.map(function(e){ return navStatusEscape(e); }).join('<br>') + '</div>';
                }
                showManifestResult(html, 'success');
                showToast('Manifest 已应用', 'success');
            } else {
                var html = '<div style="color:#ff8080;font-weight:500;margin-bottom:8px">✗ 应用失败</div>';
                html += '<pre style="font-size:12px">' + navStatusEscape(result.msg || '') + '</pre>';
                if (result.errors && result.errors.length > 0) {
                    html += '<pre style="font-size:12px;color:#ff8080">' + result.errors.map(function(e){ return navStatusEscape(e); }).join('\n') + '</pre>';
                }
                showManifestResult(html, 'error');
            }
        }
    } catch (e) {
        showManifestResult('<p style="color:#ff8080">请求失败：' + navStatusEscape(e.message) + '</p>', 'error');
    }
}

async function validateManifest() {
    var json = document.getElementById('manifest-editor').value.trim();
    if (!json) { showToast('Manifest 不能为空', 'warning'); return; }
    showToast('正在校验...', 'info');
    try {
        var result = await apiPost('manifest_validate', { manifest_json: json });
        if (result.ok) {
            showManifestResult('<p style="color:#4ade80">✓ Manifest 格式有效</p>', 'success');
            showToast('格式有效', 'success');
        } else {
            var html = '<p style="color:#ff8080">✗ ' + navStatusEscape(result.msg || '校验失败') + '</p>';
            if (result.errors && result.errors.length > 0) {
                html += '<ul style="color:#ff8080;font-size:12px">' + result.errors.map(function(e){ return '<li>' + navStatusEscape(e) + '</li>'; }).join('') + '</ul>';
            }
            showManifestResult(html, 'error');
            showToast('格式错误', 'error');
        }
    } catch (e) {
        showManifestResult('<p style="color:#ff8080">请求失败：' + navStatusEscape(e.message) + '</p>', 'error');
    }
}

function resetManifest() {
    document.getElementById('manifest-editor').value = SAMPLE_MANIFEST;
    showManifestResult('<p style="color:var(--tm)">已重置为示例 Manifest。</p>', 'info');
}
</script>

<?php require_once __DIR__ . '/shared/footer.php'; ?>
