<?php
declare(strict_types=1);

/**
 * 域名有效期查询与缓存。
 *
 * 主路径使用 RDAP；页面只读本地缓存，刷新动作才访问外网。
 */
require_once __DIR__ . '/functions.php';

const DOMAIN_EXPIRY_FILE = DATA_DIR . '/domain_expiry.json';
const DOMAIN_EXPIRY_LOG_FILE = DATA_DIR . '/logs/domain_expiry.log';
const DOMAIN_EXPIRY_RDAP_BOOTSTRAP_FILE = DATA_DIR . '/domain_expiry_rdap_bootstrap.json';
const DOMAIN_EXPIRY_RDAP_BOOTSTRAP_TTL = 604800;

function domain_expiry_default_data(): array {
    return [
        'version' => 1,
        'manual_domains' => [],
        'ignored_domains' => [],
        'platform_configs' => [],
        'records' => [],
    ];
}

function domain_expiry_ensure_dirs(): void {
    if (!is_dir(DATA_DIR . '/logs')) {
        @mkdir(DATA_DIR . '/logs', 0755, true);
    }
}

function domain_expiry_log(string $level, string $message, array $context = []): void {
    domain_expiry_ensure_dirs();
    $line = '[' . date('Y-m-d H:i:s') . '] [' . strtoupper($level) . '] ' . $message;
    if ($context !== []) {
        $line .= ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    file_put_contents(DOMAIN_EXPIRY_LOG_FILE, $line . "\n", FILE_APPEND | LOCK_EX);
}

function domain_expiry_normalize_data(array $raw): array {
    $data = $raw + domain_expiry_default_data();
    $data['version'] = 1;
    foreach (['manual_domains', 'ignored_domains'] as $key) {
        $items = is_array($data[$key] ?? null) ? $data[$key] : [];
        $normalized = [];
        foreach ($items as $domain) {
            $d = domain_expiry_normalize_domain((string)$domain);
            if ($d !== '' && domain_expiry_is_valid_domain($d)) {
                $normalized[] = $d;
            }
        }
        $data[$key] = array_values(array_unique($normalized));
        sort($data[$key], SORT_STRING);
    }
    $data['platform_configs'] = domain_expiry_normalize_platform_configs(
        is_array($data['platform_configs'] ?? null) ? $data['platform_configs'] : []
    );
    $records = [];
    foreach (($data['records'] ?? []) as $domain => $record) {
        if (!is_array($record)) {
            continue;
        }
        $d = domain_expiry_normalize_domain((string)($record['domain'] ?? $domain));
        if ($d === '' || !domain_expiry_is_valid_domain($d)) {
            continue;
        }
        $records[$d] = $record + [
            'domain' => $d,
            'rdap_domain' => '',
            'expires_at' => '',
            'days_left' => null,
            'status' => 'unknown',
            'source' => '',
            'registrar' => '',
            'checked_at' => '',
            'error' => '',
        ];
        $records[$d]['domain'] = $d;
    }
    ksort($records, SORT_STRING);
    $data['records'] = $records;
    return $data;
}

function domain_expiry_platform_catalog(): array {
    return [
        'digitalplat' => [
            'provider' => 'digitalplat',
            'label' => 'DigitalPlat',
            'site' => 'domain.digitalplat.org',
            'hint' => '适用于 qzz.io / us.kg / xx.kg / dpdns.org / qd.je，使用 Bearer Token。',
            'suffixes' => ['qzz.io', 'us.kg', 'xx.kg', 'dpdns.org', 'qd.je'],
            'fields' => ['token'],
        ],
        'dnshe' => [
            'provider' => 'dnshe',
            'label' => 'DNSHE',
            'site' => 'dnshe.com',
            'hint' => '适用于 cc.cd 等 DNSHE Free Domain，使用 API Key + API Secret。',
            'suffixes' => ['cc.cd'],
            'fields' => ['api_key', 'api_secret'],
        ],
    ];
}

function domain_expiry_normalize_platform_configs(array $configs): array {
    $catalog = domain_expiry_platform_catalog();
    $normalized = [];
    foreach ($configs as $config) {
        if (!is_array($config)) {
            continue;
        }
        $provider = strtolower(trim((string)($config['provider'] ?? '')));
        if (!isset($catalog[$provider])) {
            continue;
        }
        $row = [
            'provider' => $provider,
            'enabled' => !array_key_exists('enabled', $config) || (bool)$config['enabled'],
        ];
        foreach ($catalog[$provider]['fields'] as $field) {
            $value = trim((string)($config[$field] ?? ''));
            if ($provider === 'digitalplat' && $field === 'token') {
                $value = preg_replace('/^Bearer\s+/i', '', $value) ?? $value;
            }
            $row[$field] = trim($value);
        }
        $hasAnySecret = false;
        foreach ($catalog[$provider]['fields'] as $field) {
            if ($row[$field] !== '') {
                $hasAnySecret = true;
                break;
            }
        }
        if ($hasAnySecret) {
            $normalized[$provider] = $row;
        }
    }
    ksort($normalized, SORT_STRING);
    return array_values($normalized);
}

function domain_expiry_mask_secret(string $secret): string {
    $secret = trim($secret);
    if ($secret === '') {
        return '';
    }
    $len = strlen($secret);
    if ($len <= 8) {
        return str_repeat('•', max(4, $len));
    }
    return substr($secret, 0, 4) . str_repeat('•', min(16, max(6, $len - 8))) . substr($secret, -4);
}

function domain_expiry_platform_configs_public(): array {
    $data = domain_expiry_load();
    $catalog = domain_expiry_platform_catalog();
    $rows = [];
    foreach ($data['platform_configs'] as $config) {
        $provider = (string)($config['provider'] ?? '');
        if (!isset($catalog[$provider])) {
            continue;
        }
        $row = [
            'provider' => $provider,
            'enabled' => (bool)($config['enabled'] ?? true),
        ];
        foreach ($catalog[$provider]['fields'] as $field) {
            $value = (string)($config[$field] ?? '');
            $row[$field] = $value;
            $row['has_' . $field] = $value !== '';
            $row[$field . '_masked'] = domain_expiry_mask_secret($value);
        }
        $rows[] = $row;
    }
    return $rows;
}

function domain_expiry_save_platform_configs(array $configs): array {
    $catalog = domain_expiry_platform_catalog();
    $data = domain_expiry_load();
    $existing = [];
    foreach ($data['platform_configs'] as $config) {
        if (is_array($config) && isset($config['provider'])) {
            $existing[(string)$config['provider']] = $config;
        }
    }

    $merged = [];
    foreach ($configs as $config) {
        if (!is_array($config)) {
            continue;
        }
        $provider = strtolower(trim((string)($config['provider'] ?? '')));
        if (!isset($catalog[$provider])) {
            continue;
        }
        $old = is_array($existing[$provider] ?? null) ? $existing[$provider] : [];
        $row = [
            'provider' => $provider,
            'enabled' => !empty($config['enabled']),
        ];
        foreach ($catalog[$provider]['fields'] as $field) {
            $incoming = trim((string)($config[$field] ?? ''));
            $row[$field] = $incoming !== '' ? $incoming : trim((string)($old[$field] ?? ''));
        }
        $merged[] = $row;
    }

    $data['platform_configs'] = domain_expiry_normalize_platform_configs($merged);
    domain_expiry_save($data);
    return ['ok' => true, 'configs' => domain_expiry_platform_configs_public()];
}

function domain_expiry_platform_config(string $provider): ?array {
    $provider = strtolower(trim($provider));
    $data = domain_expiry_load();
    foreach ($data['platform_configs'] as $config) {
        if (($config['provider'] ?? '') === $provider && !empty($config['enabled'])) {
            return $config;
        }
    }
    return null;
}

function domain_expiry_no_proxy_matches(string $host): bool {
    $host = strtolower(trim($host));
    if ($host === '') {
        return false;
    }
    $raw = (string)(getenv('NO_PROXY') ?: getenv('no_proxy') ?: '');
    if (trim($raw) === '') {
        return false;
    }
    foreach (preg_split('/\s*,\s*/', $raw) ?: [] as $entry) {
        $entry = strtolower(trim($entry));
        if ($entry === '') {
            continue;
        }
        if ($entry === '*') {
            return true;
        }
        $entry = ltrim($entry, '.');
        if ($host === $entry || str_ends_with($host, '.' . $entry)) {
            return true;
        }
    }
    return false;
}

function domain_expiry_outbound_proxy(string $url): string {
    $parts = parse_url($url);
    $host = (string)($parts['host'] ?? '');
    if (domain_expiry_no_proxy_matches($host)) {
        return '';
    }
    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    $candidates = $scheme === 'https'
        ? ['HTTPS_PROXY', 'https_proxy', 'HTTP_PROXY', 'http_proxy', 'ALL_PROXY', 'all_proxy']
        : ['HTTP_PROXY', 'http_proxy', 'ALL_PROXY', 'all_proxy'];
    foreach ($candidates as $name) {
        $proxy = trim((string)(getenv($name) ?: ''));
        if ($proxy !== '' && filter_var($proxy, FILTER_VALIDATE_URL)) {
            return $proxy;
        }
    }
    return '';
}

function domain_expiry_response_is_cloudflare_challenge(int $status, array $headers, string $body): bool {
    return $status === 403 && (
        strtolower((string)($headers['cf-mitigated'] ?? '')) === 'challenge'
        || stripos($body, 'cf_chl') !== false
        || stripos($body, 'Security Check') !== false
    );
}

function domain_expiry_platform_http_error_message(string $providerLabel, array $res): string {
    $status = (int)($res['status'] ?? 0);
    $msg = trim((string)($res['msg'] ?? '接口返回失败'));
    $headers = is_array($res['headers'] ?? null) ? $res['headers'] : [];
    $body = (string)($res['body'] ?? '');
    if (domain_expiry_response_is_cloudflare_challenge($status, $headers, $body)) {
        return $providerLabel . ' API 请求被 Cloudflare 人机验证拦截。当前是容器出口被平台挑战，不是秘钥格式错误；请为容器配置 HTTPS_PROXY/HTTP_PROXY 走宿主机可用出口，或联系平台放行 API 请求。';
    }
    if ($status === 401 || $status === 403) {
        return $providerLabel . ' API 测试失败：HTTP ' . $status . '，Token / API Key 无效、无权限、已过期，或被平台侧拒绝';
    }
    if ($status > 0) {
        return $providerLabel . ' API 测试失败：HTTP ' . $status . ($msg !== '' ? '，' . $msg : '');
    }
    return $providerLabel . ' API 测试失败：' . ($msg !== '' ? $msg : '请求失败');
}

function domain_expiry_provider_for_domain(string $domain): ?array {
    $domain = domain_expiry_normalize_domain($domain);
    if ($domain === '') {
        return null;
    }
    foreach (domain_expiry_platform_catalog() as $provider) {
        foreach ($provider['suffixes'] as $suffix) {
            if ($domain === $suffix || str_ends_with($domain, '.' . $suffix)) {
                return $provider;
            }
        }
    }
    return null;
}

function domain_expiry_load(): array {
    if (!is_file(DOMAIN_EXPIRY_FILE)) {
        return domain_expiry_default_data();
    }
    $raw = json_decode((string)file_get_contents(DOMAIN_EXPIRY_FILE), true);
    if (!is_array($raw)) {
        return domain_expiry_default_data();
    }
    return domain_expiry_normalize_data($raw);
}

function domain_expiry_save(array $data): void {
    $data = domain_expiry_normalize_data($data);
    file_put_contents(
        DOMAIN_EXPIRY_FILE,
        json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );
}

function domain_expiry_normalize_domain(string $domain): string {
    $domain = strtolower(trim($domain));
    $domain = preg_replace('/^\*\./', '', $domain) ?? $domain;
    return rtrim($domain, ". \t\r\n");
}

function domain_expiry_is_valid_domain(string $domain): bool {
    if ($domain === '' || strlen($domain) > 253) {
        return false;
    }
    if (str_contains($domain, '://') || str_contains($domain, '/') || str_contains($domain, '@')) {
        return false;
    }
    if (!str_contains($domain, '.')) {
        return false;
    }
    return (bool)preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])$/', $domain);
}

function domain_expiry_registered_domain_guess(string $fqdn): string {
    $fqdn = domain_expiry_normalize_domain($fqdn);
    if (!domain_expiry_is_valid_domain($fqdn)) {
        return '';
    }
    $parts = explode('.', $fqdn);
    $count = count($parts);
    if ($count <= 2) {
        return $fqdn;
    }
    $twoLevelSuffixes = [
        'com.cn', 'net.cn', 'org.cn', 'gov.cn',
        'co.uk', 'org.uk', 'ac.uk',
        'com.au', 'net.au', 'org.au',
        'co.jp', 'ne.jp', 'or.jp',
    ];
    $suffix2 = implode('.', array_slice($parts, -2));
    if (in_array($suffix2, $twoLevelSuffixes, true) && $count >= 3) {
        return implode('.', array_slice($parts, -3));
    }
    return implode('.', array_slice($parts, -2));
}

function domain_expiry_add_source_domain(array &$sources, string $domain, string $source): void {
    $domain = domain_expiry_normalize_domain($domain);
    if ($domain === '' || !domain_expiry_is_valid_domain($domain)) {
        return;
    }
    $sources[$domain][$source] = true;
}

function domain_expiry_domain_sources(): array {
    static $memo = null;
    $sourceFiles = [
        DOMAIN_EXPIRY_FILE,
        DATA_DIR . '/dns_config.json',
        DATA_DIR . '/dns_zones_cache.json',
        DATA_DIR . '/ddns_tasks.json',
    ];
    $cacheKeyParts = [];
    foreach ($sourceFiles as $sourceFile) {
        $cacheKeyParts[] = is_file($sourceFile)
            ? ($sourceFile . ':' . (string)filemtime($sourceFile) . ':' . (string)filesize($sourceFile))
            : ($sourceFile . ':missing');
    }
    $cacheKey = implode('|', $cacheKeyParts);
    if (is_array($memo) && ($memo['key'] ?? '') === $cacheKey && is_array($memo['sources'] ?? null)) {
        return $memo['sources'];
    }

    $sources = [];
    $data = domain_expiry_load();
    foreach ($data['manual_domains'] as $domain) {
        domain_expiry_add_source_domain($sources, $domain, 'manual');
    }

    $dnsConfigFile = DATA_DIR . '/dns_config.json';
    if (is_file($dnsConfigFile)) {
        $cfg = json_decode((string)file_get_contents($dnsConfigFile), true);
        if (is_array($cfg)) {
            $selected = domain_expiry_normalize_domain((string)($cfg['ui']['selected_zone_name'] ?? ''));
            domain_expiry_add_source_domain($sources, $selected, 'dns');

            if (!empty($cfg['accounts']) && is_file(__DIR__ . '/dns_api_lib.php')) {
                require_once __DIR__ . '/dns_api_lib.php';
                try {
                    dns_api_refresh_zones_cache(false);
                } catch (Throwable $e) {
                    domain_expiry_log('warn', 'DNS zones cache refresh failed', ['msg' => $e->getMessage()]);
                }
            }
        }
    }

    $zonesCacheFile = DATA_DIR . '/dns_zones_cache.json';
    if (is_file($zonesCacheFile)) {
        $cache = json_decode((string)file_get_contents($zonesCacheFile), true);
        foreach (($cache['zones'] ?? []) as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $domain = domain_expiry_normalize_domain((string)($entry['zone']['name'] ?? ''));
            domain_expiry_add_source_domain($sources, $domain, 'dns');
        }
    }

    $ddnsFile = DATA_DIR . '/ddns_tasks.json';
    if (is_file($ddnsFile)) {
        $ddns = json_decode((string)file_get_contents($ddnsFile), true);
        foreach (($ddns['tasks'] ?? []) as $task) {
            if (!is_array($task)) {
                continue;
            }
            $domain = domain_expiry_registered_domain_guess((string)($task['target']['domain'] ?? ''));
            if ($domain !== '') {
                domain_expiry_add_source_domain($sources, $domain, 'ddns');
            }
        }
    }

    ksort($sources, SORT_STRING);
    $memo = ['key' => $cacheKey, 'sources' => $sources];
    return $sources;
}

function domain_expiry_collect_domains(bool $includeIgnored = false): array {
    $data = domain_expiry_load();
    $ignored = array_flip($data['ignored_domains']);
    $domains = [];
    foreach (domain_expiry_domain_sources() as $domain => $_source) {
        if (!$includeIgnored && isset($ignored[$domain])) {
            continue;
        }
        $domains[] = $domain;
    }
    return array_values(array_unique($domains));
}

function domain_expiry_source_label(string $domain): string {
    $sources = domain_expiry_domain_sources();
    $row = $sources[$domain] ?? [];
    $labels = [];
    if (!empty($row['manual'])) $labels[] = '手动';
    if (!empty($row['dns'])) $labels[] = 'DNS Zone';
    if (!empty($row['ddns'])) $labels[] = 'DDNS';
    return $labels === [] ? '缓存' : implode(' / ', $labels);
}

function domain_expiry_status_from_date(string $expiresAt, ?int $now = null): array {
    $expiresAt = trim($expiresAt);
    if ($expiresAt === '') {
        return ['status' => 'unknown', 'days_left' => null];
    }
    $ts = strtotime($expiresAt);
    if ($ts === false) {
        return ['status' => 'unknown', 'days_left' => null];
    }
    $now = $now ?? time();
    $today = strtotime(date('Y-m-d 00:00:00', $now));
    $expiryDay = strtotime(date('Y-m-d 00:00:00', $ts));
    $days = (int)floor(($expiryDay - $today) / 86400);
    $status = 'ok';
    if ($days < 0) {
        $status = 'expired';
    } elseif ($days <= 7) {
        $status = 'critical';
    } elseif ($days <= 30) {
        $status = 'warning';
    } elseif ($days <= 90) {
        $status = 'notice';
    }
    return ['status' => $status, 'days_left' => $days];
}

function domain_expiry_status_label(string $status): string {
    return match ($status) {
        'expired' => '已过期',
        'critical' => '7 天内',
        'warning' => '30 天内',
        'notice' => '90 天内',
        'ok' => '正常',
        'error' => '查询失败',
        'unsupported' => 'RDAP 未支持',
        default => '未知',
    };
}

function domain_expiry_status_badge(string $status): string {
    return match ($status) {
        'expired', 'critical', 'error' => 'badge-red',
        'warning' => 'badge-yellow',
        'notice' => 'badge-blue',
        'ok' => 'badge-green',
        'unsupported' => 'badge-gray',
        default => 'badge-gray',
    };
}

function domain_expiry_http_get_json(string $url, int $timeout = 8): array {
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return ['ok' => false, 'status' => 0, 'json' => null, 'body' => '', 'msg' => 'URL 无效'];
    }
    $parts = parse_url($url);
    if (($parts['scheme'] ?? '') !== 'https') {
        return ['ok' => false, 'status' => 0, 'json' => null, 'body' => '', 'msg' => '仅允许 HTTPS RDAP 请求'];
    }

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(4, $timeout),
            CURLOPT_USERAGENT => 'simple-homepage-domain-expiry/1.0',
            CURLOPT_HTTPHEADER => ['Accept: application/rdap+json,application/json,*/*'],
        ]);
        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
            curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
        }
        if (defined('CURLOPT_REDIR_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
            curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTPS);
        }
        $body = curl_exec($ch);
        $err = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($body === false) {
            return ['ok' => false, 'status' => 0, 'json' => null, 'body' => '', 'msg' => $err ?: '请求失败'];
        }
        $json = json_decode((string)$body, true);
        return [
            'ok' => $status >= 200 && $status < 400 && is_array($json),
            'status' => $status,
            'json' => is_array($json) ? $json : null,
            'body' => (string)$body,
            'msg' => is_array($json) ? '' : '返回不是有效 JSON',
        ];
    }

    $ctx = stream_context_create([
        'http' => [
            'timeout' => $timeout,
            'ignore_errors' => true,
            'follow_location' => 1,
            'max_redirects' => 3,
            'header' => "User-Agent: simple-homepage-domain-expiry/1.0\r\nAccept: application/rdap+json,application/json,*/*\r\n",
        ],
    ]);
    $body = @file_get_contents($url, false, $ctx);
    $status = 0;
    foreach (($http_response_header ?? []) as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
            $status = (int)$m[1];
            break;
        }
    }
    $json = $body !== false ? json_decode((string)$body, true) : null;
    return [
        'ok' => $body !== false && $status >= 200 && $status < 400 && is_array($json),
        'status' => $status,
        'json' => is_array($json) ? $json : null,
        'body' => $body === false ? '' : (string)$body,
        'msg' => $body === false ? '请求失败' : (is_array($json) ? '' : '返回不是有效 JSON'),
    ];
}

function domain_expiry_http_get_json_with_headers(string $url, array $headers, int $timeout = 10): array {
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return ['ok' => false, 'status' => 0, 'json' => null, 'body' => '', 'msg' => 'URL 无效'];
    }
    $parts = parse_url($url);
    if (($parts['scheme'] ?? '') !== 'https') {
        return ['ok' => false, 'status' => 0, 'json' => null, 'body' => '', 'msg' => '仅允许 HTTPS API 请求'];
    }

    $headerLines = [
        'Accept: application/json',
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36',
    ];
    foreach ($headers as $name => $value) {
        $name = trim((string)$name);
        $value = trim((string)$value);
        if ($name === '' || $value === '' || preg_match('/[\r\n:]/', $name) || preg_match('/[\r\n]/', $value)) {
            continue;
        }
        if (strcasecmp($name, 'User-Agent') === 0 || strcasecmp($name, 'Accept') === 0) {
            continue;
        }
        $headerLines[] = $name . ': ' . $value;
    }

    $streamFetch = static function () use ($url, $headerLines, $timeout): array {
        $httpOptions = [
            'timeout' => $timeout,
            'ignore_errors' => true,
            'follow_location' => 1,
            'max_redirects' => 3,
            'header' => implode("\r\n", $headerLines) . "\r\n",
        ];
        $proxy = domain_expiry_outbound_proxy($url);
        if ($proxy !== '') {
            $httpOptions['proxy'] = $proxy;
            $httpOptions['request_fulluri'] = true;
        }
        $ctx = stream_context_create(['http' => $httpOptions]);
        $body = @file_get_contents($url, false, $ctx);
        $status = 0;
        $responseHeaders = [];
        foreach (($http_response_header ?? []) as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                $status = (int)$m[1];
                continue;
            }
            if (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $responseHeaders[strtolower(trim($name))] = trim($value);
            }
        }
        $json = $body !== false ? json_decode((string)$body, true) : null;
        return [
            'ok' => $body !== false && $status >= 200 && $status < 400 && is_array($json),
            'status' => $status,
            'headers' => $responseHeaders,
            'json' => is_array($json) ? $json : null,
            'body' => $body === false ? '' : (string)$body,
            'msg' => $body === false ? '请求失败' : (is_array($json) ? '' : ('HTTP ' . $status . ' 返回不是有效 JSON')),
            'transport' => 'stream',
        ];
    };

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        $responseHeaders = [];
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
                $len = strlen($line);
                $line = trim($line);
                if ($line === '' || stripos($line, 'HTTP/') === 0 || !str_contains($line, ':')) {
                    return $len;
                }
                [$name, $value] = explode(':', $line, 2);
                $responseHeaders[strtolower(trim($name))] = trim($value);
                return $len;
            },
        ]);
        $proxy = domain_expiry_outbound_proxy($url);
        if ($proxy !== '') {
            curl_setopt($ch, CURLOPT_PROXY, $proxy);
        }
        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
            curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
        }
        if (defined('CURLOPT_REDIR_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
            curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTPS);
        }
        $body = curl_exec($ch);
        $err = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($body === false) {
            return ['ok' => false, 'status' => 0, 'headers' => $responseHeaders, 'json' => null, 'body' => '', 'msg' => $err ?: '请求失败'];
        }
        $json = json_decode((string)$body, true);
        if ($status === 403 && domain_expiry_response_is_cloudflare_challenge($status, $responseHeaders, (string)$body)) {
            $fallback = $streamFetch();
            if (!empty($fallback['ok'])) {
                return $fallback + ['fallback_from' => 'curl_cloudflare_challenge'];
            }
        }
        return [
            'ok' => $status >= 200 && $status < 400 && is_array($json),
            'status' => $status,
            'headers' => $responseHeaders,
            'json' => is_array($json) ? $json : null,
            'body' => (string)$body,
            'msg' => is_array($json) ? '' : ('HTTP ' . $status . ' 返回不是有效 JSON'),
            'transport' => 'curl',
        ];
    }

    return $streamFetch();
}

function domain_expiry_date_from_platform_value(string $value): string {
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    if (preg_match('/^\d{8}$/', $value)) {
        return substr($value, 0, 4) . '-' . substr($value, 4, 2) . '-' . substr($value, 6, 2);
    }
    $ts = strtotime($value);
    return $ts === false ? '' : date('Y-m-d', $ts);
}

function domain_expiry_status_from_platform(string $status, string $expiresAt, bool $neverExpires = false): array {
    $status = strtolower(trim($status));
    if ($neverExpires) {
        return ['status' => 'ok', 'days_left' => null];
    }
    if (in_array($status, ['expired', 'pendingdelete', 'pending_delete'], true)) {
        return ['status' => 'expired', 'days_left' => null];
    }
    if (in_array($status, ['suspended', 'blocked', 'disabled'], true)) {
        return ['status' => 'error', 'days_left' => null];
    }
    return domain_expiry_status_from_date($expiresAt);
}

function domain_expiry_parse_digitalplat_domain(array $item, string $domain): array {
    $name = domain_expiry_normalize_domain((string)($item['domain'] ?? $item['name'] ?? ''));
    if ($name !== $domain) {
        return ['ok' => false, 'msg' => '域名不匹配'];
    }
    $expiresAt = domain_expiry_date_from_platform_value((string)($item['expiry_date'] ?? $item['expires_at'] ?? ''));
    if ($expiresAt === '') {
        return ['ok' => false, 'msg' => 'DigitalPlat 返回中未找到有效到期时间'];
    }
    $state = domain_expiry_status_from_platform((string)($item['status'] ?? ''), $expiresAt);
    return [
        'ok' => true,
        'expires_at' => $expiresAt,
        'days_left' => $state['days_left'],
        'status' => $state['status'],
        'registrar' => (string)($item['registrar'] ?? 'DigitalPlat Registrar'),
        'raw_status' => [(string)($item['status'] ?? '')],
        'source' => 'digitalplat',
        'rdap_domain' => $domain,
        'rdap_url' => 'https://domain-api.digitalplat.org/api/v1/domains',
    ];
}

function domain_expiry_query_digitalplat(string $domain, array $config): array {
    $token = trim((string)($config['token'] ?? ''));
    $token = trim(preg_replace('/^Bearer\s+/i', '', $token) ?? $token);
    if ($token === '') {
        return ['ok' => false, 'status' => 'unsupported', 'source' => 'digitalplat', 'rdap_domain' => $domain, 'msg' => '该域名属于 DigitalPlat 公共命名空间，请先配置 DigitalPlat Bearer Token'];
    }
    $res = domain_expiry_http_get_json_with_headers(
        'https://domain-api.digitalplat.org/api/v1/domains',
        ['Authorization' => 'Bearer ' . $token],
        12
    );
    if (empty($res['ok']) || !is_array($res['json'] ?? null)) {
        return ['ok' => false, 'source' => 'digitalplat', 'rdap_domain' => $domain, 'msg' => domain_expiry_platform_http_error_message('DigitalPlat', $res)];
    }
    $json = $res['json'];
    if (empty($json['success'])) {
        return ['ok' => false, 'source' => 'digitalplat', 'rdap_domain' => $domain, 'msg' => 'DigitalPlat API 返回失败'];
    }
    foreach (($json['data'] ?? []) as $item) {
        if (!is_array($item)) {
            continue;
        }
        $name = domain_expiry_normalize_domain((string)($item['domain'] ?? $item['name'] ?? ''));
        if ($name === $domain) {
            return domain_expiry_parse_digitalplat_domain($item, $domain);
        }
    }
    return ['ok' => false, 'source' => 'digitalplat', 'rdap_domain' => $domain, 'msg' => 'DigitalPlat API 未返回该域名，请确认 API Key 权限或域名归属'];
}

function domain_expiry_dnshe_candidate_urls(string $domain): array {
    return [
        'https://api005.dnshe.com/index.php?m=domain_hub&endpoint=subdomains&action=list',
    ];
}

function domain_expiry_find_dnshe_domain(array $payload, string $domain): ?array {
    $candidates = [];
    foreach (['subdomains', 'data', 'domains', 'items', 'results'] as $key) {
        if (is_array($payload[$key] ?? null)) {
            $candidates[] = $payload[$key];
        }
    }
    $candidates[] = $payload;
    foreach ($candidates as $list) {
        foreach ($list as $item) {
            if (!is_array($item)) {
                continue;
            }
            $name = domain_expiry_normalize_domain((string)($item['full_domain'] ?? $item['domain'] ?? $item['name'] ?? ''));
            if ($name === $domain) {
                return $item;
            }
        }
    }
    return null;
}

function domain_expiry_parse_dnshe_domain(array $item, string $domain): array {
    $neverExpires = !empty($item['never_expires']);
    $expiresAt = domain_expiry_date_from_platform_value((string)($item['expires_at'] ?? $item['expiry_date'] ?? ''));
    if (!$neverExpires && $expiresAt === '') {
        return ['ok' => false, 'msg' => 'DNSHE 返回中未找到有效到期时间'];
    }
    $state = domain_expiry_status_from_platform((string)($item['status'] ?? ''), $expiresAt, $neverExpires);
    return [
        'ok' => true,
        'expires_at' => $neverExpires ? '永久' : $expiresAt,
        'days_left' => $state['days_left'],
        'status' => $state['status'],
        'registrar' => 'DNSHE',
        'raw_status' => [(string)($item['status'] ?? '')],
        'source' => 'dnshe',
        'rdap_domain' => $domain,
        'rdap_url' => 'https://api005.dnshe.com/index.php?m=domain_hub&endpoint=subdomains&action=list',
    ];
}

function domain_expiry_query_dnshe(string $domain, array $config): array {
    $apiKey = trim((string)($config['api_key'] ?? ''));
    $apiSecret = trim((string)($config['api_secret'] ?? ''));
    if ($apiKey === '' || $apiSecret === '') {
        return ['ok' => false, 'status' => 'unsupported', 'source' => 'dnshe', 'rdap_domain' => $domain, 'msg' => '该域名属于 DNSHE 公共命名空间，请先配置 DNSHE API Key 和 API Secret'];
    }
    $lastMsg = 'DNSHE API 请求失败';
    foreach (domain_expiry_dnshe_candidate_urls($domain) as $url) {
        $res = domain_expiry_http_get_json_with_headers($url, [
            'X-API-Key' => $apiKey,
            'X-API-Secret' => $apiSecret,
        ], 12);
        if (empty($res['ok']) || !is_array($res['json'] ?? null)) {
            $lastMsg = (string)($res['msg'] ?? ('HTTP ' . (string)($res['status'] ?? 0)));
            continue;
        }
        $item = domain_expiry_find_dnshe_domain($res['json'], $domain);
        if ($item !== null) {
            return domain_expiry_parse_dnshe_domain($item, $domain);
        }
        $lastMsg = 'DNSHE API 未返回该域名，请确认 API Key 权限或域名归属';
    }
    return ['ok' => false, 'source' => 'dnshe', 'rdap_domain' => $domain, 'msg' => $lastMsg];
}

function domain_expiry_query_platform(string $domain): array {
    $domain = domain_expiry_normalize_domain($domain);
    $provider = domain_expiry_provider_for_domain($domain);
    if ($provider === null) {
        return ['attempted' => false, 'ok' => false, 'msg' => '未匹配官方平台'];
    }
    $config = domain_expiry_platform_config((string)$provider['provider']) ?? ['provider' => $provider['provider']];
    $result = match ((string)$provider['provider']) {
        'digitalplat' => domain_expiry_query_digitalplat($domain, $config),
        'dnshe' => domain_expiry_query_dnshe($domain, $config),
        default => ['ok' => false, 'msg' => '未支持的官方平台'],
    };
    $result['attempted'] = true;
    return $result;
}

function domain_expiry_test_platform_config(string $provider, array $config): array {
    $provider = strtolower(trim($provider));
    $catalog = domain_expiry_platform_catalog();
    if (!isset($catalog[$provider])) {
        return ['ok' => false, 'msg' => '未知官方平台'];
    }
    if ($provider === 'digitalplat') {
        $token = trim((string)($config['token'] ?? ''));
        $token = trim(preg_replace('/^Bearer\s+/i', '', $token) ?? $token);
        if ($token === '') {
            return ['ok' => false, 'msg' => '请填写 DigitalPlat Bearer Token'];
        }
        $res = domain_expiry_http_get_json_with_headers(
            'https://domain-api.digitalplat.org/api/v1/domains',
            ['Authorization' => 'Bearer ' . $token],
            12
        );
        if (empty($res['ok']) || !is_array($res['json'] ?? null)) {
            return ['ok' => false, 'msg' => domain_expiry_platform_http_error_message('DigitalPlat', $res)];
        }
        if (empty($res['json']['success'])) {
            return ['ok' => false, 'msg' => 'DigitalPlat API 测试失败：接口返回 success=false，请检查 Token 权限'];
        }
        $count = is_array($res['json']['data'] ?? null) ? count($res['json']['data']) : 0;
        return ['ok' => true, 'msg' => 'DigitalPlat 可用，已读取 ' . $count . ' 个域名', 'count' => $count];
    }
    if ($provider === 'dnshe') {
        $apiKey = trim((string)($config['api_key'] ?? ''));
        $apiSecret = trim((string)($config['api_secret'] ?? ''));
        if ($apiKey === '' || $apiSecret === '') {
            return ['ok' => false, 'msg' => '请填写 DNSHE API Key 和 API Secret'];
        }
        $res = domain_expiry_http_get_json_with_headers('https://api005.dnshe.com/index.php?m=domain_hub&endpoint=subdomains&action=list', [
            'X-API-Key' => $apiKey,
            'X-API-Secret' => $apiSecret,
        ], 12);
        if (empty($res['ok']) || !is_array($res['json'] ?? null)) {
            return ['ok' => false, 'msg' => domain_expiry_platform_http_error_message('DNSHE', $res)];
        }
        $payload = $res['json'];
        $count = 0;
        foreach (['subdomains', 'data', 'domains', 'items', 'results'] as $key) {
            if (is_array($payload[$key] ?? null)) {
                $count = count($payload[$key]);
                break;
            }
        }
        return ['ok' => true, 'msg' => 'DNSHE 可用，已读取 ' . $count . ' 个域名', 'count' => $count];
    }
    return ['ok' => false, 'msg' => '未支持的官方平台'];
}

function domain_expiry_load_rdap_bootstrap(): array {
    $now = time();
    if (is_file(DOMAIN_EXPIRY_RDAP_BOOTSTRAP_FILE)) {
        $cached = json_decode((string)file_get_contents(DOMAIN_EXPIRY_RDAP_BOOTSTRAP_FILE), true);
        if (is_array($cached) && isset($cached['updated_at'], $cached['data'])
            && ($now - (int)$cached['updated_at']) < DOMAIN_EXPIRY_RDAP_BOOTSTRAP_TTL
            && is_array($cached['data'])) {
            return $cached['data'];
        }
    }
    $res = domain_expiry_http_get_json('https://data.iana.org/rdap/dns.json', 8);
    if (!$res['ok'] || !is_array($res['json'])) {
        domain_expiry_log('warn', 'RDAP bootstrap fetch failed', ['msg' => $res['msg'] ?? '', 'status' => $res['status'] ?? 0]);
        return [];
    }
    file_put_contents(
        DOMAIN_EXPIRY_RDAP_BOOTSTRAP_FILE,
        json_encode(['updated_at' => $now, 'data' => $res['json']], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );
    return $res['json'];
}

function domain_expiry_rdap_urls(string $domain): array {
    $parts = explode('.', $domain);
    $tld = end($parts) ?: '';
    $urls = [];
    $bootstrap = domain_expiry_load_rdap_bootstrap();
    foreach (($bootstrap['services'] ?? []) as $service) {
        if (!is_array($service) || count($service) < 2 || !is_array($service[0]) || !is_array($service[1])) {
            continue;
        }
        $tlds = array_map('strtolower', array_map('strval', $service[0]));
        if (!in_array($tld, $tlds, true)) {
            continue;
        }
        foreach ($service[1] as $base) {
            $base = trim((string)$base);
            if ($base !== '' && str_starts_with($base, 'https://')) {
                $urls[] = rtrim($base, '/') . '/domain/' . rawurlencode($domain);
            }
        }
    }
    $urls[] = 'https://rdap.org/domain/' . rawurlencode($domain);
    return array_values(array_unique($urls));
}

function domain_expiry_rdap_query_domains(string $domain): array {
    $domain = domain_expiry_normalize_domain($domain);
    if (!domain_expiry_is_valid_domain($domain)) {
        return [];
    }
    return [$domain];
}

function domain_expiry_parse_iana_whois_server(string $body): string {
    if (preg_match('/^whois:[ \t]*(\S+)/mi', $body, $m)) {
        return strtolower(trim($m[1]));
    }
    return '';
}

function domain_expiry_known_whois_server(string $tld): string {
    $map = [
        'cd' => 'whois.nic.cd',
        'io' => 'whois.nic.io',
    ];
    return $map[$tld] ?? '';
}

function domain_expiry_whois_query_server(string $server, string $query, int $timeout = 8): array {
    $server = strtolower(trim($server));
    $query = domain_expiry_normalize_domain($query);
    if ($server === '' || $query === '') {
        return ['ok' => false, 'msg' => 'WHOIS 参数无效', 'body' => ''];
    }
    $fp = @stream_socket_client('tcp://' . $server . ':43', $errno, $errstr, $timeout);
    if (!is_resource($fp)) {
        return ['ok' => false, 'msg' => $errstr !== '' ? $errstr : ('WHOIS 连接失败: ' . $server), 'body' => ''];
    }
    stream_set_timeout($fp, $timeout);
    fwrite($fp, $query . "\r\n");
    $body = '';
    while (!feof($fp) && strlen($body) < 262144) {
        $chunk = fread($fp, 8192);
        if ($chunk === false || $chunk === '') {
            $meta = stream_get_meta_data($fp);
            if (!empty($meta['timed_out'])) {
                fclose($fp);
                return ['ok' => false, 'msg' => 'WHOIS 请求超时: ' . $server, 'body' => $body];
            }
            break;
        }
        $body .= $chunk;
    }
    fclose($fp);
    return ['ok' => trim($body) !== '', 'msg' => trim($body) !== '' ? '' : 'WHOIS 返回为空', 'body' => $body];
}

function domain_expiry_whois_server_for_domain(string $domain): string {
    static $cache = [];
    $domain = domain_expiry_normalize_domain($domain);
    $parts = explode('.', $domain);
    $tld = strtolower((string)end($parts));
    if ($tld === '') {
        return '';
    }
    if (isset($cache[$tld])) {
        return $cache[$tld];
    }

    $server = '';
    $iana = domain_expiry_whois_query_server('whois.iana.org', $tld, 6);
    if (!empty($iana['ok'])) {
        $server = domain_expiry_parse_iana_whois_server((string)$iana['body']);
    }
    if ($server === '') {
        $server = domain_expiry_known_whois_server($tld);
    }
    $cache[$tld] = $server;
    return $server;
}

function domain_expiry_parse_whois(array|string $whois): array {
    $body = is_array($whois) ? (string)($whois['body'] ?? '') : (string)$whois;
    $body = str_replace(["\r\n", "\r"], "\n", $body);
    if (trim($body) === '') {
        return ['ok' => false, 'msg' => 'WHOIS 返回为空'];
    }

    $expiryLabels = [
        'Registry Expiry Date',
        'Registrar Registration Expiration Date',
        'Expiration Date',
        'Expiry Date',
        'Expiry date',
        'Expiration Time',
        'expire',
        'expires',
    ];
    $expires = '';
    foreach ($expiryLabels as $label) {
        $quoted = preg_quote($label, '/');
        if (preg_match('/^\s*' . $quoted . '\s*:\s*(.+?)\s*$/mi', $body, $m)) {
            $expires = trim($m[1]);
            break;
        }
    }
    if ($expires === '') {
        return ['ok' => false, 'msg' => 'WHOIS 返回中未找到到期时间'];
    }
    $expires = preg_replace('/\s+\(.+$/', '', $expires) ?? $expires;
    $expiryTs = strtotime($expires);
    if ($expiryTs === false) {
        return ['ok' => false, 'msg' => 'WHOIS 返回的到期时间无效'];
    }

    $registrar = '';
    if (preg_match('/^\s*Registrar\s*:\s*(.+?)\s*$/mi', $body, $m)
        || preg_match('/^\s*Sponsoring Registrar\s*:\s*(.+?)\s*$/mi', $body, $m)) {
        $registrar = trim($m[1]);
    }

    $state = domain_expiry_status_from_date(date('Y-m-d', $expiryTs));
    return [
        'ok' => true,
        'expires_at' => date('Y-m-d', $expiryTs),
        'days_left' => $state['days_left'],
        'status' => $state['status'],
        'registrar' => $registrar,
        'raw_status' => [],
    ];
}

function domain_expiry_query_whois(string $domain): array {
    $domain = domain_expiry_normalize_domain($domain);
    if (!domain_expiry_is_valid_domain($domain)) {
        return ['ok' => false, 'msg' => '域名格式不正确'];
    }
    $lastMsg = 'WHOIS 查询失败';
    foreach (domain_expiry_rdap_query_domains($domain) as $queryDomain) {
        $server = domain_expiry_whois_server_for_domain($queryDomain);
        if ($server === '') {
            $lastMsg = '未找到可用 WHOIS 服务器';
            continue;
        }
        $res = domain_expiry_whois_query_server($server, $queryDomain, 8);
        if (empty($res['ok'])) {
            $lastMsg = (string)($res['msg'] ?? 'WHOIS 查询失败');
            continue;
        }
        $parsed = domain_expiry_parse_whois((string)$res['body']);
        if (!empty($parsed['ok'])) {
            $parsed['source'] = 'whois';
            $parsed['rdap_domain'] = $queryDomain;
            $parsed['rdap_url'] = 'whois://' . $server . '/' . $queryDomain;
            return $parsed;
        }
        $lastMsg = (string)($parsed['msg'] ?? 'WHOIS 返回不完整');
    }
    return ['ok' => false, 'msg' => $lastMsg];
}

function domain_expiry_parse_vcard_fn(array $entity): string {
    $vcard = $entity['vcardArray'] ?? null;
    if (!is_array($vcard) || !isset($vcard[1]) || !is_array($vcard[1])) {
        return '';
    }
    foreach ($vcard[1] as $row) {
        if (is_array($row) && strtolower((string)($row[0] ?? '')) === 'fn') {
            return trim((string)($row[3] ?? ''));
        }
    }
    return '';
}

function domain_expiry_parse_rdap(array $json): array {
    $expires = '';
    foreach (($json['events'] ?? []) as $event) {
        if (!is_array($event)) {
            continue;
        }
        $action = strtolower((string)($event['eventAction'] ?? ''));
        if (str_contains($action, 'expiration') || str_contains($action, 'expiry')) {
            $date = trim((string)($event['eventDate'] ?? ''));
            if ($date !== '') {
                $expires = $date;
                break;
            }
        }
    }

    $registrar = '';
    foreach (($json['entities'] ?? []) as $entity) {
        if (!is_array($entity)) {
            continue;
        }
        $roles = array_map('strtolower', array_map('strval', is_array($entity['roles'] ?? null) ? $entity['roles'] : []));
        if (in_array('registrar', $roles, true)) {
            $registrar = domain_expiry_parse_vcard_fn($entity);
            if ($registrar !== '') {
                break;
            }
        }
    }

    if ($expires === '') {
        return ['ok' => false, 'msg' => 'RDAP 返回中未找到到期时间'];
    }
    $expiryTs = strtotime($expires);
    if ($expiryTs === false) {
        return ['ok' => false, 'msg' => 'RDAP 返回的到期时间无效'];
    }
    $state = domain_expiry_status_from_date($expires);
    return [
        'ok' => true,
        'expires_at' => date('Y-m-d', $expiryTs),
        'days_left' => $state['days_left'],
        'status' => $state['status'],
        'registrar' => $registrar,
        'raw_status' => is_array($json['status'] ?? null) ? array_values($json['status']) : [],
    ];
}

function domain_expiry_query_rdap(string $domain, ?callable $fetcher = null): array {
    $domain = domain_expiry_normalize_domain($domain);
    if (!domain_expiry_is_valid_domain($domain)) {
        return ['ok' => false, 'msg' => '域名格式不正确'];
    }
    $lastMsg = 'RDAP 查询失败';
    $queryDomains = domain_expiry_rdap_query_domains($domain);
    $primaryQueryDomain = $queryDomains[0] ?? $domain;
    $lastQueryDomain = $primaryQueryDomain;
    $onlyGenericRdap = true;
    foreach ($queryDomains as $queryDomain) {
        $lastQueryDomain = $queryDomain;
        foreach (domain_expiry_rdap_urls($queryDomain) as $url) {
            if (!str_starts_with($url, 'https://rdap.org/')) {
                $onlyGenericRdap = false;
            }
            $res = $fetcher ? $fetcher($url) : domain_expiry_http_get_json($url, 8);
            if (!is_array($res) || empty($res['ok']) || !is_array($res['json'] ?? null)) {
                $lastMsg = is_array($res) ? (string)($res['msg'] ?? ('HTTP ' . (string)($res['status'] ?? 0))) : '请求失败';
                continue;
            }
            $parsed = domain_expiry_parse_rdap($res['json']);
            if ($parsed['ok']) {
                $parsed['source'] = 'rdap';
                $parsed['rdap_url'] = $url;
                $parsed['rdap_domain'] = $queryDomain;
                return $parsed;
            }
            $lastMsg = (string)($parsed['msg'] ?? 'RDAP 返回不完整');
        }
    }
    if ($onlyGenericRdap) {
        return [
            'ok' => false,
            'status' => 'unsupported',
            'rdap_domain' => $primaryQueryDomain,
            'msg' => '该后缀未在 IANA RDAP bootstrap 发布查询服务，通用 rdap.org 也未返回有效 RDAP 数据',
        ];
    }
    return ['ok' => false, 'rdap_domain' => $lastQueryDomain, 'msg' => $lastMsg];
}

function domain_expiry_refresh_domain(string $domain, bool $force = false, ?callable $fetcher = null): array {
    $domain = domain_expiry_normalize_domain($domain);
    if (!domain_expiry_is_valid_domain($domain)) {
        return ['ok' => false, 'msg' => '域名格式不正确'];
    }
    $data = domain_expiry_load();
    $record = is_array($data['records'][$domain] ?? null) ? $data['records'][$domain] : null;
    if (!$force && $record && trim((string)($record['checked_at'] ?? '')) !== '') {
        $checked = strtotime((string)$record['checked_at']);
        if ($checked !== false && (time() - $checked) < 60) {
            return ['ok' => true, 'msg' => '刷新过于频繁，已返回缓存', 'record' => domain_expiry_row($domain, $record)];
        }
    }

    $platform = $fetcher === null ? domain_expiry_query_platform($domain) : ['attempted' => false, 'ok' => false];
    $queried = !empty($platform['attempted']) ? $platform : domain_expiry_query_rdap($domain, $fetcher);
    if (!$queried['ok'] && $fetcher === null) {
        if (empty($platform['attempted'])) {
            $whois = domain_expiry_query_whois($domain);
            if ($whois['ok']) {
                $queried = $whois;
            } elseif ((string)($queried['status'] ?? '') === 'unsupported') {
                $queried['msg'] = (string)($queried['msg'] ?? 'RDAP 未支持') . '；WHOIS fallback 也失败：' . (string)($whois['msg'] ?? 'WHOIS 查询失败');
            }
        }
    }
    $now = date('Y-m-d H:i:s');
    if (!$queried['ok']) {
        $failedStatus = ((string)($queried['status'] ?? '') === 'unsupported') ? 'unsupported' : 'error';
        $record = [
            'domain' => $domain,
            'rdap_domain' => (string)($queried['rdap_domain'] ?? ($record['rdap_domain'] ?? '')),
            'expires_at' => $failedStatus === 'unsupported' ? '' : (string)($record['expires_at'] ?? ''),
            'days_left' => $failedStatus === 'unsupported' ? null : ($record['days_left'] ?? null),
            'status' => $failedStatus,
            'source' => (string)($queried['source'] ?? 'rdap'),
            'registrar' => $failedStatus === 'unsupported' ? '' : (string)($record['registrar'] ?? ''),
            'checked_at' => $now,
            'error' => (string)($queried['msg'] ?? '查询失败'),
        ];
        $data['records'][$domain] = $record;
        domain_expiry_save($data);
        domain_expiry_log('warn', 'Domain expiry refresh failed', ['domain' => $domain, 'msg' => $record['error']]);
        return ['ok' => false, 'msg' => $record['error'], 'record' => domain_expiry_row($domain, $record)];
    }

    $record = [
        'domain' => $domain,
        'rdap_domain' => (string)($queried['rdap_domain'] ?? $domain),
        'expires_at' => (string)$queried['expires_at'],
        'days_left' => $queried['days_left'],
        'status' => (string)$queried['status'],
        'source' => (string)($queried['source'] ?? 'rdap'),
        'registrar' => (string)($queried['registrar'] ?? ''),
        'checked_at' => $now,
        'error' => '',
        'rdap_url' => (string)($queried['rdap_url'] ?? ''),
        'raw_status' => $queried['raw_status'] ?? [],
    ];
    $data['records'][$domain] = $record;
    domain_expiry_save($data);
    domain_expiry_log('info', 'Domain expiry refreshed', ['domain' => $domain, 'expires_at' => $record['expires_at'], 'status' => $record['status']]);
    return ['ok' => true, 'msg' => '刷新完成', 'record' => domain_expiry_row($domain, $record)];
}

function domain_expiry_needs_refresh(?array $record, ?int $now = null): bool {
    if (!$record) {
        return true;
    }
    $checkedAt = strtotime((string)($record['checked_at'] ?? ''));
    if ($checkedAt === false) {
        return true;
    }
    $now = $now ?? time();
    $age = $now - $checkedAt;
    $status = (string)($record['status'] ?? 'unknown');
    $days = $record['days_left'] ?? null;
    if ($status === 'error') {
        return $age >= 21600;
    }
    if (!is_int($days)) {
        return $age >= 86400;
    }
    if ($days <= 30) {
        return $age >= 86400;
    }
    return $age >= 604800;
}

function domain_expiry_refresh_due(bool $force = false, int $limit = 50): array {
    $data = domain_expiry_load();
    $domains = domain_expiry_collect_domains();
    $results = [];
    $count = 0;
    foreach ($domains as $domain) {
        $record = is_array($data['records'][$domain] ?? null) ? $data['records'][$domain] : null;
        if (!$force && !domain_expiry_needs_refresh($record)) {
            continue;
        }
        $results[] = domain_expiry_refresh_domain($domain, $force);
        $count++;
        if ($count >= $limit) {
            break;
        }
    }
    return ['ok' => true, 'checked' => $count, 'results' => $results];
}

function domain_expiry_add_manual(string $domain): array {
    $domain = domain_expiry_normalize_domain($domain);
    if (!domain_expiry_is_valid_domain($domain)) {
        return ['ok' => false, 'msg' => '域名格式不正确，请填写 example.com 这样的根域名'];
    }
    $data = domain_expiry_load();
    $data['manual_domains'][] = $domain;
    $data['manual_domains'] = array_values(array_unique($data['manual_domains']));
    sort($data['manual_domains'], SORT_STRING);
    domain_expiry_save($data);
    return ['ok' => true, 'msg' => '域名已添加', 'domain' => $domain];
}

function domain_expiry_remove_manual(string $domain): array {
    $domain = domain_expiry_normalize_domain($domain);
    $data = domain_expiry_load();
    $before = count($data['manual_domains']);
    $data['manual_domains'] = array_values(array_filter($data['manual_domains'], static fn($item) => $item !== $domain));
    domain_expiry_save($data);
    return ['ok' => count($data['manual_domains']) !== $before, 'msg' => '域名已移除'];
}

function domain_expiry_set_ignored(string $domain, bool $ignored): array {
    $domain = domain_expiry_normalize_domain($domain);
    if (!domain_expiry_is_valid_domain($domain)) {
        return ['ok' => false, 'msg' => '域名格式不正确'];
    }
    $data = domain_expiry_load();
    if ($ignored) {
        $data['ignored_domains'][] = $domain;
        $data['ignored_domains'] = array_values(array_unique($data['ignored_domains']));
        sort($data['ignored_domains'], SORT_STRING);
    } else {
        $data['ignored_domains'] = array_values(array_filter($data['ignored_domains'], static fn($item) => $item !== $domain));
    }
    domain_expiry_save($data);
    return ['ok' => true, 'msg' => $ignored ? '已忽略' : '已取消忽略'];
}

function domain_expiry_row(string $domain, ?array $record = null): array {
    $domain = domain_expiry_normalize_domain($domain);
    $record = $record ?: [];
    $status = (string)($record['status'] ?? 'unknown');
    return [
        'domain' => $domain,
        'rdap_domain' => (string)($record['rdap_domain'] ?? ''),
        'expires_at' => (string)($record['expires_at'] ?? ''),
        'days_left' => $record['days_left'] ?? null,
        'status' => $status,
        'status_label' => domain_expiry_status_label($status),
        'badge' => domain_expiry_status_badge($status),
        'source' => trim((string)($record['source'] ?? '')) !== '' ? (string)$record['source'] : domain_expiry_source_label($domain),
        'registrar' => (string)($record['registrar'] ?? ''),
        'checked_at' => (string)($record['checked_at'] ?? ''),
        'error' => (string)($record['error'] ?? ''),
    ];
}

function domain_expiry_rows(bool $includeIgnored = false): array {
    $data = domain_expiry_load();
    $domains = domain_expiry_collect_domains($includeIgnored);
    $ignored = array_flip($data['ignored_domains']);
    $rows = [];
    foreach ($domains as $domain) {
        $row = domain_expiry_row($domain, is_array($data['records'][$domain] ?? null) ? $data['records'][$domain] : null);
        $row['ignored'] = isset($ignored[$domain]);
        $rows[] = $row;
    }
    usort($rows, static function(array $a, array $b): int {
        $rank = ['expired' => 0, 'critical' => 1, 'warning' => 2, 'error' => 3, 'unknown' => 4, 'unsupported' => 5, 'notice' => 6, 'ok' => 7];
        $ra = $rank[(string)($a['status'] ?? 'unknown')] ?? 9;
        $rb = $rank[(string)($b['status'] ?? 'unknown')] ?? 9;
        if ($ra !== $rb) {
            return $ra <=> $rb;
        }
        return strcmp((string)$a['domain'], (string)$b['domain']);
    });
    return $rows;
}

function domain_expiry_summary(): array {
    $rows = domain_expiry_rows();
    $summary = [
        'total' => count($rows),
        'expired' => 0,
        'critical' => 0,
        'warning' => 0,
        'error' => 0,
        'unsupported' => 0,
        'unknown' => 0,
        'nearest' => null,
    ];
    foreach ($rows as $row) {
        $status = (string)$row['status'];
        if (isset($summary[$status]) && is_int($summary[$status])) {
            $summary[$status]++;
        }
        if (is_int($row['days_left'] ?? null) && ($summary['nearest'] === null || $row['days_left'] < $summary['nearest']['days_left'])) {
            $summary['nearest'] = [
                'domain' => $row['domain'],
                'days_left' => $row['days_left'],
                'expires_at' => $row['expires_at'],
                'status' => $status,
            ];
        }
    }
    return $summary;
}
