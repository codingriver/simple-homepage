<?php
/**
 * HTTP 客户端通用库
 * 被 public/、admin/、shared/、cli/ 各层共享引用
 */

/**
 * 发送 JSON POST 请求
 *
 * @param string $url     目标 URL
 * @param string $payload JSON 字符串
 * @param int    $timeout 超时秒数（默认 5）
 * @return array ['ok' => bool, 'status' => int, 'body' => string, 'error' => string]
 */
function http_post_json(string $url, string $payload, int $timeout = 5): array {
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
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return [
            'ok' => $status >= 200 && $status < 400,
            'status' => $status,
            'body' => (string) $body,
            'error' => '',
        ];
    }

    $ctx = stream_context_create([
        'http' => [
            'method'  => 'POST',
            'header'  => "Content-Type: application/json\r\nContent-Length: " . strlen($payload) . "\r\n",
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
        $status = (int) ($m[1] ?? 0);
    }
    return [
        'ok' => $body !== false && $status >= 200 && $status < 400,
        'status' => $status,
        'body' => $body === false ? '' : (string) $body,
        'error' => $body === false ? '请求失败' : '',
    ];
}
