<?php
$page_title='用户管理';
require_once __DIR__.'/shared/functions.php';

$current_admin = auth_get_current_user();
if (!$current_admin || ($current_admin['role'] ?? '') !== 'admin') {
  header('Location: /login.php');
  exit;
}

$users=auth_load_users();
$action=$_GET['action']??'list';$uname=$_GET['uname']??'';$err='';
$roleLabels = auth_role_labels();
if($_SERVER['REQUEST_METHOD']==='POST'){
  csrf_check();$act=$_POST['act']??'';
  $postAction = $_POST['action'] ?? '';

  if($act==='save'){
    $un=trim($_POST['username']??'');$pw=trim($_POST['password']??'');
    $role=$_POST['role']??'user';$orig=trim($_POST['orig_username']??'');
    $maxSessions = (int)($_POST['max_sessions'] ?? 3);
    if ($maxSessions < 1) $maxSessions = 1;
    if ($maxSessions > 20) $maxSessions = 20;
    $origRole = ($orig && isset($users[$orig])) ? ($users[$orig]['role'] ?? 'user') : null;
    $adminCount = count(array_filter($users, fn($u) => ($u['role'] ?? 'user') === 'admin'));
    if(!preg_match('/^[a-zA-Z0-9_-]{2,32}$/',$un))$err='用户名只允许字母数字下划线横杠，2-32位';
    elseif(!array_key_exists($role,$roleLabels))$err='角色无效';
    elseif(!$orig&&!$pw)$err='新用户必须设置密码';
    elseif($orig && $origRole==='admin' && $role!=='admin' && $adminCount<=1)$err='至少保留一个管理员账户';
    else{
      if($orig&&$orig!==$un){$users[$un]=$users[$orig]??[];unset($users[$orig]);}
      if(!isset($users[$un]))$users[$un]=[];
      if($pw){$users[$un]['password_hash']=password_hash($pw,PASSWORD_BCRYPT,['cost'=>10]);$users[$un]['updated_at']=date('Y-m-d H:i:s');unset($users[$un]['__dev_virtual']);}
      if(!isset($users[$un]['created_at']))$users[$un]['created_at']=date('Y-m-d H:i:s');
      $users[$un]['role']=$role;
      $users[$un]['permissions']=auth_role_permissions_map()[$role] ?? [];
      $users[$un]['max_sessions']=$maxSessions;
      auth_write_users($users);
      audit_log('user_save', ['username' => $un, 'role' => $role, 'orig' => $orig, 'max_sessions' => $maxSessions]);
      flash_set('success',"用户 '{$un}' 已保存");
      header('Location: users.php');exit;
    }
  }
  if($act==='delete'){
    $du=$_POST['del_user']??'';
    $adminCount = count(array_filter($users, fn($u) => ($u['role'] ?? 'user') === 'admin'));
    if($du===$current_admin['username'])$err='不能删除当前登录的自己';
    elseif((($users[$du]['role'] ?? 'user')==='admin') && $adminCount<=1)$err='至少保留一个管理员账户';
    else{
      unset($users[$du]);
      auth_write_users($users);
      if (file_exists(SESSIONS_FILE)) {
        $sessions = json_decode(file_get_contents(SESSIONS_FILE), true) ?? [];
        $changed = false;
        foreach ($sessions as $jti => $meta) {
          if (($meta['username'] ?? '') === $du) {
            unset($sessions[$jti]);
            $changed = true;
          }
        }
        if ($changed) {
          file_put_contents(SESSIONS_FILE, json_encode($sessions, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
        }
      }
      audit_log('user_delete',['username'=>$du]);
      flash_set('success','已删除');
      header('Location: users.php');
      exit;
    }
  }
}

// ── AJAX 批量操作路由 ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest') {
    header('Content-Type: application/json; charset=utf-8');
    csrf_check();
    $act = $_POST['act'] ?? '';
    $selected = $_POST['usernames'] ?? [];
    if (!is_array($selected)) $selected = [$selected];
    $selected = array_filter(array_map('trim', $selected));

    if ($act === 'batch_delete') {
        $skipped = 0; $deleted = 0;
        $adminCount = count(array_filter($users, fn($u) => ($u['role'] ?? 'user') === 'admin'));
        foreach ($selected as $du) {
            if ($du === $current_admin['username']) { $skipped++; continue; }
            if ((($users[$du]['role'] ?? 'user') === 'admin') && $adminCount <= 1) { $skipped++; continue; }
            if (!isset($users[$du])) { $skipped++; continue; }
            unset($users[$du]);
            $adminCount--;
            $deleted++;
        }
        if ($deleted > 0) {
            auth_write_users($users);
            if (file_exists(SESSIONS_FILE)) {
                $sessions = json_decode(file_get_contents(SESSIONS_FILE), true) ?? [];
                $changed = false;
                foreach ($sessions as $jti => $meta) {
                    if (in_array($meta['username'] ?? '', $selected, true)) {
                        unset($sessions[$jti]);
                        $changed = true;
                    }
                }
                if ($changed) {
                    file_put_contents(SESSIONS_FILE, json_encode($sessions, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
                }
            }
            audit_log('user_batch_delete', ['usernames' => $selected, 'deleted' => $deleted, 'skipped' => $skipped]);
        }
        echo json_encode(['ok' => true, 'msg' => "已删除 {$deleted} 个用户" . ($skipped > 0 ? "，跳过 {$skipped} 个（含当前管理员或仅剩的管理员）" : ''), 'deleted' => $deleted, 'skipped' => $skipped], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($act === 'batch_set_max_sessions') {
        $maxSess = (int)($_POST['max_sessions'] ?? 3);
        if ($maxSess < 1) $maxSess = 1;
        if ($maxSess > 20) $maxSess = 20;
        $updated = 0;
        foreach ($selected as $un) {
            if (!isset($users[$un])) continue;
            $users[$un]['max_sessions'] = $maxSess;
            $updated++;
        }
        if ($updated > 0) auth_write_users($users);
        audit_log('user_batch_set_max_sessions', ['usernames' => $selected, 'max_sessions' => $maxSess, 'updated' => $updated]);
        echo json_encode(['ok' => true, 'msg' => "已设置 {$updated} 个用户的设备上限为 {$maxSess}", 'updated' => $updated], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($act === 'batch_kick') {
        $kicked = 0;
        foreach ($selected as $un) {
            $sessions = auth_session_list($un);
            foreach ($sessions as $s) {
                if (!empty($s['jti']) && auth_session_revoke($s['jti'])) {
                    $kicked++;
                }
            }
        }
        audit_log('user_batch_kick', ['usernames' => $selected, 'kicked' => $kicked]);
        echo json_encode(['ok' => true, 'msg' => "已强制下线 {$kicked} 个会话", 'kicked' => $kicked], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode(['ok' => false, 'msg' => '未知操作'], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once __DIR__.'/shared/header.php';
$eu=null;
if($action==='edit'&&$uname&&isset($users[$uname]))$eu=$users[$uname]+['username'=>$uname];
$sf=($action==='add'||$action==='edit');
?>
<?php if($err):?><div class="alert alert-error">❌ <?=htmlspecialchars($err)?></div><?php endif;?>
<?php if($sf):?>
<div class="card"><div class="card-title"><?=$eu?'编辑':'添加'?>用户</div>
<form method="POST"><?=csrf_field()?><input type="hidden" name="act" value="save">
<input type="hidden" name="orig_username" value="<?=htmlspecialchars($eu['username']??'')?>">
<div class="form-grid">
  <div class="form-group"><label>用户名</label>
    <input type="text" name="username" required pattern="[a-zA-Z0-9_-]{2,32}" value="<?=htmlspecialchars($eu['username']??'')?>"></div>
  <div class="form-group"><label>角色</label><select name="role">
    <?php foreach($roleLabels as $roleValue=>$roleLabel): ?>
    <option value="<?=htmlspecialchars($roleValue)?>" <?=($eu['role']??'user')===$roleValue?'selected':''?>><?=htmlspecialchars($roleLabel)?></option>
    <?php endforeach; ?>
  </select></div>
  <div class="form-group"><label>最大同时在线设备数</label>
    <input type="number" name="max_sessions" min="1" max="20" value="<?= (int)($eu['max_sessions'] ?? 3) ?>">
    <div class="form-hint">超出后新登录需踢掉已有设备</div></div>
  <div class="form-group full"><label>密码<?=$eu?' （留空不修改）':' （必填）'?></label>
    <input type="password" name="password" <?=!$eu?'required':''?> autocomplete="new-password"></div>
  <div class="form-group full">
    <label>角色说明</label>
    <div class="form-hint">
      管理员：拥有全部后台权限。<br>
      主机管理员：已无专属模块权限（原主机管理功能已移除）。<br>
      主机只读：已无专属模块权限（原主机管理功能已移除）。<br>
      普通用户：仅可登录前台，不可进入后台管理页。
    </div>
  </div>
</div>
<div class="form-actions"><button type="submit" class="btn btn-primary">💾 保存</button><a href="users.php" class="btn btn-secondary">取消</a></div>
</form></div>
<?php else:?>
<div class="toolbar" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
  <a href="users.php?action=add" class="btn btn-primary">➕ 添加用户</a>
  <div id="batch-bar" style="display:none;gap:8px;align-items:center">
    <span style="color:var(--tm);font-size:13px">已选中 <strong id="batch-count">0</strong> 个</span>
    <button type="button" class="btn btn-sm" style="background:rgba(255,107,107,.12);border:1px solid rgba(255,107,107,.35);color:#ff6b6b" onclick="batchDelete()">批量删除</button>
    <button type="button" class="btn btn-sm" style="background:rgba(77,184,255,.12);border:1px solid rgba(77,184,255,.35);color:var(--blue)" onclick="batchSetMax()">批量设置设备上限</button>
    <button type="button" class="btn btn-sm" style="background:rgba(255,204,68,.12);border:1px solid rgba(255,204,68,.35);color:var(--yellow)" onclick="batchKick()">批量踢下线</button>
  </div>
</div>
<div class="card"><div class="table-wrap"><table><thead><tr>
  <th style="width:36px"><input type="checkbox" id="select-all" title="全选"></th><th>用户名</th><th>状态</th><th>角色</th><th>在线设备</th><th>创建时间</th><th>更新时间</th><th>操作</th>
</tr></thead><tbody>
<?php foreach($users as $un=>$u):?><tr>
  <td><?php if($un!==$current_admin['username']):?><input type="checkbox" class="user-select" value="<?=htmlspecialchars($un)?>"><?php endif;?></td>
  <td><strong><?=htmlspecialchars($un)?></strong><?php if($un===$current_admin['username']):?> <span class="badge badge-purple">我</span><?php endif;?><?php if(!empty($u['__dev_virtual'])):?> <span class="badge badge-gray" title="开发模式内置，不写入 users.json">dev</span><?php endif;?></td>
  <?php $online = auth_user_online_status($un); ?>
  <td><span class="badge badge-<?=$online['status']==='online'?'green':($online['status']==='recent'?'yellow':'gray')?>"><?=htmlspecialchars($online['label'])?></span></td>
  <?php $roleValue = (string)($u['role'] ?? 'user'); ?>
  <?php $activeCount = auth_user_active_session_count($un); $maxSess = auth_user_max_sessions($un); ?>
  <td><span class="badge badge-blue"><?= (int)$activeCount ?> / <?= (int)$maxSess ?></span></td>
  <td><span class="badge <?=($roleValue)==='admin'?'badge-red':(($roleValue)==='user'?'badge-green':'badge-purple')?>"><?=htmlspecialchars(($roleLabels[$roleValue] ?? $roleValue) . ' (' . $roleValue . ')')?></span></td>
  <td><?=htmlspecialchars($u['created_at']??'-')?></td>
  <td><?=htmlspecialchars($u['updated_at']??'-')?></td>
  <td style="white-space:nowrap">
    <a href="users.php?action=edit&uname=<?=urlencode($un)?>" class="btn btn-sm btn-secondary">编辑</a>
    <a href="sessions.php?username=<?=urlencode($un)?>" class="btn btn-sm btn-secondary">查看会话</a>
    <?php if($un!==$current_admin['username']):?>
    <form method="POST" style="display:inline" data-confirm-title="删除用户" data-confirm-message="确认删除用户「<?= htmlspecialchars($un) ?>」？"><?=csrf_field()?>
      <input type="hidden" name="act" value="delete">
      <input type="hidden" name="del_user" value="<?=htmlspecialchars($un)?>">
      <button type="button" class="btn btn-sm btn-danger" onclick="submitConfirmForm(this)">删除</button>
    </form><?php endif;?>
  </td>
</tr><?php endforeach;?>
</tbody></table></div></div>
<script>
(function(){
  var selectAll = document.getElementById('select-all');
  var checkboxes = document.querySelectorAll('.user-select');
  var batchBar = document.getElementById('batch-bar');
  var batchCount = document.getElementById('batch-count');

  function updateBatchBar() {
    var checked = Array.from(checkboxes).filter(function(c){ return c.checked; });
    batchCount.textContent = checked.length;
    batchBar.style.display = checked.length > 0 ? 'flex' : 'none';
  }

  if (selectAll) {
    selectAll.addEventListener('change', function(){
      checkboxes.forEach(function(c){ c.checked = selectAll.checked; });
      updateBatchBar();
    });
  }
  checkboxes.forEach(function(c){
    c.addEventListener('change', updateBatchBar);
  });

  function getSelected() {
    return Array.from(checkboxes).filter(function(c){ return c.checked; }).map(function(c){ return c.value; });
  }

  function sendBatch(act, extra) {
    var usernames = getSelected();
    if (!usernames.length) { showToast('请先选择用户', 'error'); return; }
    var fd = new FormData();
    fd.append('_csrf', window._csrf || '');
    fd.append('act', act);
    usernames.forEach(function(u){ fd.append('usernames[]', u); });
    if (extra) { Object.keys(extra).forEach(function(k){ fd.append(k, extra[k]); }); }
    fetch('users.php', {
      method: 'POST',
      body: fd,
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    }).then(function(r){ return r.json(); }).then(function(d){
      showToast(d.ok ? d.msg : (d.msg || '操作失败'), d.ok ? 'success' : 'error');
      if (d.ok) setTimeout(function(){ location.reload(); }, 800);
    }).catch(function(){ showToast('请求失败', 'error'); });
  }

  window.batchDelete = function() {
    NavConfirm.open({
      title: '批量删除用户',
      message: '确认删除选中的 ' + getSelected().length + ' 个用户？当前登录的管理员不会被删除。',
      confirmText: '确认删除',
      cancelText: '取消',
      danger: true,
      onConfirm: function() { sendBatch('batch_delete'); }
    });
  };
  window.batchSetMax = function() {
    var val = prompt('请输入最大同时在线设备数（1-20）：', '3');
    if (val === null) return;
    var n = parseInt(val, 10);
    if (isNaN(n) || n < 1 || n > 20) { showToast('请输入 1-20 之间的数字', 'error'); return; }
    sendBatch('batch_set_max_sessions', { max_sessions: n });
  };
  window.batchKick = function() {
    NavConfirm.open({
      title: '批量强制下线',
      message: '确认将选中的 ' + getSelected().length + ' 个用户的所有会话强制下线？',
      confirmText: '确认下线',
      cancelText: '取消',
      danger: true,
      onConfirm: function() { sendBatch('batch_kick'); }
    });
  };
})();
</script>

<?php endif;?>
<?php require_once __DIR__.'/shared/footer.php';?>
