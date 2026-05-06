<?php
declare(strict_types=1);

require_once __DIR__ . '/../../admin/shared/dns_api_lib.php';
require_once __DIR__ . '/../../admin/shared/functions.php';

header('Content-Type: application/json; charset=utf-8');

$isLocalhost = dns_api_is_localhost();

if (!$isLocalhost) {
    // 非本机访问需校验 API Token（与 sites.php 保持一致）
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    $token = '';
    if (str_starts_with($authHeader, 'Bearer ')) {
        $token = substr($authHeader, 7);
    } elseif (!empty($_GET['token'])) {
        $token = $_GET['token'];
    }

    if (!api_token_verify($token)) {
        http_response_code(401);
        echo json_encode(['code' => -1, 'msg' => '无效的 API Token'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($method, ['GET', 'POST'], true)) {
    http_response_code(405);
    echo json_encode(['code' => -1, 'msg' => '仅支持 GET / POST'], JSON_UNESCAPED_UNICODE);
    exit;
}

$input = dns_api_get_merged_input();
$action = trim((string)($input['action'] ?? ''));

try {
    if ($action === 'query') {
        $domain = trim((string)($input['domain'] ?? ''));
        $type = isset($input['type']) ? trim((string)$input['type']) : '';
        $out = dns_api_query($domain, $type !== '' ? $type : null);
        http_response_code(200);
        echo json_encode($out, JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($action === 'update') {
        $domain = trim((string)($input['domain'] ?? ''));
        $value = trim((string)($input['value'] ?? ''));
        $type = isset($input['type']) ? trim((string)$input['type']) : '';
        $ttl = isset($input['ttl']) ? (int)$input['ttl'] : null;
        if ($ttl !== null && $ttl <= 0) {
            $ttl = null;
        }
        $out = dns_api_upsert($domain, $value, $type !== '' ? $type : null, $ttl);
        http_response_code(200);
        echo json_encode($out, JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($action === 'batch_update') {
        @set_time_limit(0);
        $out = dns_api_batch_update($input);
        http_response_code(200);
        echo json_encode($out, JSON_UNESCAPED_UNICODE);
        exit;
    }
    http_response_code(400);
    echo json_encode(['code' => -1, 'msg' => '未知 action，支持 query / update / batch_update'], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    dns_log_write('app', 'error', 'DNS API exception', ['msg' => $e->getMessage()]);
    http_response_code(500);
    echo json_encode(['code' => -1, 'msg' => '内部错误'], JSON_UNESCAPED_UNICODE);
}
