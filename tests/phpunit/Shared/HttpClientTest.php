<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class HttpClientTest extends TestCase
{
    private static $serverProcess = null;
    private static string $serverUrl = '';
    private static string $routerFile = '';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::$routerFile = tempnam(sys_get_temp_dir(), 'riverops_http_router_');
        file_put_contents(self::$routerFile, <<<'PHP'
<?php
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if ($path === '/delay') {
    sleep(3);
}
if ($path === '/status/418') {
    http_response_code(418);
    echo 'teapot';
    return;
}
header('Content-Type: application/json');
echo json_encode([
    'method' => $_SERVER['REQUEST_METHOD'] ?? '',
    'body' => file_get_contents('php://input'),
]);
PHP
        );

        $port = self::reserveLocalPort();
        self::$serverUrl = 'http://127.0.0.1:' . $port;
        $command = escapeshellarg(PHP_BINARY)
            . ' -S 127.0.0.1:' . $port
            . ' ' . escapeshellarg(self::$routerFile);
        self::$serverProcess = proc_open($command, [
            0 => ['pipe', 'r'],
            1 => ['file', sys_get_temp_dir() . '/riverops-http-test.out.log', 'a'],
            2 => ['file', sys_get_temp_dir() . '/riverops-http-test.err.log', 'a'],
        ], $pipes);
        if (!is_resource(self::$serverProcess)) {
            throw new RuntimeException('Unable to start local HTTP test server');
        }
        if (isset($pipes[0]) && is_resource($pipes[0])) {
            fclose($pipes[0]);
        }

        $ready = false;
        for ($i = 0; $i < 50; $i++) {
            $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
            if (is_resource($socket)) {
                fclose($socket);
                $ready = true;
                break;
            }
            usleep(100000);
        }
        if (!$ready) {
            throw new RuntimeException('Local HTTP test server did not become ready');
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$serverProcess)) {
            proc_terminate(self::$serverProcess);
            proc_close(self::$serverProcess);
        }
        if (self::$routerFile !== '') {
            @unlink(self::$routerFile);
        }
        parent::tearDownAfterClass();
    }

    private static function reserveLocalPort(): int
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if (!is_resource($server)) {
            throw new RuntimeException('Unable to reserve local port: ' . $errstr);
        }
        $name = stream_socket_get_name($server, false);
        fclose($server);
        if (!is_string($name) || !preg_match('/:(\d+)$/', $name, $matches)) {
            throw new RuntimeException('Unable to determine reserved local port');
        }
        return (int) $matches[1];
    }

    public function testRejectsInvalidUrl(): void
    {
        $result = http_post_json('not-a-url', '{}');
        $this->assertFalse($result['ok']);
        $this->assertSame(0, $result['status']);
        $this->assertNotEmpty($result['error']);
    }

    public function testSuccessWithLocalServer(): void
    {
        $result = http_post_json(self::$serverUrl . '/post', '{"test":1}', 5);
        $this->assertTrue($result['ok'], 'HTTP request failed: ' . ($result['error'] ?? ''));
        $this->assertSame(200, $result['status']);
        $this->assertStringContainsString('\\"test\\":1', $result['body']);
    }

    public function testTimeout(): void
    {
        $start = microtime(true);
        $result = http_post_json(self::$serverUrl . '/delay', '{}', 1);
        $elapsed = microtime(true) - $start;
        $this->assertFalse($result['ok']);
        $this->assertLessThan(5, $elapsed, 'Request should timeout quickly');
        $this->assertNotEmpty($result['error']);
    }

    public function testStatusCodeParsing(): void
    {
        $result = http_post_json(self::$serverUrl . '/status/418', '{}', 5);
        $this->assertFalse($result['ok']);
        $this->assertSame(418, $result['status']);
    }

    public function testFallbackToFileGetContents(): void
    {
        $srcFile = realpath(__DIR__ . '/../../../shared/http_client.php');
        $scriptFile = tempnam(sys_get_temp_dir(), 'http_client_test_');
        file_put_contents($scriptFile, <<<CODE
<?php
require '{$srcFile}';
echo json_encode(http_post_json('{$this->serverUrlForScript()}/post', '{"fallback":true}', 5));
CODE
        );

        $output = shell_exec(escapeshellarg(PHP_BINARY) . ' -d disable_functions=curl_init ' . escapeshellarg($scriptFile) . ' 2>&1');
        unlink($scriptFile);

        $this->assertNotNull($output);
        $result = json_decode(trim($output), true);
        $this->assertIsArray($result, 'Failed to decode fallback response: ' . trim((string)$output));
        $this->assertTrue($result['ok'] ?? false, 'Fallback request failed: ' . ($result['error'] ?? ''));
        $this->assertSame(200, $result['status'] ?? 0);
        $this->assertStringContainsString('\\"fallback\\":true', $result['body'] ?? '');
    }

    private function serverUrlForScript(): string
    {
        return self::$serverUrl;
    }
}
