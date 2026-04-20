<?php
/**
 * 统一日志中心 API
 * admin/logs_api.php
 */

require_once __DIR__ . '/shared/functions.php';

header('Content-Type: application/json; charset=utf-8');

$current_admin = auth_get_current_user();
if (!$current_admin || ($current_admin['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'msg' => '未登录或无权限'], JSON_UNESCAPED_UNICODE);
    exit;
}

$logSources = [
    'nginx_access'   => ['path' => '/var/log/nginx/nav.access.log',   'category' => 'system', 'label' => 'Nginx 访问日志',    'clearable' => false],
    'nginx_error'    => ['path' => '/var/log/nginx/nav.error.log',    'category' => 'system', 'label' => 'Nginx 错误日志',    'clearable' => false],
    'nginx_main'     => ['path' => '/var/log/nginx/error.log',        'category' => 'system', 'label' => 'Nginx 主错误日志',  'clearable' => false],
    'php_fpm'        => ['path' => '/var/log/php-fpm/error.log',      'category' => 'system', 'label' => 'PHP-FPM 日志',      'clearable' => false],
    'request_timing' => ['path' => DATA_DIR . '/logs/request_timing.log', 'category' => 'app', 'label' => '请求耗时日志',      'clearable' => true],
    'dns'            => ['path' => DATA_DIR . '/logs/dns.log',        'category' => 'app', 'label' => 'DNS 日志',          'clearable' => true],
    'dns_python'     => ['path' => DATA_DIR . '/logs/dns_python.log', 'category' => 'app', 'label' => 'DNS Python 日志',   'clearable' => true],
    'notifications'  => ['path' => DATA_DIR . '/logs/notifications.log', 'category' => 'app', 'label' => '通知日志',         'clearable' => true],
    'auth'           => ['path' => DATA_DIR . '/logs/auth.log',       'category' => 'app', 'label' => '登录认证日志',      'clearable' => true],
    'audit'          => ['path' => DATA_DIR . '/logs/audit.log',      'category' => 'app', 'label' => '操作审计日志',      'clearable' => true],
];

function log_count_lines(string $path): int {
    if (!file_exists($path) || !is_readable($path)) {
        return 0;
    }
    $fp = fopen($path, 'rb');
    if (!$fp) {
        return 0;
    }
    $count = 0;
    while (!feof($fp)) {
        $chunk = fread($fp, 8192);
        if ($chunk === false) {
            break;
        }
        $count += substr_count($chunk, "\n");
    }
    fclose($fp);
    return $count;
}

function log_read_tail(string $path, int $limit): array {
    $fp = fopen($path, 'rb');
    if (!$fp) {
        return [];
    }
    fseek($fp, 0, SEEK_END);
    $pos = ftell($fp);
    $buf = '';
    $lines = [];
    $chunk = 8192;
    while ($pos > 0 && count($lines) < $limit) {
        $read = min($chunk, $pos);
        $pos -= $read;
        fseek($fp, $pos);
        $data = fread($fp, $read);
        if ($data === false) {
            break;
        }
        $buf = $data . $buf;
        $parts = explode("\n", $buf);
        $buf = array_shift($parts);
        foreach (array_reverse($parts) as $line) {
            $t = rtrim($line, "\r\n");
            if ($t === '' && count($lines) === 0) {
                continue;
            }
            $lines[] = $t;
            if (count($lines) >= $limit) {
                break 2;
            }
        }
    }
    fclose($fp);
    if ($buf !== '' && count($lines) < $limit) {
        $last = rtrim($buf, "\r\n");
        if ($last !== '' || count($lines) > 0) {
            $lines[] = $last;
        }
    }
    return array_reverse($lines);
}

function log_read_range(string $path, int $offset, int $limit): array {
    $fp = fopen($path, 'rb');
    if (!$fp) {
        return [];
    }
    $skipped = 0;
    while ($skipped < $offset && !feof($fp)) {
        if (fgets($fp) === false) {
            break;
        }
        $skipped++;
    }
    $lines = [];
    $read = 0;
    while ($read < $limit && !feof($fp)) {
        $line = fgets($fp);
        if ($line === false) {
            break;
        }
        $lines[] = rtrim($line, "\r\n");
        $read++;
    }
    fclose($fp);
    return $lines;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($action === 'list') {
    $result = [];
    foreach ($logSources as $key => $meta) {
        $path = $meta['path'];
        clearstatcache(true, $path);
        $exists = file_exists($path);
        $size = $exists ? filesize($path) : 0;
        $lines = $exists ? log_count_lines($path) : 0;
        $result[$key] = [
            'key'       => $key,
            'label'     => $meta['label'],
            'category'  => $meta['category'],
            'clearable' => $meta['clearable'],
            'exists'    => $exists,
            'readable'  => $exists && is_readable($path),
            'size'      => $size,
            'lines'     => $lines,
            'updated_at'=> $exists ? filemtime($path) : 0,
        ];
    }
    echo json_encode(['ok' => true, 'sources' => $result], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'read') {
    $type = $_GET['type'] ?? '';
    if (!isset($logSources[$type])) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'msg' => '未知日志类型'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $path = $logSources[$type]['path'];
    // 清除文件状态缓存，避免 bind-mount 环境下 filesize/ftell 返回旧值
    clearstatcache(true, $path);
    if (!file_exists($path) || !is_readable($path)) {
        echo json_encode(['ok' => true, 'type' => $type, 'total_lines' => 0, 'offset' => 0, 'limit' => 0, 'lines' => [], 'has_more' => false], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $limit = min(5000, max(1, (int)($_GET['limit'] ?? 100)));
    $total = log_count_lines($path);
    $direction = ($_GET['direction'] ?? '') === 'tail' ? 'tail' : 'forward';

    if ($direction === 'tail') {
        $lines = log_read_tail($path, $limit);
        $offset = max(0, $total - count($lines));
    } else {
        $offset = max(0, (int)($_GET['offset'] ?? 0));
        $lines = log_read_range($path, $offset, $limit);
    }

    echo json_encode([
        'ok'         => true,
        'type'       => $type,
        'total_lines'=> $total,
        'offset'     => $offset,
        'limit'      => $limit,
        'lines'      => $lines,
        'has_more'   => $offset > 0,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'clear' || $action === 'clear_all') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'msg' => '需要 POST 请求'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    csrf_check();

    $cleared = [];
    $failed = [];

    if ($action === 'clear_all') {
        foreach ($logSources as $key => $meta) {
            if (!$meta['clearable']) {
                continue;
            }
            $path = $meta['path'];
            if (!file_exists($path)) {
                continue;
            }
            if (file_put_contents($path, '', LOCK_EX) !== false) {
                $cleared[] = $key;
            } else {
                $failed[] = $key;
            }
        }
    } else {
        $type = $_POST['type'] ?? '';
        if (!isset($logSources[$type]) || !$logSources[$type]['clearable']) {
            echo json_encode(['ok' => false, 'msg' => '该日志不允许清空'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $path = $logSources[$type]['path'];
        if (file_exists($path)) {
            if (file_put_contents($path, '', LOCK_EX) !== false) {
                $cleared[] = $type;
            } else {
                $failed[] = $type;
            }
        } else {
            $cleared[] = $type;
        }
    }

    echo json_encode([
        'ok'      => empty($failed),
        'cleared' => $cleared,
        'failed'  => $failed,
        'msg'     => empty($failed) ? '已清空 ' . count($cleared) . ' 个日志文件' : '部分日志清空失败',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'download') {
    $type = $_GET['type'] ?? '';
    if (!isset($logSources[$type])) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'msg' => '未知日志类型'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $path = $logSources[$type]['path'];
    if (!file_exists($path) || !is_readable($path)) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'msg' => '日志文件不存在'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $type . '_log_' . date('Ymd_His') . '.txt"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'msg' => '未知操作'], JSON_UNESCAPED_UNICODE);
