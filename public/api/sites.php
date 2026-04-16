<?php
declare(strict_types=1);

require_once __DIR__ . '/../../admin/shared/functions.php';

header('Content-Type: application/json; charset=utf-8');

$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$token = '';
if (str_starts_with($authHeader, 'Bearer ')) {
    $token = substr($authHeader, 7);
} elseif (!empty($_GET['token'])) {
    $token = $_GET['token'];
}

if (!api_token_verify($token)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'msg' => '无效的 API Token'], JSON_UNESCAPED_UNICODE);
    exit;
}

$sites_data = load_sites();
$cfg = auth_get_config();

// 返回公开站点数据（不过滤权限，API 消费端自行处理）
$response = [
    'ok' => true,
    'site_name' => $cfg['site_name'] ?? '导航中心',
    'groups' => $sites_data['groups'] ?? [],
];

echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
