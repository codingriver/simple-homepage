#!/usr/bin/env php
<?php
/**
 * cli/favicon_sync.php — 站点图标预抓取
 * 遍历 sites.json 中所有站点的域名，预抓取 favicon 到本地缓存。
 * 支持 --domain 参数指定只抓取单个域名。
 */
declare(strict_types=1);
require_once __DIR__ . '/../shared/auth.php';
require_once __DIR__ . '/../shared/favicon_lib.php';

$domains = [];
$singleDomain = null;

// 解析 CLI 参数
for ($i = 1; $i < $argc; $i++) {
    if ($argv[$i] === '--domain' && isset($argv[$i + 1])) {
        $singleDomain = strtolower(trim($argv[$i + 1]));
        $i++;
    }
}

$cache_dir = '/var/www/nav/data/favicon_cache';
if (!is_dir($cache_dir)) {
    @mkdir($cache_dir, 0755, true);
}

if ($singleDomain !== null) {
    $domains = [$singleDomain];
} else {
    $sites_file = '/var/www/nav/data/sites.json';
    $sites = json_decode(file_exists($sites_file) ? file_get_contents($sites_file) : '{"groups":[]}', true) ?? ['groups' => []];

    $seen = [];
    foreach ($sites['groups'] ?? [] as $group) {
        foreach ($group['sites'] ?? [] as $site) {
            $url = $site['url'] ?? ($site['proxy_target'] ?? '');
            $domain = parse_url($url, PHP_URL_HOST) ?? '';
            if ($domain !== '' && !is_private_ip($domain) && !isset($seen[$domain])) {
                $seen[$domain] = true;
                $domains[] = $domain;
            }
        }
    }
}

$total = count($domains);
$cached = 0;
$cachedList = [];
$expired = 0;
$todo = [];

// 预扫描：统计缓存状态
foreach ($domains as $domain) {
    $cache_file = $cache_dir . '/' . md5($domain) . '.ico';
    if (file_exists($cache_file)) {
        $mtime = filemtime($cache_file);
        $size = filesize($cache_file);
        $ageDays = (int) ((time() - $mtime) / 86400);
        $format = 'unknown';
        $data = @file_get_contents($cache_file, false, null, 0, 16);
        if ($data !== false) {
            if (strpos($data, "\x89PNG") === 0) $format = 'png';
            elseif (strpos($data, 'GIF') === 0) $format = 'gif';
            elseif (strpos($data, "\xFF\xD8") === 0) $format = 'jpeg';
            elseif (strpos($data, 'RIFF') === 0 && strpos(substr($data, 8, 4), 'WEBP') !== false) $format = 'webp';
            elseif (stripos(ltrim(substr($data, 0, 64)), '<?xml') === 0 || stripos(ltrim(substr($data, 0, 64)), '<svg') === 0) $format = 'svg';
            elseif (strpos($data, "\x00\x00\x01\x00") === 0) $format = 'ico';
        }
        if ((time() - $mtime) < 7 * 86400) {
            $cached++;
            $cachedList[] = [
                'domain' => $domain,
                'file' => $cache_file,
                'size' => $size,
                'format' => $format,
                'age_days' => $ageDays,
            ];
        } else {
            $expired++;
            $todo[] = $domain;
        }
    } else {
        $todo[] = $domain;
    }
}

$ts = function (): string {
    return date('Y-m-d H:i:s');
};

// ===== 执行前总览 =====
if ($singleDomain !== null) {
    echo "[{$ts()}] === Favicon Sync Start (single: {$singleDomain}) ===\n";
} else {
    echo "[{$ts()}] === Favicon Sync Start ===\n";
    echo "总站点: {$total} | 已缓存: {$cached} | 过期: {$expired} | 待抓取: " . count($todo) . "\n";
    if ($cachedList !== []) {
        echo "已缓存详情:\n";
        foreach ($cachedList as $item) {
            $sizeStr = $item['size'] < 1024 ? $item['size'] . ' B' : round($item['size'] / 1024, 1) . ' KB';
            echo "  ✓ {$item['domain']} | {$item['format']} | {$sizeStr} | {$item['age_days']}天前 | " . basename($item['file']) . "\n";
        }
    }
    if ($todo !== []) {
        echo "待抓取域名: " . implode(', ', $todo) . "\n";
    } else {
        echo "待抓取域名: (无，全部已缓存且有效)\n";
    }
}
echo "---\n";

$ok = 0;
$fail = 0;
$okDomains = [];
$failDomains = [];

foreach ($todo as $domain) {
    $cache_file = $cache_dir . '/' . md5($domain) . '.ico';
    $favicon_url = 'https://' . $domain . '/favicon.ico';
    $error = '';
    $data = favicon_fetch($favicon_url, 3, $error);

    if (favicon_validate_data($data)) {
        file_put_contents($cache_file, $data, LOCK_EX);
        $ok++;
        $okDomains[] = $domain;
        echo "[{$ts()}] OK: {$domain}\n";
    } else {
        $fail++;
        $failDomains[] = $domain;
        $errDetail = $error !== '' ? " ({$error})" : '';
        echo "[{$ts()}] FAIL: {$domain}{$errDetail}\n";
    }
}

// ===== 执行后总览 =====
echo "---\n";
if ($singleDomain !== null) {
    $status = $ok > 0 ? '成功' : ($fail > 0 ? '失败' : '跳过(缓存有效)');
    echo "[{$ts()}] 结果: {$status}\n";
} else {
    echo "[{$ts()}] 结果: 成功 {$ok} | 失败 {$fail} | 跳过 {$cached} | 总计 {$total}\n";
    if ($okDomains !== []) {
        echo "成功域名详情:\n";
        foreach ($okDomains as $domain) {
            $file = $cache_dir . '/' . md5($domain) . '.ico';
            $size = file_exists($file) ? filesize($file) : 0;
            $sizeStr = $size < 1024 ? $size . ' B' : round($size / 1024, 1) . ' KB';
            $data = @file_get_contents($file, false, null, 0, 16);
            $format = 'unknown';
            if ($data !== false) {
                if (strpos($data, "\x89PNG") === 0) $format = 'png';
                elseif (strpos($data, 'GIF') === 0) $format = 'gif';
                elseif (strpos($data, "\xFF\xD8") === 0) $format = 'jpeg';
                elseif (strpos($data, 'RIFF') === 0 && strpos(substr($data, 8, 4), 'WEBP') !== false) $format = 'webp';
                elseif (stripos(ltrim(substr($data, 0, 64)), '<?xml') === 0 || stripos(ltrim(substr($data, 0, 64)), '<svg') === 0) $format = 'svg';
                elseif (strpos($data, "\x00\x00\x01\x00") === 0) $format = 'ico';
            }
            echo "  ✓ {$domain} | {$format} | {$sizeStr} | " . basename($file) . "\n";
        }
    }
    if ($failDomains !== []) {
        echo "失败域名: " . implode(', ', $failDomains) . "\n";
    }
}
echo "[{$ts()}] === Favicon Sync End ===\n";

exit(0);
