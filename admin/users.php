<?php
$page_title='用户管理';
require_once __DIR__.'/shared/header.php';
$users=auth_load_users();
$action=$_GET['action']??'list';$uname=$_GET['uname']??'';$err='';
if($_SERVER['REQUEST_METHOD']==='POST'){
  csrf_check();$act=$_POST['act']??'';
  if($act==='save'){
    $un=trim($_POST['username']??'');$pw=trim($_POST['password']??'');
    $role=$_POST['role']??'user';$orig=trim($_POST['orig_username']??'');
    if(!preg_match('/^[a-zA-Z0-9_-]{2,32}$/',$un))$err='用户名只允许字母数字下划线横杠，2-32位';
    elseif(!in_array($role,['admin','user']))$err='角色无效';
    elseif(!$orig&&!$pw)$err='新用户必须设置密码';
    else{
      if($orig&&$orig!==$un){$users[$un]=$users[$orig]??[];unset($users[$orig]);}
      if(!isset($users[$un]))$users[$un]=[];
      if($pw){$users[$un]['password_hash']=password_hash($pw,PASSWORD_BCRYPT,['cost'=>10]);$users[$un]['updated_at']=date('Y-m-d H:i:s');}
      if(!isset($users[$un]['created_at']))$users[$un]['created_at']=date('Y-m-d H:i:s');
      $users[$un]['role']=$role;
      auth_write_users($users);flash_set('success',"用户 '{$un}' 已保存");
      header('Location: users.php');exit;
    }
  }
  if($act==='delete'){
    $du=$_POST['del_user']??'';
    if($du===$current_admin['username'])$err='不能删除当前登录的自己';
    else{unset($users[$du]);auth_write_users($users);flash_set('success','已删除');header('Location: users.php');exit;}
  }
}
$eu=null;
if($action==='edit'&&$uname&&isset($users[$uname]))$eu=$users[$uname]+['username'=>$uname];
$sf=($action==='add'||$action==='edit');
$fs=flash_get('success');
?>
<?php if($fs):?><div class="alert alert-success">✅ <?=htmlspecialchars($fs)?></div><?php endif;?>
<?php if($err):?><div class="alert alert-error">❌ <?=htmlspecialchars($err)?></div><?php endif;?>
<?php if($sf):?>
<div class="card"><div class="card-title"><?=$eu?'编辑':'添加'?>用户</div>
<form method="POST"><?=csrf_field()?><input type="hidden" name="act" value="save">
<input type="hidden" name="orig_username" value="<?=htmlspecialchars($eu['username']??'')?>">
<div class="form-grid">
  <div class="form-group"><label>用户名</label>
    <input type="text" name="username" required pattern="[a-zA-Z0-9_-]{2,32}" value="<?=htmlspecialchars($eu['username']??'')?>"></div>
  <div class="form-group"><label>角色</label><select name="role">
    <option value="user"  <?=($eu['role']??'user')==='user'?'selected':''?>>普通用户</option>
    <option value="admin" <?=($eu['role']??'')==='admin'?'selected':''?>>管理员</option>
  </select></div>
  <div class="form-group full"><label>密码<?=$eu?' （留空不修改）':' （必填）'?></label>
    <input type="password" name="password" <?=!$eu?'required':''?> autocomplete="new-password"></div>
</div>
<div class="form-actions"><button type="submit" class="btn btn-primary">💾 保存</button><a href="users.php" class="btn btn-secondary">取消</a></div>
</form></div>
<?php else:?>
<div class="toolbar"><a href="users.php?action=add" class="btn btn-primary">➕ 添加用户</a></div>
<div class="card"><div class="table-wrap"><table><thead><tr>
  <th>用户名</th><th>角色</th><th>创建时间</th><th>更新时间</th><th>操作</th>
</tr></thead><tbody>
<?php foreach($users as $un=>$u):?><tr>
  <td><strong><?=htmlspecialchars($un)?></strong><?php if($un===$current_admin['username']):?> <span class="badge badge-purple">我</span><?php endif;?></td>
  <td><span class="badge <?=($u['role']??'')==='admin'?'badge-red':'badge-green'?>"><?=htmlspecialchars($u['role']??'user')?></span></td>
  <td><?=htmlspecialchars($u['created_at']??'-')?></td>
  <td><?=htmlspecialchars($u['updated_at']??'-')?></td>
  <td style="white-space:nowrap">
    <a href="users.php?action=edit&uname=<?=urlencode($un)?>" class="btn btn-sm btn-secondary">编辑</a>
    <?php if($un!==$current_admin['username']):?>
    <form method="POST" style="display:inline" onsubmit="return confirm('确认删除用户？')"><?=csrf_field()?>
      <input type="hidden" name="act" value="delete">
      <input type="hidden" name="del_user" value="<?=htmlspecialchars($un)?>">
      <button type="submit" class="btn btn-sm btn-danger">删除</button>
    </form><?php endif;?>
  </td>
</tr><?php endforeach;?>
</tbody></table></div></div>
<?php endif;?>
<?php require_once __DIR__.'/shared/footer.php';?>
