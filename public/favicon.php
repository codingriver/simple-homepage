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

$url = trim($_GET['url'] ?? '');
if (!$url) {
    http_response_code(400);
    exit('Missing url parameter');
}

// 解析并校验目标 URL
$parsed = parse_url($url);
$host   = $parsed['host'] ?? '';

// 禁止请求内网 IP（防 SSRF）
if (!$host || is_private_ip($host)) {
    http_response_code(403);
    exit('Forbidden: private IP not allowed');
}

// 校验域名格式
if (!filter_var('http://' . $host, FILTER_VALIDATE_URL)) {
    http_response_code(400);
    exit('Invalid host');
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
$ctx = stream_context_create(['http' => [
    'timeout'         => 3, // 3秒超时
    'follow_location' => 1,
    'max_redirects'   => 3,
    'user_agent'      => 'NavPortal/2.0 FaviconFetcher',
    'ignore_errors'   => true,
]]);

$data = @file_get_contents($favicon_url, false, $ctx);

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
