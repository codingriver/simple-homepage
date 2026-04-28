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

  // ── API Token 管理 ──
  if ($postAction === 'generate_api_token') {
      $name = trim($_POST['token_name'] ?? '');
      if ($name === '') {
          flash_set('error', 'Token 名称不能为空');
          header('Location: users.php#api-tokens'); exit;
      }
      $token = api_token_generate($name);
      audit_log('generate_api_token', ['name' => $name]);
      if (session_status() !== PHP_SESSION_ACTIVE) session_start();
      $_SESSION['_api_token_new'] = $token;
      flash_set('success', 'API Token 已生成');
      header('Location: users.php#api-tokens'); exit;
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
      header('Location: users.php#api-tokens'); exit;
  }

  if($act==='save'){
    $un=trim($_POST['username']??'');$pw=trim($_POST['password']??'');
    $role=$_POST['role']??'user';$orig=trim($_POST['orig_username']??'');
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
      auth_write_users($users);
      audit_log('user_save', ['username' => $un, 'role' => $role, 'orig' => $orig]);
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

require_once __DIR__.'/shared/header.php';
$eu=null;
if($action==='edit'&&$uname&&isset($users[$uname]))$eu=$users[$uname]+['username'=>$uname];
$sf=($action==='add'||$action==='edit');
$flash_msg=flash_get();
?>
<?php if($flash_msg):?><div class="alert alert-<?= htmlspecialchars($flash_msg['type'] ?? 'success') ?>">✅ <?=htmlspecialchars($flash_msg['msg'] ?? '')?></div><?php endif;?>
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
  <div class="form-group full"><label>密码<?=$eu?' （留空不修改）':' （必填）'?></label>
    <input type="password" name="password" <?=!$eu?'required':''?> autocomplete="new-password"></div>
  <div class="form-group full">
    <label>角色说明</label>
    <div class="form-hint">
      管理员：拥有全部后台权限。<br>
      主机管理员：仅可进入 SSH / 主机管理模块，可管理本机 SSH、远程主机、密钥、文件和终端。<br>
      主机只读：仅可查看主机状态和 SSH 审计日志。<br>
      普通用户：仅可登录前台，不可进入后台管理页。
    </div>
  </div>
</div>
<div class="form-actions"><button type="submit" class="btn btn-primary">💾 保存</button><a href="users.php" class="btn btn-secondary">取消</a></div>
</form></div>
<?php else:?>
<div class="toolbar"><a href="users.php?action=add" class="btn btn-primary">➕ 添加用户</a></div>
<div class="card"><div class="table-wrap"><table><thead><tr>
  <th>用户名</th><th>角色</th><th>创建时间</th><th>更新时间</th><th>操作</th>
</tr></thead><tbody>
<?php foreach($users as $un=>$u):?><tr>
  <td><strong><?=htmlspecialchars($un)?></strong><?php if($un===$current_admin['username']):?> <span class="badge badge-purple">我</span><?php endif;?><?php if(!empty($u['__dev_virtual'])):?> <span class="badge badge-gray" title="开发模式内置，不写入 users.json">dev</span><?php endif;?></td>
  <?php $roleValue = (string)($u['role'] ?? 'user'); ?>
  <td><span class="badge <?=($roleValue)==='admin'?'badge-red':(($roleValue)==='user'?'badge-green':'badge-purple')?>"><?=htmlspecialchars(($roleLabels[$roleValue] ?? $roleValue) . ' (' . $roleValue . ')')?></span></td>
  <td><?=htmlspecialchars($u['created_at']??'-')?></td>
  <td><?=htmlspecialchars($u['updated_at']??'-')?></td>
  <td style="white-space:nowrap">
    <a href="users.php?action=edit&uname=<?=urlencode($un)?>" class="btn btn-sm btn-secondary">编辑</a>
    <a href="sessions.php?username=<?=urlencode($un)?>" class="btn btn-sm btn-secondary">查看会话</a>
    <?php if($un!==$current_admin['username']):?>
    <form method="POST" style="display:inline" onsubmit="return confirm('确认删除用户？')"><?=csrf_field()?>
      <input type="hidden" name="act" value="delete">
      <input type="hidden" name="del_user" value="<?=htmlspecialchars($un)?>">
      <button type="submit" class="btn btn-sm btn-danger">删除</button>
    </form><?php endif;?>
  </td>
</tr><?php endforeach;?>
</tbody></table></div></div>

<!-- API Token 管理 -->
<div class="card" id="api-tokens">
  <div class="card-title">🔑 API Token 管理</div>
  <?php $apiTokens = api_tokens_load(); ?>
  <?php if (!empty($apiTokens)): ?>
  <div style="margin-bottom:14px">
    <table class="data-table" style="width:100%">
      <thead><tr><th>名称</th><th>Token</th><th>创建时间</th><th>操作</th></tr></thead>
      <tbody>
      <?php foreach ($apiTokens as $tk => $meta): ?>
      <tr>
        <td><?= htmlspecialchars($meta['name'] ?? '') ?></td>
        <td><code style="font-size:12px"><?= htmlspecialchars(api_token_mask($tk)) ?></code></td>
        <td style="font-size:12px;color:var(--tm)"><?= htmlspecialchars($meta['created_at'] ?? '') ?></td>
        <td>
          <form method="POST" style="display:inline" onsubmit="return confirm('确认删除该 Token？')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete_api_token">
            <input type="hidden" name="token" value="<?= htmlspecialchars($tk) ?>">
            <button type="submit" class="btn btn-sm btn-danger">删除</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php else: ?>
  <p style="color:var(--tm);font-size:13px;margin-bottom:14px">暂无 API Token，点击下方按钮生成。</p>
  <?php endif; ?>

  <form method="POST" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="generate_api_token">
    <input type="text" name="token_name" placeholder="Token 名称（如：HomeAssistant）" required
           style="flex:1;min-width:200px;background:var(--bg);border:1px solid var(--bd);border-radius:8px;padding:8px 12px;color:var(--tx);font-size:14px">
    <button type="submit" class="btn btn-primary">生成 Token</button>
  </form>

  <?php if (!empty($_SESSION['_api_token_new'])): ?>
  <div class="alert alert-success" style="margin-top:14px">
    <div style="font-weight:700;margin-bottom:6px">✅ Token 生成成功，请立即复制保存（仅显示一次）</div>
    <code id="newApiToken" style="display:block;background:var(--bg);padding:8px 10px;border-radius:6px;font-size:12px;word-break:break-all"><?= htmlspecialchars($_SESSION['_api_token_new']) ?></code>
    <button type="button" class="btn btn-sm btn-secondary" style="margin-top:8px" onclick="copyApiToken()">复制</button>
  </div>
  <?php unset($_SESSION['_api_token_new']); ?>
  <?php endif; ?>

  <div class="form-hint" style="margin-top:10px">
    使用方式：<code>GET /api/sites.php?token=&lt;TOKEN&gt;</code> 或 Header <code>Authorization: Bearer &lt;TOKEN&gt;</code>
  </div>
</div>
<script>
function copyApiToken() {
    var el = document.getElementById('newApiToken');
    if (!el) return;
    navigator.clipboard.writeText(el.textContent).then(function(){
        showToast('已复制到剪贴板', 'success');
    }).catch(function(){
        showToast('复制失败', 'error');
    });
}
</script>

<?php endif;?>
<?php require_once __DIR__.'/shared/footer.php';?>
