<?php
$page_title = 'Nginx 管理';
require_once __DIR__ . '/shared/header.php';

$targets = nginx_editable_targets();
$target = trim((string)($_GET['target'] ?? 'main'));
if (!isset($targets[$target])) $target = 'main';

$tab = trim((string)($_GET['tab'] ?? (($target === 'proxy_path' || $target === 'proxy_domain' || $target === 'proxy_params_simple' || $target === 'proxy_params_full') ? 'proxy' : $target)));
if (!in_array($tab, ['main', 'http', 'proxy'], true)) $tab = 'main';

$encOptions = ['utf-8' => 'UTF-8', 'gb18030' => 'GB18030', 'iso-8859-1' => 'ISO-8859-1'];
$langOptions = ['nginx' => 'Nginx', 'php' => 'PHP', 'json' => 'JSON', 'yaml' => 'YAML', 'sh' => 'Shell', 'ini' => 'INI', 'text' => 'Plain Text'];
$encoding = strtolower(trim((string)($_GET['encoding'] ?? 'utf-8')));
if (!isset($encOptions[$encoding])) $encoding = 'utf-8';
$lang = strtolower(trim((string)($_GET['lang'] ?? 'nginx')));
if (!isset($langOptions[$lang])) $lang = 'nginx';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  require_once __DIR__ . '/shared/functions.php';
  csrf_check();
  $action = trim((string)($_POST['action'] ?? ''));
  $ptarget = trim((string)($_POST['target'] ?? 'main'));
  if (!isset($targets[$ptarget])) {
    flash_set('error', '未知配置目标');
    header('Location: nginx.php');
    exit;
  }
  $ptab = trim((string)($_POST['tab'] ?? 'main'));
  if (!in_array($ptab, ['main', 'http', 'proxy'], true)) $ptab = 'main';
  $penc = strtolower(trim((string)($_POST['encoding'] ?? $encoding)));
  if (!isset($encOptions[$penc])) $penc = 'utf-8';
  $plang = strtolower(trim((string)($_POST['language_mode'] ?? $lang)));
  if (!isset($langOptions[$plang])) $plang = 'nginx';

  if ($action === 'save' || $action === 'save_and_reload') {
    $utf8 = (string)($_POST['content'] ?? '');
    $write = $utf8;
    if ($penc !== 'utf-8') {
      $converted = @iconv('UTF-8', strtoupper($penc) . '//IGNORE', $utf8);
      if ($converted === false) {
        flash_set('error', '编码转换失败，请检查字符与编码是否兼容');
        header('Location: nginx.php?' . http_build_query(['target' => $ptarget, 'tab' => $ptab, 'encoding' => $penc, 'lang' => $plang]));
        exit;
      }
      $write = $converted;
    }

    $saved = nginx_write_target($ptarget, $write);
    if (!$saved['ok']) {
      flash_set('error', $saved['msg']);
      header('Location: nginx.php?' . http_build_query(['target' => $ptarget, 'tab' => $ptab, 'encoding' => $penc, 'lang' => $plang]));
      exit;
    }

    if ($action === 'save_and_reload') {
      $test = nginx_test_config();
      if (!$test['ok']) {
        flash_set('error', '语法检测失败，已保存但未 Reload：' . $test['msg']);
        header('Location: nginx.php?' . http_build_query(['target' => $ptarget, 'tab' => $ptab, 'encoding' => $penc, 'lang' => $plang]));
        exit;
      }
      $reload = nginx_reload();
      if (!$reload['ok']) {
        flash_set('error', '已保存但 Reload 失败：' . $reload['msg']);
        header('Location: nginx.php?' . http_build_query(['target' => $ptarget, 'tab' => $ptab, 'encoding' => $penc, 'lang' => $plang]));
        exit;
      }
      nginx_mark_applied();
      audit_log('nginx_save_reload', ['target' => $ptarget]);
      flash_set('success', '保存并 Reload 成功');
      header('Location: nginx.php?' . http_build_query(['target' => $ptarget, 'tab' => $ptab, 'encoding' => $penc, 'lang' => $plang]));
      exit;
    }

    audit_log('nginx_save', ['target' => $ptarget]);
    flash_set('success', '配置已保存：' . ($targets[$ptarget]['label'] ?? $ptarget));
    header('Location: nginx.php?' . http_build_query(['target' => $ptarget, 'tab' => $ptab, 'encoding' => $penc, 'lang' => $plang]));
    exit;
  }

  if ($action === 'syntax_test') {
    $test = nginx_test_config();
    $msg = $test['msg'];
    if (trim((string)$test['test_output']) !== '') $msg .= '｜' . $test['test_output'];
    flash_set($test['ok'] ? 'success' : 'error', $msg);
    header('Location: nginx.php?' . http_build_query(['target' => $ptarget, 'tab' => $ptab, 'encoding' => $penc, 'lang' => $plang]));
    exit;
  }
}

$cap = nginx_reload_capability();
$editorDataMap = [];
foreach ($targets as $k => $meta) {
  $rr = nginx_read_target($k);
  if (!$rr['ok']) {
    $editorDataMap[$k] = [
      'ok' => false,
      'label' => (string)($meta['label'] ?? $k),
      'path' => (string)($meta['path'] ?? ''),
      'content' => '',
      'error' => (string)$rr['msg'],
    ];
    continue;
  }
  $rawContent = (string)$rr['content'];
  if ($encoding === 'utf-8') {
    $decodedContent = $rawContent;
  } else {
    $decoded = @iconv(strtoupper($encoding), 'UTF-8//IGNORE', $rawContent);
    $decodedContent = ($decoded === false) ? $rawContent : $decoded;
  }
  $editorDataMap[$k] = [
    'ok' => true,
    'label' => (string)($meta['label'] ?? $k),
    'path' => (string)$rr['path'],
    'content' => $decodedContent,
    'error' => '',
  ];
}

$currentEditor = $editorDataMap[$target] ?? [
  'label' => (string)($targets[$target]['label'] ?? $target),
  'path' => (string)($targets[$target]['path'] ?? ''),
  'content' => '',
  'error' => '读取配置失败',
];
$editorContent = (string)($currentEditor['content'] ?? '');
$editorPath = (string)($currentEditor['path'] ?? '');
$editorError = (string)($currentEditor['error'] ?? '');
?>
<style>
.ngx-editor-launch{display:flex;justify-content:flex-end;margin-bottom:8px}
</style>

<div class="card">
  <div class="card-title">🧩 Nginx 编辑器状态</div>
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:12px">
    <div style="background:var(--bg);border:1px solid var(--bd);border-radius:8px;padding:12px 14px"><div style="font-size:11px;color:var(--tm)">执行方式</div><div style="margin-top:4px"><span class="badge <?= $cap['ok'] ? 'badge-green' : 'badge-gray' ?>"><?= htmlspecialchars($cap['method']) ?></span></div></div>
    <div style="background:var(--bg);border:1px solid var(--bd);border-radius:8px;padding:12px 14px"><div style="font-size:11px;color:var(--tm)">Nginx 可执行路径</div><div style="margin-top:4px;font-family:var(--mono)"><?= htmlspecialchars($cap['nginx_bin']) ?></div></div>
    <div style="background:var(--bg);border:1px solid var(--bd);border-radius:8px;padding:12px 14px"><div style="font-size:11px;color:var(--tm)">语法检查能力</div><div style="margin-top:4px;color:<?= $cap['ok'] ? 'var(--green)' : 'var(--yellow)' ?>"><?= htmlspecialchars($cap['msg']) ?></div></div>
  </div>
</div>

<div class="card" id="nginx-nav-card">
  <div class="card-title">🗂 Nginx 配置导航</div>
  <div style="display:flex;gap:10px;flex-wrap:wrap">
    <a class="btn <?= $tab === 'main' ? 'btn-primary' : 'btn-secondary' ?>" data-nav-main="1" data-tab="main" data-target="main" href="nginx.php?<?= htmlspecialchars(http_build_query(['tab' => 'main', 'target' => 'main', 'encoding' => $encoding, 'lang' => $lang])) ?>">主配置</a>
    <a class="btn <?= $tab === 'http' ? 'btn-primary' : 'btn-secondary' ?>" data-nav-main="1" data-tab="http" data-target="http" href="nginx.php?<?= htmlspecialchars(http_build_query(['tab' => 'http', 'target' => 'http', 'encoding' => $encoding, 'lang' => $lang])) ?>">HTTP 模块</a>
    <a class="btn <?= $tab === 'proxy' ? 'btn-primary' : 'btn-secondary' ?>" data-nav-main="1" data-tab="proxy" data-target="proxy_path" href="nginx.php?<?= htmlspecialchars(http_build_query(['tab' => 'proxy', 'target' => 'proxy_path', 'encoding' => $encoding, 'lang' => $lang])) ?>">反代配置</a>
  </div>
  <div id="nginx-proxy-subnav" style="margin-top:12px;display:<?= $tab === 'proxy' ? 'flex' : 'none' ?>;gap:8px;flex-wrap:wrap">
    <a class="btn btn-sm <?= $target === 'proxy_path' ? 'btn-primary' : 'btn-secondary' ?>" data-nav-proxy="1" data-tab="proxy" data-target="proxy_path" href="nginx.php?<?= htmlspecialchars(http_build_query(['tab' => 'proxy', 'target' => 'proxy_path', 'encoding' => $encoding, 'lang' => $lang])) ?>">路径模式</a>
    <a class="btn btn-sm <?= $target === 'proxy_domain' ? 'btn-primary' : 'btn-secondary' ?>" data-nav-proxy="1" data-tab="proxy" data-target="proxy_domain" href="nginx.php?<?= htmlspecialchars(http_build_query(['tab' => 'proxy', 'target' => 'proxy_domain', 'encoding' => $encoding, 'lang' => $lang])) ?>">子域名模式</a>
    <a class="btn btn-sm <?= $target === 'proxy_params_simple' ? 'btn-primary' : 'btn-secondary' ?>" data-nav-proxy="1" data-tab="proxy" data-target="proxy_params_simple" href="nginx.php?<?= htmlspecialchars(http_build_query(['tab' => 'proxy', 'target' => 'proxy_params_simple', 'encoding' => $encoding, 'lang' => $lang])) ?>">参数模板（精简）</a>
    <a class="btn btn-sm <?= $target === 'proxy_params_full' ? 'btn-primary' : 'btn-secondary' ?>" data-nav-proxy="1" data-tab="proxy" data-target="proxy_params_full" href="nginx.php?<?= htmlspecialchars(http_build_query(['tab' => 'proxy', 'target' => 'proxy_params_full', 'encoding' => $encoding, 'lang' => $lang])) ?>">参数模板（完整）</a>
  </div>
</div>

<div class="card">
  <div class="card-title">📝 文本编辑器（Ace）</div>
  <div class="ngx-editor-launch"><button type="button" class="btn btn-primary" id="open-editor-modal-btn">打开文本编辑器</button></div>
  <div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;align-items:flex-start;margin-bottom:8px">
    <div>
      <div style="font-size:12px;color:var(--tm)">当前编辑</div>
      <div style="font-size:13px" id="editor-target-label"><?= htmlspecialchars($targets[$target]['label'] ?? $target) ?></div>
    </div>
    <div style="font-family:var(--mono);font-size:12px;color:var(--tm)" id="editor-target-path"><?= htmlspecialchars($editorPath) ?></div>
  </div>
  <?php if ($editorError !== ''): ?><div class="alert alert-error" id="editor-error"><?= htmlspecialchars($editorError) ?></div><?php else: ?><div class="alert alert-error" id="editor-error" style="display:none"></div><?php endif; ?>

  <form method="POST" id="nginx-editor-form">
    <?= csrf_field() ?>
    <input type="hidden" name="target" id="nginx-editor-target" value="<?= htmlspecialchars($target) ?>">
    <input type="hidden" name="tab" id="nginx-editor-tab" value="<?= htmlspecialchars($tab) ?>">
    <input type="hidden" name="encoding" id="nginx-editor-encoding" value="<?= htmlspecialchars($encoding) ?>">
    <input type="hidden" name="language_mode" id="nginx-editor-lang" value="<?= htmlspecialchars($lang) ?>">
    <textarea name="content" id="nginx-editor-content" style="display:none"><?= htmlspecialchars($editorContent) ?></textarea>
  </form>
</div>

<script src="assets/ace/ace.js"></script>
<script src="assets/ace/ext-searchbox.js"></script>
<?php require_once __DIR__ . '/shared/ace_editor_modal.php'; ?>
<script>
(function(){
  var form=document.getElementById('nginx-editor-form');
  var hidden=document.getElementById('nginx-editor-content');
  var encInput=document.getElementById('nginx-editor-encoding');
  var langInput=document.getElementById('nginx-editor-lang');
  var targetInput=document.getElementById('nginx-editor-target');
  var tabInput=document.getElementById('nginx-editor-tab');
  var targetLabelEl=document.getElementById('editor-target-label');
  var targetPathEl=document.getElementById('editor-target-path');
  var errorEl=document.getElementById('editor-error');
  var navCard=document.getElementById('nginx-nav-card');
  var subnav=document.getElementById('nginx-proxy-subnav');
  var openModalBtn=document.getElementById('open-editor-modal-btn');
  if(!form||!hidden) return;

  var editorDataMap = <?= json_encode($editorDataMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  var currentTarget = <?= json_encode($target, JSON_UNESCAPED_UNICODE) ?>;
  var currentTab = <?= json_encode($tab, JSON_UNESCAPED_UNICODE) ?>;

  function setEditorError(msg){
    if(!errorEl) return;
    if(msg){ errorEl.textContent=msg; errorEl.style.display='block'; }
    else { errorEl.textContent=''; errorEl.style.display='none'; }
  }

  function activateButtons(selector, activeTarget){
    document.querySelectorAll(selector).forEach(function(btn){
      var isActive=(btn.getAttribute('data-target')===activeTarget);
      btn.classList.toggle('btn-primary', isActive);
      btn.classList.toggle('btn-secondary', !isActive);
    });
  }

  function activateMainTab(tab){
    document.querySelectorAll('[data-nav-main="1"]').forEach(function(btn){
      var isActive=(btn.getAttribute('data-tab')===tab);
      btn.classList.toggle('btn-primary', isActive);
      btn.classList.toggle('btn-secondary', !isActive);
    });
  }

  function renderTarget(target, tab){
    var item=editorDataMap[target]||null;
    if(!item) return;

    currentTarget=target;
    currentTab=tab;
    if(targetInput) targetInput.value=target;
    if(tabInput) tabInput.value=tab;
    if(targetLabelEl) targetLabelEl.textContent=item.label||target;
    if(targetPathEl) targetPathEl.textContent=item.path||'';
    setEditorError(item.error||'');

    // 若弹窗已打开，同步更新编辑器内容
    if (typeof NavAceEditor !== 'undefined' && NavAceEditor.getValue) {
      var langNow = item.lang || 'nginx';
      NavAceEditor.setValue(item.content || '', langNow);
      NavAceEditor.setTitle('文本编辑器 · ' + (item.label || target));
      NavAceEditor.markClean();
      if (langInput) langInput.value = langNow;
    }

    if(tab==='proxy'){
      if(subnav) subnav.style.display='flex';
      activateButtons('[data-nav-proxy="1"]', target);
    }else{
      if(subnav) subnav.style.display='none';
    }
    activateMainTab(tab);
  }

  function openNginxEditor(){
    var item=editorDataMap[currentTarget]||{};
    var currentLang=item.lang||'nginx';
    NavAceEditor.open({
      title: '文本编辑器 · ' + (item.label || currentTarget),
      mode: currentLang,
      value: item.content||'',
      wrapMode: true,
      buttons: {
        left: [
          { type: 'dirty' },
          { text: '检查语法', class: 'btn-secondary', action: 'syntax' }
        ],
        right: [
          { text: '关闭', class: 'btn-secondary', action: 'close' },
          { text: '保存', class: 'btn-secondary', action: 'save' },
          { text: '保存并 Reload', class: 'btn-primary', action: 'save_reload' }
        ]
      },
      onAction: function(action, value){
        if(action==='close'){
          NavAceEditor.close();
          return;
        }
        if(action==='save'||action==='save_reload'||action==='syntax'){
          hidden.value=value;
          var actionInput=form.querySelector('input[name="action"]');
          if(!actionInput){
            actionInput=document.createElement('input');
            actionInput.type='hidden';
            actionInput.name='action';
            form.appendChild(actionInput);
          }
          actionInput.value=action;
          if(action==='save_reload'){
            if(!confirm('确认保存并 Reload Nginx？')) return;
          }
          form.submit();
        }
      },
      onClose: function(){
        setEditorError('');
      }
    });
  }

  if(navCard){
    navCard.addEventListener('click', function(e){
      var btn=e.target.closest('a[data-target]');
      if(!btn) return;
      e.preventDefault();
      var target=btn.getAttribute('data-target')||'main';
      var tab=btn.getAttribute('data-tab')||'main';
      renderTarget(target, tab);
      if(typeof NavAceEditor!=='undefined'&&NavAceEditor.focus){
        setTimeout(function(){ NavAceEditor.focus(); }, 10);
      }
    });
  }

  if(openModalBtn) openModalBtn.addEventListener('click', openNginxEditor);

  window.addEventListener('beforeunload',function(e){
    if(typeof NavAceEditor!=='undefined'&&NavAceEditor.isDirty&&NavAceEditor.isDirty()){
      e.preventDefault(); e.returnValue='';
    }
  });

  renderTarget(currentTarget, currentTab);
})();
</script>

<?php require_once __DIR__ . '/shared/footer.php'; ?>
