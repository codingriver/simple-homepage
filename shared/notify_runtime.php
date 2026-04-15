<?php
/**
 * 统一通知运行时
 * 供前台/后台/CLI 共用，不依赖 admin 页面上下文
 */

if (!defined('NOTIFICATIONS_FILE')) {
    define('NOTIFICATIONS_FILE', DATA_DIR . '/notifications.json');
}
if (!defined('NOTIFY_LOG_FILE')) {
    define('NOTIFY_LOG_FILE', DATA_DIR . '/logs/notifications.log');
}

function notify_default_data(): array {
    return [
        'version' => 1,
        'channels' => [],
    ];
}

function notify_event_definitions(): array {
    return [
        'task_failed' => '计划任务失败',
        'task_succeeded' => '计划任务成功',
        'ddns_failed' => 'DDNS 执行失败',
        'ddns_succeeded' => 'DDNS 执行成功',
        'login_abnormal' => '登录异常',
        'backup_failed' => '备份失败',
        'backup_succeeded' => '备份成功',
        'ssl_expiring' => 'SSL 即将到期',
        'domain_expiring' => '域名即将到期',
    ];
}

function notify_channel_type_labels(): array {
    return [
        'telegram' => 'Telegram',
        'feishu' => '飞书',
        'dingtalk' => '钉钉',
        'wecom' => '企业微信',
        'custom' => '自定义 Webhook',
    ];
}

function notify_ensure_log_dir(): void {
    $dir = dirname(NOTIFY_LOG_FILE);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
}

function notify_log_write(string $message, array $context = []): void {
    notify_ensure_log_dir();
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message;
    if ($context !== []) {
        $line .= ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    @file_put_contents(NOTIFY_LOG_FILE, $line . "\n", FILE_APPEND | LOCK_EX);
}

function notify_load_data(): array {
    if (!file_exists(NOTIFICATIONS_FILE)) {
        return notify_default_data();
    }
    $raw = json_decode((string)@file_get_contents(NOTIFICATIONS_FILE), true);
    if (!is_array($raw) || !is_array($raw['channels'] ?? null)) {
        return notify_default_data();
    }
    return $raw + ['version' => 1];
}

function notify_save_data(array $data): void {
    if (!isset($data['channels']) || !is_array($data['channels'])) {
        $data['channels'] = [];
    }
    notify_ensure_log_dir();
    @file_put_contents(
        NOTIFICATIONS_FILE,
        json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );
}

function notify_channel_make_id(): string {
    return 'notify_' . bin2hex(random_bytes(6));
}

function notify_channel_find(array $data, string $id): ?array {
    foreach ($data['channels'] ?? [] as $channel) {
        if (($channel['id'] ?? '') === $id && is_array($channel)) {
            return $channel;
        }
    }
    return null;
}

function notify_channel_normalize(array $input, ?array $existing = null): array {
    $existing = $existing ?? [];
    $type = trim((string)($input['type'] ?? ($existing['type'] ?? 'custom')));
    if (!isset(notify_channel_type_labels()[$type])) {
        $type = 'custom';
    }
    $events = array_values(array_filter(array_map('trim', (array)($input['events'] ?? ($existing['events'] ?? [])))));
    $allowedEvents = array_keys(notify_event_definitions());
    $events = array_values(array_intersect($events, $allowedEvents));
    $config = is_array($input['config'] ?? null) ? $input['config'] : [];
    $existingConfig = is_array($existing['config'] ?? null) ? $existing['config'] : [];
    $mergedConfig = array_merge($existingConfig, $config);
    return [
        'id' => trim((string)($existing['id'] ?? $input['id'] ?? '')),
        'name' => trim((string)($input['name'] ?? '')),
        'type' => $type,
        'enabled' => !empty($input['enabled']),
        'events' => $events,
        'cooldown_seconds' => max(0, (int)($input['cooldown_seconds'] ?? ($existing['cooldown_seconds'] ?? 300))),
        'config' => [
            'webhook_url' => trim((string)($mergedConfig['webhook_url'] ?? '')),
            'bot_token' => trim((string)($mergedConfig['bot_token'] ?? '')),
            'chat_id' => trim((string)($mergedConfig['chat_id'] ?? '')),
        ],
        'runtime' => [
            'last_sent' => is_array($existing['runtime']['last_sent'] ?? null) ? $existing['runtime']['last_sent'] : [],
            'last_status' => (string)($existing['runtime']['last_status'] ?? ''),
            'last_message' => (string)($existing['runtime']['last_message'] ?? ''),
            'last_sent_at' => (string)($existing['runtime']['last_sent_at'] ?? ''),
        ],
    ];
}

function notify_channel_validate(array $channel): ?string {
    if (($channel['name'] ?? '') === '') {
        return '请填写通知渠道名称';
    }
    if (($channel['events'] ?? []) === []) {
        return '请至少选择一个通知事件';
    }
    $type = (string)($channel['type'] ?? 'custom');
    $config = is_array($channel['config'] ?? null) ? $channel['config'] : [];
    if ($type === 'telegram') {
        if (trim((string)($config['bot_token'] ?? '')) === '' || trim((string)($config['chat_id'] ?? '')) === '') {
            return 'Telegram 需要填写 Bot Token 和 Chat ID';
        }
        return null;
    }
    $url = trim((string)($config['webhook_url'] ?? ''));
    if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
        return 'Webhook URL 无效';
    }
    return null;
}

function notify_channel_upsert(array $input, ?string $id = null): array {
    $data = notify_load_data();
    $existing = $id ? notify_channel_find($data, $id) : null;
    $channel = notify_channel_normalize($input, $existing);
    $channel['id'] = $id ?: ($channel['id'] !== '' ? $channel['id'] : notify_channel_make_id());
    $err = notify_channel_validate($channel);
    if ($err !== null) {
        return ['ok' => false, 'msg' => $err];
    }
    $saved = false;
    foreach ($data['channels'] as $idx => $row) {
        if (($row['id'] ?? '') === $channel['id']) {
            $data['channels'][$idx] = $channel;
            $saved = true;
            break;
        }
    }
    if (!$saved) {
        array_unshift($data['channels'], $channel);
    }
    notify_save_data($data);
    return ['ok' => true, 'channel' => $channel];
}

function notify_channel_delete(string $id): bool {
    $data = notify_load_data();
    $before = count($data['channels']);
    $data['channels'] = array_values(array_filter($data['channels'], static fn($row) => ($row['id'] ?? '') !== $id));
    if (count($data['channels']) === $before) {
        return false;
    }
    notify_save_data($data);
    return true;
}

function notify_channel_toggle(string $id): ?bool {
    $data = notify_load_data();
    foreach ($data['channels'] as $idx => $row) {
        if (($row['id'] ?? '') !== $id) {
            continue;
        }
        $data['channels'][$idx]['enabled'] = empty($row['enabled']);
        notify_save_data($data);
        return !empty($data['channels'][$idx]['enabled']);
    }
    return null;
}

function notify_http_post_json(string $url, string $payload, int $timeout = 5): array {
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return ['ok' => false, 'status' => 0, 'body' => '', 'error' => 'Webhook URL 无效'];
    }

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Content-Length: ' . strlen($payload),
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ]);
        $body = curl_exec($ch);
        if ($body === false) {
            $error = curl_error($ch);
            curl_close($ch);
            return ['ok' => false, 'status' => 0, 'body' => '', 'error' => $error ?: '请求失败'];
        }
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return [
            'ok' => $status >= 200 && $status < 400,
            'status' => $status,
            'body' => (string)$body,
            'error' => '',
        ];
    }

    $ctx = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\nContent-Length: " . strlen($payload) . "\r\n",
            'content' => $payload,
            'timeout' => $timeout,
            'ignore_errors' => true,
            'follow_location' => 1,
            'max_redirects' => 3,
        ],
    ]);
    $body = @file_get_contents($url, false, $ctx);
    $status = 0;
    if (!empty($http_response_header) && preg_match('#HTTP/\d+\.?\d*\s+(\d+)#', $http_response_header[0], $m)) {
        $status = (int)($m[1] ?? 0);
    }
    return [
        'ok' => $body !== false && $status >= 200 && $status < 400,
        'status' => $status,
        'body' => $body === false ? '' : (string)$body,
        'error' => $body === false ? '请求失败' : '',
    ];
}

function notify_build_text(string $event, array $payload = []): string {
    $cfg = file_exists(CONFIG_FILE) ? (json_decode((string)file_get_contents(CONFIG_FILE), true) ?? []) : [];
    $siteName = (string)($cfg['site_name'] ?? '导航中心');
    $label = notify_event_definitions()[$event] ?? $event;
    $lines = ['[' . $siteName . '] ' . $label];
    foreach ($payload as $key => $value) {
        if (is_array($value)) {
            $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        if ($value === '' || $value === null) {
            continue;
        }
        $lines[] = $key . '：' . (string)$value;
    }
    $lines[] = '时间：' . date('Y-m-d H:i:s');
    return implode("\n", $lines);
}

function notify_build_request(array $channel, string $event, array $payload = []): array {
    $type = (string)($channel['type'] ?? 'custom');
    $config = is_array($channel['config'] ?? null) ? $channel['config'] : [];
    $text = notify_build_text($event, $payload);

    if ($type === 'telegram') {
        $botToken = trim((string)($config['bot_token'] ?? ''));
        $chatId = trim((string)($config['chat_id'] ?? ''));
        $url = 'https://api.telegram.org/bot' . rawurlencode($botToken) . '/sendMessage';
        return [
            'url' => $url,
            'payload' => json_encode(['chat_id' => $chatId, 'text' => $text], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
    }

    $url = trim((string)($config['webhook_url'] ?? ''));
    $payloadJson = match ($type) {
        'feishu' => json_encode(['msg_type' => 'text', 'content' => ['text' => $text]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'dingtalk' => json_encode(['msgtype' => 'text', 'text' => ['content' => $text]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'wecom' => json_encode(['msgtype' => 'text', 'text' => ['content' => $text]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        default => json_encode([
            'event' => $event,
            'label' => notify_event_definitions()[$event] ?? $event,
            'site' => (file_exists(CONFIG_FILE) ? ((json_decode((string)file_get_contents(CONFIG_FILE), true) ?? [])['site_name'] ?? '导航中心') : '导航中心'),
            'time' => date('Y-m-d H:i:s'),
            'text' => $text,
            'payload' => $payload,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    };
    return ['url' => $url, 'payload' => $payloadJson];
}

function notify_channel_is_due(array $channel, string $event): bool {
    $cooldown = max(0, (int)($channel['cooldown_seconds'] ?? 300));
    if ($cooldown <= 0) {
        return true;
    }
    $lastSent = is_array($channel['runtime']['last_sent'] ?? null) ? $channel['runtime']['last_sent'] : [];
    $lastTs = strtotime((string)($lastSent[$event] ?? ''));
    if ($lastTs === false || $lastTs <= 0) {
        return true;
    }
    return (time() - $lastTs) >= $cooldown;
}

function notify_channel_mark_sent(string $channelId, string $event, bool $ok, string $message): void {
    $data = notify_load_data();
    foreach ($data['channels'] as $idx => $row) {
        if (($row['id'] ?? '') !== $channelId) {
            continue;
        }
        if (!isset($data['channels'][$idx]['runtime']) || !is_array($data['channels'][$idx]['runtime'])) {
            $data['channels'][$idx]['runtime'] = [];
        }
        if (!isset($data['channels'][$idx]['runtime']['last_sent']) || !is_array($data['channels'][$idx]['runtime']['last_sent'])) {
            $data['channels'][$idx]['runtime']['last_sent'] = [];
        }
        $data['channels'][$idx]['runtime']['last_sent'][$event] = date('Y-m-d H:i:s');
        $data['channels'][$idx]['runtime']['last_sent_at'] = date('Y-m-d H:i:s');
        $data['channels'][$idx]['runtime']['last_status'] = $ok ? 'success' : 'fail';
        $data['channels'][$idx]['runtime']['last_message'] = $message;
        break;
    }
    notify_save_data($data);
}

function notify_send_channel(array $channel, string $event, array $payload = []): array {
    $request = notify_build_request($channel, $event, $payload);
    $url = trim((string)($request['url'] ?? ''));
    $payloadJson = (string)($request['payload'] ?? '');
    notify_log_write('notify send start', [
        'channel_id' => $channel['id'] ?? '',
        'channel_name' => $channel['name'] ?? '',
        'type' => $channel['type'] ?? '',
        'event' => $event,
        'url' => $url,
    ]);
    $result = notify_http_post_json($url, $payloadJson, 5);
    notify_channel_mark_sent((string)($channel['id'] ?? ''), $event, (bool)($result['ok'] ?? false), (string)($result['error'] ?? ($result['status'] ?? 'ok')));
    notify_log_write('notify send result', [
        'channel_id' => $channel['id'] ?? '',
        'event' => $event,
        'ok' => $result['ok'] ?? false,
        'status' => $result['status'] ?? 0,
        'error' => $result['error'] ?? '',
    ]);
    return $result;
}

function notify_event(string $event, array $payload = []): void {
    $data = notify_load_data();
    foreach ($data['channels'] ?? [] as $channel) {
        if (!is_array($channel) || empty($channel['enabled'])) {
            continue;
        }
        $events = array_values(array_map('strval', $channel['events'] ?? []));
        if (!in_array($event, $events, true)) {
            continue;
        }
        if (!notify_channel_is_due($channel, $event)) {
            notify_log_write('notify skipped by cooldown', [
                'channel_id' => $channel['id'] ?? '',
                'event' => $event,
            ]);
            continue;
        }
        notify_send_channel($channel, $event, $payload);
    }
}

function notify_test_channel(string $id): array {
    $data = notify_load_data();
    $channel = notify_channel_find($data, $id);
    if (!$channel) {
        return ['ok' => false, 'msg' => '通知渠道不存在'];
    }
    $result = notify_send_channel($channel, 'task_failed', [
        'task' => '测试任务',
        'message' => '这是一条通知渠道测试消息',
    ]);
    if (!($result['ok'] ?? false)) {
        return [
            'ok' => false,
            'msg' => ($result['error'] ?? '') !== ''
                ? '发送失败：' . (string)$result['error']
                : '发送失败，HTTP 状态码：' . (int)($result['status'] ?? 0),
        ];
    }
    return ['ok' => true, 'msg' => '测试消息已发送，请检查接收端'];
}
