<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';

define('WEBDAV_AUDIT_LOG', DATA_DIR . '/logs/webdav.log');
define('WEBDAV_ACCOUNTS_FILE', DATA_DIR . '/webdav_accounts.json');

function webdav_account_username_valid(string $username): bool {
    return (bool)preg_match('/^[A-Za-z0-9_.-]{3,32}$/', $username);
}

function webdav_default_root(): string {
    return '/var/www/nav/data';
}

function webdav_normalize_path(string $path): string {
    $value = trim($path);
    if ($value === '') {
        return '/';
    }
    if ($value[0] !== '/') {
        $value = '/' . $value;
    }
    $value = preg_replace('#/+#', '/', $value) ?: '/';
    if (strlen($value) > 1) {
        $value = rtrim($value, '/');
    }
    return $value;
}

function webdav_display_local_root(string $root): string {
    return webdav_normalize_path($root);
}

function webdav_read_accounts_file(): array {
    if (!is_file(WEBDAV_ACCOUNTS_FILE)) {
        return ['version' => 1, 'accounts' => []];
    }
    $raw = json_decode((string)file_get_contents(WEBDAV_ACCOUNTS_FILE), true);
    if (!is_array($raw)) {
        return ['version' => 1, 'accounts' => []];
    }
    $accounts = [];
    foreach (($raw['accounts'] ?? []) as $account) {
        if (is_array($account)) {
            $accounts[] = $account;
        }
    }
    return [
        'version' => 1,
        'accounts' => $accounts,
    ];
}

function webdav_write_accounts_file(array $data): void {
    $dir = dirname(WEBDAV_ACCOUNTS_FILE);
    if (!is_dir($dir)) {
        mkdir($dir, 0750, true);
    }
    file_put_contents(WEBDAV_ACCOUNTS_FILE, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

function webdav_normalize_account(array $row): array {
    return [
        'id' => trim((string)($row['id'] ?? '')),
        'username' => trim((string)($row['username'] ?? '')),
        'password_hash' => (string)($row['password_hash'] ?? ''),
        'root' => trim((string)($row['root'] ?? webdav_default_root())) ?: webdav_default_root(),
        'readonly' => !empty($row['readonly']),
        'enabled' => !array_key_exists('enabled', $row) || !empty($row['enabled']),
        'max_upload_mb' => max(0, (int)($row['max_upload_mb'] ?? 0)),
        'quota_mb' => max(0, (int)($row['quota_mb'] ?? 0)),
        'ip_whitelist' => trim((string)($row['ip_whitelist'] ?? '')),
        'created_at' => (string)($row['created_at'] ?? date('Y-m-d H:i:s')),
        'updated_at' => (string)($row['updated_at'] ?? date('Y-m-d H:i:s')),
        'notes' => trim((string)($row['notes'] ?? '')),
    ];
}

function webdav_accounts_load(): array {
    $cfg = load_config();
    $data = webdav_read_accounts_file();
    $accounts = [];
    foreach (($data['accounts'] ?? []) as $row) {
        $account = webdav_normalize_account((array)$row);
        if ($account['id'] !== '' && $account['username'] !== '') {
            $accounts[] = $account;
        }
    }

    $legacyUsername = trim((string)($cfg['webdav_username'] ?? ''));
    $legacyPasswordHash = trim((string)($cfg['webdav_password_hash'] ?? ''));
    if ($legacyUsername !== '' && $legacyPasswordHash !== '') {
        $exists = false;
        foreach ($accounts as $account) {
            if ($account['username'] === $legacyUsername) {
                $exists = true;
                break;
            }
        }
        if (!$exists) {
            $accounts[] = webdav_normalize_account([
                'id' => 'legacy_' . bin2hex(random_bytes(6)),
                'username' => $legacyUsername,
                'password_hash' => $legacyPasswordHash,
                'root' => trim((string)($cfg['webdav_root'] ?? webdav_default_root())) ?: webdav_default_root(),
                'readonly' => ($cfg['webdav_readonly'] ?? '0') === '1',
                'enabled' => true,
                'notes' => 'legacy migrated account',
            ]);
            webdav_write_accounts_file(['version' => 1, 'accounts' => $accounts]);
        }
    }

    usort($accounts, static function (array $a, array $b): int {
        return strcmp((string)$a['username'], (string)$b['username']);
    });
    return $accounts;
}

function webdav_enabled(): bool {
    return (load_config()['webdav_enabled'] ?? '0') === '1';
}

function webdav_config(): array {
    $cfg = load_config();
    $accounts = webdav_accounts_load();
    return [
        'enabled' => ($cfg['webdav_enabled'] ?? '0') === '1',
        'accounts' => $accounts,
        'account_count' => count($accounts),
    ];
}

function webdav_account_usage_bytes(array $account): int {
    return webdav_directory_size((string)($account['root'] ?? webdav_default_root()));
}

function webdav_stats_summary(): array {
    $accounts = webdav_accounts_load();
    $summary = [
        'account_count' => count($accounts),
        'enabled_count' => 0,
        'readonly_count' => 0,
        'total_usage_bytes' => 0,
        'accounts' => [],
    ];
    foreach ($accounts as $account) {
        $usage = webdav_account_usage_bytes($account);
        if (!empty($account['enabled'])) {
            $summary['enabled_count'] += 1;
        }
        if (!empty($account['readonly'])) {
            $summary['readonly_count'] += 1;
        }
        $summary['total_usage_bytes'] += $usage;
        $summary['accounts'][] = $account + ['usage_bytes' => $usage];
    }
    return $summary;
}

function webdav_set_enabled(bool $enabled): array {
    $cfg = load_config();
    $cfg['webdav_enabled'] = $enabled ? '1' : '0';
    save_config($cfg);
    auth_reload_config();
    webdav_audit('service_toggle', ['enabled' => $enabled], 'system');
    return ['ok' => true, 'msg' => $enabled ? 'WebDAV 已启用' : 'WebDAV 已停用'];
}

function webdav_validate_root(string $root): array {
    if ($root === '' || $root[0] !== '/') {
        return ['ok' => false, 'msg' => 'WebDAV 根目录必须是绝对路径'];
    }
    if (!is_dir($root)) {
        if (!@mkdir($root, 0755, true) && !is_dir($root)) {
            return ['ok' => false, 'msg' => 'WebDAV 根目录创建失败，请检查权限'];
        }
    }
    return ['ok' => true];
}

function webdav_account_upsert(array $input, ?string $id = null): array {
    $username = trim((string)($input['username'] ?? ''));
    $password = (string)($input['password'] ?? '');
    $root = trim((string)($input['root'] ?? webdav_default_root())) ?: webdav_default_root();
    $readonly = !empty($input['readonly']);
    $enabled = !array_key_exists('enabled', $input) || !empty($input['enabled']);
    $notes = trim((string)($input['notes'] ?? ''));
    $maxUploadMb = max(0, (int)($input['max_upload_mb'] ?? 0));
    $quotaMb = max(0, (int)($input['quota_mb'] ?? 0));
    $ipWhitelist = trim((string)($input['ip_whitelist'] ?? ''));

    if (!webdav_account_username_valid($username)) {
        return ['ok' => false, 'msg' => 'WebDAV 用户名仅支持 3-32 位字母、数字、点、下划线和中划线'];
    }
    $rootCheck = webdav_validate_root($root);
    if (empty($rootCheck['ok'])) {
        return $rootCheck;
    }

    $accounts = webdav_accounts_load();
    $found = null;
    foreach ($accounts as $index => $account) {
        if ($id !== null && $account['id'] === $id) {
            $found = $index;
            continue;
        }
        if ($account['username'] === $username) {
            return ['ok' => false, 'msg' => 'WebDAV 用户名已存在'];
        }
    }

    if ($found === null && $password === '') {
        return ['ok' => false, 'msg' => '新增 WebDAV 账号时必须设置密码'];
    }

    $current = $found !== null ? $accounts[$found] : null;
    $record = [
        'id' => $id !== null ? $id : ('wd_' . bin2hex(random_bytes(8))),
        'username' => $username,
        'password_hash' => $password !== '' ? password_hash($password, PASSWORD_DEFAULT) : (string)($current['password_hash'] ?? ''),
        'root' => $root,
        'readonly' => $readonly,
        'enabled' => $enabled,
        'max_upload_mb' => $maxUploadMb,
        'quota_mb' => $quotaMb,
        'ip_whitelist' => $ipWhitelist,
        'created_at' => (string)($current['created_at'] ?? date('Y-m-d H:i:s')),
        'updated_at' => date('Y-m-d H:i:s'),
        'notes' => $notes,
    ];

    if ($found !== null) {
        $accounts[$found] = $record;
    } else {
        $accounts[] = $record;
    }

    webdav_write_accounts_file(['version' => 1, 'accounts' => array_values($accounts)]);
    webdav_audit('account_upsert', [
        'account_id' => $record['id'],
        'username' => $username,
        'root' => $root,
        'readonly' => $readonly,
        'enabled' => $enabled,
        'max_upload_mb' => $maxUploadMb,
        'quota_mb' => $quotaMb,
        'ip_whitelist' => $ipWhitelist,
    ], 'system');
    return ['ok' => true, 'msg' => 'WebDAV 账号已保存', 'account' => $record];
}

function webdav_account_delete(string $id): bool {
    $accounts = webdav_accounts_load();
    $next = [];
    $deleted = null;
    foreach ($accounts as $account) {
        if ($account['id'] === $id) {
            $deleted = $account;
            continue;
        }
        $next[] = $account;
    }
    if ($deleted === null) {
        return false;
    }
    webdav_write_accounts_file(['version' => 1, 'accounts' => array_values($next)]);
    webdav_audit('account_delete', ['account_id' => $id, 'username' => (string)$deleted['username']], 'system');
    return true;
}

function webdav_account_toggle(string $id): ?bool {
    $accounts = webdav_accounts_load();
    $changed = null;
    foreach ($accounts as &$account) {
        if ($account['id'] !== $id) {
            continue;
        }
        $account['enabled'] = empty($account['enabled']);
        $account['updated_at'] = date('Y-m-d H:i:s');
        $changed = (bool)$account['enabled'];
        webdav_audit('account_toggle', ['account_id' => $id, 'username' => (string)$account['username'], 'enabled' => $changed], 'system');
        break;
    }
    unset($account);
    if ($changed === null) {
        return null;
    }
    webdav_write_accounts_file(['version' => 1, 'accounts' => array_values($accounts)]);
    return $changed;
}

function webdav_account_find_by_id(string $id): ?array {
    foreach (webdav_accounts_load() as $account) {
        if ($account['id'] === $id) {
            return $account;
        }
    }
    return null;
}

function webdav_local_path_relation(string $currentPath, string $accountRoot): string {
    $current = webdav_normalize_path($currentPath);
    $root = webdav_normalize_path($accountRoot);
    if ($current === $root) {
        return 'exact';
    }
    if ($current !== '/' && str_starts_with($current . '/', $root . '/')) {
        return 'inside';
    }
    if ($root !== '/' && str_starts_with($root . '/', $current . '/')) {
        return 'child';
    }
    if ($current === '/' && $root !== '/') {
        return 'child';
    }
    if ($root === '/' && $current !== '/') {
        return 'inside';
    }
    return '';
}

function webdav_accounts_for_local_path(string $path): array {
    $target = webdav_normalize_path($path);
    $matches = [];
    foreach (webdav_accounts_load() as $account) {
        $displayRoot = webdav_display_local_root((string)($account['root'] ?? '/'));
        $relation = webdav_local_path_relation($target, $displayRoot);
        if ($relation === '') {
            continue;
        }
        $matches[] = $account + [
            'display_root' => $displayRoot,
            'relation' => $relation,
        ];
    }
    usort($matches, static function(array $a, array $b): int {
        $order = ['exact' => 0, 'inside' => 1, 'child' => 2];
        $left = $order[(string)($a['relation'] ?? '')] ?? 9;
        $right = $order[(string)($b['relation'] ?? '')] ?? 9;
        if ($left !== $right) {
            return $left <=> $right;
        }
        return strcmp((string)($a['username'] ?? ''), (string)($b['username'] ?? ''));
    });
    return $matches;
}

function webdav_account_clone(string $id): array {
    $account = webdav_account_find_by_id($id);
    if (!$account) {
        return ['ok' => false, 'msg' => 'WebDAV 账号不存在'];
    }
    $sourceUsername = (string)($account['username'] ?? '');
    $newUsername = $sourceUsername . '_copy';
    $accounts = webdav_accounts_load();
    $existing = array_map(static fn(array $item): string => (string)($item['username'] ?? ''), $accounts);
    $suffix = 2;
    while (in_array($newUsername, $existing, true)) {
        $newUsername = $sourceUsername . '_copy' . $suffix;
        $suffix += 1;
    }
    $result = webdav_account_upsert([
        'username' => $newUsername,
        'password' => bin2hex(random_bytes(8)),
        'root' => (string)($account['root'] ?? webdav_default_root()),
        'readonly' => !empty($account['readonly']),
        'enabled' => !empty($account['enabled']),
        'max_upload_mb' => (int)($account['max_upload_mb'] ?? 0),
        'quota_mb' => (int)($account['quota_mb'] ?? 0),
        'ip_whitelist' => (string)($account['ip_whitelist'] ?? ''),
        'notes' => trim((string)($account['notes'] ?? '') . ' [cloned]'),
    ], null);
    if (!empty($result['ok'])) {
        webdav_audit('account_clone', [
            'source_account_id' => $id,
            'source_username' => $sourceUsername,
            'target_account_id' => (string)($result['account']['id'] ?? ''),
            'target_username' => $newUsername,
        ], 'system');
    }
    return $result + ['cloned_username' => $newUsername];
}

function webdav_account_reset_password(string $id, string $password): array {
    $account = webdav_account_find_by_id($id);
    if (!$account) {
        return ['ok' => false, 'msg' => 'WebDAV 账号不存在'];
    }
    if (trim($password) === '') {
        return ['ok' => false, 'msg' => '新密码不能为空'];
    }
    $result = webdav_account_upsert([
        'username' => (string)($account['username'] ?? ''),
        'password' => $password,
        'root' => (string)($account['root'] ?? webdav_default_root()),
        'readonly' => !empty($account['readonly']),
        'enabled' => !empty($account['enabled']),
        'max_upload_mb' => (int)($account['max_upload_mb'] ?? 0),
        'quota_mb' => (int)($account['quota_mb'] ?? 0),
        'ip_whitelist' => (string)($account['ip_whitelist'] ?? ''),
        'notes' => (string)($account['notes'] ?? ''),
    ], $id);
    if (!empty($result['ok'])) {
        webdav_audit('account_reset_password', [
            'account_id' => $id,
            'username' => (string)($account['username'] ?? ''),
        ], 'system');
    }
    return $result;
}

function webdav_authenticate(string $username, string $password): ?array {
    if ($username === '') {
        return null;
    }
    foreach (webdav_accounts_load() as $account) {
        if ($account['username'] !== $username || empty($account['enabled'])) {
            continue;
        }
        if (password_verify($password, (string)$account['password_hash'])) {
            return $account;
        }
    }
    return null;
}

function webdav_audit(string $action, array $context = [], string $user = ''): void {
    $dir = dirname(WEBDAV_AUDIT_LOG);
    if (!is_dir($dir)) {
        mkdir($dir, 0750, true);
    }
    $entry = [
        'time' => date('Y-m-d H:i:s'),
        'user' => $user !== '' ? $user : (string)(auth_get_current_user()['username'] ?? 'webdav'),
        'action' => $action,
        'context' => $context,
    ];
    file_put_contents(WEBDAV_AUDIT_LOG, json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND | LOCK_EX);
}

function webdav_audit_tail(int $limit = 100, array $filters = []): array {
    if (!is_file(WEBDAV_AUDIT_LOG)) {
        return [];
    }
    $lines = file(WEBDAV_AUDIT_LOG, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $page = max(1, (int)($filters['page'] ?? 1));
    $window = max(1, min(2000, $limit * max(3, $page + 1)));
    $lines = array_slice($lines, -$window);
    $result = [];
    $action = trim((string)($filters['action'] ?? ''));
    $user = trim((string)($filters['user'] ?? ''));
    $keyword = strtolower(trim((string)($filters['keyword'] ?? '')));
    foreach (array_reverse($lines) as $line) {
        $decoded = json_decode($line, true);
        if (!is_array($decoded)) {
            continue;
        }
        if ($action !== '' && (string)($decoded['action'] ?? '') !== $action) {
            continue;
        }
        if ($user !== '' && (string)($decoded['user'] ?? '') !== $user) {
            continue;
        }
        $haystack = strtolower(json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        if ($keyword !== '' && strpos($haystack, $keyword) === false) {
            continue;
        }
        $result[] = $decoded;
        if (count($result) >= $limit) {
            break;
        }
    }
    $offset = ($page - 1) * $limit;
    return array_slice($result, $offset, $limit);
}

function webdav_audit_query(array $filters = []): array {
    if (!is_file(WEBDAV_AUDIT_LOG)) {
        return ['items' => [], 'total' => 0, 'page' => 1, 'per_page' => max(1, (int)($filters['limit'] ?? 50)), 'has_next' => false];
    }
    $lines = file(WEBDAV_AUDIT_LOG, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $items = [];
    $action = trim((string)($filters['action'] ?? ''));
    $user = trim((string)($filters['user'] ?? ''));
    $keyword = strtolower(trim((string)($filters['keyword'] ?? '')));
    foreach (array_reverse($lines) as $line) {
        $decoded = json_decode($line, true);
        if (!is_array($decoded)) {
            continue;
        }
        if ($action !== '' && (string)($decoded['action'] ?? '') !== $action) {
            continue;
        }
        if ($user !== '' && (string)($decoded['user'] ?? '') !== $user) {
            continue;
        }
        $haystack = strtolower(json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        if ($keyword !== '' && strpos($haystack, $keyword) === false) {
            continue;
        }
        $items[] = $decoded;
    }
    $page = max(1, (int)($filters['page'] ?? 1));
    $perPage = max(1, min(500, (int)($filters['limit'] ?? 50)));
    $offset = ($page - 1) * $perPage;
    $paged = array_slice($items, $offset, $perPage);
    return [
        'items' => $paged,
        'total' => count($items),
        'page' => $page,
        'per_page' => $perPage,
        'has_next' => ($offset + $perPage) < count($items),
    ];
}

function webdav_audit_export_json(array $filters = []): string {
    $query = webdav_audit_query($filters + ['limit' => (int)($filters['limit'] ?? 500), 'page' => 1]);
    return json_encode($query['items'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function webdav_account_recent_activity_map(int $limit = 1000): array {
    $query = webdav_audit_query(['limit' => $limit, 'page' => 1]);
    $map = [];
    foreach (($query['items'] ?? []) as $entry) {
        $username = trim((string)($entry['user'] ?? ''));
        if ($username === '' || $username === 'system') {
            continue;
        }
        if (isset($map[$username])) {
            continue;
        }
        $context = is_array($entry['context'] ?? null) ? $entry['context'] : [];
        $map[$username] = [
            'time' => (string)($entry['time'] ?? ''),
            'action' => (string)($entry['action'] ?? ''),
            'detail' => trim((string)($context['path'] ?? $context['relative'] ?? $context['destination'] ?? $context['target'] ?? '')),
        ];
    }
    return $map;
}

function webdav_shares_summary(): array {
    $accounts = webdav_accounts_load();
    $recentMap = webdav_account_recent_activity_map();
    $groups = [];
    foreach ($accounts as $account) {
        $displayRoot = webdav_display_local_root((string)($account['root'] ?? '/'));
        if (!isset($groups[$displayRoot])) {
            $groups[$displayRoot] = [
                'root' => $displayRoot,
                'account_count' => 0,
                'enabled_count' => 0,
                'readonly_count' => 0,
                'accounts' => [],
                'last_time' => '',
                'last_action' => '',
                'last_user' => '',
            ];
        }
        $groups[$displayRoot]['account_count'] += 1;
        if (!empty($account['enabled'])) {
            $groups[$displayRoot]['enabled_count'] += 1;
        }
        if (!empty($account['readonly'])) {
            $groups[$displayRoot]['readonly_count'] += 1;
        }
        $groups[$displayRoot]['accounts'][] = $account;
        $recent = $recentMap[(string)($account['username'] ?? '')] ?? null;
        if ($recent && (string)($recent['time'] ?? '') > (string)$groups[$displayRoot]['last_time']) {
            $groups[$displayRoot]['last_time'] = (string)($recent['time'] ?? '');
            $groups[$displayRoot]['last_action'] = (string)($recent['action'] ?? '');
            $groups[$displayRoot]['last_user'] = (string)($account['username'] ?? '');
        }
    }
    $items = array_values($groups);
    usort($items, static function(array $a, array $b): int {
        if ((string)($a['last_time'] ?? '') !== (string)($b['last_time'] ?? '')) {
            return strcmp((string)($b['last_time'] ?? ''), (string)($a['last_time'] ?? ''));
        }
        return strcmp((string)($a['root'] ?? ''), (string)($b['root'] ?? ''));
    });
    return $items;
}

function webdav_client_ip(): string {
    $candidates = [
        (string)($_SERVER['HTTP_X_REAL_IP'] ?? ''),
        (string)($_SERVER['REMOTE_ADDR'] ?? ''),
    ];
    foreach ($candidates as $candidate) {
        $value = trim($candidate);
        if ($value !== '') {
            return $value;
        }
    }
    return '';
}

function webdav_ip_matches(string $ip, string $rule): bool {
    $rule = trim($rule);
    if ($rule === '') {
        return false;
    }
    if ($ip === $rule) {
        return true;
    }
    if (!str_contains($rule, '/')) {
        return false;
    }
    [$subnet, $bits] = array_pad(explode('/', $rule, 2), 2, '');
    if ($subnet === '' || $bits === '' || !filter_var($ip, FILTER_VALIDATE_IP) || !filter_var($subnet, FILTER_VALIDATE_IP)) {
        return false;
    }
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $mask = -1 << (32 - max(0, min(32, (int)$bits)));
        return ((ip2long($ip) & $mask) === (ip2long($subnet) & $mask));
    }
    return false;
}

function webdav_ip_allowed(array $account, string $ip): bool {
    $raw = trim((string)($account['ip_whitelist'] ?? ''));
    if ($raw === '') {
        return true;
    }
    $rules = preg_split('/[\r\n,]+/', $raw) ?: [];
    foreach ($rules as $rule) {
        if (webdav_ip_matches($ip, trim((string)$rule))) {
            return true;
        }
    }
    return false;
}

function webdav_directory_size(string $path): int {
    if (is_file($path)) {
        return (int)(filesize($path) ?: 0);
    }
    if (!is_dir($path)) {
        return 0;
    }
    $size = 0;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $item) {
        if ($item->isFile()) {
            $size += (int)$item->getSize();
        }
    }
    return $size;
}

function webdav_quota_bytes(array $account): int {
    return max(0, (int)($account['quota_mb'] ?? 0)) * 1024 * 1024;
}

function webdav_max_upload_bytes(array $account): int {
    return max(0, (int)($account['max_upload_mb'] ?? 0)) * 1024 * 1024;
}

function webdav_safe_path(string $root, string $requestPath): array {
    $root = rtrim($root, '/');
    $requestPath = rawurldecode($requestPath);
    $relative = '/' . ltrim(trim($requestPath), '/');
    $candidate = preg_replace('#/+#', '/', $root . $relative);
    $dir = dirname($candidate);
    if (!is_dir($dir)) {
        $probe = $dir;
        while ($probe !== '/' && !is_dir($probe)) {
            $probe = dirname($probe);
        }
        $realDir = realpath($probe);
        $candidate = ($realDir ?: $probe) . substr($candidate, strlen($probe));
    } else {
        $realDir = realpath($dir);
        $candidate = ($realDir ?: $dir) . '/' . basename($candidate);
    }
    if (strpos($candidate, $root) !== 0) {
        return ['ok' => false, 'msg' => '非法路径'];
    }
    return ['ok' => true, 'path' => $candidate, 'relative' => $relative];
}

function webdav_delete_tree(string $path): bool {
    if (is_file($path) || is_link($path)) {
        return @unlink($path);
    }
    if (!is_dir($path)) {
        return true;
    }
    foreach (scandir($path) ?: [] as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        if (!webdav_delete_tree($path . '/' . $name)) {
            return false;
        }
    }
    return @rmdir($path);
}

function webdav_copy_tree(string $source, string $target): bool {
    if (is_file($source)) {
        $dir = dirname($target);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return false;
        }
        return @copy($source, $target);
    }
    if (!is_dir($source)) {
        return false;
    }
    if (!is_dir($target) && !@mkdir($target, 0755, true) && !is_dir($target)) {
        return false;
    }
    foreach (scandir($source) ?: [] as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        if (!webdav_copy_tree($source . '/' . $name, $target . '/' . $name)) {
            return false;
        }
    }
    return true;
}

function webdav_format_href(string $baseUri, string $relativePath, bool $isDir): string {
    $relativePath = '/' . ltrim($relativePath, '/');
    $segments = array_map('rawurlencode', array_values(array_filter(explode('/', $relativePath), static fn(string $item): bool => $item !== '')));
    $path = rtrim($baseUri, '/');
    if ($segments) {
        $path .= '/' . implode('/', $segments);
    } else {
        $path .= '/';
    }
    if ($isDir && substr($path, -1) !== '/') {
        $path .= '/';
    }
    return $path;
}

function webdav_multistatus_xml(string $baseUri, array $items): string {
    $xml = new DOMDocument('1.0', 'UTF-8');
    $xml->formatOutput = true;
    $multistatus = $xml->createElementNS('DAV:', 'd:multistatus');
    $xml->appendChild($multistatus);

    foreach ($items as $item) {
        $response = $xml->createElement('d:response');
        $response->appendChild($xml->createElement('d:href', webdav_format_href($baseUri, (string)$item['relative'], !empty($item['is_dir']))));
        $propstat = $xml->createElement('d:propstat');
        $prop = $xml->createElement('d:prop');
        $prop->appendChild($xml->createElement('d:displayname', (string)$item['displayname']));
        $resourceType = $xml->createElement('d:resourcetype');
        if (!empty($item['is_dir'])) {
            $resourceType->appendChild($xml->createElement('d:collection'));
        }
        $prop->appendChild($resourceType);
        $prop->appendChild($xml->createElement('d:getlastmodified', gmdate('D, d M Y H:i:s', (int)$item['mtime']) . ' GMT'));
        $prop->appendChild($xml->createElement('d:creationdate', gmdate('Y-m-d\TH:i:s\Z', (int)$item['mtime'])));
        if (empty($item['is_dir'])) {
            $prop->appendChild($xml->createElement('d:getcontentlength', (string)$item['size']));
            $prop->appendChild($xml->createElement('d:getcontenttype', (string)($item['content_type'] ?? 'application/octet-stream')));
        }
        $propstat->appendChild($prop);
        $propstat->appendChild($xml->createElement('d:status', 'HTTP/1.1 200 OK'));
        $response->appendChild($propstat);
        $multistatus->appendChild($response);
    }

    return $xml->saveXML() ?: '';
}
