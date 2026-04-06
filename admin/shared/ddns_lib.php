<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/cron_lib.php';

const DDNS_TASKS_FILE = DATA_DIR . '/ddns_tasks.json';
const DDNS_LOG_FILE = DATA_DIR . '/logs/ddns.log';
const DDNS_DEFAULT_DNS_API = 'http://127.0.0.1/api/dns.php';

function ddns_task_log_file(string $id): string {
    $id = preg_replace('/[^a-zA-Z0-9_-]/', '', $id);
    return DATA_DIR . '/logs/ddns_' . $id . '.log';
}

function ddns_default_data(): array {
    return ['version' => 1, 'tasks' => []];
}

function ddns_ensure_dirs(): void {
    if (!is_dir(DATA_DIR . '/logs')) {
        @mkdir(DATA_DIR . '/logs', 0755, true);
    }
}

function ddns_load_tasks(): array {
    if (!file_exists(DDNS_TASKS_FILE)) {
        return ddns_default_data();
    }
    $raw = json_decode((string)file_get_contents(DDNS_TASKS_FILE), true);
    if (!is_array($raw) || !isset($raw['tasks']) || !is_array($raw['tasks'])) {
        return ddns_default_data();
    }
    return $raw + ['version' => 1];
}

function ddns_save_tasks(array $data): void {
    if (!isset($data['tasks']) || !is_array($data['tasks'])) {
        $data['tasks'] = [];
    }
    file_put_contents(
        DDNS_TASKS_FILE,
        json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );
}

function ddns_log(string $level, string $message, array $context = []): void {
    ddns_ensure_dirs();
    $line = '[' . date('Y-m-d H:i:s') . '] [' . strtoupper($level) . '] ' . $message;
    if ($context !== []) {
        $line .= ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    file_put_contents(DDNS_LOG_FILE, $line . "\n", FILE_APPEND | LOCK_EX);
}

function ddns_task_log(string $id, string $level, string $message, array $context = []): void {
    ddns_ensure_dirs();
    $line = '[' . date('Y-m-d H:i:s') . '] [' . strtoupper($level) . '] ' . $message;
    if ($context !== []) {
        $line .= ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    file_put_contents(ddns_task_log_file($id), $line . "\n", FILE_APPEND | LOCK_EX);
}

function ddns_task_log_page(string $id, int $page = 1): array {
    $file = ddns_task_log_file($id);
    if (!file_exists($file)) {
        return ['lines' => [], 'total' => 0, 'page' => 1, 'pages' => 0];
    }
    $all = file($file, FILE_IGNORE_NEW_LINES);
    $total = count($all);
    $per = 100;
    $pages = max(1, (int)ceil($total / $per));
    $page = max(1, min($page, $pages));
    $slice = array_slice($all, ($page - 1) * $per, $per);
    return ['lines' => $slice, 'total' => $total, 'page' => $page, 'pages' => $pages];
}

function ddns_task_log_clear(string $id): void {
    $file = ddns_task_log_file($id);
    if (file_exists($file)) {
        @unlink($file);
    }
}

function ddns_make_id(): string {
    return 'ddns_' . bin2hex(random_bytes(6));
}

function ddns_find_task(array $data, string $id): ?array {
    foreach ($data['tasks'] ?? [] as $task) {
        if (($task['id'] ?? '') === $id) {
            return is_array($task) ? $task : null;
        }
    }
    return null;
}

function ddns_source_label(array $task): string {
    $source = $task['source'] ?? [];
    $type = (string)($source['type'] ?? '');
    return match ($type) {
        'vps789_cfip' => 'vps789 / ' . ((string)($source['line'] ?? 'CT') ?: 'CT'),
        'local_ipv6' => 'local_ipv6',
        default => 'local_ipv4',
    };
}

function ddns_status_label(array $task): string {
    $runtime = is_array($task['runtime'] ?? null) ? $task['runtime'] : [];
    if (!empty($runtime['running'])) {
        return 'running';
    }
    $status = (string)($runtime['last_status'] ?? 'fail');
    return in_array($status, ['success', 'fail', 'running'], true) ? $status : 'fail';
}

function ddns_task_row(array $task): array {
    $target = is_array($task['target'] ?? null) ? $task['target'] : [];
    $schedule = is_array($task['schedule'] ?? null) ? $task['schedule'] : [];
    $runtime = is_array($task['runtime'] ?? null) ? $task['runtime'] : [];
    return [
        'id' => (string)($task['id'] ?? ''),
        'name' => (string)($task['name'] ?? ''),
        'enabled' => !empty($task['enabled']),
        'source_label' => ddns_source_label($task),
        'domain' => (string)($target['domain'] ?? ''),
        'record_type' => strtoupper((string)($target['record_type'] ?? 'A')),
        'cron' => (string)($schedule['cron'] ?? ''),
        'last_status' => ddns_status_label($task),
        'last_run_at' => (string)($runtime['last_run_at'] ?? ''),
        'last_message' => (string)($runtime['last_message'] ?? ''),
    ];
}

function ddns_normalize_task(array $input, ?array $existing = null): array {
    $existing = $existing ?? [];
    $source = is_array($input['source'] ?? null) ? $input['source'] : [];
    $target = is_array($input['target'] ?? null) ? $input['target'] : [];
    $schedule = is_array($input['schedule'] ?? null) ? $input['schedule'] : [];
    $runtime = is_array($existing['runtime'] ?? null) ? $existing['runtime'] : [];

    $type = trim((string)($source['type'] ?? 'local_ipv4'));
    if (!in_array($type, ['local_ipv4', 'local_ipv6', 'vps789_cfip'], true)) {
        $type = 'local_ipv4';
    }
    $line = strtoupper(trim((string)($source['line'] ?? 'CT')));
    if (!in_array($line, ['CT', 'CU', 'CM'], true)) {
        $line = 'CT';
    }
    $pick = trim((string)($source['pick_strategy'] ?? 'best_score'));
    if (!in_array($pick, ['first', 'best_score'], true)) {
        $pick = 'best_score';
    }
    $recordType = strtoupper(trim((string)($target['record_type'] ?? 'A')));
    if (!in_array($recordType, ['A', 'AAAA'], true)) {
        $recordType = 'A';
    }
    $ttl = (int)($target['ttl'] ?? 120);
    if ($ttl <= 0) {
        $ttl = 120;
    }
    $cron = trim((string)($schedule['cron'] ?? '*/30 * * * *'));

    return [
        'id' => trim((string)($existing['id'] ?? $input['id'] ?? '')),
        'name' => trim((string)($input['name'] ?? '')),
        'enabled' => !empty($input['enabled']),
        'source' => [
            'type' => $type,
            'line' => $line,
            'pick_strategy' => $pick,
            'max_latency' => max(0, (int)($source['max_latency'] ?? 250)),
            'max_loss_rate' => max(0, (float)($source['max_loss_rate'] ?? 5)),
        ],
        'target' => [
            'domain' => strtolower(trim((string)($target['domain'] ?? ''))),
            'record_type' => $recordType,
            'ttl' => $ttl,
            'skip_when_unchanged' => !array_key_exists('skip_when_unchanged', $target) || !empty($target['skip_when_unchanged']),
        ],
        'schedule' => [
            'cron' => $cron,
        ],
        'runtime' => [
            'running' => !empty($runtime['running']),
            'last_run_at' => (string)($runtime['last_run_at'] ?? ''),
            'last_status' => (string)($runtime['last_status'] ?? ''),
            'last_message' => (string)($runtime['last_message'] ?? ''),
            'last_value' => (string)($runtime['last_value'] ?? ''),
            'started_at' => (string)($runtime['started_at'] ?? ''),
        ],
    ];
}

function ddns_validate_task(array $task): ?string {
    if (($task['name'] ?? '') === '') {
        return '请填写任务名称';
    }
    if (($task['target']['domain'] ?? '') === '') {
        return '请填写目标域名';
    }
    $domain = (string)($task['target']['domain'] ?? '');
    if (!preg_match('/^(?=.{1,253}$)(?!-)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i', $domain)) {
        return '目标域名格式不正确';
    }
    $cron = (string)($task['schedule']['cron'] ?? '');
    if (!cron_validate_schedule($cron)) {
        return 'Cron 表达式无效';
    }
    $type = (string)($task['source']['type'] ?? '');
    if ($type === 'vps789_cfip' && !in_array((string)($task['source']['line'] ?? ''), ['CT', 'CU', 'CM'], true)) {
        return 'vps789 线路无效';
    }
    return null;
}

function ddns_upsert_task(array $task, ?string $id = null): array {
    $data = ddns_load_tasks();
    $existing = $id ? ddns_find_task($data, $id) : null;
    $normalized = ddns_normalize_task($task, $existing);
    $normalized['id'] = ($id === null || $id === '') ? ddns_make_id() : $id;
    $error = ddns_validate_task($normalized);
    if ($error !== null) {
        return ['ok' => false, 'msg' => $error];
    }

    $saved = false;
    foreach ($data['tasks'] as $idx => $row) {
        if (($row['id'] ?? '') === $normalized['id']) {
            $data['tasks'][$idx] = $normalized;
            $saved = true;
            break;
        }
    }
    if (!$saved) {
        $data['tasks'][] = $normalized;
    }
    ddns_save_tasks($data);
    return ['ok' => true, 'task' => $normalized];
}

function ddns_delete_task(string $id): bool {
    $data = ddns_load_tasks();
    $before = count($data['tasks']);
    $data['tasks'] = array_values(array_filter($data['tasks'], fn($t) => ($t['id'] ?? '') !== $id));
    if (count($data['tasks']) === $before) {
        return false;
    }
    ddns_save_tasks($data);
    return true;
}

function ddns_toggle_task(string $id): ?bool {
    $data = ddns_load_tasks();
    foreach ($data['tasks'] as $idx => $task) {
        if (($task['id'] ?? '') === $id) {
            $data['tasks'][$idx]['enabled'] = empty($task['enabled']);
            ddns_save_tasks($data);
            return !empty($data['tasks'][$idx]['enabled']);
        }
    }
    return null;
}

function ddns_fetch_url(string $url): array {
    $context = stream_context_create([
        'http' => [
            'timeout' => 15,
            'ignore_errors' => true,
            'header' => "User-Agent: simple-homepage-ddns/1.0\r\nAccept: application/json,text/plain,*/*\r\n",
        ],
        'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
    ]);
    $body = @file_get_contents($url, false, $context);
    if ($body === false) {
        return ['ok' => false, 'msg' => '请求来源失败'];
    }
    $status = 200;
    foreach (($http_response_header ?? []) as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
            $status = (int)$m[1];
            break;
        }
    }
    if ($status >= 400) {
        return ['ok' => false, 'msg' => '来源返回 HTTP ' . $status, 'body' => $body];
    }
    return ['ok' => true, 'body' => $body];
}

function ddns_pick_best_candidate(array $rows, array $source): ?array {
    $line = (string)($source['line'] ?? 'CT');
    $pick = (string)($source['pick_strategy'] ?? 'best_score');
    $maxLatency = (int)($source['max_latency'] ?? 250);
    $maxLoss = (float)($source['max_loss_rate'] ?? 5);
    $candidates = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $value = trim((string)($row['ip'] ?? $row['value'] ?? ''));
        if ($value === '') {
            continue;
        }
        $latency = (float)($row['latency'] ?? $row['delay'] ?? $row['ping'] ?? 0);
        $loss = (float)($row['loss_rate'] ?? $row['loss'] ?? 0);
        $score = (float)($row['score'] ?? $row['avg_score'] ?? $row['latency_score'] ?? 0);
        if ($maxLatency > 0 && $latency > 0 && $latency > $maxLatency) {
            continue;
        }
        if ($maxLoss > 0 && $loss > $maxLoss) {
            continue;
        }
        $candidates[] = [
            'value' => $value,
            'latency' => $latency,
            'loss_rate' => $loss,
            'score' => $score,
            'line' => $line,
        ];
    }
    if ($candidates === []) {
        return null;
    }
    if ($pick === 'first') {
        return $candidates[0];
    }
    usort($candidates, fn($a, $b) => ($a['score'] <=> $b['score']) ?: ($a['latency'] <=> $b['latency']));
    return $candidates[0];
}

function ddns_resolve_source(array $task): array {
    $source = is_array($task['source'] ?? null) ? $task['source'] : [];
    $type = (string)($source['type'] ?? 'local_ipv4');
    if ($type === 'local_ipv4') {
        $r = ddns_fetch_url('https://api.ipify.org');
        if (!$r['ok']) return $r;
        $value = trim((string)($r['body'] ?? ''));
        if (!filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return ['ok' => false, 'msg' => '未获取到有效 IPv4'];
        }
        return ['ok' => true, 'value' => $value, 'message' => '获取公网 IPv4 成功'];
    }
    if ($type === 'local_ipv6') {
        $r = ddns_fetch_url('https://api64.ipify.org');
        if (!$r['ok']) return $r;
        $value = trim((string)($r['body'] ?? ''));
        if (!filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return ['ok' => false, 'msg' => '未获取到有效 IPv6'];
        }
        return ['ok' => true, 'value' => $value, 'message' => '获取公网 IPv6 成功'];
    }

    $line = strtoupper((string)($source['line'] ?? 'CT'));
    $apis = [
        'https://vps789.com/vps/sum/cfIpTop20?line=' . rawurlencode($line),
        'https://vps789.com/public/sum/cfIpApi?line=' . rawurlencode($line),
    ];
    $lastError = '请求来源失败';
    foreach ($apis as $api) {
        $r = ddns_fetch_url($api);
        if (!$r['ok']) {
            $lastError = (string)($r['msg'] ?? $lastError);
            continue;
        }
        $body = trim((string)($r['body'] ?? ''));
        $raw = json_decode($body, true);
        if (!is_array($raw)) {
            if (filter_var($body, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                return ['ok' => true, 'value' => $body, 'message' => '获取 vps789 候选 IP 成功'];
            }
            $lastError = 'vps789 返回格式错误';
            continue;
        }
        $rows = [];
        if (isset($raw['data'][$line]) && is_array($raw['data'][$line])) {
            $rows = $raw['data'][$line];
        } elseif (isset($raw['data']) && is_array($raw['data'])) {
            $rows = $raw['data'];
        } elseif (isset($raw[$line]) && is_array($raw[$line])) {
            $rows = $raw[$line];
        } elseif (isset($raw['ip']) && is_string($raw['ip'])) {
            return ['ok' => true, 'value' => trim((string)$raw['ip']), 'message' => '获取 vps789 候选 IP 成功', 'meta' => $raw];
        }
        $picked = ddns_pick_best_candidate($rows, $source);
        if ($picked !== null) {
            return [
                'ok' => true,
                'value' => (string)$picked['value'],
                'message' => '获取 vps789 候选 IP 成功',
                'meta' => $picked,
            ];
        }
        $lastError = '未找到符合条件的候选 IP';
    }
    return ['ok' => false, 'msg' => $lastError];
}

function ddns_call_dns_api(string $domain, string $value, string $recordType, int $ttl): array {
    require_once __DIR__ . '/dns_api_lib.php';

    $result = dns_api_upsert($domain, $value, $recordType, $ttl > 0 ? $ttl : null);
    if ((int)($result['code'] ?? -1) !== 0) {
        return [
            'ok' => false,
            'msg' => (string)($result['msg'] ?? 'DNS API 调用失败'),
            'raw' => $result,
        ];
    }
    return [
        'ok' => true,
        'msg' => (string)($result['msg'] ?? 'ok'),
        'data' => $result['data'] ?? [],
    ];
}

function ddns_query_dns_value(string $domain, string $recordType): ?string {
    $url = DDNS_DEFAULT_DNS_API . '?action=query&domain=' . rawurlencode($domain) . '&type=' . rawurlencode($recordType);
    $body = @file_get_contents($url);
    if ($body === false) {
        return null;
    }
    $json = json_decode($body, true);
    if (!is_array($json) || (int)($json['code'] ?? -1) !== 0) {
        return null;
    }
    $records = $json['data']['records'] ?? [];
    if (!is_array($records)) {
        return null;
    }
    foreach ($records as $record) {
        if (is_array($record) && strtoupper((string)($record['type'] ?? '')) === strtoupper($recordType)) {
            return trim((string)($record['value'] ?? ''));
        }
    }
    return null;
}

function ddns_mark_running(string $id, bool $running): void {
    $data = ddns_load_tasks();
    foreach ($data['tasks'] as $idx => $task) {
        if (($task['id'] ?? '') === $id) {
            $data['tasks'][$idx]['runtime']['running'] = $running;
            $data['tasks'][$idx]['runtime']['started_at'] = $running ? date('Y-m-d H:i:s') : '';
            ddns_save_tasks($data);
            return;
        }
    }
}

function ddns_store_result(string $id, string $status, string $message, string $value = ''): void {
    $data = ddns_load_tasks();
    foreach ($data['tasks'] as $idx => $task) {
        if (($task['id'] ?? '') === $id) {
            $data['tasks'][$idx]['runtime']['running'] = false;
            $data['tasks'][$idx]['runtime']['last_status'] = $status;
            $data['tasks'][$idx]['runtime']['last_message'] = $message;
            $data['tasks'][$idx]['runtime']['last_run_at'] = date('Y-m-d H:i:s');
            $data['tasks'][$idx]['runtime']['last_value'] = $value;
            $data['tasks'][$idx]['runtime']['started_at'] = '';
            ddns_save_tasks($data);
            return;
        }
    }
}

function ddns_run_task(array $task): array {
    $id = (string)($task['id'] ?? '');
    $name = (string)($task['name'] ?? $id);
    $target = is_array($task['target'] ?? null) ? $task['target'] : [];
    $domain = (string)($target['domain'] ?? '');
    $recordType = strtoupper((string)($target['record_type'] ?? 'A'));
    $ttl = (int)($target['ttl'] ?? 120);
    $skipUnchanged = !array_key_exists('skip_when_unchanged', $target) || !empty($target['skip_when_unchanged']);

    ddns_mark_running($id, true);
    ddns_log('info', 'DDNS task start', ['id' => $id, 'name' => $name, 'domain' => $domain]);
    ddns_task_log($id, 'info', '任务开始执行', ['name' => $name, 'domain' => $domain, 'record_type' => $recordType, 'ttl' => $ttl]);

    $resolved = ddns_resolve_source($task);
    if (!$resolved['ok']) {
        $msg = (string)($resolved['msg'] ?? '来源解析失败');
        ddns_store_result($id, 'fail', $msg);
        ddns_log('error', 'DDNS source failed', ['id' => $id, 'name' => $name, 'msg' => $msg]);
        ddns_task_log($id, 'error', '来源解析失败', ['message' => $msg]);
        return ['ok' => false, 'status' => 'fail', 'msg' => $msg, 'task_name' => $name];
    }

    $value = trim((string)($resolved['value'] ?? ''));
    $sourceLabel = ddns_source_label($task);
    ddns_task_log($id, 'info', '来源解析成功', ['source' => $sourceLabel, 'value' => $value]);
    if ($skipUnchanged) {
        $current = ddns_query_dns_value($domain, $recordType);
        if ($current !== null && $current === $value) {
            $msg = '值未变化，已跳过';
            ddns_store_result($id, 'success', $msg, $value);
            ddns_log('info', 'DDNS skip unchanged', ['id' => $id, 'name' => $name, 'domain' => $domain, 'value' => $value]);
            ddns_task_log($id, 'info', '值未变化，跳过更新', ['domain' => $domain, 'record_type' => $recordType, 'value' => $value]);
            return [
                'ok' => true,
                'status' => 'success',
                'msg' => $msg,
                'value' => $value,
                'task_name' => $name,
                'domain' => $domain,
                'record_type' => $recordType,
                'source_label' => $sourceLabel,
                'final_state' => 'skipped_unchanged',
            ];
        }
    }

    $updated = ddns_call_dns_api($domain, $value, $recordType, $ttl);
    if (!$updated['ok']) {
        $msg = (string)($updated['msg'] ?? 'DNS 更新失败');
        ddns_store_result($id, 'fail', $msg, $value);
        ddns_log('error', 'DDNS update failed', ['id' => $id, 'name' => $name, 'domain' => $domain, 'msg' => $msg, 'value' => $value]);
        ddns_task_log($id, 'error', 'DNS 更新失败', ['domain' => $domain, 'record_type' => $recordType, 'value' => $value, 'message' => $msg, 'detail' => $updated['error'] ?? $updated['raw'] ?? null, 'http_status' => $updated['http_status'] ?? null]);
        return [
            'ok' => false,
            'status' => 'fail',
            'msg' => $msg,
            'value' => $value,
            'task_name' => $name,
            'domain' => $domain,
            'record_type' => $recordType,
            'source_label' => $sourceLabel,
            'final_state' => 'failed_update',
        ];
    }

    $msg = (string)$updated['msg'];
    ddns_store_result($id, 'success', $msg, $value);
    ddns_log('info', 'DDNS update success', ['id' => $id, 'name' => $name, 'domain' => $domain, 'value' => $value]);
        ddns_task_log($id, 'info', 'DNS 更新成功', ['domain' => $domain, 'record_type' => $recordType, 'value' => $value, 'message' => $msg, 'action' => $updated['data']['action'] ?? null]);
    return [
        'ok' => true,
        'status' => 'success',
        'msg' => $msg,
        'value' => $value,
        'task_name' => $name,
        'domain' => $domain,
        'record_type' => $recordType,
        'source_label' => $sourceLabel,
        'final_state' => 'updated',
    ];
}

function ddns_run_task_by_id(string $id): array {
    $data = ddns_load_tasks();
    $task = ddns_find_task($data, $id);
    if (!$task) {
        return ['ok' => false, 'status' => 'fail', 'msg' => '任务不存在'];
    }
    return ddns_run_task($task);
}

function ddns_cron_field_matches(string $expr, int $value, int $min, int $max): bool {
    foreach (explode(',', $expr) as $part) {
        $part = trim($part);
        if ($part === '*') {
            return true;
        }
        $step = 1;
        if (str_contains($part, '/')) {
            [$part, $stepRaw] = explode('/', $part, 2);
            $step = max(1, (int)$stepRaw);
        }
        $ranges = $part === '*' ? [[$min, $max]] : [];
        if ($part !== '*' && str_contains($part, '-')) {
            [$a, $b] = explode('-', $part, 2);
            $ranges[] = [(int)$a, (int)$b];
        } elseif ($part !== '*') {
            $ranges[] = [(int)$part, (int)$part];
        }
        foreach ($ranges as [$a, $b]) {
            $a = max($min, $a);
            $b = min($max, $b);
            if ($value < $a || $value > $b) {
                continue;
            }
            if ((($value - $a) % $step) === 0) {
                return true;
            }
        }
    }
    return false;
}

function ddns_cron_matches(string $expr, ?int $ts = null): bool {
    $parts = preg_split('/\s+/', trim($expr));
    if (count($parts) < 5) {
        return false;
    }
    $ts = $ts ?? time();
    [$min, $hour, $dom, $mon, $dow] = array_slice($parts, 0, 5);
    return ddns_cron_field_matches($min, (int)date('i', $ts), 0, 59)
        && ddns_cron_field_matches($hour, (int)date('G', $ts), 0, 23)
        && ddns_cron_field_matches($dom, (int)date('j', $ts), 1, 31)
        && ddns_cron_field_matches($mon, (int)date('n', $ts), 1, 12)
        && ddns_cron_field_matches($dow, (int)date('w', $ts), 0, 6);
}

function ddns_due_tasks(?int $ts = null): array {
    $data = ddns_load_tasks();
    $ts = $ts ?? time();
    $out = [];
    foreach ($data['tasks'] as $task) {
        if (empty($task['enabled'])) {
            continue;
        }
        $cron = (string)($task['schedule']['cron'] ?? '');
        if ($cron !== '' && ddns_cron_matches($cron, $ts)) {
            $out[] = $task;
        }
    }
    return $out;
}
