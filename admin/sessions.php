<?php
$page_title = '会话管理';
require_once __DIR__ . '/shared/header.php';

$filterUsername = trim($_GET['username'] ?? '');
?>
<div class="card">
  <div class="card-title">📱 会话管理</div>
  <p style="color:var(--tm);font-size:13px;margin-bottom:14px">
    查看所有活跃登录会话，并可强制下线指定设备。
    <?php if ($filterUsername !== ''): ?>
    当前过滤用户：<strong><?= htmlspecialchars($filterUsername) ?></strong>
    <a href="sessions.php" style="margin-left:8px">清除过滤</a>
    <?php endif; ?>
  </p>

  <div id="sessions-wrap">
    <div style="color:var(--tm);font-size:13px">加载中…</div>
  </div>
</div>

<script>
(function(){
  var wrap = document.getElementById('sessions-wrap');
  var filterUsername = <?= json_encode($filterUsername, JSON_UNESCAPED_UNICODE) ?>;

  function load() {
    var url = 'sessions_api.php?action=list' + (filterUsername ? '&username=' + encodeURIComponent(filterUsername) : '');
    fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function(r){ return r.json(); })
      .then(function(d){
        if (!d || !d.ok) { wrap.innerHTML = '<div style="color:#ff6b6b">加载失败：' + (d.msg || '未知错误') + '</div>'; return; }
        render(d.sessions || []);
      })
      .catch(function(){ wrap.innerHTML = '<div style="color:#ff6b6b">加载失败，请重试</div>'; });
  }

  function render(rows) {
    if (!rows.length) { wrap.innerHTML = '<div style="color:var(--tm)">暂无活跃会话</div>'; return; }
    var html = '<table style="width:100%;border-collapse:collapse;font-size:13px">' +
      '<thead><tr style="text-align:left;border-bottom:1px solid var(--bd)">' +
        '<th style="padding:8px">用户</th>' +
        '<th style="padding:8px">IP</th>' +
        '<th style="padding:8px">创建时间</th>' +
        '<th style="padding:8px">User-Agent</th>' +
        '<th style="padding:8px;width:120px">操作</th>' +
      '</tr></thead><tbody>';
    rows.forEach(function(row){
      html += '<tr style="border-bottom:1px solid rgba(255,255,255,.06)">' +
        '<td style="padding:8px">' + esc(row.username) + '</td>' +
        '<td style="padding:8px;color:var(--tm)">' + esc(row.ip) + '</td>' +
        '<td style="padding:8px;color:var(--tm)">' + esc(row.created_at) + '</td>' +
        '<td style="padding:8px;color:var(--tm);word-break:break-all;max-width:300px">' + esc(row.user_agent) + '</td>' +
        '<td style="padding:8px">' +
          '<form class="revoke-form" style="display:inline">' +
            '<input type="hidden" name="_csrf" value="' + esc(window._csrf || '') + '">' +
            '<input type="hidden" name="jti" value="' + esc(row.jti) + '">' +
            '<button type="submit" class="btn btn-sm" style="background:rgba(255,107,107,.12);border:1px solid rgba(255,107,107,.35);color:#ff6b6b">强制下线</button>' +
          '</form>' +
        '</td>' +
      '</tr>';
    });
    html += '</tbody></table>';
    wrap.innerHTML = html;

    wrap.querySelectorAll('.revoke-form').forEach(function(form){
      form.addEventListener('submit', function(e){
        e.preventDefault();
        NavConfirm.open({
          title: '强制下线',
          message: '确认强制下线该会话？',
          confirmText: '确认',
          cancelText: '取消',
          danger: true,
          onConfirm: function() {
            var fd = new FormData(form);
            fetch('sessions_api.php?action=revoke', {
              method: 'POST',
              body: fd,
              headers: { 'X-Requested-With': 'XMLHttpRequest' }
            }).then(function(r){ return r.json(); }).then(function(d){
              showToast(d.ok ? '会话已强制下线' : (d.msg || '操作失败'), d.ok ? 'success' : 'error');
              if (d.ok) load();
            }).catch(function(){ showToast('请求失败', 'error'); });
          }
        });
      });
    });
  }

  function esc(s) {
    return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }

  load();
})();
</script>

<?php require_once __DIR__ . '/shared/footer.php'; ?>
