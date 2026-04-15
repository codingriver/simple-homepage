<?php
declare(strict_types=1);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/shared/functions.php';
    require_once __DIR__ . '/shared/webdav_lib.php';
    $user = auth_get_current_user();
    if (!$user || ($user['role'] ?? '') !== 'admin') {
        header('Location: /login.php');
        exit;
    }
    csrf_check();
    $action = trim((string)($_POST['action'] ?? ''));
    if ($action === 'save_webdav_service') {
        $result = webdav_set_enabled(($_POST['enabled'] ?? '') === '1');
        flash_set($result['ok'] ? 'success' : 'error', (string)($result['msg'] ?? '保存失败'));
        header('Location: webdav.php');
        exit;
    }
    if ($action === 'save_webdav_account') {
        $result = webdav_account_upsert([
            'username' => $_POST['username'] ?? '',
            'password' => $_POST['password'] ?? '',
            'root' => $_POST['root'] ?? '',
            'readonly' => ($_POST['readonly'] ?? '') === '1',
            'enabled' => ($_POST['account_enabled'] ?? '') === '1',
            'max_upload_mb' => (int)($_POST['max_upload_mb'] ?? 0),
            'quota_mb' => (int)($_POST['quota_mb'] ?? 0),
            'ip_whitelist' => $_POST['ip_whitelist'] ?? '',
            'notes' => $_POST['notes'] ?? '',
        ], trim((string)($_POST['id'] ?? '')) ?: null);
        flash_set($result['ok'] ? 'success' : 'error', (string)($result['msg'] ?? '保存失败'));
        header('Location: webdav.php');
        exit;
    }
    if ($action === 'delete_webdav_account') {
        $ok = webdav_account_delete(trim((string)($_POST['id'] ?? '')));
        flash_set($ok ? 'success' : 'error', $ok ? 'WebDAV 账号已删除' : 'WebDAV 账号不存在');
        header('Location: webdav.php');
        exit;
    }
    if ($action === 'toggle_webdav_account') {
        $enabled = webdav_account_toggle(trim((string)($_POST['id'] ?? '')));
        flash_set($enabled === null ? 'error' : 'success', $enabled === null ? 'WebDAV 账号不存在' : ($enabled ? 'WebDAV 账号已启用' : 'WebDAV 账号已禁用'));
        header('Location: webdav.php');
        exit;
    }
    if ($action === 'clone_webdav_account') {
        $result = webdav_account_clone(trim((string)($_POST['id'] ?? '')));
        $msg = !empty($result['ok'])
            ? ('WebDAV 账号已克隆为 ' . (string)($result['cloned_username'] ?? ''))
            : (string)($result['msg'] ?? '克隆失败');
        flash_set(!empty($result['ok']) ? 'success' : 'error', $msg);
        header('Location: webdav.php');
        exit;
    }
    if ($action === 'reset_webdav_password') {
        $result = webdav_account_reset_password(trim((string)($_POST['id'] ?? '')), (string)($_POST['new_password'] ?? ''));
        flash_set(!empty($result['ok']) ? 'success' : 'error', (string)($result['msg'] ?? '密码重置失败'));
        header('Location: webdav.php');
        exit;
    }
}

if (($_GET['action'] ?? '') === 'audit_export') {
    require_once __DIR__ . '/shared/functions.php';
    require_once __DIR__ . '/shared/webdav_lib.php';
    $user = auth_get_current_user();
    if (!$user || ($user['role'] ?? '') !== 'admin') {
        header('Location: /login.php');
        exit;
    }
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="webdav-audit-export.json"');
    echo webdav_audit_export_json([
        'limit' => (int)($_GET['limit'] ?? 500),
        'action' => trim((string)($_GET['action_name'] ?? '')),
        'user' => trim((string)($_GET['log_user'] ?? '')),
        'keyword' => trim((string)($_GET['keyword'] ?? '')),
    ]);
    exit;
}

$page_title = 'WebDAV';
require_once __DIR__ . '/shared/header.php';
require_once __DIR__ . '/shared/webdav_lib.php';

$cfg = webdav_config();
$stats = webdav_stats_summary();
$recentMap = webdav_account_recent_activity_map();
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
$webdavUrl = $scheme . '://' . $host . '/webdav/';
$logs = webdav_audit_query([
    'limit' => 20,
    'page' => 1,
    'action' => trim((string)($_GET['action_name'] ?? '')),
    'user' => trim((string)($_GET['log_user'] ?? '')),
    'keyword' => trim((string)($_GET['keyword'] ?? '')),
]);
$editId = trim((string)($_GET['edit'] ?? ''));
$editAccount = $editId !== '' ? webdav_account_find_by_id($editId) : null;
$editAccountRelations = $editAccount ? webdav_accounts_for_local_path(webdav_display_local_root((string)($editAccount['root'] ?? '/'))) : [];
?>

<div class="card" style="margin-bottom:16px">
  <div class="card-title">WebDAV 服务</div>
  <div style="color:var(--tm);font-size:12px;line-height:1.9">
    WebDAV 已升级为多账号模式。每个账号都可以独立设置根目录、只读/读写、上传限制、目录配额和 IP 白名单，统一通过同一个 <code>/webdav/</code> 地址访问。
  </div>
</div>

<div style="display:grid;grid-template-columns:1.1fr .9fr;gap:16px;align-items:start">
  <div style="display:flex;flex-direction:column;gap:16px">
    <div class="card">
      <div class="card-title">服务开关</div>
      <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_webdav_service">
        <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap">
          <select name="enabled" style="min-width:160px">
            <option value="1" <?= $cfg['enabled'] ? 'selected' : '' ?>>开启 WebDAV</option>
            <option value="0" <?= !$cfg['enabled'] ? 'selected' : '' ?>>关闭 WebDAV</option>
          </select>
          <button type="submit" class="btn btn-primary">保存服务状态</button>
          <span class="badge <?= $cfg['enabled'] ? 'badge-green' : 'badge-gray' ?>"><?= $cfg['enabled'] ? '运行中' : '已关闭' ?></span>
        </div>
      </form>
    </div>

    <div class="card">
      <div class="card-title"><?= $editAccount ? '编辑 WebDAV 账号' : '新增 WebDAV 账号' ?></div>
      <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_webdav_account">
        <input type="hidden" name="id" value="<?= htmlspecialchars((string)($editAccount['id'] ?? '')) ?>">
        <div class="form-grid">
          <div class="form-group">
            <label>用户名</label>
            <input type="text" name="username" value="<?= htmlspecialchars((string)($editAccount['username'] ?? '')) ?>" placeholder="webdav-user" required>
          </div>
          <div class="form-group">
            <label>密码</label>
            <input type="password" name="password" value="" placeholder="<?= $editAccount ? '留空表示不修改密码' : '新增账号必须设置密码' ?>">
          </div>
          <div class="form-group">
            <label>根目录</label>
            <input type="text" name="root" value="<?= htmlspecialchars((string)($editAccount['root'] ?? webdav_default_root())) ?>" placeholder="/var/www/nav/data">
          </div>
          <div class="form-group">
            <label>备注</label>
            <input type="text" name="notes" value="<?= htmlspecialchars((string)($editAccount['notes'] ?? '')) ?>" placeholder="例如：同步工具、只读挂载">
          </div>
          <div class="form-group">
            <label>单文件上传上限（MB）</label>
            <input type="number" name="max_upload_mb" min="0" value="<?= (int)($editAccount['max_upload_mb'] ?? 0) ?>" placeholder="0 表示不限制">
          </div>
          <div class="form-group">
            <label>目录配额（MB）</label>
            <input type="number" name="quota_mb" min="0" value="<?= (int)($editAccount['quota_mb'] ?? 0) ?>" placeholder="0 表示不限制">
          </div>
          <div class="form-group" style="grid-column:1/-1">
            <label>IP 白名单</label>
            <textarea name="ip_whitelist" style="width:100%;min-height:90px;background:var(--bg);border:1px solid var(--bd);border-radius:10px;padding:12px;color:var(--tx);font-family:var(--mono)" placeholder="留空表示不限制，一行一个 IP 或 CIDR，例如：&#10;127.0.0.1&#10;172.16.0.0/12"><?= htmlspecialchars((string)($editAccount['ip_whitelist'] ?? '')) ?></textarea>
          </div>
          <div class="form-group" style="justify-content:flex-end;padding-bottom:4px">
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px;color:var(--tx)">
              <input type="checkbox" name="readonly" value="1" <?= !empty($editAccount['readonly']) ? 'checked' : '' ?> style="width:16px;height:16px;accent-color:var(--ac)">
              只读
            </label>
          </div>
          <div class="form-group" style="justify-content:flex-end;padding-bottom:4px">
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px;color:var(--tx)">
              <input type="checkbox" name="account_enabled" value="1" <?= !array_key_exists('enabled', $editAccount ?? []) || !empty($editAccount['enabled']) ? 'checked' : '' ?> style="width:16px;height:16px;accent-color:var(--ac)">
              启用账号
            </label>
          </div>
        </div>
        <div class="form-actions">
          <button type="submit" class="btn btn-primary">保存 WebDAV 账号</button>
          <?php if ($editAccount): ?><a href="webdav.php" class="btn btn-secondary">取消编辑</a><?php endif; ?>
          <?php if ($editAccount): ?><a href="files.php?host_id=local&path=<?= urlencode(webdav_display_local_root((string)($editAccount['root'] ?? '/'))) ?>" class="btn btn-secondary">打开文件系统目录</a><?php endif; ?>
        </div>
      </form>
      <?php if ($editAccount): ?>
        <div class="form-hint" style="margin-top:10px">
          当前账号目录：<code><?= htmlspecialchars(webdav_display_local_root((string)($editAccount['root'] ?? '/'))) ?></code>
          <?php if ($editAccountRelations): ?>
            ，该目录相关共享账号：
            <?php foreach ($editAccountRelations as $index => $related): ?>
              <?php if ($index > 0): ?>、<?php endif; ?>
              <a href="webdav.php?edit=<?= urlencode((string)($related['id'] ?? '')) ?>"><?= htmlspecialchars((string)($related['username'] ?? '')) ?></a>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>

    <div class="card">
      <div class="card-title">账号列表</div>
      <?php if (!$cfg['accounts']): ?>
        <div class="form-hint">暂无 WebDAV 账号。</div>
      <?php else: ?>
        <div class="table-wrap"><table>
          <thead><tr><th>用户名</th><th>根目录</th><th>权限</th><th>最近活动</th><th>限制</th><th>状态</th><th>操作</th></tr></thead>
          <tbody>
            <?php foreach ($cfg['accounts'] as $account): ?>
            <?php $recent = $recentMap[(string)($account['username'] ?? '')] ?? null; ?>
            <tr>
              <td style="font-weight:700"><?= htmlspecialchars((string)$account['username']) ?></td>
              <td style="font-family:var(--mono);font-size:12px">
                <?= htmlspecialchars((string)$account['root']) ?><br>
                <a href="files.php?host_id=local&path=<?= urlencode(webdav_display_local_root((string)($account['root'] ?? '/'))) ?>" style="font-family:inherit">打开目录</a>
              </td>
              <td><?= !empty($account['readonly']) ? '只读' : '读写' ?></td>
              <td style="font-size:12px;color:var(--tx2)">
                <?php if ($recent): ?>
                  <div><?= htmlspecialchars((string)($recent['time'] ?? '')) ?></div>
                  <div><?= htmlspecialchars((string)($recent['action'] ?? '')) ?></div>
                  <?php if (!empty($recent['detail'])): ?><div style="font-family:var(--mono)"><?= htmlspecialchars((string)$recent['detail']) ?></div><?php endif; ?>
                <?php else: ?>
                  <span class="form-hint">暂无访问</span>
                <?php endif; ?>
              </td>
              <td style="font-size:12px;color:var(--tx2)">
                单文件 <?= (int)($account['max_upload_mb'] ?? 0) > 0 ? ((int)$account['max_upload_mb'] . 'MB') : '不限' ?><br>
                配额 <?= (int)($account['quota_mb'] ?? 0) > 0 ? ((int)$account['quota_mb'] . 'MB') : '不限' ?>
              </td>
              <td>
                <span class="badge <?= !empty($account['enabled']) ? 'badge-green' : 'badge-gray' ?>"><?= !empty($account['enabled']) ? '启用' : '禁用' ?></span>
              </td>
              <td style="white-space:nowrap">
                <a class="btn btn-sm btn-secondary" href="webdav.php?edit=<?= urlencode((string)$account['id']) ?>">编辑</a>
                <form method="POST" style="display:inline">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="clone_webdav_account">
                  <input type="hidden" name="id" value="<?= htmlspecialchars((string)$account['id']) ?>">
                  <button type="submit" class="btn btn-sm btn-secondary">克隆</button>
                </form>
                <form method="POST" style="display:inline" class="webdav-reset-form">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="reset_webdav_password">
                  <input type="hidden" name="id" value="<?= htmlspecialchars((string)$account['id']) ?>">
                  <input type="hidden" name="new_password" value="">
                  <button type="submit" class="btn btn-sm btn-secondary">重置密码</button>
                </form>
                <form method="POST" style="display:inline">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="toggle_webdav_account">
                  <input type="hidden" name="id" value="<?= htmlspecialchars((string)$account['id']) ?>">
                  <button type="submit" class="btn btn-sm btn-secondary"><?= !empty($account['enabled']) ? '禁用' : '启用' ?></button>
                </form>
                <form method="POST" style="display:inline" onsubmit="return confirm('确认删除该 WebDAV 账号？')">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="delete_webdav_account">
                  <input type="hidden" name="id" value="<?= htmlspecialchars((string)$account['id']) ?>">
                  <button type="submit" class="btn btn-sm btn-danger">删除</button>
                </form>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table></div>
      <?php endif; ?>
    </div>
  </div>

  <div style="display:flex;flex-direction:column;gap:16px">
    <div class="card">
      <div class="card-title">连接信息</div>
      <div style="display:grid;grid-template-columns:1fr;gap:12px">
        <div style="border:1px solid var(--bd);border-radius:10px;padding:14px;background:var(--bg)">
          <div style="font-size:11px;color:var(--tm);margin-bottom:6px">访问地址</div>
          <div style="font-weight:700;font-family:var(--mono);word-break:break-all"><?= htmlspecialchars($webdavUrl) ?></div>
        </div>
        <div style="border:1px solid var(--bd);border-radius:10px;padding:14px;background:var(--bg)">
          <div style="font-size:11px;color:var(--tm);margin-bottom:6px">账号数量</div>
          <div style="font-weight:700"><?= (int)$cfg['account_count'] ?></div>
        </div>
        <div style="border:1px solid var(--bd);border-radius:10px;padding:14px;background:var(--bg)">
          <div style="font-size:11px;color:var(--tm);margin-bottom:6px">当前状态</div>
          <div style="font-weight:700"><?= $cfg['enabled'] ? '已启用' : '未启用' ?></div>
        </div>
      </div>
      <div class="form-hint" style="margin-top:12px">所有账号共用同一个 WebDAV 地址，不同账号登录后只会看到自己被分配的根目录。</div>
    </div>

    <div class="card">
      <div class="card-title">访问统计</div>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-bottom:12px">
        <div style="border:1px solid var(--bd);border-radius:10px;padding:14px;background:var(--bg)">
          <div style="font-size:11px;color:var(--tm);margin-bottom:6px">总账号数</div>
          <div style="font-weight:700"><?= (int)$stats['account_count'] ?></div>
        </div>
        <div style="border:1px solid var(--bd);border-radius:10px;padding:14px;background:var(--bg)">
          <div style="font-size:11px;color:var(--tm);margin-bottom:6px">启用账号</div>
          <div style="font-weight:700"><?= (int)$stats['enabled_count'] ?></div>
        </div>
        <div style="border:1px solid var(--bd);border-radius:10px;padding:14px;background:var(--bg)">
          <div style="font-size:11px;color:var(--tm);margin-bottom:6px">只读账号</div>
          <div style="font-weight:700"><?= (int)$stats['readonly_count'] ?></div>
        </div>
        <div style="border:1px solid var(--bd);border-radius:10px;padding:14px;background:var(--bg)">
          <div style="font-size:11px;color:var(--tm);margin-bottom:6px">总占用</div>
          <div style="font-weight:700"><?= number_format(((int)$stats['total_usage_bytes']) / 1024 / 1024, 2) ?> MB</div>
        </div>
      </div>
      <?php if (!empty($stats['accounts'])): ?>
        <div class="table-wrap"><table>
          <thead><tr><th>用户名</th><th>目录占用</th><th>根目录</th></tr></thead>
          <tbody>
            <?php foreach ($stats['accounts'] as $account): ?>
            <tr>
              <td style="font-weight:700"><?= htmlspecialchars((string)$account['username']) ?></td>
              <td><?= number_format(((int)($account['usage_bytes'] ?? 0)) / 1024 / 1024, 2) ?> MB</td>
              <td style="font-family:var(--mono);font-size:12px"><?= htmlspecialchars((string)$account['root']) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table></div>
      <?php endif; ?>
    </div>

    <div class="card">
      <div style="display:flex;justify-content:space-between;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:12px">
        <div class="card-title" style="margin:0">最近审计</div>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
          <a href="webdav_shares.php" class="btn btn-secondary">共享总览</a>
          <a href="webdav_audit.php" class="btn btn-secondary">打开独立审计页</a>
          <a href="webdav.php?action=audit_export&limit=500&action_name=<?= urlencode((string)($_GET['action_name'] ?? '')) ?>&log_user=<?= urlencode((string)($_GET['log_user'] ?? '')) ?>&keyword=<?= urlencode((string)($_GET['keyword'] ?? '')) ?>" class="btn btn-secondary">导出 JSON</a>
        </div>
      </div>
      <form method="GET" style="display:flex;gap:10px;flex-wrap:wrap;align-items:end;margin-bottom:12px">
        <div class="form-group" style="margin:0;min-width:140px">
          <label>动作</label>
          <input type="text" name="action_name" value="<?= htmlspecialchars((string)($_GET['action_name'] ?? '')) ?>" placeholder="如 put">
        </div>
        <div class="form-group" style="margin:0;min-width:140px">
          <label>用户</label>
          <input type="text" name="log_user" value="<?= htmlspecialchars((string)($_GET['log_user'] ?? '')) ?>" placeholder="如 webdavuser">
        </div>
        <div class="form-group" style="margin:0;min-width:220px;flex:1">
          <label>关键字</label>
          <input type="text" name="keyword" value="<?= htmlspecialchars((string)($_GET['keyword'] ?? '')) ?>" placeholder="搜索路径、用户名、上下文">
        </div>
        <button type="submit" class="btn btn-secondary">筛选</button>
        <a href="webdav.php" class="btn btn-secondary">清空</a>
      </form>
      <?php if (empty($logs['items'])): ?>
        <div class="form-hint">暂无符合条件的 WebDAV 审计日志。</div>
      <?php else: ?>
        <div class="table-wrap"><table>
          <thead><tr><th>时间</th><th>用户</th><th>动作</th><th>上下文</th></tr></thead>
          <tbody>
            <?php foreach (($logs['items'] ?? []) as $log): ?>
            <tr>
              <td style="font-family:var(--mono)"><?= htmlspecialchars((string)($log['time'] ?? '')) ?></td>
              <td><?= htmlspecialchars((string)($log['user'] ?? '')) ?></td>
              <td><?= htmlspecialchars((string)($log['action'] ?? '')) ?></td>
              <td><pre style="margin:0;white-space:pre-wrap;background:none;border:none;padding:0;color:var(--tx2);font-size:12px"><?= htmlspecialchars((string)json_encode($log['context'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table></div>
        <div class="form-hint" style="margin-top:10px">当前展示最新 <?= count($logs['items']) ?> 条，更多筛选和分页请进入独立审计页。</div>
      <?php endif; ?>
    </div>

    <div class="card">
      <div class="card-title">挂载说明</div>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px">
        <div style="border:1px solid var(--bd);border-radius:10px;padding:14px;background:var(--bg)">
          <div style="font-weight:700;margin-bottom:8px">Windows</div>
          <div class="form-hint">资源管理器中选择“映射网络驱动器”，地址填 <code><?= htmlspecialchars($webdavUrl) ?></code>。</div>
        </div>
        <div style="border:1px solid var(--bd);border-radius:10px;padding:14px;background:var(--bg)">
          <div style="font-weight:700;margin-bottom:8px">macOS</div>
          <div class="form-hint">Finder 中使用“前往服务器”，地址填 <code>dav://<?= htmlspecialchars($host) ?>/webdav/</code>。</div>
        </div>
        <div style="border:1px solid var(--bd);border-radius:10px;padding:14px;background:var(--bg)">
          <div style="font-weight:700;margin-bottom:8px">Linux</div>
          <div class="form-hint">文件管理器、<code>rclone</code>、<code>davfs2</code> 都可以直接挂载这个地址。</div>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
document.querySelectorAll('.webdav-reset-form').forEach(function(form) {
  form.addEventListener('submit', function(event) {
    var password = prompt('请输入新的 WebDAV 密码', '');
    if (!password) {
      event.preventDefault();
      return;
    }
    var input = form.querySelector('input[name="new_password"]');
    if (input) {
      input.value = password;
    }
  });
});
</script>

<?php require_once __DIR__ . '/shared/footer.php'; ?>
