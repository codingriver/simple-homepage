<?php
declare(strict_types=1);

require_once __DIR__ . '/../../admin/shared/dns_api_lib.php';

header('Content-Type: application/json; charset=utf-8');

if (!dns_api_is_localhost()) {
    http_response_code(403);
    echo json_encode(['code' => -1, 'msg' => '仅允许本机 127.0.0.1 / ::1 访问'], JSON_UNESCAPED_UNICODE);
    exit;
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
