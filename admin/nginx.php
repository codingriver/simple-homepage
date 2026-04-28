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

  // ── 下载 Nginx 代理配置 ──
  if ($action === 'gen_nginx') {
      $cfg        = load_config();
      $sites_data = load_sites();
      $domain     = $cfg['nav_domain'] ?? 'nav.yourdomain.com';
      $lines      = ['# Nginx 代理配置 — 由导航站自动生成于 ' . date('Y-m-d H:i:s'), ''];
      foreach ($sites_data['groups'] as $grp) {
          foreach ($grp['sites'] ?? [] as $s) {
              if (($s['type'] ?? '') !== 'proxy') continue;
              $target = $s['proxy_target'] ?? '';
              $name   = $s['name'] ?? $s['id'];
              if (($s['proxy_mode'] ?? 'path') === 'path') {
                  $slug = $s['slug'] ?? $s['id'];
                  $lines[] = "# {$name}";
                  $lines[] = "location /p/{$slug}/ {";
                  $lines[] = "    proxy_pass {$target}/;";
                  $lines[] = "    proxy_set_header Host \$host;";
                  $lines[] = "    proxy_set_header X-Real-IP \$remote_addr;";
                  $lines[] = "}";
                  $lines[] = '';
              } else {
                  $pd = $s['proxy_domain'] ?? '';
                  if (!$pd) continue;
                  $lines[] = "# {$name} (子域名模式)";
                  $lines[] = 'server {';
                  $lines[] = "    listen 443 ssl http2;";
                  $lines[] = "    server_name {$pd};";
                  $lines[] = "    location / { proxy_pass {$target}; }";
                  $lines[] = '}';
                  $lines[] = '';
              }
          }
      }
      $content = implode("\n", $lines);
      header('Content-Type: text/plain; charset=utf-8');
      header('Content-Disposition: attachment; filename="nav_proxy_' . date('Ymd_His') . '.conf"');
      header('Content-Length: ' . strlen($content));
      echo $content; exit;
  }

  // ── 自动生成代理配置并写入 + reload ──
  if ($action === 'nginx_apply' || $action === 'nginx_reload' || $action === 'nginx_apply_and_reload') {
      $do_reload = ($action === 'nginx_reload' || $action === 'nginx_apply_and_reload');
      $result = nginx_apply_proxy_conf($do_reload);

      if (!$result['ok']) {
          flash_set('error', $result['msg']);
          header('Location: nginx.php#proxy'); exit;
      }

      if ($do_reload) {
          nginx_mark_applied();
      }
      audit_log('nginx_apply', ['reload' => $do_reload, 'ok' => $result['ok']]);
      flash_set('success', $result['msg']);
      $redirect = ($action === 'nginx_apply_and_reload') ? 'nginx.php' : 'nginx.php#proxy';
      header('Location: ' . $redirect); exit;
  }

  // ── 保存反代参数模式 ──
  if ($action === 'save_proxy_params_mode') {
      $cfg = load_config();
      $cfg['proxy_params_mode'] = ($_POST['proxy_params_mode'] ?? 'simple') === 'full' ? 'full' : 'simple';
      save_config($cfg);
      audit_log('save_proxy_params_mode', ['mode' => $cfg['proxy_params_mode']]);
      flash_set('success', '反代参数模式已保存');
      header('Location: nginx.php#proxy'); exit;
  }

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

<?php
$proxy_conf_path = nginx_proxy_conf_path();
$conf_exists     = file_exists($proxy_conf_path);
$conf_mtime      = $conf_exists ? date('Y-m-d H:i:s', filemtime($proxy_conf_path)) : null;
$proxy_count = 0;
foreach (load_sites()['groups'] ?? [] as $g)
    foreach ($g['sites'] ?? [] as $s)
        if (($s['type'] ?? '') === 'proxy') $proxy_count++;
?>

<div class="card" id="proxy">
  <div class="card-title">🔀 Nginx 代理配置生成
    <span style="font-size:11px;color:var(--tm);font-weight:400;margin-left:8px">基于站点数据自动生成</span>
  </div>

  <!-- Reload 执行环境：首屏不 exec，进入本区域时异步检测 -->
  <div id="nginx-sudo-banner" style="min-height:0"></div>

  <div style="display:flex;gap:16px;flex-wrap:wrap;align-items:flex-start;margin-bottom:18px">
    <div style="background:var(--bg);border:1px solid var(--bd);border-radius:8px;padding:14px 18px;flex:1;min-width:200px">
      <div style="font-size:11px;color:var(--tm);margin-bottom:4px">配置文件</div>
      <div style="font-size:13px;font-family:monospace"><?= htmlspecialchars($proxy_conf_path) ?></div>
      <div style="margin-top:6px">
        <?php if ($conf_exists): ?>
        <span class="badge badge-green">已生成</span>
        <span style="font-size:11px;color:var(--tm);margin-left:6px">上次更新：<?= $conf_mtime ?></span>
        <?php else: ?>
        <span class="badge badge-gray">未生成</span>
        <?php endif; ?>
      </div>
    </div>
    <div style="background:var(--bg);border:1px solid var(--bd);border-radius:8px;padding:14px 18px;min-width:120px">
      <div style="font-size:11px;color:var(--tm);margin-bottom:4px">Proxy 站点数</div>
      <div style="font-size:28px;font-weight:700;color:var(--ac2)"><?= $proxy_count ?></div>
    </div>
  </div>

  <!-- 反代参数模式选择 -->
  <?php $ppm = $cfg['proxy_params_mode'] ?? 'simple'; ?>
  <div style="margin-bottom:16px;background:var(--bg);border:1px solid var(--bd);border-radius:10px;padding:14px 18px">
    <div style="font-size:12px;color:var(--tm);margin-bottom:10px;font-weight:600">📦 反代参数模板</div>
    <form method="POST" id="proxy-params-mode-form" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save_proxy_params_mode">
      <label data-ppm-card="simple" style="display:flex;align-items:flex-start;gap:10px;cursor:pointer;flex:1;min-width:220px;background:<?= $ppm==='simple'?'rgba(99,179,237,.08)':'var(--sf)' ?>;border:2px solid <?= $ppm==='simple'?'var(--ac)':'var(--bd)' ?>;border-radius:8px;padding:12px;transition:all .2s">
        <input type="radio" name="proxy_params_mode" value="simple" <?= $ppm==='simple'?'checked':'' ?> id="ppm_simple" style="margin-top:2px;accent-color:var(--ac)">
        <div>
          <div style="font-size:13px;font-weight:700;color:var(--tx)">⚡ 精简模式 <span style="font-size:11px;font-weight:400;color:var(--tm);">（14 条参数 · 超时 60s）</span></div>
          <div style="font-size:11px;color:var(--tm);margin-top:4px;line-height:1.6">HTTP/1.1、WebSocket 升级、Host / IP / Proto 透传、连接 10s + 读写 60s 超时、基础缓冲。<br>适合普通 Web 应用，<b>默认推荐</b>，小白首选。</div>
        </div>
      </label>
      <label data-ppm-card="full" style="display:flex;align-items:flex-start;gap:10px;cursor:pointer;flex:1;min-width:220px;background:<?= $ppm==='full'?'rgba(99,179,237,.08)':'var(--sf)' ?>;border:2px solid <?= $ppm==='full'?'var(--ac)':'var(--bd)' ?>;border-radius:8px;padding:12px;transition:all .2s">
        <input type="radio" name="proxy_params_mode" value="full" <?= $ppm==='full'?'checked':'' ?> id="ppm_full" style="margin-top:2px;accent-color:var(--ac)">
        <div>
          <div style="font-size:13px;font-weight:700;color:var(--tx)">🔥 完整模式 <span style="font-size:11px;font-weight:400;color:var(--tm);">（60+ 条参数 · 超时 86400s）</span></div>
          <div style="font-size:11px;color:var(--tm);margin-top:4px;line-height:1.6">WebSocket 全头透传、断点续传、Cookie / Auth / CORS 透传、流媒体无缓冲、无限超时（86400s）、全量响应头直通。<br>适合视频流、大文件、SSH 隧道、长连接等复杂场景。</div>
        </div>
      </label>
      <div style="display:flex;flex-direction:column;gap:8px;align-self:center">
        <button type="submit" class="btn btn-primary" style="white-space:nowrap">💾 保存模式</button>
        <?php if ($proxy_count > 0): ?>
        <span style="font-size:11px;color:var(--tm);text-align:center">保存后需 Reload<br>才能生效</span>
        <?php endif; ?>
      </div>
    </form>
    <div class="form-hint" style="margin-top:8px">
      切换模式后需点击下方「生成配置并 Reload Nginx」重新生成配置文件才会生效。<?php if ($proxy_count > 0): ?> <span style="color:#fbbf24">当前有 <?= $proxy_count ?> 个代理站点，切换后请及时 Reload。</span><?php endif; ?>
    </div>
  </div>

  <!-- 操作按钮 -->
  <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px">
    <form method="POST" id="nginx-reload-form" style="display:inline"><?= csrf_field() ?>
      <input type="hidden" name="action" value="nginx_reload">
      <button class="btn btn-primary" id="nginx-reload-btn">
        🔄 生成配置并 Reload Nginx
      </button>
    </form>
    <form method="POST" style="display:inline"><?= csrf_field() ?>
      <input type="hidden" name="action" value="nginx_apply">
      <button class="btn btn-secondary">📝 仅生成配置文件（不 reload）</button>
    </form>
    <form method="POST" style="display:inline"><?= csrf_field() ?>
      <input type="hidden" name="action" value="gen_nginx">
      <button class="btn btn-secondary">⬇ 下载配置文件</button>
    </form>
  </div>

  <!-- 配置文件预览 -->
  <?php if ($conf_exists): ?>
  <details style="margin-top:4px">
    <summary style="cursor:pointer;font-size:13px;color:var(--tm);user-select:none">
      查看当前配置文件内容 ▸
    </summary>
    <pre style="margin-top:10px;background:var(--bg);border:1px solid var(--bd);
border-radius:8px;padding:14px;font-size:11px;font-family:monospace;color:#a5f3a5;
overflow-x:auto;max-height:300px;overflow-y:auto"><?=
      htmlspecialchars(@file_get_contents($proxy_conf_path) ?: '（读取失败）')
    ?></pre>
  </details>
  <?php endif; ?>

  <div class="alert alert-info" style="margin-top:16px">
    ℹ️ 点击「生成配置并 Reload」将自动写入
    <code style="font-size:11px">/etc/nginx/conf.d/nav-proxy.conf</code>
    并执行 Nginx 语法检测与 Reload。
    语法检测失败时会中止 reload 并显示错误信息。
  </div>
  <div id="nginx-reload-note" class="form-hint" style="margin-top:10px">按钮始终可点击；环境检测未通过时，提交后会显示明确错误原因。</div>
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

// ── 反代参数模式选择卡片联动 ──
function selectPPM(val) {
    var radios = document.querySelectorAll('input[name="proxy_params_mode"]');
    radios.forEach(function(radio) {
        if (val) {
            radio.checked = radio.value === val;
        }
        var card = radio.closest('[data-ppm-card]');
        if (!card) return;
        var isSelected = !!radio.checked;
        card.style.borderColor = isSelected ? 'var(--ac)' : 'var(--bd)';
        card.style.background  = isSelected ? 'rgba(99,179,237,.08)' : 'var(--sf)';
    });
}

document.addEventListener('DOMContentLoaded', function() {
    var radios = document.querySelectorAll('input[name="proxy_params_mode"]');
    radios.forEach(function(radio) {
        radio.addEventListener('change', function() {
            selectPPM();
        });
        var card = radio.closest('[data-ppm-card]');
        if (!card) return;
        card.addEventListener('click', function() {
            radio.checked = true;
            selectPPM();
        });
    });
    selectPPM();
});

// ── Nginx 代理配置生成环境检测 ──
(function initNginxLazy() {
    var nginxReloadForm = document.getElementById('nginx-reload-form');
    var nginxReloadBtn = document.getElementById('nginx-reload-btn');
    var nginxReloadNote = document.getElementById('nginx-reload-note');
    var nginxSubmitting = false;

    function setNginxReloadUi(state, note) {
        if (nginxReloadBtn) {
            if (state === 'submitting') {
                nginxReloadBtn.disabled = true;
                nginxReloadBtn.textContent = '处理中...';
            } else {
                nginxReloadBtn.disabled = false;
                nginxReloadBtn.textContent = '🔄 生成配置并 Reload Nginx';
            }
        }
        if (nginxReloadNote && note) {
            nginxReloadNote.textContent = note;
        }
    }

    if (nginxReloadForm) {
        nginxReloadForm.addEventListener('submit', function() {
            if (nginxSubmitting) return false;
            nginxSubmitting = true;
            setNginxReloadUi('submitting', '正在生成配置并触发 Nginx Reload，请稍候...');
        });
    }

    var nginxLoaded = false;
    function loadNginxSudoOnce() {
        if (nginxLoaded) return;
        nginxLoaded = true;
        var el = document.getElementById('nginx-sudo-banner');
        if (!el) return;
        setNginxReloadUi('checking', '正在检测 Nginx reload 运行环境...');
        fetch('settings_ajax.php?action=nginx_sudo', { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function(r){ return r.json(); })
            .then(function(d){
                if (!d.ok) {
                    setNginxReloadUi('ready', '环境检测失败，但仍可尝试提交，失败时会显示具体原因。');
                    return;
                }
                if (d.reload_ok) {
                    el.innerHTML = '';
                    setNginxReloadUi('ready', d.message || '环境检测通过，可以直接生成配置并 Reload。');
                    return;
                }
                var html = '<div class="alert alert-warn">⚠️ ' + escHtml(d.message || '未检测到可用的 Nginx reload 执行权限。');
                if (d.sudo_hint) {
                    html += '<br>请在服务器上执行以下命令配置白名单：<pre style="margin-top:8px;background:var(--bg);padding:10px;border-radius:6px;font-size:12px;overflow-x:auto">' + escHtml(d.sudo_hint) + '</pre>';
                }
                html += '</div>';
                el.innerHTML = html;
                setNginxReloadUi('warn', '环境检测未通过，点击按钮后会返回明确错误；也可以先按上方提示补齐执行权限。');
            })
            .catch(function(){
                setNginxReloadUi('ready', '环境检测请求失败，但仍可尝试提交，失败时会显示具体原因。');
            });
    }

    var nginx = document.getElementById('proxy');
    if (window.IntersectionObserver) {
        if (nginx) {
            var io2 = new IntersectionObserver(function(entries){
                entries.forEach(function(e){ if (e.isIntersecting) loadNginxSudoOnce(); });
            }, { rootMargin: '80px' });
            io2.observe(nginx);
        }
    } else {
        if (nginx) loadNginxSudoOnce();
    }
    if (location.hash === '#proxy') loadNginxSudoOnce();
})();
</script>

<?php require_once __DIR__ . '/shared/footer.php'; ?>
