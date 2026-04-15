#!/usr/bin/env php
<?php
declare(strict_types=1);

$method = strtoupper((string)($argv[1] ?? 'GET'));
$path = (string)($argv[2] ?? '/_ping');
$payload_base64 = (string)($argv[3] ?? '');
$socket = (string)($argv[4] ?? '/var/run/docker.sock');

if ($method === 'PING') {
    echo "pong\n";
    exit(0);
}

$payload = null;
if ($payload_base64 !== '') {
    $decoded = base64_decode($payload_base64, true);
    if ($decoded === false) {
        fwrite(STDERR, "invalid base64 payload\n");
        exit(2);
    }
    $payload = $decoded;
}

$ch = curl_init('http://localhost' . $path);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_UNIX_SOCKET_PATH => $socket,
    CURLOPT_CUSTOMREQUEST => $method,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_CONNECTTIMEOUT => 3,
    CURLOPT_TIMEOUT => 20,
]);
if ($payload_base64 !== '') {
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
}
$body = curl_exec($ch);
$errno = curl_errno($ch);
$error = $errno ? curl_error($ch) : '';
$status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
curl_close($ch);

echo json_encode([
    'ok' => $errno === 0 && $status >= 200 && $status < 300,
    'status' => $status,
    'error' => $error,
    'body' => is_string($body) ? $body : '',
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
