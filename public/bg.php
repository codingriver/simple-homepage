<?php
/**
 * 背景图访问入口
 * 从 data/bg 安全读取已上传背景图，避免直接暴露 data 目录。
 */
require_once __DIR__ . '/../shared/auth.php';

auth_check_setup();

$file = basename((string) ($_GET['file'] ?? ''));
if ($file === '' || !preg_match('/^[a-zA-Z0-9._-]+$/', $file)) {
    http_response_code(404);
    exit('Not Found');
}

$path = DATA_DIR . '/bg/' . $file;
if (!is_file($path)) {
    http_response_code(404);
    exit('Not Found');
}

$mime = function_exists('mime_content_type') ? mime_content_type($path) : false;
$allowed = [
    'image/jpeg',
    'image/png',
    'image/gif',
    'image/webp',
];
if (!$mime || !in_array($mime, $allowed, true)) {
    http_response_code(404);
    exit('Not Found');
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) filesize($path));
header('Cache-Control: public, max-age=604800');
readfile($path);
