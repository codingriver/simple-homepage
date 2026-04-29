<?php
/**
 * 站点管理 admin/sites.php
 * 增删改查站点，支持 internal/proxy/external 三种类型
 * Proxy 类型验证内网IP防止SSRF
 */

function sites_parse_tags(string $raw): array {
    $parts = preg_split('/[,，\n\r]+/', $raw) ?: [];
    $tags = [];
    foreach ($parts as $part) {
        $tag = trim((string)$part);
        if ($tag === '') {
            continue;
        }
        $tags[] = $tag;
    }
    return array_values(array_unique($tags));
}

function sites_parse_bool_post(string $key): bool {
    return !empty($_POST[$key]);
}

// 统一处理保存/删除逻辑（AJAX 与普通表单共用）
function sites_handle_post(array &$sites_data): array {
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $old_gid = $_POST['old_gid'] ?? '';
        $old_sid = $_POST['old_sid'] ?? '';
        $gid     = trim($_POST['gid']  ?? '');
        $sid     = trim($_POST['sid']  ?? '');
        $name    = trim($_POST['name'] ?? '');
        $icon    = trim($_POST['icon'] ?? '🔗');
        $type    = $_POST['type'] ?? 'external';
        $url     = trim($_POST['url']  ?? '');
        $notes = trim((string)($_POST['notes'] ?? ''));

        $err = '';
        if (!preg_match('/^[a-z0-9_-]+$/', $sid)) $err = '站点ID只允许小写字母数字下划线横杠';
        elseif (!$name) $err = '名称不能为空';
        elseif (!$gid)  $err = '请选择所属分组';
        elseif ($type === 'proxy') {
            $target = trim($_POST['proxy_target'] ?? '');
            if (!is_allowed_proxy_target($target)) $err = '代理目标必须是 RFC1918 内网IPv4地址（防SSRF）';
        }

        if ($err) return ['ok' => false, 'msg' => $err];

        // 编辑时保留旧数据中不在表单里的字段
        $oldSite = null;
        if ($old_gid && $old_sid) {
            foreach ($sites_data['groups'] as $g) {
                if ($g['id'] !== $old_gid) continue;
                foreach ($g['sites'] as $s) {
                    if ($s['id'] === $old_sid) { $oldSite = $s; break 2; }
                }
            }
        }
        $site = [
            'id' => $sid,
            'name' => $name,
            'icon' => $icon,
            'type' => $type,
            'notes' => $notes,
        ];
        if ($oldSite) {
            foreach (['desc','order','tags','favorite','pinned','status_badge','owner','env','asset_type','domain_expire_at','ssl_expire_at','renew_url'] as $k) {
                if (isset($oldSite[$k])) $site[$k] = $oldSite[$k];
            }
        }
        if ($type === 'proxy') {
            $site['proxy_mode']   = $_POST['proxy_mode']   ?? 'path';
            $site['proxy_target'] = trim($_POST['proxy_target'] ?? '');
            $site['slug']         = trim($_POST['slug'] ?? $sid);
            $site['proxy_domain'] = trim($_POST['proxy_domain'] ?? '');
        } else {
            $site['url'] = $url;
        }

        if ($old_gid && $old_gid === $gid) {
            foreach ($sites_data['groups'] as &$g) {
                if ($g['id'] !== $gid) continue;
                $replaced = false;
                foreach ($g['sites'] as &$s) {
                    if ($s['id'] === $old_sid) { $s = $site; $replaced = true; break; }
                }
                unset($s);
                if (!$replaced) $g['sites'][] = $site;
                usort($g['sites'], function($a,$b){ return ($a['order']??0)-($b['order']??0); });
                break;
            }
            unset($g);
        } else {
            if ($old_gid) {
                foreach ($sites_data['groups'] as &$g) {
                    if ($g['id'] === $old_gid) {
                        $g['sites'] = array_values(array_filter($g['sites'], function($s) use ($old_sid){ return $s['id'] !== $old_sid; }));
                    }
                }
                unset($g);
            }
            foreach ($sites_data['groups'] as &$g) {
                if ($g['id'] === $gid) {
                    $g['sites'][] = $site;
                    usort($g['sites'], function($a,$b){ return ($a['order']??0)-($b['order']??0); });
                    break;
                }
            }
            unset($g);
        }

        save_sites($sites_data);
        audit_log('site_save', ['gid' => $gid, 'sid' => $sid, 'name' => $name]);
        flash_set('success', '站点已保存');
        return ['ok' => true, 'msg' => '站点已保存'];
    }

    if ($action === 'delete') {
        $gid = $_POST['gid'] ?? '';
        $sid = $_POST['sid'] ?? '';
        $proxy_target = null;
        foreach ($sites_data['groups'] as $g) {
            if ($g['id'] === $gid) {
                foreach ($g['sites'] as $s) {
                    if ($s['id'] === $sid) {
                        $proxy_target = $s['proxy_target'] ?? null;
                        break 2;
                    }
                }
            }
        }
        foreach ($sites_data['groups'] as &$g) {
            if ($g['id'] === $gid) {
                $g['sites'] = array_values(array_filter($g['sites'], function($s) use ($sid){ return $s['id'] !== $sid; }));
                break;
            }
        }
        unset($g);
        save_sites($sites_data);
        @unlink(DATA_DIR . '/nginx/' . $sid . '.conf');
        @unlink(DATA_DIR . '/favicon_cache/' . $sid . '.png');
        if ($proxy_target !== null && file_exists(HEALTH_CACHE_FILE)) {
            $health_cache = json_decode(file_get_contents(HEALTH_CACHE_FILE), true) ?: [];
            if (isset($health_cache[$proxy_target])) {
                unset($health_cache[$proxy_target]);
                file_put_contents(HEALTH_CACHE_FILE, json_encode($health_cache, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
            }
        }
        audit_log('site_delete', ['gid' => $gid, 'sid' => $sid]);
        flash_set('success', '站点已删除');
        return ['ok' => true, 'msg' => '站点已删除'];
    }

    if ($action === 'reorder') {
        $gid = $_POST['gid'] ?? '';
        $orders = array_filter((array)($_POST['orders'] ?? []));
        $orderMap = [];
        foreach ($orders as $item) {
            $parts = explode(':', $item);
            if (count($parts) === 2) {
                $orderMap[$parts[0]] = (int)$parts[1];
            }
        }
        foreach ($sites_data['groups'] as &$g) {
            if ($g['id'] !== $gid) continue;
            foreach ($g['sites'] as &$s) {
                if (isset($orderMap[$s['id']])) {
                    $s['order'] = $orderMap[$s['id']];
                }
            }
            unset($s);
            usort($g['sites'], function($a,$b){ return ($a['order']??0)-($b['order']??0); });
            break;
        }
        unset($g);
        save_sites($sites_data);
        audit_log('sites_reorder', ['gid' => $gid, 'count' => count($orders)]);
        flash_set('success', '排序已保存');
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
    $result = sites_handle_post($sites_data);

    if ($is_ajax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!$result['ok']) {
        flash_set('error', $result['msg']);
    }
    header('Location: sites.php');
    exit;
}

$page_title = '站点管理';
require_once __DIR__ . '/shared/header.php';

$sites_data = load_sites();

// 构建分组索引（gid => &group）
function &find_group(array &$data, string $gid) {
    foreach ($data['groups'] as &$g) {
        if ($g['id'] === $gid) return $g;
    }
    return null;
}


$sites_data = load_sites();
$groups     = $sites_data['groups'] ?? [];
// 健康状态缓存
$health_cache_file = DATA_DIR . '/health_cache.json';
$health_cache = file_exists($health_cache_file)
    ? (json_decode(file_get_contents($health_cache_file), true) ?? [])
    : [];
// 构建分组选项（供JS弹层使用）
$groups_json = json_encode(
    array_map(function($g){ return ['id'=>$g['id'],'name'=>$g['name']]; }, $groups),
    JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP
);
?>

<div class="toolbar">
  <button class="btn btn-primary" onclick="openForm(null,null)">＋ 添加站点</button>
</div>

<?php foreach ($groups as $grp): ?>
<div class="card">
  <div class="card-title"><?= htmlspecialchars($grp['icon']??'') ?> <?= htmlspecialchars($grp['name']) ?>
    <span class="badge badge-blue" style="margin-left:6px"><?= count($grp['sites']??[]) ?> 个站点</span>
  </div>
  <?php if (empty($grp['sites'])): ?>
    <p style="color:var(--tm);font-size:13px">该分组暂无站点</p>
  <?php else: ?>
  <div class="table-wrap"><table class="sites-table" data-gid="<?= htmlspecialchars($grp['id']) ?>">
    <thead><tr><th style="width:40px"></th><th>ID</th><th>名称</th><th>类型</th><th>地址/目标</th><th>状态</th><th>操作</th></tr></thead>
    <tbody>
    <?php foreach ($grp['sites'] as $s):
      $h_url = ($s['type']??'') === 'proxy' ? ($s['proxy_target']??'') : ($s['url']??'');
      $h_entry = $health_cache[$h_url] ?? null;
      if ($h_entry && (time() - ($h_entry['checked_at']??0)) < 600) {
          $h_status = $h_entry['status'] ?? 'unknown';
          $h_ms     = $h_entry['ms'] ?? '-';
      } else {
          $h_status = 'unknown'; $h_ms = '-';
      }
      $h_dot = $h_status === 'up'
          ? '<span style="color:#4ade80" title="在线（'.$h_ms.'ms）">● 在线</span>'
          : ($h_status === 'down'
              ? '<span style="color:#f87171" title="离线">● 离线</span>'
              : '<span style="color:var(--tm)">— </span>');
    ?>
    <tr data-sid="<?= htmlspecialchars($s['id']) ?>">
      <td style="cursor:move;text-align:center;color:var(--tm)">☰</td>
      <td><code style="font-size:12px"><?= htmlspecialchars($s['id']) ?></code></td>
      <td><?= htmlspecialchars($s['icon']??'') ?> <?= htmlspecialchars($s['name']) ?></td>
      <td><span class="badge <?= ['internal'=>'badge-purple','proxy'=>'badge-yellow','external'=>'badge-gray'][$s['type']??'external'] ?>"><?= htmlspecialchars($s['type']??'external') ?></span></td>
      <td style="font-size:12px;font-family:monospace;max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
        <?= htmlspecialchars($s['url'] ?? $s['proxy_target'] ?? '') ?></td>
      <td style="font-size:12px;white-space:nowrap"><?= $h_dot ?><?= $h_status==='up' && $h_ms!=='-' ? ' <span style="color:var(--tm);font-size:10px">('.$h_ms.'ms)</span>' : '' ?></td>
      <td>
        <button class="btn btn-sm btn-secondary"
          onclick='openForm(<?= htmlspecialchars(json_encode($s),ENT_QUOTES) ?>, "<?= htmlspecialchars($grp['id'],ENT_QUOTES) ?>")'>编辑</button>
        <form method="POST" style="display:inline" onsubmit="return confirm('确认删除该站点？')">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="gid" value="<?= htmlspecialchars($grp['id']) ?>">
          <input type="hidden" name="sid" value="<?= htmlspecialchars($s['id']) ?>">
          <button type="submit" class="btn btn-sm btn-danger">删除</button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>
<?php endforeach; ?>

<!-- 编辑弹层 -->
<div id="modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.65);
z-index:500;align-items:flex-start;justify-content:center;padding:20px;overflow-y:auto">
<div id="modalInner" style="background:var(--sf);border:1px solid var(--bd);border-radius:14px;
padding:28px;width:100%;max-width:520px;margin:auto">
  <div style="font-weight:700;font-size:15px;margin-bottom:20px" id="mtitle">添加站点</div>
  <form method="POST" id="siteForm">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="old_gid" id="fi_ogid">
    <input type="hidden" name="old_sid" id="fi_osid">
    <div class="form-grid">
      <div class="form-group"><label>站点ID（小写英文）</label>
        <input type="text" name="sid" id="fi_sid" pattern="[a-z0-9_\-]+" required></div>
      <div class="form-group"><label>名称</label>
        <input type="text" name="name" id="fi_name" required></div>
      <div class="form-group"><label>图标（Emoji）</label>
        <div style="display:flex;gap:6px">
          <input type="text" name="icon" id="fi_icon" value="🔗" style="flex:1">
          <button type="button" class="btn btn-secondary btn-sm" onclick="openEmojiPicker('fi_icon')" style="flex-shrink:0">😊 选择</button>
        </div></div>
      <div class="form-group"><label>所属分组</label>
        <select name="gid" id="fi_gid"></select></div>
      <div class="form-group"><label>类型</label>
        <select name="type" id="fi_type" onchange="toggleType(this.value)">
          <option value="external">外链 External</option>
          <option value="internal">内站 Internal</option>
          <option value="proxy">代理 Proxy</option>
        </select></div>
      <div class="form-group full" id="row_url"><label>目标URL</label>
        <input type="url" name="url" id="fi_url" placeholder="https://"></div>
      <div id="proxy_fields" style="display:none;grid-column:1/-1">
        <div class="form-grid">
          <div class="form-group"><label>代理模式</label>
            <select name="proxy_mode" id="fi_pmode">
              <option value="path">路径前缀 /p/{slug}/</option>
              <option value="domain">子域名 proxy_domain</option>
            </select></div>
          <div class="form-group"><label>内网目标（防SSRF）</label>
            <input type="text" name="proxy_target" id="fi_ptarget" placeholder="http://192.168.1.x:port"></div>
          <div class="form-group"><label>Slug（路径模式用）</label>
            <input type="text" name="slug" id="fi_slug" placeholder="my-app"></div>
          <div class="form-group"><label>代理域名（子域名模式）</label>
            <input type="text" name="proxy_domain" id="fi_pdomain" placeholder="app.yourdomain.com"></div>
        </div>
      </div>
      <div class="form-group full"><label>备注</label>
        <textarea name="notes" id="fi_notes" rows="3" style="width:100%;background:var(--bg);color:var(--tx);border:1px solid var(--bd);border-radius:10px;padding:10px 12px;resize:vertical"></textarea></div>
    </div>
    <div class="form-actions">
      <button type="submit" class="btn btn-primary">保存</button>
      <button type="button" class="btn btn-secondary" onclick="closeModal()">取消</button>
    </div>
  </form>
</div></div>

<script>
var modal  = document.getElementById('modal');
var groups = <?= $groups_json ?>;
function populateGroups(selGid) {
    var sel = document.getElementById('fi_gid');
    if(!groups||!groups.length){sel.innerHTML='<option value="">(请先添加分组)</option>';return;}
    sel.innerHTML = groups.map(function(g){
        return '<option value="'+g.id+'"'+(g.id===selGid?' selected':'')+'>'+g.name+'</option>';
    }).join('');
}
function toggleType(t) {
    document.getElementById('row_url').style.display    = t==='proxy' ? 'none' : '';
    document.getElementById('proxy_fields').style.display = t==='proxy' ? 'grid' : 'none';
}
function openForm(s, gid) {
    document.getElementById('mtitle').textContent = s ? '编辑站点' : '添加站点';
    document.getElementById('fi_ogid').value   = gid  || '';
    document.getElementById('fi_osid').value   = s ? s.id   : '';
    document.getElementById('fi_sid').value    = s ? s.id   : '';
    document.getElementById('fi_name').value   = s ? s.name : '';
    document.getElementById('fi_icon').value   = s ? (s.icon||'🔗') : '🔗';
    document.getElementById('fi_url').value    = s ? (s.url||'') : '';
    document.getElementById('fi_type').value   = s ? (s.type||'external') : 'external';
    document.getElementById('fi_pmode').value  = s ? (s.proxy_mode||'path') : 'path';
    document.getElementById('fi_ptarget').value= s ? (s.proxy_target||'') : '';
    document.getElementById('fi_slug').value   = s ? (s.slug||'') : '';
    document.getElementById('fi_pdomain').value= s ? (s.proxy_domain||'') : '';
    document.getElementById('fi_notes').value = s ? (s.notes||'') : '';
    populateGroups(gid||(groups.length?groups[0].id:''));
    toggleType(s ? (s.type||'external') : 'external');
    var sb=document.querySelector('#siteForm button[type=submit]');if(sb){sb.disabled=false;sb.textContent='保存';}
    modal.style.display='flex';
}
function closeModal() { modal.style.display = 'none'; closeEmojiPicker(); }



// 防止鼠标滑动误关闭弹窗：只有点击背景层（非内容区）才关闭
(function(){
    var _mdBg=false;
    modal.addEventListener('mousedown',function(e){_mdBg=(e.target===modal);});
    modal.addEventListener('click',function(e){if(e.target===modal&&_mdBg)closeModal();_mdBg=false;});
})();

// AJAX 提交，成功后关闭弹窗并刷新页面
document.getElementById('siteForm').addEventListener('submit', function(e){
    e.preventDefault();
    var form = this;
    var btn  = form.querySelector('button[type=submit]');
    btn.disabled = true;
    btn.textContent = '保存中...';
    fetch('sites.php', {
        method: 'POST',
        credentials: 'same-origin',
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
  '🔗','🌐','🏠','📁','📂','⚙️','🛠','🔧','🔨','🖥','💻','📱','🖨','🖱',
  '📊','📈','📉','📋','📌','📍','🗂','🗃','📦','📬','📮','✉️','📧','💬',
  '🔔','🔕','🔒','🔓','🔑','🗝','🛡','⚠️','🚨','🚀','✈️','🚗','🚢','🏎',
  '🎮','🕹','🎯','🎲','♟','🧩','🎵','🎬','📷','📸','🎥','📺','📻','🔊',
  '💡','🔦','🕯','🌙','☀️','⭐','🌟','💫','✨','🔥','💧','🌊','🌈','❄️',
  '🌿','🌱','🌲','🌸','🍎','🍕','☕','🍺','🧃','🏆','🥇','🎖','🎗','🏅',
  '👤','👥','👨‍💻','👩‍💻','🧑‍🔧','👔','🤖','👾','😊','🙂','😎','🤔','💪','👍',
  '❤️','💙','💚','💛','🧡','💜','🖤','🤍','♥️','💯','✅','❌','⭕','🔴',
  '🟠','🟡','🟢','🔵','🟣','⚫','⚪','🟤','🔶','🔷','🔸','🔹','▶️','⏩',
  '📡','🛰','🔭','🔬','🧬','💊','🏥','🏦','🏪','🏫','🏗','🏠','🏡','🗼',
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
    if (left + 320 > window.innerWidth) left = window.innerWidth - 330;
    if (left < 6) left = 6;
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
    // 点击其他地方关闭
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
</script>
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
<script>
(function(){
    document.querySelectorAll('.sites-table tbody').forEach(function(tbody){
        var table = tbody.closest('.sites-table');
        var gid = table ? table.getAttribute('data-gid') : '';
        if (!gid) return;
        new Sortable(tbody, {
            handle: 'td:first-child',
            animation: 150,
            onEnd: function() {
                var rows = tbody.querySelectorAll('tr[data-sid]');
                var orders = [];
                rows.forEach(function(row, idx){
                    orders.push(row.getAttribute('data-sid') + ':' + idx);
                });
                var form = new FormData();
                form.append('action', 'reorder');
                form.append('gid', gid);
                orders.forEach(function(o){ form.append('orders[]', o); });
                if (window._csrf) form.append('_csrf', window._csrf);
                fetch('sites.php', {
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
    });
})();
</script>
<?php require_once __DIR__ . '/shared/footer.php'; ?>
