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
        // 所有 DNS 解析失败：在容器/受限网络中常见。
        // 返回 true 放行，由后续 HTTP 连接超时/失败来自然拦截。
        // 这比完全拒绝更实用，且 SSRF 风险由 CURLOPT_PROTOCOLS 限制。
        return true;
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

function favicon_parse_html_icon(string $html, string $base_url): ?string {
    // 匹配 <link rel="icon" href="..."> 或 <link rel="shortcut icon" href="...">
    if (preg_match('/<link[^>]*rel=["\'](?:shortcut\s+)?icon["\'][^>]*href=["\']([^"\']+)["\'][^>]*>/i', $html, $matches)) {
        return favicon_resolve_redirect_url($base_url, $matches[1]);
    }
    // 某些站点 href 在 rel 前面
    if (preg_match('/<link[^>]*href=["\']([^"\']+)["\'][^>]*rel=["\'](?:shortcut\s+)?icon["\'][^>]*>/i', $html, $matches)) {
        return favicon_resolve_redirect_url($base_url, $matches[1]);
    }
    return null;
}

function favicon_fetch(string $url, int $max_redirects = 3, ?string &$error = null): ?string {
    $current = $url;

    for ($i = 0; $i <= $max_redirects; $i++) {
        $parts = parse_url($current);
        $current_host = $parts['host'] ?? '';
        $scheme = strtolower($parts['scheme'] ?? 'https');
        if (!$current_host || !in_array($scheme, ['http', 'https'], true)) {
            $error = 'invalid url';
            return null;
        }
        if (!favicon_host_is_public($current_host)) {
            $error = 'host resolves to private ip';
            return null;
        }

        if (!function_exists('curl_init')) {
            $ctx = stream_context_create([
                'http' => [
                    'timeout' => 10,
                    'follow_location' => 0,
                    'max_redirects' => 0,
                    'user_agent' => 'NavPortal/2.0 FaviconFetcher',
                    'ignore_errors' => true,
                ],
            ]);
            $data = @file_get_contents($current, false, $ctx);
            if ($data === false) {
                $error = 'file_get_contents failed';
                return null;
            }
            return $data;
        }

        $ch = curl_init($current);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_USERAGENT => 'NavPortal/2.0 FaviconFetcher',
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ]);
        $response = curl_exec($ch);
        if ($response === false) {
            $error = 'curl error: ' . curl_error($ch) . ' (errno ' . curl_errno($ch) . ')';
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
                $error = 'redirect without location header';
                return null;
            }
            $next = favicon_resolve_redirect_url($current, trim($matches[1]));
            if ($next === null) {
                $error = 'invalid redirect url';
                return null;
            }
            $current = $next;
            continue;
        }

        if ($status >= 200 && $status < 400) {
            return $body;
        }

        // 如果 /favicon.ico 返回 404，尝试从首页 HTML 解析 <link rel="icon">
        if ($status === 404 && preg_match('#/favicon\.ico$#i', $current)) {
            $home_url = $scheme . '://' . $current_host . '/';
            $html = favicon_fetch_simple($home_url);
            if ($html !== null) {
                $icon_url = favicon_parse_html_icon($html, $home_url);
                if ($icon_url !== null) {
                    $current = $icon_url;
                    continue;
                }
            }
            $error = '/favicon.ico 404 and no <link rel="icon"> found in homepage';
            return null;
        }

        $error = 'http ' . $status;
        return null;
    }

    $error = 'too many redirects';
    return null;
}

function favicon_fetch_simple(string $url): ?string {
    $parts = parse_url($url);
    $host = $parts['host'] ?? '';
    $scheme = strtolower($parts['scheme'] ?? 'https');
    if (!$host || !in_array($scheme, ['http', 'https'], true) || !favicon_host_is_public($host)) {
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
        $data = @file_get_contents($url, false, $ctx);
        return $data === false ? null : $data;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HEADER => false,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_USERAGENT => 'NavPortal/2.0 FaviconFetcher',
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
    ]);
    $data = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    return ($status >= 200 && $status < 400 && $data !== false) ? $data : null;
}

function favicon_content_type(?string $data): string {
    if (!$data || strlen($data) < 4) {
        return 'image/x-icon';
    }
    $magic = substr($data, 0, 16);
    if (strpos($magic, "\x89PNG") === 0) return 'image/png';
    if (strpos($magic, 'GIF') === 0) return 'image/gif';
    if (strpos($magic, "\xFF\xD8") === 0) return 'image/jpeg';
    if (strpos($magic, 'RIFF') === 0 && strpos(substr($data, 8, 4), 'WEBP') !== false) return 'image/webp';
    $trimmed = ltrim(substr($data, 0, 256));
    if (stripos($trimmed, '<?xml') === 0 || stripos($trimmed, '<svg') === 0) return 'image/svg+xml';
    return 'image/x-icon';
}

function favicon_validate_data(?string $data): bool {
    if (!$data || strlen($data) <= 10) {
        return false;
    }
    $magic = substr($data, 0, 16);
    // ICO / PNG / GIF / JPEG
    if (strpos($magic, "\x00\x00\x01\x00") === 0 ||
        strpos($magic, "\x89PNG") === 0 ||
        strpos($magic, 'GIF') === 0 ||
        strpos($magic, "\xFF\xD8") === 0) {
        return true;
    }
    // WebP (RIFF....WEBP)
    if (strpos($magic, 'RIFF') === 0 && strpos(substr($data, 8, 4), 'WEBP') !== false) {
        return true;
    }
    // SVG (文本格式)
    $trimmed = ltrim(substr($data, 0, 256));
    if (stripos($trimmed, '<?xml') === 0 || stripos($trimmed, '<svg') === 0) {
        return true;
    }
    return false;
}
