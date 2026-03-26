<?php
/**
 * 站点健康检测接口 admin/health_check.php
 * GET  ?ajax=status          — 返回所有站点当前缓存的健康状态
 * POST action=check_all      — 立即检测所有站点并更新缓存
 * POST action=check_one&url= — 检测单个 URL
 */
require_once __DIR__ . '/shared/functions.php';

$current_admin = auth_get_current_user();
if (!$current_admin || ($current_admin['role'] ?? '') !== 'admin') {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'msg' => '未登录或无权限']); exit;
}

header('Content-Type: application/json; charset=utf-8');

// ── GET：返回缓存状态 ──
if (isset($_GET['ajax']) && $_GET['ajax'] === 'status') {
    echo json_encode(['ok' => true, 'data' => health_load_cache()]); exit;
}

// ── POST ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'check_all') {
        $result = health_check_all();
        echo json_encode(['ok' => true, 'data' => $result, 'checked' => count($result)]); exit;
    }

    if ($action === 'check_one') {
        if (!auth_is_ip_access()) {
            echo json_encode([
                'ok' => false,
                'msg' => '任意 URL 单点探测仅允许在 IP 访问模式下使用，避免域名模式被滥用',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $url = trim($_POST['url'] ?? '');
        if (!$url) { echo json_encode(['ok' => false, 'msg' => '缺少 url']); exit; }
        $status = health_check_url($url);
        echo json_encode(['ok' => true, 'status' => $status]); exit;
    }
}

echo json_encode(['ok' => false, 'msg' => '无效请求']);
