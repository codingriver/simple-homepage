#!/usr/local/bin/php
<?php
declare(strict_types=1);
require_once __DIR__ . '/../admin/shared/alidns.php';

$cfg = load_dns_config();
$ak  = (string)($cfg['access_key_id'] ?? '');
$sk  = (string)($cfg['access_key_secret'] ?? '');
$dom = trim((string)($cfg['domain_name'] ?? ''));

if ($ak === '' || $sk === '' || $dom === '') {
    fwrite(STDERR, "alidns_sync: 缺少 AccessKey 或域名配置\n");
    exit(1);
}

$client = new AliyunDnsClient($ak, $sk);
$res    = $client->describeDomainRecords($dom, 1, 20);
if (!$res['ok']) {
    fwrite(STDERR, "alidns_sync: " . $res['msg'] . "\n");
    exit(1);
}

$cfg['last_sync_at'] = date('Y-m-d H:i:s');
save_dns_config($cfg);

$n = count($res['records']);
echo "alidns_sync OK domain={$dom} records={$n}\n";
exit(0);
