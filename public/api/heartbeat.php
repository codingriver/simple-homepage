<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/auth.php';

header('Content-Type: application/json; charset=utf-8');

$user = auth_get_current_user();
if (!$user) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'msg' => '未登录'], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode([
    'ok'       => true,
    'username' => $user['username'] ?? '',
    'time'     => date('Y-m-d H:i:s'),
], JSON_UNESCAPED_UNICODE);
