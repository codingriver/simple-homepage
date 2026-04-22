<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ApiSitesTest extends TestCase
{
    private string $apiFile;

    protected function setUp(): void
    {
        parent::setUp();
        auth_reset_config_cache();
        @unlink(API_TOKENS_FILE);
        @unlink(SITES_FILE);
        @unlink(CONFIG_FILE);
        $this->apiFile = realpath(__DIR__ . '/../../../public/api/sites.php');
    }

    private function runApiSites(array $server = [], array $get = []): string
    {
        $php = PHP_BINARY;
        $serverJson = json_encode($server + [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/api/sites.php',
            'HTTP_HOST' => 'localhost',
        ]);
        $getJson = json_encode($get);

        $dataDir = DATA_DIR;
        $script = <<<CODE
<?php
if (!defined('DATA_DIR')) { define('DATA_DIR', '{$dataDir}'); }
\$_SERVER = json_decode('{$serverJson}', true);
\$_GET = json_decode('{$getJson}', true);
ob_start();
require '{$this->apiFile}';
echo ob_get_clean();
CODE;
        $tmpFile = tempnam(sys_get_temp_dir(), 'api_sites_');
        file_put_contents($tmpFile, $script);
        $output = shell_exec(escapeshellarg($php) . ' ' . escapeshellarg($tmpFile) . ' 2>&1');
        unlink($tmpFile);
        return (string) $output;
    }

    public function testMissingTokenReturns401(): void
    {
        $output = $this->runApiSites();
        $data = json_decode($output, true);
        $this->assertIsArray($data);
        $this->assertFalse($data['ok'] ?? true);
        $this->assertSame('无效的 API Token', $data['msg'] ?? '');
    }

    public function testInvalidTokenReturns401(): void
    {
        $output = $this->runApiSites(['HTTP_AUTHORIZATION' => 'Bearer invalid-token']);
        $data = json_decode($output, true);
        $this->assertFalse($data['ok'] ?? true);
        $this->assertSame('无效的 API Token', $data['msg'] ?? '');
    }

    public function testValidTokenReturnsCorrectStructure(): void
    {
        api_token_generate('test');
        $tokens = api_tokens_load();
        $token = array_key_first($tokens);

        save_sites(['groups' => [
            ['name' => 'Group1', 'sites' => [['name' => 'Site1', 'url' => 'http://example.com']]],
        ]]);

        $output = $this->runApiSites(['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        $data = json_decode($output, true);
        $this->assertIsArray($data);
        $this->assertTrue($data['ok'] ?? false);
        $this->assertIsArray($data['groups'] ?? null);
        $this->assertCount(1, $data['groups']);
        $this->assertSame('Group1', $data['groups'][0]['name'] ?? '');
    }

    public function testEmptyGroupsHandledCorrectly(): void
    {
        api_token_generate('test');
        $tokens = api_tokens_load();
        $token = array_key_first($tokens);

        save_sites(['groups' => []]);

        $output = $this->runApiSites(['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        $data = json_decode($output, true);
        $this->assertIsArray($data);
        $this->assertTrue($data['ok'] ?? false);
        $this->assertSame([], $data['groups']);
    }
}
