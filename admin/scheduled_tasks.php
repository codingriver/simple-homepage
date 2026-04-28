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
        $data  = load_scheduled_tasks();
        $id    = trim((string)($_POST['id']       ?? ''));
        $name  = trim((string)($_POST['name']     ?? ''));
        $sched = trim((string)($_POST['schedule'] ?? ''));
        $cmd   = task_normalize_editor_contents((string)($_POST['command'] ?? ''));
        $en    = !empty($_POST['enabled']);
        if ($id === '') $id = 't_' . bin2hex(random_bytes(8));
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $id)) {
            flash_set('error', '任务 ID 仅允许字母数字、下划线、短横线');
            header('Location: scheduled_tasks.php'); exit;
        }
        if ($name === '') {
            flash_set('error', '请填写任务名称');
            header('Location: scheduled_tasks.php'); exit;
        }
        if (cron_is_ddns_dispatcher_id($id)) {
            flash_set('error', 'DDNS 调度器由系统自动维护，不能手动编辑');
            header('Location: scheduled_tasks.php'); exit;
        }
        if (!cron_validate_schedule($sched)) {
            $sched = '0 * * * *';
            $schedResetNotice = true;
        } else {
            $schedResetNotice = false;
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
            flash_set('error', $scriptSync['msg']);
            header('Location: scheduled_tasks.php'); exit;
        }
        save_scheduled_tasks($data);
        $r = cron_regenerate();
        $msg = $r['ok'] ? '已保存并更新 crontab' : $r['msg'];
        if ($r['ok'] && !empty($schedResetNotice)) {
            $msg .= '（注意：执行周期格式错误，已自动重置为每小时运行一次 0 * * * *）';
        }
        flash_set($r['ok'] ? 'success' : 'error', $msg);
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
        flash_set('success', '日志已清空');
        header('Location: scheduled_tasks.php'); exit;
    }

    /* ------ 重新安装 crontab ------ */
    if ($action === 'cron_reload') {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $r = cron_regenerate();
        flash_set($r['ok'] ? 'success' : 'error',
            $r['ok'] ? '已重新安装 crontab' : $r['msg']);
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
  <a href="task_templates.php" class="btn btn-secondary">🧱 任务模板</a>
  <form method="POST" style="display:inline">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="cron_reload">
    <button type="submit" class="btn btn-secondary">↺ 重新安装 crontab</button>
  </form>
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
      <th>工作目录</th>
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
      <td style="font-size:11px;line-height:1.5">
        <div><span class="badge badge-blue"><?= htmlspecialchars($t['_workdir_mode_label']) ?></span></div>
        <div style="margin-top:6px;font-family:var(--mono);color:var(--tx2);max-width:280px;word-break:break-all">
          <?= htmlspecialchars($t['_workdir']) ?>
        </div>
      </td>
      <td data-task-status-cell>
        <span class="badge <?= $enabled ? 'badge-green' : 'badge-gray' ?>" data-task-enabled-badge>
          <?= $enabled ? '启用' : '禁用' ?>
        </span>
        <div data-task-running-wrap style="margin-top:6px;<?= !empty($t['_running']) ? '' : 'display:none' ?>">
          <span class="badge badge-blue">运行中</span>
        </div>
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

        <!-- 复制工作目录 -->
        <button type="button" class="btn btn-sm btn-secondary"
          onclick="copyTaskWorkdir(<?= htmlspecialchars(json_encode($t['_workdir'] ?? '', JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>)">
          📁 复制目录
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
        <th>工作目录</th>
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
        <td style="font-size:11px;line-height:1.5">
          <div><span class="badge badge-blue"><?= htmlspecialchars($t['_workdir_mode_label']) ?></span></div>
          <div style="margin-top:6px;font-family:var(--mono);color:var(--tx2);max-width:280px;word-break:break-all">
            <?= htmlspecialchars($t['_workdir']) ?>
          </div>
        </td>
        <td data-task-status-cell>
          <span class="badge <?= $enabled ? 'badge-green' : 'badge-gray' ?>" data-task-enabled-badge>
            <?= $enabled ? '启用' : '禁用' ?>
          </span>
          <div data-task-running-wrap style="margin-top:6px;<?= !empty($t['_running']) ? '' : 'display:none' ?>">
            <span class="badge badge-blue">运行中</span>
          </div>
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
          <button type="button" class="btn btn-sm btn-secondary"
            onclick="copyTaskWorkdir(<?= htmlspecialchars(json_encode($t['_workdir'] ?? '', JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>)">
            📁 复制目录
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
" onclick="if(event.target===this)closeTaskModal()">
  <div style="
    background:var(--sf);border:1px solid var(--bd2);
    border-radius:var(--r2);width:min(680px,96vw);
    box-shadow:0 24px 64px rgba(0,0,0,.5);
    display:flex;flex-direction:column;max-height:90vh;
  ">
    <!-- header -->
    <div style="padding:18px 22px 14px;border-bottom:1px solid var(--bd);display:flex;align-items:center;justify-content:space-between">
      <span id="modal-title" style="font-weight:700;font-size:15px;font-family:var(--mono);color:var(--ac)">新建任务</span>
      <button onclick="closeTaskModal()" style="background:none;border:none;color:var(--tm);cursor:pointer;font-size:18px;line-height:1;padding:2px 6px">✕</button>
    </div>
    <!-- body -->
    <div style="padding:20px 22px;overflow-y:auto;flex:1">
      <form method="POST" id="task-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="task_save">
        <input type="hidden" name="id"     id="fm-id" value="">

        <div class="form-actions" style="margin-bottom:16px">
          <button type="submit" class="btn btn-primary">💾 保存</button>
          <button type="button" class="btn btn-secondary" onclick="closeTaskModal()">取消</button>
        </div>

        <div class="form-grid">
          <!-- 名称 -->
          <div class="form-group">
            <label>任务名称 *</label>
            <input type="text" name="name" id="fm-name" required placeholder="例：清理临时文件">
          </div>
          <!-- Cron -->
          <div class="form-group">
            <label>Cron 表达式 *（五段）</label>
            <input type="text" name="schedule" id="fm-schedule" required
              placeholder="*/5 * * * *"
              style="font-family:var(--mono)">
            <span class="form-hint" id="fm-next-tip" style="color:var(--ac);font-family:var(--mono)"></span>
          </div>
          <!-- 启用 -->
          <div class="form-group" style="justify-content:flex-end;padding-bottom:4px">
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;
              font-size:13px;text-transform:none;letter-spacing:0;font-weight:500;color:var(--tx)">
              <input type="checkbox" name="enabled" value="1" id="fm-enabled"
                style="width:16px;height:16px;accent-color:var(--ac)">
              启用此任务
            </label>
          </div>
        </div>

        <!-- 命令（20行可滚动）-->
        <div class="form-group" style="margin-top:14px">
          <label>命令 / 脚本</label>
          <textarea name="command" id="fm-command" rows="20"
            placeholder="# 新建任务时会自动填充默认 bash 脚本"
            style="font-family:var(--mono);font-size:12px;resize:vertical;
                   min-height:120px;max-height:400px;overflow-y:auto;line-height:1.55"></textarea>
          <span class="form-hint">保存时会直接把这里的文本写入上面的脚本文件；执行时等价于 <code style="font-family:var(--mono)">/bin/bash script.sh &gt;&gt; data/tasks/同名.log 2&gt;&amp;1</code>。脚本文件默认不删除，后续保存同一个任务时只更新这个固定脚本文件。如果要运行二进制，请直接写 <code style="font-family:var(--mono)">./your-binary args</code> 或绝对路径，不要写成 <code style="font-family:var(--mono)">bash your-binary</code>。DDNS 可调用本机 <code style="font-family:var(--mono)">http://127.0.0.1/api/dns.php</code>，说明见「域名解析」页底部。</span>
        </div>
      </form>
    </div>
  </div>
</div>


<!-- =================================================
     MODAL：运行日志
================================================== -->
<div id="log-modal" style="
  display:none;position:fixed;inset:0;z-index:900;
  background:rgba(0,0,0,.7);backdrop-filter:blur(4px);
  align-items:center;justify-content:center;
" onclick="if(event.target===this)closeLogModal()">
  <div style="
    background:var(--sf);border:1px solid var(--bd2);
    border-radius:var(--r2);width:min(860px,98vw);
    box-shadow:0 24px 64px rgba(0,0,0,.6);
    display:flex;flex-direction:column;max-height:92vh;
  ">
    <!-- header -->
    <div style="padding:16px 20px 12px;border-bottom:1px solid var(--bd);
                display:flex;align-items:center;justify-content:space-between;flex-shrink:0">
      <span id="log-modal-title" style="font-weight:700;font-size:14px;font-family:var(--mono);color:var(--blue)">运行日志</span>
      <div style="display:flex;gap:8px;align-items:center">
        <button type="button" class="btn btn-sm btn-danger" onclick="clearCurrentLog()">清空日志</button>
        <button onclick="closeLogModal()" style="background:none;border:none;color:var(--tm);cursor:pointer;font-size:18px;line-height:1;padding:2px 6px">✕</button>
      </div>
    </div>
    <!-- 日志内容 -->
    <div id="log-body" style="
      flex:1;overflow-y:auto;padding:16px 20px;
      font-family:var(--mono);font-size:12px;line-height:1.6;
      color:var(--tx2);background:var(--bg);
    ">
      <span style="color:var(--tm)">加载中…</span>
    </div>
    <!-- 分页控制 -->
    <div style="padding:12px 20px;border-top:1px solid var(--bd);display:flex;
                align-items:center;gap:12px;flex-shrink:0;flex-wrap:wrap">
      <span id="log-info" style="font-size:12px;color:var(--tm);font-family:var(--mono)"></span>
      <div style="margin-left:auto;display:flex;gap:6px;align-items:center">
        <button class="btn btn-sm btn-secondary" id="log-prev" onclick="logLoadPage(logState.page-1, false)">◀ 上一页</button>
        <span id="log-page-label" style="font-size:12px;font-family:var(--mono);color:var(--tx2)"></span>
        <button class="btn btn-sm btn-secondary" id="log-next" onclick="logLoadPage(logState.page+1, false)">下一页 ▶</button>
        <button class="btn btn-sm btn-secondary" onclick="logLoadPage(1, false)" title="第一页">⏮</button>
        <button class="btn btn-sm btn-secondary" id="log-last-btn" onclick="logLoadPage(logState.pages, false)" title="最后一页">⏭</button>
      </div>
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
  document.getElementById('modal-title').textContent = isNew ? '新建任务' : '编辑任务';
  document.getElementById('fm-id').value       = isNew ? ''   : (task.id       || '');
  document.getElementById('fm-name').value     = isNew ? ''   : (task.name     || '');
  document.getElementById('fm-schedule').value = isNew ? '*/5 * * * *' : (task.schedule || '');
  document.getElementById('fm-command').value  = isNew ? DEFAULT_TASK_COMMAND : (task.command || '');
  document.getElementById('fm-enabled').checked = isNew ? true  : !!task.enabled;
  updateNextTip();
  m.style.display = 'flex';
  setTimeout(function(){ document.getElementById('fm-name').focus(); }, 80);
}
function closeTaskModal() {
  document.getElementById('task-modal').style.display = 'none';
}

/* 实时预览下次运行时间（简单客户端提示，5段基本格式）*/
function updateNextTip() {
  var tip = document.getElementById('fm-next-tip');
  var v = (document.getElementById('fm-schedule').value || '').trim();
  if (/^(\S+ ){4}\S+/.test(v)) {
    tip.textContent = '✓ 格式看起来正确';
  } else if (v === '') {
    tip.textContent = '';
  } else {
    tip.textContent = '⚠ 请填写 5 个时间字段';
    tip.style.color = 'var(--yellow)';
    return;
  }
  tip.style.color = 'var(--ac)';
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
  var runningWrap = row.querySelector('[data-task-running-wrap]');
  var nextCell = row.querySelector('[data-task-next]');
  var lastRunCell = row.querySelector('[data-task-last-run]');
  var exitCell = row.querySelector('[data-task-exit]');
  var runBtn = row.querySelector('[data-task-run-btn]');
  var toggleBtn = row.querySelector('[data-task-toggle-btn]');

  if (enabledBadge) {
    enabledBadge.textContent = task.enabled ? '启用' : '禁用';
    enabledBadge.className = 'badge ' + (task.enabled ? 'badge-green' : 'badge-gray');
  }
  if (runningWrap) {
    runningWrap.style.display = task.running ? '' : 'none';
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
  if (inp) inp.addEventListener('input', updateNextTip);
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

function isLogNearBottom(body) {
  return (body.scrollHeight - body.scrollTop - body.clientHeight) < 32;
}

function escapeHtml(text) {
  return String(text || '')
    .replace(/&/g,'&amp;')
    .replace(/</g,'&lt;')
    .replace(/>/g,'&gt;');
}

function renderLogLines(lines) {
  return lines.map(function(line){
    var cls = '';
    var safe = escapeHtml(line);
    if (/\bFAILED\b|error|fail|fatal|exception/i.test(safe)) cls = 'color:var(--red)';
    else if (/\bSKIP\b|warn/i.test(safe)) cls = 'color:var(--yellow)';
    else if (/\bUPDATED\b|\bOK\b|success|done|完成/i.test(safe)) cls = 'color:var(--green)';
    return cls
      ? '<div style="' + cls + '"><span style="opacity:.35">&gt;&nbsp;</span>' + safe + '</div>'
      : '<div><span style="opacity:.35">&gt;&nbsp;</span>' + safe + '</div>';
  }).join('');
}

function openLogModal(id, name) {
  logState = { id: id, name: name, page: 1, pages: 1, requestSeq: 0 };
  document.getElementById('log-modal-title').textContent = '运行日志 — ' + name;
  document.getElementById('log-modal').style.display = 'flex';
  var body = document.getElementById('log-body');
  body.dataset.signature = '';
  if (logPollTimer) clearInterval(logPollTimer);
  logPollTimer = setInterval(function() {
    if (document.getElementById('log-modal').style.display !== 'flex') return;
    logLoadPage(logState.page || 1, false, { silent: true });
  }, 2000);
  // 先加载第1页获取总页数，再跳到最后一页
  logLoadPage(1, true, { forceScrollBottom: true });
}
function closeLogModal() {
  document.getElementById('log-modal').style.display = 'none';
  logState.requestSeq += 1;
  if (logPollTimer) {
    clearInterval(logPollTimer);
    logPollTimer = 0;
  }
}
function clearCurrentLog() {
  if (!logState.id) return;
  if (!confirm('确定清空当前任务日志？此操作不可恢复。')) return;
  stopTaskStatusPolling();
  var form = document.createElement('form');
  form.method = 'POST';
  form.action = 'scheduled_tasks.php';
  form.innerHTML =
    '<input type="hidden" name="_csrf" value="' + String(CSRF_TOKEN).replace(/&/g,'&amp;').replace(/"/g,'&quot;') + '">' +
    '<input type="hidden" name="action" value="task_log_clear">' +
    '<input type="hidden" name="id" value="' + String(logState.id).replace(/&/g,'&amp;').replace(/"/g,'&quot;') + '">';
  document.body.appendChild(form);
  form.submit();
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
  var body = document.getElementById('log-body');
  var prevSignature = body.dataset.signature || '';
  var shouldStickBottom = isLogNearBottom(body) || !!options.forceScrollBottom;
  if (!options.silent) {
    body.innerHTML = '<span style="color:var(--tm)">加载中…</span>';
  }

  var url = 'api/task_log.php?id=' + encodeURIComponent(logState.id) + '&page=' + p;
  var requestSeq = ++logState.requestSeq;
  fetch(url, { credentials: 'same-origin' })
    .then(function(r){ return r.json(); })
    .then(function(d) {
      if (requestSeq !== logState.requestSeq) return;
      if (d.error) { body.innerHTML = '<span style="color:var(--red)">' + d.error + '</span>'; return; }
      logState.pages = d.pages || 1;
      logState.page  = d.page  || 1;

      // 首次打开跳到最后一页
      if (jumpToLast && d.pages > 1) {
        logLoadPage(d.pages, false, { forceScrollBottom: true });
        return;
      }

      document.getElementById('log-info').textContent =
        '共 ' + d.total + ' 行，每页 100 行';
      document.getElementById('log-page-label').textContent =
        '第 ' + d.page + ' / ' + d.pages + ' 页';
      document.getElementById('log-prev').disabled = d.page <= 1;
      document.getElementById('log-next').disabled = d.page >= d.pages;
      document.getElementById('log-last-btn').disabled = d.page >= d.pages;

      if (!d.lines || d.lines.length === 0) {
        var emptySignature = 'empty:' + d.page + ':' + d.total;
        if (!options.silent || prevSignature !== emptySignature) {
          body.innerHTML = '<span style="color:var(--tm)">暂无日志记录</span>';
          body.dataset.signature = emptySignature;
        }
        return;
      }
      var signature = [d.page, d.pages, d.total, d.lines.length, d.lines[0], d.lines[d.lines.length - 1]].join('|');
      if (!options.silent || prevSignature !== signature) {
        body.innerHTML = renderLogLines(d.lines);
        body.dataset.signature = signature;
      }
      if (shouldStickBottom && d.page >= d.pages) {
        body.scrollTop = body.scrollHeight;
      }
    })
    .catch(function(e){
      if (requestSeq !== logState.requestSeq) return;
      body.innerHTML = '<span style="color:var(--red)">请求失败：' + e.message + '</span>';
    });
}
</script>

<?php require_once __DIR__ . '/shared/footer.php'; ?>
