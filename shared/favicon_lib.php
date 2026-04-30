<?php
/**
 * Favicon 抓取共享库 shared/favicon_lib.php
 * 供 favicon.php（Web）和 cli/favicon_sync.php（CLI）共用
 */

function favicon_host_is_public(string $host): bool {
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    $ips = [];
    if (function_exists('dns_get_record')) {
        $records = @dns_get_record($host, DNS_A + DNS_AAAA);
        foreach ($records ?: [] as $record) {
            if (!empty($record['ip'])) {
                $ips[] = $record['ip'];
            }
            if (!empty($record['ipv6'])) {
                $ips[] = $record['ipv6'];
            }
        }
    }
    if (empty($ips)) {
        $resolved = @gethostbynamel($host);
        if (is_array($resolved)) {
            $ips = array_merge($ips, $resolved);
        }
    }
    if (empty($ips)) {
        return false;
    }
    foreach (array_unique($ips) as $ip) {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }
    }
    return true;
}

function favicon_resolve_redirect_url(string $current, string $location): ?string {
    $location = trim($location);
    if ($location === '') return null;
    if (preg_match('#^https?://#i', $location)) {
        return $location;
    }

    $parts = parse_url($current);
    if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
        return null;
    }

    $base = $parts['scheme'] . '://' . $parts['host'];
    if (!empty($parts['port'])) {
        $base .= ':' . $parts['port'];
    }
    if (strpos($location, '//') === 0) {
        return $parts['scheme'] . ':' . $location;
    }
    if (strpos($location, '/') === 0) {
        return $base . $location;
    }

    $dir = '/';
    if (!empty($parts['path'])) {
        $dir = rtrim(str_replace('\\', '/', dirname($parts['path'])), '/');
        $dir = ($dir === '' || $dir === '.') ? '' : $dir;
    }
    return $base . $dir . '/' . $location;
}

function favicon_fetch(string $url, int $max_redirects = 3): ?string {
    $current = $url;

    for ($i = 0; $i <= $max_redirects; $i++) {
        $parts = parse_url($current);
        $current_host = $parts['host'] ?? '';
        $scheme = strtolower($parts['scheme'] ?? 'https');
        if (!$current_host || !in_array($scheme, ['http', 'https'], true) || !favicon_host_is_public($current_host)) {
            return null;
        }

        if (!function_exists('curl_init')) {
            $ctx = stream_context_create([
                'http' => [
                    'timeout' => 3,
                    'follow_location' => 0,
                    'max_redirects' => 0,
                    'user_agent' => 'NavPortal/2.0 FaviconFetcher',
                    'ignore_errors' => true,
                ],
            ]);
            $data = @file_get_contents($current, false, $ctx);
            return $data === false ? null : $data;
        }

        $ch = curl_init($current);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => 3,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_USERAGENT => 'NavPortal/2.0 FaviconFetcher',
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ]);
        $response = curl_exec($ch);
        if ($response === false) {
            curl_close($ch);
            return null;
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $header_size = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $headers = substr($response, 0, $header_size);
        $body = substr($response, $header_size);

        if ($status >= 300 && $status < 400) {
            if (!preg_match('/^Location:\s*(.+)$/mi', $headers, $matches)) {
                return null;
            }
            $next = favicon_resolve_redirect_url($current, trim($matches[1]));
            if ($next === null) {
                return null;
            }
            $current = $next;
            continue;
        }

        return ($status >= 200 && $status < 400) ? $body : null;
    }

    return null;
}

function favicon_validate_data(?string $data): bool {
    if (!$data || strlen($data) <= 10) {
        return false;
    }
    $magic = substr($data, 0, 8);
    if (strpos($magic, "\x00\x00\x01\x00") === 0 ||
        strpos($magic, "\x89PNG") === 0 ||
        strpos($magic, 'GIF') === 0 ||
        strpos($magic, "\xFF\xD8") === 0) {
        return true;
    }
    return false;
}
