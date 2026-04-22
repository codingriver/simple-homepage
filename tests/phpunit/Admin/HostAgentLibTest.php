<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../admin/shared/host_agent_lib.php';

final class HostAgentLibTest extends TestCase
{
    private $serverProc = null;
    private string $routerFile = '';
    private string $counterFile = '';

    protected function setUp(): void
    {
        parent::setUp();
        @unlink(DATA_DIR . '/host_agent.json');
        $this->counterFile = sys_get_temp_dir() . '/ha-counter-' . uniqid() . '.txt';
        @unlink($this->counterFile);
    }

    protected function tearDown(): void
    {
        if ($this->serverProc !== null) {
            proc_terminate($this->serverProc, 9);
            proc_close($this->serverProc);
            $this->serverProc = null;
        }
        if ($this->routerFile !== '' && is_file($this->routerFile)) {
            @unlink($this->routerFile);
        }
        @unlink($this->counterFile);
        @unlink(DATA_DIR . '/host_agent.json');
        parent::tearDown();
    }

    private function getFreePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if (!$socket) {
            throw new RuntimeException($errstr);
        }
        $name = stream_socket_get_name($socket, false);
        fclose($socket);
        if (!preg_match('/:(\d+)$/', $name, $m)) {
            throw new RuntimeException('Could not determine free port');
        }
        return (int)$m[1];
    }

    private function startServer(string $script): int
    {
        $port = $this->getFreePort();
        $this->routerFile = sys_get_temp_dir() . '/ha-router-' . uniqid() . '.php';
        file_put_contents($this->routerFile, $script);
        $descriptors = [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']];
        $env = array_filter(
            array_merge($_ENV, $_SERVER, ['HA_TEST_COUNTER' => $this->counterFile]),
            static fn($v) => is_string($v) || is_int($v) || is_float($v) || is_bool($v)
        );
        $this->serverProc = proc_open(
            ['php', '-S', '127.0.0.1:' . $port, $this->routerFile],
            $descriptors,
            $pipes,
            null,
            $env
        );
        for ($i = 0; $i < 20; $i++) {
            $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
            if ($fp) {
                fclose($fp);
                return $port;
            }
            usleep(50000);
        }
        $this->fail('Failed to start test server');
        return 0;
    }

    public function testRetryLogicSimulatesFailureThenSuccess(): void
    {
        $script = '<?php
$c = getenv("HA_TEST_COUNTER");
$n = is_file($c) ? (int)file_get_contents($c) : 0;
$n++;
file_put_contents($c, (string)$n);
if ($n < 2) {
    http_response_code(503);
    echo json_encode(["ok" => false, "msg" => "down"]);
    exit;
}
http_response_code(200);
echo json_encode(["ok" => true, "msg" => "up", "data" => ["n" => $n]]);
';
        $port = $this->startServer($script);
        host_agent_save_state([
            'service_url' => 'http://127.0.0.1:' . $port,
            'token' => 'testtoken',
            'container_name' => '',
        ]);
        $result = host_agent_api_request('GET', '/health');
        $this->assertTrue($result['ok']);
        $this->assertSame(200, $result['status']);
        $this->assertSame('up', $result['msg']);
        $this->assertSame(['n' => 2], $result['data']);
    }

    public function testJsonParseFailureHandling(): void
    {
        $script = '<?php http_response_code(200); echo "not-json";';
        $port = $this->startServer($script);
        $result = host_agent_http_request('http://127.0.0.1:' . $port, 'GET', 'testtoken');
        $this->assertFalse($result['ok']);
        $this->assertSame(200, $result['status']);
        $this->assertSame('', $result['msg']);
        $this->assertNull($result['data']);
        $this->assertSame('not-json', $result['body']);
    }

    public function testHttpStatusCodeNon200Handling(): void
    {
        $script = '<?php http_response_code(404); echo json_encode(["ok" => true, "msg" => "found"]);';
        $port = $this->startServer($script);
        $result = host_agent_http_request('http://127.0.0.1:' . $port, 'GET', 'testtoken');
        $this->assertFalse($result['ok']);
        $this->assertSame(404, $result['status']);
    }

    public function testTimeoutConfigurationReturnsErrorInsteadOfHanging(): void
    {
        $result = host_agent_http_request('http://127.0.0.1:1/health', 'GET', 'testtoken');
        $this->assertFalse($result['ok']);
        $this->assertNotSame(0, $result['errno']);
        $this->assertNotEmpty($result['error']);
    }
}
