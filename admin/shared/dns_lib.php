<?php
/**
 * 多厂商 DNS 配置与 Python CLI 桥接
 */
require_once __DIR__ . '/../../shared/auth.php';

define('DNS_CONFIG_FILE', DATA_DIR . '/dns_config.json');
define('DNS_LOG_FILE', DATA_DIR . '/logs/dns.log');
define('DNS_PYTHON_LOG_FILE', DATA_DIR . '/logs/dns_python.log');

function dns_log_write(string $channel, string $level, string $message, array $context = []): void {
    $path = $channel === 'python' ? DNS_PYTHON_LOG_FILE : DNS_LOG_FILE;
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    $line = '[' . date('Y-m-d H:i:s') . ']'
        . ' [' . strtoupper($channel) . ']'
        . ' [' . strtoupper($level) . '] '
        . $message;

    if (!empty($context)) {
        $line .= ' | ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    @file_put_contents($path, $line . "\n", FILE_APPEND | LOCK_EX);
}
function dns_provider_catalog(): array {
    return [
        'aliyun' => [
            'label' => 'Aliyun DNS',
            'badge' => 'badge-yellow',
            'supports_proxied' => false,
            'credential_fields' => [
                [
                    'name' => 'access_key_id',
                    'label' => 'AccessKey ID',
                    'type' => 'text',
                    'required' => true,
                    'placeholder' => 'LTAI...',
                ],
                [
                    'name' => 'access_key_secret',
                    'label' => 'AccessKey Secret',
                    'type' => 'password',
                    'required' => true,
                    'placeholder' => '留空则保持不变',
                ],
            ],
        ],
        'cloudflare' => [
            'label' => 'Cloudflare',
            'badge' => 'badge-blue',
            'supports_proxied' => true,
            'credential_fields' => [
                [
                    'name' => 'api_token',
                    'label' => 'API Token（无需邮箱）',
                    'type' => 'password',
                    'required' => true,
                    'placeholder' => '留空则保持不变',
                    'help' => '当前使用 Cloudflare API Token 接入，只需填写 Token 本体，不需要邮箱，也不要带 Bearer 前缀或换行；至少需要 Zone Read 权限。若域名列表只显示 1 个但你实际有多个域名，请在 Cloudflare Token 的 Zone Resources 中改为 Include: All zones。编辑解析记录还需要 DNS Read / DNS Write 权限。',
                ],
            ],
        ],
    ];
}

function dns_config_defaults(): array {
    return [
        'version' => 2,
        'accounts' => [],
        'ui' => [
            'selected_account_id' => '',
            'selected_zone_id' => '',
            'selected_zone_name' => '',
        ],
    ];
}

function dns_make_account_id(): string {
    return 'dns_' . bin2hex(random_bytes(8));
}

function dns_provider_label(string $provider): string {
    $catalog = dns_provider_catalog();
    return $catalog[$provider]['label'] ?? strtoupper($provider);
}

function dns_provider_badge_class(string $provider): string {
    $catalog = dns_provider_catalog();
    return $catalog[$provider]['badge'] ?? 'badge-gray';
}

function dns_provider_supports_proxied(string $provider): bool {
    $catalog = dns_provider_catalog();
    return !empty($catalog[$provider]['supports_proxied']);
}

function dns_account_defaults(): array {
    return [
        'id' => '',
        'provider' => 'aliyun',
        'name' => '',
        'credentials' => [],
        'created_at' => '',
        'updated_at' => '',
    ];
}

function dns_is_v2_config(array $raw): bool {
    return isset($raw['version'], $raw['accounts']) && is_array($raw['accounts']);
}

function dns_migrate_legacy_config(array $raw): array {
    $cfg = dns_config_defaults();
    $ak = trim((string)($raw['access_key_id'] ?? ''));
    $sk = (string)($raw['access_key_secret'] ?? '');
    $domain = trim((string)($raw['domain_name'] ?? ''));
    if ($ak === '' && $sk === '' && $domain === '') {
        return $cfg;
    }
    $id = 'dns_legacy_aliyun';
    $stamp = trim((string)($raw['last_sync_at'] ?? '')) ?: date('Y-m-d H:i:s');
    $cfg['accounts'][] = [
        'id' => $id,
        'provider' => 'aliyun',
        'name' => 'Aliyun DNS（迁移）',
        'credentials' => [
            'access_key_id' => $ak,
            'access_key_secret' => $sk,
        ],
        'created_at' => $stamp,
        'updated_at' => $stamp,
    ];
    $cfg['ui']['selected_account_id'] = $id;
    $cfg['ui']['selected_zone_id'] = $domain;
    $cfg['ui']['selected_zone_name'] = $domain;
    return $cfg;
}

function dns_normalize_config(array $raw): array {
    $cfg = dns_is_v2_config($raw) ? ($raw + dns_config_defaults()) : dns_migrate_legacy_config($raw);
    $catalog = dns_provider_catalog();
    $accounts = [];
    foreach (($cfg['accounts'] ?? []) as $account) {
        if (!is_array($account)) {
            continue;
        }
        $row = $account + dns_account_defaults();
        $provider = (string)($row['provider'] ?? '');
        if (!isset($catalog[$provider])) {
            continue;
        }
        $row['id'] = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($row['id'] ?? ''));
        if ($row['id'] === '') {
            $row['id'] = dns_make_account_id();
        }
        $row['name'] = trim((string)($row['name'] ?? '')) ?: dns_provider_label($provider);
        $row['credentials'] = is_array($row['credentials']) ? $row['credentials'] : [];
        $row['created_at'] = trim((string)($row['created_at'] ?? ''));
        $row['updated_at'] = trim((string)($row['updated_at'] ?? ''));
        $accounts[] = $row;
    }
    $cfg['version'] = 2;
    $cfg['accounts'] = array_values($accounts);
    $cfg['ui'] = is_array($cfg['ui'] ?? null) ? ($cfg['ui'] + dns_config_defaults()['ui']) : dns_config_defaults()['ui'];
    return $cfg;
}

function load_dns_config(): array {
    if (!file_exists(DNS_CONFIG_FILE)) {
        return dns_config_defaults();
    }
    $raw = json_decode((string)file_get_contents(DNS_CONFIG_FILE), true);
    if (!is_array($raw)) {
        return dns_config_defaults();
    }
    return dns_normalize_config($raw);
}

function save_dns_config(array $cfg): void {
    $cfg = dns_normalize_config($cfg);
    file_put_contents(
        DNS_CONFIG_FILE,
        json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );
}

function dns_find_account(array $cfg, string $accountId): ?array {
    foreach (($cfg['accounts'] ?? []) as $account) {
        if (($account['id'] ?? '') === $accountId) {
            return $account;
        }
    }
    return null;
}

function dns_upsert_account(array &$cfg, array $account): string {
    $account = dns_normalize_config(['version' => 2, 'accounts' => [$account], 'ui' => []])['accounts'][0] ?? dns_account_defaults();
    $account['updated_at'] = date('Y-m-d H:i:s');
    if (($account['created_at'] ?? '') === '') {
        $account['created_at'] = $account['updated_at'];
    }

    $found = false;
    foreach ($cfg['accounts'] as &$row) {
        if (($row['id'] ?? '') === ($account['id'] ?? '')) {
            $row = $account;
            $found = true;
            break;
        }
    }
    unset($row);
    if (!$found) {
        $cfg['accounts'][] = $account;
    }
    return (string)$account['id'];
}

function dns_delete_account(array &$cfg, string $accountId): bool {
    $before = count($cfg['accounts'] ?? []);
    $cfg['accounts'] = array_values(array_filter(
        $cfg['accounts'] ?? [],
        fn($account) => (($account['id'] ?? '') !== $accountId)
    ));
    if (($cfg['ui']['selected_account_id'] ?? '') === $accountId) {
        $cfg['ui']['selected_account_id'] = '';
        $cfg['ui']['selected_zone_id'] = '';
        $cfg['ui']['selected_zone_name'] = '';
    }
    return count($cfg['accounts']) !== $before;
}

function dns_store_ui_selection(array &$cfg, string $accountId, string $zoneId, string $zoneName): void {
    $cfg['ui']['selected_account_id'] = $accountId;
    $cfg['ui']['selected_zone_id'] = $zoneId;
    $cfg['ui']['selected_zone_name'] = $zoneName;
}

function dns_cli_script_path(): string {
    return realpath(__DIR__ . '/../../python/dns_core.py') ?: (__DIR__ . '/../../python/dns_core.py');
}

function dns_cli_call(array $payload): array {
    $script = dns_cli_script_path();
    $action = (string)($payload['action'] ?? 'unknown');
    $startedAt = microtime(true);

    dns_log_write('app', 'info', 'DNS CLI call start', [
        'action' => $action,
        'account_id' => (string)($payload['account']['id'] ?? ''),
        'zone_id' => (string)($payload['zone']['id'] ?? ''),
        'zone_name' => (string)($payload['zone']['name'] ?? ''),
    ]);

    $cmd = ['python3', $script];
    $desc = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $env = [
        'PYTHONUNBUFFERED' => '1',
        'PATH' => getenv('PATH') ?: '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
    ];
    $proc = proc_open($cmd, $desc, $pipes, dirname($script), $env);
    if (!is_resource($proc)) {
        dns_log_write('app', 'error', 'Unable to start Python DNS process', [
            'action' => $action,
            'script' => $script,
        ]);
        return ['ok' => false, 'msg' => '无法启动 Python DNS 核心进程'];
    }

    fwrite($pipes[0], json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);

    $stdout = trim((string)$stdout);
    $stderr = trim((string)$stderr);
    $durationMs = (int)round((microtime(true) - $startedAt) * 1000);

    dns_log_write('python', 'info', 'Python process finished', [
        'action' => $action,
        'exit_code' => $code,
        'duration_ms' => $durationMs,
        'stdout_length' => strlen($stdout),
        'stderr_length' => strlen($stderr),
    ]);

    if ($stderr !== '') {
        dns_log_write('python', 'error', 'Python stderr output', [
            'action' => $action,
            'exit_code' => $code,
            'duration_ms' => $durationMs,
            'stderr' => $stderr,
        ]);
    }

    if ($code !== 0 && $stdout === '') {
        $msg = $stderr !== '' ? $stderr : ('DNS 核心退出码 ' . $code);
        dns_log_write('app', 'error', 'DNS CLI call failed (no stdout)', [
            'action' => $action,
            'exit_code' => $code,
            'duration_ms' => $durationMs,
            'message' => $msg,
        ]);
        return ['ok' => false, 'msg' => $msg];
    }

    $json = json_decode($stdout, true);
    if (!is_array($json)) {
        $msg = $stderr !== '' ? $stderr : 'DNS 核心返回了无效 JSON';
        dns_log_write('app', 'error', 'DNS CLI returned invalid JSON', [
            'action' => $action,
            'exit_code' => $code,
            'duration_ms' => $durationMs,
            'stdout' => $stdout,
            'message' => $msg,
        ]);
        return ['ok' => false, 'msg' => $msg];
    }
    if (!isset($json['ok'])) {
        dns_log_write('app', 'error', 'DNS CLI response missing ok field', [
            'action' => $action,
            'exit_code' => $code,
            'duration_ms' => $durationMs,
            'stdout_json' => $json,
        ]);
        return ['ok' => false, 'msg' => 'DNS 核心响应缺少状态字段'];
    }
    if (!$json['ok']) {
        $msg = (string)($json['msg'] ?? 'DNS 操作失败');
        dns_log_write('app', 'error', 'DNS CLI returned business failure', [
            'action' => $action,
            'exit_code' => $code,
            'duration_ms' => $durationMs,
            'message' => $msg,
            'response_data' => $json['data'] ?? null,
        ]);
        return ['ok' => false, 'msg' => $msg];
    }

    dns_log_write('app', 'info', 'DNS CLI call success', [
        'action' => $action,
        'exit_code' => $code,
        'duration_ms' => $durationMs,
    ]);

    return [
        'ok' => true,
        'msg' => (string)($json['msg'] ?? ''),
        'data' => is_array($json['data'] ?? null) ? $json['data'] : [],
    ];
}

function dns_mask_secret(string $value): string {
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    if (mb_strlen($value) <= 8) {
        return str_repeat('*', max(4, mb_strlen($value)));
    }
    return mb_substr($value, 0, 4) . str_repeat('*', max(4, mb_strlen($value) - 8)) . mb_substr($value, -4);
}
