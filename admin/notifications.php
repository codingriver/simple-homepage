<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/shared/functions.php';
    require_once __DIR__ . '/shared/notify_lib.php';
    $user = auth_get_current_user();
    if (!$user || ($user['role'] ?? '') !== 'admin') {
        header('Location: /login.php');
        exit;
    }
    csrf_check();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'save_channel') {
        $id = trim((string)($_POST['id'] ?? ''));
        $input = [
            'name' => trim((string)($_POST['name'] ?? '')),
            'type' => trim((string)($_POST['type'] ?? 'custom')),
            'enabled' => !empty($_POST['enabled']),
            'cooldown_seconds' => (int)($_POST['cooldown_seconds'] ?? 300),
            'events' => is_array($_POST['events'] ?? null) ? $_POST['events'] : [],
            'config' => [
                'webhook_url' => trim((string)($_POST['webhook_url'] ?? '')),
                'bot_token' => trim((string)($_POST['bot_token'] ?? '')),
                'chat_id' => trim((string)($_POST['chat_id'] ?? '')),
            ],
        ];
        $saved = notify_channel_upsert($input, $id !== '' ? $id : null);
        flash_set($saved['ok'] ? 'success' : 'error', (string)($saved['msg'] ?? ($saved['ok'] ? '已保存' : '保存失败')));
        header('Location: notifications.php');
        exit;
    }

    if ($action === 'delete_channel') {
        $ok = notify_channel_delete(trim((string)($_POST['id'] ?? '')));
        flash_set($ok ? 'success' : 'error', $ok ? '通知渠道已删除' : '通知渠道不存在');
        header('Location: notifications.php');
        exit;
    }

    if ($action === 'toggle_channel') {
        $enabled = notify_channel_toggle(trim((string)($_POST['id'] ?? '')));
        flash_set($enabled === null ? 'error' : 'success', $enabled === null ? '通知渠道不存在' : ($enabled ? '通知渠道已启用' : '通知渠道已禁用'));
        header('Location: notifications.php');
        exit;
    }

    if ($action === 'test_channel') {
        $result = notify_test_channel(trim((string)($_POST['id'] ?? '')));
        flash_set($result['ok'] ? 'success' : 'warn', (string)($result['msg'] ?? '测试失败'));
        header('Location: notifications.php');
        exit;
    }
}

$page_title = '通知中心';
require_once __DIR__ . '/shared/header.php';
require_once __DIR__ . '/shared/notify_lib.php';

$data = notify_load_data();
$channels = $data['channels'] ?? [];
$editId = trim((string)($_GET['edit'] ?? ''));
$editChannel = $editId !== '' ? notify_channel_find($data, $editId) : null;
$eventDefs = notify_event_definitions();
$typeLabels = notify_channel_type_labels();
?>

<div class="card" style="margin-bottom:16px">
  <div class="card-title">通知中心</div>
  <div style="color:var(--tm);font-size:12px;line-height:1.8">
    统一管理任务失败、DDNS 成功/失败、登录异常、备份结果、SSL/域名到期提醒。支持 Telegram、飞书、钉钉、企业微信和自定义 Webhook。
  </div>
</div>

<div class="card" style="margin-bottom:16px">
  <div class="card-title"><?= $editChannel ? '编辑通知渠道' : '新增通知渠道' ?></div>
  <form method="POST">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_channel">
    <input type="hidden" name="id" value="<?= htmlspecialchars((string)($editChannel['id'] ?? '')) ?>">
    <div class="form-grid">
      <div class="form-group">
        <label>渠道名称</label>
        <input type="text" name="name" value="<?= htmlspecialchars((string)($editChannel['name'] ?? '')) ?>" required>
      </div>
      <div class="form-group">
        <label>渠道类型</label>
        <select name="type" id="notify-type" onchange="syncNotifyType()">
          <?php $currentType = (string)($editChannel['type'] ?? 'custom'); foreach ($typeLabels as $value => $label): ?>
          <option value="<?= htmlspecialchars($value) ?>" <?= $currentType === $value ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label>去重冷却秒数</label>
        <input type="number" name="cooldown_seconds" min="0" value="<?= (int)($editChannel['cooldown_seconds'] ?? 300) ?>">
      </div>
      <div class="form-group" style="justify-content:flex-end;padding-bottom:4px">
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px;color:var(--tx)">
          <input type="checkbox" name="enabled" value="1" <?= !array_key_exists('enabled', $editChannel ?? []) || !empty($editChannel['enabled']) ? 'checked' : '' ?> style="width:16px;height:16px;accent-color:var(--ac)">
          启用此渠道
        </label>
      </div>
    </div>

    <div class="form-grid">
      <div class="form-group full" id="notify-webhook-wrap">
        <label>Webhook URL</label>
        <input type="url" name="webhook_url" value="<?= htmlspecialchars((string)($editChannel['config']['webhook_url'] ?? '')) ?>" placeholder="https://...">
      </div>
      <div class="form-group" id="notify-bot-token-wrap">
        <label>Telegram Bot Token</label>
        <input type="text" name="bot_token" value="<?= htmlspecialchars((string)($editChannel['config']['bot_token'] ?? '')) ?>" placeholder="123456:ABCDEF">
      </div>
      <div class="form-group" id="notify-chat-id-wrap">
        <label>Telegram Chat ID</label>
        <input type="text" name="chat_id" value="<?= htmlspecialchars((string)($editChannel['config']['chat_id'] ?? '')) ?>" placeholder="-1001234567890">
      </div>
    </div>

    <div class="form-group">
      <label>订阅事件</label>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:8px">
        <?php $selectedEvents = array_values(array_map('strval', $editChannel['events'] ?? array_keys($eventDefs))); foreach ($eventDefs as $event => $label): ?>
        <label style="display:flex;align-items:center;gap:8px;border:1px solid var(--bd);border-radius:10px;padding:10px 12px;background:var(--sf2);cursor:pointer">
          <input type="checkbox" name="events[]" value="<?= htmlspecialchars($event) ?>" <?= in_array($event, $selectedEvents, true) ? 'checked' : '' ?> style="width:16px;height:16px;accent-color:var(--ac)">
          <span style="font-size:12px;color:var(--tx2)"><?= htmlspecialchars($label) ?></span>
        </label>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="form-actions">
      <button type="submit" class="btn btn-primary">保存通知渠道</button>
      <?php if ($editChannel): ?><a href="notifications.php" class="btn btn-secondary">取消编辑</a><?php endif; ?>
    </div>
  </form>
</div>

<div class="card">
  <div class="card-title">通知渠道列表</div>
  <?php if (!$channels): ?>
    <p style="color:var(--tm);font-size:13px">暂无通知渠道。</p>
  <?php else: ?>
    <div class="table-wrap"><table>
      <thead><tr><th>名称</th><th>类型</th><th>状态</th><th>事件</th><th>最近结果</th><th>操作</th></tr></thead>
      <tbody>
      <?php foreach ($channels as $channel): ?>
        <tr>
          <td style="font-weight:600"><?= htmlspecialchars((string)($channel['name'] ?? '')) ?></td>
          <td><?= htmlspecialchars($typeLabels[(string)($channel['type'] ?? 'custom')] ?? (string)($channel['type'] ?? 'custom')) ?></td>
          <td><span class="badge <?= !empty($channel['enabled']) ? 'badge-green' : 'badge-gray' ?>"><?= !empty($channel['enabled']) ? '启用' : '禁用' ?></span></td>
          <td style="font-size:12px;color:var(--tx2)"><?= htmlspecialchars(implode('、', array_map(static fn($event) => notify_event_definitions()[$event] ?? $event, array_values(array_map('strval', $channel['events'] ?? []))))) ?></td>
          <td style="font-size:12px;color:var(--tx2)">
            <?= htmlspecialchars((string)($channel['runtime']['last_status'] ?? '未发送')) ?>
            <?php if (!empty($channel['runtime']['last_sent_at'])): ?>
              <div style="font-family:var(--mono);margin-top:4px"><?= htmlspecialchars((string)$channel['runtime']['last_sent_at']) ?></div>
            <?php endif; ?>
          </td>
          <td style="white-space:nowrap">
            <a class="btn btn-sm btn-secondary" href="notifications.php?edit=<?= urlencode((string)($channel['id'] ?? '')) ?>">编辑</a>
            <form method="POST" style="display:inline">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="toggle_channel">
              <input type="hidden" name="id" value="<?= htmlspecialchars((string)($channel['id'] ?? '')) ?>">
              <button type="submit" class="btn btn-sm btn-secondary"><?= !empty($channel['enabled']) ? '禁用' : '启用' ?></button>
            </form>
            <form method="POST" style="display:inline">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="test_channel">
              <input type="hidden" name="id" value="<?= htmlspecialchars((string)($channel['id'] ?? '')) ?>">
              <button type="submit" class="btn btn-sm btn-secondary">测试</button>
            </form>
            <form method="POST" style="display:inline" onsubmit="return confirm('确认删除通知渠道？')">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete_channel">
              <input type="hidden" name="id" value="<?= htmlspecialchars((string)($channel['id'] ?? '')) ?>">
              <button type="submit" class="btn btn-sm btn-danger">删除</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endif; ?>
</div>

<script>
function syncNotifyType() {
  var t = document.getElementById('notify-type').value;
  document.getElementById('notify-webhook-wrap').style.display = t === 'telegram' ? 'none' : '';
  document.getElementById('notify-bot-token-wrap').style.display = t === 'telegram' ? '' : 'none';
  document.getElementById('notify-chat-id-wrap').style.display = t === 'telegram' ? '' : 'none';
}
document.addEventListener('DOMContentLoaded', syncNotifyType);
</script>

<?php require_once __DIR__ . '/shared/footer.php'; ?>
