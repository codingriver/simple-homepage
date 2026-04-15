<?php
require_once __DIR__ . '/../admin/shared/expiry_lib.php';

$notify = in_array('--notify', $argv, true);
$scan = expiry_scan_and_store($notify);

echo 'expiry scan finished: ' . count($scan['rows'] ?? []) . " rows\n";
foreach ($scan['rows'] ?? [] as $row) {
    echo ($row['name'] ?? '-') . ' | domain=' . ($row['domain'] ?? '-') . ' | domain_days=' . (($row['domain_days_left'] ?? null) === null ? '-' : $row['domain_days_left']) . ' | ssl_days=' . (($row['ssl_days_left'] ?? null) === null ? '-' : $row['ssl_days_left']) . "\n";
}
