<?php
/**
 * 导航首页 index.php
 * 展示分组+站点卡片，支持搜索、折叠、Favicon异步加载、自定义背景
 */
require_once __DIR__ . '/../shared/auth.php';
require_once __DIR__ . '/../shared/request_timing.php';
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

// 健康状态缓存（仅对登录用户显示）
$health_cache = [];
if ($user) {
    $health_file = DATA_DIR . '/health_cache.json';
    if (file_exists($health_file)) {
        $health_cache = json_decode(file_get_contents($health_file), true) ?? [];
    }
}

// 判断是否有公开分组（auth_required=false）
$has_public = false;
foreach ($groups as $g) {
    if (!($g['auth_required'] ?? true)) { $has_public = true; break; }
}

function homepage_group_is_visible(array $group, bool $is_admin, $user): bool {
    if (($group['visible_to'] ?? 'all') === 'admin' && !$is_admin) {
        return false;
    }
    if (($group['auth_required'] ?? true) && !$user) {
        return false;
    }
    return true;
}

function homepage_site_tags(array $site): array {
    $raw = $site['tags'] ?? [];
    if (is_string($raw)) {
        $raw = preg_split('/[,，\n\r]+/', $raw) ?: [];
    }
    if (!is_array($raw)) {
        return [];
    }
    $tags = [];
    foreach ($raw as $tag) {
        $tag = trim((string)$tag);
        if ($tag !== '') {
            $tags[] = $tag;
        }
    }
    return array_values(array_unique($tags));
}

function homepage_days_left(string $date): ?int {
    $date = trim($date);
    if ($date === '') {
        return null;
    }
    $ts = strtotime($date . ' 00:00:00');
    if ($ts === false) {
        return null;
    }
    return (int)floor(($ts - strtotime(date('Y-m-d 00:00:00'))) / 86400);
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

// 前台含退出/管理操作需要提前建立 Session，避免输出后再 session_start 导致 CSRF 失效
if ($user) {
    csrf_token();
}

// 无登录、无公开分组、且无待生效提示时才跳转登录
if (!$user && !$has_public && empty($_pending_proxy)) {
    $r = urlencode($_SERVER['REQUEST_URI'] ?? '/');
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

function homepage_render_site_card(array $site, array $group, string $href, string $token, array $health_cache, bool $showHealth): string {
    $type = (string)($site['type'] ?? 'external');
    $typeClass = ['internal' => 'bi', 'proxy' => 'bp', 'external' => 'be'][$type] ?? 'be';
    $typeLabel = ['internal' => '内站', 'proxy' => '代理', 'external' => '外链'][$type] ?? '外链';
    $iconUrl = (string)($site['url'] ?? ($site['proxy_target'] ?? ''));
    $domain = parse_url($iconUrl, PHP_URL_HOST) ?? '';
    $healthUrl = $type === 'proxy' ? (string)($site['proxy_target'] ?? '') : (string)($site['url'] ?? '');
    $healthEntry = $health_cache[$healthUrl] ?? null;
    $healthStatus = 'unknown';
    if ($healthEntry && (time() - ($healthEntry['checked_at'] ?? 0)) < 600) {
        $healthStatus = (string)($healthEntry['status'] ?? 'unknown');
    }
    $tags = homepage_site_tags($site);
    $favorite = !empty($site['favorite']);
    $pinned = !empty($site['pinned']);
    $statusBadge = trim((string)($site['status_badge'] ?? ''));
    $assetType = trim((string)($site['asset_type'] ?? ''));
    $env = trim((string)($site['env'] ?? ''));
    $owner = trim((string)($site['owner'] ?? ''));
    $notes = trim((string)($site['notes'] ?? ''));
    $renewUrl = trim((string)($site['renew_url'] ?? ''));
    $domainExpireAt = trim((string)($site['domain_expire_at'] ?? ''));
    $sslExpireAt = trim((string)($site['ssl_expire_at'] ?? ''));
    $domainDays = homepage_days_left($domainExpireAt);
    $sslDays = homepage_days_left($sslExpireAt);
    $siteKey = (string)($group['id'] ?? '') . ':' . (string)($site['id'] ?? '');
    $searchBlob = strtolower(implode(' ', array_filter([
        (string)($site['name'] ?? ''),
        (string)($site['desc'] ?? ''),
        (string)($group['name'] ?? ''),
        implode(' ', $tags),
        $assetType,
        $env,
        $owner,
        $statusBadge,
        $notes,
    ])));

    ob_start();
    ?>
  <a class="card<?= empty($site['desc']) ? ' no-desc' : '' ?>"
     href="<?= htmlspecialchars($href) ?>"
     target="_blank"
     rel="noopener noreferrer"
     data-site-key="<?= htmlspecialchars($siteKey) ?>"
     data-name="<?= htmlspecialchars(strtolower((string)($site['name'] ?? ''))) ?>"
     data-desc="<?= htmlspecialchars(strtolower((string)($site['desc'] ?? ''))) ?>"
     data-group="<?= htmlspecialchars(strtolower((string)($group['name'] ?? ''))) ?>"
     data-tags="<?= htmlspecialchars(strtolower(implode(',', $tags))) ?>"
     data-env="<?= htmlspecialchars(strtolower($env)) ?>"
     data-asset-type="<?= htmlspecialchars(strtolower($assetType)) ?>"
     data-status-badge="<?= htmlspecialchars(strtolower($statusBadge)) ?>"
     data-favorite="<?= $favorite ? '1' : '0' ?>"
     data-pinned="<?= $pinned ? '1' : '0' ?>"
     data-search="<?= htmlspecialchars($searchBlob) ?>">
    <span class="bx <?= htmlspecialchars($typeClass) ?>"><?= htmlspecialchars($typeLabel) ?></span>
    <?php if ($showHealth && $healthStatus !== 'unknown'): ?>
    <span class="hd" style="position:absolute;bottom:5px;right:6px;width:7px;height:7px;border-radius:50%;background:<?= $healthStatus === 'up' ? '#4ade80' : '#f87171' ?>;box-shadow:0 0 5px <?= $healthStatus === 'up' ? '#4ade80' : '#f87171' ?>;" title="<?= $healthStatus === 'up' ? '在线' : '离线' ?>"></span>
    <?php endif; ?>
    <div class="ci">
      <?php if ($domain && !is_private_ip((string)$domain)): ?>
      <img src="/favicon.php?url=<?= urlencode('https://' . $domain) ?>"
           onerror="this.style.display='none';this.nextElementSibling.style.display='block'"
           alt="" loading="lazy">
      <span style="display:none"><?= htmlspecialchars((string)($site['icon'] ?? '🔗')) ?></span>
      <?php else: ?>
      <span><?= htmlspecialchars((string)($site['icon'] ?? '🔗')) ?></span>
      <?php endif; ?>
    </div>
    <div class="cn"><?= htmlspecialchars((string)($site['name'] ?? '')) ?></div>
    <div class="card-meta-line">
      <?php if ($favorite): ?><span class="mini-badge mini-star">收藏</span><?php endif; ?>
      <?php if ($pinned): ?><span class="mini-badge mini-pin">常用</span><?php endif; ?>
      <?php if ($assetType !== ''): ?><span class="mini-badge"><?= htmlspecialchars($assetType) ?></span><?php endif; ?>
      <?php if ($env !== ''): ?><span class="mini-badge"><?= htmlspecialchars($env) ?></span><?php endif; ?>
      <?php if ($statusBadge !== ''): ?><span class="mini-badge mini-status"><?= htmlspecialchars($statusBadge) ?></span><?php endif; ?>
    </div>
    <div class="cd"><?= htmlspecialchars((string)($site['desc'] ?? $typeLabel)) ?></div>
    <?php if ($tags !== []): ?>
    <div class="tag-row">
      <?php foreach (array_slice($tags, 0, 4) as $tag): ?>
      <span class="tag-chip"><?= htmlspecialchars($tag) ?></span>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="group-chip">#<?= htmlspecialchars((string)($group['name'] ?? '')) ?></div>
    <?php if (!empty($site['desc']) || $iconUrl !== '' || $notes !== '' || $renewUrl !== ''): ?>
    <div class="tt">
      <?php if (!empty($site['desc'])): ?><p><?= htmlspecialchars((string)$site['desc']) ?></p><?php endif; ?>
      <?php if ($notes !== ''): ?><p>备注：<?= htmlspecialchars($notes) ?></p><?php endif; ?>
      <?php if ($owner !== ''): ?><p>负责人：<?= htmlspecialchars($owner) ?></p><?php endif; ?>
      <?php if ($renewUrl !== ''): ?><p>续费：<?= htmlspecialchars($renewUrl) ?></p><?php endif; ?>
      <span class="tt-url"><?= htmlspecialchars($iconUrl) ?></span>
    </div>
    <?php endif; ?>
  </a>
    <?php
    return (string)ob_get_clean();
}

$visible_groups = [];
$visible_sites = [];
foreach ($groups as $group) {
    if (!homepage_group_is_visible($group, $is_admin, $user)) {
        continue;
    }
    $groupSites = [];
    foreach ($group['sites'] ?? [] as $site) {
        if (!is_array($site)) {
            continue;
        }
        $href = build_nav_url($site, $token);
        $groupSites[] = ['site' => $site, 'href' => $href];
        $visible_sites[] = ['group' => $group, 'site' => $site, 'href' => $href];
    }
    $group['_render_sites'] = $groupSites;
    $visible_groups[] = $group;
}

$favorite_sites = array_values(array_filter($visible_sites, static fn($row) => !empty($row['site']['favorite'])));
$pinned_sites = array_values(array_filter($visible_sites, static fn($row) => !empty($row['site']['pinned'])));
$tag_options = [];
$env_options = [];
$asset_type_options = [];
$status_badge_options = [];
foreach ($visible_sites as $row) {
    foreach (homepage_site_tags($row['site']) as $tag) {
        $tag_options[$tag] = true;
    }
    $env = trim((string)($row['site']['env'] ?? ''));
    if ($env !== '') {
        $env_options[$env] = true;
    }
    $assetType = trim((string)($row['site']['asset_type'] ?? ''));
    if ($assetType !== '') {
        $asset_type_options[$assetType] = true;
    }
    $statusBadge = trim((string)($row['site']['status_badge'] ?? ''));
    if ($statusBadge !== '') {
        $status_badge_options[$statusBadge] = true;
    }
}
ksort($tag_options, SORT_NATURAL | SORT_FLAG_CASE);
ksort($env_options, SORT_NATURAL | SORT_FLAG_CASE);
ksort($asset_type_options, SORT_NATURAL | SORT_FLAG_CASE);
ksort($status_badge_options, SORT_NATURAL | SORT_FLAG_CASE);

// 构造背景样式（安全校验 bg_image，防路径遍历）
$bg_style = '';
if ($bg_image) {
    // 只允许字母数字下划线点横杠，防止路径遍历
    $safe_bg = preg_replace('/[^a-zA-Z0-9._-]/', '', basename($bg_image));
    if ($safe_bg && file_exists(DATA_DIR . '/bg/' . $safe_bg)) {
        $bg_style = "background-image:url('/bg.php?file=" . rawurlencode($safe_bg) . "');background-size:cover;background-attachment:fixed;";
    }
} elseif ($bg_color && preg_match('/^#[0-9a-fA-F]{3,8}$/', $bg_color)) {
    $bg_style = "background-color:" . htmlspecialchars($bg_color) . ";";
} else {
    $bg_style = "background-image:radial-gradient(ellipse 70% 50% at 50% -5%,rgba(0,212,170,.08),transparent 65%);";
}
$theme = $cfg['theme'] ?? 'dark';
?>
<!DOCTYPE html>
<html lang="zh-CN" data-theme="<?= htmlspecialchars($theme) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#0f1117">
<link rel="manifest" href="/manifest.webmanifest">
<title><?= htmlspecialchars($site_name) ?></title>
<script src="/gesture-guard.js" defer></script>
<?php if (!empty($cfg['custom_css'] ?? '')): ?>
<style id="nav-custom-css"><?= $cfg['custom_css'] ?></style>
<?php endif; ?>
<style>
:root{--bg:#0f1117;--sf:#1a1d27;--bd:#2a2d3a;--ac:#6c63ff;--ac2:#a78bfa;
--tx:#e2e4f0;--tm:#7b7f9e;--r:14px;--fn:'Segoe UI','PingFang SC','Microsoft YaHei',sans-serif}
*{box-sizing:border-box;margin:0;padding:0}
html{overscroll-behavior-x:none;overscroll-behavior-y:none}
body{background:var(--bg);color:var(--tx);font-family:var(--fn);min-height:100vh;
<?= $bg_style ?>overscroll-behavior-x:none;overscroll-behavior-y:none}
body.search-open{overflow-x:hidden}
header{padding:12px 18px 10px;
border-bottom:1px solid var(--bd);backdrop-filter:blur(12px);position:sticky;top:0;z-index:100;
background:rgba(15,17,23,.88)}
.topbar-main{display:flex;align-items:center;justify-content:space-between;gap:12px}
.topbar-actions{display:flex;align-items:center;gap:8px}
.topbar-actions form{display:inline-flex;margin:0}
.topbar-search{display:none;padding-top:10px}
body.search-open .topbar-search{display:block}
body.search-open .search-trigger{color:var(--tx);border-color:rgba(108,99,255,.48);background:rgba(108,99,255,.16);box-shadow:0 0 0 1px rgba(108,99,255,.08) inset}
.logo{display:flex;align-items:center;gap:8px;font-weight:700;font-size:17px;text-decoration:none;color:var(--tx);min-width:0}
.logo-text{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.dot{width:7px;height:7px;background:var(--ac);border-radius:50%;box-shadow:0 0 7px var(--ac);animation:pulse 2s infinite}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.4}}
.hr{display:flex;align-items:center;gap:8px;font-size:13px}
.sb{background:rgba(255,255,255,.05);border:1px solid var(--bd);border-radius:12px;
padding:10px 12px;color:var(--tx);font-size:13px;outline:none;width:100%;
font-family:var(--fn);transition:border-color .2s,box-shadow .2s,background .2s}
.sb:focus{border-color:var(--ac);box-shadow:0 0 0 3px rgba(108,99,255,.12);background:rgba(255,255,255,.07)}
.ub{background:var(--sf);border:1px solid var(--bd);border-radius:20px;padding:4px 12px;font-size:13px}
.nl{color:var(--tm);text-decoration:none;font-size:13px;padding:7px 10px;
border:1px solid var(--bd);border-radius:12px;transition:all .15s;background:rgba(255,255,255,.02)}
.nl:hover{color:var(--tx);border-color:rgba(108,99,255,.5)}
.search-trigger{display:inline-flex;align-items:center;justify-content:center;min-width:40px;height:40px;padding:0 12px}
.search-cancel{display:inline-flex;align-items:center;justify-content:center;min-width:44px;height:40px;padding:0 12px}
.search-row{display:flex;align-items:center;gap:8px}
.search-meta{display:none;margin-top:8px;color:var(--tm);font-size:12px}
body.search-open .search-meta{display:block}
.nav-bar{display:flex;gap:8px;padding:10px 18px 12px;overflow-x:auto;
border-bottom:1px solid var(--bd);background:rgba(15,17,23,.6);backdrop-filter:blur(8px);scrollbar-width:none}
.nav-bar::-webkit-scrollbar{display:none}
.na{color:var(--tm);text-decoration:none;font-size:13px;white-space:nowrap;
padding:7px 14px;border-radius:999px;transition:all .15s;border:1px solid rgba(255,255,255,.05);background:rgba(255,255,255,.02)}
.na:hover{color:var(--tx);background:rgba(255,255,255,.06)}
.na.active{color:var(--tx);background:rgba(108,99,255,.18);border-color:rgba(108,99,255,.42);box-shadow:0 6px 16px rgba(108,99,255,.12)}
main{max-width:1280px;margin:0 auto;padding:22px 16px}
.sec{display:none}.sec.active{display:block}
.section-label{display:none;margin-bottom:10px;color:var(--tm);font-size:12px;letter-spacing:.04em}
body.search-open .section-label{display:block}
.filter-bar{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:10px;margin:0 0 16px}
.filter-cell{display:flex;flex-direction:column;gap:6px}
.filter-cell label{font-size:11px;color:var(--tm);letter-spacing:.04em}
.filter-cell select,.filter-cell button{width:100%}
.quick-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:10px}
.quick-section{margin-bottom:18px}
.quick-section.hidden{display:none}
.quick-title{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:10px}
.quick-title strong{font-size:13px}
.quick-title span{font-size:11px;color:var(--tm)}
.card-meta-line,.tag-row{display:flex;gap:6px;flex-wrap:wrap}
.card-meta-line{min-height:20px}
.mini-badge,.tag-chip{display:inline-flex;align-items:center;gap:4px;border-radius:999px;padding:2px 8px;font-size:10px;line-height:1.5;border:1px solid rgba(255,255,255,.08);background:rgba(255,255,255,.04);color:var(--tx2)}
.mini-star{background:rgba(251,191,36,.14);color:#fbbf24;border-color:rgba(251,191,36,.28)}
.mini-pin{background:rgba(96,165,250,.14);color:#93c5fd;border-color:rgba(96,165,250,.24)}
.mini-status{background:rgba(74,222,128,.12);color:#86efac;border-color:rgba(74,222,128,.24)}
.tag-chip{color:var(--ac2);border-color:rgba(167,139,250,.22)}

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
footer{text-align:center;padding:18px 14px;color:var(--tm);font-size:12px;border-top:1px solid var(--bd)}
.card.no-desc .cd{opacity:.76}
.group-chip{display:none;margin-top:auto;padding-top:6px;font-size:10px;color:var(--ac2);letter-spacing:.04em}
body.search-open .group-chip{display:block}
#proxy-pending-bar{background:linear-gradient(180deg,rgba(239,68,68,.12),rgba(239,68,68,.06));border:1px solid rgba(239,68,68,.35);border-radius:14px;padding:12px 14px;margin-bottom:14px;display:flex;align-items:center;gap:12px;flex-wrap:wrap}
#proxy-pending-bar .proxy-pending-text{color:#fca5a5;font-size:13px;flex:1;line-height:1.6}
#proxy-pending-bar .proxy-pending-action{background:rgba(239,68,68,.14);border:1px solid rgba(239,68,68,.42);color:#fda4af;border-radius:10px;padding:8px 12px;font-size:12px;cursor:pointer;white-space:nowrap}
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
  header{padding:10px 12px 10px}
  .topbar-main{gap:10px}
  .logo{font-size:15px;max-width:50vw}
  .topbar-actions{gap:6px}
  .topbar-actions .ub{display:none}
  .topbar-actions .nl{font-size:12px;padding:7px 9px;min-height:38px}
  .search-trigger{min-width:40px;padding:0 10px}
  .nav-bar{padding:10px 12px 12px;gap:8px}
  .na{padding:7px 12px;font-size:12px}
  main{padding:16px 12px}
  .section-label{margin-bottom:8px;font-size:11px}
  .grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
  .card{min-height:102px;padding:10px 10px 9px;border-radius:14px;gap:8px;align-items:flex-start;text-align:left}
  .card .ci{width:24px;height:24px;font-size:18px;margin-bottom:2px}
  .card .ci img{width:18px;height:18px}
  .card .cn{font-size:12px;line-height:1.28;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;min-height:2.56em;max-width:calc(100% - 20px)}
  .card .cd{display:block;font-size:10px;line-height:1.35;color:var(--tm);display:-webkit-box;-webkit-line-clamp:1;-webkit-box-orient:vertical;overflow:hidden;min-height:1.35em;max-width:100%}
  .group-chip{padding-top:5px;font-size:9px}
  .bx{top:8px;right:8px;font-size:9px;padding:2px 6px;border-radius:999px;opacity:.92}
  .filter-bar{grid-template-columns:repeat(2,minmax(0,1fr))}
  .quick-grid{grid-template-columns:repeat(2,minmax(0,1fr))}
  .card-meta-line{min-height:0}
  #proxy-pending-bar{padding:12px;border-radius:12px}
  #proxy-pending-bar .proxy-pending-text{font-size:12px}
  #proxy-pending-bar .proxy-pending-action{width:100%;justify-content:center;display:inline-flex}
  .tt{display:none!important}
}
@media(max-width:480px){
  .logo{max-width:46vw}
  .search-meta{font-size:11px}
  .topbar-actions .nl{padding:7px 8px}
  .card{min-height:94px}
  .bx{font-size:8px;padding:2px 5px}
}
/* ── 命令面板 ── */
#cmdk-overlay{position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:998;opacity:0;pointer-events:none;transition:opacity .15s}
#cmdk-overlay.open{opacity:1;pointer-events:auto}
#cmdk-panel{position:fixed;top:18%;left:50%;transform:translateX(-50%) scale(.96);width:min(640px,92vw);background:var(--sf);border:1px solid var(--bd);border-radius:14px;box-shadow:0 20px 60px rgba(0,0,0,.5);z-index:999;opacity:0;pointer-events:none;transition:opacity .15s,transform .15s;display:flex;flex-direction:column;max-height:60vh}
#cmdk-panel.open{opacity:1;pointer-events:auto;transform:translateX(-50%) scale(1)}
#cmdk-input{width:100%;background:transparent;border:none;border-bottom:1px solid var(--bd);padding:14px 16px;color:var(--tx);font-size:15px;outline:none}
#cmdk-list{overflow-y:auto;padding:6px}
.cmdk-item{padding:10px 12px;border-radius:10px;cursor:pointer;display:flex;align-items:center;gap:10px;transition:background .1s}
.cmdk-item:hover,.cmdk-item.active{background:rgba(108,99,255,.18)}
.cmdk-item .cmdk-icon{font-size:18px;flex-shrink:0}
.cmdk-item .cmdk-meta{margin-left:auto;font-size:11px;color:var(--tm)}
#cmdk-empty{padding:16px;text-align:center;color:var(--tm);font-size:13px}
</style></head><body>
<header>
  <div class="topbar-main">
    <a class="logo" href="/"><div class="dot"></div><span class="logo-text"><?= htmlspecialchars($site_name) ?></span></a>
    <div class="topbar-actions">
      <?php if($user):?><div class="ub">👤 <?= htmlspecialchars($user['username']) ?></div><?php endif;?>
      <button type="button" class="nl search-trigger" id="searchToggle" aria-expanded="false" aria-controls="searchPanel">🔍</button>
      <?php if($is_admin):?><a href="../admin/" class="nl">⚙</a><?php endif;?>
      <?php if($user):?>
      <form method="POST" action="logout.php" style="display:inline;margin:0">
        <?= csrf_field() ?>
        <button type="submit" class="nl" style="background:none;cursor:pointer">退出</button>
      </form>
      <?php else:?><a href="login.php" class="nl">登录</a><?php endif;?>
    </div>
  </div>
  <div class="topbar-search" id="searchPanel">
    <div class="search-row">
      <input class="sb" id="sq" placeholder="搜索站点、描述、分组…" autocomplete="off" type="search">
      <button type="button" class="nl search-cancel" id="searchClose">取消</button>
    </div>
    <div class="search-meta" id="searchMeta">输入关键词，跨分组搜索站点</div>
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
<div id="proxy-pending-bar">
  <span class="proxy-pending-text">
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
    <button type="submit" class="proxy-pending-action">🔄 生成配置并 Reload Nginx</button>
  </form>
  <?php endif; ?>
</div>
<?php endif; ?>

<section class="quick-section" id="homepage-filters">
  <div class="quick-title">
    <strong>资产筛选</strong>
    <span id="searchMetaInline">默认显示当前分组，可按标签、环境、类型、徽标快速过滤</span>
  </div>
  <div class="filter-bar">
    <div class="filter-cell">
      <label for="filterTag">标签</label>
      <select class="sb" id="filterTag">
        <option value="">全部标签</option>
        <?php foreach (array_keys($tag_options) as $tag): ?>
        <option value="<?= htmlspecialchars(strtolower((string)$tag)) ?>"><?= htmlspecialchars((string)$tag) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="filter-cell">
      <label for="filterEnv">环境</label>
      <select class="sb" id="filterEnv">
        <option value="">全部环境</option>
        <?php foreach (array_keys($env_options) as $env): ?>
        <option value="<?= htmlspecialchars(strtolower((string)$env)) ?>"><?= htmlspecialchars((string)$env) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="filter-cell">
      <label for="filterAssetType">资产类型</label>
      <select class="sb" id="filterAssetType">
        <option value="">全部类型</option>
        <?php foreach (array_keys($asset_type_options) as $assetType): ?>
        <option value="<?= htmlspecialchars(strtolower((string)$assetType)) ?>"><?= htmlspecialchars((string)$assetType) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="filter-cell">
      <label for="filterStatusBadge">徽标</label>
      <select class="sb" id="filterStatusBadge">
        <option value="">全部徽标</option>
        <?php foreach (array_keys($status_badge_options) as $statusBadge): ?>
        <option value="<?= htmlspecialchars(strtolower((string)$statusBadge)) ?>"><?= htmlspecialchars((string)$statusBadge) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="filter-cell">
      <label>&nbsp;</label>
      <button type="button" class="nl" id="onlyFavorites">仅看收藏</button>
    </div>
    <div class="filter-cell">
      <label>&nbsp;</label>
      <button type="button" class="nl" id="onlyPinned">仅看常用</button>
    </div>
  </div>
</section>

<?php if ($favorite_sites !== []): ?>
<section class="quick-section" id="favoritesSection">
  <div class="quick-title"><strong>收藏站点</strong><span><?= count($favorite_sites) ?> 个</span></div>
  <div class="quick-grid">
    <?php foreach ($favorite_sites as $row): ?>
      <?= homepage_render_site_card($row['site'], $row['group'], $row['href'], $token, $health_cache, (bool)$user) ?>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<?php if ($pinned_sites !== []): ?>
<section class="quick-section" id="pinnedSection">
  <div class="quick-title"><strong>常用站点</strong><span><?= count($pinned_sites) ?> 个</span></div>
  <div class="quick-grid">
    <?php foreach ($pinned_sites as $row): ?>
      <?= homepage_render_site_card($row['site'], $row['group'], $row['href'], $token, $health_cache, (bool)$user) ?>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<section class="quick-section hidden" id="recentSection">
  <div class="quick-title"><strong>最近访问</strong><span>基于当前浏览器本地记录</span></div>
  <div class="quick-grid" id="recentGrid"></div>
</section>

<?php $first_grp = true; foreach($visible_groups as $grp):
  $gid=htmlspecialchars((string)$grp['id']);
?>
<div class="sec<?= $first_grp ? ' active' : '' ?>" id="g-<?=$gid?>">
  <div class="section-label">#<?= htmlspecialchars($grp['name']) ?></div>
  <div class="grid">
  <?php foreach($grp['_render_sites'] ?? [] as $row): ?>
    <?= homepage_render_site_card($row['site'], $grp, $row['href'], $token, $health_cache, (bool)$user) ?>
  <?php endforeach;?></div>
</div>
<?php $first_grp = false; endforeach;?></main>
<footer><?php if($user):?><?= htmlspecialchars($user['username']) ?> · 已登录<?php endif;?></footer>
<script>
// ── Tab 切换 ──
var body=document.body;
var searchInput=document.getElementById('sq');
var searchToggle=document.getElementById('searchToggle');
var searchClose=document.getElementById('searchClose');
var searchMeta=document.getElementById('searchMeta');
var searchMetaInline=document.getElementById('searchMetaInline');
var tabs=document.querySelectorAll('.na[data-tab]');
var secs=document.querySelectorAll('.sec');
var filterTag=document.getElementById('filterTag');
var filterEnv=document.getElementById('filterEnv');
var filterAssetType=document.getElementById('filterAssetType');
var filterStatusBadge=document.getElementById('filterStatusBadge');
var onlyFavoritesBtn=document.getElementById('onlyFavorites');
var onlyPinnedBtn=document.getElementById('onlyPinned');
var favoritesSection=document.getElementById('favoritesSection');
var pinnedSection=document.getElementById('pinnedSection');
var recentSection=document.getElementById('recentSection');
var recentGrid=document.getElementById('recentGrid');
var filterState={onlyFavorites:false,onlyPinned:false};

function hasActiveFilters(){
  return !!(
    (searchInput&&searchInput.value.trim())||
    (filterTag&&filterTag.value)||
    (filterEnv&&filterEnv.value)||
    (filterAssetType&&filterAssetType.value)||
    (filterStatusBadge&&filterStatusBadge.value)||
    filterState.onlyFavorites||
    filterState.onlyPinned
  );
}
function showTab(id){
  tabs.forEach(function(t){t.classList.toggle('active',t.dataset.tab===id);});
  secs.forEach(function(s){s.classList.toggle('active',s.id===id);});
  localStorage.setItem('nav_tab',id);
}
function restoreTabView(){
  var cur=localStorage.getItem('nav_tab')||'';
  secs.forEach(function(s){s.classList.toggle('active',!cur||s.id===cur);s.style.display='';});
  secs.forEach(function(s){s.querySelectorAll('.card').forEach(function(c){c.style.display='';});});
  if(searchMeta) searchMeta.textContent='输入关键词，跨分组搜索站点';
  if(searchMetaInline) searchMetaInline.textContent='默认显示当前分组，可按标签、环境、类型、徽标快速过滤';
  [favoritesSection,pinnedSection,recentSection].forEach(function(sec){ if(sec) sec.classList.remove('hidden'); });
}
function openSearch(){
  body.classList.add('search-open');
  if(searchToggle) searchToggle.setAttribute('aria-expanded','true');
  if(searchInput) searchInput.focus();
}
function closeSearch(){
  body.classList.remove('search-open');
  if(searchToggle) searchToggle.setAttribute('aria-expanded','false');
  if(searchInput){ searchInput.value=''; searchInput.blur(); }
  applyFilters();
}
function updateRecentSection(){
  if(!recentGrid||!recentSection) return;
  var raw=localStorage.getItem('nav_recent_sites')||'[]';
  var keys=[];
  try{keys=JSON.parse(raw)||[];}catch(e){keys=[];}
  keys=Array.isArray(keys)?keys:[];
  recentGrid.innerHTML='';
  keys.slice(0,8).forEach(function(key){
    var source=document.querySelector('.sec .card[data-site-key="'+CSS.escape(key)+'"]');
    if(!source) return;
    var clone=source.cloneNode(true);
    recentGrid.appendChild(clone);
  });
  recentSection.classList.toggle('hidden', recentGrid.children.length===0 || hasActiveFilters());
}
function rememberSite(card){
  var key=card&&card.getAttribute('data-site-key');
  if(!key) return;
  var raw=localStorage.getItem('nav_recent_sites')||'[]';
  var list=[];
  try{list=JSON.parse(raw)||[];}catch(e){list=[];}
  if(!Array.isArray(list)) list=[];
  list=list.filter(function(item){return item!==key;});
  list.unshift(key);
  list=list.slice(0,12);
  localStorage.setItem('nav_recent_sites',JSON.stringify(list));
}
function setFilterToggle(btn, active){
  if(!btn) return;
  btn.dataset.active=active?'1':'0';
  btn.style.borderColor=active?'rgba(108,99,255,.48)':'';
  btn.style.background=active?'rgba(108,99,255,.16)':'';
  btn.style.color=active?'var(--tx)':'';
}
function applyFilters(){
  var q=(searchInput&&searchInput.value||'').toLowerCase().trim();
  var tag=(filterTag&&filterTag.value||'').toLowerCase().trim();
  var env=(filterEnv&&filterEnv.value||'').toLowerCase().trim();
  var assetType=(filterAssetType&&filterAssetType.value||'').toLowerCase().trim();
  var statusBadge=(filterStatusBadge&&filterStatusBadge.value||'').toLowerCase().trim();
  var total=0;
  var active=hasActiveFilters();
  if(q){ openSearch(); }
  secs.forEach(function(sec){
    var cards=sec.querySelectorAll('.card');
    var any=false;
    cards.forEach(function(c){
      var matchesQ=!q||(c.dataset.search||'').includes(q);
      var matchesTag=!tag||(c.dataset.tags||'').split(',').filter(Boolean).includes(tag);
      var matchesEnv=!env||(c.dataset.env||'')===env;
      var matchesType=!assetType||(c.dataset.assetType||'')===assetType;
      var matchesStatus=!statusBadge||(c.dataset.statusBadge||'')===statusBadge;
      var matchesFavorite=!filterState.onlyFavorites||c.dataset.favorite==='1';
      var matchesPinned=!filterState.onlyPinned||c.dataset.pinned==='1';
      var show=matchesQ&&matchesTag&&matchesEnv&&matchesType&&matchesStatus&&matchesFavorite&&matchesPinned;
      c.style.display=show?'':'none';
      if(show){ any=true; total++; }
    });
    if(active){
      sec.classList.toggle('active',any);
      sec.style.display=any?'':'none';
    }else{
      sec.style.display='';
    }
  });
  if(active){
    if(searchMeta) searchMeta.textContent=total>0?('找到 '+total+' 个结果'):'没有找到匹配结果';
    if(searchMetaInline) searchMetaInline.textContent=total>0?('筛选后共 '+total+' 个站点'):'当前筛选条件下没有结果';
    [favoritesSection,pinnedSection,recentSection].forEach(function(sec){ if(sec) sec.classList.add('hidden'); });
  }else{
    restoreTabView();
    updateRecentSection();
  }
}
// 恢复上次选中的 Tab
var savedTab=localStorage.getItem('nav_tab');
if(savedTab&&document.getElementById(savedTab))showTab(savedTab);
tabs.forEach(function(t){
  t.addEventListener('click',function(e){e.preventDefault();showTab(this.dataset.tab);});
});
if(searchToggle){ searchToggle.addEventListener('click',function(){ body.classList.contains('search-open') ? closeSearch() : openSearch(); }); }
if(searchClose){ searchClose.addEventListener('click',closeSearch); }
if(filterTag){ filterTag.addEventListener('change',applyFilters); }
if(filterEnv){ filterEnv.addEventListener('change',applyFilters); }
if(filterAssetType){ filterAssetType.addEventListener('change',applyFilters); }
if(filterStatusBadge){ filterStatusBadge.addEventListener('change',applyFilters); }
if(onlyFavoritesBtn){ onlyFavoritesBtn.addEventListener('click',function(){ filterState.onlyFavorites=!filterState.onlyFavorites; setFilterToggle(onlyFavoritesBtn,filterState.onlyFavorites); applyFilters(); }); }
if(onlyPinnedBtn){ onlyPinnedBtn.addEventListener('click',function(){ filterState.onlyPinned=!filterState.onlyPinned; setFilterToggle(onlyPinnedBtn,filterState.onlyPinned); applyFilters(); }); }
document.querySelectorAll('.card[data-site-key]').forEach(function(card){
  card.addEventListener('click',function(){ rememberSite(card); });
});
// ── 搜索 ──
document.addEventListener('keydown',function(e){
  if(e.key==='/'&&document.activeElement.tagName!=='INPUT'){e.preventDefault();openSearch();}
  if(e.key==='Escape')closeSearch();
});
searchInput.addEventListener('input',applyFilters);
updateRecentSection();
applyFilters();

// ── Service Worker 注册 ──
if ('serviceWorker' in navigator) {
  navigator.serviceWorker.register('/sw.js').catch(function(){});
}

// ── 命令面板 (Cmd/Ctrl + K) ──
(function(){
  var cmdkHtml = '<div id="cmdk-overlay"></div><div id="cmdk-panel"><input id="cmdk-input" type="text" placeholder="搜索站点并跳转…" autocomplete="off"><div id="cmdk-list"></div><div id="cmdk-empty" style="display:none">无匹配站点</div></div>';
  var div = document.createElement('div'); div.innerHTML = cmdkHtml; document.body.appendChild(div);
  var overlay = document.getElementById('cmdk-overlay');
  var panel = document.getElementById('cmdk-panel');
  var input = document.getElementById('cmdk-input');
  var list = document.getElementById('cmdk-list');
  var empty = document.getElementById('cmdk-empty');
  var allCards = Array.from(document.querySelectorAll('.card[data-site-key]')).map(function(c){
    return { name: (c.dataset.name||c.querySelector('.cn')?.textContent||'').trim(), href: c.getAttribute('href'), icon: (c.querySelector('.ci')?.textContent||'').trim() };
  });
  function openCmdk(){ overlay.classList.add('open'); panel.classList.add('open'); input.value=''; input.focus(); render(''); }
  function closeCmdk(){ overlay.classList.remove('open'); panel.classList.remove('open'); }
  function render(q){
    q = q.toLowerCase().trim();
    var matched = allCards.filter(function(s){ return !q || s.name.toLowerCase().includes(q); });
    list.innerHTML = '';
    empty.style.display = matched.length ? 'none' : 'block';
    matched.slice(0, 50).forEach(function(s, idx){
      var el = document.createElement('div'); el.className = 'cmdk-item' + (idx===0?' active':'');
      el.innerHTML = '<span class="cmdk-icon">' + (s.icon || '🔗') + '</span><span style="flex:1">' + s.name + '</span><span class="cmdk-meta">↵ 跳转</span>';
      el.addEventListener('click', function(){ window.open(s.href, '_blank'); closeCmdk(); });
      list.appendChild(el);
    });
  }
  overlay.addEventListener('click', closeCmdk);
  input.addEventListener('input', function(){ render(input.value); });
  input.addEventListener('keydown', function(e){
    var items = list.querySelectorAll('.cmdk-item');
    var active = list.querySelector('.cmdk-item.active');
    var idx = active ? Array.prototype.indexOf.call(items, active) : -1;
    if (e.key === 'ArrowDown') { e.preventDefault(); if (items.length) { items[idx]?.classList.remove('active'); items[(idx+1)%items.length].classList.add('active'); } }
    else if (e.key === 'ArrowUp') { e.preventDefault(); if (items.length) { items[idx]?.classList.remove('active'); items[(idx-1+items.length)%items.length].classList.add('active'); } }
    else if (e.key === 'Enter') { e.preventDefault(); if (active) { active.click(); } }
    else if (e.key === 'Escape') { closeCmdk(); }
  });
  document.addEventListener('keydown', function(e){
    if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') { e.preventDefault(); openCmdk(); }
  });
})();
</script></body></html>
