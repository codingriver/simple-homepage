<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/notify_lib.php';

const EXPIRY_SCAN_FILE = DATA_DIR . '/expiry_scan.json';

function expiry_default_data(): array {
    return ['version' => 1, 'last_scan_at' => '', 'rows' => []];
}

function expiry_load_scan(): array {
    if (!file_exists(EXPIRY_SCAN_FILE)) {
        return expiry_default_data();
    }
    $raw = json_decode((string)@file_get_contents(EXPIRY_SCAN_FILE), true);
    if (!is_array($raw) || !is_array($raw['rows'] ?? null)) {
        return expiry_default_data();
    }
    return $raw + ['version' => 1];
}

function expiry_save_scan(array $data): void {
    @file_put_contents(
        EXPIRY_SCAN_FILE,
        json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );
}

function expiry_days_left(?string $date): ?int {
    $date = trim((string)$date);
    if ($date === '') {
        return null;
    }
    $ts = strtotime($date . ' 00:00:00');
    if ($ts === false) {
        return null;
    }
    return (int)floor(($ts - strtotime(date('Y-m-d 00:00:00'))) / 86400);
}

function expiry_probe_ssl_expire_at(string $url): ?string {
    static $cache = [];
    $parts = parse_url($url);
    if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https') {
        return null;
    }
    $host = trim((string)($parts['host'] ?? ''));
    if ($host === '') {
        return null;
    }
    $port = (int)($parts['port'] ?? 443);
    $cacheKey = strtolower($host) . ':' . $port;
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }
    if (filter_var($host, FILTER_VALIDATE_IP) && is_private_ip($host)) {
        $cache[$cacheKey] = null;
        return null;
    }
    $ctx = stream_context_create([
        'ssl' => [
            'capture_peer_cert' => true,
            'verify_peer' => false,
            'verify_peer_name' => false,
        ],
    ]);
    $client = @stream_socket_client(
        'ssl://' . $host . ':' . $port,
        $errno,
        $errstr,
        2,
        STREAM_CLIENT_CONNECT,
        $ctx
    );
    if (!$client) {
        $cache[$cacheKey] = null;
        return null;
    }
    $params = stream_context_get_params($client);
    fclose($client);
    $cert = $params['options']['ssl']['peer_certificate'] ?? null;
    if (!$cert) {
        $cache[$cacheKey] = null;
        return null;
    }
    $parsed = @openssl_x509_parse($cert);
    if (!is_array($parsed) || empty($parsed['validTo_time_t'])) {
        $cache[$cacheKey] = null;
        return null;
    }
    $cache[$cacheKey] = date('Y-m-d', (int)$parsed['validTo_time_t']);
    return $cache[$cacheKey];
}

function expiry_notice_levels(?int $daysLeft): array {
    if ($daysLeft === null) {
        return [];
    }
    if ($daysLeft < 0) {
        return ['overdue'];
    }
    $levels = [];
    foreach ([30, 7, 1] as $day) {
        if ($daysLeft <= $day) {
            $levels[] = (string)$day;
        }
    }
    return $levels;
}

function expiry_site_rows(): array {
    $sites = load_sites()['groups'] ?? [];
    $rows = [];
    foreach ($sites as $group) {
        foreach ($group['sites'] ?? [] as $site) {
            if (!is_array($site)) {
                continue;
            }
            $targetUrl = (string)($site['url'] ?? '');
            $domain = trim((string)($site['proxy_domain'] ?? ''));
            if ($domain === '' && $targetUrl !== '') {
                $parsedHost = parse_url($targetUrl, PHP_URL_HOST);
                if (is_string($parsedHost)) {
                    $domain = $parsedHost;
                }
            }
            $manualDomainExpireAt = trim((string)($site['domain_expire_at'] ?? ''));
            $manualSslExpireAt = trim((string)($site['ssl_expire_at'] ?? ''));
            $autoSslExpireAt = $manualSslExpireAt !== '' ? $manualSslExpireAt : expiry_probe_ssl_expire_at($targetUrl);
            $rows[] = [
                'site_id' => (string)($site['id'] ?? ''),
                'group_id' => (string)($group['id'] ?? ''),
                'group_name' => (string)($group['name'] ?? ''),
                'name' => (string)($site['name'] ?? ''),
                'domain' => $domain,
                'renew_url' => (string)($site['renew_url'] ?? ''),
                'domain_expire_at' => $manualDomainExpireAt,
                'domain_days_left' => expiry_days_left($manualDomainExpireAt),
                'ssl_expire_at' => $autoSslExpireAt ?: '',
                'ssl_days_left' => expiry_days_left($autoSslExpireAt ?: ''),
            ];
        }
    }
    return $rows;
}

function expiry_scan_and_store(bool $dispatchNotifications = false): array {
    $rows = expiry_site_rows();
    $scan = [
        'version' => 1,
        'last_scan_at' => date('Y-m-d H:i:s'),
        'rows' => $rows,
    ];
    expiry_save_scan($scan);

    if ($dispatchNotifications) {
        $domainExpiring = [];
        $sslExpiring = [];
        foreach ($rows as $row) {
            $domainLevels = expiry_notice_levels($row['domain_days_left']);
            if ($row['domain'] !== '' && $domainLevels !== []) {
                $domainExpiring[] = sprintf(
                    '%s(%s, %s天, %s)',
                    (string)$row['name'],
                    (string)$row['domain'],
                    (string)$row['domain_days_left'],
                    (string)$row['domain_expire_at']
                );
            }
            $sslLevels = expiry_notice_levels($row['ssl_days_left']);
            if ($row['ssl_expire_at'] !== '' && $sslLevels !== []) {
                $sslExpiring[] = sprintf(
                    '%s(%s, %s天, %s)',
                    (string)$row['name'],
                    (string)$row['domain'],
                    (string)$row['ssl_days_left'],
                    (string)$row['ssl_expire_at']
                );
            }
        }
        if ($domainExpiring !== []) {
            notify_event('domain_expiring', [
                'count' => (string)count($domainExpiring),
                'items' => implode('；', $domainExpiring),
            ]);
        }
        if ($sslExpiring !== []) {
            notify_event('ssl_expiring', [
                'count' => (string)count($sslExpiring),
                'items' => implode('；', $sslExpiring),
            ]);
        }
    }

    return $scan;
}
