<?php
/**
 * 多厂商 DNS 解析管理 — 重构版
 * 布局：顶部账号栏 + 域名下拉 + 解析列表 + 批量导入导出
 */

function dns_redirect_to(array $params = []): void {
    $query = http_build_query(array_filter(
        $params,
        fn($value) => $value !== '' && $value !== null
    ));
    header('Location: dns.php' . ($query !== '' ? ('?' . $query) : ''));
    exit;
}

function dns_record_type_options(): array {
    return ['A', 'AAAA', 'CNAME', 'TXT', 'MX', 'NS', 'SRV'];
}

function dns_safe_int(mixed $value, int $default): int {
    $number = (int)$value;
    return $number > 0 ? $number : $default;
}

function dns_is_ajax_request(): bool {
    return (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest');
}

function dns_json_response(array $payload, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

define('DNS_BATCH_DELETE_CHUNK_SIZE', 20);
define('DNS_IMPORT_CHUNK_SIZE', 20);

function dns_pick_working_account(array $cfg, array $accounts, string $preferredAccountId = ''): ?array {
    $ordered = [];
    $seen = [];

    $push = static function (?array $account) use (&$ordered, &$seen): void {
        if (!is_array($account)) {
            return;
        }
        $id = (string)($account['id'] ?? '');
        if ($id === '' || isset($seen[$id])) {
            return;
        }
        $seen[$id] = true;
        $ordered[] = $account;
    };

    if ($preferredAccountId !== '') {
        $push(dns_find_account($cfg, $preferredAccountId));
    }
    foreach ($accounts as $account) {
        $push(is_array($account) ? $account : null);
    }

    $firstReachable = null;
    foreach ($ordered as $account) {
        $probe = dns_cli_call(['action' => 'zones.list', 'account' => $account]);
        if (!$probe['ok']) {
            continue;
        }
        if ($firstReachable === null) {
            $firstReachable = $account;
        }
        $zones = $probe['data']['zones'] ?? [];
        if (!empty($zones)) {
            return $account;
        }
    }

    return $firstReachable;
}

function dns_pick_zone_from_list(array $zones, string $preferredZoneId = '', string $preferredZoneName = ''): ?array {
    foreach ($zones as $zone) {
        if (($zone['id'] ?? '') === $preferredZoneId && $preferredZoneId !== '') {
            return $zone;
        }
    }
    foreach ($zones as $zone) {
        if (($zone['name'] ?? '') === $preferredZoneName && $preferredZoneName !== '') {
            return $zone;
        }
    }
    return !empty($zones) ? ($zones[0] ?? null) : null;
}

function dns_resolve_zone_for_account(array $account, string $preferredZoneId = '', string $preferredZoneName = ''): array {
    $zonesResult = dns_cli_call(['action' => 'zones.list', 'account' => $account]);
    if (!$zonesResult['ok']) {
        return [
            'ok' => false,
            'msg' => (string)$zonesResult['msg'],
            'zones' => [],
            'zone' => null,
        ];
    }
    $zones = is_array($zonesResult['data']['zones'] ?? null) ? $zonesResult['data']['zones'] : [];
    return [
        'ok' => true,
        'msg' => '',
        'zones' => $zones,
        'zone' => dns_pick_zone_from_list($zones, $preferredZoneId, $preferredZoneName),
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/shared/functions.php';
    require_once __DIR__ . '/shared/dns_lib.php';
    require_once __DIR__ . '/shared/dns_api_lib.php';
    $user = auth_get_current_user();
    if (!$user || ($user['role'] ?? '') !== 'admin') {
        header('Location: /login.php');
        exit;
    }
    csrf_check();

    $cfg = load_dns_config();
    $catalog = dns_provider_catalog();
    $action = trim((string)($_POST['action'] ?? ''));

    // ── 账号保存 ──
    if ($action === 'save_account') {
        $isAjax = dns_is_ajax_request();
        $id = preg_replace('/[^a-zA-Z0-9_-]/', '', trim((string)($_POST['id'] ?? '')));
        $provider = trim((string)($_POST['provider'] ?? 'aliyun'));
        if (!isset($catalog[$provider])) {
            if ($isAjax) {
                dns_json_response(['ok' => false, 'msg' => '不支持的 DNS 厂商'], 400);
            }
            flash_set('error', '不支持的 DNS 厂商');
            dns_redirect_to();
        }
        $existing = $id !== '' ? dns_find_account($cfg, $id) : null;
        if ($id === '') { $id = dns_make_account_id(); }
        $name = trim((string)($_POST['name'] ?? '')) ?: dns_provider_label($provider);
        $credentials = [];
        foreach ($catalog[$provider]['credential_fields'] as $field) {
            $fieldName = (string)$field['name'];
            $inputName = 'cred_' . $fieldName;
            $value = trim((string)($_POST[$inputName] ?? ''));
            $previous = (string)($existing['credentials'][$fieldName] ?? '');
            $keepPrevious = $existing && ($existing['provider'] ?? '') === $provider
                && ($field['type'] ?? '') === 'password'
                && $value === ''
                && $previous !== '';
            if ($keepPrevious) { $value = $previous; }
            if (!empty($field['required']) && $value === '') {
                $message = '请输入 ' . ($field['label'] ?? $fieldName);
                if ($isAjax) {
                    dns_json_response(['ok' => false, 'msg' => $message], 400);
                }
                flash_set('error', $message);
                dns_redirect_to();
            }
            $credentials[$fieldName] = $value;
        }
        $accountId = dns_upsert_account($cfg, [
            'id'          => $id,
            'provider'    => $provider,
            'name'        => $name,
            'credentials' => $credentials,
            'created_at'  => (string)($existing['created_at'] ?? ''),
        ]);
        dns_store_ui_selection($cfg, $accountId, '', '');
        save_dns_config($cfg);
        dns_api_invalidate_zones_cache();
        audit_log('dns_account_save', ['account_id' => $accountId]);
        if ($isAjax) {
            dns_json_response([
                'ok' => true,
                'msg' => 'DNS 账户已保存',
                'redirect' => 'dns.php?' . http_build_query(['account' => $accountId]),
                'account_id' => $accountId,
            ]);
        }
        flash_set('success', 'DNS 账号已保存');
        dns_redirect_to(['account' => $accountId]);
    }

    if ($action === 'delete_account') {
        $accountId = trim((string)($_POST['account_id'] ?? ''));
        if ($accountId === '' || !dns_delete_account($cfg, $accountId)) {
            flash_set('error', '账号不存在');
            dns_redirect_to();
        }
        save_dns_config($cfg);
        dns_api_invalidate_zones_cache();
        audit_log('dns_account_delete', ['account_id' => $accountId]);
        flash_set('success', 'DNS 账号已删除');
        dns_redirect_to();
    }

    if ($action === 'verify_account') {
        $accountId = trim((string)($_POST['account_id'] ?? ''));
        $account = dns_find_account($cfg, $accountId);
        if (!$account) {
            flash_set('error', '账号不存在');
            dns_redirect_to();
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        @set_time_limit(0);

        $result = dns_cli_call(['action' => 'account.verify', 'account' => $account]);
        if (!$result['ok']) {
            flash_set('error', $result['msg']);
            dns_redirect_to(['account' => $accountId]);
        }

        $message = $result['msg'] ?: '连接测试通过';
        $zonesResult = dns_cli_call(['action' => 'zones.list', 'account' => $account]);
        if ($zonesResult['ok']) {
            $zones = $zonesResult['data']['zones'] ?? [];
            $zonesCount = count($zones);
            $zoneNames = array_values(array_filter(array_map(
                fn($zone) => trim((string)($zone['name'] ?? '')),
                $zones
            )));
            $sample = array_slice($zoneNames, 0, 3);

            if ($zonesCount > 0) {
                $message .= "，当前可见域名 {$zonesCount} 个";
                if (!empty($sample)) {
                    $message .= '（示例：' . implode('、', $sample) . ($zonesCount > 3 ? ' 等' : '') . '）';
                }
            } else {
                $message .= '，当前未读取到可见域名';
            }

            if (($account['provider'] ?? '') === 'cloudflare' && $zonesCount === 1) {
                $message .= '；若该账号实际有多个域名，请将 Cloudflare Token 的 Zone Resources 设为 Include: All zones';
            }
        } else {
            $message .= '；域名列表读取失败：' . $zonesResult['msg'];
        }

        flash_set('success', $message);
        audit_log('dns_account_verify', ['account_id' => $accountId]);
        dns_redirect_to(['account' => $accountId]);
    }

    // ── 批量导入 ──
    if ($action === 'records_import') {
        $accountId = trim((string)($_POST['account_id'] ?? ''));
        $zoneId    = trim((string)($_POST['zone_id'] ?? ''));
        $zoneName  = trim((string)($_POST['zone_name'] ?? ''));
        $isAjax    = dns_is_ajax_request();
        $account   = dns_find_account($cfg, $accountId);
        if (!$account || $zoneId === '') {
            if ($isAjax) {
                dns_json_response(['ok' => false, 'msg' => '请先选择账号与域名'], 400);
            }
            flash_set('error', '请先选择账号与域名');
            dns_redirect_to(['account' => $accountId, 'zone' => $zoneId, 'zone_name' => $zoneName]);
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        @set_time_limit(0);
        $raw = trim((string)($_POST['import_json'] ?? ''));
        $rows = json_decode($raw, true);
        if (!is_array($rows)) {
            if ($isAjax) {
                dns_json_response(['ok' => false, 'msg' => 'JSON 格式错误，请检查导入内容'], 400);
            }
            flash_set('error', 'JSON 格式错误，请检查导入内容');
            dns_redirect_to(['account' => $accountId, 'zone' => $zoneId, 'zone_name' => $zoneName]);
        }
        $zone = ['id' => $zoneId, 'name' => $zoneName];
        $ok = 0; $fail = 0;
        $failedRows = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                $fail++;
                $failedRows[] = ['name' => '', 'msg' => '条目格式错误'];
                continue;
            }
            $rec = [
                'name'  => trim((string)($row['name']  ?? '@')) ?: '@',
                'type'  => strtoupper(trim((string)($row['type'] ?? 'A'))),
                'value' => trim((string)($row['value'] ?? '')),
                'ttl'   => dns_safe_int($row['ttl'] ?? 600, 600),
            ];
            if (in_array($rec['type'], ['MX','SRV'], true)) {
                $rec['priority'] = dns_safe_int($row['priority'] ?? 10, 10);
            }
            if ($rec['type'] === 'SRV') {
                $rec['weight'] = dns_safe_int($row['weight'] ?? 0, 0);
                $rec['port']   = dns_safe_int($row['port']   ?? 1, 1);
                $rec['target'] = trim((string)($row['target'] ?? ''));
                $rec['value']  = $rec['target'];
            }
            $r = dns_cli_call(['action' => 'record.create', 'account' => $account, 'zone' => $zone, 'record' => $rec]);
            if ($r['ok']) {
                $ok++;
            } else {
                $fail++;
                $failedRows[] = [
                    'name' => (string)($rec['name'] ?? ''),
                    'type' => (string)($rec['type'] ?? ''),
                    'msg'  => (string)($r['msg'] ?? '导入失败'),
                ];
            }
        }
        if ($isAjax) {
            dns_json_response([
                'ok' => true,
                'msg' => "导入完成：成功 {$ok} 条，失败 {$fail} 条",
                'data' => [
                    'success_count' => $ok,
                    'failed_count' => $fail,
                    'failed' => $failedRows,
                ],
            ]);
        }
        flash_set($fail > 0 ? 'warn' : 'success', "导入完成：成功 {$ok} 条，失败 {$fail} 条");
        audit_log('dns_records_import', ['account_id' => $accountId, 'zone' => $zoneName, 'ok' => $ok, 'fail' => $fail]);
        dns_redirect_to(['account' => $accountId, 'zone' => $zoneId, 'zone_name' => $zoneName]);
    }

    // ── 记录操作 ──
    if (in_array($action, ['record_create', 'record_update', 'record_delete', 'record_batch_delete'], true)) {
        $accountId = trim((string)($_POST['account_id'] ?? ''));
        $zoneId    = trim((string)($_POST['zone_id'] ?? ''));
        $zoneName  = trim((string)($_POST['zone_name'] ?? ''));
        $account = dns_find_account($cfg, $accountId);
        if (!$account && !empty($cfg['accounts'] ?? [])) {
            $account = dns_pick_working_account($cfg, $cfg['accounts'], $accountId);
            if ($account) {
                $accountId = (string)($account['id'] ?? '');
            }
        }
        if (!$account) { flash_set('error', '请选择有效的 DNS 账号'); dns_redirect_to(); }

        $zoneInfo = dns_resolve_zone_for_account($account, $zoneId, $zoneName);
        if (!$zoneInfo['ok']) {
            flash_set('error', $zoneInfo['msg']);
            dns_redirect_to(['account' => $accountId]);
        }
        $zone = is_array($zoneInfo['zone'] ?? null) ? $zoneInfo['zone'] : null;
        if (!$zone) {
            flash_set('error', '请选择有效的域名 Zone');
            dns_redirect_to(['account' => $accountId]);
        }
        $zoneId = (string)($zone['id'] ?? '');
        $zoneName = (string)($zone['name'] ?? '');
        dns_store_ui_selection($cfg, $accountId, $zoneId, $zoneName);
        save_dns_config($cfg);

        if ($action === 'record_batch_delete') {
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
            @set_time_limit(0);
            $isAjax = dns_is_ajax_request();
            $recordIds = array_values(array_filter(array_map(
                fn($v) => trim((string)$v),
                is_array($_POST['record_ids'] ?? null) ? $_POST['record_ids'] : []
            )));
            if (empty($recordIds)) {
                if ($isAjax) {
                    dns_json_response(['ok' => false, 'msg' => '请先选择要删除的记录'], 400);
                }
                flash_set('error', '请先选择要删除的记录');
                dns_redirect_to(['account' => $accountId, 'zone' => $zoneId, 'zone_name' => $zoneName]);
            }
            $result = dns_cli_call(['action' => 'records.delete_many', 'account' => $account, 'zone' => $zone, 'record_ids' => $recordIds]);
            if ($result['ok']) {
                $data = $result['data'] ?? [];
                $sc = (int)($data['success_count'] ?? count($recordIds));
                $fc = count($data['failed'] ?? []);
                if ($isAjax) {
                    dns_json_response([
                        'ok' => true,
                        'msg' => "批量删除完成：成功 {$sc}，失败 {$fc}",
                        'data' => [
                            'success_count' => $sc,
                            'failed_count' => $fc,
                            'failed' => array_values($data['failed'] ?? []),
                        ],
                    ]);
                }
                flash_set($fc > 0 ? 'warn' : 'success', "批量删除完成：成功 {$sc}，失败 {$fc}");
                audit_log('dns_record_batch_delete', ['account_id' => $accountId, 'zone' => $zoneName, 'count' => count($recordIds)]);
            } else {
                if ($isAjax) {
                    dns_json_response(['ok' => false, 'msg' => $result['msg']], 502);
                }
                flash_set('error', $result['msg']);
            }
            dns_redirect_to(['account' => $accountId, 'zone' => $zoneId, 'zone_name' => $zoneName]);
        }

        if ($action === 'record_delete') {
            $result = dns_cli_call(['action' => 'record.delete', 'account' => $account, 'zone' => $zone, 'record' => ['id' => trim((string)($_POST['record_id'] ?? ''))]]);
            flash_set($result['ok'] ? 'success' : 'error', $result['ok'] ? '记录已删除' : $result['msg']);
            audit_log('dns_record_delete', ['account_id' => $accountId, 'zone' => $zoneName, 'record_id' => trim((string)($_POST['record_id'] ?? ''))]);
            dns_redirect_to(['account' => $accountId, 'zone' => $zoneId, 'zone_name' => $zoneName]);
        }

        $defaultTtl = (($account['provider'] ?? '') === 'cloudflare') ? 1 : 600;
        $record = [
            'id'       => trim((string)($_POST['record_id'] ?? '')),
            'old_type' => strtoupper(trim((string)($_POST['record_old_type'] ?? $_POST['record_type'] ?? 'A')) ?: 'A'),
            'name'     => trim((string)($_POST['record_name'] ?? '@')) ?: '@',
            'type'     => strtoupper(trim((string)($_POST['record_type'] ?? 'A')) ?: 'A'),
            'value'    => trim((string)($_POST['record_value'] ?? '')),
            'ttl'      => dns_safe_int($_POST['record_ttl'] ?? $defaultTtl, $defaultTtl),
        ];
        if (in_array($record['type'], ['MX', 'SRV'], true)) {
            $record['priority'] = dns_safe_int($_POST['record_priority'] ?? 10, 10);
        }
        if ($record['type'] === 'SRV') {
            $record['weight'] = dns_safe_int($_POST['record_weight'] ?? 0, 0);
            $record['port']   = dns_safe_int($_POST['record_port']   ?? 1, 1);
            $record['target'] = trim((string)($_POST['record_target'] ?? ''));
            $record['value']  = $record['target'];
        }
        if (dns_provider_supports_proxied((string)$account['provider'])) {
            $record['proxied'] = !empty($_POST['record_proxied']);
        }
        $result = dns_cli_call([
            'action'  => $action === 'record_create' ? 'record.create' : 'record.update',
            'account' => $account,
            'zone'    => $zone,
            'record'  => $record,
        ]);
        flash_set($result['ok'] ? 'success' : 'error', $result['ok'] ? ($action === 'record_create' ? '记录已创建' : '记录已更新') : $result['msg']);
        audit_log($action === 'record_create' ? 'dns_record_create' : 'dns_record_update', ['account_id' => $accountId, 'zone' => $zoneName, 'record_name' => $record['name'], 'type' => $record['type']]);
        dns_redirect_to(['account' => $accountId, 'zone' => $zoneId, 'zone_name' => $zoneName]);
    }

    flash_set('error', '未知操作');
    dns_redirect_to();
}

// ═══════════════════════════ GET — 渲染准备 ═══════════════════════════
$page_title = '域名解析';
require_once __DIR__ . '/shared/dns_lib.php';

$cfg      = load_dns_config();
$catalog  = dns_provider_catalog();
$accounts = $cfg['accounts'] ?? [];

// 当前激活账号
$selectedAccountId = trim((string)($_GET['account'] ?? ($cfg['ui']['selected_account_id'] ?? '')));
$selectedAccount   = $selectedAccountId !== '' ? dns_find_account($cfg, $selectedAccountId) : null;
if ((!$selectedAccount || ((string)($_GET['account'] ?? '') === '' && ($cfg['ui']['selected_account_id'] ?? '') !== $selectedAccountId)) && !empty($accounts)) {
    $selectedAccount = dns_pick_working_account($cfg, $accounts, $selectedAccountId);
    if (!$selectedAccount) {
        $selectedAccount = $accounts[0];
    }
    $selectedAccountId = (string)($selectedAccount['id'] ?? '');
}

// ── 异步数据接口（避免页面首屏阻塞）──
if ($_SERVER['REQUEST_METHOD'] === 'GET' && (string)($_GET['ajax'] ?? '') === 'dns_data') {
    $user = auth_get_current_user();
    if (!dns_is_ajax_request()) {
        dns_log_write('app', 'error', 'DNS async hydrate rejected non-ajax request', [
            'account_id' => $selectedAccountId,
            'remote_ip' => get_client_ip(),
        ]);
        dns_json_response(['ok' => false, 'msg' => '请求方式无效'], 401);
    }
    if (!$user || ($user['role'] ?? '') !== 'admin') {
        dns_log_write('app', 'error', 'DNS async hydrate unauthorized', [
            'account_id' => $selectedAccountId,
            'remote_ip' => get_client_ip(),
        ]);
        dns_json_response(['ok' => false, 'msg' => '未登录或无权限'], 401);
    }
    if (!empty($_GET['bad'])) {
        dns_log_write('app', 'warn', 'DNS async hydrate rejected malformed query', [
            'account_id' => $selectedAccountId,
            'query' => $_GET,
        ]);
        dns_json_response(['ok' => false, 'msg' => '请求参数无效'], 400);
    }

    // 关键：异步 DNS 拉取可能较慢，提前释放 session 锁，避免阻塞其它后台页面请求
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    $cfg = load_dns_config();
    $accountId = trim((string)($_GET['account'] ?? ($cfg['ui']['selected_account_id'] ?? '')));
    $account = $accountId !== '' ? dns_find_account($cfg, $accountId) : null;
    if (!$account && !empty($cfg['accounts'] ?? [])) {
        $account = dns_pick_working_account($cfg, $cfg['accounts'], $accountId);
        if ($account) {
            $accountId = (string)($account['id'] ?? '');
        }
    }

    dns_log_write('app', 'info', 'DNS async hydrate request', [
        'account_id' => $accountId,
        'query_zone' => (string)($_GET['zone'] ?? ''),
        'query_zone_name' => (string)($_GET['zone_name'] ?? ''),
    ]);

    if (!$account) {
        dns_log_write('app', 'info', 'DNS async hydrate no account selected', [
            'account_id' => $accountId,
        ]);
        dns_json_response(['ok' => true, 'data' => ['zones' => [], 'records' => [], 'selected_zone' => null]]);
    }

    $zoneInfo = dns_resolve_zone_for_account(
        $account,
        trim((string)($_GET['zone'] ?? ($cfg['ui']['selected_zone_id'] ?? ''))),
        trim((string)($_GET['zone_name'] ?? ($cfg['ui']['selected_zone_name'] ?? '')))
    );
    if (!$zoneInfo['ok']) {
        dns_log_write('app', 'error', 'DNS async hydrate zones.list failed', [
            'account_id' => $accountId,
            'message' => (string)$zoneInfo['msg'],
        ]);
        dns_json_response(['ok' => false, 'msg' => $zoneInfo['msg']]);
    }
    $zones = $zoneInfo['zones'] ?? [];
    $selectedZone = $zoneInfo['zone'] ?? null;

    $records = [];
    if ($selectedZone) {
        $recordsResult = dns_cli_call(['action' => 'records.list', 'account' => $account, 'zone' => $selectedZone]);
        if (!$recordsResult['ok']) {
            dns_log_write('app', 'error', 'DNS async hydrate records.list failed', [
                'account_id' => $accountId,
                'zone_id' => (string)($selectedZone['id'] ?? ''),
                'zone_name' => (string)($selectedZone['name'] ?? ''),
                'message' => (string)$recordsResult['msg'],
            ]);
            dns_json_response(['ok' => false, 'msg' => $recordsResult['msg']]);
        }
        $records = $recordsResult['data']['records'] ?? [];
    }

    dns_log_write('app', 'info', 'DNS async hydrate success', [
        'account_id' => $accountId,
        'zones_count' => count($zones),
        'records_count' => count($records),
        'selected_zone' => (string)($selectedZone['name'] ?? ''),
    ]);

    dns_json_response([
        'ok' => true,
        'data' => [
            'zones' => $zones,
            'records' => $records,
            'selected_zone' => $selectedZone,
        ],
    ]);
}

require_once __DIR__ . '/shared/header.php';

$dnsApiPort = getenv('NAV_PORT');
if ($dnsApiPort === false || $dnsApiPort === '' || !ctype_digit($dnsApiPort)) {
    $dnsApiPort = '58080';
}

$dnsHydrate = ((string)($_GET['hydrate'] ?? '') === '1');

// 加载 Zone 列表（默认异步，hydrate=1 时服务端渲染完整数据）
$zones             = [];
$zonesError        = '';
$zonesEmptyMessage = '正在异步加载域名列表...';

if ($selectedAccount) {
    $sp = (string)($selectedAccount['provider'] ?? '');
    if ($sp === 'cloudflare') {
        $zonesEmptyMessage = '正在异步加载 Zone 列表...';
    } elseif ($sp === 'aliyun') {
        $zonesEmptyMessage = '正在异步加载域名列表...';
    }
}

// 当前 Zone（优先 GET 参数，然后 ui 记忆）
$selectedZoneId   = trim((string)($_GET['zone']      ?? ($cfg['ui']['selected_zone_id']   ?? '')));
$selectedZoneName = trim((string)($_GET['zone_name'] ?? ($cfg['ui']['selected_zone_name'] ?? '')));
$selectedZone     = ($selectedZoneId !== '' || $selectedZoneName !== '')
    ? ['id' => $selectedZoneId, 'name' => $selectedZoneName]
    : null;

// 非 hydrate 首屏不提前展示上一次 Zone 的操作区，避免异步跳转前用户点到旧按钮/旧列表
if (!$dnsHydrate) {
    $selectedZone = null;
    $selectedZoneId = '';
    $selectedZoneName = '';
}

// 首屏默认不阻塞，hydrate=1 时才同步加载完整数据
$records      = [];
$recordsError = '';

if ($dnsHydrate && $selectedAccount) {
    $zonesResult = dns_cli_call(['action' => 'zones.list', 'account' => $selectedAccount]);
    if ($zonesResult['ok']) {
        $zones = $zonesResult['data']['zones'] ?? [];
    } else {
        $zonesError = $zonesResult['msg'];
    }

    foreach ($zones as $zone) {
        if (($zone['id'] ?? '') === $selectedZoneId) { $selectedZone = $zone; break; }
    }
    if (!$selectedZone && $selectedZoneName !== '') {
        foreach ($zones as $zone) {
            if (($zone['name'] ?? '') === $selectedZoneName) { $selectedZone = $zone; break; }
        }
    }
    if (!$selectedZone && !empty($zones)) { $selectedZone = $zones[0]; }
    if ($selectedZone) {
        $selectedZoneId   = (string)($selectedZone['id']   ?? '');
        $selectedZoneName = (string)($selectedZone['name'] ?? '');
        $recordsResult = dns_cli_call(['action' => 'records.list', 'account' => $selectedAccount, 'zone' => $selectedZone]);
        if ($recordsResult['ok']) {
            $records = $recordsResult['data']['records'] ?? [];
        } else {
            $recordsError = $recordsResult['msg'];
        }
    }

    if (
        ($cfg['ui']['selected_account_id'] ?? '') !== $selectedAccountId ||
        ($cfg['ui']['selected_zone_id']    ?? '') !== $selectedZoneId ||
        ($cfg['ui']['selected_zone_name']  ?? '') !== $selectedZoneName
    ) {
        dns_store_ui_selection($cfg, $selectedAccountId, $selectedZoneId, $selectedZoneName);
        save_dns_config($cfg);
    }
} elseif ($selectedAccount && ($cfg['ui']['selected_account_id'] ?? '') !== $selectedAccountId) {
    dns_store_ui_selection($cfg, $selectedAccountId, '', '');
    save_dns_config($cfg);
}

// ── 筛选 ──
$typeFilter      = strtoupper(trim((string)($_GET['type_filter'] ?? '')));
$keyword         = trim((string)($_GET['q'] ?? ''));
$filteredRecords = array_values(array_filter($records, function ($rec) use ($typeFilter, $keyword) {
    if ($typeFilter !== '' && strtoupper((string)($rec['type'] ?? '')) !== $typeFilter) return false;
    if ($keyword === '') return true;
    $hay = implode("\n", [
        (string)($rec['name']  ?? ''),
        (string)($rec['fqdn']  ?? ''),
        (string)($rec['type']  ?? ''),
        (string)($rec['value'] ?? ''),
    ]);
    return mb_stripos($hay, $keyword) !== false;
}));

// JS 用账号数据（脱敏）
$accountRowsForJs = [];
foreach ($accounts as $account) {
    $masked = [];
    foreach (($account['credentials'] ?? []) as $key => $val) {
        $masked[$key] = dns_mask_secret((string)$val);
    }
    $accountRowsForJs[] = [
        'id'                 => $account['id'],
        'provider'           => $account['provider'],
        'name'               => $account['name'],
        'masked_credentials' => $masked,
    ];
}
?>
<style>
/* ── DNS 页专用样式 ── */
.dns-account-bar{display:flex;align-items:center;gap:12px;padding:12px 18px;background:var(--sf);border:1px solid var(--bd);border-radius:var(--r2);margin-bottom:18px;flex-wrap:wrap}
.dns-account-bar-left{display:flex;align-items:center;gap:10px;flex:1;min-width:0}
.dns-account-active{display:flex;align-items:center;gap:8px}
.dns-account-name{font-size:14px;font-weight:700;color:var(--tx)}
.dns-account-meta{font-size:11px;color:var(--tm);font-family:var(--mono)}
.dns-account-dot{width:8px;height:8px;border-radius:50%;background:var(--ac);box-shadow:0 0 6px var(--ac-glow);flex-shrink:0}
.dns-no-account{color:var(--tm);font-size:13px}
.dns-toolbar-row{display:flex;align-items:center;gap:12px;margin-bottom:18px;flex-wrap:wrap}
.dns-domain-select-wrap{display:flex;align-items:center;gap:10px;flex:1;min-width:0;flex-wrap:wrap}
.dns-domain-label{font-size:11px;font-weight:700;color:var(--tx2);text-transform:uppercase;letter-spacing:.08em;font-family:var(--mono);white-space:nowrap}
.dns-domain-select{background:var(--bg);border:1px solid var(--bd2);border-radius:var(--r);padding:8px 36px 8px 12px;color:var(--tx);font-size:13px;font-family:var(--fn);outline:none;cursor:pointer;min-width:220px;max-width:420px;transition:border-color .2s;appearance:none;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%2300d4aa' d='M6 8L1 3h10z'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 10px center}
.dns-domain-select:focus{border-color:var(--ac);box-shadow:0 0 0 3px rgba(0,212,170,.1)}
.dns-record-count{font-size:11px;color:var(--tm);font-family:var(--mono);white-space:nowrap;padding:4px 8px;background:var(--sf2);border:1px solid var(--bd);border-radius:20px}
.dns-filter-row{display:flex;align-items:flex-end;gap:10px;flex-wrap:wrap;margin-bottom:14px}
.dns-filter-row .form-group{min-width:160px}
.dns-filter-row .form-group label{margin-bottom:4px;display:block}
.dns-actions-row{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.dns-record-name strong{display:block;font-size:13px;color:var(--tx)}
.dns-record-name code{font-size:11px;color:var(--tm)}
.dns-record-value{max-width:360px;word-break:break-all;font-size:12px;line-height:1.6;color:var(--tx2)}
.dns-extra{font-size:11px;color:var(--tm);line-height:1.8;font-family:var(--mono)}
.dns-check-col{width:36px}
.dns-batchbar{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-top:12px;padding-top:12px;border-top:1px solid var(--bd)}
.dns-empty-state{padding:32px;text-align:center;color:var(--tm);font-size:13px;line-height:1.9}
.dns-empty-state strong{display:block;font-size:15px;color:var(--tx2);margin-bottom:6px}
/* 弹窗 */
.dns-modal{display:none;position:fixed;inset:0;z-index:950;background:rgba(0,0,0,.72);backdrop-filter:blur(6px);align-items:center;justify-content:center;padding:18px}
.dns-modal.open{display:flex}
.dns-modal-card{width:min(780px,96vw);max-height:92vh;overflow-y:auto;background:var(--sf);border:1px solid var(--bd2);border-radius:var(--r2);box-shadow:0 30px 80px rgba(0,0,0,.5)}
.dns-modal-head{padding:16px 20px;border-bottom:1px solid var(--bd);display:flex;align-items:center;justify-content:space-between;gap:12px;position:sticky;top:0;background:var(--sf);z-index:1}
.dns-modal-title{font-family:var(--mono);font-size:13px;color:var(--ac);font-weight:700;letter-spacing:.05em}
.dns-modal-close{background:none;border:none;color:var(--tm);font-size:20px;cursor:pointer;line-height:1;padding:2px 6px;border-radius:4px;transition:color .15s}
.dns-modal-close:hover{color:var(--tx)}
.dns-modal-body{padding:22px}
.dns-account-list-modal{display:flex;flex-direction:column;gap:10px;margin-bottom:18px}
.dns-account-row{display:flex;align-items:center;gap:12px;padding:12px 14px;border:1px solid var(--bd);border-radius:var(--r);background:var(--sf2);transition:border-color .18s}
.dns-account-row:hover{border-color:var(--bd2)}
.dns-account-row.is-active{border-color:var(--ac);background:rgba(0,212,170,.06)}
.dns-account-row-info{flex:1;min-width:0}
.dns-account-row-name{font-size:13px;font-weight:600;color:var(--tx)}
.dns-account-row-meta{font-size:11px;color:var(--tm);font-family:var(--mono);margin-top:2px}
.dns-account-row-actions{display:flex;gap:6px;flex-shrink:0}
.dns-divider{border:none;border-top:1px solid var(--bd);margin:18px 0}
.dns-tip{padding:12px 14px;border:1px solid var(--bd);border-radius:var(--r);background:var(--bg);color:var(--tx2);font-size:12px;line-height:1.7;margin-top:12px}
.dns-loading{display:flex;align-items:center;gap:10px;color:var(--tm);font-size:13px;padding:12px 0}
.dns-spinner{width:16px;height:16px;border:2px solid rgba(0,212,170,.25);border-top-color:var(--ac);border-radius:50%;animation:dnsSpin .9s linear infinite}
@keyframes dnsSpin{to{transform:rotate(360deg)}}
body.dns-zone-loading .dns-actions-row a,
body.dns-zone-loading .dns-actions-row button{pointer-events:none;opacity:.55}
body.dns-hydrate-loading .dns-account-bar a,
body.dns-hydrate-loading .dns-account-bar button{pointer-events:none;opacity:.55}
</style>

<?php
// ── 账号栏 ──
?>
<div class="dns-account-bar">
  <div class="dns-account-bar-left">
    <?php if ($selectedAccount): ?>
    <div class="dns-account-dot"></div>
    <div>
      <div class="dns-account-active">
        <span class="dns-account-name"><?= htmlspecialchars((string)($selectedAccount['name'] ?? '')) ?></span>
        <span class="badge <?= htmlspecialchars(dns_provider_badge_class((string)$selectedAccount['provider'])) ?>"><?= htmlspecialchars(dns_provider_label((string)$selectedAccount['provider'])) ?></span>
      </div>
      <div class="dns-account-meta">账号 ID: <?= htmlspecialchars($selectedAccountId) ?> &nbsp;|&nbsp; 共 <?= count($accounts) ?> 个账号</div>
    </div>
    <?php else: ?>
    <span class="dns-no-account">尚未配置 DNS 账号，请先添加 DNS 账户</span>
    <?php endif; ?>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <button type="button" class="btn btn-secondary" onclick="openDnsApiModal()">API 说明</button>
    <button type="button" class="btn btn-secondary" onclick="openAccountMgr()">管理 DNS 账号</button>
    <?php if ($selectedAccount): ?>
    <form method="POST" style="margin:0">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="verify_account">
      <input type="hidden" name="account_id" value="<?= htmlspecialchars($selectedAccountId) ?>">
      <button type="submit" class="btn btn-secondary">测试连接</button>
    </form>
    <?php endif; ?>
    <button type="button" class="btn btn-primary" onclick="openAccountForm()">+ 添加 DNS 账户</button>
  </div>
</div>

<?php
// ── 域名工具栏 ──
?>
<div class="dns-toolbar-row">
  <div class="dns-domain-select-wrap">
    <span class="dns-domain-label">当前域名</span>
    <?php if (!$selectedAccount): ?>
    <span style="color:var(--tm);font-size:13px">请先选择账号</span>
    <?php elseif ($zonesError !== ''): ?>
    <span style="color:var(--red);font-size:13px">加载失败: <?= htmlspecialchars($zonesError) ?></span>
    <?php elseif (empty($zones)): ?>
    <span style="color:var(--tm);font-size:13px"><?= htmlspecialchars($zonesEmptyMessage) ?></span>
    <?php else: ?>
    <form method="GET" id="zone-switch-form" style="display:contents">
      <input type="hidden" name="hydrate" value="1">
      <input type="hidden" name="account" value="<?= htmlspecialchars($selectedAccountId) ?>">
      <select class="dns-domain-select" id="dns-zone-select" name="zone_name">
        <?php foreach ($zones as $z): ?>
        <?php $zn = (string)($z['name'] ?? ''); $zi = (string)($z['id'] ?? ''); ?>
        <option value="<?= htmlspecialchars($zn) ?>" data-zone-id="<?= htmlspecialchars($zi) ?>" <?= $zi === $selectedZoneId ? 'selected' : '' ?>>
          <?= htmlspecialchars($zn) ?>
        </option>
        <?php endforeach; ?>
      </select>
    </form>
    <?php if ($selectedZone): ?>
    <span class="dns-record-count" id="dns-record-count"><?= count($records) ?> 条记录</span>
    <?php endif; ?>
    <?php if (($selectedAccount['provider'] ?? '') === 'cloudflare' && count($zones) === 1): ?>
    <span style="font-size:12px;color:var(--tm)">若该 CF 账号实际有多个域名，请将 Token 的 Zone Resources 设为 Include: All zones。</span>
    <?php endif; ?>
    <?php endif; ?>
  </div>
  <?php if ($selectedAccount && $selectedZone): ?>
  <div class="dns-actions-row">
    <a class="btn btn-secondary" href="dns.php?<?= htmlspecialchars(http_build_query(['account' => $selectedAccountId, 'zone' => $selectedZoneId, 'zone_name' => $selectedZoneName])) ?>">刷新</a>
    <button type="button" class="btn btn-secondary" onclick="openImportModal()">批量导入</button>
    <button type="button" class="btn btn-secondary" id="export-btn">批量导出</button>
    <button type="button" class="btn btn-primary" onclick="openRecordModal()">+ 新建记录</button>
  </div>
  <?php endif; ?>
</div>

<?php if ($selectedAccount && $selectedZone): ?>

<?php if ($recordsError !== ''): ?>
<div class="alert alert-error">记录加载失败：<?= htmlspecialchars($recordsError) ?></div>
<?php else: ?>

<div class="card" id="dns-records-panel">
  <div class="dns-filter-row">
    <form method="GET" style="display:contents">
      <input type="hidden" name="account" value="<?= htmlspecialchars($selectedAccountId) ?>">
      <input type="hidden" name="zone" value="<?= htmlspecialchars($selectedZoneId) ?>">
      <input type="hidden" name="zone_name" value="<?= htmlspecialchars($selectedZoneName) ?>">
      <div class="form-group">
        <label>关键字搜索</label>
        <input type="text" name="q" value="<?= htmlspecialchars($keyword) ?>" placeholder="主机名 / 记录值">
      </div>
      <div class="form-group">
        <label>记录类型</label>
        <select name="type_filter">
          <option value="">全部类型</option>
          <?php foreach (dns_record_type_options() as $t): ?>
          <option value="<?= htmlspecialchars($t) ?>" <?= $typeFilter === $t ? 'selected' : '' ?>><?= htmlspecialchars($t) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <button type="submit" class="btn btn-secondary" style="align-self:flex-end">筛选</button>
      <?php if ($keyword !== '' || $typeFilter !== ''): ?>
      <a href="dns.php?<?= htmlspecialchars(http_build_query(['account' => $selectedAccountId, 'zone' => $selectedZoneId, 'zone_name' => $selectedZoneName])) ?>" class="btn btn-secondary" style="align-self:flex-end">清除</a>
      <?php endif; ?>
    </form>
  </div>

  <?php if (empty($filteredRecords)): ?>
  <div class="dns-empty-state">
    <strong>暂无记录</strong>
    <?= $keyword !== '' || $typeFilter !== '' ? '当前筛选条件下没有匹配的记录' : '该域名下还没有解析记录，点击「新建记录」开始添加' ?>
  </div>
  <?php else: ?>

  <form method="POST" id="batch-delete-form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="record_batch_delete">
    <input type="hidden" name="account_id" value="<?= htmlspecialchars($selectedAccountId) ?>">
    <input type="hidden" name="zone_id" value="<?= htmlspecialchars($selectedZoneId) ?>">
    <input type="hidden" name="zone_name" value="<?= htmlspecialchars($selectedZoneName) ?>">
  </form>

  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th class="dns-check-col"><input type="checkbox" id="chk-all" title="全选"></th>
          <th>主机记录</th>
          <th>类型</th>
          <th>记录值</th>
          <th>TTL</th>
          <th>附加信息</th>
          <th>操作</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($filteredRecords as $rec): ?>
        <?php
          $rid   = (string)($rec['id']   ?? '');
          $rtype = (string)($rec['type'] ?? '');
          $rextra = $rec['provider_extra'] ?? [];
        ?>
        <tr>
          <td class="dns-check-col">
            <input type="checkbox" class="rec-chk" name="record_ids[]" value="<?= htmlspecialchars($rid) ?>" form="batch-delete-form">
          </td>
          <td class="dns-record-name">
            <strong><?= htmlspecialchars((string)($rec['name'] ?? '@')) ?></strong>
            <?php if (!empty($rec['fqdn']) && $rec['fqdn'] !== $rec['name']): ?>
            <code><?= htmlspecialchars((string)$rec['fqdn']) ?></code>
            <?php endif; ?>
          </td>
          <td><span class="badge badge-gray"><?= htmlspecialchars($rtype) ?></span></td>
          <td class="dns-record-value"><?= htmlspecialchars((string)($rec['value'] ?? '')) ?></td>
          <td><span style="font-family:var(--mono);font-size:12px"><?= htmlspecialchars((string)($rec['ttl'] ?? '')) ?></span></td>
          <td class="dns-extra">
            <?php if (!empty($rec['priority'])): ?>Priority: <?= (int)$rec['priority'] ?><br><?php endif; ?>
            <?php if ($rtype === 'SRV'): ?>Weight: <?= (int)($rec['weight']??0) ?> Port: <?= (int)($rec['port']??0) ?><br><?php endif; ?>
            <?php if (array_key_exists('proxied',$rec) && $rec['proxied'] !== null): ?>Proxied: <?= !empty($rec['proxied'])?'On':'Off' ?><br><?php endif; ?>
            <?php if (!empty($rextra['line'])): ?>Line: <?= htmlspecialchars((string)$rextra['line']) ?><br><?php endif; ?>
          </td>
          <td>
            <div style="display:flex;gap:6px">
              <button type="button" class="btn btn-sm btn-secondary" onclick="openRecordModal('<?= htmlspecialchars($rid) ?>')">编辑</button>
              <form method="POST" data-confirm-title="删除记录" data-confirm-message="确认删除这条记录吗？">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="record_delete">
                <input type="hidden" name="account_id" value="<?= htmlspecialchars($selectedAccountId) ?>">
                <input type="hidden" name="zone_id" value="<?= htmlspecialchars($selectedZoneId) ?>">
                <input type="hidden" name="zone_name" value="<?= htmlspecialchars($selectedZoneName) ?>">
                <input type="hidden" name="record_id" value="<?= htmlspecialchars($rid) ?>">
                <button type="button" class="btn btn-sm btn-danger" onclick="submitConfirmForm(this)">删除</button>
              </form>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="dns-batchbar">
    <div style="display:flex;align-items:center;gap:10px">
      <button type="submit" form="batch-delete-form" class="btn btn-danger btn-sm" id="batch-delete-btn">删除选中</button>
      <span id="checked-count" style="font-size:12px;color:var(--tm)">已选 0 条</span>
      <span id="batch-delete-status" style="font-size:12px;color:var(--tm)"></span>
    </div>
    <span style="font-size:12px;color:var(--tm)">显示 <?= count($filteredRecords) ?> / <?= count($records) ?> 条</span>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php else: ?>
<div class="card" id="dns-main-card">
  <div class="dns-empty-state">
    <strong><?= !$selectedAccount ? '尚未配置账号' : '请选择域名' ?></strong>
    <?= !$selectedAccount ? '请点击「管理 DNS 账号」添加 DNS 账户。' : '在上方下拉菜单中选择一个域名，即可管理其解析记录。' ?>
  </div>
</div>
<?php endif; ?>

<!-- ═══ 账号管理弹窗 ═══ -->
<div id="account-mgr-modal" class="dns-modal">
  <div class="dns-modal-card">
    <div class="dns-modal-head">
      <span class="dns-modal-title">DNS 账号管理</span>
      <button class="dns-modal-close" onclick="closeModal('account-mgr-modal')">×</button>
    </div>
    <div class="dns-modal-body">
      <?php if (empty($accounts)): ?>
      <div class="dns-empty-state" style="padding:18px"><strong>暂无账号</strong>点击「添加 DNS 账户」开始添加</div>
      <?php else: ?>
      <div class="dns-account-list-modal">
        <?php foreach ($accounts as $acct): ?>
        <?php $aid=(string)($acct['id']??''); $isAct=$aid===$selectedAccountId; ?>
        <div class="dns-account-row<?= $isAct?' is-active':'' ?>">
          <div class="dns-account-row-info">
            <div class="dns-account-row-name">
              <?= htmlspecialchars((string)($acct['name']??'')) ?>
              <span class="badge <?= htmlspecialchars(dns_provider_badge_class((string)$acct['provider'])) ?>" style="margin-left:6px"><?= htmlspecialchars(dns_provider_label((string)$acct['provider'])) ?></span>
              <?php if ($isAct): ?><span class="badge badge-green" style="margin-left:4px">当前</span><?php endif; ?>
            </div>
            <div class="dns-account-row-meta">ID: <?= htmlspecialchars($aid) ?> &nbsp;|&nbsp; 更新: <?= htmlspecialchars((string)($acct['updated_at']??'-')) ?></div>
          </div>
          <div class="dns-account-row-actions">
            <a href="dns.php?<?= htmlspecialchars(http_build_query(['account'=>$aid])) ?>" class="btn btn-sm btn-secondary" onclick="closeModal('account-mgr-modal')">选择</a>
            <button type="button" class="btn btn-sm btn-secondary" onclick="openAccountForm('<?= htmlspecialchars($aid) ?>')">编辑</button>
            <form method="POST" data-confirm-title="删除账号" data-confirm-message="确认删除该账号吗？">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete_account">
              <input type="hidden" name="account_id" value="<?= htmlspecialchars($aid) ?>">
              <button type="button" class="btn btn-sm btn-danger" onclick="submitConfirmForm(this)">删除</button>
            </form>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
      <hr class="dns-divider">
      <button type="button" class="btn btn-primary" onclick="openAccountForm()">+ 添加 DNS 账户</button>
    </div>
  </div>
</div>

<!-- ═══ 账号表单弹窗 ═══ -->
<div id="account-form-modal" class="dns-modal">
  <div class="dns-modal-card">
    <div class="dns-modal-head">
      <span class="dns-modal-title" id="acct-form-title">添加 DNS 账户</span>
      <button class="dns-modal-close" onclick="closeModal('account-form-modal')">×</button>
    </div>
    <div class="dns-modal-body">
      <form method="POST" id="acct-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_account">
        <input type="hidden" name="id" id="acct-id" value="">
        <div class="form-grid">
          <div class="form-group">
            <label>显示名称</label>
            <input type="text" name="name" id="acct-name" placeholder="例：生产 Cloudflare">
          </div>
          <div class="form-group">
            <label>厂商</label>
            <select name="provider" id="acct-provider" onchange="renderCredFields()">
              <?php foreach ($catalog as $pid=>$pmeta): ?>
              <option value="<?= htmlspecialchars($pid) ?>"><?= htmlspecialchars($pmeta['label']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="form-group" style="margin-top:14px">
          <label>凭据</label>
          <div id="acct-cred-fields"></div>
        </div>
        <div class="dns-tip">密码类字段留空则保持原值不变。Cloudflare 当前使用 API Token 接入，不需要填写邮箱。</div>
        <div id="acct-form-feedback" class="dns-tip" style="display:none;margin-top:12px"></div>
        <div class="form-actions">
          <button type="button" class="btn btn-secondary" onclick="openAccountHelpModal()">说明</button>
          <button type="submit" class="btn btn-primary" id="acct-submit-btn">保存 DNS 账户</button>
          <button type="button" class="btn btn-secondary" onclick="closeModal('account-form-modal')">取消</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ═══ 账号接入说明弹窗 ═══ -->
<div id="account-help-modal" class="dns-modal">
  <div class="dns-modal-card">
    <div class="dns-modal-head">
      <span class="dns-modal-title">DNS 账户参数获取说明</span>
      <button class="dns-modal-close" onclick="closeModal('account-help-modal')">×</button>
    </div>
    <div class="dns-modal-body" style="display:flex;flex-direction:column;gap:16px">
      <div class="dns-tip" style="margin-top:0">
        请选择对应厂商后，按下面说明获取参数。建议优先创建最小权限凭据，只授予当前业务所需的读取/修改 DNS 权限。
      </div>

      <div class="card" style="padding:16px 18px">
        <div class="dns-account-row-name" style="margin-bottom:8px">Aliyun DNS</div>
        <div style="font-size:13px;line-height:1.9;color:var(--tx2)">
          1. 登录阿里云控制台，进入 <strong>AccessKey 管理</strong>。<br>
          2. 创建或查看可用于 DNS 管理的 AccessKey。<br>
          3. 将 <code>AccessKey ID</code> 填入 <code>AccessKey ID</code>。<br>
          4. 将 <code>AccessKey Secret</code> 填入 <code>AccessKey Secret</code>。<br>
          5. 请确保该账号具备阿里云 DNS 解析的读取和修改权限。
        </div>
      </div>

      <div class="card" style="padding:16px 18px">
        <div class="dns-account-row-name" style="margin-bottom:8px">Cloudflare</div>
        <div style="font-size:13px;line-height:1.9;color:var(--tx2)">
          1. 登录 Cloudflare 控制台，进入 <strong>My Profile / API Tokens</strong>。<br>
          2. 创建一个 API Token，建议使用自定义模板。<br>
          3. 至少授予 <code>Zone Read</code> 权限；若需要新增、修改、删除解析记录，还需授予 <code>DNS Read</code> 和 <code>DNS Write</code> 权限。<br>
          4. 若你希望在本系统中看到该账号下的全部域名，请将 Zone Resources 设为 <code>Include: All zones</code>；若只授权单个 Zone，则这里只会显示那一个域名。<br>
          5. 在这里仅填写 <code>API Token</code> 本体，不需要邮箱，也不要带 <code>Bearer </code> 前缀。
        </div>
      </div>

      <div class="dns-tip" style="margin-top:0">
        如果保存后提示鉴权失败，优先检查：参数是否复制完整、权限是否足够、凭据是否已限制到正确的域名范围。
      </div>

      <div class="form-actions" style="margin-top:0">
        <button type="button" class="btn btn-primary" onclick="closeModal('account-help-modal')">我知道了</button>
      </div>
    </div>
  </div>
</div>

<!-- ═══ 本机 DNS API 说明弹窗 ═══ -->
<div id="dns-api-modal" class="dns-modal">
  <div class="dns-modal-card" style="width:min(920px,96vw);max-height:92vh">
    <div class="dns-modal-head">
      <span class="dns-modal-title">本机 DNS API</span>
      <button class="dns-modal-close" onclick="closeModal('dns-api-modal')" type="button" aria-label="关闭">×</button>
    </div>
    <div class="dns-modal-body" style="max-height:calc(92vh - 56px);overflow-y:auto">

      <!-- 接口地址 -->
      <div style="margin:0 0 14px;padding:10px 14px;background:rgba(0,212,170,.06);border:1px solid var(--bd);border-radius:var(--r)">
        <strong style="color:var(--tx);font-size:13px">接口地址：</strong>
        <code style="font-size:12px">http://127.0.0.1:<?= htmlspecialchars($dnsApiPort) ?>/api/dns.php</code>
        <span style="color:var(--tx2);font-size:12px">（仅容器内 127.0.0.1 / ::1 可访问）</span>
      </div>

      <!-- 一、支持的 API 列表 -->
      <h4 style="color:var(--tx);font-size:14px;margin:16px 0 8px">一、支持的 API</h4>
      <table style="width:100%;border-collapse:collapse;font-size:12px;margin-bottom:14px">
        <thead>
          <tr style="border-bottom:1px solid var(--bd)">
            <th style="text-align:left;padding:6px 8px;color:var(--tm)">action</th>
            <th style="text-align:left;padding:6px 8px;color:var(--tm)">说明</th>
            <th style="text-align:left;padding:6px 8px;color:var(--tm)">方法</th>
            <th style="text-align:left;padding:6px 8px;color:var(--tm)">必填参数</th>
            <th style="text-align:left;padding:6px 8px;color:var(--tm)">可选参数</th>
          </tr>
        </thead>
        <tbody>
          <tr style="border-bottom:1px solid var(--bd)">
            <td style="padding:6px 8px"><code>query</code></td>
            <td style="padding:6px 8px">查询某域名当前解析记录（只读）</td>
            <td style="padding:6px 8px">GET / POST</td>
            <td style="padding:6px 8px"><code>domain</code></td>
            <td style="padding:6px 8px"><code>type</code>（A/AAAA/CNAME，默认查全部）</td>
          </tr>
          <tr style="border-bottom:1px solid var(--bd)">
            <td style="padding:6px 8px"><code>update</code></td>
            <td style="padding:6px 8px">单条更新/创建；存在则更新，不存在则创建，值不变则跳过</td>
            <td style="padding:6px 8px">GET / POST</td>
            <td style="padding:6px 8px"><code>domain</code>, <code>value</code></td>
            <td style="padding:6px 8px"><code>type</code>（不传则自动推断）, <code>ttl</code></td>
          </tr>
          <tr>
            <td style="padding:6px 8px"><code>batch_update</code></td>
            <td style="padding:6px 8px">批量更新多个域名到同一个 IP 值</td>
            <td style="padding:6px 8px">GET / POST(JSON)</td>
            <td style="padding:6px 8px"><code>value</code>, <code>domains</code></td>
            <td style="padding:6px 8px"><code>type</code>, <code>ttl</code></td>
          </tr>
        </tbody>
      </table>

      <!-- 二、通用规则 -->
      <h4 style="color:var(--tx);font-size:14px;margin:16px 0 8px">二、通用规则</h4>
      <ul style="color:var(--tx2);font-size:12px;line-height:1.85;margin:0 0 14px;padding-left:18px">
        <li>无需填写账号 ID 与 Zone，系统根据已配置的 DNS 账号<strong>自动匹配</strong>域名归属</li>
        <li>支持记录类型：<strong>A / AAAA / CNAME</strong>；不传 <code>type</code> 时按 <code>value</code> 自动推断（IPv4→A，IPv6→AAAA，其余→CNAME）</li>
        <li>返回 JSON：<code>code</code> 为 <code>0</code> 成功，<code>-1</code> 失败；<code>msg</code> 为文本说明</li>
        <li>GET 与 POST 均可；POST 时参数支持 <code>application/x-www-form-urlencoded</code> 或 <code>application/json</code></li>
        <li>POST 时正文参数<strong>覆盖</strong> URL Query 中的同名参数</li>
      </ul>

      <!-- 三、详细测试用例 -->
      <h4 style="color:var(--tx);font-size:14px;margin:16px 0 8px">三、测试用例</h4>
      <div style="font-family:var(--mono);font-size:12px;line-height:1.65;color:var(--tx2);background:var(--bg);border:1px solid var(--bd);border-radius:var(--r);padding:14px 16px;overflow-x:auto">

        <div style="color:var(--tm);margin-bottom:8px">▼ query — 查询单个域名</div>
        <pre style="margin:0 0 16px;white-space:pre-wrap;word-break:break-all">curl -sS "http://127.0.0.1:<?= $dnsApiPort ?>/api/dns.php?action=query&amp;domain=www.example.com&amp;type=A"
curl -sS "http://127.0.0.1:<?= $dnsApiPort ?>/api/dns.php?action=query&amp;domain=www.example.com"</pre>

        <div style="color:var(--tm);margin-bottom:8px">▼ query — 查询多个域名（分别调用）</div>
        <pre style="margin:0 0 16px;white-space:pre-wrap;word-break:break-all">curl -sS "http://127.0.0.1:<?= $dnsApiPort ?>/api/dns.php?action=query&amp;domain=www.example.com&amp;type=A"
curl -sS "http://127.0.0.1:<?= $dnsApiPort ?>/api/dns.php?action=query&amp;domain=blog.example.com&amp;type=A"
curl -sS "http://127.0.0.1:<?= $dnsApiPort ?>/api/dns.php?action=query&amp;domain=*.example.com&amp;type=A"</pre>

        <div style="color:var(--tm);margin-bottom:8px">▼ update — 单域名 GET 写法</div>
        <pre style="margin:0 0 16px;white-space:pre-wrap;word-break:break-all">curl -sS "http://127.0.0.1:<?= $dnsApiPort ?>/api/dns.php?action=update&amp;domain=www.example.com&amp;value=1.2.3.4"
curl -sS "http://127.0.0.1:<?= $dnsApiPort ?>/api/dns.php?action=update&amp;domain=www.example.com&amp;value=1.2.3.4&amp;type=A&amp;ttl=120"
curl -sS "http://127.0.0.1:<?= $dnsApiPort ?>/api/dns.php?action=update&amp;domain=www.example.com&amp;value=2606:4700::6810:85e5&amp;type=AAAA"</pre>

        <div style="color:var(--tm);margin-bottom:8px">▼ update — 单域名 POST 表单写法</div>
        <pre style="margin:0 0 16px;white-space:pre-wrap;word-break:break-all">curl -sS http://127.0.0.1:<?= $dnsApiPort ?>/api/dns.php \
  -d "action=update" \
  -d "domain=www.example.com" \
  -d "value=1.2.3.4"

curl -sS http://127.0.0.1:<?= $dnsApiPort ?>/api/dns.php \
  -d "action=update" \
  -d "domain=www.example.com" \
  -d "value=1.2.3.4" \
  -d "type=A" \
  -d "ttl=120"</pre>

        <div style="color:var(--tm);margin-bottom:8px">▼ update — 单域名 POST JSON 写法</div>
        <pre style="margin:0 0 16px;white-space:pre-wrap;word-break:break-all">curl -sS http://127.0.0.1:<?= $dnsApiPort ?>/api/dns.php \
  -H "Content-Type: application/json" \
  -d '{"action":"update","domain":"www.example.com","value":"1.2.3.4","type":"A","ttl":120}'</pre>

        <div style="color:var(--tm);margin-bottom:8px">▼ batch_update — 多个域名 GET 写法（逗号分隔）</div>
        <pre style="margin:0 0 16px;white-space:pre-wrap;word-break:break-all">curl -sS "http://127.0.0.1:<?= $dnsApiPort ?>/api/dns.php?action=batch_update&amp;value=1.2.3.4&amp;domains=example.com"
curl -sS "http://127.0.0.1:<?= $dnsApiPort ?>/api/dns.php?action=batch_update&amp;value=1.2.3.4&amp;domains=example.com,www.example.com"
curl -sS "http://127.0.0.1:<?= $dnsApiPort ?>/api/dns.php?action=batch_update&amp;value=1.2.3.4&amp;domains=example.com,www.example.com,*.example.com"</pre>

        <div style="color:var(--tm);margin-bottom:8px">▼ batch_update — 多个域名 POST JSON 写法（推荐）</div>
        <pre style="margin:0 0 16px;white-space:pre-wrap;word-break:break-all">curl -sS http://127.0.0.1:<?= $dnsApiPort ?>/api/dns.php \
  -H "Content-Type: application/json" \
  -d '{"action":"batch_update","value":"1.2.3.4","domains":["example.com"]}'

curl -sS http://127.0.0.1:<?= $dnsApiPort ?>/api/dns.php \
  -H "Content-Type: application/json" \
  -d '{"action":"batch_update","value":"1.2.3.4","domains":["example.com","www.example.com","*.example.com"]}'</pre>

        <div style="color:var(--tm);margin-bottom:8px">▼ batch_update — POST 表单写法</div>
        <pre style="margin:0 0 16px;white-space:pre-wrap;word-break:break-all">curl -sS http://127.0.0.1:<?= $dnsApiPort ?>/api/dns.php \
  -d "action=batch_update" \
  -d "value=1.2.3.4" \
  -d "domains=example.com,www.example.com,*.example.com"</pre>
      </div>

      <!-- 四、完整示例脚本 -->
      <h4 style="color:var(--tx);font-size:14px;margin:16px 0 8px">四、计划任务完整示例</h4>
      <p style="color:var(--tx2);font-size:12px;line-height:1.75;margin:0 0 10px">
        在后台「计划任务」中新建任务，填写 Cron 与下方脚本；标准输出会写入任务运行日志。
      </p>
      <div style="font-family:var(--mono);font-size:12px;line-height:1.65;color:var(--tx2);background:var(--bg);border:1px solid var(--bd);border-radius:var(--r);padding:14px 16px;overflow-x:auto">
        <pre style="margin:0;white-space:pre-wrap;word-break:break-all">LOG=/var/www/nav/data/logs/ddns_manual.log
log(){ echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*" | tee -a "$LOG"; }

API="http://127.0.0.1:<?= $dnsApiPort ?>/api/dns.php"

log "=== DDNS 开始 ==="

# 查询当前记录
log "查询 www.example.com 当前 A 记录"
Q=$(curl -sS "$API?action=query&amp;domain=www.example.com&amp;type=A") || { log "query 失败"; exit 1; }
log "query 返回: $Q"

# 获取公网 IP
log "获取公网 IP"
IP=$(curl -sS --connect-timeout 5 https://api.ipify.org) || { log "获取 IP 失败"; exit 1; }
log "公网 IP: $IP"

# 批量更新 3 个域名
log "批量更新 example.com / www.example.com / *.example.com"
RESP=$(curl -sS "$API" -H "Content-Type: application/json" \
  -d "{\"action\":\"batch_update\",\"value\":\"$IP\",\"domains\":[\"example.com\",\"www.example.com\",\"*.example.com\"]}") || { log "batch_update 失败"; exit 1; }
log "batch_update 返回: $RESP"

log "=== DDNS 结束 ==="</pre>
      </div>

      <div class="form-actions" style="margin-top:18px;margin-bottom:0">
        <button type="button" class="btn btn-primary" onclick="closeModal('dns-api-modal')">关闭</button>
      </div>
    </div>
  </div>
</div>

<!-- ═══ 记录编辑弹窗 ═══ -->
<div id="record-modal" class="dns-modal">
  <div class="dns-modal-card">
    <div class="dns-modal-head">
      <span class="dns-modal-title" id="rec-modal-title">新建解析记录</span>
      <button class="dns-modal-close" onclick="closeModal('record-modal')">×</button>
    </div>
    <div class="dns-modal-body">
      <form method="POST" id="rec-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" id="rec-action" value="record_create">
        <input type="hidden" name="account_id" value="<?= htmlspecialchars($selectedAccountId) ?>">
        <input type="hidden" name="zone_id" value="<?= htmlspecialchars($selectedZoneId) ?>">
        <input type="hidden" name="zone_name" value="<?= htmlspecialchars($selectedZoneName) ?>">
        <input type="hidden" name="record_id" id="rec-id" value="">
        <input type="hidden" name="record_old_type" id="rec-old-type" value="A">
        <div class="form-grid">
          <div class="form-group">
            <label>主机记录</label>
            <input type="text" name="record_name" id="rec-name" value="@" placeholder="@ / www / api">
          </div>
          <div class="form-group">
            <label>类型</label>
            <select name="record_type" id="rec-type" onchange="onRecTypeChange()">
              <?php foreach (dns_record_type_options() as $t): ?>
              <option value="<?= htmlspecialchars($t) ?>"><?= htmlspecialchars($t) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group full" id="rec-value-group">
            <label id="rec-value-label">记录值</label>
            <input type="text" name="record_value" id="rec-value" placeholder="1.2.3.4">
          </div>
          <div class="form-group" id="rec-priority-group" style="display:none">
            <label>Priority</label>
            <input type="number" name="record_priority" id="rec-priority" value="10">
          </div>
          <div class="form-group" id="rec-weight-group" style="display:none">
            <label>Weight</label>
            <input type="number" name="record_weight" id="rec-weight" value="0">
          </div>
          <div class="form-group" id="rec-port-group" style="display:none">
            <label>Port</label>
            <input type="number" name="record_port" id="rec-port" value="1">
          </div>
          <div class="form-group full" id="rec-target-group" style="display:none">
            <label>Target</label>
            <input type="text" name="record_target" id="rec-target" placeholder="sip.example.com">
          </div>
          <div class="form-group">
            <label>TTL</label>
            <input type="number" name="record_ttl" id="rec-ttl" value="<?= ($selectedAccount&&($selectedAccount['provider']??'')==='cloudflare')?'1':'600' ?>">
            <span class="form-hint" id="rec-ttl-hint">Cloudflare 使用 1 表示自动 TTL</span>
          </div>
          <div class="form-group" id="rec-proxied-group" style="display:none">
            <label style="display:flex;align-items:center;gap:8px;text-transform:none;letter-spacing:0;font-weight:500;color:var(--tx);cursor:pointer">
              <input type="checkbox" name="record_proxied" value="1" id="rec-proxied" style="width:15px;height:15px;accent-color:var(--ac)">
              启用 Proxied（仅 Cloudflare A/AAAA/CNAME）
            </label>
          </div>
        </div>
        <div class="form-actions">
          <button type="submit" class="btn btn-primary">保存记录</button>
          <button type="button" class="btn btn-secondary" onclick="closeModal('record-modal')">取消</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ═══ 批量导入弹窗 ═══ -->
<div id="import-modal" class="dns-modal">
  <div class="dns-modal-card">
    <div class="dns-modal-head">
      <span class="dns-modal-title">批量导入解析记录</span>
      <button class="dns-modal-close" onclick="closeModal('import-modal')">×</button>
    </div>
    <div class="dns-modal-body">
      <form method="POST" id="dns-import-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="records_import">
        <input type="hidden" name="account_id" value="<?= htmlspecialchars($selectedAccountId) ?>">
        <input type="hidden" name="zone_id" value="<?= htmlspecialchars($selectedZoneId) ?>">
        <input type="hidden" name="zone_name" value="<?= htmlspecialchars($selectedZoneName) ?>">
        <div class="form-group">
          <label>JSON 数据</label>
          <textarea name="import_json" id="dns-import-json" rows="12" style="font-family:var(--mono);font-size:12px" placeholder='[&#10;  {"name":"@","type":"A","value":"1.2.3.4","ttl":600},&#10;  {"name":"www","type":"CNAME","value":"example.com","ttl":600}&#10;]'></textarea>
        </div>
        <div class="dns-tip">每条记录须含 <code>name</code>、<code>type</code>、<code>value</code>。MX/SRV 可附加 <code>priority</code>。</div>
        <div id="dns-import-status" class="dns-tip" style="display:none;margin-top:10px"></div>
        <div class="form-actions">
          <button type="submit" class="btn btn-primary" id="dns-import-submit">开始导入</button>
          <button type="button" class="btn btn-secondary" onclick="closeModal('import-modal')">取消</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
var DNS_CATALOG = <?= json_encode($catalog, JSON_UNESCAPED_UNICODE|JSON_HEX_TAG) ?>;
var DNS_ACCOUNTS = <?= json_encode($accountRowsForJs, JSON_UNESCAPED_UNICODE|JSON_HEX_TAG) ?>;
var DNS_RECORDS  = <?= json_encode($records, JSON_UNESCAPED_UNICODE|JSON_HEX_TAG) ?>;
var DNS_PROVIDER = <?= json_encode($selectedAccount ? ($selectedAccount['provider']??'') : '', JSON_HEX_TAG) ?>;
var DNS_HYDRATE = <?= $dnsHydrate ? 'true' : 'false' ?>;
var DNS_SELECTED_ACCOUNT = <?= json_encode($selectedAccountId, JSON_HEX_TAG) ?>;
var DNS_BATCH_DELETE_CHUNK_SIZE = <?= (int)DNS_BATCH_DELETE_CHUNK_SIZE ?>;
var DNS_IMPORT_CHUNK_SIZE = <?= (int)DNS_IMPORT_CHUNK_SIZE ?>;
/** 最近一次服务端渲染的解析列表区 HTML，用于域名切换失败或中断时恢复（避免连续切换时误把「加载中」当快照） */
var dnsPanelHtmlSnapshot = '';
var dnsRecordCountSnapshot = '';
(function initDnsPanelSnapshots() {
  var p = document.getElementById('dns-records-panel');
  var c = document.getElementById('dns-record-count');
  if (p) dnsPanelHtmlSnapshot = p.innerHTML;
  if (c) dnsRecordCountSnapshot = c.innerHTML;
})();

var dnsHydrateToolbarSnapshot = '';
var dnsHydrateMainSnapshot = '';

var dnsAsyncController = null;
var dnsAsyncAborted = false;
/** 用户点击外链/其它后台页面前为 true，用于中止域名切换 fetch 后不再误恢复旧列表 */
var dnsUserLeaving = false;
/** 域名切换并发代数：新一次切换会使旧请求在 Abort 后不恢复 DOM */
var dnsZoneSwitchGen = 0;
/** 首屏异步 hydrate 并发代数（与域名切换独立，避免 finally 误清理） */
var dnsHydrateGen = 0;

function abortDnsAsync() {
  dnsAsyncAborted = true;
  if (dnsAsyncController) {
    try { dnsAsyncController.abort(); } catch (_) {}
  }
  dnsAsyncController = null;
  var loadingEl = document.getElementById('dns-async-loading');
  if (loadingEl) loadingEl.remove();
}

function bindDnsAbortOnNavigation() {
  document.querySelectorAll('a[href]').forEach(function(a) {
    a.addEventListener('click', function(ev) {
      var h = a.getAttribute('href');
      if (h && h !== '#' && h.indexOf('javascript:') !== 0) {
        dnsUserLeaving = true;
      }
      abortDnsAsync();
    }, { capture: true });
  });
  document.querySelectorAll('form').forEach(function(f) {
    // 仅中止请求；不设 dnsUserLeaving（避免 preventDefault 的 AJAX 表单误标为离开导致解析区无法恢复）
    f.addEventListener('submit', abortDnsAsync, { capture: true });
  });
  window.addEventListener('beforeunload', function() {
    dnsUserLeaving = true;
    abortDnsAsync();
  }, { once: true });
  window.addEventListener('pagehide', function() {
    dnsUserLeaving = true;
    abortDnsAsync();
  }, { once: true });
}

function restoreDnsHydrateShell() {
  var toolbar = document.querySelector('.dns-toolbar-row');
  var mainEl = document.getElementById('dns-records-panel') || document.getElementById('dns-main-card');
  if (toolbar && typeof dnsHydrateToolbarSnapshot === 'string' && dnsHydrateToolbarSnapshot !== '' && !toolbar.querySelector('#dns-async-loading')) {
    toolbar.innerHTML = dnsHydrateToolbarSnapshot;
  }
  if (mainEl && typeof dnsHydrateMainSnapshot === 'string' && dnsHydrateMainSnapshot !== '' && !mainEl.querySelector('#dns-async-loading')) {
    mainEl.innerHTML = dnsHydrateMainSnapshot;
  }
  var loadingEl = document.getElementById('dns-async-loading');
  if (loadingEl) loadingEl.remove();
  document.body.classList.remove('dns-hydrate-loading');
}

async function dnsAsyncHydrateIfNeeded() {
  if (DNS_HYDRATE || !DNS_SELECTED_ACCOUNT) return;

  dnsHydrateGen++;
  var myGen = dnsHydrateGen;

  var toolbar = document.querySelector('.dns-toolbar-row');
  var mainEl = document.getElementById('dns-records-panel') || document.getElementById('dns-main-card');

  if (toolbar && toolbar.innerHTML.indexOf('正在加载 DNS 数据') === -1) {
    dnsHydrateToolbarSnapshot = toolbar.innerHTML;
  }
  if (mainEl && mainEl.innerHTML.indexOf('正在加载解析记录') === -1) {
    dnsHydrateMainSnapshot = mainEl.innerHTML;
  }

  dnsUserLeaving = false;
  if (dnsAsyncController) {
    try { dnsAsyncController.abort(); } catch (_) {}
  }

  if (toolbar && !document.getElementById('dns-async-loading')) {
    var loading = document.createElement('div');
    loading.id = 'dns-async-loading';
    loading.className = 'dns-loading';
    loading.style.padding = '8px 0';
    loading.innerHTML = '<span class="dns-spinner"></span><span>正在加载 DNS 数据...</span>';
    toolbar.appendChild(loading);
  }
  document.body.classList.add('dns-hydrate-loading');

  dnsAsyncAborted = false;
  dnsAsyncController = new AbortController();

  try {
    var url = 'dns.php?ajax=dns_data&account=' + encodeURIComponent(DNS_SELECTED_ACCOUNT);
    var res = await fetch(url, { signal: dnsAsyncController.signal, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
    var rawText = await res.text();
    var json = null;
    try {
      json = rawText ? JSON.parse(rawText) : null;
    } catch (_) {
      throw new Error('DNS 接口返回非 JSON（HTTP ' + res.status + '）');
    }

    if (dnsAsyncAborted || myGen !== dnsHydrateGen) return;
    if (!res.ok || !json || !json.ok) {
      throw new Error((json && json.msg) ? json.msg : ('加载 DNS 数据失败（HTTP ' + res.status + '）'));
    }

    if (dnsUserLeaving) return;

    var zone = (json.data && json.data.selected_zone) ? json.data.selected_zone : null;
    var next = new URL(window.location.href);
    next.searchParams.set('hydrate', '1');
    next.searchParams.set('account', DNS_SELECTED_ACCOUNT);
    if (zone && zone.id) next.searchParams.set('zone', zone.id);
    if (zone && zone.name) next.searchParams.set('zone_name', zone.name);
    if (!dnsAsyncAborted && !dnsUserLeaving) window.location.replace(next.toString());
  } catch (e) {
    if (e && e.name === 'AbortError') {
      if (dnsUserLeaving || myGen !== dnsHydrateGen) return;
      restoreDnsHydrateShell();
      return;
    }
    if (dnsAsyncAborted || myGen !== dnsHydrateGen) return;
    showToast((e && e.message) ? e.message : 'DNS 数据加载失败', 'error');
    restoreDnsHydrateShell();
  } finally {
    if (myGen === dnsHydrateGen) {
      dnsAsyncController = null;
    }
  }
}

function dnsBuildHydrateUrl(zone) {
  var next = new URL(window.location.href);
  next.searchParams.set('hydrate', '1');
  next.searchParams.set('account', String(DNS_SELECTED_ACCOUNT));
  if (zone && zone.id) next.searchParams.set('zone', zone.id);
  else next.searchParams.delete('zone');
  if (zone && zone.name) next.searchParams.set('zone_name', zone.name);
  else next.searchParams.delete('zone_name');
  next.searchParams.delete('q');
  next.searchParams.delete('type_filter');
  return next.toString();
}

async function dnsZoneSwitchViaFetch(zoneSelect, zoneIdInput) {
  var panel = document.getElementById('dns-records-panel');
  if (!panel) return;

  dnsZoneSwitchGen++;
  var myGen = dnsZoneSwitchGen;

  var prevIdx = zoneSelect.dataset.dnsPrevIdx !== undefined ? parseInt(zoneSelect.dataset.dnsPrevIdx, 10) : zoneSelect.selectedIndex;
  if (isNaN(prevIdx)) prevIdx = 0;

  var opt = zoneSelect.options[zoneSelect.selectedIndex];
  var zid = opt ? (opt.getAttribute('data-zone-id') || '') : '';
  var zname = opt ? (opt.value || '') : '';
  if (zoneIdInput) zoneIdInput.value = zid;

  var countEl = document.getElementById('dns-record-count');
  if (panel.innerHTML.indexOf('正在加载解析记录') === -1) {
    dnsPanelHtmlSnapshot = panel.innerHTML;
    if (countEl) dnsRecordCountSnapshot = countEl.innerHTML;
  }
  var savedPanelHtml = dnsPanelHtmlSnapshot;
  var savedCountHtml = countEl ? dnsRecordCountSnapshot : '';
  var prevZoneId = '';
  if (zoneSelect.options[prevIdx]) {
    prevZoneId = zoneSelect.options[prevIdx].getAttribute('data-zone-id') || '';
  }

  dnsUserLeaving = false;
  if (dnsAsyncController) {
    try { dnsAsyncController.abort(); } catch (_) {}
  }
  dnsAsyncController = new AbortController();
  dnsAsyncAborted = false;

  document.body.classList.add('dns-zone-loading');
  panel.innerHTML = '<div class="dns-empty-state"><span class="dns-loading" style="justify-content:center;padding:28px 0"><span class="dns-spinner"></span><span>正在加载解析记录...</span></span></div>';
  if (countEl) countEl.innerHTML = '加载中…';

  try {
    var url = 'dns.php?ajax=dns_data&account=' + encodeURIComponent(String(DNS_SELECTED_ACCOUNT))
      + '&zone=' + encodeURIComponent(zid) + '&zone_name=' + encodeURIComponent(zname);
    var res = await fetch(url, { signal: dnsAsyncController.signal, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
    var rawText = await res.text();
    var json = null;
    try {
      json = rawText ? JSON.parse(rawText) : null;
    } catch (_) {
      throw new Error('DNS 接口返回非 JSON（HTTP ' + res.status + '）');
    }

    if (dnsAsyncAborted || myGen !== dnsZoneSwitchGen) return;
    if (!res.ok || !json || !json.ok) {
      throw new Error((json && json.msg) ? json.msg : ('加载解析记录失败（HTTP ' + res.status + '）'));
    }

    var zone = (json.data && json.data.selected_zone) ? json.data.selected_zone : null;
    if (dnsUserLeaving) return;
    window.location.replace(dnsBuildHydrateUrl(zone));
  } catch (e) {
    if (e && e.name === 'AbortError') {
      if (dnsUserLeaving || myGen !== dnsZoneSwitchGen) return;
      panel.innerHTML = savedPanelHtml;
      if (countEl) countEl.innerHTML = savedCountHtml;
      if (prevIdx >= 0 && prevIdx < zoneSelect.options.length) zoneSelect.selectedIndex = prevIdx;
      if (zoneIdInput) zoneIdInput.value = prevZoneId;
      zoneSelect.dataset.dnsPrevIdx = String(zoneSelect.selectedIndex);
      dnsPanelHtmlSnapshot = savedPanelHtml;
      if (countEl) dnsRecordCountSnapshot = savedCountHtml;
      return;
    }
    if (myGen !== dnsZoneSwitchGen) return;
    showToast((e && e.message) ? e.message : '加载解析记录失败', 'error');
    panel.innerHTML = savedPanelHtml;
    if (countEl) countEl.innerHTML = savedCountHtml;
    if (prevIdx >= 0 && prevIdx < zoneSelect.options.length) zoneSelect.selectedIndex = prevIdx;
    if (zoneIdInput) zoneIdInput.value = prevZoneId;
    zoneSelect.dataset.dnsPrevIdx = String(zoneSelect.selectedIndex);
    dnsPanelHtmlSnapshot = savedPanelHtml;
    if (countEl) dnsRecordCountSnapshot = savedCountHtml;
  } finally {
    if (myGen === dnsZoneSwitchGen) {
      document.body.classList.remove('dns-zone-loading');
      dnsAsyncController = null;
    }
  }
}


function closeModal(id){ document.getElementById(id).classList.remove('open'); }
function openModal(id) { document.getElementById(id).classList.add('open'); }

/* 弹窗背景点击关闭防护（阻止 mousedown 在内容区、mouseup 在背景层的误触） */
(function(){
  var mdTarget = null;
  ['account-mgr-modal','account-form-modal','account-help-modal','dns-api-modal','record-modal','import-modal'].forEach(function(id){
    var modal = document.getElementById(id);
    if (!modal) return;
    modal.addEventListener('mousedown', function(e){ mdTarget = e.target; });
    modal.addEventListener('click', function(e){
      if (e.target === modal && mdTarget === modal) closeModal(id);
      mdTarget = null;
    });
  });
})();
function openAccountMgr()  { openModal('account-mgr-modal'); }
function openAccountHelpModal() { openModal('account-help-modal'); }
function openDnsApiModal() { openModal('dns-api-modal'); }
function openImportModal() { openModal('import-modal'); }

function findAccount(id) {
  for (var i = 0; i < DNS_ACCOUNTS.length; i++) { if (DNS_ACCOUNTS[i].id === id) return DNS_ACCOUNTS[i]; }
  return null;
}
function findRecord(id) {
  for (var i = 0; i < DNS_RECORDS.length; i++) { if (DNS_RECORDS[i].id === id) return DNS_RECORDS[i]; }
  return null;
}

function renderCredFields() {
  var provider = document.getElementById('acct-provider').value;
  var acctId   = document.getElementById('acct-id').value;
  var acct     = acctId ? findAccount(acctId) : null;
  var meta     = DNS_CATALOG[provider] || { credential_fields: [] };
  var html     = '<div class="form-grid">';
  (meta.credential_fields || []).forEach(function(f) {
    var masked = acct && acct.masked_credentials ? (acct.masked_credentials[f.name] || '') : '';
    var hint   = (f.type === 'password' && masked) ? '已保存: ' + masked + '；留空保持不变' : (f.help || '');
    html += '<div class="form-group"><label>' + f.label + '</label>'
          + '<input type="' + (f.type || 'text') + '" name="cred_' + f.name + '" autocomplete="off" placeholder="' + (f.placeholder || '') + '">';
    if (hint) html += '<span class="form-hint">' + hint + '</span>';
    html += '</div>';
  });
  html += '</div>';
  document.getElementById('acct-cred-fields').innerHTML = html;
}

function openAccountForm(acctId) {
  var acct = acctId ? findAccount(acctId) : null;
  var feedback = document.getElementById('acct-form-feedback');
  if (feedback) {
    feedback.style.display = 'none';
    feedback.textContent = '';
  }
  document.getElementById('acct-form-title').textContent    = acct ? '编辑 DNS 账户' : '添加 DNS 账户';
  document.getElementById('acct-id').value                  = acct ? (acct.id || '') : '';
  document.getElementById('acct-name').value                = acct ? (acct.name || '') : '';
  document.getElementById('acct-provider').value            = acct ? (acct.provider || 'aliyun') : 'aliyun';
  renderCredFields();
  closeModal('account-mgr-modal');
  openModal('account-form-modal');
}

var acctForm = document.getElementById('acct-form');
if (acctForm) {
  acctForm.addEventListener('submit', async function(event) {
    event.preventDefault();
    var submitBtn = document.getElementById('acct-submit-btn');
    var feedback = document.getElementById('acct-form-feedback');
    var originalText = submitBtn ? submitBtn.textContent : '';
    if (feedback) {
      feedback.style.display = 'none';
      feedback.textContent = '';
    }
    if (submitBtn) {
      submitBtn.disabled = true;
      submitBtn.textContent = '保存中...';
    }

    try {
      var response = await fetch('dns.php', {
        method: 'POST',
        headers: {
          'X-Requested-With': 'XMLHttpRequest'
        },
        body: new FormData(acctForm)
      });
      var result = await response.json();
      if (!response.ok || !result.ok) {
        throw new Error((result && result.msg) ? result.msg : '保存 DNS 账户失败');
      }
      if (submitBtn) {
        submitBtn.textContent = '保存成功，正在跳转...';
      }
      window.location.href = result.redirect || 'dns.php';
    } catch (error) {
      if (feedback) {
        feedback.textContent = error && error.message ? error.message : '保存 DNS 账户失败，请稍后重试';
        feedback.style.display = 'block';
      } else {
        alert(error && error.message ? error.message : '保存 DNS 账户失败，请稍后重试');
      }
      if (submitBtn) {
        submitBtn.disabled = false;
        submitBtn.textContent = originalText || '保存 DNS 账户';
      }
    }
  });
}

function onRecTypeChange() {
  var type     = (document.getElementById('rec-type').value || '').toUpperCase();
  var isMX     = type === 'MX';
  var isSRV    = type === 'SRV';
  var canProxy = DNS_PROVIDER === 'cloudflare' && ['A','AAAA','CNAME'].indexOf(type) >= 0;
  document.getElementById('rec-priority-group').style.display = (isMX || isSRV) ? '' : 'none';
  document.getElementById('rec-weight-group').style.display   = isSRV ? '' : 'none';
  document.getElementById('rec-port-group').style.display     = isSRV ? '' : 'none';
  document.getElementById('rec-target-group').style.display   = isSRV ? '' : 'none';
  document.getElementById('rec-value-group').style.display    = isSRV ? 'none' : '';
  document.getElementById('rec-proxied-group').style.display  = canProxy ? '' : 'none';
  document.getElementById('rec-value-label').textContent      = isMX ? 'Mail Server' : '记录值';
}

function openRecordModal(recId) {
  var rec = recId ? findRecord(recId) : null;
  var defTtl = DNS_PROVIDER === 'cloudflare' ? 1 : 600;
  document.getElementById('rec-modal-title').textContent = rec ? '编辑解析记录' : '新建解析记录';
  document.getElementById('rec-action').value   = rec ? 'record_update' : 'record_create';
  document.getElementById('rec-id').value        = rec ? (rec.id || '')       : '';
  document.getElementById('rec-old-type').value  = rec ? (rec.type || 'A')    : 'A';
  document.getElementById('rec-name').value      = rec ? (rec.name || '@')    : '@';
  document.getElementById('rec-type').value      = rec ? (rec.type || 'A')    : 'A';
  document.getElementById('rec-value').value     = rec ? (rec.value || '')    : '';
  document.getElementById('rec-priority').value  = rec ? (rec.priority || 10) : 10;
  document.getElementById('rec-weight').value    = rec ? (rec.weight || 0)    : 0;
  document.getElementById('rec-port').value      = rec ? (rec.port || 1)      : 1;
  document.getElementById('rec-target').value    = rec ? (rec.target || '')   : '';
  document.getElementById('rec-ttl').value       = rec ? (rec.ttl || defTtl)  : defTtl;
  document.getElementById('rec-proxied').checked = !!(rec && rec.proxied);
  onRecTypeChange();
  openModal('record-modal');
}

// 全选
var chkAll = document.getElementById('chk-all');
if (chkAll) {
  chkAll.addEventListener('change', function() {
    document.querySelectorAll('.rec-chk').forEach(function(c){ c.checked = chkAll.checked; });
    updateCheckedCount();
  });
}
document.querySelectorAll('.rec-chk').forEach(function(c) {
  c.addEventListener('change', updateCheckedCount);
});
function getCheckedCount() {
  return document.querySelectorAll('.rec-chk:checked').length;
}
function getCheckedRecordIds() {
  return Array.from(document.querySelectorAll('.rec-chk:checked')).map(function(input) {
    return (input && input.value) ? String(input.value).trim() : '';
  }).filter(function(value) {
    return value !== '';
  });
}
function updateCheckedCount() {
  var el = document.getElementById('checked-count');
  if (el) el.textContent = '已选 ' + getCheckedCount() + ' 条';
}

var dnsImportForm = document.getElementById('dns-import-form');
if (dnsImportForm) {
  dnsImportForm.addEventListener('submit', async function(event) {
    event.preventDefault();

    var textarea = document.getElementById('dns-import-json');
    var submitBtn = document.getElementById('dns-import-submit');
    var statusEl = document.getElementById('dns-import-status');
    var originalText = submitBtn ? submitBtn.textContent : '开始导入';
    var raw = textarea ? String(textarea.value || '').trim() : '';
    if (!raw) {
      showToast('请输入导入 JSON', 'error');
      return;
    }

    var rows;
    try {
      rows = JSON.parse(raw);
    } catch (error) {
      showToast('JSON 格式错误，请检查导入内容', 'error');
      return;
    }
    if (!Array.isArray(rows) || !rows.length) {
      showToast('导入数据必须是非空数组', 'error');
      return;
    }

    if (submitBtn) {
      submitBtn.disabled = true;
      submitBtn.textContent = '导入中...';
    }
    if (statusEl) {
      statusEl.style.display = '';
      statusEl.textContent = '准备导入 ' + rows.length + ' 条记录...';
    }

    var successCount = 0;
    var failed = [];
    try {
      for (var start = 0; start < rows.length; start += DNS_IMPORT_CHUNK_SIZE) {
        var chunk = rows.slice(start, start + DNS_IMPORT_CHUNK_SIZE);
        if (statusEl) {
          statusEl.textContent = '正在导入 ' + Math.min(start + 1, rows.length) + '-' + Math.min(start + chunk.length, rows.length) + ' / ' + rows.length;
        }
        var formData = new FormData(dnsImportForm);
        formData.set('import_json', JSON.stringify(chunk));
        var response = await fetch('dns.php', {
          method: 'POST',
          headers: { 'X-Requested-With': 'XMLHttpRequest' },
          credentials: 'same-origin',
          body: formData,
        });
        var result = await response.json().catch(function() {
          return null;
        });
        if (!response.ok || !result || !result.ok) {
          throw new Error((result && result.msg) ? result.msg : ('导入失败（HTTP ' + response.status + '）'));
        }
        var data = result.data || {};
        successCount += Number(data.success_count || 0);
        failed = failed.concat(Array.isArray(data.failed) ? data.failed : []);
      }

      var failedCount = failed.length;
      showToast('导入完成：成功 ' + successCount + ' 条，失败 ' + failedCount + ' 条', failedCount > 0 ? 'warning' : 'success');
      var next = new URL(window.location.href);
      next.searchParams.set('hydrate', '1');
      next.searchParams.set('account', <?= json_encode($selectedAccountId, JSON_HEX_TAG) ?>);
      next.searchParams.set('zone', <?= json_encode($selectedZoneId, JSON_HEX_TAG) ?>);
      next.searchParams.set('zone_name', <?= json_encode($selectedZoneName, JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) ?>);
      window.location.replace(next.toString());
    } catch (error) {
      if (submitBtn) {
        submitBtn.disabled = false;
        submitBtn.textContent = originalText || '开始导入';
      }
      if (statusEl) {
        statusEl.textContent = '';
        statusEl.style.display = 'none';
      }
      showToast((error && error.message) ? error.message : '导入失败，请稍后重试', 'error');
    }
  });
}

var batchDeleteForm = document.getElementById('batch-delete-form');
if (batchDeleteForm) {
  batchDeleteForm.addEventListener('submit', async function(event) {
    event.preventDefault();

    var recordIds = getCheckedRecordIds();
    if (!recordIds.length) {
      showToast('请先选择要删除的记录', 'error');
      return;
    }
    NavConfirm.open({
      title: '批量删除记录',
      message: '确认删除选中的 ' + recordIds.length + ' 条记录吗？',
      confirmText: '删除',
      cancelText: '取消',
      danger: true,
      onConfirm: function() { doBatchDeleteRecords(recordIds); }
    });
  });
}

async function doBatchDeleteRecords(recordIds) {
  var submitBtn = document.getElementById('batch-delete-btn');
  var statusEl = document.getElementById('batch-delete-status');
  var originalText = submitBtn ? submitBtn.textContent : '删除选中';
  if (submitBtn) {
    submitBtn.disabled = true;
    submitBtn.textContent = '删除中...';
  }
  if (chkAll) chkAll.disabled = true;
  document.querySelectorAll('.rec-chk').forEach(function(input) {
    input.disabled = true;
  });

  var successCount = 0;
  var failed = [];
  try {
    for (var start = 0; start < recordIds.length; start += DNS_BATCH_DELETE_CHUNK_SIZE) {
      var chunk = recordIds.slice(start, start + DNS_BATCH_DELETE_CHUNK_SIZE);
      if (statusEl) {
        statusEl.textContent = '正在删除 ' + Math.min(start + 1, recordIds.length) + '-' + Math.min(start + chunk.length, recordIds.length) + ' / ' + recordIds.length;
      }
      var formData = new FormData(batchDeleteForm);
      formData.delete('record_ids[]');
      chunk.forEach(function(id) {
        formData.append('record_ids[]', id);
      });

      var response = await fetch('dns.php', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
        body: formData,
      });
      var result = await response.json().catch(function() {
        return null;
      });
      if (!response.ok || !result || !result.ok) {
        throw new Error((result && result.msg) ? result.msg : ('批量删除失败（HTTP ' + response.status + '）'));
      }
      var data = result.data || {};
      successCount += Number(data.success_count || 0);
      failed = failed.concat(Array.isArray(data.failed) ? data.failed : []);
    }

    var failedCount = failed.length;
    showToast('批量删除完成：成功 ' + successCount + '，失败 ' + failedCount, failedCount > 0 ? 'warning' : 'success');
    var next = new URL(window.location.href);
    next.searchParams.set('hydrate', '1');
    next.searchParams.set('account', <?= json_encode($selectedAccountId, JSON_HEX_TAG) ?>);
    next.searchParams.set('zone', <?= json_encode($selectedZoneId, JSON_HEX_TAG) ?>);
    next.searchParams.set('zone_name', <?= json_encode($selectedZoneName, JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) ?>);
    window.location.replace(next.toString());
  } catch (error) {
    if (statusEl) {
      statusEl.textContent = '';
    }
    if (submitBtn) {
      submitBtn.disabled = false;
      submitBtn.textContent = originalText || '删除选中';
    }
    if (chkAll) chkAll.disabled = false;
    document.querySelectorAll('.rec-chk').forEach(function(input) {
      input.disabled = false;
    });
    showToast((error && error.message) ? error.message : '批量删除失败，请稍后重试', 'error');
    updateCheckedCount();
  }
}

// 批量导出
var exportBtn = document.getElementById('export-btn');
if (exportBtn) {
  exportBtn.addEventListener('click', function() {
    var out = DNS_RECORDS.map(function(r) {
      var row = { name: r.name, type: r.type, value: r.value, ttl: r.ttl };
      if (r.priority) row.priority = r.priority;
      if (r.type === 'SRV') { row.weight = r.weight; row.port = r.port; row.target = r.target; }
      return row;
    });
    var blob = new Blob([JSON.stringify(out, null, 2)], { type: 'application/json' });
    var a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'dns-records-<?= htmlspecialchars($selectedZoneName) ?>.json';
    a.click();
  });
}

// zone 下拉：异步切换域名；切换瞬间清空列表并显示加载；跳转其它后台页时中止请求，避免旧列表被当成新域名
var zoneSelect = document.querySelector('.dns-domain-select');
if (zoneSelect) {
  zoneSelect.dataset.dnsPrevIdx = String(zoneSelect.selectedIndex);
  zoneSelect.addEventListener('focus', function() {
    zoneSelect.dataset.dnsPrevIdx = String(zoneSelect.selectedIndex);
  });
  zoneSelect.addEventListener('mousedown', function() {
    zoneSelect.dataset.dnsPrevIdx = String(zoneSelect.selectedIndex);
  });
  var zoneIdInput = document.createElement('input');
  zoneIdInput.type = 'hidden';
  zoneIdInput.name = 'zone';
  zoneIdInput.value = zoneSelect.options[zoneSelect.selectedIndex]
    ? (zoneSelect.options[zoneSelect.selectedIndex].getAttribute('data-zone-id') || '')
    : '';
  zoneSelect.parentNode.insertBefore(zoneIdInput, zoneSelect.nextSibling);
  zoneSelect.addEventListener('change', function() {
    var opt = zoneSelect.options[zoneSelect.selectedIndex];
    zoneIdInput.value = opt ? (opt.getAttribute('data-zone-id') || '') : '';
    if (document.getElementById('dns-records-panel')) {
      dnsZoneSwitchViaFetch(zoneSelect, zoneIdInput);
    }
  });
}

bindDnsAbortOnNavigation();
dnsAsyncHydrateIfNeeded();
onRecTypeChange();
updateCheckedCount();
</script>

<?php require_once __DIR__ . '/shared/footer.php'; ?>
