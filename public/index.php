<?php
/**
 * 导航首页 index.php
 * 展示分组+站点卡片，支持搜索、折叠、Favicon异步加载、自定义背景
 */
require_once __DIR__ . '/../shared/auth.php';
auth_check_setup();

$cfg       = auth_get_config();
$site_name = $cfg['site_name'] ?? '导航中心';
$bg_color  = $cfg['bg_color']  ?? '';
$bg_image  = $cfg['bg_image']  ?? '';
$card_size      = max(50, min(600, (int)($cfg['card_size']   ?? 140)));
$card_height    = max(0,  min(800, (int)($cfg['card_height'] ?? 0)));
$card_show_desc = ($cfg['card_show_desc'] ?? '1') === '1';
$card_layout    = in_array($cfg['card_layout']    ?? 'grid', ['grid','compact','list','large'])        ? ($cfg['card_layout']    ?? 'grid') : 'grid';
$card_direction = in_array($cfg['card_direction'] ?? 'col',  ['col','row','row-reverse','col-center']) ? ($cfg['card_direction'] ?? 'col')  : 'col';

// 读取站点配置
$raw    = file_exists(DATA_DIR.'/sites.json') ? file_get_contents(DATA_DIR.'/sites.json') : '{"groups":[]}';
$data   = json_decode($raw, true) ?? ['groups' => []];
$groups = $data['groups'] ?? [];

// 当前用户信息
$user     = auth_get_current_user();
$is_admin = ($user['role'] ?? '') === 'admin';
$token    = $_COOKIE[SESSION_COOKIE_NAME] ?? '';

// 判断是否有公开分组（auth_required=false）
$has_public = false;
foreach ($groups as $g) {
    if (!($g['auth_required'] ?? true)) { $has_public = true; break; }
}

function homepage_pending_proxy_sites(): array {
    $cfg_path = CONFIG_FILE;
    $sites_path = DATA_DIR . '/sites.json';
    $cfg = file_exists($cfg_path) ? (json_decode(file_get_contents($cfg_path), true) ?? []) : [];
    $last_applied = (int)($cfg['nginx_last_applied'] ?? 0);
    $sites_mtime = file_exists($sites_path) ? filemtime($sites_path) : 0;
    if ($sites_mtime <= $last_applied) return [];

    $sites_data = file_exists($sites_path)
        ? (json_decode(file_get_contents($sites_path), true) ?? ['groups' => []])
        : ['groups' => []];

    $pending = [];
    foreach (($sites_data['groups'] ?? []) as $grp) {
        foreach (($grp['sites'] ?? []) as $s) {
            if (($s['type'] ?? '') !== 'proxy') continue;
            $pending[] = [
                'name' => $s['name'] ?? $s['id'] ?? '未命名代理站点',
            ];
        }
    }
    return $pending;
}

$_pending_proxy = homepage_pending_proxy_sites();

// 前台管理员执行 Reload 按钮需要提前建立 Session，避免输出后再 session_start 导致 CSRF 失效
if ($is_admin && !empty($_pending_proxy)) {
    csrf_token();
}

// 无登录、无公开分组、且无待生效提示时才跳转登录
if (!$user && !$has_public && empty($_pending_proxy)) {
    $r = urlencode((isset($_SERVER['HTTPS'])?'https':'http').'://'.$_SERVER['HTTP_HOST'].$_SERVER['REQUEST_URI']);
    header('Location: login.php?redirect='.$r); exit;
}

/**
 * 根据站点类型构造跳转 URL
 */
function build_nav_url(array $site, string $token): string {
    switch ($site['type'] ?? 'external') {
        case 'internal':
            $sep = (strpos($site['url'] ?? '', '?') !== false) ? '&' : '?';
            return ($site['url'] ?? '#').$sep.'_nav_token='.urlencode($token);
        case 'proxy':
            if (($site['proxy_mode'] ?? '') === 'domain')
                return 'https://'.($site['proxy_domain'] ?? '').'/';
            return '/p/'.($site['slug'] ?? '').'/';
        default: return $site['url'] ?? '#';
    }
}

// 构造背景样式（安全校验 bg_image，防路径遍历）
$bg_style = '';
if ($bg_image) {
    // 只允许字母数字下划线点横杠，防止路径遍历
    $safe_bg = preg_replace('/[^a-zA-Z0-9._-]/', '', basename($bg_image));
    if ($safe_bg && file_exists(DATA_DIR . '/bg/' . $safe_bg)) {
        $bg_style = "background-image:url('/data/bg/" . htmlspecialchars($safe_bg) . "');background-size:cover;background-attachment:fixed;";
    }
} elseif ($bg_color && preg_match('/^#[0-9a-fA-F]{3,8}$/', $bg_color)) {
    $bg_style = "background-color:" . htmlspecialchars($bg_color) . ";";
} else {
    $bg_style = "background-image:radial-gradient(ellipse 70% 50% at 50% -5%,rgba(0,212,170,.08),transparent 65%);";
}
?>
<!DOCTYPE html><html lang="zh-CN"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= htmlspecialchars($site_name) ?></title>
<style>
:root{--bg:#0f1117;--sf:#1a1d27;--bd:#2a2d3a;--ac:#6c63ff;--ac2:#a78bfa;
--tx:#e2e4f0;--tm:#7b7f9e;--r:14px;--fn:'Segoe UI','PingFang SC','Microsoft YaHei',sans-serif}
*{box-sizing:border-box;margin:0;padding:0}
body{background:var(--bg);color:var(--tx);font-family:var(--fn);min-height:100vh;
<?= $bg_style ?>}
header{display:flex;align-items:center;justify-content:space-between;padding:13px 24px;
border-bottom:1px solid var(--bd);backdrop-filter:blur(12px);position:sticky;top:0;z-index:100;
background:rgba(15,17,23,.88)}
.logo{display:flex;align-items:center;gap:8px;font-weight:700;font-size:17px;text-decoration:none;color:var(--tx)}
.dot{width:7px;height:7px;background:var(--ac);border-radius:50%;box-shadow:0 0 7px var(--ac);animation:pulse 2s infinite}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.4}}
.hr{display:flex;align-items:center;gap:8px;font-size:13px}
.sb{background:rgba(255,255,255,.05);border:1px solid var(--bd);border-radius:8px;
padding:5px 11px;color:var(--tx);font-size:13px;outline:none;width:180px;
font-family:var(--fn);transition:width .2s,border-color .2s}
.sb:focus{border-color:var(--ac);width:240px}
.ub{background:var(--sf);border:1px solid var(--bd);border-radius:20px;padding:4px 12px;font-size:13px}
.nl{color:var(--tm);text-decoration:none;font-size:13px;padding:4px 10px;
border:1px solid var(--bd);border-radius:16px;transition:all .15s}
.nl:hover{color:var(--tx);border-color:rgba(108,99,255,.5)}
.nav-bar{display:flex;gap:6px;padding:10px 24px;overflow-x:auto;
border-bottom:1px solid var(--bd);background:rgba(15,17,23,.6);backdrop-filter:blur(8px);scrollbar-width:none}
.nav-bar::-webkit-scrollbar{display:none}
.na{color:var(--tm);text-decoration:none;font-size:13px;white-space:nowrap;
padding:5px 14px;border-radius:8px;transition:all .15s;border:1px solid transparent}
.na:hover{color:var(--tx);background:rgba(255,255,255,.06)}
.na.active{color:var(--tx);background:rgba(108,99,255,.15);border-color:rgba(108,99,255,.35)}
main{max-width:1280px;margin:0 auto;padding:28px 20px}
.sec{display:none}.sec.active{display:block}
<?php
// ── 方向映射 ──
$dir_map = [
    'col'         => ['flex'=>'column',      'align'=>'flex-start', 'text'=>'left'],
    'row'         => ['flex'=>'row',          'align'=>'center',     'text'=>'left'],
    'row-reverse' => ['flex'=>'row-reverse',  'align'=>'center',     'text'=>'left'],
    'col-center'  => ['flex'=>'column',      'align'=>'center',     'text'=>'center'],
];
$dir = $dir_map[$card_direction] ?? $dir_map['col'];

// ── 根据布局方案+方向生成 CSS ──
$ch_css = $card_height > 0 ? "min-height:{$card_height}px;" : '';

switch ($card_layout) {
    case 'compact':
        // 紧凑模式：强制横向单行，忽略 direction
        echo ".grid{display:grid;grid-template-columns:repeat(auto-fill,minmax({$card_size}px,1fr));gap:6px}";
        echo ".card{background:var(--sf);border:1px solid var(--bd);border-radius:10px;padding:8px 12px;"
           . "text-decoration:none;color:var(--tx);display:flex;flex-direction:row;align-items:center;gap:8px;"
           . "transition:transform .15s,border-color .15s;position:relative;overflow:hidden;{$ch_css}}"
           . ".card .bx{display:none}.card .cd{display:none}"
           . ".card .ci{width:22px;height:22px;flex-shrink:0;font-size:16px;display:flex;align-items:center;justify-content:center}"
           . ".card .ci img{width:16px;height:16px}"
           . ".card .cn{font-size:12px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}";
        break;
    case 'list':
        // 列表模式：强制横向，direction 控制图标左/右
        $fd = in_array($card_direction,['row-reverse']) ? 'row-reverse' : 'row';
        echo ".grid{display:flex;flex-direction:column;gap:5px}";
        echo ".card{background:var(--sf);border:1px solid var(--bd);border-radius:10px;padding:10px 16px;"
           . "text-decoration:none;color:var(--tx);display:flex;flex-direction:{$fd};align-items:center;gap:12px;"
           . "transition:transform .15s,border-color .15s;position:relative;overflow:hidden;{$ch_css}}"
           . ".card .bx{position:static;font-size:10px;padding:2px 7px;border-radius:9px;font-weight:600;flex-shrink:0;order:99}"
           . ".card .ci{width:30px;height:30px;flex-shrink:0;font-size:20px;display:flex;align-items:center;justify-content:center}"
           . ".card .ci img{width:20px;height:20px}"
           . ".card .cn{font-size:13px;font-weight:600;flex:1}"
           . ".card .cd{font-size:11px;color:var(--tm);flex:2}";
        break;
    case 'large':
        // 大卡片：方向影响内容排列
        $fd  = $dir['flex'];
        $al  = $dir['align'];
        $ta  = $dir['text'];
        echo ".grid{display:grid;grid-template-columns:repeat(auto-fill,minmax({$card_size}px,1fr));gap:12px}";
        echo ".card{background:var(--sf);border:1px solid var(--bd);border-radius:14px;padding:20px 16px;"
           . "text-decoration:none;color:var(--tx);display:flex;flex-direction:{$fd};align-items:{$al};text-align:{$ta};gap:10px;"
           . "transition:transform .2s,border-color .2s,box-shadow .2s;position:relative;overflow:hidden;{$ch_css}}"
           . ".card .bx{position:absolute;top:0;right:0;font-size:9px;padding:1px 5px;border-radius:0 var(--r) 0 6px;font-weight:600;line-height:1.6}"
           . ".card .ci{width:44px;height:44px;font-size:32px;display:flex;align-items:center;justify-content:center;flex-shrink:0}"
           . ".card .ci img{width:32px;height:32px;border-radius:8px}"
           . ".card .cn{font-size:14px;font-weight:700}"
           . ".card .cd{font-size:12px;color:var(--tm);line-height:1.5}";
        break;
    default: // grid
        $fd  = $dir['flex'];
        $al  = $dir['align'];
        $ta  = $dir['text'];
        $pd  = in_array($card_direction,['row','row-reverse']) ? '10px 14px' : '12px 14px';
        echo ".grid{display:grid;grid-template-columns:repeat(auto-fill,minmax({$card_size}px,1fr));gap:8px}";
        echo ".card{background:var(--sf);border:1px solid var(--bd);border-radius:var(--r);padding:{$pd};"
           . "text-decoration:none;color:var(--tx);display:flex;flex-direction:{$fd};align-items:{$al};text-align:{$ta};gap:8px;"
           . "transition:transform .18s,border-color .18s,box-shadow .18s;position:relative;overflow:hidden;{$ch_css}}"
           . ".card .ci{width:28px;height:28px;display:flex;align-items:center;justify-content:center;font-size:20px;flex-shrink:0}"
           . ".card .ci img{width:18px;height:18px;border-radius:3px;object-fit:contain}"
           . ".card .cn{font-weight:600;font-size:13px;" . (in_array($card_direction,['row','row-reverse'])?' flex:1;':'') . "}"
           . ".card .cd{font-size:11px;color:var(--tm);line-height:1.4}";
}
?>
.card:before{content:'';position:absolute;inset:0;
background:linear-gradient(135deg,rgba(108,99,255,.07),transparent);opacity:0;transition:opacity .2s}
.card:hover{transform:translateY(-2px);border-color:rgba(108,99,255,.5);box-shadow:0 6px 20px rgba(108,99,255,.12)}
.card:hover:before{opacity:1}
.bx{position:absolute;top:0;right:0;font-size:9px;padding:1px 5px;border-radius:0 var(--r) 0 6px;font-weight:600;line-height:1.6}
.bi{background:rgba(108,99,255,.15);color:var(--ac2)}
.bp{background:rgba(251,191,36,.12);color:#fbbf24}
.be{background:rgba(156,163,175,.1);color:#9ca3af}
footer{text-align:center;padding:22px;color:var(--tm);font-size:12px;border-top:1px solid var(--bd)}
.hidden{display:none!important}
/* ── Tooltip ── */
.card{position:relative}
.tt{position:absolute;bottom:calc(100% + 8px);left:50%;transform:translateX(-50%) scale(.95);
background:#1e2130;border:1px solid var(--bd);border-radius:10px;padding:10px 12px;
width:max-content;max-width:240px;min-width:140px;
font-size:11px;color:var(--tx);line-height:1.5;
box-shadow:0 8px 24px rgba(0,0,0,.4);z-index:200;
opacity:0;pointer-events:none;transition:opacity .15s,transform .15s}
.tt p{margin:0 0 4px;color:var(--tx)}
.tt-url{color:var(--tm);word-break:break-all;font-family:monospace;font-size:10px}
.card:hover .tt{opacity:1;transform:translateX(-50%) scale(1);pointer-events:auto}
/* 防止 tooltip 超出视口左边 */
.card:first-child .tt,.card:nth-child(4n+1) .tt{left:0;transform:translateX(0) scale(.95)}
.card:first-child:hover .tt,.card:nth-child(4n+1):hover .tt{transform:translateX(0) scale(1)}
<?php if($card_show_desc): ?>.card .cd{display:block}<?php else: ?>.card .cd{display:none}<?php endif; ?>
@media(max-width:768px){
  .grid{grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:10px}
  header{padding:10px 14px}
  .sb{width:120px}.sb:focus{width:150px}
  main{padding:20px 12px}
}
@media(max-width:480px){
  .grid{grid-template-columns:1fr 1fr}
  .hr .ub{display:none}
}
</style></head><body>
<header>
  <a class="logo" href="/"><div class="dot"></div><?= htmlspecialchars($site_name) ?></a>
  <div class="hr">
    <input class="sb" id="sq" placeholder="搜索… (/)" autocomplete="off" type="search">
    <?php if($user):?><div class="ub">👤 <?= htmlspecialchars($user['username']) ?></div><?php endif;?>
    <?php if($is_admin):?><a href="../admin/" class="nl">⚙ 后台</a><?php endif;?>
    <?php if($user):?><a href="logout.php" class="nl">退出</a><?php else:?><a href="login.php" class="nl">登录</a><?php endif;?>
  </div>
</header>
<nav class="nav-bar" id="tabs">
<?php $first_grp = true; foreach($groups as $grp):
  if(($grp['visible_to']??'all')==='admin'&&!$is_admin)continue;
  if(($grp['auth_required']??true)&&!$user)continue;
?><a class="na<?= $first_grp ? ' active' : '' ?>" href="#" data-tab="g-<?= htmlspecialchars($grp['id']) ?>"><?= htmlspecialchars($grp['icon']??'') ?> <?= htmlspecialchars($grp['name']) ?></a>
<?php $first_grp = false; endforeach;?>
</nav>
<main id="mn">
<?php if (!empty($_pending_proxy)): ?>
<div id="proxy-pending-bar" style="background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.35);border-radius:10px;padding:10px 16px;margin-bottom:14px;display:flex;align-items:center;gap:12px;flex-wrap:wrap">
  <span style="color:#f87171;font-size:13px;flex:1">
    ⚠️
    <?php if (count($_pending_proxy) <= 3): ?>
      以下代理站点配置已修改但尚未生效：
      <strong><?= implode('、', array_map(function($s){ return htmlspecialchars($s['name']); }, $_pending_proxy)) ?></strong>
    <?php else: ?>
      有 <strong><?= count($_pending_proxy) ?></strong> 个代理站点配置已修改但尚未生效，请及时 Reload Nginx 使其生效。
    <?php endif; ?>
  </span>
  <?php if ($is_admin): ?>
  <form method="POST" action="/admin/settings.php" style="margin:0">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="nginx_apply_and_reload">
    <button type="submit" style="background:rgba(239,68,68,.2);border:1px solid rgba(239,68,68,.5);color:#f87171;border-radius:8px;padding:6px 14px;font-size:12px;cursor:pointer;white-space:nowrap">🔄 生成配置并 Reload Nginx</button>
  </form>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php $first_grp = true; foreach($groups as $grp):
  if(($grp['visible_to']??'all')==='admin'&&!$is_admin)continue;
  if(($grp['auth_required']??true)&&!$user)continue;
  $gid=htmlspecialchars($grp['id']);
?>
<div class="sec<?= $first_grp ? ' active' : '' ?>" id="g-<?=$gid?>">
  <div class="grid">
  <?php foreach($grp['sites']??[] as $s):
    $href=build_nav_url($s,$token);
    $tc=['internal'=>'bi','proxy'=>'bp','external'=>'be'][$s['type']??'']??'be';
    $tl=['internal'=>'内站','proxy'=>'代理','external'=>'外链'][$s['type']??'']??'';
    $icon_url=($s['url']??$s['proxy_target']??'');
    $domain=parse_url($icon_url,PHP_URL_HOST)??'';
  ?>
  <a class="card" href="<?= htmlspecialchars($href) ?>" target="_blank" rel="noopener noreferrer"
     data-name="<?= htmlspecialchars(strtolower($s['name'])) ?>"
     data-desc="<?= htmlspecialchars(strtolower($s['desc']??'')) ?>">
    <span class="bx <?=$tc?>"><?=$tl?></span>
    <div class="ci">
      <?php if($domain&&!is_private_ip($domain)):?>
      <img src="/favicon.php?url=<?= urlencode('https://'.$domain) ?>"
           onerror="this.style.display='none';this.nextElementSibling.style.display='block'"
           alt="" loading="lazy">
      <span style="display:none"><?= htmlspecialchars($s['icon']??'🔗') ?></span>
      <?php else:?>
      <span><?= htmlspecialchars($s['icon']??'🔗') ?></span>
      <?php endif;?>
    </div>
    <div class="cn"><?= htmlspecialchars($s['name']) ?></div>
    <?php if(!empty($s['desc']) || !empty($href)): ?>
    <div class="tt">
      <?php if(!empty($s['desc'])): ?><p><?= htmlspecialchars($s['desc']) ?></p><?php endif; ?>
      <span class="tt-url"><?= htmlspecialchars($s['url'] ?? $s['proxy_target'] ?? '') ?></span>
    </div>
    <?php endif; ?>
  </a>
  <?php endforeach;?></div>
</div>
<?php $first_grp = false; endforeach;?></main>
<footer><?php if($user):?><?= htmlspecialchars($user['username']) ?> &nbsp;·&nbsp; Cookie 到期 <?= date('Y-m-d H:i',$user['exp']??time()) ?><?php endif;?></footer>
<script>
// ── Tab 切换 ──
var tabs=document.querySelectorAll('.na[data-tab]');
var secs=document.querySelectorAll('.sec');
function showTab(id){
  tabs.forEach(function(t){t.classList.toggle('active',t.dataset.tab===id);});
  secs.forEach(function(s){s.classList.toggle('active',s.id===id);});
  localStorage.setItem('nav_tab',id);
}
// 恢复上次选中的 Tab
var savedTab=localStorage.getItem('nav_tab');
if(savedTab&&document.getElementById(savedTab))showTab(savedTab);
tabs.forEach(function(t){
  t.addEventListener('click',function(e){e.preventDefault();showTab(this.dataset.tab);});
});
// ── 搜索 ──
document.addEventListener('keydown',function(e){
  if(e.key==='/'&&document.activeElement.tagName!=='INPUT'){e.preventDefault();document.getElementById('sq').focus();}
  if(e.key==='Escape')document.getElementById('sq').blur();
});
document.getElementById('sq').addEventListener('input',function(){
  var q=this.value.toLowerCase().trim();
  if(!q){
    // 恢复 Tab 模式
    var cur=localStorage.getItem('nav_tab')||'';
    secs.forEach(function(s){s.classList.toggle('active',!cur||s.id===cur);s.style.display='';});
    secs.forEach(function(s){s.querySelectorAll('.card').forEach(function(c){c.style.display='';});});
    return;
  }
  // 搜索模式：显示所有含匹配结果的分组
  secs.forEach(function(sec){
    var cards=sec.querySelectorAll('.card');var any=false;
    cards.forEach(function(c){
      var m=(c.dataset.name||'').includes(q)||(c.dataset.desc||'').includes(q);
      c.style.display=m?'':'none';if(m)any=true;
    });
    sec.classList.toggle('active',any);
    sec.style.display=any?'':'none';
  });
});
</script></body></html>