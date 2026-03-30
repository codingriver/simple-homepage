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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/shared/functions.php';
    require_once __DIR__ . '/shared/dns_lib.php';
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
        $id = preg_replace('/[^a-zA-Z0-9_-]/', '', trim((string)($_POST['id'] ?? '')));
        $provider = trim((string)($_POST['provider'] ?? 'aliyun'));
        if (!isset($catalog[$provider])) {
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
                flash_set('error', '请填写 ' . ($field['label'] ?? $fieldName));
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
        $result = dns_cli_call(['action' => 'account.verify', 'account' => $account]);
        flash_set($result['ok'] ? 'success' : 'error', $result['ok'] ? ($result['msg'] ?: '连接测试通过') : $result['msg']);
        dns_redirect_to(['account' => $accountId]);
    }

    // ── 批量导入 ──
    if ($action === 'records_import') {
        $accountId = trim((string)($_POST['account_id'] ?? ''));
        $zoneId    = trim((string)($_POST['zone_id'] ?? ''));
        $zoneName  = trim((string)($_POST['zone_name'] ?? ''));
        $account   = dns_find_account($cfg, $accountId);
        if (!$account || $zoneId === '') {
            flash_set('error', '请先选择账号与域名');
            dns_redirect_to(['account' => $accountId, 'zone' => $zoneId, 'zone_name' => $zoneName]);
        }
        $raw = trim((string)($_POST['import_json'] ?? ''));
        $rows = json_decode($raw, true);
        if (!is_array($rows)) {
            flash_set('error', 'JSON 格式错误，请检查导入内容');
            dns_redirect_to(['account' => $accountId, 'zone' => $zoneId, 'zone_name' => $zoneName]);
        }
        $zone = ['id' => $zoneId, 'name' => $zoneName];
        $ok = 0; $fail = 0;
        foreach ($rows as $row) {
            if (!is_array($row)) { $fail++; continue; }
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
            $r['ok'] ? $ok++ : $fail++;
        }
        flash_set($fail > 0 ? 'warn' : 'success', "导入完成：成功 {$ok} 条，失败 {$fail} 条");
        dns_redirect_to(['account' => $accountId, 'zone' => $zoneId, 'zone_name' => $zoneName]);
    }

    // ── 记录操作 ──
    if (in_array($action, ['record_create', 'record_update', 'record_delete', 'record_batch_delete'], true)) {
        $accountId = trim((string)($_POST['account_id'] ?? ''));
        $zoneId    = trim((string)($_POST['zone_id'] ?? ''));
        $zoneName  = trim((string)($_POST['zone_name'] ?? ''));
        $account   = dns_find_account($cfg, $accountId);
        if (!$account) { flash_set('error', '请选择有效的 DNS 账号'); dns_redirect_to(); }
        if ($zoneId === '' || $zoneName === '') {
            flash_set('error', '请选择有效的域名 Zone');
            dns_redirect_to(['account' => $accountId]);
        }
        dns_store_ui_selection($cfg, $accountId, $zoneId, $zoneName);
        save_dns_config($cfg);
        $zone = ['id' => $zoneId, 'name' => $zoneName];

        if ($action === 'record_batch_delete') {
            $recordIds = array_values(array_filter(array_map(
                fn($v) => trim((string)$v),
                is_array($_POST['record_ids'] ?? null) ? $_POST['record_ids'] : []
            )));
            if (empty($recordIds)) {
                flash_set('error', '请先选择要删除的记录');
                dns_redirect_to(['account' => $accountId, 'zone' => $zoneId, 'zone_name' => $zoneName]);
            }
            $result = dns_cli_call(['action' => 'records.delete_many', 'account' => $account, 'zone' => $zone, 'record_ids' => $recordIds]);
            if ($result['ok']) {
                $data = $result['data'] ?? [];
                $sc = (int)($data['success_count'] ?? count($recordIds));
                $fc = count($data['failed'] ?? []);
                flash_set($fc > 0 ? 'warn' : 'success', "批量删除完成：成功 {$sc}，失败 {$fc}");
            } else {
                flash_set('error', $result['msg']);
            }
            dns_redirect_to(['account' => $accountId, 'zone' => $zoneId, 'zone_name' => $zoneName]);
        }

        if ($action === 'record_delete') {
            $result = dns_cli_call(['action' => 'record.delete', 'account' => $account, 'zone' => $zone, 'record' => ['id' => trim((string)($_POST['record_id'] ?? ''))]]);
            flash_set($result['ok'] ? 'success' : 'error', $result['ok'] ? '记录已删除' : $result['msg']);
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
        dns_redirect_to(['account' => $accountId, 'zone' => $zoneId, 'zone_name' => $zoneName]);
    }

    flash_set('error', '未知操作');
    dns_redirect_to();
}

// ═══════════════════════════ GET — 渲染准备 ═══════════════════════════
$page_title = '域名解析';
require_once __DIR__ . '/shared/header.php';
require_once __DIR__ . '/shared/dns_lib.php';

$cfg      = load_dns_config();
$catalog  = dns_provider_catalog();
$accounts = $cfg['accounts'] ?? [];

// 当前激活账号
$selectedAccountId = trim((string)($_GET['account'] ?? ($cfg['ui']['selected_account_id'] ?? '')));
$selectedAccount   = $selectedAccountId !== '' ? dns_find_account($cfg, $selectedAccountId) : null;
if (!$selectedAccount && !empty($accounts)) {
    $selectedAccount   = $accounts[0];
    $selectedAccountId = (string)($selectedAccount['id'] ?? '');
}

// 加载 Zone 列表
$zones             = [];
$zonesError        = '';
$zonesEmptyMessage = '当前账号下没有可访问的 Zone。';

if ($selectedAccount) {
    $sp = (string)($selectedAccount['provider'] ?? '');
    if ($sp === 'cloudflare') {
        $zonesEmptyMessage = '当前账号没有可访问的 Zone。请确认 API Token 权限正确。';
    } elseif ($sp === 'aliyun') {
        $zonesEmptyMessage = '当前账号下没有可访问的域名。请确认 AccessKey 权限正确。';
    }
    $zonesResult = dns_cli_call(['action' => 'zones.list', 'account' => $selectedAccount]);
    if ($zonesResult['ok']) {
        $zones = $zonesResult['data']['zones'] ?? [];
    } else {
        $zonesError = $zonesResult['msg'];
    }
}

// 当前 Zone（优先 GET 参数，然后 ui 记忆）
$selectedZoneId   = trim((string)($_GET['zone']      ?? ($cfg['ui']['selected_zone_id']   ?? '')));
$selectedZoneName = trim((string)($_GET['zone_name'] ?? ($cfg['ui']['selected_zone_name'] ?? '')));
$selectedZone     = null;

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
}

// 加载解析记录
$records      = [];
$recordsError = '';

if ($selectedAccount && $selectedZone) {
    $recordsResult = dns_cli_call(['action' => 'records.list', 'account' => $selectedAccount, 'zone' => $selectedZone]);
    if ($recordsResult['ok']) {
        $records = $recordsResult['data']['records'] ?? [];
    } else {
        $recordsError = $recordsResult['msg'];
    }
    // 持久化 UI 状态
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
    <span class="dns-no-account">尚未配置 DNS 账号，请先添加账号</span>
    <?php endif; ?>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <button type="button" class="btn btn-secondary" onclick="openAccountMgr()">管理 DNS 账号</button>
    <?php if ($selectedAccount): ?>
    <form method="POST" style="margin:0">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="verify_account">
      <input type="hidden" name="account_id" value="<?= htmlspecialchars($selectedAccountId) ?>">
      <button type="submit" class="btn btn-secondary">测试连接</button>
    </form>
    <?php endif; ?>
    <button type="button" class="btn btn-primary" onclick="openAccountForm()">+ 添加账号</button>
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
      <input type="hidden" name="account" value="<?= htmlspecialchars($selectedAccountId) ?>">
      <select class="dns-domain-select" name="zone_name" onchange="document.getElementById('zone-switch-form').submit()">
        <?php foreach ($zones as $z): ?>
        <?php $zn = (string)($z['name'] ?? ''); $zi = (string)($z['id'] ?? ''); ?>
        <option value="<?= htmlspecialchars($zn) ?>" data-zone-id="<?= htmlspecialchars($zi) ?>" <?= $zi === $selectedZoneId ? 'selected' : '' ?>>
          <?= htmlspecialchars($zn) ?>
        </option>
        <?php endforeach; ?>
      </select>
    </form>
    <?php if ($selectedZone): ?>
    <span class="dns-record-count"><?= count($records) ?> 条记录</span>
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

<div class="card">
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

  <form method="POST" id="batch-delete-form" onsubmit="return confirm('确认删除选中的 ' + getCheckedCount() + ' 条记录吗？')">
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
              <form method="POST" onsubmit="return confirm('确认删除这条记录吗？')">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="record_delete">
                <input type="hidden" name="account_id" value="<?= htmlspecialchars($selectedAccountId) ?>">
                <input type="hidden" name="zone_id" value="<?= htmlspecialchars($selectedZoneId) ?>">
                <input type="hidden" name="zone_name" value="<?= htmlspecialchars($selectedZoneName) ?>">
                <input type="hidden" name="record_id" value="<?= htmlspecialchars($rid) ?>">
                <button type="submit" class="btn btn-sm btn-danger">删除</button>
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
      <button type="submit" form="batch-delete-form" class="btn btn-danger btn-sm">删除选中</button>
      <span id="checked-count" style="font-size:12px;color:var(--tm)">已选 0 条</span>
    </div>
    <span style="font-size:12px;color:var(--tm)">显示 <?= count($filteredRecords) ?> / <?= count($records) ?> 条</span>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php else: ?>
<div class="card">
  <div class="dns-empty-state">
    <strong><?= !$selectedAccount ? '尚未配置账号' : '请选择域名' ?></strong>
    <?= !$selectedAccount ? '请点击「管理 DNS 账号」添加账号。' : '在上方下拉菜单中选择一个域名，即可管理其解析记录。' ?>
  </div>
</div>
<?php endif; ?>

<!-- ═══ 账号管理弹窗 ═══ -->
<div id="account-mgr-modal" class="dns-modal" onclick="if(event.target===this)closeModal('account-mgr-modal')">
  <div class="dns-modal-card">
    <div class="dns-modal-head">
      <span class="dns-modal-title">DNS 账号管理</span>
      <button class="dns-modal-close" onclick="closeModal('account-mgr-modal')">×</button>
    </div>
    <div class="dns-modal-body">
      <?php if (empty($accounts)): ?>
      <div class="dns-empty-state" style="padding:18px"><strong>暂无账号</strong>点击「添加账号」开始添加</div>
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
            <a href="dns.php?<?= htmlspecialchars(http_build_query(['account'=>$aid])) ?>" class="btn btn-sm btn-secondary" onclick="closeModal('account-mgr-modal')">切换</a>
            <button type="button" class="btn btn-sm btn-secondary" onclick="openAccountForm('<?= htmlspecialchars($aid) ?>')">编辑</button>
            <form method="POST" onsubmit="return confirm('确认删除该账号吗？')">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete_account">
              <input type="hidden" name="account_id" value="<?= htmlspecialchars($aid) ?>">
              <button type="submit" class="btn btn-sm btn-danger">删除</button>
            </form>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
      <hr class="dns-divider">
      <button type="button" class="btn btn-primary" onclick="openAccountForm()">+ 添加账号</button>
    </div>
  </div>
</div>

<!-- ═══ 账号管理弹窗 ═══ -->
<div id="account-mgr-modal" class="dns-modal" onclick="if(event.target===this)closeModal('account-mgr-modal')">
  <div class="dns-modal-card">
    <div class="dns-modal-head">
      <span class="dns-modal-title">DNS 账号管理</span>
      <button class="dns-modal-close" onclick="closeModal('account-mgr-modal')">×</button>
    </div>
    <div class="dns-modal-body">
      <?php if (empty($accounts)): ?>
      <div class="dns-empty-state" style="padding:18px"><strong>暂无账号</strong>点击「添加账号」开始添加</div>
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
            <a href="dns.php?<?= htmlspecialchars(http_build_query(['account'=>$aid])) ?>" class="btn btn-sm btn-secondary" onclick="closeModal('account-mgr-modal')">切换</a>
            <button type="button" class="btn btn-sm btn-secondary" onclick="openAccountForm('<?= htmlspecialchars($aid) ?>')">编辑</button>
            <form method="POST" onsubmit="return confirm('确认删除该账号吗？')">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete_account">
              <input type="hidden" name="account_id" value="<?= htmlspecialchars($aid) ?>">
              <button type="submit" class="btn btn-sm btn-danger">删除</button>
            </form>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
      <hr class="dns-divider">
      <button type="button" class="btn btn-primary" onclick="openAccountForm()">+ 添加账号</button>
    </div>
  </div>
</div>

<!-- ═══ 账号表单弹窗 ═══ -->
<div id="account-form-modal" class="dns-modal" onclick="if(event.target===this)closeModal('account-form-modal')">
  <div class="dns-modal-card">
    <div class="dns-modal-head">
      <span class="dns-modal-title" id="acct-form-title">添加 DNS 账号</span>
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
        <div class="dns-tip">密码类字段留空则保持原值不变。</div>
        <div class="form-actions">
          <button type="submit" class="btn btn-primary">保存账号</button>
          <button type="button" class="btn btn-secondary" onclick="closeModal('account-form-modal')">取消</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ═══ 记录编辑弹窗 ═══ -->
<div id="record-modal" class="dns-modal" onclick="if(event.target===this)closeModal('record-modal')">
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
<div id="import-modal" class="dns-modal" onclick="if(event.target===this)closeModal('import-modal')">
  <div class="dns-modal-card">
    <div class="dns-modal-head">
      <span class="dns-modal-title">批量导入解析记录</span>
      <button class="dns-modal-close" onclick="closeModal('import-modal')">×</button>
    </div>
    <div class="dns-modal-body">
      <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="records_import">
        <input type="hidden" name="account_id" value="<?= htmlspecialchars($selectedAccountId) ?>">
        <input type="hidden" name="zone_id" value="<?= htmlspecialchars($selectedZoneId) ?>">
        <input type="hidden" name="zone_name" value="<?= htmlspecialchars($selectedZoneName) ?>">
        <div class="form-group">
          <label>JSON 数据</label>
          <textarea name="import_json" rows="12" style="font-family:var(--mono);font-size:12px" placeholder='[&#10;  {"name":"@","type":"A","value":"1.2.3.4","ttl":600},&#10;  {"name":"www","type":"CNAME","value":"example.com","ttl":600}&#10;]'></textarea>
        </div>
        <div class="dns-tip">每条记录须含 <code>name</code>、<code>type</code>、<code>value</code>。MX/SRV 可附加 <code>priority</code>。</div>
        <div class="form-actions">
          <button type="submit" class="btn btn-primary">开始导入</button>
          <button type="button" class="btn btn-secondary" onclick="closeModal('import-modal')">取消</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ═══ 记录编辑弹窗 ═══ -->
<div id="record-modal" class="dns-modal" onclick="if(event.target===this)closeModal('record-modal')">
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
            <span class="form-hint">Cloudflare 使用 1 表示自动 TTL</span>
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
<div id="import-modal" class="dns-modal" onclick="if(event.target===this)closeModal('import-modal')">
  <div class="dns-modal-card">
    <div class="dns-modal-head">
      <span class="dns-modal-title">批量导入解析记录</span>
      <button class="dns-modal-close" onclick="closeModal('import-modal')">×</button>
    </div>
    <div class="dns-modal-body">
      <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="records_import">
        <input type="hidden" name="account_id" value="<?= htmlspecialchars($selectedAccountId) ?>">
        <input type="hidden" name="zone_id" value="<?= htmlspecialchars($selectedZoneId) ?>">
        <input type="hidden" name="zone_name" value="<?= htmlspecialchars($selectedZoneName) ?>">
        <div class="form-group">
          <label>JSON 数据</label>
          <textarea name="import_json" rows="12" style="font-family:var(--mono);font-size:12px" placeholder='[&#10;  {"name":"@","type":"A","value":"1.2.3.4","ttl":600},&#10;  {"name":"www","type":"CNAME","value":"example.com","ttl":600}&#10;]'></textarea>
        </div>
        <div class="dns-tip">每条记录须含 <code>name</code>、<code>type</code>、<code>value</code>。MX/SRV 可附加 <code>priority</code>。</div>
        <div class="form-actions">
          <button type="submit" class="btn btn-primary">开始导入</button>
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

function closeModal(id){ document.getElementById(id).classList.remove('open'); }
function openModal(id) { document.getElementById(id).classList.add('open'); }
function openAccountMgr()  { openModal('account-mgr-modal'); }
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
  document.getElementById('acct-form-title').textContent    = acct ? '编辑 DNS 账号' : '添加 DNS 账号';
  document.getElementById('acct-id').value                  = acct ? (acct.id || '') : '';
  document.getElementById('acct-name').value                = acct ? (acct.name || '') : '';
  document.getElementById('acct-provider').value            = acct ? (acct.provider || 'aliyun') : 'aliyun';
  renderCredFields();
  closeModal('account-mgr-modal');
  openModal('account-form-modal');
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
function updateCheckedCount() {
  var el = document.getElementById('checked-count');
  if (el) el.textContent = '已选 ' + getCheckedCount() + ' 条';
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

// zone 下拉同步 zone_id
var zoneSelect = document.querySelector('.dns-domain-select');
if (zoneSelect) {
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
  });
}

onRecTypeChange();
updateCheckedCount();
</script>

<?php require_once __DIR__ . '/shared/footer.php'; ?>