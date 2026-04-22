<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ApiDnsTest extends TestCase
{
    private string $apiFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->apiFile = realpath(__DIR__ . '/../../../public/api/dns.php');
    }

    private function runApiDns(array $server = [], array $get = [], array $post = [], string $method = 'GET'): string
    {
        $php = PHP_BINARY;
        $serverJson = json_encode($server + ['REQUEST_METHOD' => $method]);
        $getJson = json_encode($get);
        $postJson = json_encode($post);

        $dataDir = DATA_DIR;
        $script = <<<CODE
<?php
if (!defined('DATA_DIR')) { define('DATA_DIR', '{$dataDir}'); }
\$_SERVER = json_decode('{$serverJson}', true);
\$_GET = json_decode('{$getJson}', true);
\$_POST = json_decode('{$postJson}', true);
ob_start();
require '{$this->apiFile}';
echo ob_get_clean();
CODE;
        $tmpFile = tempnam(sys_get_temp_dir(), 'api_dns_');
        file_put_contents($tmpFile, $script);
        $output = shell_exec(escapeshellarg($php) . ' ' . escapeshellarg($tmpFile) . ' 2>&1');
        unlink($tmpFile);
        return (string) $output;
    }

    public function testNonLocalhostIpReturns403(): void
    {
        $output = $this->runApiDns(['REMOTE_ADDR' => '192.168.1.1']);
        $data = json_decode($output, true);
        $this->assertIsArray($data);
        $this->assertSame(-1, $data['code'] ?? null);
        $this->assertStringContainsString('仅允许本机', $data['msg'] ?? '');
    }

    public function testUnknownActionReturns400(): void
    {
        $output = $this->runApiDns(
            ['REMOTE_ADDR' => '127.0.0.1'],
            ['action' => 'unknown_action']
        );
        $data = json_decode($output, true);
        $this->assertIsArray($data);
        $this->assertSame(-1, $data['code'] ?? null);
        $this->assertStringContainsString('未知 action', $data['msg'] ?? '');
    }

    public function testQueryActionWithDomain(): void
    {
        $output = $this->runApiDns(
            ['REMOTE_ADDR' => '127.0.0.1'],
            ['action' => 'query', 'domain' => 'example.com']
        );
        $data = json_decode($output, true);
        $this->assertIsArray($data);
        $this->assertSame(-1, $data['code'] ?? null);
        $this->assertStringContainsString('Zone', $data['msg'] ?? '');
    }

    public function testPostUpdateActionValidation(): void
    {
        $output = $this->runApiDns(
            ['REMOTE_ADDR' => '127.0.0.1', 'CONTENT_TYPE' => 'application/x-www-form-urlencoded'],
            [],
            ['action' => 'update', 'domain' => '', 'value' => ''],
            'POST'
        );
        $data = json_decode($output, true);
        $this->assertIsArray($data);
        $this->assertSame(-1, $data['code'] ?? null);
    }

    public function testExceptionHandlingReturns500(): void
    {
        $php = PHP_BINARY;
        $original = file_get_contents($this->apiFile);
        $originalDir = dirname($this->apiFile);
        $escapedDir = str_replace("'", "\\'", $originalDir);
        $modified = str_replace('__DIR__', "'" . $escapedDir . "'", $original);
        $modified = str_replace(
            'try {',
            "try {\n    if (!empty(\$_GET['__inject_error'])) { throw new RuntimeException('injected'); }",
            $modified
        );
        $tmpFile = tempnam(sys_get_temp_dir(), 'api_dns_500_') . '.php';
        file_put_contents($tmpFile, $modified);

        $script = <<<CODE
<?php
\$_SERVER = ['REMOTE_ADDR' => '127.0.0.1', 'REQUEST_METHOD' => 'GET'];
\$_GET = ['action' => 'query', 'domain' => 'example.com', '__inject_error' => 1];
ob_start();
require '{$tmpFile}';
echo ob_get_clean();
CODE;
        $tmpRunner = tempnam(sys_get_temp_dir(), 'api_dns_500_runner_') . '.php';
        file_put_contents($tmpRunner, $script);
        $output = shell_exec(escapeshellarg($php) . ' ' . escapeshellarg($tmpRunner) . ' 2>&1');
        unlink($tmpFile);
        unlink($tmpRunner);

        $data = json_decode($output, true);
        $this->assertIsArray($data);
        $this->assertSame(-1, $data['code'] ?? null);
        $this->assertStringContainsString('内部错误', $data['msg'] ?? '');
    }
}
