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
      flash_set('success', '保存并 Reload 成功');
      header('Location: nginx.php?' . http_build_query(['target' => $ptarget, 'tab' => $ptab, 'encoding' => $penc, 'lang' => $plang]));
      exit;
    }

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
.ngx-modal{display:none;position:fixed;inset:0;background:rgba(8,10,14,.78);backdrop-filter:blur(4px);z-index:980;align-items:center;justify-content:center;padding:8px}
.ngx-modal.open{display:flex}
.ngx-modal-card{width:min(1680px,99vw);height:min(96vh,1080px);background:var(--sf);border:1px solid var(--bd2);border-radius:10px;display:flex;flex-direction:column;overflow:hidden;box-shadow:0 30px 80px rgba(0,0,0,.45)}
.ngx-modal-head{display:flex;align-items:center;justify-content:space-between;gap:8px;padding:4px 10px;border-bottom:1px solid var(--bd);min-height:30px}
.ngx-modal-title{font-size:12px;color:var(--ac);font-family:var(--mono);line-height:1.1}
.ngx-close-btn{padding:3px 10px!important;min-height:24px;line-height:1.1;font-size:12px}
.ngx-modal-body{padding:6px 8px 8px;display:flex;flex-direction:column;min-height:0;height:100%}
.ngx-editor-toolbar{display:flex;gap:6px;flex-wrap:wrap;align-items:center;margin-bottom:6px;line-height:1.1}
.ngx-editor-toolbar .btn{padding:4px 10px;min-height:26px;line-height:1.1;font-size:12px}
.ngx-editor-toolbar label{display:flex;align-items:center;gap:4px;font-size:11px;line-height:1.1;color:var(--tx2)}
.ngx-editor-toolbar select{height:24px;padding:0 6px}
.ngx-editor-main{width:100%;flex:1 1 auto;min-height:0;border:1px solid var(--bd2);border-radius:8px;overflow:hidden}
.ngx-editor-actions{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-top:6px;padding-top:6px;border-top:1px solid var(--bd)}
.ngx-editor-actions-left,.ngx-editor-actions-right{display:flex;gap:6px;flex-wrap:wrap;align-items:center}
.ngx-editor-actions .btn{padding:5px 12px;min-height:28px;line-height:1.1;font-size:12px}
.ngx-editor-actions .btn-primary{padding:5px 14px}
.ngx-modal-card.focus-mode .ngx-modal-head{display:none}
.ngx-modal-card.focus-mode .ngx-editor-toolbar{display:none}
.ngx-modal-card.focus-mode .ngx-modal-body{padding:4px 6px 6px}
.ngx-modal-card.focus-mode .ngx-editor-main{border-radius:6px}
.ngx-focus-exit-btn{display:none}
.ngx-modal-card.focus-mode .ngx-focus-exit-btn{display:inline-flex}
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
    <textarea name="content" id="nginx-editor-content" style="display:none"><?= htmlspecialchars($editorContent) ?></textarea>

    <div id="nginx-editor-modal" class="ngx-modal" onclick="if(event.target===this)closeEditorModal()">
      <div class="ngx-modal-card">
        <div class="ngx-modal-head">
          <div class="ngx-modal-title" id="editor-modal-title">文本编辑器 · <?= htmlspecialchars($targets[$target]['label'] ?? $target) ?></div>
          <button type="button" class="btn btn-secondary ngx-close-btn" id="close-editor-modal-btn" aria-label="关闭">×</button>
        </div>
        <div class="ngx-modal-body">
          <div class="ngx-editor-toolbar">
            <button type="button" class="btn btn-secondary" id="editor-find-btn">查找 (Ctrl+F)</button>
            <button type="button" class="btn btn-secondary" id="editor-goto-btn">跳转行号 (Ctrl+G)</button>
            <label>语言
              <select name="language_mode" id="editor-language"><?php foreach ($langOptions as $v => $lb): ?><option value="<?= htmlspecialchars($v) ?>" <?= $lang === $v ? 'selected' : '' ?>><?= htmlspecialchars($lb) ?></option><?php endforeach; ?></select>
            </label>
            <label>主题
              <select id="editor-theme">
                <option value="tomorrow_night">Tomorrow Night</option>
                <option value="monokai">Monokai</option>
                <option value="github_dark">GitHub Dark</option>
                <option value="dracula">Dracula</option>
              </select>
            </label>
            <label>字号
              <select id="editor-font-size">
                <option value="12">12px</option>
                <option value="13">13px</option>
                <option value="14">14px</option>
                <option value="15">15px</option>
                <option value="16">16px</option>
                <option value="18">18px</option>
                <option value="20">20px</option>
              </select>
            </label>
            <label>编码
              <select name="encoding"><?php foreach ($encOptions as $v => $lb): ?><option value="<?= htmlspecialchars($v) ?>" <?= $encoding === $v ? 'selected' : '' ?>><?= htmlspecialchars($lb) ?></option><?php endforeach; ?></select>
            </label>
            <label><input type="checkbox" id="editor-wrap-toggle" checked> 自动换行</label>
            <label><input type="checkbox" id="editor-focus-toggle"> 沉浸模式</label>
          </div>

          <div id="nginx-ace-editor" class="ngx-editor-main"></div>

          <div class="ngx-editor-actions">
            <div class="ngx-editor-actions-left">
              <span id="editor-dirty-hint" style="font-size:12px;color:var(--tm)">未修改</span>
              <button class="btn btn-secondary" type="submit" name="action" value="syntax_test">检查语法</button>
            </div>
            <div class="ngx-editor-actions-right">
              <button class="btn btn-secondary ngx-focus-exit-btn" type="button" id="editor-focus-exit-btn">退出沉浸模式</button>
              <button class="btn btn-secondary" type="button" id="close-editor-modal-btn-footer">关闭</button>
              <button class="btn btn-secondary" type="submit" name="action" value="save">保存</button>
              <button class="btn btn-primary" type="submit" name="action" value="save_and_reload" onclick="return confirm('确认保存并 Reload Nginx？')">保存并 Reload</button>
            </div>
          </div>
        </div>
      </div>
    </div>
  </form>
</div>

<script src="assets/ace/ace.js"></script>
<script src="assets/ace/ext-searchbox.js"></script>
<script>
(function(){
  var form=document.getElementById('nginx-editor-form');
  var hidden=document.getElementById('nginx-editor-content');
  var hint=document.getElementById('editor-dirty-hint');
  var wrap=document.getElementById('editor-wrap-toggle');
  var focusToggle=document.getElementById('editor-focus-toggle');
  var findBtn=document.getElementById('editor-find-btn');
  var gotoBtn=document.getElementById('editor-goto-btn');
  var langSel=document.getElementById('editor-language');
  var themeSel=document.getElementById('editor-theme');
  var fontSizeSel=document.getElementById('editor-font-size');
  var openModalBtn=document.getElementById('open-editor-modal-btn');
  var closeModalBtn=document.getElementById('close-editor-modal-btn');
  var closeModalBtnFooter=document.getElementById('close-editor-modal-btn-footer');
  var focusExitBtn=document.getElementById('editor-focus-exit-btn');
  var editorModal=document.getElementById('nginx-editor-modal');
  var modalCard=document.querySelector('#nginx-editor-modal .ngx-modal-card');
  var navCard=document.getElementById('nginx-nav-card');
  var subnav=document.getElementById('nginx-proxy-subnav');
  var targetInput=document.getElementById('nginx-editor-target');
  var tabInput=document.getElementById('nginx-editor-tab');
  var targetLabelEl=document.getElementById('editor-target-label');
  var targetPathEl=document.getElementById('editor-target-path');
  var modalTitleEl=document.getElementById('editor-modal-title');
  var errorEl=document.getElementById('editor-error');
  if(!form||!hidden||!hint) return;

  var editorDataMap = <?= json_encode($editorDataMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  var currentTarget = <?= json_encode($target, JSON_UNESCAPED_UNICODE) ?>;
  var currentTab = <?= json_encode($tab, JSON_UNESCAPED_UNICODE) ?>;

  var editor=ace.edit('nginx-ace-editor');
  editor.setTheme('ace/theme/tomorrow_night');
  editor.session.setUseWrapMode(true);
  editor.session.setTabSize(2);
  editor.session.setUseSoftTabs(true);
  editor.setOptions({
    fontSize:'13px',
    showPrintMargin:false,
    useWorker:false,
    enableBasicAutocompletion:false,
    enableLiveAutocompletion:false,
    enableSnippets:false
  });

  function applyMode(m){var safe=['nginx','php','json','yaml','sh','ini','text']; if(safe.indexOf(m)<0)m='nginx'; editor.session.setMode('ace/mode/'+m);} 
  function applyTheme(t){var safe=['tomorrow_night','monokai','github_dark','dracula']; if(safe.indexOf(t)<0)t='tomorrow_night'; editor.setTheme('ace/theme/'+t);} 
  function applyFontSize(size){
    var n=parseInt(size,10);
    if(isNaN(n)||n<12||n>20) n=13;
    editor.setFontSize(n+'px');
    if(fontSizeSel) fontSizeSel.value=String(n);
    try { localStorage.setItem('nginx-editor-font-size', String(n)); } catch(e) {}
  }
  function applyFocusMode(enabled){
    if(!modalCard) return;
    var on=!!enabled;
    modalCard.classList.toggle('focus-mode', on);
    if(focusToggle) focusToggle.checked=on;
    setTimeout(function(){ editor.resize(); }, 20);
  }
  applyMode(<?= json_encode($lang) ?>);
  var themeKey='nginx-editor-theme';
  var savedTheme=(function(){ try { return localStorage.getItem(themeKey) || ''; } catch(e) { return ''; } })();
  var initialTheme = savedTheme || 'tomorrow_night';
  applyTheme(initialTheme);
  if (themeSel) themeSel.value = initialTheme;

  var savedFontSize=(function(){ try { return localStorage.getItem('nginx-editor-font-size') || ''; } catch(e) { return ''; } })();
  applyFontSize(savedFontSize || '13');
  applyFocusMode(false);

  var initial='';
  function sync(){var d=editor.getValue()!==initial; hint.textContent=d?'有未保存修改':'未修改'; hint.style.color=d?'var(--yellow)':'var(--tm)';}

  function setEditorError(msg){
    if(!errorEl) return;
    if(msg){
      errorEl.textContent=msg;
      errorEl.style.display='block';
    }else{
      errorEl.textContent='';
      errorEl.style.display='none';
    }
  }

  function activateButtons(selector, activeTarget){
    var nodes=document.querySelectorAll(selector);
    nodes.forEach(function(btn){
      var isActive=(btn.getAttribute('data-target')===activeTarget);
      btn.classList.toggle('btn-primary', isActive);
      btn.classList.toggle('btn-secondary', !isActive);
    });
  }

  function activateMainTab(tab){
    var nodes=document.querySelectorAll('[data-nav-main="1"]');
    nodes.forEach(function(btn){
      var isActive=(btn.getAttribute('data-tab')===tab);
      btn.classList.toggle('btn-primary', isActive);
      btn.classList.toggle('btn-secondary', !isActive);
    });
  }

  function renderTarget(target, tab, keepScroll){
    var item=editorDataMap[target]||null;
    if(!item) return;

    currentTarget=target;
    currentTab=tab;
    if(targetInput) targetInput.value=target;
    if(tabInput) tabInput.value=tab;

    var langNow = langSel ? (langSel.value || 'nginx') : 'nginx';
    applyMode(langNow);

    if(targetLabelEl) targetLabelEl.textContent=item.label||target;
    if(targetPathEl) targetPathEl.textContent=item.path||'';
    if(modalTitleEl) modalTitleEl.textContent='文本编辑器 · ' + (item.label||target);

    setEditorError(item.error||'');

    if(!keepScroll){
      editor.setValue(item.content||'', -1);
    }else{
      var pos=editor.session.selection.toJSON();
      editor.setValue(item.content||'', -1);
      editor.session.selection.fromJSON(pos);
    }
    initial=editor.getValue();
    hidden.value=initial;
    sync();
    editor.resize();

    if(tab==='proxy'){
      if(subnav) subnav.style.display='flex';
      activateButtons('[data-nav-proxy="1"]', target);
    }else{
      if(subnav) subnav.style.display='none';
    }
    activateMainTab(tab);
  }

  function goLine(){editor.execCommand('gotoline');}
  function openEditorModal(){ if(!editorModal) return; editorModal.classList.add('open'); setTimeout(function(){ editor.resize(); editor.focus(); }, 10); }
  function closeEditorModal(){ if(!editorModal) return; editorModal.classList.remove('open'); applyFocusMode(false); }

  editor.session.on('change',sync);
  if(findBtn)findBtn.addEventListener('click',function(){editor.execCommand('find');});
  if(gotoBtn)gotoBtn.addEventListener('click',goLine);
  if(langSel)langSel.addEventListener('change',function(){applyMode(langSel.value||'nginx');});
  if(themeSel)themeSel.addEventListener('change',function(){
    var t = themeSel.value || 'tomorrow_night';
    applyTheme(t);
    try { localStorage.setItem(themeKey, t); } catch(e) {}
  });
  if(fontSizeSel)fontSizeSel.addEventListener('change', function(){ applyFontSize(fontSizeSel.value || '13'); });
  if(focusToggle)focusToggle.addEventListener('change', function(){ applyFocusMode(!!focusToggle.checked); });
  if(focusExitBtn)focusExitBtn.addEventListener('click', function(){ applyFocusMode(false); });
  if(openModalBtn) openModalBtn.addEventListener('click', openEditorModal);
  if(closeModalBtn) closeModalBtn.addEventListener('click', closeEditorModal);
  if(closeModalBtnFooter) closeModalBtnFooter.addEventListener('click', closeEditorModal);
  window.closeEditorModal = closeEditorModal;
  if(wrap)wrap.addEventListener('change',function(){editor.session.setUseWrapMode(!!wrap.checked);});

  if(navCard){
    navCard.addEventListener('click', function(e){
      var btn=e.target.closest('a[data-target]');
      if(!btn) return;
      e.preventDefault();
      var target=btn.getAttribute('data-target')||'main';
      var tab=btn.getAttribute('data-tab')||'main';
      renderTarget(target, tab, false);
      if(editorModal && editorModal.classList.contains('open')){
        setTimeout(function(){ editor.focus(); }, 10);
      }
    });
  }

  editor.commands.addCommand({name:'save',bindKey:{win:'Ctrl-S',mac:'Command-S'},exec:function(){var b=form.querySelector('button[name="action"][value="save"]'); if(b)b.click();}});
  editor.commands.addCommand({name:'saveReload',bindKey:{win:'Ctrl-Shift-S',mac:'Command-Shift-S'},exec:function(){var b=form.querySelector('button[name="action"][value="save_and_reload"]'); if(b)b.click();}});
  editor.commands.addCommand({name:'goto',bindKey:{win:'Ctrl-G',mac:'Command-G'},exec:goLine});

  form.addEventListener('submit',function(){hidden.value=editor.getValue();});
  window.addEventListener('keydown', function(e){
    if (e.key === 'Escape' && editorModal && editorModal.classList.contains('open')) {
      closeEditorModal();
    }
  });
  window.addEventListener('beforeunload',function(e){if(editor.getValue()===initial)return; e.preventDefault(); e.returnValue='';});

  renderTarget(currentTarget, currentTab, false);
})();
</script>

<?php require_once __DIR__ . '/shared/footer.php'; ?>
