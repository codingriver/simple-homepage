<?php
declare(strict_types=1);

require_once __DIR__ . '/shared/functions.php';
require_once __DIR__ . '/shared/domain_expiry_lib.php';

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'XMLHttpRequest') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'msg' => '需要 AJAX 请求'], JSON_UNESCAPED_UNICODE);
    exit;
}

$user = auth_get_current_user();
if (!$user || ($user['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'msg' => '无权限'], JSON_UNESCAPED_UNICODE);
    exit;
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$input = $method === 'POST'
    ? (json_decode((string)file_get_contents('php://input'), true) ?: $_POST)
    : $_GET;
$action = trim((string)($input['action'] ?? ''));

auth_start_php_session();
$domain_expiry_ajax_csrf_token = (string)($_SESSION['csrf_token'] ?? '');
session_write_close();

function domain_expiry_ajax_response(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function domain_expiry_ajax_require_csrf(array $input): void {
    global $domain_expiry_ajax_csrf_token;
    $token = (string)($input['_csrf'] ?? '');
    if ($token === '' || $domain_expiry_ajax_csrf_token === '' || !hash_equals($domain_expiry_ajax_csrf_token, $token)) {
        domain_expiry_ajax_response(['ok' => false, 'msg' => 'CSRF验证失败，请刷新重试'], 403);
    }
}

if ($action === 'list') {
    domain_expiry_ajax_response([
        'ok' => true,
        'data' => [
            'rows' => domain_expiry_rows(!empty($input['include_ignored'])),
            'summary' => domain_expiry_summary(),
        ],
    ]);
}

if ($action === 'platform_configs') {
    domain_expiry_ajax_response([
        'ok' => true,
        'data' => [
            'providers' => array_values(domain_expiry_platform_catalog()),
            'configs' => domain_expiry_platform_configs_public(),
        ],
    ]);
}

if (in_array($action, ['refresh_one', 'refresh_due', 'manual_add', 'manual_delete', 'ignore', 'unignore', 'platform_configs_save', 'platform_config_test'], true)) {
    domain_expiry_ajax_require_csrf($input);
}

if ($action === 'refresh_one') {
    @set_time_limit(0);
    $domain = trim((string)($input['domain'] ?? ''));
    $result = domain_expiry_refresh_domain($domain, !empty($input['force']));
    domain_expiry_ajax_response([
        'ok' => $result['ok'],
        'msg' => $result['msg'] ?? ($result['ok'] ? '刷新完成' : '刷新失败'),
        'data' => ['row' => $result['record'] ?? null, 'summary' => domain_expiry_summary()],
    ], $result['ok'] ? 200 : 400);
}

if ($action === 'refresh_due') {
    @set_time_limit(0);
    $force = !empty($input['force']);
    $limit = max(1, min(100, (int)($input['limit'] ?? 50)));
    $result = domain_expiry_refresh_due($force, $limit);
    domain_expiry_ajax_response([
        'ok' => true,
        'msg' => '刷新完成：检查 ' . (int)$result['checked'] . ' 个域名',
        'data' => [
            'checked' => (int)$result['checked'],
            'results' => $result['results'],
            'rows' => domain_expiry_rows(),
            'summary' => domain_expiry_summary(),
        ],
    ]);
}

if ($action === 'manual_add') {
    $domain = trim((string)($input['domain'] ?? ''));
    $result = domain_expiry_add_manual($domain);
    if (!$result['ok']) {
        domain_expiry_ajax_response(['ok' => false, 'msg' => $result['msg'] ?? '添加失败'], 400);
    }
    require_once __DIR__ . '/shared/cron_lib.php';
    cron_regenerate();
    domain_expiry_ajax_response([
        'ok' => true,
        'msg' => $result['msg'] ?? '域名已添加',
        'data' => ['rows' => domain_expiry_rows(), 'summary' => domain_expiry_summary()],
    ]);
}

if ($action === 'manual_delete') {
    $domain = trim((string)($input['domain'] ?? ''));
    $result = domain_expiry_remove_manual($domain);
    require_once __DIR__ . '/shared/cron_lib.php';
    cron_regenerate();
    domain_expiry_ajax_response([
        'ok' => true,
        'msg' => $result['ok'] ? '域名已移除' : '没有找到手动域名',
        'data' => ['rows' => domain_expiry_rows(), 'summary' => domain_expiry_summary()],
    ]);
}

if ($action === 'ignore' || $action === 'unignore') {
    $domain = trim((string)($input['domain'] ?? ''));
    $result = domain_expiry_set_ignored($domain, $action === 'ignore');
    if (!$result['ok']) {
        domain_expiry_ajax_response(['ok' => false, 'msg' => $result['msg'] ?? '操作失败'], 400);
    }
    domain_expiry_ajax_response([
        'ok' => true,
        'msg' => $result['msg'] ?? '操作完成',
        'data' => ['rows' => domain_expiry_rows(!empty($input['include_ignored'])), 'summary' => domain_expiry_summary()],
    ]);
}

if ($action === 'platform_configs_save') {
    $configs = is_array($input['configs'] ?? null) ? $input['configs'] : [];
    $result = domain_expiry_save_platform_configs($configs);
    domain_expiry_ajax_response([
        'ok' => true,
        'msg' => '官方平台秘钥已保存',
        'data' => [
            'providers' => array_values(domain_expiry_platform_catalog()),
            'configs' => $result['configs'],
        ],
    ]);
}

if ($action === 'platform_config_test') {
    $provider = strtolower(trim((string)($input['provider'] ?? '')));
    $catalog = domain_expiry_platform_catalog();
    if (!isset($catalog[$provider])) {
        domain_expiry_ajax_response(['ok' => false, 'msg' => '未知官方平台'], 400);
    }
    $config = is_array($input['config'] ?? null) ? $input['config'] : [];
    $saved = domain_expiry_platform_config($provider) ?? [];
    foreach ($catalog[$provider]['fields'] as $field) {
        if (trim((string)($config[$field] ?? '')) === '' && trim((string)($saved[$field] ?? '')) !== '') {
            $config[$field] = (string)$saved[$field];
        }
    }
    $result = domain_expiry_test_platform_config($provider, $config);
    domain_expiry_ajax_response([
        'ok' => !empty($result['ok']),
        'msg' => (string)($result['msg'] ?? (!empty($result['ok']) ? '测试成功' : '测试失败')),
        'data' => ['count' => (int)($result['count'] ?? 0)],
    ], !empty($result['ok']) ? 200 : 400);
}

domain_expiry_ajax_response(['ok' => false, 'msg' => '未知 action'], 404);
