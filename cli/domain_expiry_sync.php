#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../admin/shared/domain_expiry_lib.php';

$args = array_slice($argv, 1);
$force = in_array('--force', $args, true);
$domain = '';
foreach ($args as $arg) {
    if ($arg !== '--force' && $arg !== '') {
        $domain = $arg;
        break;
    }
}

if ($domain !== '') {
    $result = domain_expiry_refresh_domain($domain, $force);
    $row = is_array($result['record'] ?? null) ? $result['record'] : [];
    $line = ($result['ok'] ? 'OK' : 'FAIL') . ' ' . $domain . ' ' . ($result['msg'] ?? '');
    if (($row['expires_at'] ?? '') !== '') {
        $line .= ' expires_at=' . $row['expires_at'];
    }
    fwrite($result['ok'] ? STDOUT : STDERR, trim($line) . PHP_EOL);
    exit($result['ok'] ? 0 : 1);
}

$result = domain_expiry_refresh_due($force, 100);
$failed = false;
foreach (($result['results'] ?? []) as $item) {
    if (!is_array($item)) {
        continue;
    }
    $row = is_array($item['record'] ?? null) ? $item['record'] : [];
    $domainText = (string)($row['domain'] ?? '-');
    $line = ($item['ok'] ? 'OK' : 'FAIL') . ' ' . $domainText . ' ' . ($item['msg'] ?? '');
    if (($row['expires_at'] ?? '') !== '') {
        $line .= ' expires_at=' . $row['expires_at'];
    }
    fwrite($item['ok'] ? STDOUT : STDERR, trim($line) . PHP_EOL);
    if (!$item['ok']) {
        $failed = true;
    }
}

if ((int)($result['checked'] ?? 0) === 0) {
    fwrite(STDOUT, 'No domain expiry refresh needed' . PHP_EOL);
}

exit($failed ? 1 : 0);
