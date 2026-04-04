<?php
/**
 * 备份与恢复 admin/backups.php
 */

// ── 所有 POST/GET 操作必须在 header.php 之前处理（避免 HTML 已输出导致 header() 失效）──
if (isset($_GET['download']) || $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/shared/functions.php';
    $current_user = auth_get_current_user();
    if (!$current_user || ($current_user['role'] ?? '') !== 'admin') {
        header('Location: /login.php'); exit;
    }

    // 下载备份
    if (isset($_GET['download'])) {
        $file = basename($_GET['download']);
        if (!preg_match('/^backup_[\d_a-z]+\.json$/', $file)) {
            http_response_code(400); exit('Invalid filename');
        }
        $path = BACKUPS_DIR . '/' . $file;
        if (!file_exists($path)) { http_response_code(404); exit('Not found'); }
        $content = file_get_contents($path);
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $file . '"');
        header('Content-Length: ' . strlen($content));
        echo $content; exit;
    }

    // POST 操作
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $action = $_POST['action'] ?? '';

        if ($action === 'create') {
            backup_create('manual');
            flash_set('success', '备份已创建');
            header('Location: backups.php'); exit;
        }

        if ($action === 'restore') {
            $file = basename($_POST['filename'] ?? '');
            if (backup_restore($file)) {
                flash_set('success', "已恢复备份：{$file}，恢复前的状态已自动备份");
            } else {
                flash_set('error', '恢复失败，文件不存在或格式无效');
            }
            header('Location: backups.php'); exit;
        }

        if ($action === 'delete') {
            $file = basename($_POST['filename'] ?? '');
            if (backup_delete($file)) {
                flash_set('success', '备份已删除');
            } else {
                flash_set('error', '删除失败');
            }
            header('Location: backups.php'); exit;
        }
    }
}

$page_title = '备份与恢复';
require_once __DIR__ . '/shared/header.php';

$backups = backup_list();

// 触发方式中文映射
function trigger_label(string $t): string {
    $map = [
        'manual'              => '手动',
        'auto_import'         => '自动-导入',
        'auto_settings'       => '自动-设置',
        'auto_before_restore' => '自动-恢复前',
    ];
    return isset($map[$t]) ? $map[$t] : $t;
}

function trigger_badge(string $t): string {
    $map = [
        'manual'              => 'badge-blue',
        'auto_import'         => 'badge-yellow',
        'auto_settings'       => 'badge-purple',
        'auto_before_restore' => 'badge-red',
    ];
    return isset($map[$t]) ? $map[$t] : 'badge-gray';
}
?>

<div class="toolbar">
  <form method="POST" style="display:inline">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="create">
    <button class="btn btn-primary">💾 立即备份</button>
  </form>
  <span style="color:var(--tm);font-size:13px">共 <?= count($backups) ?> / <?= MAX_BACKUPS ?> 条备份</span>
</div>

<div class="card">
  <?php if (empty($backups)): ?>
    <p style="color:var(--tm);font-size:13px;padding:8px 0">暂无备份记录</p>
  <?php else: ?>
  <div class="table-wrap"><table>
    <tr><th>备份时间</th><th>触发方式</th><th>分组数</th><th>站点数</th><th>大小</th><th>操作</th></tr>
    <?php foreach ($backups as $bk): ?>
    <tr>
      <td style="font-family:monospace;font-size:12px;white-space:nowrap">
        <?= htmlspecialchars($bk['created_at']) ?></td>
      <td><span class="badge <?= trigger_badge($bk['trigger']) ?>">
        <?= htmlspecialchars(trigger_label($bk['trigger'])) ?></span></td>
      <td><?= $bk['groups_count'] ?></td>
      <td><?= $bk['sites_count'] ?></td>
      <td style="font-size:12px"><?= round($bk['size']/1024, 1) ?> KB</td>
      <td>
        <a href="?download=<?= urlencode($bk['filename']) ?>"
           class="btn btn-sm btn-secondary">⬇ 下载</a>
        <form method="POST" style="display:inline"
              onsubmit="return confirm('确认恢复此备份？当前配置将被覆盖（会自动备份当前状态）')">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="restore">
          <input type="hidden" name="filename" value="<?= htmlspecialchars($bk['filename']) ?>">
          <button type="submit" class="btn btn-sm btn-secondary">🔄 恢复</button>
        </form>
        <form method="POST" style="display:inline"
              onsubmit="return confirm('确认删除此备份？')">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="filename" value="<?= htmlspecialchars($bk['filename']) ?>">
          <button type="submit" class="btn btn-sm btn-danger">删除</button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
  </table></div>
  <?php endif; ?>
</div>

<div class="card">
  <div class="card-title">ℹ️ 备份方案说明</div>
  <ul style="color:var(--tm);font-size:13px;line-height:2;padding-left:18px">
    <li><strong>单文件 JSON</strong>：与「设置 → 导出配置」结构相同，一条记录对应一次快照。</li>
    <li><strong>包含内容</strong>：<code>sites</code>（站点分组）、<code>config</code>（系统配置）、<code>scheduled_tasks</code>（计划任务定义，含每条任务的 <code>command</code> 脚本与 cron 表达式）、<code>dns_config</code>（域名解析服务商账户与凭据）。</li>
    <li><strong>不含内容</strong>：用户账户（<code>users.json</code>）、登录日志、Favicon 缓存、计划任务运行日志（<code>data/logs/cron_*.log</code>）、DNS Zone 列表缓存；若任务使用「任务工作区」目录，<code>data/tasks/&lt;id&gt;/</code> 下的额外文件需另行备份。</li>
    <li><strong>恢复与导入</strong>：写入计划任务后会重新生成系统 crontab；写入 DNS 配置后会清除本机 DNS Zone 缓存。</li>
    <li>最多保留 <?= MAX_BACKUPS ?> 条，超出时自动删除最旧的；恢复或导入前会先自动备份当前状态。</li>
    <li>触发方式：手动 / 自动-导入 / 自动-设置 / 自动-恢复前（见列表中「触发方式」列）。</li>
  </ul>
</div>

<?php require_once __DIR__ . '/shared/footer.php'; ?>
