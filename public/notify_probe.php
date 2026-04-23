<?php
declare(strict_types=1);

require_once __DIR__ . '/../shared/auth.php';
require_once __DIR__ . '/../shared/request_timing.php';

$logFile = DATA_DIR . '/logs/notify_probe.log';
$dir = dirname($logFile);
if (!is_dir($dir)) {
    @mkdir($dir, 0755, true);
}

$raw = (string)file_get_contents('php://input');
$bodyParsed = json_decode($raw, true);
$line = json_encode([
    'time' => date('Y-m-d H:i:s'),
    'method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
    'query' => $_GET,
    'body' => $bodyParsed !== null ? $bodyParsed : $raw,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
@file_put_contents($logFile, $line . "\n", FILE_APPEND | LOCK_EX);

header('Content-Type: application/json; charset=utf-8');
http_response_code(200);
echo json_encode([
    'ok' => true,
    'message' => 'notify probe received',
    'time' => date('Y-m-d H:i:s'),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
