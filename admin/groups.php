<?php
/**
 * 分组管理 admin/groups.php
 * 增删改查分组，支持 auth_required / visible_to / order 设置
 */

// 统一处理保存/删除逻辑（AJAX 与普通表单共用）
function groups_handle_post(array &$sites_data): array {
    $groups = &$sites_data['groups'];
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $old_id  = $_POST['old_id']    ?? '';
        $id      = trim($_POST['gid']  ?? '');
        $name    = trim($_POST['name'] ?? '');
        $icon    = trim($_POST['icon'] ?? '📁');
        $order   = (int)($_POST['order'] ?? 0);
        $auth    = isset($_POST['auth_required']) && $_POST['auth_required'] === '1';
        $visible = in_array($_POST['visible_to'] ?? 'all', ['all','admin'], true)
            ? $_POST['visible_to'] : 'all';

        if (!preg_match('/^[a-z0-9_-]+$/', $id) || !$name) {
            return ['ok' => false, 'msg' => 'ID 只允许小写字母/数字/下划线/横杠，名称不能为空'];
        }

        if ($old_id && $old_id !== $id) {
            foreach ($groups as &$g) { if ($g['id'] === $old_id) $g['id'] = $id; }
            unset($g);
        }

        $found = false;
        foreach ($groups as &$g) {
            if ($g['id'] === $id) {
                $g['name']=$name; $g['icon']=$icon; $g['order']=$order;
                $g['auth_required']=$auth; $g['visible_to']=$visible;
                $found=true; break;
            }
        }
        unset($g);

        if (!$found) {
            $groups[] = ['id'=>$id,'name'=>$name,'icon'=>$icon,'order'=>$order,
                'auth_required'=>$auth,'visible_to'=>$visible,'sites'=>[]];
        }

        usort($groups, function($a,$b){ return ($a['order']??0)-($b['order']??0); });
        save_sites($sites_data);
        audit_log('group_save', ['gid' => $id, 'name' => $name]);
        flash_set('success', '分组已保存');
        return ['ok' => true, 'msg' => '分组已保存'];
    }

    if ($action === 'delete') {
        $id = $_POST['gid'] ?? '';
        $sites_data['groups'] = array_values(
            array_filter($groups, function($g) use ($id){ return $g['id'] !== $id; })
        );
        save_sites($sites_data);
        audit_log('group_delete', ['gid' => $id]);
        flash_set('success', '分组及其站点已删除');
        return ['ok' => true, 'msg' => '分组及其站点已删除'];
    }

    if ($action === 'reorder_groups') {
        $orders = $_POST['orders'] ?? [];
        if (!is_array($orders) || empty($orders)) {
            return ['ok' => false, 'msg' => '缺少排序数据'];
        }
        $map = [];
        foreach ($orders as $item) {
            $parts = explode(':', $item);
            if (count($parts) === 2) {
                $map[$parts[0]] = (int)$parts[1];
            }
        }
        foreach ($groups as &$g) {
            if (isset($map[$g['id']])) {
                $g['order'] = $map[$g['id']];
            }
        }
        unset($g);
        usort($groups, function($a,$b){ return ($a['order']??0)-($b['order']??0); });
        $sites_data['groups'] = $groups;
        save_sites($sites_data);
        audit_log('group_reorder', ['count' => count($orders)]);
        return ['ok' => true, 'msg' => '排序已保存'];
    }

    return ['ok' => false, 'msg' => '未知操作'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/shared/functions.php';
    $is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']);
    $current_user = auth_get_current_user();
    if (!$current_user || ($current_user['role'] ?? '') !== 'admin') {
        if ($is_ajax) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(401);
            echo json_encode(['ok' => false, 'msg' => '未登录或无权限，请刷新页面重新登录']);
            exit;
        }
        header('Location: /login.php');
        exit;
    }

    csrf_check();
    $sites_data = load_sites();
    $result = groups_handle_post($sites_data);

    if ($is_ajax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!$result['ok']) {
        flash_set('error', $result['msg']);
    }
    header('Location: groups.php');
    exit;
}

$page_title = '分组管理';
require_once __DIR__ . '/shared/header.php';

$sites_data = load_sites();
$groups     = $sites_data['groups'] ?? [];
?>

<div class="toolbar">
  <button class="btn btn-primary" onclick="openForm(null)">＋ 添加分组</button>
</div>

<div class="card">
  <div class="table-wrap"><table>
    <thead><tr><th style="width:40px"></th><th>ID</th><th>名称</th><th>站点数</th><th>排序</th><th>验证</th><th>可见</th><th>操作</th></tr></thead>
    <tbody id="group-tbody">
    <?php if (empty($groups)): ?>
    <tr><td colspan="8" style="text-align:center;color:var(--tm);padding:24px">暂无分组，点击上方按钮添加</td></tr>
    <?php else: ?>
    <?php foreach ($groups as $g): ?>
    <tr data-id="<?= htmlspecialchars($g['id']) ?>">
      <td class="drag-handle" style="cursor:move;text-align:center;color:var(--tm)">☰</td>
      <td><code style="font-size:12px"><?= htmlspecialchars($g['id']) ?></code></td>
      <td><?= htmlspecialchars($g['icon']??'') ?> <?= htmlspecialchars($g['name']) ?></td>
      <td><span class="badge badge-blue"><?= count($g['sites']??[]) ?></span></td>
      <td class="order-cell"><?= $g['order']??0 ?></td>
      <td><span class="badge <?= ($g['auth_required']??true)?'badge-purple':'badge-green' ?>"><?= ($g['auth_required']??true)?'需登录':'公开' ?></span></td>
      <td><span class="badge <?= ($g['visible_to']??'all')==='all'?'badge-gray':'badge-yellow' ?>"><?= ($g['visible_to']??'all')==='all'?'所有用户':'仅Admin' ?></span></td>
      <td>
        <button class="btn btn-sm btn-secondary"
          onclick='openForm(<?= htmlspecialchars(json_encode($g), ENT_QUOTES) ?>)'>编辑</button>
        <form method="POST" style="display:inline"
              onsubmit="return confirm('确认删除该分组及其所有站点？')">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="gid" value="<?= htmlspecialchars($g['id']) ?>">
          <button type="submit" class="btn btn-sm btn-danger">删除</button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
    <?php endif; ?>
    </tbody>
  </table></div>
</div>

<!-- 编辑弹层 -->
<div id="modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.65);
z-index:500;align-items:center;justify-content:center;padding:20px">
<div style="background:var(--sf);border:1px solid var(--bd);border-radius:14px;
padding:28px;width:100%;max-width:460px">
  <div style="font-weight:700;font-size:15px;margin-bottom:20px" id="mtitle">添加分组</div>
  <form id="groupForm" method="POST">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="old_id" id="fi_old">
    <div class="form-grid">
      <div class="form-group"><label>ID（小写英文）</label>
        <input type="text" name="gid" id="fi_id" pattern="[a-z0-9_\-]+" required></div>
      <div class="form-group"><label>名称</label>
        <input type="text" name="name" id="fi_name" required></div>
      <div class="form-group"><label>图标（Emoji）</label>
        <div style="display:flex;gap:6px">
          <input type="text" name="icon" id="fi_icon" value="📁" style="flex:1">
          <button type="button" class="btn btn-secondary btn-sm" onclick="openEmojiPicker('fi_icon')" style="flex-shrink:0">😊 选择</button>
        </div></div>
      <div class="form-group"><label>排序权重（小的在前）</label>
        <input type="number" name="order" id="fi_order" value="0"></div>
      <div class="form-group"><label>可见范围</label>
        <select name="visible_to" id="fi_vis">
          <option value="all">所有用户</option>
          <option value="admin">仅管理员</option>
        </select></div>
      <div class="form-group"><label>登录要求</label>
        <select name="auth_required" id="fi_auth">
          <option value="1">需要登录</option>
          <option value="0">公开访问</option>
        </select></div>
    </div>
    <div class="form-actions">
      <button type="submit" class="btn btn-primary">保存</button>
      <button type="button" class="btn btn-secondary" onclick="closeModal()">取消</button>
    </div>
  </form>
</div></div>

<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
<script>
var modal = document.getElementById('modal');
function openForm(g) {
    document.getElementById('mtitle').textContent = g ? '编辑分组' : '添加分组';
    document.getElementById('fi_old').value   = g ? g.id    : '';
    document.getElementById('fi_id').value    = g ? g.id    : '';
    document.getElementById('fi_name').value  = g ? g.name  : '';
    document.getElementById('fi_icon').value  = g ? (g.icon||'📁') : '📁';
    document.getElementById('fi_order').value = g ? (g.order||0) : 0;
    document.getElementById('fi_vis').value   = g ? (g.visible_to||'all') : 'all';
    document.getElementById('fi_auth').value  = g ? (g.auth_required?'1':'0') : '1';
    modal.style.display = 'flex';
}
function closeModal() { modal.style.display = 'none'; if(typeof closeEmojiPicker==='function') closeEmojiPicker(); }

// 仅点击背景关闭弹窗（禁止鼠标滑动误触发）
(function(){
    var mouseDownTarget = null;
    modal.addEventListener('mousedown', function(e){ mouseDownTarget = e.target; });
    modal.addEventListener('click', function(e){
        if (e.target === modal && mouseDownTarget === modal) closeModal();
        mouseDownTarget = null;
    });
})();

// AJAX 提交表单，成功后关闭弹窗并刷新页面
document.getElementById('groupForm').addEventListener('submit', function(e){
    e.preventDefault();
    var form = this;
    var btn  = form.querySelector('button[type=submit]');
    btn.disabled = true;
    btn.textContent = '保存中...';
    fetch('groups.php', {
        credentials: 'same-origin',
        method: 'POST',
        body: new FormData(form),
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    }).then(function(r){ return r.json(); }).then(function(d){
        if (d.ok) {
            closeModal();
            window.location.reload();
        } else {
            btn.disabled = false;
            btn.textContent = '保存';
            alert(d.msg || '保存失败，请重试');
        }
    }).catch(function(){
        btn.disabled = false;
        btn.textContent = '保存';
        alert('网络错误，请重试');
    });
});

// ── Emoji 选择器 ──
var EMOJIS = [
  '📁','📂','🔗','🌐','🏠','⚙️','🛠','🔧','🔨','🖥','💻','📱','🖨','🖱',
  '📊','📈','📉','📋','📌','📍','🗂','🗃','📦','📬','📮','✉️','📧','💬',
  '🔔','🔕','🔒','🔓','🔑','🗝','🛡','⚠️','🚨','🚀','✈️','🚗','🚢','🏎',
  '🎮','🕹','🎯','🎲','♟','🧩','🎵','🎬','📷','📸','🎥','📺','📻','🔊',
  '💡','🔦','🕯','🌙','☀️','⭐','🌟','💫','✨','🔥','💧','🌊','🌈','❄️',
  '🌿','🌱','🌲','🌸','🍎','🍕','☕','🍺','🧃','🏆','🥇','🎖','🎗','🏅',
  '👤','👥','👨‍💻','👩‍💻','🧑‍🔧','👔','🤖','👾','😊','🙂','😎','🤔','💪','👍',
  '❤️','💙','💚','💛','🧡','💜','🖤','🤍','💯','✅','❌','⭕','🔴','🟢',
  '🔵','🟣','⚫','⚪','🟤','🔶','🔷','🔸','🔹','▶️','⏩','📡','🛰','🔭',
  '🔬','🧬','💊','🏥','🏦','🏪','🏫','🏗','🏠','🏡','🗼','🌍','🌏','🌎',
];
var emojiPickerEl = null;
var currentEmojiInput = null;
function openEmojiPicker(inputId) {
    closeEmojiPicker();
    currentEmojiInput = document.getElementById(inputId);
    var rect = currentEmojiInput.getBoundingClientRect();
    var picker = document.createElement('div');
    picker.id = 'emojiPicker';
    picker.style.cssText = 'position:fixed;z-index:2000;background:var(--sf);border:1px solid var(--bd);border-radius:10px;padding:10px;display:grid;grid-template-columns:repeat(14,1fr);gap:2px;width:480px;overflow-x:hidden;overflow-y:auto;box-shadow:0 8px 32px rgba(0,0,0,.5)';
    var top = rect.bottom + 6;
    var left = rect.left;
    // 防止超出右边界
    if (left + 320 > window.innerWidth) left = window.innerWidth - 330;
    if (left < 6) left = 6;
    // 防止超出下边界，改为向上弹出
    if (top + 260 > window.innerHeight) top = rect.top - 266;
    if (top < 6) top = 6;
    picker.style.top  = top + 'px';
    picker.style.left = left + 'px';
    EMOJIS.forEach(function(em) {
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.textContent = em;
        btn.style.cssText = 'background:none;border:none;font-size:18px;cursor:pointer;padding:4px;border-radius:4px;line-height:1';
        btn.addEventListener('mouseenter', function(){ this.style.background='var(--bd)'; });
        btn.addEventListener('mouseleave', function(){ this.style.background='none'; });
        btn.addEventListener('click', function(e){
            e.stopPropagation();
            currentEmojiInput.value = em;
            closeEmojiPicker();
        });
        picker.appendChild(btn);
    });
    document.body.appendChild(picker);
    emojiPickerEl = picker;
    setTimeout(function(){
        document.addEventListener('click', outsideEmojiClick);
    }, 10);
}
function closeEmojiPicker() {
    if (emojiPickerEl) { emojiPickerEl.remove(); emojiPickerEl = null; }
    document.removeEventListener('click', outsideEmojiClick);
}
function outsideEmojiClick(e) {
    if (emojiPickerEl && !emojiPickerEl.contains(e.target)) closeEmojiPicker();
}

// ── 拖拽排序 ──
(function(){
    var tbody = document.getElementById('group-tbody');
    if (!tbody) return;
    var sortable = new Sortable(tbody, {
        handle: '.drag-handle',
        animation: 150,
        onEnd: function() {
            var rows = tbody.querySelectorAll('tr[data-id]');
            var orders = [];
            rows.forEach(function(row, idx){
                orders.push(row.getAttribute('data-id') + ':' + idx);
            });
            var form = new FormData();
            form.append('action', 'reorder_groups');
            orders.forEach(function(o){ form.append('orders[]', o); });
            if (window._csrf) form.append('_csrf', window._csrf);
            fetch('groups.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: form
            }).then(function(r){ return r.json(); }).then(function(d){
                if (!d.ok) alert(d.msg || '排序保存失败');
            }).catch(function(){
                alert('网络错误，排序未保存');
            });
        }
    });
})();
</script>

<?php require_once __DIR__ . '/shared/footer.php'; ?>
