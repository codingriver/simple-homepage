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
require_once __DIR__ . '/../shared/request_timing.php';
require_once __DIR__ . '/../shared/favicon_lib.php';

auth_check_setup();

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
        header('Content-Type: ' . favicon_content_type($data));
        header('Cache-Control: public, max-age=604800');
        echo $data;
        exit;
    }
}

// 抓取远程 Favicon
$error = '';
$data = favicon_fetch($favicon_url, 3, $error);

// 校验返回内容是否为图片（防止缓存 HTML 错误页）
$valid = favicon_validate_data($data);

if ($valid) {
    // 保存缓存
    if (!is_dir($cache_dir)) mkdir($cache_dir, 0755, true);
    file_put_contents($cache_file, $data, LOCK_EX);
    header('Content-Type: ' . favicon_content_type($data));
    header('Cache-Control: public, max-age=604800');
    echo $data;
} else {
    // 返回 404，触发前端 img onerror 降级显示 Emoji
    http_response_code(404);
}
