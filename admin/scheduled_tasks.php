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
        $cmd   = (string)($_POST['command']       ?? '');
        $mode  = task_normalize_workdir_mode($_POST['working_dir_mode'] ?? null);
        $wdir  = trim((string)($_POST['working_dir'] ?? ''));
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
            flash_set('error', 'Cron 表达式无效（需至少 5 个时间字段）');
            header('Location: scheduled_tasks.php'); exit;
        }
        if ($mode === 'custom') {
            if ($wdir === '') {
                flash_set('error', '自定义工作目录不能为空');
                header('Location: scheduled_tasks.php'); exit;
            }
            if (!str_starts_with($wdir, '/')) {
                flash_set('error', '自定义工作目录必须为绝对路径');
                header('Location: scheduled_tasks.php'); exit;
            }
        } else {
            $wdir = '';
        }
        $found = false;
        foreach ($data['tasks'] as &$t) {
            if (($t['id'] ?? '') === $id) {
                $t['name'] = $name; $t['enabled'] = $en;
                $t['schedule'] = $sched; $t['command'] = $cmd;
                $t['working_dir_mode'] = $mode;
                $t['working_dir'] = $wdir;
                $found = true; break;
            }
        }
        unset($t);
        if (!$found) {
            $data['tasks'][] = ['id' => $id, 'name' => $name,
                'enabled' => $en, 'schedule' => $sched, 'command' => $cmd,
                'working_dir_mode' => $mode, 'working_dir' => $wdir];
        }
        task_ensure_workdir(['id' => $id, 'working_dir_mode' => $mode, 'working_dir' => $wdir]);
        save_scheduled_tasks($data);
        $r = cron_regenerate();
        flash_set($r['ok'] ? 'success' : 'error',
            $r['ok'] ? '已保存并更新 crontab' : $r['msg']);
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
        if (cron_is_ddns_dispatcher_id($id)) {
            $ex = cron_execute_task($id);
            flash_set($ex['ok'] ? 'success' : 'warn', $ex['ok'] ? 'DDNS 分组执行完成' : ('DDNS 分组执行有失败，退出码 ' . $ex['code']));
            header('Location: scheduled_tasks.php'); exit;
        }
        $ex = cron_execute_task($id);
        flash_set($ex['ok'] ? 'success' : 'error', $ex['ok'] ? '执行完成' : ('执行失败，退出码 ' . $ex['code']));
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
$tasks = load_scheduled_tasks()['tasks'] ?? [];
foreach ($tasks as &$_t) {
    $_t['_is_system'] = cron_is_system_task($_t);
    $_t['_next'] = (!empty($_t['enabled']) && !empty($_t['schedule']))
        ? (cron_next_run($_t['schedule']) ?: '-')
        : '-';
    $_t['_workdir'] = task_resolve_workdir($_t);
    $_t['_workdir_mode_label'] = match(task_normalize_workdir_mode($_t['working_dir_mode'] ?? null)) {
        'task' => '任务目录',
        'custom' => '自定义目录',
        default => '项目目录',
    };
}
unset($_t);
$default_task_command = <<<'BASH'
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

echo
echo "== all environment variables (sorted) =="
env | sort
BASH;
$CSRF = csrf_field();
?>

<!-- ===== 工具栏 ===== -->
<div class="toolbar">
  <button type="button" class="btn btn-primary" onclick="openTaskModal(null)">＋ 新建任务</button>
  <form method="POST" style="display:inline">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="cron_reload">
    <button type="submit" class="btn btn-secondary">↺ 重新安装 crontab</button>
  </form>
  <span style="color:var(--tm);font-size:12px">管理员可执行任意 shell，请自行评估风险。</span>
</div>

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

<!-- ===== 任务列表 ===== -->
<div class="card">
<?php if (empty($tasks)): ?>
  <p style="color:var(--tm);font-size:13px">暂无任务，点击「新建任务」创建第一条。</p>
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
    <?php foreach ($tasks as $t):
        $enabled   = !empty($t['enabled']);
        $exitCode  = $t['last_code'] ?? null;
        $exitBadge = $exitCode === null ? '—' :
            ($exitCode === 0
                ? '<span class="badge badge-green">0</span>'
                : '<span class="badge badge-red">' . (int)$exitCode . '</span>');
    ?>
    <tr>
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
      <td>
        <?php if ($enabled): ?>
          <span class="badge badge-green">启用</span>
        <?php else: ?>
          <span class="badge badge-gray">禁用</span>
        <?php endif; ?>
      </td>
      <td style="font-size:12px;font-family:var(--mono);color:var(--tx2)">
        <?= htmlspecialchars($t['_next']) ?>
      </td>
      <td style="font-size:12px;font-family:var(--mono);color:var(--tx2)">
        <?= htmlspecialchars($t['last_run'] ?? '—') ?>
      </td>
      <td><?= $exitBadge ?></td>
      <td style="white-space:nowrap">

        <!-- 启用 / 禁用 -->
        <?php if (empty($t['_is_system'])): ?>
        <form method="POST" style="display:inline">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="task_toggle">
          <input type="hidden" name="id" value="<?= htmlspecialchars($t['id'] ?? '') ?>">
          <button type="submit"
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
          <button type="submit" class="btn btn-sm btn-secondary">▶▶ 立即执行</button>
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
          onsubmit="return confirmDeleteTask(<?= htmlspecialchars(json_encode($t['name'] ?? '', JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode(task_normalize_workdir_mode($t['working_dir_mode'] ?? null), JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>)">
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
          <!-- 工作目录模式 -->
          <div class="form-group">
            <label>工作目录模式</label>
            <select name="working_dir_mode" id="fm-working-dir-mode" onchange="toggleWorkdirInputs()">
              <option value="project">项目目录（/var/www/nav）</option>
              <option value="task">任务目录（自动分配）</option>
              <option value="custom">自定义目录</option>
            </select>
            <span class="form-hint">任务目录会自动分配到 data/tasks/&lt;任务ID&gt;，删除任务时会一起清理。</span>
          </div>
          <!-- 自定义工作目录 -->
          <div class="form-group" id="fm-working-dir-wrap" style="display:none">
            <label>自定义工作目录</label>
            <input type="text" name="working_dir" id="fm-working-dir" placeholder="/tmp/my-job" oninput="updateWorkdirPreview()">
            <span class="form-hint">仅在“自定义目录”模式下生效，必须是绝对路径。</span>
          </div>
          <div class="form-group" style="grid-column:1/-1">
            <label>最终工作目录</label>
            <div id="fm-workdir-preview" style="padding:10px 12px;border:1px solid var(--bd);border-radius:10px;background:var(--bg);font-family:var(--mono);font-size:12px;color:var(--tx2);word-break:break-all"></div>
            <span class="form-hint">任务目录模式会自动使用 data/tasks/&lt;任务ID&gt;；删除该任务时会连同目录一起清理。</span>
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
          <span class="form-hint">新建任务会默认打印基础命令结果和 bash 启动前注入的环境变量，方便直接观察与修改。DDNS 可调用本机 <code style="font-family:var(--mono)">http://127.0.0.1/api/dns.php</code>，说明见「域名解析」页底部。</span>
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

/* ---- 任务弹窗 ---- */
function openTaskModal(task) {
  var m = document.getElementById('task-modal');
  var isNew = !task || !task.id;
  document.getElementById('modal-title').textContent = isNew ? '新建任务' : '编辑任务';
  document.getElementById('fm-id').value       = isNew ? ''   : (task.id       || '');
  document.getElementById('fm-name').value     = isNew ? ''   : (task.name     || '');
  document.getElementById('fm-schedule').value = isNew ? '*/5 * * * *' : (task.schedule || '');
  document.getElementById('fm-command').value  = isNew ? DEFAULT_TASK_COMMAND : (task.command || '');
  document.getElementById('fm-working-dir-mode').value = isNew ? 'task' : (task.working_dir_mode || 'task');
  document.getElementById('fm-working-dir').value = isNew ? '' : (task.working_dir || '');
  document.getElementById('fm-enabled').checked = isNew ? true  : !!task.enabled;
  toggleWorkdirInputs();
  updateWorkdirPreview();
  updateNextTip();
  m.style.display = 'flex';
  setTimeout(function(){ document.getElementById('fm-name').focus(); }, 80);
}
function closeTaskModal() {
  document.getElementById('task-modal').style.display = 'none';
}
function toggleWorkdirInputs() {
  var mode = document.getElementById('fm-working-dir-mode').value;
  var wrap = document.getElementById('fm-working-dir-wrap');
  if (!wrap) return;
  wrap.style.display = mode === 'custom' ? '' : 'none';
  updateWorkdirPreview();
}
function updateWorkdirPreview() {
  var mode = document.getElementById('fm-working-dir-mode').value;
  var id = (document.getElementById('fm-id').value || '').trim();
  var custom = (document.getElementById('fm-working-dir').value || '').trim();
  var preview = document.getElementById('fm-workdir-preview');
  if (!preview) return;
  if (mode === 'project') {
    preview.textContent = '/var/www/nav';
    return;
  }
  if (mode === 'task') {
    preview.textContent = '/var/www/nav/data/tasks/' + (id || '<保存后自动生成任务ID>');
    return;
  }
  preview.textContent = custom || '<请输入绝对路径>';
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
document.addEventListener('DOMContentLoaded', function(){
  var inp = document.getElementById('fm-schedule');
  if (inp) inp.addEventListener('input', updateNextTip);
  var idInp = document.getElementById('fm-id');
  var customInp = document.getElementById('fm-working-dir');
  if (idInp) idInp.addEventListener('input', updateWorkdirPreview);
  if (customInp) customInp.addEventListener('input', updateWorkdirPreview);
  toggleWorkdirInputs();
  // 按 ESC 关闭弹窗
  document.addEventListener('keydown', function(e){
    if (e.key === 'Escape') { closeTaskModal(); closeLogModal(); }
  });
});

/* ---- 日志弹窗 ---- */
var logState = { id: '', name: '', page: 1, pages: 1 };

function openLogModal(id, name) {
  logState = { id: id, name: name, page: 1, pages: 1 };
  document.getElementById('log-modal-title').textContent = '运行日志 — ' + name;
  document.getElementById('log-modal').style.display = 'flex';
  // 先加载第1页获取总页数，再跳到最后一页
  logLoadPage(1, true);
}
function closeLogModal() {
  document.getElementById('log-modal').style.display = 'none';
}
function clearCurrentLog() {
  if (!logState.id) return;
  if (!confirm('确定清空当前任务日志？此操作不可恢复。')) return;
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
function confirmDeleteTask(name, mode) {
  var lines = ['确定删除任务「' + name + '」？', '', '• 会删除该任务对应的日志'];
  if (mode === 'task') {
    lines.push('• 会删除该任务的独立工作目录');
  } else if (mode === 'custom') {
    lines.push('• 不会删除自定义工作目录');
  } else {
    lines.push('• 不会删除项目目录');
  }
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

function logLoadPage(p, jumpToLast) {
  if (p < 1 || p > logState.pages) return;
  logState.page = p;
  var body = document.getElementById('log-body');
  body.innerHTML = '<span style="color:var(--tm)">加载中…</span>';

  var url = 'api/task_log.php?id=' + encodeURIComponent(logState.id) + '&page=' + p;
  fetch(url, { credentials: 'same-origin' })
    .then(function(r){ return r.json(); })
    .then(function(d) {
      if (d.error) { body.innerHTML = '<span style="color:var(--red)">' + d.error + '</span>'; return; }
      logState.pages = d.pages || 1;
      logState.page  = d.page  || 1;

      // 首次打开跳到最后一页
      if (jumpToLast && d.pages > 1) {
        logLoadPage(d.pages, false);
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
        body.innerHTML = '<span style="color:var(--tm)">暂无日志记录</span>';
        return;
      }
      var html = d.lines.map(function(line){
        var cls = '', safe = line
          .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
        if (/\bFAILED\b|error|fail|fatal|exception/i.test(safe)) cls = 'color:var(--red)';
        else if (/\bSKIP\b|warn/i.test(safe))                    cls = 'color:var(--yellow)';
        else if (/\bUPDATED\b|\bOK\b|success|done|完成/i.test(safe)) cls = 'color:var(--green)';
        return cls
          ? '<div style="' + cls + '"><span style="opacity:.35">&gt;&nbsp;</span>' + safe + '</div>'
          : '<div><span style="opacity:.35">&gt;&nbsp;</span>' + safe + '</div>';
      }).join('');
      body.innerHTML = html;
      // 默认滚动到底部（最新内容）
      body.scrollTop = body.scrollHeight;
    })
    .catch(function(e){
      body.innerHTML = '<span style="color:var(--red)">请求失败：' + e.message + '</span>';
    });
}
</script>

<?php require_once __DIR__ . '/shared/footer.php'; ?>
