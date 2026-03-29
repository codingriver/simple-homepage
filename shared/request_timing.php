<?php
/**
 * 请求耗时日志：收到请求时一条、响应结束前一条，带时间戳与耗时，便于排查局域网/慢响应。
 *
 * 局域网仍可能偏慢的常见原因（与「内容多少」无直接关系）：
 * - 无线/WiFi 抖动、交换机或路由器排队
 * - 宿主机 Docker 端口映射（NAT）额外延迟
 * - 浏览器并行连接、DNS（若用主机名）
 * - PHP 首次冷启动、OPcache 未命中
 * - 本日志的两次写盘（recv/done）亦有微小开销，排障后可 NAV_REQUEST_TIMING=0 关闭
 *
 * 环境变量：NAV_REQUEST_TIMING=0 关闭；CLI 默认不写，需调试 CLI 时设 NAV_REQUEST_TIMING_CLI=1
 */
if (defined('NAV_REQUEST_TIMING_LOADED')) {
    return;
}
define('NAV_REQUEST_TIMING_LOADED', true);

if (getenv('NAV_REQUEST_TIMING') === '0') {
    return;
}
if (PHP_SAPI === 'cli' && getenv('NAV_REQUEST_TIMING_CLI') !== '1') {
    return;
}

if (!defined('NAV_TIMING_LOG_FILE')) {
    define('NAV_TIMING_LOG_FILE', DATA_DIR . '/logs/request_timing.log');
}

if (!defined('NAV_REQUEST_T0')) {
    define('NAV_REQUEST_T0', microtime(true));
}

/**
 * @param 'recv'|'done' $phase
 */
function nav_request_timing_write(string $phase, float $elapsedSec, int $httpCode = 0): void {
    $dir = dirname(NAV_TIMING_LOG_FILE);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    $method = $_SERVER['REQUEST_METHOD'] ?? '?';
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    if (strlen($uri) > 400) {
        $uri = substr($uri, 0, 400) . '...';
    }
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    $ts = date('Y-m-d H:i:s');

    if ($phase === 'recv') {
        $line = sprintf("[%s] recv | t=+0.000s | %s | %s | ip=%s | script=%s\n", $ts, $method, $uri, $ip, $script);
    } else {
        $line = sprintf(
            "[%s] done | t=+%.3fs | HTTP %d | %s | %s | ip=%s | script=%s\n",
            $ts,
            $elapsedSec,
            $httpCode,
            $method,
            $uri,
            $ip,
            $script
        );
    }

    @file_put_contents(NAV_TIMING_LOG_FILE, $line, FILE_APPEND | LOCK_EX);
}

nav_request_timing_write('recv', 0.0);

register_shutdown_function(static function (): void {
    if (getenv('NAV_REQUEST_TIMING') === '0') {
        return;
    }
    if (PHP_SAPI === 'cli' && getenv('NAV_REQUEST_TIMING_CLI') !== '1') {
        return;
    }

    $elapsed = microtime(true) - (float) NAV_REQUEST_T0;
    $code = http_response_code();
    if ($code === false || $code < 100) {
        $code = 200;
    }
    nav_request_timing_write('done', $elapsed, (int) $code);
});
