<?php
/**
 * WebDAV 备份客户端与后台任务。
 *
 * 设计约束：
 * - 仅由管理员手动触发，不注册定时任务；
 * - 远端保存与本地备份相同的明文 JSON；
 * - SSRF 防护、TLS、认证默认关闭；
 * - 每个 RiverOps 实例在远端最多保留 10 份备份。
 */

if (!defined('DATA_DIR')) {
    require_once __DIR__ . '/../../shared/auth.php';
}

define('BACKUP_WEBDAV_CONFIG_FILE', DATA_DIR . '/backup_webdav.json');
define('BACKUP_WEBDAV_JOBS_DIR', DATA_DIR . '/backups/jobs');
define('BACKUP_WEBDAV_TMP_DIR', DATA_DIR . '/backups/tmp');
define('BACKUP_WEBDAV_START_LOCK', DATA_DIR . '/backups/.webdav_job_start.lock');
define('BACKUP_WEBDAV_RETENTION', 10);
define('BACKUP_WEBDAV_MAX_DOWNLOAD_BYTES', 32 * 1024 * 1024);
define('BACKUP_WEBDAV_MAX_RESPONSE_BYTES', 2 * 1024 * 1024);
define('BACKUP_WEBDAV_JOB_LOG_BYTES', 64 * 1024);

function backup_webdav_default_config(): array {
    return [
        'version' => 1,
        'instance_id' => '',
        'enabled' => false,
        'name' => 'WebDAV',
        'base_url' => '',
        'remote_dir' => '/RiverOps',
        'ssrf_protection' => false,
        'tls_enabled' => false,
        'tls_verify' => true,
        'auth_enabled' => false,
        'auth_mode' => 'basic',
        'username' => '',
        'password' => '',
        'connect_timeout' => 10,
        'request_timeout' => 300,
        'remote_retention' => BACKUP_WEBDAV_RETENTION,
    ];
}

function backup_webdav_bool($value): bool {
    if (is_bool($value)) {
        return $value;
    }
    return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
}

function backup_webdav_limit_string(string $value, int $length): string {
    return function_exists('mb_substr') ? mb_substr($value, 0, $length, 'UTF-8') : substr($value, 0, $length);
}

function backup_webdav_clean_instance_id(string $value): string {
    $value = strtolower(trim($value));
    return preg_match('/^[a-f0-9]{8,32}$/', $value) ? $value : '';
}

function backup_webdav_generate_instance_id(): string {
    return bin2hex(random_bytes(6));
}

function backup_webdav_normalize_remote_dir(string $value): string {
    $value = trim(str_replace('\\', '/', $value));
    if ($value === '') {
        return '/RiverOps';
    }
    $segments = [];
    foreach (explode('/', $value) as $segment) {
        $segment = trim($segment);
        if ($segment === '') {
            continue;
        }
        if ($segment === '.' || $segment === '..' || str_contains($segment, "\0")) {
            throw new InvalidArgumentException('WebDAV 远端目录包含非法路径片段');
        }
        $segments[] = $segment;
    }
    if ($segments === []) {
        return '/';
    }
    return '/' . implode('/', $segments);
}

function backup_webdav_validate_base_url(string $url): array {
    $url = trim($url);
    if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
        return ['ok' => false, 'msg' => 'WebDAV URL 无效'];
    }
    $parts = parse_url($url);
    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    if (!in_array($scheme, ['http', 'https'], true)) {
        return ['ok' => false, 'msg' => 'WebDAV URL 只允许 http:// 或 https://'];
    }
    if (empty($parts['host'])) {
        return ['ok' => false, 'msg' => 'WebDAV URL 缺少主机名'];
    }
    if (isset($parts['user']) || isset($parts['pass'])) {
        return ['ok' => false, 'msg' => '请使用独立的用户名和密码字段，不要将凭据写入 URL'];
    }
    if (isset($parts['fragment'])) {
        return ['ok' => false, 'msg' => 'WebDAV URL 不能包含 fragment'];
    }
    if (isset($parts['query'])) {
        return ['ok' => false, 'msg' => 'WebDAV URL 不能包含查询参数'];
    }
    if (preg_match('/[\r\n]/', $url)) {
        return ['ok' => false, 'msg' => 'WebDAV URL 包含非法字符'];
    }
    return ['ok' => true, 'msg' => ''];
}

function backup_webdav_normalize_config(array $raw, ?array $existing = null): array {
    $defaults = backup_webdav_default_config();
    $existing = is_array($existing) ? $existing : [];
    $merged = array_merge($defaults, $existing, $raw);

    $instanceId = backup_webdav_clean_instance_id((string)($merged['instance_id'] ?? ''));
    if ($instanceId === '') {
        $instanceId = backup_webdav_generate_instance_id();
    }

    $baseUrl = trim((string)($merged['base_url'] ?? ''));
    if ($baseUrl !== '') {
        $parts = parse_url($baseUrl);
        if (is_array($parts) && !empty($parts['host'])) {
            $tlsEnabled = backup_webdav_bool($merged['tls_enabled'] ?? false);
            $scheme = $tlsEnabled ? 'https' : 'http';
            $authority = $parts['host'];
            if (str_contains($authority, ':') && $authority[0] !== '[') {
                $authority = '[' . $authority . ']';
            }
            if (isset($parts['port'])) {
                $authority .= ':' . (int)$parts['port'];
            }
            $path = (string)($parts['path'] ?? '');
            $baseUrl = $scheme . '://' . $authority . rtrim($path, '/');
            if (!empty($parts['query'])) {
                $baseUrl .= '?' . $parts['query'];
            }
        }
    }

    $authMode = strtolower(trim((string)($merged['auth_mode'] ?? 'basic')));
    if (!in_array($authMode, ['basic', 'digest', 'auto'], true)) {
        $authMode = 'basic';
    }

    return [
        'version' => 1,
        'instance_id' => $instanceId,
        'enabled' => backup_webdav_bool($merged['enabled'] ?? false),
        'name' => backup_webdav_limit_string(trim((string)($merged['name'] ?? 'WebDAV')), 80) ?: 'WebDAV',
        'base_url' => rtrim($baseUrl, '/'),
        'remote_dir' => backup_webdav_normalize_remote_dir((string)($merged['remote_dir'] ?? '/RiverOps')),
        'ssrf_protection' => backup_webdav_bool($merged['ssrf_protection'] ?? false),
        'tls_enabled' => backup_webdav_bool($merged['tls_enabled'] ?? false),
        'tls_verify' => backup_webdav_bool($merged['tls_verify'] ?? true),
        'auth_enabled' => backup_webdav_bool($merged['auth_enabled'] ?? false),
        'auth_mode' => $authMode,
        'username' => backup_webdav_limit_string(trim((string)($merged['username'] ?? '')), 255),
        'password' => (string)($merged['password'] ?? ''),
        'connect_timeout' => max(1, min(60, (int)($merged['connect_timeout'] ?? 10))),
        'request_timeout' => max(10, min(1800, (int)($merged['request_timeout'] ?? 300))),
        'remote_retention' => BACKUP_WEBDAV_RETENTION,
    ];
}

function backup_webdav_load_config(): array {
    $raw = [];
    if (is_file(BACKUP_WEBDAV_CONFIG_FILE)) {
        $decoded = json_decode((string)@file_get_contents(BACKUP_WEBDAV_CONFIG_FILE), true);
        $raw = is_array($decoded) ? $decoded : [];
    }
    $config = backup_webdav_normalize_config($raw);
    if (backup_webdav_clean_instance_id((string)($raw['instance_id'] ?? '')) === '') {
        $dir = dirname(BACKUP_WEBDAV_CONFIG_FILE);
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        @file_put_contents(
            BACKUP_WEBDAV_CONFIG_FILE,
            json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
        @chmod(BACKUP_WEBDAV_CONFIG_FILE, 0600);
    }
    return $config;
}

function backup_webdav_public_config(?array $config = null): array {
    $config = $config ?? backup_webdav_load_config();
    $public = $config;
    $public['password_set'] = (string)($config['password'] ?? '') !== '';
    $public['password'] = '';
    return $public;
}

function backup_webdav_save_config(array $input): array {
    $existing = backup_webdav_load_config();
    if (!array_key_exists('password', $input) || (string)$input['password'] === '') {
        $input['password'] = (string)($existing['password'] ?? '');
    }
    try {
        $config = backup_webdav_normalize_config($input, $existing);
    } catch (Throwable $e) {
        return ['ok' => false, 'msg' => $e->getMessage()];
    }
    if ($config['enabled'] || $config['base_url'] !== '') {
        $valid = backup_webdav_validate_base_url($config['base_url']);
        if (!$valid['ok']) {
            return $valid;
        }
    }
    $dir = dirname(BACKUP_WEBDAV_CONFIG_FILE);
    if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
        return ['ok' => false, 'msg' => '无法创建 WebDAV 配置目录'];
    }
    $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json) || @file_put_contents(BACKUP_WEBDAV_CONFIG_FILE, $json, LOCK_EX) === false) {
        return ['ok' => false, 'msg' => 'WebDAV 配置写入失败'];
    }
    @chmod(BACKUP_WEBDAV_CONFIG_FILE, 0600);
    return ['ok' => true, 'msg' => 'WebDAV 配置已保存', 'data' => ['config' => backup_webdav_public_config($config)]];
}

function backup_webdav_ensure_dirs(): void {
    foreach ([BACKUP_WEBDAV_JOBS_DIR, BACKUP_WEBDAV_TMP_DIR] as $dir) {
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
    }
}

function backup_webdav_encode_path(string $path): string {
    $segments = array_values(array_filter(explode('/', trim($path, '/')), static fn(string $v): bool => $v !== ''));
    return $segments === [] ? '' : '/' . implode('/', array_map('rawurlencode', $segments));
}

function backup_webdav_base_collection_url(array $config): string {
    return rtrim((string)$config['base_url'], '/') . backup_webdav_encode_path((string)$config['remote_dir']);
}

function backup_webdav_file_url(array $config, string $filename): string {
    return backup_webdav_base_collection_url($config) . '/' . rawurlencode($filename);
}

function backup_webdav_public_ip(string $ip): bool {
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
}

/** @return array{ok:bool,msg:string,resolve?:string} */
function backup_webdav_validate_request_target(string $url, bool $ssrfProtection): array {
    $valid = backup_webdav_validate_base_url($url);
    if (!$valid['ok'] || !$ssrfProtection) {
        return $valid;
    }
    $host = (string)(parse_url($url, PHP_URL_HOST) ?? '');
    if ($host === '') {
        return ['ok' => false, 'msg' => '目标主机无效'];
    }
    $ips = [];
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        $ips[] = $host;
    } else {
        $v4 = @gethostbynamel($host) ?: [];
        foreach ($v4 as $ip) {
            $ips[] = $ip;
        }
        if (function_exists('dns_get_record')) {
            foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $record) {
                if (!empty($record['ipv6'])) {
                    $ips[] = (string)$record['ipv6'];
                }
            }
        }
    }
    $ips = array_values(array_unique($ips));
    if ($ips === []) {
        return ['ok' => false, 'msg' => '无法解析 WebDAV 主机'];
    }
    foreach ($ips as $ip) {
        if (!backup_webdav_public_ip($ip)) {
            return ['ok' => false, 'msg' => 'SSRF 防护已开启，目标解析到了非公网地址：' . $ip];
        }
    }
    return ['ok' => true, 'msg' => '', 'resolve' => $ips[0]];
}

function backup_webdav_parse_response_headers(array $lines): array {
    $headers = [];
    foreach ($lines as $line) {
        $pos = strpos($line, ':');
        if ($pos === false) {
            continue;
        }
        $name = strtolower(trim(substr($line, 0, $pos)));
        $value = trim(substr($line, $pos + 1));
        if ($name !== '') {
            $headers[$name] = $value;
        }
    }
    return $headers;
}

/**
 * @param array{body?:string,upload_file?:string,download_file?:string,max_response_bytes?:int,headers?:array<int,string>} $options
 * @return array{ok:bool,status:int,body:string,headers:array,error:string,bytes:int}
 */
function backup_webdav_request(array $config, string $method, string $url, array $options = []): array {
    $target = backup_webdav_validate_request_target($url, !empty($config['ssrf_protection']));
    if (!$target['ok']) {
        return ['ok' => false, 'status' => 0, 'body' => '', 'headers' => [], 'error' => $target['msg'], 'bytes' => 0];
    }
    $method = strtoupper($method);
    $headers = array_values(array_map('strval', $options['headers'] ?? []));
    $maxResponse = max(1024, (int)($options['max_response_bytes'] ?? BACKUP_WEBDAV_MAX_RESPONSE_BYTES));
    $downloadFile = (string)($options['download_file'] ?? '');
    $uploadFile = (string)($options['upload_file'] ?? '');
    $bodyInput = array_key_exists('body', $options) ? (string)$options['body'] : null;

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        $responseHeaders = [];
        $responseBody = '';
        $responseBytes = 0;
        $downloadHandle = null;
        $uploadHandle = null;
        if ($downloadFile !== '') {
            $downloadHandle = @fopen($downloadFile, 'wb');
            if (!is_resource($downloadHandle)) {
                return ['ok' => false, 'status' => 0, 'body' => '', 'headers' => [], 'error' => '无法创建下载临时文件', 'bytes' => 0];
            }
            @chmod($downloadFile, 0600);
        }
        if ($uploadFile !== '') {
            $uploadHandle = @fopen($uploadFile, 'rb');
            if (!is_resource($uploadHandle)) {
                if (is_resource($downloadHandle)) @fclose($downloadHandle);
                return ['ok' => false, 'status' => 0, 'body' => '', 'headers' => [], 'error' => '无法读取待上传备份', 'bytes' => 0];
            }
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => (int)$config['connect_timeout'],
            CURLOPT_TIMEOUT => (int)$config['request_timeout'],
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => !empty($config['tls_verify']),
            CURLOPT_SSL_VERIFYHOST => !empty($config['tls_verify']) ? 2 : 0,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$responseHeaders): int {
                $trimmed = trim($line);
                if ($trimmed !== '') {
                    $responseHeaders[] = $trimmed;
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$responseBody, &$responseBytes, $downloadHandle, $maxResponse): int {
                $length = strlen($chunk);
                $responseBytes += $length;
                if ($responseBytes > $maxResponse) {
                    return 0;
                }
                if (is_resource($downloadHandle)) {
                    $written = fwrite($downloadHandle, $chunk);
                    return $written === false ? 0 : $written;
                }
                $responseBody .= $chunk;
                return $length;
            },
        ]);
        if (!empty($config['auth_enabled'])) {
            $mode = (string)$config['auth_mode'];
            $auth = $mode === 'digest' ? CURLAUTH_DIGEST : ($mode === 'auto' ? CURLAUTH_ANY : CURLAUTH_BASIC);
            curl_setopt($ch, CURLOPT_HTTPAUTH, $auth);
            curl_setopt($ch, CURLOPT_USERPWD, (string)$config['username'] . ':' . (string)$config['password']);
        }
        if (!empty($target['resolve'])) {
            $host = (string)parse_url($url, PHP_URL_HOST);
            $port = (int)(parse_url($url, PHP_URL_PORT) ?: (parse_url($url, PHP_URL_SCHEME) === 'https' ? 443 : 80));
            curl_setopt($ch, CURLOPT_RESOLVE, [$host . ':' . $port . ':' . $target['resolve']]);
        }
        if (is_resource($uploadHandle)) {
            curl_setopt($ch, CURLOPT_UPLOAD, true);
            curl_setopt($ch, CURLOPT_INFILE, $uploadHandle);
            curl_setopt($ch, CURLOPT_INFILESIZE, (int)filesize($uploadFile));
        } elseif ($bodyInput !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $bodyInput);
        }
        if ($method === 'HEAD') {
            curl_setopt($ch, CURLOPT_NOBODY, true);
        }
        $executed = curl_exec($ch);
        $error = $executed === false ? curl_error($ch) : '';
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if (is_resource($downloadHandle)) fclose($downloadHandle);
        if (is_resource($uploadHandle)) fclose($uploadHandle);
        if ($executed === false && $responseBytes > $maxResponse) {
            $error = '响应或下载文件超过允许大小';
        }
        return [
            'ok' => $executed !== false && $status >= 200 && $status < 300,
            'status' => $status,
            'body' => $responseBody,
            'headers' => backup_webdav_parse_response_headers($responseHeaders),
            'error' => $error,
            'bytes' => $responseBytes,
        ];
    }

    if (!empty($config['auth_enabled']) && (string)$config['auth_mode'] !== 'basic') {
        return ['ok' => false, 'status' => 0, 'body' => '', 'headers' => [], 'error' => '当前 PHP 未启用 curl，无法使用 Digest/Auto 认证', 'bytes' => 0];
    }
    if (!empty($config['auth_enabled'])) {
        $headers[] = 'Authorization: Basic ' . base64_encode((string)$config['username'] . ':' . (string)$config['password']);
    }
    $content = $bodyInput;
    if ($uploadFile !== '') {
        $content = @file_get_contents($uploadFile);
        if ($content === false) {
            return ['ok' => false, 'status' => 0, 'body' => '', 'headers' => [], 'error' => '无法读取待上传备份', 'bytes' => 0];
        }
    }
    $context = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers) . "\r\n",
            'content' => $content ?? '',
            'timeout' => (int)$config['request_timeout'],
            'ignore_errors' => true,
            'follow_location' => 0,
        ],
        'ssl' => [
            'verify_peer' => !empty($config['tls_verify']),
            'verify_peer_name' => !empty($config['tls_verify']),
        ],
    ]);
    $body = @file_get_contents($url, false, $context);
    $responseHeaderLines = $http_response_header ?? [];
    $status = 0;
    foreach ($responseHeaderLines as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
            $status = (int)$m[1];
        }
    }
    $body = $body === false ? '' : (string)$body;
    if (strlen($body) > $maxResponse) {
        return ['ok' => false, 'status' => $status, 'body' => '', 'headers' => [], 'error' => '响应或下载文件超过允许大小', 'bytes' => strlen($body)];
    }
    if ($downloadFile !== '' && @file_put_contents($downloadFile, $body, LOCK_EX) === false) {
        return ['ok' => false, 'status' => $status, 'body' => '', 'headers' => [], 'error' => '下载临时文件写入失败', 'bytes' => strlen($body)];
    }
    return [
        'ok' => $status >= 200 && $status < 300,
        'status' => $status,
        'body' => $downloadFile === '' ? $body : '',
        'headers' => backup_webdav_parse_response_headers($responseHeaderLines),
        'error' => !($status >= 200 && $status < 300) ? 'WebDAV 请求失败' : '',
        'bytes' => strlen($body),
    ];
}

function backup_webdav_error_message(array $result, string $operation): string {
    $status = (int)($result['status'] ?? 0);
    $detail = trim((string)($result['error'] ?? ''));
    $known = [
        401 => '认证失败',
        403 => '没有访问权限',
        404 => '远端路径或文件不存在',
        405 => 'WebDAV 服务不支持该操作',
        409 => '远端父目录不存在',
        423 => '远端文件被锁定',
        507 => 'WebDAV 存储空间不足',
    ];
    if (isset($known[$status])) {
        return $operation . '失败：' . $known[$status] . '（HTTP ' . $status . '）';
    }
    if ($detail !== '') {
        return $operation . '失败：' . $detail . ($status > 0 ? '（HTTP ' . $status . '）' : '');
    }
    return $operation . '失败' . ($status > 0 ? '（HTTP ' . $status . '）' : '');
}

function backup_webdav_ensure_remote_dir(array $config): array {
    $base = rtrim((string)$config['base_url'], '/');
    $segments = array_values(array_filter(explode('/', trim((string)$config['remote_dir'], '/')), static fn(string $v): bool => $v !== ''));
    $current = $base;
    foreach ($segments as $segment) {
        $current .= '/' . rawurlencode($segment);
        $result = backup_webdav_request($config, 'MKCOL', $current);
        if (!$result['ok'] && !in_array((int)$result['status'], [405], true)) {
            return ['ok' => false, 'msg' => backup_webdav_error_message($result, '创建远端目录')];
        }
    }
    return ['ok' => true, 'msg' => '远端目录已就绪'];
}

function backup_webdav_parse_multistatus(string $xml): array {
    if (!class_exists('DOMDocument')) {
        throw new RuntimeException('PHP DOM 扩展未启用，无法解析 WebDAV 目录响应');
    }
    $previous = libxml_use_internal_errors(true);
    $doc = new DOMDocument();
    $loaded = $doc->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_NOCDATA);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    if (!$loaded) {
        throw new RuntimeException('WebDAV 返回了无效 XML');
    }
    $xpath = new DOMXPath($doc);
    $xpath->registerNamespace('d', 'DAV:');
    $rows = [];
    foreach ($xpath->query('//d:response') ?: [] as $response) {
        $value = static function (string $expression) use ($xpath, $response): string {
            $nodes = $xpath->query($expression, $response);
            return $nodes && $nodes->length > 0 ? trim((string)$nodes->item(0)->textContent) : '';
        };
        $rows[] = [
            'href' => $value('./d:href'),
            'size' => (int)$value('.//d:getcontentlength'),
            'modified' => $value('.//d:getlastmodified'),
            'etag' => trim($value('.//d:getetag'), '"'),
            'is_collection' => ($xpath->query('.//d:resourcetype/d:collection', $response)?->length ?? 0) > 0,
        ];
    }
    return $rows;
}

function backup_webdav_remote_filename_pattern(array $config): string {
    return '/^riverops_' . preg_quote((string)$config['instance_id'], '/') . '_backup_(\d{8})_(\d{6})_([a-z0-9_]+)\.json$/';
}

function backup_webdav_remote_filename(array $config, string $localFilename): string {
    $localFilename = basename($localFilename);
    if (!preg_match('/^backup_[\d_a-z]+\.json$/', $localFilename)) {
        throw new InvalidArgumentException('本地备份文件名无效');
    }
    return 'riverops_' . $config['instance_id'] . '_' . $localFilename;
}

function backup_webdav_remote_item_from_row(array $config, array $row): ?array {
    $path = rawurldecode((string)(parse_url((string)($row['href'] ?? ''), PHP_URL_PATH) ?? ''));
    $filename = basename($path);
    if (!preg_match(backup_webdav_remote_filename_pattern($config), $filename, $m)) {
        return null;
    }
    $timestamp = strtotime($m[1] . ' ' . substr($m[2], 0, 2) . ':' . substr($m[2], 2, 2) . ':' . substr($m[2], 4, 2));
    return [
        'filename' => $filename,
        'created_at' => $timestamp === false ? '' : date('Y-m-d H:i:s', $timestamp),
        'trigger' => $m[3],
        'size' => max(0, (int)($row['size'] ?? 0)),
        'modified' => (string)($row['modified'] ?? ''),
        'etag' => (string)($row['etag'] ?? ''),
    ];
}

function backup_webdav_list_remote(?array $config = null): array {
    $config = $config ?? backup_webdav_load_config();
    if (trim((string)$config['base_url']) === '') {
        return ['ok' => false, 'msg' => '尚未配置 WebDAV URL', 'data' => ['items' => []]];
    }
    $body = '<?xml version="1.0" encoding="utf-8" ?>'
        . '<d:propfind xmlns:d="DAV:"><d:prop><d:getcontentlength/><d:getlastmodified/><d:getetag/><d:resourcetype/></d:prop></d:propfind>';
    $result = backup_webdav_request($config, 'PROPFIND', backup_webdav_base_collection_url($config), [
        'body' => $body,
        'headers' => ['Depth: 1', 'Content-Type: application/xml; charset=utf-8'],
    ]);
    if ((int)$result['status'] === 404) {
        return ['ok' => true, 'msg' => '远端目录尚未创建', 'data' => ['items' => []]];
    }
    if ((int)$result['status'] !== 207) {
        return ['ok' => false, 'msg' => backup_webdav_error_message($result, '读取远端备份列表'), 'data' => ['items' => []]];
    }
    try {
        $rows = backup_webdav_parse_multistatus((string)$result['body']);
    } catch (Throwable $e) {
        return ['ok' => false, 'msg' => $e->getMessage(), 'data' => ['items' => []]];
    }
    $items = [];
    foreach ($rows as $row) {
        $item = backup_webdav_remote_item_from_row($config, $row);
        if ($item !== null) {
            $items[] = $item;
        }
    }
    usort($items, static fn(array $a, array $b): int => strcmp((string)$b['created_at'], (string)$a['created_at']));
    return ['ok' => true, 'msg' => '远端备份列表已加载', 'data' => ['items' => $items]];
}

function backup_webdav_remote_filename_valid(array $config, string $filename): bool {
    return preg_match(backup_webdav_remote_filename_pattern($config), basename($filename)) === 1;
}

function backup_webdav_delete_remote(string $filename, ?array $config = null): array {
    $config = $config ?? backup_webdav_load_config();
    $filename = basename($filename);
    if (!backup_webdav_remote_filename_valid($config, $filename)) {
        return ['ok' => false, 'msg' => '远端备份文件名无效'];
    }
    $result = backup_webdav_request($config, 'DELETE', backup_webdav_file_url($config, $filename));
    if (!$result['ok'] && (int)$result['status'] !== 404) {
        return ['ok' => false, 'msg' => backup_webdav_error_message($result, '删除远端备份')];
    }
    return ['ok' => true, 'msg' => '远端备份已删除'];
}

function backup_webdav_retention_candidates(array $items, int $keep = BACKUP_WEBDAV_RETENTION): array {
    usort($items, static fn(array $a, array $b): int => strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? '')));
    return array_slice($items, max(0, $keep));
}

function backup_webdav_cleanup_remote(?array $config = null): array {
    $config = $config ?? backup_webdav_load_config();
    $list = backup_webdav_list_remote($config);
    if (!$list['ok']) {
        return $list;
    }
    $deleted = [];
    $failed = [];
    foreach (backup_webdav_retention_candidates($list['data']['items'] ?? [], BACKUP_WEBDAV_RETENTION) as $item) {
        $filename = (string)($item['filename'] ?? '');
        $result = backup_webdav_delete_remote($filename, $config);
        if ($result['ok']) {
            $deleted[] = $filename;
        } else {
            $failed[] = ['filename' => $filename, 'msg' => $result['msg'] ?? '删除失败'];
        }
    }
    return [
        'ok' => $failed === [],
        'msg' => $failed === [] ? '远端保留策略已执行' : '备份上传成功，但部分旧备份清理失败',
        'data' => ['deleted' => $deleted, 'failed' => $failed],
    ];
}

function backup_webdav_validate_local_backup(string $path): array {
    if (!is_file($path) || !is_readable($path)) {
        return ['ok' => false, 'msg' => '备份文件不存在或不可读'];
    }
    $size = filesize($path);
    if ($size === false || $size <= 0 || $size > BACKUP_WEBDAV_MAX_DOWNLOAD_BYTES) {
        return ['ok' => false, 'msg' => '备份文件为空或超过 32MB'];
    }
    try {
        $data = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable $e) {
        return ['ok' => false, 'msg' => '备份 JSON 解析失败：' . $e->getMessage()];
    }
    if (!is_array($data)) {
        return ['ok' => false, 'msg' => '备份文件结构无效'];
    }
    if (function_exists('backup_validate_payload')) {
        return backup_validate_payload($data);
    }
    $sections = ['config', 'scheduled_tasks', 'dns_config', 'ddns_tasks', 'domain_expiry'];
    foreach ($sections as $section) {
        if (isset($data[$section]) && is_array($data[$section])) {
            return ['ok' => true, 'msg' => '备份文件有效', 'data' => $data];
        }
    }
    return ['ok' => false, 'msg' => '未识别到有效备份内容'];
}

function backup_webdav_upload_local(string $localFilename, ?array $config = null): array {
    $config = $config ?? backup_webdav_load_config();
    $localFilename = basename($localFilename);
    if (!preg_match('/^backup_[\d_a-z]+\.json$/', $localFilename)) {
        return ['ok' => false, 'msg' => '本地备份文件名无效'];
    }
    $localPath = BACKUPS_DIR . '/' . $localFilename;
    $valid = backup_webdav_validate_local_backup($localPath);
    if (!$valid['ok']) {
        return $valid;
    }
    $ensure = backup_webdav_ensure_remote_dir($config);
    if (!$ensure['ok']) {
        return $ensure;
    }
    try {
        $remoteFilename = backup_webdav_remote_filename($config, $localFilename);
    } catch (Throwable $e) {
        return ['ok' => false, 'msg' => $e->getMessage()];
    }
    $partFilename = '.' . $remoteFilename . '.part_' . bin2hex(random_bytes(4));
    $partUrl = backup_webdav_file_url($config, $partFilename);
    $finalUrl = backup_webdav_file_url($config, $remoteFilename);
    $upload = backup_webdav_request($config, 'PUT', $partUrl, [
        'upload_file' => $localPath,
        'headers' => ['Content-Type: application/json; charset=utf-8'],
        'max_response_bytes' => 64 * 1024,
    ]);
    if (!$upload['ok']) {
        return ['ok' => false, 'msg' => backup_webdav_error_message($upload, '上传 WebDAV 备份')];
    }
    $move = backup_webdav_request($config, 'MOVE', $partUrl, [
        'headers' => ['Destination: ' . $finalUrl, 'Overwrite: T'],
        'max_response_bytes' => 64 * 1024,
    ]);
    if (!$move['ok']) {
        $fallback = backup_webdav_request($config, 'PUT', $finalUrl, [
            'upload_file' => $localPath,
            'headers' => ['Content-Type: application/json; charset=utf-8'],
            'max_response_bytes' => 64 * 1024,
        ]);
        backup_webdav_request($config, 'DELETE', $partUrl, ['max_response_bytes' => 64 * 1024]);
        if (!$fallback['ok']) {
            return ['ok' => false, 'msg' => backup_webdav_error_message($fallback, '完成 WebDAV 备份上传')];
        }
    }
    $head = backup_webdav_request($config, 'HEAD', $finalUrl, ['max_response_bytes' => 64 * 1024]);
    if (!$head['ok']) {
        return ['ok' => false, 'msg' => backup_webdav_error_message($head, '验证远端备份')];
    }
    $remoteSize = (int)($head['headers']['content-length'] ?? 0);
    $localSize = (int)filesize($localPath);
    if ($remoteSize > 0 && $remoteSize !== $localSize) {
        return ['ok' => false, 'msg' => '远端备份大小校验失败'];
    }
    $cleanup = backup_webdav_cleanup_remote($config);
    return [
        'ok' => true,
        'msg' => $cleanup['ok'] ? '备份已上传到 WebDAV' : '备份已上传，但旧备份清理存在失败',
        'data' => [
            'local_filename' => $localFilename,
            'remote_filename' => $remoteFilename,
            'size' => $localSize,
            'cleanup' => $cleanup,
        ],
    ];
}

function backup_webdav_download_remote(string $remoteFilename, ?array $config = null): array {
    backup_webdav_ensure_dirs();
    $config = $config ?? backup_webdav_load_config();
    $remoteFilename = basename($remoteFilename);
    if (!backup_webdav_remote_filename_valid($config, $remoteFilename)) {
        return ['ok' => false, 'msg' => '远端备份文件名无效'];
    }
    $tmp = BACKUP_WEBDAV_TMP_DIR . '/download_' . bin2hex(random_bytes(8)) . '.json.part';
    $result = backup_webdav_request($config, 'GET', backup_webdav_file_url($config, $remoteFilename), [
        'download_file' => $tmp,
        'max_response_bytes' => BACKUP_WEBDAV_MAX_DOWNLOAD_BYTES,
    ]);
    if (!$result['ok']) {
        @unlink($tmp);
        return ['ok' => false, 'msg' => backup_webdav_error_message($result, '下载远端备份')];
    }
    $valid = backup_webdav_validate_local_backup($tmp);
    if (!$valid['ok']) {
        @unlink($tmp);
        return $valid;
    }
    return ['ok' => true, 'msg' => '远端备份已下载并通过校验', 'data' => ['tmp_path' => $tmp, 'size' => (int)filesize($tmp)]];
}

function backup_webdav_store_downloaded_backup(string $tmpPath, string $trigger = 'webdav_download'): array {
    if (!is_file($tmpPath)) {
        return ['ok' => false, 'msg' => '下载临时文件不存在'];
    }
    if (!is_dir(BACKUPS_DIR)) {
        @mkdir(BACKUPS_DIR, 0750, true);
    }
    $filename = 'backup_' . date('Ymd_His') . '_' . preg_replace('/[^a-z0-9_]/', '', strtolower($trigger)) . '.json';
    $path = BACKUPS_DIR . '/' . $filename;
    $suffix = 1;
    while (file_exists($path)) {
        $filename = 'backup_' . date('Ymd_His') . '_' . preg_replace('/[^a-z0-9_]/', '', strtolower($trigger)) . '_' . $suffix . '.json';
        $path = BACKUPS_DIR . '/' . $filename;
        $suffix++;
    }
    try {
        $payload = json_decode((string)file_get_contents($tmpPath), true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable $e) {
        return ['ok' => false, 'msg' => '下载备份解析失败：' . $e->getMessage()];
    }
    if (!is_array($payload)) {
        return ['ok' => false, 'msg' => '下载备份结构无效'];
    }
    $payload['remote_created_at'] = (string)($payload['created_at'] ?? '');
    $payload['created_at'] = date('Y-m-d H:i:s');
    $payload['trigger'] = $trigger;
    try {
        backup_write_json_file($path, $payload);
    } catch (Throwable $e) {
        return ['ok' => false, 'msg' => '无法保存下载的本地备份：' . $e->getMessage()];
    }
    @unlink($tmpPath);
    @chmod($path, 0600);
    backup_cleanup();
    return ['ok' => true, 'msg' => '远端备份已保存到本地', 'data' => ['filename' => $filename, 'path' => $path]];
}

function backup_webdav_test_connection(?array $config = null): array {
    $config = $config ?? backup_webdav_load_config();
    if (trim((string)$config['base_url']) === '') {
        return ['ok' => false, 'msg' => '请先填写 WebDAV URL'];
    }
    $ensure = backup_webdav_ensure_remote_dir($config);
    if (!$ensure['ok']) {
        return $ensure;
    }
    $filename = '.riverops_test_' . bin2hex(random_bytes(6)) . '.tmp';
    $url = backup_webdav_file_url($config, $filename);
    $put = backup_webdav_request($config, 'PUT', $url, [
        'body' => 'RiverOps WebDAV test ' . date(DATE_ATOM),
        'headers' => ['Content-Type: text/plain; charset=utf-8'],
        'max_response_bytes' => 64 * 1024,
    ]);
    if (!$put['ok']) {
        return ['ok' => false, 'msg' => backup_webdav_error_message($put, 'WebDAV 写入测试')];
    }
    $delete = backup_webdav_request($config, 'DELETE', $url, ['max_response_bytes' => 64 * 1024]);
    if (!$delete['ok'] && (int)$delete['status'] !== 404) {
        return ['ok' => false, 'msg' => backup_webdav_error_message($delete, '清理 WebDAV 测试文件')];
    }
    return ['ok' => true, 'msg' => 'WebDAV 连接、写入和删除测试成功'];
}

function backup_webdav_clean_job_id(string $jobId): string {
    return preg_replace('/[^a-zA-Z0-9_-]/', '', $jobId) ?: '';
}

function backup_webdav_job_id(): string {
    return 'webdav_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4));
}

function backup_webdav_job_file(string $jobId): string {
    return BACKUP_WEBDAV_JOBS_DIR . '/' . backup_webdav_clean_job_id($jobId) . '.json';
}

function backup_webdav_job_log_file(string $jobId): string {
    return BACKUP_WEBDAV_JOBS_DIR . '/' . backup_webdav_clean_job_id($jobId) . '.log';
}

function backup_webdav_job_write(string $jobId, array $data): void {
    backup_webdav_ensure_dirs();
    $data['id'] = backup_webdav_clean_job_id($jobId);
    $data['updated_at'] = date('Y-m-d H:i:s');
    @file_put_contents(backup_webdav_job_file($jobId), json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    @chmod(backup_webdav_job_file($jobId), 0600);
}

function backup_webdav_job_read(string $jobId): ?array {
    $jobId = backup_webdav_clean_job_id($jobId);
    if ($jobId === '' || !is_file(backup_webdav_job_file($jobId))) {
        return null;
    }
    $handle = @fopen(backup_webdav_job_file($jobId), 'rb');
    if (!is_resource($handle)) {
        return null;
    }
    @flock($handle, LOCK_SH);
    $raw = stream_get_contents($handle);
    @flock($handle, LOCK_UN);
    fclose($handle);
    $data = json_decode(is_string($raw) ? $raw : '{}', true);
    return is_array($data) ? $data : null;
}

function backup_webdav_job_update(string $jobId, array $patch): array {
    $job = backup_webdav_job_read($jobId) ?? ['id' => backup_webdav_clean_job_id($jobId), 'status' => 'running'];
    $job = array_merge($job, $patch);
    backup_webdav_job_write($jobId, $job);
    return $job;
}

function backup_webdav_job_append_log(string $jobId, string $line): void {
    backup_webdav_ensure_dirs();
    $line = trim(str_replace(["\r\n", "\r"], "\n", $line));
    if ($line === '') return;
    @file_put_contents(backup_webdav_job_log_file($jobId), '[' . date('H:i:s') . '] ' . $line . "\n", FILE_APPEND | LOCK_EX);
}

function backup_webdav_job_tail_log(string $jobId): string {
    $file = backup_webdav_job_log_file($jobId);
    if (!is_file($file)) return '';
    $size = filesize($file);
    if ($size === false) return '';
    $offset = max(0, $size - BACKUP_WEBDAV_JOB_LOG_BYTES);
    return (string)@file_get_contents($file, false, null, $offset);
}

function backup_webdav_job_finish(string $jobId, bool $ok, string $message, array $extra = []): array {
    $job = backup_webdav_job_update($jobId, array_merge($extra, [
        'status' => $ok ? 'success' : 'failed',
        'phase' => $ok ? '完成' : '失败',
        'percent' => 100,
        'message' => $message,
        'finished_at' => date('Y-m-d H:i:s'),
    ]));
    backup_webdav_job_append_log($jobId, $message);
    return $job;
}

function backup_webdav_job_process_exists(int $pid, string $jobId): bool {
    if ($pid <= 0) return false;
    if (is_dir('/proc')) {
        $dir = '/proc/' . $pid;
        if (!is_dir($dir)) return false;
        $stat = @file_get_contents($dir . '/stat');
        if (is_string($stat) && preg_match('/^\d+ \(.+\) ([A-Z]) /', $stat, $m) && $m[1] === 'Z') return false;
        $cmd = @file_get_contents($dir . '/cmdline');
        return !is_string($cmd) || $cmd === '' || str_contains(str_replace("\0", ' ', $cmd), backup_webdav_clean_job_id($jobId));
    }
    return function_exists('posix_kill') ? @posix_kill($pid, 0) : false;
}

function backup_webdav_job_public_payload(string $jobId): ?array {
    $job = backup_webdav_job_read($jobId);
    if ($job === null) return null;
    if (in_array((string)($job['status'] ?? ''), ['queued', 'running'], true)) {
        $pid = (int)($job['pid'] ?? 0);
        $updated = strtotime((string)($job['updated_at'] ?? $job['created_at'] ?? '')) ?: time();
        $grace = $pid > 0 ? 5 : 30;
        if (time() - $updated >= $grace && ($pid <= 0 || !backup_webdav_job_process_exists($pid, $jobId))) {
            $job = backup_webdav_job_finish($jobId, false, 'WebDAV 后台任务异常退出');
        }
    }
    unset($job['params']);
    $job['log'] = backup_webdav_job_tail_log($jobId);
    return $job;
}

function backup_webdav_current_job(): ?array {
    backup_webdav_ensure_dirs();
    $files = glob(BACKUP_WEBDAV_JOBS_DIR . '/*.json') ?: [];
    usort($files, static fn(string $a, string $b): int => ((int)@filemtime($b)) <=> ((int)@filemtime($a)));
    foreach ($files as $file) {
        $job = backup_webdav_job_public_payload(basename($file, '.json'));
        if ($job !== null && in_array((string)($job['status'] ?? ''), ['queued', 'running'], true)) {
            return $job;
        }
    }
    return null;
}

function backup_webdav_start_job(string $action, array $params, string $operator): array {
    $allowed = ['test_connection', 'create_upload', 'upload_local', 'download_remote', 'restore_remote', 'delete_remote'];
    if (!in_array($action, $allowed, true)) {
        return ['ok' => false, 'msg' => '未知 WebDAV 任务'];
    }
    backup_webdav_ensure_dirs();
    $lock = @fopen(BACKUP_WEBDAV_START_LOCK, 'c+');
    if (!is_resource($lock) || !@flock($lock, LOCK_EX)) {
        if (is_resource($lock)) fclose($lock);
        return ['ok' => false, 'msg' => '无法锁定 WebDAV 任务队列'];
    }
    $current = backup_webdav_current_job();
    if ($current !== null) {
        @flock($lock, LOCK_UN);
        fclose($lock);
        return ['ok' => true, 'msg' => '已有 WebDAV 任务正在运行', 'data' => ['job_id' => $current['id'], 'existing' => true]];
    }
    $jobId = backup_webdav_job_id();
    backup_webdav_job_write($jobId, [
        'action' => $action,
        'params' => $params,
        'operator' => $operator,
        'status' => 'queued',
        'phase' => '排队中',
        'percent' => 0,
        'message' => '等待后台进程启动',
        'created_at' => date('Y-m-d H:i:s'),
    ]);
    backup_webdav_job_append_log($jobId, '创建人工 WebDAV 任务：' . $action);
    $php = is_file(PHP_BINDIR . '/php') ? PHP_BINDIR . '/php' : 'php';
    $argv = [$php, dirname(__DIR__, 2) . '/cli/backup_webdav_job.php', $jobId, DATA_DIR];
    $command = implode(' ', array_map(static fn($v): string => escapeshellarg((string)$v), $argv));
    $shell = 'cd ' . escapeshellarg(dirname(__DIR__, 2))
        . ' && if command -v setsid >/dev/null 2>&1; then nohup setsid ' . $command
        . ' >/dev/null 2>&1 </dev/null & else nohup ' . $command
        . ' >/dev/null 2>&1 </dev/null & fi; printf %s "$!"';
    $output = [];
    $code = 0;
    @exec('/bin/sh -lc ' . escapeshellarg($shell), $output, $code);
    $pid = trim(implode("\n", $output));
    if ($code !== 0 || !preg_match('/^\d+$/', $pid)) {
        backup_webdav_job_finish($jobId, false, '无法启动 WebDAV 后台任务');
        @flock($lock, LOCK_UN);
        fclose($lock);
        return ['ok' => false, 'msg' => '无法启动 WebDAV 后台任务', 'data' => ['job_id' => $jobId]];
    }
    backup_webdav_job_update($jobId, ['status' => 'running', 'pid' => (int)$pid, 'phase' => '启动中', 'percent' => 3, 'message' => '后台进程已启动']);
    @flock($lock, LOCK_UN);
    fclose($lock);
    return ['ok' => true, 'msg' => 'WebDAV 任务已启动', 'data' => ['job_id' => $jobId]];
}

function backup_webdav_audit(string $action, string $operator, array $context = []): void {
    if (!defined('AUDIT_LOG_FILE')) return;
    $dir = dirname(AUDIT_LOG_FILE);
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    $line = json_encode([
        'time' => date('Y-m-d H:i:s'),
        'user' => $operator !== '' ? $operator : 'system',
        'ip' => 'cli',
        'action' => $action,
        'context' => $context,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    @file_put_contents(AUDIT_LOG_FILE, $line . "\n", FILE_APPEND | LOCK_EX);
}

function backup_webdav_run_job(string $jobId): array {
    $job = backup_webdav_job_read($jobId);
    if ($job === null) {
        return ['ok' => false, 'msg' => 'WebDAV 任务不存在'];
    }
    $action = (string)($job['action'] ?? '');
    $params = is_array($job['params'] ?? null) ? $job['params'] : [];
    $operator = (string)($job['operator'] ?? 'system');
    $config = backup_webdav_load_config();
    backup_webdav_job_update($jobId, ['status' => 'running', 'pid' => getmypid(), 'started_at' => date('Y-m-d H:i:s')]);

    try {
        if ($action === 'test_connection') {
            backup_webdav_job_update($jobId, ['phase' => '测试连接', 'percent' => 20, 'message' => '正在测试 WebDAV 写入和删除']);
            $result = backup_webdav_test_connection($config);
        } elseif ($action === 'create_upload') {
            backup_webdav_job_update($jobId, ['phase' => '创建备份', 'percent' => 15, 'message' => '正在创建本地备份']);
            $path = backup_create('webdav_manual');
            if ($path === '') throw new RuntimeException('本地备份创建失败');
            backup_webdav_job_update($jobId, ['phase' => '上传备份', 'percent' => 45, 'message' => '正在上传到 WebDAV']);
            $result = backup_webdav_upload_local(basename($path), $config);
        } elseif ($action === 'upload_local') {
            backup_webdav_job_update($jobId, ['phase' => '上传备份', 'percent' => 35, 'message' => '正在上传本地备份']);
            $result = backup_webdav_upload_local((string)($params['filename'] ?? ''), $config);
        } elseif ($action === 'download_remote') {
            backup_webdav_job_update($jobId, ['phase' => '下载备份', 'percent' => 30, 'message' => '正在下载远端备份']);
            $download = backup_webdav_download_remote((string)($params['filename'] ?? ''), $config);
            if (!$download['ok']) {
                $result = $download;
            } else {
                backup_webdav_job_update($jobId, ['phase' => '保存本地', 'percent' => 75, 'message' => '正在保存为本地备份']);
                $result = backup_webdav_store_downloaded_backup((string)$download['data']['tmp_path'], 'webdav_download');
            }
        } elseif ($action === 'restore_remote') {
            backup_webdav_job_update($jobId, ['phase' => '下载备份', 'percent' => 20, 'message' => '正在下载并校验远端备份']);
            $download = backup_webdav_download_remote((string)($params['filename'] ?? ''), $config);
            if (!$download['ok']) {
                $result = $download;
            } else {
                $stored = backup_webdav_store_downloaded_backup((string)$download['data']['tmp_path'], 'webdav_restore');
                if (!$stored['ok']) {
                    $result = $stored;
                } else {
                    backup_webdav_job_update($jobId, ['phase' => '恢复配置', 'percent' => 70, 'message' => '正在创建保护快照并恢复配置']);
                    $ok = backup_restore((string)$stored['data']['filename']);
                    $result = ['ok' => $ok, 'msg' => $ok ? 'WebDAV 备份已恢复，恢复前状态已保存在本地' : 'WebDAV 备份恢复失败'];
                }
            }
        } elseif ($action === 'delete_remote') {
            backup_webdav_job_update($jobId, ['phase' => '删除备份', 'percent' => 45, 'message' => '正在删除远端备份']);
            $result = backup_webdav_delete_remote((string)($params['filename'] ?? ''), $config);
        } else {
            $result = ['ok' => false, 'msg' => '未知 WebDAV 任务'];
        }
    } catch (Throwable $e) {
        $result = ['ok' => false, 'msg' => $e->getMessage()];
    }

    backup_webdav_audit('backup_webdav_' . $action, $operator, [
        'ok' => !empty($result['ok']),
        'filename' => basename((string)($params['filename'] ?? ($result['data']['remote_filename'] ?? ''))),
        'message' => (string)($result['msg'] ?? ''),
    ]);
    return backup_webdav_job_finish($jobId, !empty($result['ok']), (string)($result['msg'] ?? 'WebDAV 任务结束'), ['result' => $result['data'] ?? null]);
}
