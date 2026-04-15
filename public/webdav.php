<?php
declare(strict_types=1);

require_once __DIR__ . '/../shared/auth.php';
require_once __DIR__ . '/../admin/shared/webdav_lib.php';

function webdav_send_status(int $status, string $body = '', array $headers = []): void {
    http_response_code($status);
    foreach ($headers as $name => $value) {
        header($name . ': ' . $value);
    }
    if ($body !== '') {
        echo $body;
    }
    exit;
}

function webdav_unauthorized(): void {
    header('WWW-Authenticate: Basic realm="WebDAV"');
    webdav_send_status(401, 'Unauthorized');
}

function webdav_forbidden(string $message = 'Forbidden'): void {
    webdav_send_status(403, $message);
}

function webdav_not_found(): void {
    webdav_send_status(404, 'Not Found');
}

function webdav_conflict(string $message): void {
    webdav_send_status(409, $message);
}

function webdav_insufficient_storage(string $message): void {
    webdav_send_status(507, $message);
}

function webdav_server_path(string $baseUri): string {
    $uriPath = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/webdav/'), PHP_URL_PATH);
    $baseUri = rtrim($baseUri, '/');
    if ($uriPath === $baseUri) {
        return '/';
    }
    if (strpos($uriPath, $baseUri . '/') === 0) {
        return substr($uriPath, strlen($baseUri));
    }
    return '/';
}

function webdav_auth_credentials(): array {
    $user = (string)($_SERVER['PHP_AUTH_USER'] ?? '');
    $pass = (string)($_SERVER['PHP_AUTH_PW'] ?? '');
    if ($user !== '') {
        return [$user, $pass];
    }
    $header = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if (stripos($header, 'Basic ') === 0) {
        $decoded = base64_decode(substr($header, 6), true);
        if ($decoded !== false && strpos($decoded, ':') !== false) {
            return explode(':', $decoded, 2);
        }
    }
    return ['', ''];
}

function webdav_children(string $path): array {
    $items = [];
    foreach (scandir($path) ?: [] as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        $items[] = $path . '/' . $name;
    }
    sort($items);
    return $items;
}

auth_check_setup();
if (!webdav_enabled()) {
    webdav_not_found();
}

[$user, $pass] = webdav_auth_credentials();
$account = webdav_authenticate($user, $pass);
if ($account === null) {
    webdav_unauthorized();
}
$clientIp = webdav_client_ip();
if (!webdav_ip_allowed($account, $clientIp)) {
    webdav_audit('ip_denied', ['ip' => $clientIp, 'username' => (string)$account['username']], (string)$account['username']);
    webdav_forbidden('IP not allowed');
}

$baseUri = (string)($_SERVER['WEBDAV_BASE_URI'] ?? '/webdav');
$requestPath = webdav_server_path($baseUri);
$resolved = webdav_safe_path((string)$account['root'], $requestPath);
if (empty($resolved['ok'])) {
    webdav_forbidden((string)($resolved['msg'] ?? '非法路径'));
}
$targetPath = (string)$resolved['path'];
$relative = (string)$resolved['relative'];
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$readonly = !empty($account['readonly']);

if ($method === 'OPTIONS') {
    webdav_send_status(204, '', [
        'DAV' => '1',
        'Allow' => 'OPTIONS, PROPFIND, GET, HEAD, PUT, DELETE, MKCOL, MOVE, COPY',
        'MS-Author-Via' => 'DAV',
    ]);
}

if ($method === 'PROPFIND') {
    if (!file_exists($targetPath)) {
        webdav_not_found();
    }
    $depth = (string)($_SERVER['HTTP_DEPTH'] ?? '0');
    $items = [[
        'relative' => $relative,
        'displayname' => basename(rtrim($relative, '/')) ?: '/',
        'is_dir' => is_dir($targetPath),
        'size' => is_file($targetPath) ? (filesize($targetPath) ?: 0) : 0,
        'mtime' => filemtime($targetPath) ?: time(),
        'content_type' => is_file($targetPath) ? ((mime_content_type($targetPath) ?: 'application/octet-stream')) : '',
    ]];
    if ($depth !== '0' && is_dir($targetPath)) {
        foreach (webdav_children($targetPath) as $child) {
            $childRelative = $relative === '/' ? ('/' . basename($child)) : ($relative . '/' . basename($child));
            $items[] = [
                'relative' => $childRelative,
                'displayname' => basename($child),
                'is_dir' => is_dir($child),
                'size' => is_file($child) ? (filesize($child) ?: 0) : 0,
                'mtime' => filemtime($child) ?: time(),
                'content_type' => is_file($child) ? ((mime_content_type($child) ?: 'application/octet-stream')) : '',
            ];
        }
    }
    $body = webdav_multistatus_xml($baseUri, $items);
    webdav_audit('propfind', ['path' => $relative, 'depth' => $depth, 'root' => (string)$account['root']], $user);
    webdav_send_status(207, $body, ['Content-Type' => 'application/xml; charset=utf-8']);
}

if ($method === 'GET' || $method === 'HEAD') {
    if (!is_file($targetPath)) {
        webdav_not_found();
    }
    header('Content-Type: ' . (mime_content_type($targetPath) ?: 'application/octet-stream'));
    header('Content-Length: ' . (string)(filesize($targetPath) ?: 0));
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', filemtime($targetPath) ?: time()) . ' GMT');
    webdav_audit($method === 'GET' ? 'get' : 'head', ['path' => $relative, 'root' => (string)$account['root']], $user);
    if ($method === 'HEAD') {
        exit;
    }
    readfile($targetPath);
    exit;
}

if ($readonly) {
    webdav_forbidden('WebDAV is readonly');
}

if ($method === 'PUT') {
    $input = file_get_contents('php://input');
    $content = $input === false ? '' : $input;
    $incomingBytes = strlen((string)$content);
    $maxUploadBytes = webdav_max_upload_bytes($account);
    if ($maxUploadBytes > 0 && $incomingBytes > $maxUploadBytes) {
        webdav_audit('put_rejected_max_upload', ['path' => $relative, 'bytes' => $incomingBytes, 'max_upload_bytes' => $maxUploadBytes], $user);
        webdav_send_status(413, 'File too large');
    }
    $quotaBytes = webdav_quota_bytes($account);
    if ($quotaBytes > 0) {
        $currentUsage = webdav_directory_size((string)$account['root']);
        $existingBytes = is_file($targetPath) ? (int)(filesize($targetPath) ?: 0) : 0;
        $nextUsage = $currentUsage - $existingBytes + $incomingBytes;
        if ($nextUsage > $quotaBytes) {
            webdav_audit('put_rejected_quota', ['path' => $relative, 'bytes' => $incomingBytes, 'quota_bytes' => $quotaBytes, 'next_usage' => $nextUsage], $user);
            webdav_insufficient_storage('Quota exceeded');
        }
    }
    $dir = dirname($targetPath);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        webdav_send_status(500, 'mkdir failed');
    }
    file_put_contents($targetPath, $content);
    webdav_audit('put', ['path' => $relative, 'bytes' => $incomingBytes, 'root' => (string)$account['root']], $user);
    webdav_send_status(is_file($targetPath) ? 201 : 500);
}

if ($method === 'DELETE') {
    if (!file_exists($targetPath)) {
        webdav_not_found();
    }
    $ok = webdav_delete_tree($targetPath);
    webdav_audit('delete', ['path' => $relative, 'ok' => $ok, 'root' => (string)$account['root']], $user);
    webdav_send_status($ok ? 204 : 500);
}

if ($method === 'MKCOL') {
    if (file_exists($targetPath)) {
        webdav_send_status(405, 'Already Exists');
    }
    $ok = @mkdir($targetPath, 0755, true);
    webdav_audit('mkcol', ['path' => $relative, 'ok' => $ok, 'root' => (string)$account['root']], $user);
    webdav_send_status($ok ? 201 : 500);
}

if ($method === 'MOVE' || $method === 'COPY') {
    if (!file_exists($targetPath)) {
        webdav_not_found();
    }
    $destination = (string)($_SERVER['HTTP_DESTINATION'] ?? '');
    if ($destination === '') {
        webdav_send_status(400, 'Destination required');
    }
    $destinationPath = (string)parse_url($destination, PHP_URL_PATH);
    if ($destinationPath === '') {
        webdav_send_status(400, 'Invalid destination');
    }
    $normalizedBaseUri = rtrim($baseUri, '/');
    if (strpos($destinationPath, $normalizedBaseUri . '/') !== 0 && $destinationPath !== $normalizedBaseUri) {
        webdav_forbidden('Destination out of WebDAV root');
    }
    $destinationRelative = '/';
    if ($destinationPath !== $normalizedBaseUri) {
        $destinationRelative = substr($destinationPath, strlen($normalizedBaseUri));
        if ($destinationRelative === false || $destinationRelative === '') {
            $destinationRelative = '/';
        }
    }
    $destResolved = webdav_safe_path((string)$account['root'], $destinationRelative);
    if (empty($destResolved['ok'])) {
        webdav_forbidden((string)($destResolved['msg'] ?? '非法目标路径'));
    }
    $destPath = (string)$destResolved['path'];
    $destRelative = (string)$destResolved['relative'];
    $overwrite = strtoupper((string)($_SERVER['HTTP_OVERWRITE'] ?? 'T')) !== 'F';
    if (file_exists($destPath)) {
        if (!$overwrite) {
            webdav_send_status(412, 'Destination exists');
        }
        webdav_delete_tree($destPath);
    }
    $dir = dirname($destPath);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        webdav_send_status(500, 'mkdir failed');
    }
    if ($method === 'COPY') {
        $quotaBytes = webdav_quota_bytes($account);
        if ($quotaBytes > 0) {
            $currentUsage = webdav_directory_size((string)$account['root']);
            $sourceBytes = webdav_directory_size($targetPath);
            $existingBytes = file_exists($destPath) ? webdav_directory_size($destPath) : 0;
            $nextUsage = $currentUsage - $existingBytes + $sourceBytes;
            if ($nextUsage > $quotaBytes) {
                webdav_audit('copy_rejected_quota', ['from' => $relative, 'to' => $destRelative, 'quota_bytes' => $quotaBytes, 'next_usage' => $nextUsage], $user);
                webdav_insufficient_storage('Quota exceeded');
            }
        }
    }
    $ok = $method === 'MOVE' ? @rename($targetPath, $destPath) : webdav_copy_tree($targetPath, $destPath);
    webdav_audit(strtolower($method), ['from' => $relative, 'to' => $destRelative, 'ok' => $ok, 'root' => (string)$account['root']], $user);
    webdav_send_status($ok ? 201 : 500);
}

webdav_send_status(405, 'Method Not Allowed', [
    'Allow' => 'OPTIONS, PROPFIND, GET, HEAD, PUT, DELETE, MKCOL, MOVE, COPY',
]);
