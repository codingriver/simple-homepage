<?php
/**
 * 本机 DNS HTTP API：域名自动匹配 Zone、upsert（无则创建）
 */
declare(strict_types=1);

require_once __DIR__ . '/dns_lib.php';

define('DNS_API_ZONES_CACHE_FILE', DATA_DIR . '/dns_zones_cache.json');
define('DNS_API_ZONES_CACHE_TTL', 600);
define('DNS_API_BATCH_MAX', 100);

function dns_api_invalidate_zones_cache(): void {
    if (is_file(DNS_API_ZONES_CACHE_FILE)) {
        @unlink(DNS_API_ZONES_CACHE_FILE);
    }
}

function dns_api_is_localhost(): bool {
    $ips = [];
    foreach (['REMOTE_ADDR', 'SERVER_ADDR'] as $key) {
        $value = trim((string)($_SERVER[$key] ?? ''));
        if ($value !== '') {
            $ips[] = $value;
        }
    }
    $forwarded = trim((string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
    if ($forwarded !== '') {
        foreach (explode(',', $forwarded) as $part) {
            $ip = trim($part);
            if ($ip !== '') {
                $ips[] = $ip;
            }
        }
    }
    foreach ($ips as $ip) {
        if ($ip === '127.0.0.1' || $ip === '::1' || $ip === '192.168.65.1') {
            return true;
        }
    }
    return false;
}

/**
 * 合并 GET 与 POST/JSON 正文（POST 字段覆盖同名 GET，便于调试时在 URL 带 action）。
 * @return array<string, mixed>
 */
function dns_api_get_merged_input(): array {
    $get = is_array($_GET) ? $_GET : [];
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method !== 'POST') {
        return $get;
    }
    $ct = (string)($_SERVER['CONTENT_TYPE'] ?? '');
    if (stripos($ct, 'application/json') !== false) {
        $raw = (string)file_get_contents('php://input');
        $j = json_decode($raw ?: '[]', true);
        if (is_array($j)) {
            return array_merge($get, $j);
        }
        return $get;
    }
    return array_merge($get, is_array($_POST) ? $_POST : []);
}

/** @deprecated 使用 dns_api_get_merged_input */
function dns_api_read_input(): array {
    return dns_api_get_merged_input();
}

/** @return list<array{account_id:string,provider:string,account:array,zone:array}> */
function dns_api_load_cached_zones_flat(): array {
    if (!is_file(DNS_API_ZONES_CACHE_FILE)) {
        return [];
    }
    $raw = json_decode((string)file_get_contents(DNS_API_ZONES_CACHE_FILE), true);
    if (!is_array($raw) || !isset($raw['zones']) || !is_array($raw['zones'])) {
        return [];
    }
    return $raw['zones'];
}

function dns_api_refresh_zones_cache(bool $force): void {
    $now = time();
    if (!$force && is_file(DNS_API_ZONES_CACHE_FILE)) {
        $raw = json_decode((string)file_get_contents(DNS_API_ZONES_CACHE_FILE), true);
        $updated = is_array($raw) ? (int)($raw['updated_at'] ?? 0) : 0;
        if ($updated > 0 && ($now - $updated) < DNS_API_ZONES_CACHE_TTL) {
            return;
        }
    }

    $cfg = load_dns_config();
    $accounts = is_array($cfg['accounts'] ?? null) ? $cfg['accounts'] : [];
    $preferredId = trim((string)($cfg['ui']['selected_account_id'] ?? ''));
    if ($preferredId !== '') {
        usort($accounts, static function ($a, $b) use ($preferredId): int {
            $aid = (string)($a['id'] ?? '');
            $bid = (string)($b['id'] ?? '');
            if ($aid === $bid) {
                return 0;
            }
            if ($aid === $preferredId) {
                return -1;
            }
            if ($bid === $preferredId) {
                return 1;
            }
            return 0;
        });
    }

    $flat = [];
    $reachable = [];
    foreach ($accounts as $account) {
        if (!is_array($account)) {
            continue;
        }
        $r = dns_cli_call(['action' => 'zones.list', 'account' => $account]);
        if (!$r['ok']) {
            dns_log_write('app', 'warn', 'DNS API zones.list failed', [
                'account_id' => (string)($account['id'] ?? ''),
                'msg' => (string)($r['msg'] ?? ''),
            ]);
            continue;
        }
        $reachable[] = (string)($account['id'] ?? '');
        foreach ($r['data']['zones'] ?? [] as $z) {
            if (!is_array($z)) {
                continue;
            }
            $zname = trim((string)($z['name'] ?? ''));
            if ($zname === '') {
                continue;
            }
            $flat[] = [
                'account_id' => (string)($account['id'] ?? ''),
                'provider'   => (string)($account['provider'] ?? ''),
                'account'    => $account,
                'zone'       => $z,
            ];
        }
    }

    usort($flat, static function ($a, $b): int {
        $la = strlen((string)($a['zone']['name'] ?? ''));
        $lb = strlen((string)($b['zone']['name'] ?? ''));
        return $lb <=> $la;
    });

    $dir = dirname(DNS_API_ZONES_CACHE_FILE);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    file_put_contents(
        DNS_API_ZONES_CACHE_FILE,
        json_encode([
            'updated_at' => $now,
            'reachable_account_ids' => $reachable,
            'zones' => $flat,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );
}

/**
 * @param list<array{account_id:string,provider:string,account:array,zone:array}> $flat
 * @return ?array{account:array, zone:array, record_name:string}
 */
function dns_api_parse_fqdn_to_zone(string $fqdn, array $flat): ?array {
    $fqdn = strtolower(rtrim(trim($fqdn), '.'));
    if ($fqdn === '') {
        return null;
    }
    foreach ($flat as $entry) {
        $zname = strtolower(trim((string)($entry['zone']['name'] ?? '')));
        if ($zname === '') {
            continue;
        }
        if ($fqdn === $zname) {
            return [
                'account'     => $entry['account'],
                'zone'        => $entry['zone'],
                'record_name' => '@',
            ];
        }
        $suffix = '.' . $zname;
        if (strlen($fqdn) > strlen($suffix) && str_ends_with($fqdn, $suffix)) {
            $rel = substr($fqdn, 0, -strlen($suffix));
            return [
                'account'     => $entry['account'],
                'zone'        => $entry['zone'],
                'record_name' => $rel === '' ? '@' : $rel,
            ];
        }
    }
    return null;
}

/** @return ?array{account:array, zone:array, record_name:string} */
function dns_api_resolve_domain(string $domain): ?array {
    dns_api_refresh_zones_cache(false);
    $flat = dns_api_load_cached_zones_flat();
    $first = dns_api_parse_fqdn_to_zone($domain, $flat);
    if ($first !== null) {
        return $first;
    }
    dns_api_refresh_zones_cache(true);
    $flat = dns_api_load_cached_zones_flat();
    return dns_api_parse_fqdn_to_zone($domain, $flat);
}

function dns_api_infer_type(string $value): string {
    $v = trim($value);
    if ($v === '') {
        return 'A';
    }
    if (filter_var($v, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        return 'A';
    }
    if (filter_var($v, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        return 'AAAA';
    }
    return 'CNAME';
}

function dns_api_validate_value_for_type(string $type, string $value): ?string {
    $type = strtoupper($type);
    if ($type === 'A') {
        if (!filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return 'A 记录值须为 IPv4 地址';
        }
    } elseif ($type === 'AAAA') {
        if (!filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return 'AAAA 记录值须为 IPv6 地址';
        }
    } elseif ($type === 'CNAME') {
        if (filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6)) {
            return 'CNAME 记录值不能为 IP，请使用 A/AAAA';
        }
    }
    return null;
}

function dns_api_normalize_ttl(array $account, int $ttl): int {
    $provider = (string)($account['provider'] ?? '');
    if ($provider === 'aliyun') {
        return max(600, min($ttl, 86400));
    }
    if ($provider === 'cloudflare') {
        if ($ttl <= 1) {
            return 1;
        }
        return max(60, min($ttl, 86400));
    }
    return max(60, min($ttl, 86400));
}

/** @return array{code:int,msg:string,data?:array} */
function dns_api_upsert(string $domain, string $value, ?string $type, ?int $ttl): array {
    $domain = trim($domain);
    $value = trim($value);
    if ($domain === '' || $value === '') {
        return ['code' => -1, 'msg' => 'domain 与 value 不能为空'];
    }

    $rtype = ($type !== null && $type !== '')
        ? strtoupper(trim($type))
        : dns_api_infer_type($value);

    if (!in_array($rtype, ['A', 'AAAA', 'CNAME'], true)) {
        return ['code' => -1, 'msg' => '不支持的记录类型: ' . $rtype . '（支持 A / AAAA / CNAME）'];
    }

    $verr = dns_api_validate_value_for_type($rtype, $value);
    if ($verr !== null) {
        return ['code' => -1, 'msg' => $verr];
    }

    $parsed = dns_api_resolve_domain($domain);
    if ($parsed === null) {
        return ['code' => -1, 'msg' => '域名未匹配到任何已配置的 DNS 账号下的 Zone'];
    }

    $account = $parsed['account'];
    $zone = $parsed['zone'];
    $recordName = $parsed['record_name'];

    $recordsRes = dns_cli_call([
        'action'  => 'records.list',
        'account' => $account,
        'zone'    => $zone,
    ]);
    if (!$recordsRes['ok']) {
        return ['code' => -1, 'msg' => '读取解析记录失败: ' . $recordsRes['msg']];
    }

    $records = $recordsRes['data']['records'] ?? [];
    $match = null;
    foreach ($records as $rec) {
        if (!is_array($rec)) {
            continue;
        }
        $n = (string)($rec['name'] ?? '');
        $t = strtoupper((string)($rec['type'] ?? ''));
        if (strcasecmp($n, $recordName) === 0 && $t === $rtype) {
            $match = $rec;
            break;
        }
    }

    $defaultTtl = (($account['provider'] ?? '') === 'cloudflare') ? 1 : 600;
    $useTtl = $ttl !== null && $ttl > 0 ? $ttl : ($match !== null ? (int)($match['ttl'] ?? $defaultTtl) : $defaultTtl);
    if ($useTtl <= 0) {
        $useTtl = $defaultTtl;
    }
    $useTtl = dns_api_normalize_ttl($account, $useTtl);

    if ($match !== null) {
        $currentVal = trim((string)($match['value'] ?? ''));
        if ($currentVal === $value) {
            dns_log_write('app', 'info', 'DNS API upsert skip (unchanged)', [
                'domain' => $domain,
                'type'   => $rtype,
            ]);
            return [
                'code' => 0,
                'msg'  => 'ok，记录值未变化，跳过',
                'data' => ['action' => 'skip', 'fqdn' => $domain, 'type' => $rtype],
            ];
        }

        $payload = [
            'action'  => 'record.update',
            'account' => $account,
            'zone'    => $zone,
            'record'  => [
                'id'        => (string)($match['id'] ?? ''),
                'old_type'  => $rtype,
                'name'      => $recordName,
                'type'      => $rtype,
                'value'     => $value,
                'ttl'       => $useTtl,
            ],
        ];
        if (dns_provider_supports_proxied((string)$account['provider']) && array_key_exists('proxied', $match)) {
            $payload['record']['proxied'] = (bool)$match['proxied'];
        }
        $r = dns_cli_call($payload);
        if (!$r['ok']) {
            return ['code' => -1, 'msg' => '更新失败: ' . $r['msg']];
        }
        dns_log_write('app', 'info', 'DNS API upsert updated', ['domain' => $domain, 'type' => $rtype]);
        return [
            'code' => 0,
            'msg'  => 'ok，已更新',
            'data' => ['action' => 'update', 'fqdn' => $domain, 'type' => $rtype],
        ];
    }

    $payload = [
        'action'  => 'record.create',
        'account' => $account,
        'zone'    => $zone,
        'record'  => [
            'name'  => $recordName,
            'type'  => $rtype,
            'value' => $value,
            'ttl'   => $useTtl,
        ],
    ];
    if (dns_provider_supports_proxied((string)$account['provider']) && in_array($rtype, ['A', 'AAAA', 'CNAME'], true)) {
        $payload['record']['proxied'] = false;
    }
    $r = dns_cli_call($payload);
    if (!$r['ok']) {
        return ['code' => -1, 'msg' => '创建失败: ' . $r['msg']];
    }
    dns_log_write('app', 'info', 'DNS API upsert created', ['domain' => $domain, 'type' => $rtype]);
    return [
        'code' => 0,
        'msg'  => 'ok，已创建',
        'data' => ['action' => 'create', 'fqdn' => $domain, 'type' => $rtype],
    ];
}

/**
 * 查询某 FQDN 在当前 Zone 下已配置的 A/AAAA/CNAME（只读，不修改）。
 * @return array{code:int,msg:string,data?:array}
 */
function dns_api_query(string $domain, ?string $type): array {
    $domain = trim($domain);
    if ($domain === '') {
        return ['code' => -1, 'msg' => 'domain 不能为空'];
    }
    $rtypeFilter = ($type !== null && $type !== '') ? strtoupper(trim($type)) : null;
    if ($rtypeFilter !== null && !in_array($rtypeFilter, ['A', 'AAAA', 'CNAME'], true)) {
        return ['code' => -1, 'msg' => 'type 仅支持 A / AAAA / CNAME'];
    }

    $parsed = dns_api_resolve_domain($domain);
    if ($parsed === null) {
        return ['code' => -1, 'msg' => '域名未匹配到任何已配置的 DNS 账号下的 Zone'];
    }

    $account = $parsed['account'];
    $zone = $parsed['zone'];
    $recordName = $parsed['record_name'];

    $recordsRes = dns_cli_call([
        'action'  => 'records.list',
        'account' => $account,
        'zone'    => $zone,
    ]);
    if (!$recordsRes['ok']) {
        return ['code' => -1, 'msg' => '读取解析记录失败: ' . $recordsRes['msg']];
    }

    $records = $recordsRes['data']['records'] ?? [];
    $matches = [];
    foreach ($records as $rec) {
        if (!is_array($rec)) {
            continue;
        }
        $n = (string)($rec['name'] ?? '');
        $t = strtoupper((string)($rec['type'] ?? ''));
        if (strcasecmp($n, $recordName) !== 0) {
            continue;
        }
        if (!in_array($t, ['A', 'AAAA', 'CNAME'], true)) {
            continue;
        }
        if ($rtypeFilter !== null && $t !== $rtypeFilter) {
            continue;
        }
        $row = [
            'id'    => (string)($rec['id'] ?? ''),
            'type'  => $t,
            'value' => trim((string)($rec['value'] ?? '')),
            'ttl'   => (int)($rec['ttl'] ?? 0),
        ];
        if (array_key_exists('proxied', $rec) && $rec['proxied'] !== null) {
            $row['proxied'] = (bool)$rec['proxied'];
        }
        $matches[] = $row;
    }

    return [
        'code' => 0,
        'msg'  => 'ok',
        'data' => [
            'fqdn'        => $domain,
            'zone'        => (string)($zone['name'] ?? ''),
            'record_name' => $recordName,
            'matches'     => $matches,
            'records'     => $matches,
        ],
    ];
}

/** @param array<string, mixed> $input @return array{code:int,msg:string,results:list<array<string,mixed>>} */
function dns_api_batch_update(array $input): array {
    $defaultValue = trim((string)($input['value'] ?? ''));
    $defaultTtl = isset($input['ttl']) ? (int)$input['ttl'] : null;
    if ($defaultTtl !== null && $defaultTtl <= 0) {
        $defaultTtl = null;
    }

    $domains = $input['domains'] ?? null;
    if (is_string($domains)) {
        $domains = array_values(array_filter(array_map('trim', explode(',', $domains)), static fn ($s) => $s !== ''));
    }
    if (!is_array($domains)) {
        return ['code' => -1, 'msg' => 'domains 须为数组或逗号分隔字符串', 'results' => []];
    }
    if (count($domains) > DNS_API_BATCH_MAX) {
        return ['code' => -1, 'msg' => '单次最多 ' . DNS_API_BATCH_MAX . ' 条域名', 'results' => []];
    }
    if (count($domains) === 0) {
        return ['code' => -1, 'msg' => 'domains 不能为空', 'results' => []];
    }

    $results = [];
    $anyFail = false;
    foreach ($domains as $item) {
        if (is_string($item)) {
            $d = trim($item);
            $v = $defaultValue;
            $t = null;
            $ttlOne = $defaultTtl;
        } elseif (is_array($item)) {
            $d = trim((string)($item['domain'] ?? ''));
            $v = trim((string)($item['value'] ?? ''));
            if ($v === '') {
                $v = $defaultValue;
            }
            $t = isset($item['type']) ? trim((string)$item['type']) : null;
            $ttlOne = isset($item['ttl']) ? (int)$item['ttl'] : $defaultTtl;
            if ($ttlOne !== null && $ttlOne <= 0) {
                $ttlOne = null;
            }
        } else {
            $results[] = ['domain' => '', 'code' => -1, 'msg' => '条目格式错误'];
            $anyFail = true;
            continue;
        }

        if ($d === '' || $v === '') {
            $results[] = ['domain' => $d, 'code' => -1, 'msg' => 'domain 或 value 为空'];
            $anyFail = true;
            continue;
        }

        $r = dns_api_upsert($d, $v, ($t !== null && $t !== '') ? $t : null, $ttlOne);
        $results[] = [
            'domain' => $d,
            'code'   => $r['code'],
            'msg'    => $r['msg'],
            'data'   => $r['data'] ?? null,
        ];
        if ($r['code'] !== 0) {
            $anyFail = true;
        }
    }

    $okCount = 0;
    foreach ($results as $row) {
        if (($row['code'] ?? -1) === 0) {
            $okCount++;
        }
    }

    return [
        'code' => $anyFail ? -1 : 0,
        'msg'  => $anyFail
            ? "批量完成：成功 {$okCount}，存在失败"
            : '批量完成：共 ' . count($results) . ' 条成功',
        'results' => $results,
    ];
}
