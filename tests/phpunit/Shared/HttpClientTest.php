<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class HttpClientTest extends TestCase
{
    public function testRejectsInvalidUrl(): void
    {
        $result = http_post_json('not-a-url', '{}');
        $this->assertFalse($result['ok']);
        $this->assertSame(0, $result['status']);
        $this->assertNotEmpty($result['error']);
    }

    public function testSuccessWithHttpBin(): void
    {
        $result = http_post_json('https://httpbin.org/post', '{"test":1}', 15);
        if (!$result['ok'] && $result['status'] === 0) {
            $this->markTestSkipped('Network unavailable (httpbin.org unreachable)');
        }
        $this->assertTrue($result['ok'], 'HTTP request failed: ' . ($result['error'] ?? ''));
        $this->assertSame(200, $result['status']);
        $this->assertStringContainsString('"test"', $result['body']);
    }

    public function testTimeout(): void
    {
        $start = microtime(true);
        $result = http_post_json('https://httpbin.org/delay/10', '{}', 1);
        $elapsed = microtime(true) - $start;
        $this->assertFalse($result['ok']);
        $this->assertLessThan(5, $elapsed, 'Request should timeout quickly');
        $this->assertNotEmpty($result['error']);
    }

    public function testStatusCodeParsing(): void
    {
        $result = http_post_json('https://httpbin.org/status/418', '{}', 10);
        if (!$result['ok'] && $result['status'] === 0) {
            $this->markTestSkipped('Network unavailable');
        }
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
echo json_encode(http_post_json('https://httpbin.org/post', '{"fallback":true}', 15));
CODE
        );

        $output = shell_exec(escapeshellarg(PHP_BINARY) . ' -d disable_functions=curl_init ' . escapeshellarg($scriptFile) . ' 2>&1');
        unlink($scriptFile);

        $this->assertNotNull($output);
        $result = json_decode(trim($output), true);
        $this->assertIsArray($result, 'Failed to decode fallback response: ' . trim((string)$output));
        if (!($result['ok'] ?? false) && ($result['status'] ?? 0) === 0) {
            $this->markTestSkipped('Network unavailable for fallback test');
        }
        $this->assertTrue($result['ok'] ?? false, 'Fallback request failed: ' . ($result['error'] ?? ''));
        $this->assertSame(200, $result['status'] ?? 0);
        $this->assertStringContainsString('"fallback"', $result['body'] ?? '');
    }
}
