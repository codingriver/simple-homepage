<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/auth.php';

define('SSH_HOSTS_FILE', DATA_DIR . '/ssh_hosts.json');
define('SSH_KEYS_FILE', DATA_DIR . '/ssh_keys.json');
define('SSH_AUDIT_LOG', DATA_DIR . '/logs/ssh_audit.log');

function ssh_manager_csv_split(string $value): array {
    $items = preg_split('/[,，\n]+/', $value) ?: [];
    $result = [];
    foreach ($items as $item) {
        $trimmed = trim($item);
        if ($trimmed !== '') {
            $result[] = $trimmed;
        }
    }
    return array_values(array_unique($result));
}

function ssh_manager_default_hosts(): array {
    return ['version' => 1, 'hosts' => []];
}

function ssh_manager_default_keys(): array {
    return ['version' => 1, 'keys' => []];
}

function ssh_manager_read_json(string $path, array $default): array {
    if (!is_file($path)) {
        return $default;
    }
    $raw = json_decode((string)file_get_contents($path), true);
    return is_array($raw) ? ($raw + $default) : $default;
}

function ssh_manager_write_json(string $path, array $payload): void {
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0750, true);
    }
    file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

function ssh_manager_secret_key(): string {
    return hash('sha256', auth_secret_key(), true);
}

function ssh_manager_encrypt_secret(string $plain): string {
    if ($plain === '') {
        return '';
    }
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $cipher = sodium_crypto_secretbox($plain, $nonce, ssh_manager_secret_key());
    return base64_encode($nonce . $cipher);
}

function ssh_manager_decrypt_secret(string $cipherText): string {
    if ($cipherText === '') {
        return '';
    }
    $raw = base64_decode($cipherText, true);
    if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
        return '';
    }
    $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $cipher = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $plain = sodium_crypto_secretbox_open($cipher, $nonce, ssh_manager_secret_key());
    return $plain === false ? '' : $plain;
}

function ssh_manager_load_hosts(): array {
    return ssh_manager_read_json(SSH_HOSTS_FILE, ssh_manager_default_hosts());
}

function ssh_manager_save_hosts(array $payload): void {
    ssh_manager_write_json(SSH_HOSTS_FILE, $payload);
}

function ssh_manager_load_keys(): array {
    return ssh_manager_read_json(SSH_KEYS_FILE, ssh_manager_default_keys());
}

function ssh_manager_save_keys(array $payload): void {
    ssh_manager_write_json(SSH_KEYS_FILE, $payload);
}

function ssh_manager_list_hosts(): array {
    $data = ssh_manager_load_hosts();
    $hosts = [];
    foreach (($data['hosts'] ?? []) as $host) {
        if (!is_array($host)) {
            continue;
        }
        $hosts[] = $host;
    }
    usort($hosts, static fn(array $a, array $b) => strcmp((string)($b['updated_at'] ?? ''), (string)($a['updated_at'] ?? '')));
    return $hosts;
}

function ssh_manager_find_host(string $id): ?array {
    foreach (ssh_manager_list_hosts() as $host) {
        if ((string)($host['id'] ?? '') === $id) {
            return $host;
        }
    }
    return null;
}

function ssh_manager_upsert_host(array $input, ?string $id = null): array {
    $name = trim((string)($input['name'] ?? ''));
    $hostname = trim((string)($input['hostname'] ?? ''));
    $username = trim((string)($input['username'] ?? 'root'));
    $port = max(1, min(65535, (int)($input['port'] ?? 22)));
    $authType = in_array((string)($input['auth_type'] ?? 'key'), ['key', 'password'], true) ? (string)$input['auth_type'] : 'key';
    $keyId = trim((string)($input['key_id'] ?? ''));
    $password = (string)($input['password'] ?? '');

    if ($name === '') {
        return ['ok' => false, 'msg' => '主机名称不能为空'];
    }
    if ($hostname === '') {
        return ['ok' => false, 'msg' => '主机地址不能为空'];
    }
    if ($username === '') {
        return ['ok' => false, 'msg' => '用户名不能为空'];
    }
    if ($authType === 'key' && $keyId === '') {
        return ['ok' => false, 'msg' => '密钥认证必须选择 SSH 密钥'];
    }
    if ($authType === 'password' && $password === '') {
        return ['ok' => false, 'msg' => '密码认证必须填写密码'];
    }

    $data = ssh_manager_load_hosts();
    $hosts = [];
    $now = date('Y-m-d H:i:s');
    $targetId = $id ?: ('h_' . bin2hex(random_bytes(8)));
    $saved = null;

    foreach (($data['hosts'] ?? []) as $row) {
        if (!is_array($row)) {
            continue;
        }
        if ((string)($row['id'] ?? '') !== $targetId) {
            $hosts[] = $row;
            continue;
        }
        $saved = $row;
    }

    $record = [
        'id' => $targetId,
        'name' => $name,
        'hostname' => $hostname,
        'port' => $port,
        'username' => $username,
        'auth_type' => $authType,
        'key_id' => $authType === 'key' ? $keyId : '',
        'password_enc' => $authType === 'password'
            ? ssh_manager_encrypt_secret($password !== '' ? $password : ssh_manager_decrypt_secret((string)($saved['password_enc'] ?? '')))
            : '',
        'group_name' => trim((string)($input['group_name'] ?? ($saved['group_name'] ?? ''))),
        'tags' => ssh_manager_csv_split((string)($input['tags'] ?? implode(',', (array)($saved['tags'] ?? [])))),
        'favorite' => !empty($input['favorite']),
        'notes' => trim((string)($input['notes'] ?? '')),
        'created_at' => (string)($saved['created_at'] ?? $now),
        'updated_at' => $now,
    ];
    $hosts[] = $record;
    $data['hosts'] = array_values($hosts);
    ssh_manager_save_hosts($data);
    ssh_manager_audit('remote_host_upsert', ['host_id' => $targetId, 'host_name' => $name]);
    return ['ok' => true, 'msg' => '远程主机已保存', 'host' => $record];
}

function ssh_manager_delete_host(string $id): bool {
    $data = ssh_manager_load_hosts();
    $hosts = [];
    $deleted = false;
    foreach (($data['hosts'] ?? []) as $row) {
        if (!is_array($row)) {
            continue;
        }
        if ((string)($row['id'] ?? '') === $id) {
            $deleted = true;
            continue;
        }
        $hosts[] = $row;
    }
    $data['hosts'] = array_values($hosts);
    ssh_manager_save_hosts($data);
    if ($deleted) {
        ssh_manager_audit('remote_host_delete', ['host_id' => $id]);
    }
    return $deleted;
}

function ssh_manager_host_groups(): array {
    $groups = [];
    foreach (ssh_manager_list_hosts() as $host) {
        $name = trim((string)($host['group_name'] ?? ''));
        if ($name !== '') {
            $groups[$name] = true;
        }
    }
    $result = array_keys($groups);
    sort($result, SORT_NATURAL);
    return $result;
}

function ssh_manager_list_keys(bool $withSecrets = false): array {
    $data = ssh_manager_load_keys();
    $keys = [];
    foreach (($data['keys'] ?? []) as $row) {
        if (!is_array($row)) {
            continue;
        }
        if (!$withSecrets) {
            unset($row['private_key_enc'], $row['passphrase_enc']);
            $row['has_private_key'] = !empty($row['fingerprint']) || !empty($row['private_key_hint']);
        }
        $keys[] = $row;
    }
    usort($keys, static fn(array $a, array $b) => strcmp((string)($b['updated_at'] ?? ''), (string)($a['updated_at'] ?? '')));
    return $keys;
}

function ssh_manager_find_key(string $id, bool $withSecrets = false): ?array {
    foreach (ssh_manager_list_keys($withSecrets) as $key) {
        if ((string)($key['id'] ?? '') === $id) {
            return $key;
        }
    }
    return null;
}

function ssh_manager_public_key_from_private(string $privateKey, string $passphrase = ''): array {
    $tmpDir = DATA_DIR . '/tmp';
    if (!is_dir($tmpDir)) {
        mkdir($tmpDir, 0750, true);
    }
    $privatePath = $tmpDir . '/sshkey_' . bin2hex(random_bytes(6));
    $normalizedKey = rtrim($privateKey, "\r\n") . "\n";
    file_put_contents($privatePath, $normalizedKey, LOCK_EX);
    chmod($privatePath, 0600);
    $command = 'ssh-keygen -y -f ' . escapeshellarg($privatePath);
    if ($passphrase !== '') {
        $command = 'printf %s ' . escapeshellarg($passphrase) . ' | SSH_ASKPASS=/bin/false DISPLAY=none ' . $command;
    }
    $output = [];
    $code = 0;
    exec($command . ' 2>&1', $output, $code);
    @unlink($privatePath);
    return [
        'ok' => $code === 0,
        'public_key' => trim(implode("\n", $output)),
    ];
}

function ssh_manager_upsert_key(array $input, ?string $id = null): array {
    $name = trim((string)($input['name'] ?? ''));
    $username = trim((string)($input['username'] ?? ''));
    $privateKey = trim((string)($input['private_key'] ?? ''));
    $passphrase = (string)($input['passphrase'] ?? '');

    if ($name === '') {
        return ['ok' => false, 'msg' => '密钥名称不能为空'];
    }
    if ($privateKey === '' && !$id) {
        return ['ok' => false, 'msg' => '必须提供私钥内容'];
    }

    $data = ssh_manager_load_keys();
    $targetId = $id ?: ('k_' . bin2hex(random_bytes(8)));
    $keys = [];
    $saved = null;
    foreach (($data['keys'] ?? []) as $row) {
        if (!is_array($row)) {
            continue;
        }
        if ((string)($row['id'] ?? '') !== $targetId) {
            $keys[] = $row;
            continue;
        }
        $saved = $row;
    }

    $plainPrivate = $privateKey !== '' ? $privateKey : ssh_manager_decrypt_secret((string)($saved['private_key_enc'] ?? ''));
    if ($plainPrivate === '') {
        return ['ok' => false, 'msg' => '未检测到可保存的私钥内容'];
    }

    $publicInfo = ssh_manager_public_key_from_private($plainPrivate, $passphrase !== '' ? $passphrase : ssh_manager_decrypt_secret((string)($saved['passphrase_enc'] ?? '')));
    $publicKey = !empty($publicInfo['ok']) ? trim((string)$publicInfo['public_key']) : '';
    $fingerprint = '';
    if ($publicKey !== '') {
        $parts = preg_split('/\s+/', $publicKey) ?: [];
        if (isset($parts[1])) {
            $decoded = base64_decode((string)$parts[1], true);
            if ($decoded !== false) {
                $fingerprint = 'SHA256:' . base64_encode(hash('sha256', $decoded, true));
            }
        }
    }

    $record = [
        'id' => $targetId,
        'name' => $name,
        'username' => $username,
        'private_key_enc' => ssh_manager_encrypt_secret($plainPrivate),
        'passphrase_enc' => $passphrase !== '' ? ssh_manager_encrypt_secret($passphrase) : (string)($saved['passphrase_enc'] ?? ''),
        'public_key' => $publicKey,
        'fingerprint' => $fingerprint,
        'created_at' => (string)($saved['created_at'] ?? date('Y-m-d H:i:s')),
        'updated_at' => date('Y-m-d H:i:s'),
    ];
    $keys[] = $record;
    $data['keys'] = array_values($keys);
    ssh_manager_save_keys($data);
    ssh_manager_audit('ssh_key_upsert', ['key_id' => $targetId, 'key_name' => $name]);
    return ['ok' => true, 'msg' => 'SSH 密钥已保存', 'key' => $record];
}

function ssh_manager_delete_key(string $id): bool {
    $data = ssh_manager_load_keys();
    $keys = [];
    $deleted = false;
    foreach (($data['keys'] ?? []) as $row) {
        if (!is_array($row)) {
            continue;
        }
        if ((string)($row['id'] ?? '') === $id) {
            $deleted = true;
            continue;
        }
        $keys[] = $row;
    }
    $data['keys'] = array_values($keys);
    ssh_manager_save_keys($data);
    if ($deleted) {
        ssh_manager_audit('ssh_key_delete', ['key_id' => $id]);
    }
    return $deleted;
}

function ssh_manager_host_runtime_spec(array $host): array {
    $spec = [
        'type' => 'remote',
        'name' => (string)($host['name'] ?? ''),
        'hostname' => (string)($host['hostname'] ?? ''),
        'port' => (int)($host['port'] ?? 22),
        'username' => (string)($host['username'] ?? 'root'),
        'auth_type' => (string)($host['auth_type'] ?? 'key'),
    ];
    if ($spec['auth_type'] === 'password') {
        $spec['password'] = ssh_manager_decrypt_secret((string)($host['password_enc'] ?? ''));
    } else {
        $key = ssh_manager_find_key((string)($host['key_id'] ?? ''), true);
        if (!$key) {
            return [];
        }
        $spec['private_key'] = ssh_manager_decrypt_secret((string)($key['private_key_enc'] ?? ''));
        $spec['passphrase'] = ssh_manager_decrypt_secret((string)($key['passphrase_enc'] ?? ''));
        $spec['key_name'] = (string)($key['name'] ?? '');
        $spec['key_id'] = (string)($key['id'] ?? '');
    }
    return $spec;
}

function ssh_manager_audit(string $action, array $context = []): void {
    $dir = dirname(SSH_AUDIT_LOG);
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
    file_put_contents(SSH_AUDIT_LOG, json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND | LOCK_EX);
}

function ssh_manager_audit_tail(int $limit = 200): array {
    if (!is_file(SSH_AUDIT_LOG)) {
        return [];
    }
    $lines = file(SSH_AUDIT_LOG, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $lines = array_slice($lines, -max(1, min(500, $limit)));
    $result = [];
    foreach (array_reverse($lines) as $line) {
        $decoded = json_decode($line, true);
        if (is_array($decoded)) {
            $result[] = $decoded;
        }
    }
    return $result;
}

function ssh_manager_audit_query(array $filters = []): array {
    $limit = max(1, min(1000, (int)($filters['limit'] ?? 200)));
    $action = trim((string)($filters['action'] ?? ''));
    $hostId = trim((string)($filters['host_id'] ?? ''));
    $username = trim((string)($filters['user'] ?? ''));
    $keyword = strtolower(trim((string)($filters['keyword'] ?? '')));
    $page = max(1, (int)($filters['page'] ?? 1));
    $logs = ssh_manager_audit_tail(max($limit * 5, 500));
    $result = [];
    foreach ($logs as $log) {
        if (!is_array($log)) {
            continue;
        }
        $context = is_array($log['context'] ?? null) ? $log['context'] : [];
        if ($action !== '' && (string)($log['action'] ?? '') !== $action) {
            continue;
        }
        if ($hostId !== '' && (string)($context['host_id'] ?? '') !== $hostId) {
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

function ssh_manager_audit_export_json(array $filters = []): string {
    $query = ssh_manager_audit_query($filters + ['page' => 1]);
    return json_encode(($query['items'] ?? []), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
