<?php
$page_title='API Token 管理';
require_once __DIR__.'/shared/functions.php';

$current_admin = auth_get_current_user();
if (!$current_admin || ($current_admin['role'] ?? '') !== 'admin') {
  header('Location: /login.php');
  exit;
}

if($_SERVER['REQUEST_METHOD']==='POST'){
  csrf_check();$postAction = $_POST['action'] ?? '';

  if ($postAction === 'generate_api_token') {
      $name = trim($_POST['token_name'] ?? '');
      if ($name === '') {
          flash_set('error', 'Token 名称不能为空');
          header('Location: api_tokens.php'); exit;
      }
      $token = api_token_generate($name);
      audit_log('generate_api_token', ['name' => $name]);
      flash_set('success', 'API Token 已生成');
      header('Location: api_tokens.php'); exit;
  }
  if ($postAction === 'delete_api_token') {
      $tk = $_POST['token'] ?? '';
      $tokens = api_tokens_load();
      if (isset($tokens[$tk])) {
          $name = $tokens[$tk]['name'] ?? '';
          unset($tokens[$tk]);
          api_tokens_save($tokens);
          audit_log('delete_api_token', ['name' => $name]);
          flash_set('success', 'API Token 已删除');
      } else {
          flash_set('error', 'Token 不存在');
      }
      header('Location: api_tokens.php'); exit;
  }
}

require_once __DIR__.'/shared/header.php';
$apiTokens = api_tokens_load();
?>

<div class="card" id="api-tokens">
  <div class="card-title">🔑 API Token 管理</div>

  <form method="POST" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:14px">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="generate_api_token">
    <input type="text" name="token_name" placeholder="Token 名称（如：HomeAssistant）" required
           style="flex:1;min-width:200px;background:var(--bg);border:1px solid var(--bd);border-radius:8px;padding:8px 12px;color:var(--tx);font-size:14px">
    <button type="submit" class="btn btn-primary">生成 Token</button>
  </form>

  <?php if (!empty($apiTokens)): ?>
  <div style="margin-bottom:14px">
    <table class="data-table" style="width:100%">
      <thead><tr><th>名称</th><th>Token</th><th>创建时间</th><th style="text-align:center">操作</th></tr></thead>
      <tbody>
      <?php foreach ($apiTokens as $tk => $meta): ?>
      <tr>
        <td><?= htmlspecialchars($meta['name'] ?? '') ?></td>
        <td style="white-space:nowrap">
          <code style="font-size:12px"><?= htmlspecialchars(api_token_mask($tk)) ?></code>
          <button type="button" class="btn btn-sm btn-secondary" style="margin-left:6px;padding:2px 6px;font-size:12px;line-height:1" onclick="copyToken(this)" data-token="<?= htmlspecialchars($tk) ?>" title="复制">📋</button>
        </td>
        <td style="font-size:12px;color:var(--tm)"><?= htmlspecialchars($meta['created_at'] ?? '') ?></td>
        <td style="text-align:center;white-space:nowrap">
          <button type="button" class="btn btn-sm btn-danger" onclick="deleteToken(this)"
                  data-token="<?= htmlspecialchars($tk) ?>" data-name="<?= htmlspecialchars($meta['name'] ?? '') ?>">删除</button>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php else: ?>
  <p style="color:var(--tm);font-size:13px;margin-bottom:14px">暂无 API Token，点击下方按钮生成。</p>
  <?php endif; ?>

  <div class="form-hint" style="margin-top:10px">
    使用方式：<code>GET /api/sites.php?token=&lt;TOKEN&gt;</code> 或 Header <code>Authorization: Bearer &lt;TOKEN&gt;</code>
  </div>
</div>

<script>
function fallbackCopy(text) {
    var ta = document.createElement('textarea');
    ta.value = text;
    ta.style.cssText = 'position:fixed;left:-9999px;top:-9999px;opacity:0';
    document.body.appendChild(ta);
    ta.focus();
    ta.setSelectionRange(0, text.length);
    var ok = false;
    try {
        ok = document.execCommand('copy');
    } catch (e) {}
    document.body.removeChild(ta);
    return ok;
}
function copyToken(btn) {
    var token = btn.getAttribute('data-token');
    if (!token) return;
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(token).then(function(){
            showToast('Token 已复制到剪贴板', 'success');
        }).catch(function(){
            if (fallbackCopy(token)) {
                showToast('Token 已复制到剪贴板', 'success');
            } else {
                showToast('复制失败', 'error');
            }
        });
    } else {
        if (fallbackCopy(token)) {
            showToast('Token 已复制到剪贴板', 'success');
        } else {
            showToast('复制失败', 'error');
        }
    }
}
function deleteToken(btn) {
    var token = btn.getAttribute('data-token') || '';
    var name = btn.getAttribute('data-name') || '';
    NavConfirm.open({
        title: '删除 API Token',
        message: '确认删除 Token「' + name + '」？此操作不可恢复。',
        confirmText: '删除',
        cancelText: '取消',
        danger: true,
        onConfirm: function() {
            var form = document.createElement('form');
            form.method = 'POST';
            form.action = 'api_tokens.php';
            form.style.display = 'none';
            document.body.appendChild(form);

            var csrf = document.createElement('input');
            csrf.type = 'hidden'; csrf.name = '_csrf'; csrf.value = window._csrf || '';
            form.appendChild(csrf);

            var action = document.createElement('input');
            action.type = 'hidden'; action.name = 'action'; action.value = 'delete_api_token';
            form.appendChild(action);

            var tk = document.createElement('input');
            tk.type = 'hidden'; tk.name = 'token'; tk.value = token;
            form.appendChild(tk);

            form.submit();
        }
    });
}
</script>

<?php require_once __DIR__.'/shared/footer.php';?>
