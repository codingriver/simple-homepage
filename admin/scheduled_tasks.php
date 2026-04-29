<?php
/**
 * admin/scheduled_tasks.php — 计划任务管理
 * 新建/编辑通过弹窗操作，含启禁切换、下次运行时间、日志分页查看
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/shared/functions.php';
    require_once __DIR__ . '/shared/cron_lib.php';
    $user = auth_get_current_user();
    if (!$user || ($user['role'] ?? '') !== 'admin') {
        header('Location: /login.php'); exit;
    }
    csrf_check();
    $action = $_POST['action'] ?? '';

    /* ------ 保存任务 ------ */
    if ($action === 'task_save') {
        $lock = scheduled_tasks_lock_exclusive();
        $data  = load_scheduled_tasks();
        $id    = trim((string)($_POST['id']       ?? ''));
        $name  = trim((string)($_POST['name']     ?? ''));
        $sched = trim((string)($_POST['schedule'] ?? ''));
        $cmd   = task_normalize_editor_contents((string)($_POST['command'] ?? ''));
        $en    = !empty($_POST['enabled']);
        if ($id === '') {
            $id = task_allocate_next_id();
            if ($id === '') {
                scheduled_tasks_unlock($lock);
                flash_set('error', '任务 ID 分配失败，请检查 tasks 目录');
                header('Location: scheduled_tasks.php'); exit;
            }
        }
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $id)) {
            scheduled_tasks_unlock($lock);
            flash_set('error', '任务 ID 仅允许字母数字、下划线、短横线');
            header('Location: scheduled_tasks.php'); exit;
        }
        if ($name === '') {
            scheduled_tasks_unlock($lock);
            flash_set('error', '请填写任务名称');
            header('Location: scheduled_tasks.php'); exit;
        }
        if (cron_is_ddns_dispatcher_id($id)) {
            scheduled_tasks_unlock($lock);
            flash_set('error', 'DDNS 调度器由系统自动维护，不能手动编辑');
            header('Location: scheduled_tasks.php'); exit;
        }
        if (!cron_validate_schedule($sched)) {
            scheduled_tasks_unlock($lock);
            flash_set('error', '执行周期格式无效：' . htmlspecialchars($sched) . ' 不是合法的 5 段 Cron 表达式（如 */5 * * * *）');
            header('Location: scheduled_tasks.php'); exit;
        }
        $found = false;
        $taskRow = null;
        foreach ($data['tasks'] as &$t) {
            if (($t['id'] ?? '') === $id) {
                $t['name'] = $name; $t['enabled'] = $en;
                $t['schedule'] = $sched; $t['command'] = $cmd;
                unset($t['working_dir_mode'], $t['working_dir']);
                $taskRow = $t;
                $found = true; break;
            }
        }
        unset($t);
        if (!$found) {
            $taskRow = ['id' => $id, 'name' => $name,
                'enabled' => $en, 'schedule' => $sched, 'command' => $cmd, 'created_at' => date('Y-m-d H:i:s')];
            array_unshift($data['tasks'], $taskRow);
        }
        if (is_array($taskRow)) {
            $scriptFilename = task_resolve_script_filename($taskRow, $data['tasks']);
            foreach ($data['tasks'] as &$t) {
                if (($t['id'] ?? '') !== $id) {
                    continue;
                }
                $t['script_filename'] = $scriptFilename;
                $taskRow = $t;
                break;
            }
            unset($t);
        }
        task_ensure_workdir(['id' => $id]);
        $scriptSync = task_sync_script_for_task($taskRow ?? ['id' => $id, 'name' => $name, 'command' => $cmd], $data['tasks']);
        if (!$scriptSync['ok']) {
            scheduled_tasks_unlock($lock);
            flash_set('error', $scriptSync['msg']);
            header('Location: scheduled_tasks.php'); exit;
        }
        save_scheduled_tasks($data, $lock);
        scheduled_tasks_unlock($lock);
        $r = cron_regenerate();
        flash_set($r['ok'] ? 'success' : 'error', $r['ok'] ? '已保存并更新 crontab' : $r['msg']);
        header('Location: scheduled_tasks.php'); exit;
    }

    /* ------ 删除任务 ------ */
    if ($action === 'task_delete') {
        $id = trim((string)($_POST['id'] ?? ''));
        if (cron_is_ddns_dispatcher_id($id)) {
            flash_set('error', 'DDNS 调度器由系统自动维护，不能手动删除');
            header('Location: scheduled_tasks.php'); exit;
        }
        $data = load_scheduled_tasks();
        $deleted = null;
        foreach ($data['tasks'] ?? [] as $row) {
            if (($row['id'] ?? '') === $id) {
                $deleted = $row;
                break;
            }
        }
        $data['tasks'] = array_values(array_filter(
            $data['tasks'] ?? [], fn($t) => ($t['id'] ?? '') !== $id));
        save_scheduled_tasks($data);
        if (is_array($deleted)) {
            task_cleanup_on_delete($deleted);
        }
        $r = cron_regenerate();
        flash_set($r['ok'] ? 'success' : 'warn',
            $r['ok'] ? '已删除' : ('已删除但 crontab 更新失败：' . $r['msg']));
        header('Location: scheduled_tasks.php'); exit;
    }

    /* ------ 立即执行 ------ */
    if ($action === 'task_run') {
        $id = trim((string)($_POST['id'] ?? ''));
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        @set_time_limit(0);
        $ex = cron_dispatch_task_async($id);
        flash_set($ex['ok'] ? 'success' : 'warn', $ex['msg']);
        header('Location: scheduled_tasks.php'); exit;
    }

    /* ------ 停止任务 ------ */
    if ($action === 'task_stop') {
        $id = trim((string)($_POST['id'] ?? ''));
        $r = task_stop($id);
        flash_set($r['ok'] ? 'success' : 'error', $r['msg']);
        header('Location: scheduled_tasks.php'); exit;
    }

    /* ------ 启用 / 禁用切换 ------ */
    if ($action === 'task_toggle') {
        $id  = trim((string)($_POST['id'] ?? ''));
        if (cron_is_ddns_dispatcher_id($id)) {
            flash_set('error', 'DDNS 调度器由系统自动维护，不能手动启停');
            header('Location: scheduled_tasks.php'); exit;
        }
        $new = task_toggle_enabled($id);
        flash_set('success', $new === null ? '任务不存在' : ($new ? '已启用' : '已禁用'));
        header('Location: scheduled_tasks.php'); exit;
    }

    /* ------ 清空日志 ------ */
    if ($action === 'task_log_clear') {
        $id = trim((string)($_POST['id'] ?? ''));
        task_clear_log($id);
        if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest') {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => true, 'msg' => '日志已清空'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        flash_set('success', '日志已清空');
        header('Location: scheduled_tasks.php'); exit;
    }

}

$page_title = '计划任务';
require_once __DIR__ . '/shared/header.php';
require_once __DIR__ . '/shared/cron_lib.php';
require_once __DIR__ . '/shared/ddns_lib.php';

$ddns_dispatcher = cron_sync_ddns_dispatcher_task();
$tasks = task_sort_for_display(load_scheduled_tasks()['tasks'] ?? []);
foreach ($tasks as &$_t) {
    $_t['command'] = task_resolve_command_text($_t);
    $_t['_is_system'] = cron_is_system_task($_t);
    $_t['_running'] = cron_task_is_running($_t);
    $_t['_started_at'] = (string)(cron_task_runtime($_t)['started_at'] ?? '');
    $_t['_next'] = (!empty($_t['enabled']) && !empty($_t['schedule']))
        ? (cron_next_run($_t['schedule']) ?: '-')
        : '-';
    $_t['_workdir'] = task_resolve_workdir($_t);
    $_t['_workdir_mode_label'] = '任务目录';
    $_t['_script_filename'] = task_resolve_script_filename($_t, $tasks);
    $_t['_script_file'] = task_script_file_for_task($_t, $tasks);
    $_t['_log_filename'] = task_resolve_log_filename($_t, $tasks);
    $_t['_log_file'] = task_log_file_for_task($_t, $tasks);
}
unset($_t);
$manual_tasks = array_values(array_filter($tasks, fn($row) => empty($row['_is_system'])));
$system_tasks = array_values(array_filter($tasks, fn($row) => !empty($row['_is_system'])));
$default_task_command = <<<'BASH'
#!/bin/bash

# 这是默认 Bash 脚本，可以直接修改

echo "== basic commands =="
echo "hello"
pwd
python3 -c "print('hello from python3')"
python -c "print('hello from python')"

echo
echo "== bash runtime info =="
echo "whoami: $(whoami)"
echo "shell: ${SHELL:-}"
echo "pwd: $(pwd)"
echo "date: $(date '+%Y-%m-%d %H:%M:%S %Z')"

echo
echo "== custom env injected before bash =="
printf 'HOME=%s\n' "${HOME:-}"
printf 'USER=%s\n' "${USER:-}"
printf 'PATH=%s\n' "${PATH:-}"
printf 'SHELL=%s\n' "${SHELL:-}"
printf 'LANG=%s\n' "${LANG:-}"
printf 'TASK_ID=%s\n' "${TASK_ID:-}"
printf 'TASK_NAME=%s\n' "${TASK_NAME:-}"
printf 'TASK_WORKDIR=%s\n' "${TASK_WORKDIR:-}"
printf 'TASK_SCRIPT_FILE=%s\n' "${TASK_SCRIPT_FILE:-}"
printf 'TASK_LOG_FILE=%s\n' "${TASK_LOG_FILE:-}"

echo
echo "== all environment variables (sorted) =="
env | sort
BASH;
$CSRF = csrf_field();
?>

<!-- ===== 工具栏 ===== -->
<div class="toolbar">
  <button type="button" class="btn btn-primary" onclick="openTaskModal(null)">＋ 新建任务</button>
  <span style="color:var(--tm);font-size:12px">管理员可执行任意 shell，请自行评估风险。</span>
</div>

<div class="card" style="margin-bottom:16px;padding:12px 14px">
  <div role="tablist" aria-label="计划任务页签" style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
    <button type="button" class="btn btn-secondary" id="scheduled-tab-btn-tasks" role="tab" aria-controls="scheduled-tab-panel-tasks" aria-selected="true" onclick="switchScheduledTab('tasks')" style="padding:12px 14px;font-weight:700">
      左侧页签 · 手动任务
    </button>
    <button type="button" class="btn btn-secondary" id="scheduled-tab-btn-ddns" role="tab" aria-controls="scheduled-tab-panel-ddns" aria-selected="false" onclick="switchScheduledTab('ddns')" style="padding:12px 14px;font-weight:700">
      右侧页签 · DDNS 调度器
    </button>
  </div>
</div>

<section id="scheduled-tab-panel-tasks" role="tabpanel" aria-labelledby="scheduled-tab-btn-tasks" data-tab-panel="tasks">
  <div class="card" style="margin-bottom:16px">
    <div class="card-title">手动任务</div>
    <div style="color:var(--tm);font-size:12px;line-height:1.8">这里用于创建和运行普通 Shell 计划任务。DDNS 自动调度已拆分到右侧页签。</div>
  </div>

  <div class="card">
<?php if (empty($manual_tasks)): ?>
  <p style="color:var(--tm);font-size:13px">暂无手动任务，点击「新建任务」创建第一条。</p>
<?php else: ?>
  <div class="table-wrap"><table>
    <thead><tr>
      <th>名称</th>
      <th>Cron 表达式</th>
      <th>状态</th>
      <th>下次运行</th>
      <th>上次运行</th>
      <th>退出码</th>
      <th style="min-width:260px">操作</th>
    </tr></thead>
    <tbody>
    <?php foreach ($manual_tasks as $t):
        $enabled   = !empty($t['enabled']);
        $exitCode  = $t['last_code'] ?? null;
        $exitBadge = $exitCode === null ? '—' :
            ($exitCode === 0
                ? '<span class="badge badge-green">0</span>'
                : '<span class="badge badge-red">' . (int)$exitCode . '</span>');
    ?>
    <tr data-task-row data-task-id="<?= htmlspecialchars($t['id'] ?? '') ?>" data-task-system="0">
      <td style="font-weight:600">
        <?= htmlspecialchars($t['name'] ?? '') ?>
        <?php if (!empty($t['_is_system'])): ?>
          <div style="margin-top:6px;display:flex;gap:6px;flex-wrap:wrap;align-items:center">
            <span class="badge badge-purple">系统任务</span>
            <?php if (!empty($t['meta']['group_label'])): ?>
              <span style="font-size:11px;color:var(--tx2)">包含：<?= htmlspecialchars((string)$t['meta']['group_label']) ?></span>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </td>
      <td><code><?= htmlspecialchars($t['schedule'] ?? '') ?></code></td>
      <td data-task-status-cell>
        <span class="badge <?= $enabled ? 'badge-green' : 'badge-gray' ?>" data-task-enabled-badge>
          <?= $enabled ? '启用' : '禁用' ?>
        </span>
      </td>
      <td style="font-size:12px;font-family:var(--mono);color:var(--tx2)" data-task-next>
        <?= htmlspecialchars($t['_next']) ?>
      </td>
      <td style="font-size:12px;font-family:var(--mono);color:var(--tx2)" data-task-last-run>
        <?= htmlspecialchars($t['last_run'] ?? '—') ?>
      </td>
      <td data-task-exit><?= $exitBadge ?></td>
      <td style="white-space:nowrap">

        <!-- 启用 / 禁用 -->
        <?php if (empty($t['_is_system'])): ?>
        <form method="POST" style="display:inline">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="task_toggle">
          <input type="hidden" name="id" value="<?= htmlspecialchars($t['id'] ?? '') ?>">
          <button type="submit"
            data-task-toggle-btn
            class="btn btn-sm <?= $enabled ? 'btn-secondary' : 'btn-secondary' ?>"
            style="<?= $enabled
              ? 'color:var(--yellow);border-color:rgba(255,204,68,.35)'
              : 'color:var(--green);border-color:rgba(61,255,160,.35)' ?>">
            <?= $enabled ? '⏸ 禁用' : '▶ 启用' ?>
          </button>
        </form>
        <?php else: ?>
        <button type="button" class="btn btn-sm btn-secondary" disabled style="opacity:.55;cursor:not-allowed">自动维护</button>
        <?php endif; ?>

        <!-- 编辑 -->
        <?php if (empty($t['_is_system'])): ?>
        <button type="button" class="btn btn-sm btn-secondary"
          onclick='openTaskModal(<?= htmlspecialchars(json_encode($t, JSON_UNESCAPED_UNICODE|JSON_HEX_APOS|JSON_HEX_TAG), ENT_QUOTES) ?>)'>
          ✏ 编辑
        </button>
        <?php else: ?>
        <button type="button" class="btn btn-sm btn-secondary" disabled style="opacity:.55;cursor:not-allowed">✏ 系统维护</button>
        <?php endif; ?>

        <!-- 停止 -->
        <?php if (!empty($t['_running'])): ?>
        <form method="POST" style="display:inline"
          onsubmit="return confirm('确定停止任务「<?= htmlspecialchars($t['name'] ?? '', ENT_QUOTES) ?>」？');">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="task_stop">
          <input type="hidden" name="id" value="<?= htmlspecialchars($t['id'] ?? '') ?>">
          <button type="submit" class="btn btn-sm btn-danger" data-task-stop-btn>⏹ 停止</button>
        </form>
        <?php endif; ?>

        <!-- 立即执行 -->
        <form method="POST" style="display:inline">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="task_run">
          <input type="hidden" name="id" value="<?= htmlspecialchars($t['id'] ?? '') ?>">
          <button type="submit" class="btn btn-sm btn-secondary" data-task-run-btn <?= !empty($t['_running']) ? 'disabled style="opacity:.55;cursor:not-allowed"' : '' ?>><?= !empty($t['_running']) ? '运行中' : '▶▶ 立即执行' ?></button>
        </form>

        <!-- 查看日志 -->
        <button type="button" class="btn btn-sm btn-secondary"
          onclick="openLogModal(<?= htmlspecialchars(json_encode($t['id'] ?? ''), ENT_QUOTES) ?>,
                                 <?= htmlspecialchars(json_encode($t['name'] ?? '', JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>)">
          📋 日志
        </button>

        <!-- 删除 -->
        <?php if (empty($t['_is_system'])): ?>
        <form method="POST" style="display:inline"
          onsubmit="return confirmDeleteTask(<?= htmlspecialchars(json_encode($t['name'] ?? '', JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>)">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="task_delete">
          <input type="hidden" name="id" value="<?= htmlspecialchars($t['id'] ?? '') ?>">
          <button type="submit" class="btn btn-sm btn-danger">✕ 删除</button>
        </form>
        <?php else: ?>
        <button type="button" class="btn btn-sm btn-danger" disabled style="opacity:.55;cursor:not-allowed">✕ 系统维护</button>
        <?php endif; ?>

      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
<?php endif; ?>
  </div>
</section>

<section id="scheduled-tab-panel-ddns" role="tabpanel" aria-labelledby="scheduled-tab-btn-ddns" data-tab-panel="ddns" hidden>
  <div class="card" style="margin-bottom:16px">
    <div class="card-title">DDNS 调度器</div>
    <?php if (!empty($ddns_dispatcher['enabled'])): ?>
      <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
        <span class="badge badge-blue">已自动接入</span>
        <span style="font-family:var(--mono);font-size:12px;color:var(--tx2)">系统分组数：<?= count($ddns_dispatcher['groups'] ?? []) ?></span>
      </div>
      <div style="margin-top:10px;color:var(--tm);font-size:12px;line-height:1.8">
        系统会按 DDNS 任务的 Cron 分组，自动生成多个调度器。每个分组只负责执行同一个 cron 下的 DDNS 任务。
      </div>
      <div style="margin-top:10px;display:grid;gap:8px">
        <?php foreach (($ddns_dispatcher['groups'] ?? []) as $cronExpr => $groupTasks): ?>
          <div style="padding:10px 12px;border:1px solid var(--bd);border-radius:10px;background:var(--sf2)">
            <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
              <span class="badge badge-purple">分组</span>
              <code><?= htmlspecialchars($cronExpr) ?></code>
              <span style="font-family:var(--mono);font-size:12px;color:var(--tx2)"><?= htmlspecialchars(cron_ddns_dispatcher_id($cronExpr)) ?></span>
              <span style="font-size:12px;color:var(--tx2)">名称：<?= htmlspecialchars('DDNS 调度器 [' . $cronExpr . ']') ?></span>
            </div>
            <div style="margin-top:6px;color:var(--tx2);font-size:12px">
              任务：<?= htmlspecialchars(implode('、', array_map(fn($row) => (string)($row['name'] ?: $row['id']), $groupTasks))) ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <div style="color:var(--tm);font-size:12px;line-height:1.7">当前没有启用的 DDNS 任务，所以不会生成 DDNS 调度器。</div>
    <?php endif; ?>
  </div>

  <div class="card">
    <div class="card-title">系统调度器列表</div>
<?php if (empty($system_tasks)): ?>
    <p style="color:var(--tm);font-size:13px">当前没有系统调度器。</p>
<?php else: ?>
    <div class="table-wrap"><table>
      <thead><tr>
        <th>名称</th>
        <th>Cron 表达式</th>
        <th>状态</th>
        <th>下次运行</th>
        <th>上次运行</th>
        <th>退出码</th>
        <th style="min-width:260px">操作</th>
      </tr></thead>
      <tbody>
      <?php foreach ($system_tasks as $t):
          $enabled   = !empty($t['enabled']);
          $exitCode  = $t['last_code'] ?? null;
          $exitBadge = $exitCode === null ? '—' :
              ($exitCode === 0
                  ? '<span class="badge badge-green">0</span>'
                  : '<span class="badge badge-red">' . (int)$exitCode . '</span>');
      ?>
      <tr data-task-row data-task-id="<?= htmlspecialchars($t['id'] ?? '') ?>" data-task-system="1">
        <td style="font-weight:600">
          <?= htmlspecialchars($t['name'] ?? '') ?>
          <div style="margin-top:6px;display:flex;gap:6px;flex-wrap:wrap;align-items:center">
            <span class="badge badge-purple">系统任务</span>
            <?php if (!empty($t['meta']['group_label'])): ?>
              <span style="font-size:11px;color:var(--tx2)">包含：<?= htmlspecialchars((string)$t['meta']['group_label']) ?></span>
            <?php endif; ?>
          </div>
        </td>
        <td><code><?= htmlspecialchars($t['schedule'] ?? '') ?></code></td>
        <td data-task-status-cell>
          <span class="badge <?= $enabled ? 'badge-green' : 'badge-gray' ?>" data-task-enabled-badge>
            <?= $enabled ? '启用' : '禁用' ?>
          </span>
        </td>
        <td style="font-size:12px;font-family:var(--mono);color:var(--tx2)" data-task-next>
          <?= htmlspecialchars($t['_next']) ?>
        </td>
        <td style="font-size:12px;font-family:var(--mono);color:var(--tx2)" data-task-last-run>
          <?= htmlspecialchars($t['last_run'] ?? '—') ?>
        </td>
        <td data-task-exit><?= $exitBadge ?></td>
        <td style="white-space:nowrap">
          <button type="button" class="btn btn-sm btn-secondary" disabled style="opacity:.55;cursor:not-allowed">自动维护</button>
          <button type="button" class="btn btn-sm btn-secondary" disabled style="opacity:.55;cursor:not-allowed">✏ 系统维护</button>
          <?php if (!empty($t['_running'])): ?>
          <form method="POST" style="display:inline"
            onsubmit="return confirm('确定停止任务「<?= htmlspecialchars($t['name'] ?? '', ENT_QUOTES) ?>」？');">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="task_stop">
            <input type="hidden" name="id" value="<?= htmlspecialchars($t['id'] ?? '') ?>">
            <button type="submit" class="btn btn-sm btn-danger" data-task-stop-btn>⏹ 停止</button>
          </form>
          <?php endif; ?>
          <form method="POST" style="display:inline">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="task_run">
            <input type="hidden" name="id" value="<?= htmlspecialchars($t['id'] ?? '') ?>">
            <button type="submit" class="btn btn-sm btn-secondary" data-task-run-btn <?= !empty($t['_running']) ? 'disabled style="opacity:.55;cursor:not-allowed"' : '' ?>><?= !empty($t['_running']) ? '运行中' : '▶▶ 立即执行' ?></button>
          </form>
          <button type="button" class="btn btn-sm btn-secondary"
            onclick="openLogModal(<?= htmlspecialchars(json_encode($t['id'] ?? ''), ENT_QUOTES) ?>,
                                   <?= htmlspecialchars(json_encode($t['name'] ?? '', JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>)">
            📋 日志
          </button>
          <button type="button" class="btn btn-sm btn-danger" disabled style="opacity:.55;cursor:not-allowed">✕ 系统维护</button>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
<?php endif; ?>
  </div>
</section>


<!-- ===================================================
     MODAL：新建 / 编辑任务
===================================================== -->
<div id="task-modal" style="
  display:none;position:fixed;inset:0;z-index:800;
  background:rgba(0,0,0,.65);backdrop-filter:blur(4px);
  align-items:center;justify-content:center;
">
  <div style="
    background:var(--sf);border:1px solid var(--bd2);
    border-radius:var(--r2);width:min(900px,96vw);
    box-shadow:0 24px 64px rgba(0,0,0,.5);
    display:flex;flex-direction:column;max-height:90vh;
  ">
    <!-- header -->
    <div style="padding:10px 16px 8px;border-bottom:1px solid var(--bd);display:flex;align-items:center;justify-content:space-between">
      <span id="modal-title" style="font-weight:700;font-size:15px;font-family:var(--mono);color:var(--ac)">新建任务</span>
      <div style="display:flex;gap:10px;align-items:center">
        <button type="submit" form="task-form" class="btn btn-primary">💾 保存</button>
        <button onclick="closeTaskModal()" style="background:none;border:none;color:var(--tm);cursor:pointer;font-size:18px;line-height:1;padding:2px 6px">✕</button>
      </div>
    </div>
    <!-- body -->
    <div style="padding:14px 16px;overflow-y:auto;flex:1">
      <form method="POST" id="task-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="task_save">
        <input type="hidden" name="id"     id="fm-id" value="">

        <div class="form-grid">
          <!-- 名称 -->
          <div class="form-group">
            <label>任务名称 *</label>
            <input type="text" name="name" id="fm-name" required placeholder="例：清理临时文件">
          </div>
          <!-- Cron + 启用 -->
          <div class="form-group">
            <label>Cron 表达式 *（五段）</label>
            <input type="text" name="schedule" id="fm-schedule" required
              placeholder="*/5 * * * *"
              style="font-family:var(--mono)">
            <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;margin-top:6px">
              <span class="form-hint" id="fm-next-tip" style="color:var(--ac);font-family:var(--mono);margin:0"></span>
              <label style="display:flex;align-items:center;gap:8px;cursor:pointer;
                font-size:13px;text-transform:none;letter-spacing:0;font-weight:500;color:var(--tx);white-space:nowrap;flex-shrink:0">
                <input type="checkbox" name="enabled" value="1" id="fm-enabled"
                  style="width:16px;height:16px;accent-color:var(--ac)">
                启用此任务
              </label>
            </div>
          </div>
        </div>

        <!-- 命令（20行可滚动）-->
        <div class="form-group" style="margin-top:10px">
          <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:6px">
            <label style="margin:0">命令 / 脚本</label>
            <button type="button" class="btn btn-sm btn-secondary" onclick="openTaskCommandEditor()">📝 打开编辑器</button>
          </div>
          <textarea name="command" id="fm-command" rows="20"
            placeholder="# 新建任务时会自动填充默认 bash 脚本"
            style="font-family:var(--mono);font-size:12px;resize:vertical;
                   min-height:120px;max-height:400px;overflow-y:auto;line-height:1.55"></textarea>
          <span class="form-hint">保存时会直接把这里的文本写入上面的脚本文件；执行时等价于 <code style="font-family:var(--mono)">/bin/bash script.sh &gt;&gt; data/tasks/同名.log 2&gt;&amp;1</code>。脚本文件默认不删除，后续保存同一个任务时只更新这个固定脚本文件。如果要运行二进制，请直接写 <code style="font-family:var(--mono)">./your-binary args</code> 或绝对路径，不要写成 <code style="font-family:var(--mono)">bash your-binary</code>。DDNS 可调用本机 <code style="font-family:var(--mono)">http://127.0.0.1/api/dns.php</code>，说明见「域名解析」页底部。</span>
          <span class="form-hint" id="fm-workdir-hint" style="display:none">
            工作目录：<code id="fm-workdir" style="font-family:var(--mono)"></code>
          </span>
        </div>
      </form>
    </div>
  </div>
</div>




<script>
var TASK_ROWS = <?= json_encode($tasks, JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_APOS) ?>;
var CSRF_TOKEN = <?= json_encode($GLOBALS['_nav_csrf_token'] ?? '') ?>;
var DEFAULT_TASK_COMMAND = <?= json_encode($default_task_command, JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_APOS) ?>;
var TASKS_ROOT = '/var/www/nav/data/tasks';
var logPollTimer = 0;
var taskStatusPollTimer = 0;
var taskStatusPollInFlight = false;
var taskStatusPollingStopped = false;
var scheduledTabState = { active: 'tasks' };

function switchScheduledTab(tab) {
  scheduledTabState.active = tab === 'ddns' ? 'ddns' : 'tasks';
  ['tasks', 'ddns'].forEach(function(name) {
    var btn = document.getElementById('scheduled-tab-btn-' + name);
    var panel = document.getElementById('scheduled-tab-panel-' + name);
    var active = name === scheduledTabState.active;
    if (btn) {
      btn.setAttribute('aria-selected', active ? 'true' : 'false');
      btn.style.borderColor = active ? 'var(--ac)' : 'var(--bd)';
      btn.style.color = active ? 'var(--ac)' : 'var(--tx2)';
      btn.style.background = active ? 'rgba(61,255,160,.08)' : 'var(--sf2)';
    }
    if (panel) {
      panel.hidden = !active;
    }
  });
}

/* ---- 任务弹窗 ---- */
function openTaskModal(task) {
  var m = document.getElementById('task-modal');
  var isNew = !task || !task.id;
  window._currentEditingTask = task || {};
  document.getElementById('modal-title').textContent = isNew ? '新建任务' : '编辑任务';
  document.getElementById('fm-id').value       = isNew ? ''   : (task.id       || '');
  document.getElementById('fm-name').value     = isNew ? ''   : (task.name     || '');
  document.getElementById('fm-schedule').value = isNew ? '*/5 * * * *' : (task.schedule || '');
  document.getElementById('fm-command').value  = isNew ? DEFAULT_TASK_COMMAND : (task.command || '');
  document.getElementById('fm-enabled').checked = isNew ? true  : !!task.enabled;

  // 工作目录说明
  var workdirHint = document.getElementById('fm-workdir-hint');
  var workdirEl   = document.getElementById('fm-workdir');
  if (!isNew && task._workdir) {
    workdirEl.textContent = task._workdir;
    workdirHint.style.display = '';
  } else {
    workdirEl.textContent = '';
    workdirHint.style.display = 'none';
  }

  updateNextTip();
  m.style.display = 'flex';
  setTimeout(function(){ document.getElementById('fm-name').focus(); }, 80);
}
function closeTaskModal() {
  document.getElementById('task-modal').style.display = 'none';
}

function openTaskCommandEditor() {
  var content = document.getElementById('fm-command').value || '';
  var task = window._currentEditingTask || {};
  var taskName = document.getElementById('fm-name').value || task.name || '';
  var scriptFile = task._script_file || '';
  var title = '编辑计划任务脚本';
  if (taskName) title += ' · ' + taskName;
  if (scriptFile) title += ' · ' + scriptFile;
  NavAceEditor.open({
    title: title,
    mode: 'sh',
    value: content,
    wrapMode: true,
    buttons: {
      left: [{ type: 'dirty' }],
      right: [
        { text: '关闭', class: 'btn-secondary', action: 'close' },
        { text: '保存', class: 'btn-primary', action: 'save' }
      ]
    },
    onAction: function(action, value) {
      if (action === 'close') {
        NavAceEditor.close();
        return;
      }
      if (action === 'save') {
        document.getElementById('fm-command').value = value;
        NavAceEditor.markClean();
        NavAceEditor.close();
        showToast('脚本内容已更新', 'success');
      }
    }
  });
}

/* 严格验证单个 cron 字段（与后端 cron_validate_field 等价） */
function validateCronField(field, min, max) {
  if (!field || /[^0-9*,\-\/]/.test(field)) return false;
  var parts = field.split(',');
  for (var i = 0; i < parts.length; i++) {
    var part = parts[i];
    if (part === '*') continue;
    var slashIdx = part.indexOf('/');
    if (slashIdx !== -1) {
      var range = part.substring(0, slashIdx);
      var step = part.substring(slashIdx + 1);
      if (!step || !/^\d+$/.test(step) || parseInt(step, 10) < 1) return false;
      if (range !== '*') {
        var dashIdx = range.indexOf('-');
        if (dashIdx !== -1) {
          var s = parseInt(range.substring(0, dashIdx), 10);
          var e = parseInt(range.substring(dashIdx + 1), 10);
          if (isNaN(s) || isNaN(e) || s < min || e > max || s > e) return false;
        } else {
          var v = parseInt(range, 10);
          if (isNaN(v) || v < min || v > max) return false;
        }
      }
    } else if (part.indexOf('-') !== -1) {
      var dashIdx = part.indexOf('-');
      var s = parseInt(part.substring(0, dashIdx), 10);
      var e = parseInt(part.substring(dashIdx + 1), 10);
      if (isNaN(s) || isNaN(e) || s < min || e > max || s > e) return false;
    } else {
      var v = parseInt(part, 10);
      if (isNaN(v) || v < min || v > max) return false;
    }
  }
  return true;
}

/* 严格验证 5 段 Cron 表达式 */
function validateCronSchedule(expr) {
  var v = (expr || '').trim();
  if (!v || /[\r\n]/.test(v)) return { ok: false, msg: '不能为空' };
  var parts = v.split(/\s+/);
  if (parts.length !== 5) return { ok: false, msg: '需 5 个时间字段（分 时 日 月 周）' };
  var ranges = [[0,59,'分'], [0,23,'时'], [1,31,'日'], [1,12,'月'], [0,6,'周']];
  for (var i = 0; i < 5; i++) {
    if (!validateCronField(parts[i], ranges[i][0], ranges[i][1])) {
      return { ok: false, msg: '第 ' + (i + 1) + ' 段（' + ranges[i][2] + '）格式无效：' + parts[i] };
    }
  }
  return { ok: true };
}

/* 实时预览下次运行时间 */
function updateNextTip() {
  var tip = document.getElementById('fm-next-tip');
  var v = (document.getElementById('fm-schedule').value || '').trim();
  if (v === '') {
    tip.textContent = '';
    return;
  }
  var result = validateCronSchedule(v);
  if (result.ok) {
    tip.textContent = '✓ Cron 格式合法';
    tip.style.color = 'var(--ac)';
  } else {
    tip.textContent = '⚠ ' + result.msg;
    tip.style.color = 'var(--yellow)';
  }
}

function taskExitBadgeHtml(code) {
  if (code === null || code === undefined || code === '') {
    return '—';
  }
  var num = Number(code);
  if (!Number.isFinite(num)) {
    return '—';
  }
  if (num === 0) {
    return '<span class="badge badge-green">0</span>';
  }
  return '<span class="badge badge-red">' + String(Math.trunc(num)) + '</span>';
}

function stopTaskStatusPolling() {
  taskStatusPollingStopped = true;
  if (taskStatusPollTimer) {
    clearTimeout(taskStatusPollTimer);
    taskStatusPollTimer = 0;
  }
}

function scheduleTaskStatusPoll(delay) {
  if (taskStatusPollingStopped) return;
  if (taskStatusPollTimer) {
    clearTimeout(taskStatusPollTimer);
  }
  taskStatusPollTimer = window.setTimeout(pollTaskStatuses, delay);
}

function updateTaskRowStatus(task) {
  if (!task || !task.id) return;
  var row = document.querySelector('[data-task-row][data-task-id="' + String(task.id) + '"]');
  if (!row) return;

  var enabledBadge = row.querySelector('[data-task-enabled-badge]');
  var nextCell = row.querySelector('[data-task-next]');
  var lastRunCell = row.querySelector('[data-task-last-run]');
  var exitCell = row.querySelector('[data-task-exit]');
  var runBtn = row.querySelector('[data-task-run-btn]');
  var stopBtn = row.querySelector('[data-task-stop-btn]');
  var toggleBtn = row.querySelector('[data-task-toggle-btn]');

  if (enabledBadge) {
    enabledBadge.textContent = task.enabled ? '启用' : '禁用';
    enabledBadge.className = 'badge ' + (task.enabled ? 'badge-green' : 'badge-gray');
  }
  if (nextCell) {
    nextCell.textContent = task.next || '-';
  }
  if (lastRunCell) {
    lastRunCell.textContent = task.last_run || '—';
  }
  if (exitCell) {
    exitCell.innerHTML = taskExitBadgeHtml(task.last_code);
  }
  if (runBtn) {
    runBtn.disabled = !!task.running;
    runBtn.textContent = task.running ? '运行中' : '▶▶ 立即执行';
    if (task.running) {
      runBtn.style.opacity = '.55';
      runBtn.style.cursor = 'not-allowed';
    } else {
      runBtn.style.opacity = '';
      runBtn.style.cursor = '';
    }
  }
  // 停止按钮：运行中显示，不在运行中移除
  if (task.running) {
    if (!stopBtn && runBtn) {
      var stopForm = document.createElement('form');
      stopForm.method = 'POST';
      stopForm.style.display = 'inline';
      stopForm.onsubmit = function() {
        return confirm('确定停止任务？');
      };
      stopForm.innerHTML =
        '<input type="hidden" name="_csrf" value="' + String(CSRF_TOKEN).replace(/&/g,'&amp;').replace(/"/g,'&quot;') + '">' +
        '<input type="hidden" name="action" value="task_stop">' +
        '<input type="hidden" name="id" value="' + String(task.id).replace(/&/g,'&amp;').replace(/"/g,'&quot;') + '">' +
        '<button type="submit" class="btn btn-sm btn-danger" data-task-stop-btn>⏹ 停止</button>';
      runBtn.parentNode.insertBefore(stopForm, runBtn);
    }
  } else {
    if (stopBtn) {
      var form = stopBtn.closest('form');
      if (form) form.remove();
      else stopBtn.remove();
    }
  }
  if (toggleBtn) {
    toggleBtn.textContent = task.enabled ? '⏸ 禁用' : '▶ 启用';
    toggleBtn.style.color = task.enabled ? 'var(--yellow)' : 'var(--green)';
    toggleBtn.style.borderColor = task.enabled ? 'rgba(255,204,68,.35)' : 'rgba(61,255,160,.35)';
  }

  for (var i = 0; i < TASK_ROWS.length; i++) {
    if ((TASK_ROWS[i] && TASK_ROWS[i].id) === task.id) {
      TASK_ROWS[i].enabled = !!task.enabled;
      TASK_ROWS[i].last_run = task.last_run || '';
      TASK_ROWS[i].last_code = task.last_code;
      TASK_ROWS[i]._running = !!task.running;
      TASK_ROWS[i]._next = task.next || '-';
      if (!TASK_ROWS[i].runtime || typeof TASK_ROWS[i].runtime !== 'object') {
        TASK_ROWS[i].runtime = {};
      }
      TASK_ROWS[i].runtime.running = !!task.running;
      TASK_ROWS[i].runtime.started_at = task.started_at || '';
      break;
    }
  }
}

function pollTaskStatuses() {
  if (taskStatusPollingStopped) return;
  if (taskStatusPollInFlight) {
    scheduleTaskStatusPoll(200);
    return;
  }
  var ids = Array.from(document.querySelectorAll('[data-task-row][data-task-id]'))
    .map(function(row) { return row.getAttribute('data-task-id') || ''; })
    .filter(function(id) { return id !== ''; });
  if (!ids.length) {
    scheduleTaskStatusPoll(1000);
    return;
  }

  taskStatusPollInFlight = true;
  fetch('api/task_status.php?ids=' + encodeURIComponent(ids.join(',')), { credentials: 'same-origin' })
    .then(function(r) { return r.json(); })
    .then(function(payload) {
      var tasks = payload && payload.tasks ? payload.tasks : {};
      Object.keys(tasks).forEach(function(id) {
        updateTaskRowStatus(tasks[id]);
      });
    })
    .catch(function() {})
    .then(function() {
      taskStatusPollInFlight = false;
      scheduleTaskStatusPoll(1000);
    });
}

document.addEventListener('DOMContentLoaded', function(){
  var inp = document.getElementById('fm-schedule');
  if (inp) {
    inp.addEventListener('input', updateNextTip);
    inp.addEventListener('blur', function() {
      var v = (inp.value || '').trim();
      if (!v) return;
      var result = validateCronSchedule(v);
      if (!result.ok) {
        showToast('Cron 表达式无效：' + result.msg, 'error');
        inp.style.borderColor = 'var(--red)';
      } else {
        inp.style.borderColor = '';
      }
    });
  }
  switchScheduledTab('tasks');
  taskStatusPollingStopped = false;
  pollTaskStatuses();
  document.addEventListener('submit', stopTaskStatusPolling, true);
  window.addEventListener('beforeunload', stopTaskStatusPolling);
  window.addEventListener('pagehide', stopTaskStatusPolling);
  // 按 ESC 关闭弹窗
  document.addEventListener('keydown', function(e){
    if (e.key === 'Escape') { closeTaskModal(); closeLogModal(); }
  });
});

/* ---- 日志弹窗 ---- */
var logState = { id: '', name: '', page: 1, pages: 1, requestSeq: 0 };

function openLogModal(id, name) {
  logState = { id: id, name: name, page: 1, pages: 1, requestSeq: 0 };
  if (logPollTimer) clearInterval(logPollTimer);

  var footerHtml = '<div style="display:flex;align-items:center;justify-content:flex-end;gap:6px;width:100%">'
    + '<span id="st-log-info" style="font-size:12px;color:var(--tm);font-family:var(--mono)">加载中…</span>'
    + '<button type="button" class="btn btn-sm btn-secondary" id="st-log-prev" onclick="logLoadPage(logState.page-1, false)">◀ 上一页</button>'
    + '<span id="st-log-page-label" style="font-size:12px;font-family:var(--mono);color:var(--tx2)">第 1 / 1 页</span>'
    + '<button type="button" class="btn btn-sm btn-secondary" id="st-log-next" onclick="logLoadPage(logState.page+1, false)">下一页 ▶</button>'
    + '<button type="button" class="btn btn-sm btn-secondary" onclick="logLoadPage(1, false)" title="第一页">⏮</button>'
    + '<button type="button" class="btn btn-sm btn-secondary" id="st-log-last-btn" onclick="logLoadPage(logState.pages, false)" title="最后一页">⏭</button>'
    + '</div>';

  NavAceEditor.open({
    title: '运行日志 · ' + name,
    mode: 'text',
    value: '加载中…',
    readOnly: true,
    wrapMode: true,
    footerHtml: footerHtml,
    buttons: {
      left: [{ text: '🗑 清空日志', bgColor: '#e74c3c', action: 'clear' }],
      right: [{ text: '关闭', class: 'btn-secondary', action: 'close' }]
    },
    onAction: function(action) {
      if (action === 'close') {
        NavAceEditor.close();
        return;
      }
      if (action === 'clear') {
        clearCurrentLog();
      }
    },
    onClose: function() {
      if (logPollTimer) {
        clearInterval(logPollTimer);
        logPollTimer = 0;
      }
    }
  });

  logPollTimer = setInterval(function() {
    if (typeof NavAceEditor === 'undefined' || !NavAceEditor.getValue) return;
    logLoadPage(logState.page || 1, false, { silent: true });
  }, 2000);
  // 先加载第1页获取总页数，再跳到最后一页
  logLoadPage(1, true, { forceScrollBottom: true });
}
function closeLogModal() {
  NavAceEditor.close();
}
/* 弹窗背景点击关闭防护（阻止 mousedown 在内容区、mouseup 在背景层的误触） */
(function(){
  var mdTarget = null;
  var taskModal = document.getElementById('task-modal');
  if (taskModal) {
    taskModal.addEventListener('mousedown', function(e){ mdTarget = e.target; });
    taskModal.addEventListener('click', function(e){
      if (e.target === taskModal && mdTarget === taskModal) closeTaskModal();
      mdTarget = null;
    });
  }
})();

function clearCurrentLog() {
  if (!logState.id) return;
  if (!confirm('确定清空当前任务日志？此操作不可恢复。')) return;
  stopTaskStatusPolling();
  NavAceEditor.setButtonDisabled('clear', true);
  fetch('scheduled_tasks.php', {
    method: 'POST',
    headers: {
      'X-Requested-With': 'XMLHttpRequest',
      'Content-Type': 'application/x-www-form-urlencoded'
    },
    body: new URLSearchParams({
      action: 'task_log_clear',
      id: logState.id,
      _csrf: CSRF_TOKEN
    })
  })
  .then(function(r){ return r.json(); })
  .then(function(data){
    NavAceEditor.setButtonDisabled('clear', false);
    if (data.ok) {
      showToast(data.msg || '日志已清空', 'success');
      logLoadPage(1, false);
    } else {
      showToast(data.msg || '清空失败', 'error');
    }
  })
  .catch(function(){
    NavAceEditor.setButtonDisabled('clear', false);
    showToast('请求失败，请检查网络', 'error');
  });
}
function confirmDeleteTask(name) {
  var lines = ['确定删除任务「' + name + '」？', '', '• 会删除该任务对应的日志', '• 不会删除共享工作目录 data/tasks'];
  lines.push('', '此操作不可恢复。');
  return confirm(lines.join('\n'));
}
function copyTaskWorkdir(path) {
  if (!path) return;
  if (navigator.clipboard && navigator.clipboard.writeText) {
    navigator.clipboard.writeText(path).then(function(){
      alert('工作目录已复制：\n' + path);
    }).catch(function(){
      window.prompt('请手动复制工作目录：', path);
    });
    return;
  }
  window.prompt('请手动复制工作目录：', path);
}

function logLoadPage(p, jumpToLast, options) {
  options = options || {};
  if (p < 1 || p > logState.pages) return;
  logState.page = p;
  var shouldStickBottom = !!options.forceScrollBottom;
  if (!options.silent) {
    NavAceEditor.setValue('加载中…');
  }

  var url = 'api/task_log.php?id=' + encodeURIComponent(logState.id) + '&page=' + p;
  var requestSeq = ++logState.requestSeq;
  fetch(url, { credentials: 'same-origin' })
    .then(function(r){ return r.json(); })
    .then(function(d) {
      if (requestSeq !== logState.requestSeq) return;
      if (d.error) {
        if (!options.silent) NavAceEditor.setValue('请求失败：' + d.error);
        return;
      }
      logState.pages = d.pages || 1;
      logState.page  = d.page  || 1;

      // 首次打开跳到最后一页
      if (jumpToLast && d.pages > 1) {
        logLoadPage(d.pages, false, { forceScrollBottom: true });
        return;
      }

      var infoEl = document.getElementById('st-log-info');
      var pageLabelEl = document.getElementById('st-log-page-label');
      var prevBtn = document.getElementById('st-log-prev');
      var nextBtn = document.getElementById('st-log-next');
      var lastBtn = document.getElementById('st-log-last-btn');

      if (infoEl) infoEl.textContent = '共 ' + d.total + ' 行，每页 100 行';
      if (pageLabelEl) pageLabelEl.textContent = '第 ' + d.page + ' / ' + d.pages + ' 页';
      if (prevBtn) prevBtn.disabled = d.page <= 1;
      if (nextBtn) nextBtn.disabled = d.page >= d.pages;
      if (lastBtn) lastBtn.disabled = d.page >= d.pages;

      if (!d.lines || d.lines.length === 0) {
        if (!options.silent) NavAceEditor.setValue('暂无日志记录');
        return;
      }
      var text = d.lines.join('\n');
      if (!options.silent) {
        NavAceEditor.setValue(text);
      } else {
        var current = NavAceEditor.getValue();
        if (current !== text) {
          NavAceEditor.setValue(text);
        }
      }
      if (shouldStickBottom && d.page >= d.pages) {
        var totalLines = text.split('\n').length;
        NavAceEditor.gotoLine(totalLines, 0, false);
      }
    })
    .catch(function(e){
      if (requestSeq !== logState.requestSeq) return;
      if (!options.silent) NavAceEditor.setValue('请求失败：' + e.message);
    });
}
</script>

<script src="assets/ace/ace.js"></script>
<script src="assets/ace/ext-searchbox.js"></script>
<?php require_once __DIR__ . '/shared/ace_editor_modal.php'; ?>

<div class="card">
  <div class="card-title" style="color:#ff9f43">⚠ 危险操作</div>
  <div class="form-hint" style="margin-bottom:12px">
    下列操作会先自动创建备份，再执行清空。清空计划任务不会删除 <code>data/tasks/</code> 目录中的其他共享文件，只会删除系统管理的任务脚本、任务日志和锁文件。
  </div>
  <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
    <form method="POST" onsubmit="return confirm('确认清空全部普通计划任务？\n\n会删除系统生成的任务脚本、任务日志、锁文件，并重新生成 crontab。\n不会删除 data/tasks 目录里的其他共享文件。');">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="clear_scheduled_tasks">
      <button class="btn btn-danger" type="submit">🗑 清空计划任务</button>
    </form>
  </div>
</div>

<?php require_once __DIR__ . '/shared/footer.php'; ?>
