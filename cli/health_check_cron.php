<?php
/**
 * 自动健康检测 CLI
 * 用法: php cli/health_check_cron.php [--force]
 * --force: 忽略 health_auto_enabled 开关强制运行
 *
 * 逻辑:
 * 1. 检测所有站点并更新缓存
 * 2. 对状态为 down 的站点，若未发送过告警则触发 Webhook
 * 3. 状态恢复为 up 的站点，清除告警记录
 */
require_once __DIR__ . '/../admin/shared/functions.php';

$force = in_array('--force', $argv, true);
$cfg = load_config();

if (($cfg['health_auto_enabled'] ?? '0') !== '1' && !$force) {
    echo "auto health check is disabled. use --force to override.\n";
    exit(0);
}

$cache = health_load_cache();
$alerts = health_alert_load();

// 执行检测
$results = health_check_all();

// 获取站点元数据用于告警名称映射
$sitesMeta = [];
foreach (load_sites()['groups'] ?? [] as $g) {
    foreach ($g['sites'] ?? [] as $s) {
        $url = ($s['type'] ?? '') === 'proxy' ? ($s['proxy_target'] ?? '') : ($s['url'] ?? '');
        if ($url) {
            $sitesMeta[$url] = $s['name'] ?? $s['id'] ?? $url;
        }
    }
}

$newAlerts = [];
$notified = 0;
$cleared = 0;

foreach ($results as $url => $result) {
    $status = $result['status'] ?? 'unknown';
    if ($status === 'down') {
        $newAlerts[$url] = $result['checked_at'] ?? time();
        if (!isset($alerts[$url])) {
            // 首次告警
            webhook_send_health_alert(
                $sitesMeta[$url] ?? $url,
                $url,
                (int)($result['code'] ?? 0),
                (int)($result['ms'] ?? 0)
            );
            $notified++;
        }
    } elseif ($status === 'up' && isset($alerts[$url])) {
        $cleared++;
    }
}

health_alert_save($newAlerts);

$downCount = count($newAlerts);
echo "health check finished. total checked: " . count($results) . ", down: {$downCount}, notified: {$notified}, cleared: {$cleared}\n";
exit(0);
