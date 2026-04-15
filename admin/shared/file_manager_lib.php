<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/host_agent_lib.php';
require_once __DIR__ . '/ssh_manager_lib.php';

define('FILE_MANAGER_FAVORITES_FILE', DATA_DIR . '/file_favorites.json');
define('FILE_MANAGER_RECENT_FILE', DATA_DIR . '/file_recent.json');

function file_manager_read_json(string $path, array $default): array {
    if (!is_file($path)) {
        return $default;
    }
    $raw = json_decode((string)file_get_contents($path), true);
    return is_array($raw) ? $raw : $default;
}

function file_manager_write_json(string $path, array $payload): void {
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0750, true);
    }
    file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

function file_manager_target_payload(string $hostId): array {
    if ($hostId === '' || $hostId === 'local') {
        return ['type' => 'local'];
    }
    $host = ssh_manager_find_host($hostId);
    if (!$host) {
        return [];
    }
    return ssh_manager_host_runtime_spec($host);
}

function file_manager_target_label(string $hostId): string {
    if ($hostId === '' || $hostId === 'local') {
        return '本机';
    }
    $host = ssh_manager_find_host($hostId);
    return trim((string)($host['name'] ?? '')) ?: $hostId;
}

function file_manager_quick_paths(): array {
    return [
        ['id' => 'q_data', 'name' => '数据目录', 'host_id' => 'local', 'target_type' => 'local', 'path' => '/var/www/nav/data'],
        ['id' => 'q_tasks', 'name' => '任务目录', 'host_id' => 'local', 'target_type' => 'local', 'path' => '/var/www/nav/data/tasks'],
        ['id' => 'q_logs', 'name' => '日志目录', 'host_id' => 'local', 'target_type' => 'local', 'path' => '/var/www/nav/data/logs'],
        ['id' => 'q_backups', 'name' => '备份目录', 'host_id' => 'local', 'target_type' => 'local', 'path' => '/var/www/nav/data/backups'],
        ['id' => 'q_nginx', 'name' => 'Nginx 配置', 'host_id' => 'local', 'target_type' => 'local', 'path' => '/etc/nginx'],
        ['id' => 'q_ssh', 'name' => 'SSH 配置', 'host_id' => 'local', 'target_type' => 'local', 'path' => '/etc/ssh'],
        ['id' => 'q_root', 'name' => '根目录', 'host_id' => 'local', 'target_type' => 'local', 'path' => '/'],
    ];
}

function file_manager_load_favorites(): array {
    $data = file_manager_read_json(FILE_MANAGER_FAVORITES_FILE, ['version' => 1, 'items' => []]);
    $items = [];
    foreach (($data['items'] ?? []) as $item) {
        if (is_array($item)) {
            $items[] = $item;
        }
    }
    $data['items'] = $items;
    return $data;
}

function file_manager_save_favorites(array $data): void {
    file_manager_write_json(FILE_MANAGER_FAVORITES_FILE, $data);
}

function file_manager_favorites_list(string $username): array {
    $data = file_manager_load_favorites();
    $items = [];
    foreach (($data['items'] ?? []) as $item) {
        if (($item['user'] ?? '') !== $username) {
            continue;
        }
        $items[] = $item;
    }
    usort($items, static fn(array $a, array $b): int => strcmp((string)($b['updated_at'] ?? ''), (string)($a['updated_at'] ?? '')));
    return $items;
}

function file_manager_save_favorite(string $username, array $payload): array {
    $path = trim((string)($payload['path'] ?? ''));
    $hostId = trim((string)($payload['host_id'] ?? 'local')) ?: 'local';
    $targetType = $hostId === 'local' ? 'local' : 'remote';
    $name = trim((string)($payload['name'] ?? ''));
    if ($path === '') {
        return ['ok' => false, 'msg' => '路径不能为空'];
    }
    if ($name === '') {
        $name = basename(rtrim($path, '/')) ?: $path;
    }

    $data = file_manager_load_favorites();
    $items = [];
    $existing = null;
    foreach (($data['items'] ?? []) as $item) {
        if (!is_array($item)) {
            continue;
        }
        if (($item['user'] ?? '') === $username && ($item['host_id'] ?? 'local') === $hostId && ($item['path'] ?? '') === $path) {
            $existing = $item;
            continue;
        }
        $items[] = $item;
    }
    $record = [
        'id' => (string)($existing['id'] ?? ('fav_' . bin2hex(random_bytes(8)))),
        'user' => $username,
        'host_id' => $hostId,
        'target_type' => $targetType,
        'path' => $path,
        'name' => $name,
        'created_at' => (string)($existing['created_at'] ?? date('Y-m-d H:i:s')),
        'updated_at' => date('Y-m-d H:i:s'),
    ];
    $items[] = $record;
    $data['items'] = array_values($items);
    file_manager_save_favorites($data);
    ssh_manager_audit('file_favorite_save', ['host_id' => $hostId, 'path' => $path, 'name' => $name]);
    return ['ok' => true, 'msg' => '收藏目录已保存', 'item' => $record];
}

function file_manager_delete_favorite(string $username, string $id): bool {
    $data = file_manager_load_favorites();
    $items = [];
    $deleted = null;
    foreach (($data['items'] ?? []) as $item) {
        if (!is_array($item)) {
            continue;
        }
        if (($item['user'] ?? '') === $username && ($item['id'] ?? '') === $id) {
            $deleted = $item;
            continue;
        }
        $items[] = $item;
    }
    $data['items'] = array_values($items);
    file_manager_save_favorites($data);
    if ($deleted !== null) {
        ssh_manager_audit('file_favorite_delete', ['host_id' => (string)($deleted['host_id'] ?? 'local'), 'path' => (string)($deleted['path'] ?? '')]);
        return true;
    }
    return false;
}

function file_manager_load_recent(): array {
    $data = file_manager_read_json(FILE_MANAGER_RECENT_FILE, ['version' => 1, 'items' => []]);
    $items = [];
    foreach (($data['items'] ?? []) as $item) {
        if (is_array($item)) {
            $items[] = $item;
        }
    }
    $data['items'] = $items;
    return $data;
}

function file_manager_save_recent(array $data): void {
    file_manager_write_json(FILE_MANAGER_RECENT_FILE, $data);
}

function file_manager_recent_list(string $username, int $limit = 12): array {
    $data = file_manager_load_recent();
    $items = [];
    foreach (($data['items'] ?? []) as $item) {
        if (($item['user'] ?? '') !== $username) {
            continue;
        }
        $items[] = $item;
    }
    usort($items, static fn(array $a, array $b): int => strcmp((string)($b['updated_at'] ?? ''), (string)($a['updated_at'] ?? '')));
    return array_slice($items, 0, max(1, min(50, $limit)));
}

function file_manager_touch_recent(string $username, string $hostId, string $path): void {
    $path = trim($path);
    if ($path === '') {
        return;
    }
    $hostId = $hostId === '' ? 'local' : $hostId;
    $data = file_manager_load_recent();
    $items = [];
    foreach (($data['items'] ?? []) as $item) {
        if (!is_array($item)) {
            continue;
        }
        if (($item['user'] ?? '') === $username && ($item['host_id'] ?? 'local') === $hostId && ($item['path'] ?? '') === $path) {
            continue;
        }
        $items[] = $item;
    }
    array_unshift($items, [
        'id' => 'recent_' . bin2hex(random_bytes(8)),
        'user' => $username,
        'host_id' => $hostId,
        'target_type' => $hostId === 'local' ? 'local' : 'remote',
        'path' => $path,
        'name' => basename(rtrim($path, '/')) ?: $path,
        'updated_at' => date('Y-m-d H:i:s'),
    ]);
    $data['items'] = array_slice(array_values($items), 0, 100);
    file_manager_save_recent($data);
}
