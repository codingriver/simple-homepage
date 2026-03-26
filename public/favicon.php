<?php
/**
 * Favicon 抓取代理接口 favicon.php
 *
 * 前端通过此接口异步抓取站点 Favicon，避免跨域和 SSRF 问题。
 * 结果缓存到 data/favicon_cache/ 目录，缓存有效期 7 天。
 *
 * 安全措施：
 * 1. 目标必须是合法域名（非内网IP）
 * 2. 请求超时 3 秒
 * 3. 只返回图片类型内容
 * 4. 需要登录才能调用
 */
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../shared/auth.php';

auth_check_setup();

// 需要登录
if (!auth_get_current_user()) {
    http_response_code(401);
    exit('Unauthorized');
}

$url = trim((string)($_GET['url'] ?? ''));
if ($url === '') {
    http_response_code(400);
    exit('Missing url parameter');
}

$parsed = parse_url($url);
$scheme = strtolower((string)($parsed['scheme'] ?? ''));
$host   = strtolower(trim((string)($parsed['host'] ?? '')));

if (!$parsed || !in_array($scheme, ['http', 'https'], true) || $host === '') {
    http_response_code(400);
    exit('Invalid url');
}

if (in_array($host, ['localhost', 'localhost.localdomain'], true) ||
    preg_match('/\.(local|lan|internal|intranet|corp)$/', $host)) {
    http_response_code(403);
    exit('Forbidden host');
}

if (filter_var($host, FILTER_VALIDATE_IP) && !is_public_ip($host)) {
    http_response_code(403);
    exit('Forbidden: private IP not allowed');
}

if (!filter_var('http://' . $host, FILTER_VALIDATE_URL)) {
    http_response_code(400);
    exit('Invalid host');
}

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

if (!favicon_host_is_public($host)) {
    http_response_code(403);
    exit('Forbidden: host resolves to private IP');
}

// 构造 Favicon URL：优先尝试 /favicon.ico
$scheme    = in_array($parsed['scheme'] ?? 'https', ['http', 'https']) ? $parsed['scheme'] : 'https';
$favicon_url = $scheme . '://' . $host . '/favicon.ico';

// 缓存文件名（MD5 哈希，避免路径遍历）
$cache_dir  = DATA_DIR . '/favicon_cache';
$cache_file = $cache_dir . '/' . md5($host) . '.ico';
$cache_ttl  = 7 * 86400; // 7天

// 如果缓存存在且未过期，直接返回
if (file_exists($cache_file) && (time() - filemtime($cache_file)) < $cache_ttl) {
    $data = file_get_contents($cache_file);
    if ($data && strlen($data) > 0) {
        header('Content-Type: image/x-icon');
        header('Cache-Control: public, max-age=604800');
        echo $data;
        exit;
    }
}

// 抓取远程 Favicon
$data = favicon_fetch($favicon_url, 3);

// 校验返回内容是否为图片（防止缓存 HTML 错误页）
$valid = false;
if ($data && strlen($data) > 10) {
    // 检查文件头魔数
    $magic = substr($data, 0, 8);
    // ICO: 00 00 01 00 / PNG: 89 50 4E 47 / GIF: 47 49 46 / JPEG: FF D8
    if (strpos($magic, "\x00\x00\x01\x00") === 0 ||
        strpos($magic, "\x89PNG") === 0 ||
        strpos($magic, 'GIF') === 0 ||
        strpos($magic, "\xFF\xD8") === 0) {
        $valid = true;
    }
}

if ($valid) {
    // 保存缓存
    if (!is_dir($cache_dir)) mkdir($cache_dir, 0755, true);
    file_put_contents($cache_file, $data, LOCK_EX);
    header('Content-Type: image/x-icon');
    header('Cache-Control: public, max-age=604800');
    echo $data;
} else {
    // 返回 204 No Content，前端降级显示 Emoji
    http_response_code(204);
}
