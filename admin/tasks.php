<?php
/**
 * 异步任务监控 admin/tasks.php
 * 查看和管理 host-agent 异步任务队列
 */

declare(strict_types=1);

$page_title = '异步任务';
$page_permission = 'ssh.view';
require_once __DIR__ . '/shared/header.php';
require_once __DIR__ . '/shared/host_agent_lib.php';

$canManage = auth_user_has_permission('ssh.manage', $current_admin);

// 获取任务列表
$taskList = ['ok' => false, 'tasks' => []];
try {
    $taskList = host_agent_task_list();
} catch (Throwable $e) {
    $taskList['msg'] = 'host-agent 未运行或无法连接';
}

$tasks = (array)($taskList['tasks'] ?? []);

function taskStatusBadge(string $status): string {
    $map = [
        'pending'   => ['⏳ 等待中', '#f59e0b'],
        'running'   => ['▶️ 运行中', '#3b82f6'],
        'completed' => ['✓ 已完成', '#22c55e'],
        'failed'    => ['✗ 失败', '#ef4444'],
        'cancelled' => ['⊘ 已取消', '#9ca3af'],
    ];
    $info = $map[$status] ?? ['? ' . $status, '#9ca3af'];
    return '<span style="color:' . $info[1] . ';font-weight:500">' . $info[0] . '</span>';
}

function taskActionLabel(string $action): string {
    $map = [
        'package_install'   => '📦 安装包',
        'package_remove'    => '📦 卸载包',
        'package_upgrade_all' => '⬆️ 全系统升级',
        'config_apply'      => '⚙️ 应用配置',
        'config_restore'    => '⚙️ 恢复配置',
        'manifest_apply'    => '📋 应用 Manifest',
        'manifest_dry_run'  => '📋 预演 Manifest',
    ];
    return $map[$action] ?? $action;
}
?>

<div class="card">
  <div class="card-title">⏱ 异步任务监控</div>
  <p style="color:var(--tm);font-size:13px;margin-bottom:16px">
    查看 host-agent 异步任务队列的状态。耗时操作（如包安装、全系统升级、配置应用、Manifest 应用）会自动提交为后台任务。
  </p>

  <div style="display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap">
    <button class="btn btn-secondary" onclick="loadTaskList()">🔄 刷新列表</button>
    <?php if ($canManage): ?>
    <button class="btn btn-secondary" onclick="cancelAllRunning()">⊘ 取消全部运行中</button>
    <?php endif; ?>
  </div>

  <div id="task-list">
    <?php if (empty($tasks)): ?>
    <p style="color:var(--tm);padding:12px">暂无任务记录。</p>
    <?php else: ?>
    <table style="width:100%;font-size:13px;border-collapse:collapse">
      <tr style="border-bottom:1px solid rgba(255,255,255,0.1)">
        <th style="text-align:left;padding:8px">任务 ID</th>
        <th style="text-align:left;padding:8px">操作</th>
        <th style="text-align:left;padding:8px">状态</th>
        <th style="text-align:left;padding:8px">开始时间</th>
        <th style="text-align:left;padding:8px">完成时间</th>
        <th style="text-align:left;padding:8px">操作</th>
      </tr>
      <?php foreach ($tasks as $task): ?>
      <tr style="border-bottom:1px solid rgba(255,255,255,0.05)" data-task-id="<?= htmlspecialchars((string)($task['id'] ?? '')) ?>">
        <td style="padding:8px;font-family:var(--mono);font-size:12px"><?= htmlspecialchars(substr((string)($task['id'] ?? ''), 0, 16) . '...') ?></td>
        <td style="padding:8px"><?= taskActionLabel((string)($task['action'] ?? '')) ?></td>
        <td style="padding:8px"><?= taskStatusBadge((string)($task['status'] ?? '')) ?></td>
        <td style="padding:8px;color:var(--tm);font-size:12px"><?= htmlspecialchars((string)($task['started_at'] ?? '-')) ?></td>
        <td style="padding:8px;color:var(--tm);font-size:12px"><?= htmlspecialchars((string)($task['completed_at'] ?? '-')) ?></td>
        <td style="padding:8px">
          <button class="btn btn-sm btn-secondary" onclick="showTaskDetail('<?= htmlspecialchars((string)($task['id'] ?? '')) ?>')">详情</button>
          <?php if ($canManage && in_array(($task['status'] ?? ''), ['pending', 'running'], true)): ?>
          <button class="btn btn-sm" onclick="cancelTask('<?= htmlspecialchars((string)($task['id'] ?? '')) ?>')">取消</button>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </table>
    <?php endif; ?>
  </div>
</div>

<!-- 任务详情模态框 -->
<div id="task-modal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.6);z-index:2000;align-items:center;justify-content:center">
  <div style="background:var(--card);border-radius:12px;max-width:720px;width:90%;max-height:80vh;display:flex;flex-direction:column;box-shadow:0 20px 60px rgba(0,0,0,.4)">
    <div style="display:flex;justify-content:space-between;align-items:center;padding:16px 20px;border-bottom:1px solid rgba(255,255,255,0.06)">
      <div style="font-weight:600">任务详情</div>
      <button class="btn btn-sm" onclick="closeTaskModal()">✕</button>
    </div>
    <div style="padding:16px 20px;overflow:auto;flex:1">
      <div id="task-detail-content" style="font-size:13px;line-height:1.6"></div>
      <pre id="task-detail-output" style="background:var(--bg);padding:12px;border-radius:8px;font-size:12px;max-height:300px;overflow:auto;margin-top:12px;display:none"></pre>
    </div>
  </div>
</div>

<script>
var HOST_CSRF = <?= json_encode(csrf_token()) ?>;

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

function loadTaskList() {
    location.reload();
}

async function showTaskDetail(taskId) {
    document.getElementById('task-modal').style.display = 'flex';
    document.getElementById('task-detail-content').innerHTML = '加载中...';
    document.getElementById('task-detail-output').style.display = 'none';
    try {
        var result = await apiPost('task_status', { task_id: taskId });
        if (!result.ok) {
            document.getElementById('task-detail-content').innerHTML = '<p style="color:#ff8080">加载失败：' + navStatusEscape(result.msg || '') + '</p>';
            return;
        }
        var html = '<div style="margin-bottom:8px"><strong>任务 ID：</strong><span style="font-family:var(--mono)">' + navStatusEscape(result.task_id || '') + '</span></div>';
        html += '<div style="margin-bottom:8px"><strong>操作：</strong>' + navStatusEscape(result.action || '') + '</div>';
        html += '<div style="margin-bottom:8px"><strong>状态：</strong>' + navStatusEscape(result.status || '') + '</div>';
        if (result.started_at) {
            html += '<div style="margin-bottom:8px"><strong>开始时间：</strong>' + navStatusEscape(String(result.started_at)) + '</div>';
        }
        if (result.completed_at) {
            html += '<div style="margin-bottom:8px"><strong>完成时间：</strong>' + navStatusEscape(String(result.completed_at)) + '</div>';
        }
        if (result.result) {
            html += '<div style="margin-bottom:8px"><strong>执行结果：</strong>' + (result.result.ok ? '<span style="color:#22c55e">成功</span>' : '<span style="color:#ef4444">失败</span>') + '</div>';
            if (result.result.msg) {
                html += '<div style="margin-bottom:8px"><strong>消息：</strong>' + navStatusEscape(result.result.msg) + '</div>';
            }
        }
        document.getElementById('task-detail-content').innerHTML = html;
        if (result.output) {
            document.getElementById('task-detail-output').textContent = result.output;
            document.getElementById('task-detail-output').style.display = 'block';
        }
        // 如果任务还在运行，自动轮询
        if (result.status === 'pending' || result.status === 'running') {
            setTimeout(function() { showTaskDetail(taskId); }, 2000);
        }
    } catch (e) {
        document.getElementById('task-detail-content').innerHTML = '<p style="color:#ff8080">加载失败：' + navStatusEscape(e.message) + '</p>';
    }
}

function closeTaskModal() {
    document.getElementById('task-modal').style.display = 'none';
}

document.getElementById('task-modal').addEventListener('click', function(e) {
    if (e.target === this) closeTaskModal();
});

async function cancelTask(taskId) {
    if (!confirm('确认取消任务「' + taskId + '」？')) return;
    try {
        var result = await apiPost('task_cancel', { task_id: taskId });
        showToast(result.ok ? '任务已取消' : '取消失败：' + (result.msg || ''), result.ok ? 'success' : 'error');
        if (result.ok) setTimeout(function() { location.reload(); }, 1000);
    } catch (e) {
        showToast('取消失败：' + e.message, 'error');
    }
}

async function cancelAllRunning() {
    var rows = document.querySelectorAll('#task-list tr[data-task-id]');
    var cancelled = 0;
    for (var i = 0; i < rows.length; i++) {
        var statusCell = rows[i].querySelector('td:nth-child(3)');
        if (!statusCell) continue;
        var statusText = statusCell.textContent || '';
        if (statusText.indexOf('等待中') !== -1 || statusText.indexOf('运行中') !== -1) {
            var taskId = rows[i].getAttribute('data-task-id');
            try {
                await apiPost('task_cancel', { task_id: taskId });
                cancelled++;
            } catch (e) {}
        }
    }
    showToast('已取消 ' + cancelled + ' 个任务', 'success');
    setTimeout(function() { location.reload(); }, 1000);
}
</script>

<?php require_once __DIR__ . '/shared/footer.php'; ?>
