<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/shared/functions.php';
    require_once __DIR__ . '/shared/task_template_lib.php';
    $user = auth_get_current_user();
    if (!$user || ($user['role'] ?? '') !== 'admin') {
        header('Location: /login.php');
        exit;
    }
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'create_task_from_template') {
        $templateId = trim((string)($_POST['template_id'] ?? ''));
        $template = task_template_find($templateId);
        if (!$template) {
            flash_set('error', '模板不存在');
            header('Location: task_templates.php');
            exit;
        }
        $vars = is_array($_POST['vars'] ?? null) ? $_POST['vars'] : [];
        $err = task_template_validate_vars($template, $vars);
        if ($err !== null) {
            flash_set('error', $err);
            header('Location: task_templates.php');
            exit;
        }
        $name = trim((string)($_POST['task_name'] ?? ''));
        if ($name === '') {
            $name = (string)($template['name'] ?? '模板任务');
        }
        $schedule = trim((string)($_POST['schedule'] ?? ($template['default_schedule'] ?? '*/5 * * * *')));
        $command = task_template_render_command($template, $vars);
        $saved = scheduled_task_upsert([
            'name' => $name,
            'schedule' => $schedule,
            'command' => $command,
            'enabled' => !empty($_POST['enabled']),
        ]);
        flash_set($saved['ok'] ? 'success' : 'error', (string)($saved['msg'] ?? '模板创建失败'));
        header('Location: ' . ($saved['ok'] ? 'scheduled_tasks.php' : 'task_templates.php'));
        exit;
    }
}

$page_title = '任务模板';
require_once __DIR__ . '/shared/header.php';
require_once __DIR__ . '/shared/task_template_lib.php';

$grouped = task_template_grouped();
?>

<div class="card" style="margin-bottom:16px">
  <div class="card-title">任务模板中心</div>
  <div style="color:var(--tm);font-size:12px;line-height:1.8">
    这里提供内置计划任务模板。模板会直接渲染成真实 shell 脚本，再写入当前计划任务体系，后续仍然按普通任务编辑、执行、记录日志。
  </div>
</div>

<?php foreach ($grouped as $category => $templates): ?>
<div class="card" style="margin-bottom:16px">
  <div class="card-title"><?= htmlspecialchars($category) ?></div>
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:14px">
    <?php foreach ($templates as $template): ?>
    <div style="border:1px solid var(--bd);border-radius:12px;padding:14px;background:var(--sf2)">
      <div style="display:flex;gap:10px;align-items:center;margin-bottom:8px">
        <span style="font-size:20px"><?= htmlspecialchars((string)($template['icon'] ?? '📄')) ?></span>
        <div>
          <div style="font-weight:700"><?= htmlspecialchars((string)($template['name'] ?? '未命名模板')) ?></div>
          <div style="font-size:12px;color:var(--tm)"><?= htmlspecialchars((string)($template['description'] ?? '')) ?></div>
        </div>
      </div>
      <div style="margin-bottom:10px;font-size:12px;color:var(--tx2)">
        默认 Cron：<code><?= htmlspecialchars((string)($template['default_schedule'] ?? '')) ?></code>
      </div>
      <pre style="margin:0 0 12px;background:var(--bg);border:1px solid var(--bd);border-radius:10px;padding:10px;white-space:pre-wrap;word-break:break-all;font-size:11px;color:var(--tx2);font-family:var(--mono)"><?= htmlspecialchars((string)($template['command_template'] ?? '')) ?></pre>
      <form method="POST" style="display:grid;gap:10px">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create_task_from_template">
        <input type="hidden" name="template_id" value="<?= htmlspecialchars((string)($template['id'] ?? '')) ?>">
        <div class="form-group" style="margin:0">
          <label>任务名称</label>
          <input type="text" name="task_name" value="<?= htmlspecialchars((string)($template['name'] ?? '')) ?>">
        </div>
        <div class="form-group" style="margin:0">
          <label>Cron 表达式</label>
          <input type="text" name="schedule" value="<?= htmlspecialchars((string)($template['default_schedule'] ?? '')) ?>" style="font-family:var(--mono)">
        </div>
        <?php foreach (($template['variables'] ?? []) as $var): ?>
          <?php if (!is_array($var)) { continue; } ?>
          <div class="form-group" style="margin:0">
            <label><?= htmlspecialchars((string)($var['label'] ?? ($var['key'] ?? '变量'))) ?><?= !empty($var['required']) ? ' *' : '' ?></label>
            <input
              type="text"
              name="vars[<?= htmlspecialchars((string)($var['key'] ?? '')) ?>]"
              placeholder="<?= htmlspecialchars((string)($var['placeholder'] ?? '')) ?>"
              <?= !empty($var['required']) ? 'required' : '' ?>>
          </div>
        <?php endforeach; ?>
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px;color:var(--tx)">
          <input type="checkbox" name="enabled" value="1" checked style="width:16px;height:16px;accent-color:var(--ac)">
          创建后立即启用
        </label>
        <div class="form-actions" style="margin:0">
          <button type="submit" class="btn btn-primary">从模板创建任务</button>
          <a href="scheduled_tasks.php" class="btn btn-secondary">查看计划任务</a>
        </div>
      </form>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endforeach; ?>

<?php require_once __DIR__ . '/shared/footer.php'; ?>
