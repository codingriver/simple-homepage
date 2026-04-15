<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/auth.php';

define('SHARE_SERVICE_AUDIT_LOG', DATA_DIR . '/logs/share_service_audit.log');
define('SHARE_SERVICE_HISTORY_DIR', DATA_DIR . '/share_service_history');

function share_service_supported_map(): array {
    return [
        'sftp' => ['label' => 'SFTP', 'files' => ['etc/ssh/sshd_config']],
        'smb' => ['label' => 'SMB', 'files' => ['etc/samba/smb.conf', 'var/lib/host-agent/share_services.json']],
        'ftp' => ['label' => 'FTP', 'files' => ['etc/vsftpd.conf', 'etc/vsftpd.userlist', 'var/lib/host-agent/share_services.json']],
        'nfs' => ['label' => 'NFS', 'files' => ['etc/exports', 'etc/nfs.conf', 'var/lib/host-agent/share_services.json']],
        'afp' => ['label' => 'AFP', 'files' => ['etc/netatalk/afp.conf', 'var/lib/host-agent/share_services.json']],
        'async' => ['label' => 'Async / Rsync', 'files' => ['etc/rsyncd.conf', 'var/lib/host-agent/share_services.json']],
    ];
}

function share_service_label(string $service): string {
    $map = share_service_supported_map();
    return (string)($map[$service]['label'] ?? strtoupper($service));
}

function share_service_history_dir(): string {
    if (!is_dir(SHARE_SERVICE_HISTORY_DIR)) {
        mkdir(SHARE_SERVICE_HISTORY_DIR, 0750, true);
    }
    return SHARE_SERVICE_HISTORY_DIR;
}

function share_service_audit(string $action, array $context = []): void {
    $dir = dirname(SHARE_SERVICE_AUDIT_LOG);
    if (!is_dir($dir)) {
        mkdir($dir, 0750, true);
    }
    $user = auth_get_current_user();
    $entry = [
        'time' => date('Y-m-d H:i:s'),
        'user' => (string)($user['username'] ?? 'system'),
        'role' => (string)($user['role'] ?? 'system'),
        'action' => $action,
        'context' => $context,
    ];
    file_put_contents(SHARE_SERVICE_AUDIT_LOG, json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND | LOCK_EX);
}

function share_service_audit_tail(int $limit = 200): array {
    if (!is_file(SHARE_SERVICE_AUDIT_LOG)) {
        return [];
    }
    $lines = file(SHARE_SERVICE_AUDIT_LOG, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $lines = array_slice($lines, -max(1, min(1000, $limit)));
    $result = [];
    foreach (array_reverse($lines) as $line) {
        $decoded = json_decode($line, true);
        if (is_array($decoded)) {
            $result[] = $decoded;
        }
    }
    return $result;
}

function share_service_audit_query(array $filters = []): array {
    $limit = max(1, min(1000, (int)($filters['limit'] ?? 200)));
    $action = trim((string)($filters['action'] ?? ''));
    $service = trim((string)($filters['service'] ?? ''));
    $username = trim((string)($filters['user'] ?? ''));
    $keyword = strtolower(trim((string)($filters['keyword'] ?? '')));
    $page = max(1, (int)($filters['page'] ?? 1));
    $logs = share_service_audit_tail(max($limit * 5, 500));
    $result = [];
    foreach ($logs as $log) {
        if (!is_array($log)) {
            continue;
        }
        $context = is_array($log['context'] ?? null) ? $log['context'] : [];
        if ($action !== '' && (string)($log['action'] ?? '') !== $action) {
            continue;
        }
        if ($service !== '' && (string)($context['service'] ?? '') !== $service) {
            continue;
        }
        if ($username !== '' && (string)($log['user'] ?? '') !== $username) {
            continue;
        }
        if ($keyword !== '') {
            $haystack = strtolower((string)($log['action'] ?? '') . ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            if (strpos($haystack, $keyword) === false) {
                continue;
            }
        }
        $result[] = $log;
    }
    $offset = ($page - 1) * $limit;
    return [
        'items' => array_slice($result, $offset, $limit),
        'total' => count($result),
        'page' => $page,
        'per_page' => $limit,
        'has_next' => ($offset + $limit) < count($result),
    ];
}

function share_service_audit_export_json(array $filters = []): string {
    $query = share_service_audit_query($filters + ['page' => 1]);
    return json_encode(($query['items'] ?? []), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function share_service_history_write(string $service, string $action, array $snapshot, array $meta = []): array {
    $id = 'sshare_' . bin2hex(random_bytes(8));
    $payload = [
        'id' => $id,
        'service' => $service,
        'label' => share_service_label($service),
        'action' => $action,
        'created_at' => date('Y-m-d H:i:s'),
        'created_by' => (string)((auth_get_current_user()['username'] ?? 'system')),
        'meta' => $meta,
        'snapshot' => $snapshot,
    ];
    $path = share_service_history_dir() . '/' . $id . '.json';
    file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    return $payload;
}

function share_service_history_list(array $filters = []): array {
    $service = trim((string)($filters['service'] ?? ''));
    $keyword = strtolower(trim((string)($filters['keyword'] ?? '')));
    $limit = max(1, min(500, (int)($filters['limit'] ?? 200)));
    $items = [];
    foreach (glob(share_service_history_dir() . '/*.json') ?: [] as $path) {
        $decoded = json_decode((string)file_get_contents($path), true);
        if (!is_array($decoded)) {
            continue;
        }
        if ($service !== '' && (string)($decoded['service'] ?? '') !== $service) {
            continue;
        }
        if ($keyword !== '') {
            $haystack = strtolower(json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            if (strpos($haystack, $keyword) === false) {
                continue;
            }
        }
        unset($decoded['snapshot']);
        $items[] = $decoded;
    }
    usort($items, static fn(array $a, array $b): int => strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? '')));
    return array_slice($items, 0, $limit);
}

function share_service_history_find(string $id): ?array {
    $path = share_service_history_dir() . '/' . basename($id) . '.json';
    if (!is_file($path)) {
        return null;
    }
    $decoded = json_decode((string)file_get_contents($path), true);
    return is_array($decoded) ? $decoded : null;
}
