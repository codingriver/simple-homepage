<?php
/**
 * 通知管理 admin/notifications.php
 */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/shared/functions.php';

    $current_admin = auth_get_current_user();
    if (!$current_admin || ($current_admin['role'] ?? '') !== 'admin') {
        header('Location: /login.php'); exit;
    }
    csrf_check();
    $action = $_POST['action'] ?? '';

    // ── 保存 Webhook 设置 ──
    if ($action === 'save_webhook') {
        $cfg = load_config();
        $cfg['webhook_enabled'] = ($_POST['webhook_enabled'] ?? '0') === '1' ? '1' : '0';
        $cfg['webhook_type']    = in_array($_POST['webhook_type'] ?? 'custom', ['telegram','feishu','dingtalk','custom'])
                                  ? $_POST['webhook_type'] : 'custom';
        $cfg['webhook_url']     = trim($_POST['webhook_url']     ?? '');
        $cfg['webhook_tg_chat'] = trim($_POST['webhook_tg_chat'] ?? '');
        $events_raw = $_POST['webhook_events'] ?? [];
        $allowed_events = ['SUCCESS','FAIL','IP_LOCKED','LOGOUT','SETUP','HEALTH_DOWN'];
        $events = array_values(array_intersect((array)$events_raw, $allowed_events));
        $cfg['webhook_events']  = implode(',', $events ?: ['FAIL','IP_LOCKED']);
        save_config($cfg);
        audit_log('save_webhook', ['type' => $cfg['webhook_type']]);
        flash_set('success', 'Webhook 设置已保存');
        header('Location: notifications.php'); exit;
    }

    // ── 测试 Webhook ──
    if ($action === 'test_webhook') {
        $result = webhook_test();
        audit_log('test_webhook', ['ok' => $result['ok']]);
        flash_set($result['ok'] ? 'success' : 'error', $result['msg']);
        header('Location: notifications.php'); exit;
    }
}

$page_title = '通知管理';
require_once __DIR__ . '/shared/header.php';

$cfg = auth_get_config();
?>

<div class="card" id="webhook">
  <div class="card-title">🔔 Webhook 通知</div>
  <form method="POST">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_webhook">
    <div class="form-grid">
      <div class="form-group" style="grid-column:1/-1;display:flex;flex-direction:row;align-items:center;gap:14px">
        <label style="margin:0">启用 Webhook 通知</label>
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
          <input type="checkbox" name="webhook_enabled" value="1" <?= ($cfg['webhook_enabled']??'0')==='1'?'checked':'' ?>
                 style="width:16px;height:16px;accent-color:var(--ac)">
          <span style="font-size:13px">启用</span>
        </label>
      </div>
      <div class="form-group">
        <label>通知类型</label>
        <select name="webhook_type" id="wh_type" onchange="syncWebhookType()" style="width:100%;background:var(--bg);border:1px solid var(--bd);border-radius:8px;padding:10px 12px;color:var(--tx);font-size:14px;outline:none">
          <?php $wt=$cfg['webhook_type']??'custom'; foreach(['telegram'=>'Telegram Bot','feishu'=>'飞书 Webhook','dingtalk'=>'钉钉 Webhook','custom'=>'自定义 POST JSON'] as $v=>$l): ?>
          <option value="<?=$v?>" <?= $wt===$v?'selected':'' ?>><?=$l?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label>Webhook URL</label>
        <input type="url" name="webhook_url" value="<?= htmlspecialchars($cfg['webhook_url']??'') ?>" placeholder="https://..." style="width:100%">
      </div>
      <div class="form-group" id="wh_tg_chat" style="display:<?= ($cfg['webhook_type']??'custom')==='telegram'?'block':'none' ?>">
        <label>Telegram Chat ID</label>
        <input type="text" name="webhook_tg_chat" value="<?= htmlspecialchars($cfg['webhook_tg_chat']??'') ?>" placeholder="-1001234567890">
        <div class="form-hint" style="margin-top:5px">从 @userinfobot 获取，群组 ID 通常为负数</div>
      </div>
      <div class="form-group" style="grid-column:1/-1">
        <label style="margin-bottom:8px;display:block">订阅事件</label>
        <div style="display:flex;gap:16px;flex-wrap:wrap">
          <?php
          $wevents = array_filter(array_map('trim', explode(',', $cfg['webhook_events']??'FAIL,IP_LOCKED')));
          $event_labels = ['SUCCESS'=>'✅ 登录成功','FAIL'=>'❌ 登录失败','IP_LOCKED'=>'🔒 IP被锁定','LOGOUT'=>'🚪 退出登录','SETUP'=>'🎉 初始安装','HEALTH_DOWN'=>'💔 健康告警'];
          foreach ($event_labels as $ev => $el): ?>
          <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:13px">
            <input type="checkbox" name="webhook_events[]" value="<?=$ev?>" <?= in_array($ev,$wevents)?'checked':'' ?>
                   style="accent-color:var(--ac)">
            <?= $el ?>
          </label>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
    <div class="form-actions" style="display:flex;gap:10px">
      <button type="submit" class="btn btn-primary">保存 Webhook 设置</button>
      <button type="button" class="btn btn-secondary" onclick="testWebhook()">📨 发送测试消息</button>
    </div>
    <div class="form-hint" style="margin-top:10px">
      <b>Telegram</b>：先创建 Bot（@BotFather），URL 填 <code>https://api.telegram.org/bot{TOKEN}/sendMessage</code>，Chat ID 填目标会话 ID。<br>
      <b>飞书 / 钉钉</b>：在群机器人设置中创建 Webhook，复制 URL 填入即可。<br>
      <b>自定义</b>：向指定 URL POST 一个 JSON，包含 event/username/ip/time/text 字段。
    </div>
  </form>
  <!-- 隐藏的测试表单 -->
  <form id="webhookTestForm" method="POST" style="display:none">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="test_webhook">
  </form>
</div>

<script>
// ── Webhook 类型联动 ──
function syncWebhookType() {
    var t = document.getElementById('wh_type').value;
    document.getElementById('wh_tg_chat').style.display = t === 'telegram' ? 'block' : 'none';
}
function testWebhook() {
    NavConfirm.open({
        title: '测试 Webhook',
        message: '发送一条测试 Webhook 消息？',
        confirmText: '发送',
        cancelText: '取消',
        danger: false,
        onConfirm: function() {
            document.getElementById('webhookTestForm').submit();
        }
    });
}
</script>

<?php require_once __DIR__ . '/shared/footer.php'; ?>
