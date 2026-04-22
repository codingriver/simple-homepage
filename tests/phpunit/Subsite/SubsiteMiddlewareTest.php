<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class SubsiteMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        auth_ensure_secret_key();
        @unlink(DATA_DIR . '/sessions.json');
        putenv('NAV_AUTH_PHP_PATH');
    }

    /**
     * Start a temporary PHP built-in server to test HTTP middleware behavior.
     */
    private function startTempServer(string $entryScript): array
    {
        $docRoot = dirname($entryScript);
        $router = tempnam(sys_get_temp_dir(), 'router_') . '.php';
        file_put_contents($router, '<?php require ' . var_export($entryScript, true) . ';');

        $port = 0;
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:0', $router],
            $descriptors,
            $pipes,
            $docRoot
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Failed to start temp server');
        }
        // Read first line of output to get the port
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        usleep(300000);
        $status = proc_get_status($process);
        $pid = $status['pid'];

        // Find listening port from /proc/{pid}/fd or netstat
        $port = null;
        for ($i = 0; $i < 20 && $port === null; $i++) {
            usleep(100000);
            $netstat = @shell_exec('ss -tlnp 2>/dev/null | grep ' . (int)$pid . ' | awk \'{print $4}\'');
            if ($netstat) {
                $parts = explode(':', trim($netstat));
                $port = (int) array_pop($parts);
            }
        }
        if (!$port) {
            proc_terminate($process);
            throw new RuntimeException('Could not detect server port');
        }
        return ['process' => $process, 'port' => $port, 'router' => $router];
    }

    private function stopTempServer(array $server): void
    {
        proc_terminate($server['process']);
        proc_close($server['process']);
        @unlink($server['router']);
    }

    public function testNavTokenInUrlValidatesAndRedirectsToCleanUrl(): void
    {
        auth_ensure_secret_key();
        $token = auth_generate_token('admin', 'admin');

        $middlewareFile = realpath(__DIR__ . '/../../../subsite-middleware/auth_check.php');
        $authFile = realpath(__DIR__ . '/../../../shared/auth.php');
        $entryScript = tempnam(sys_get_temp_dir(), 'subsite_entry_') . '.php';
        file_put_contents($entryScript, "<?php\nrequire '{$authFile}';\nauth_ensure_secret_key();\n\$token = auth_generate_token('admin', 'admin');\n\$_SERVER['REQUEST_URI'] = '/subsite/page.php?_nav_token=' . urlencode(\$token) . '&foo=bar';\n\$_GET = ['_nav_token' => \$token, 'foo' => 'bar'];\n\$_COOKIE = [];\nrequire '{$middlewareFile}';\n");

        $server = $this->startTempServer($entryScript);
        try {
            $ch = curl_init('http://127.0.0.1:' . $server['port'] . '/subsite/page.php?_nav_token=' . urlencode($token) . '&foo=bar');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HEADER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $this->assertSame(302, $httpCode);
            $this->assertStringContainsString('Location:', $response);
            $this->assertStringContainsString('/subsite/page.php', $response);
            $this->assertStringNotContainsString('_nav_token', $response);
        } finally {
            $this->stopTempServer($server);
            @unlink($entryScript);
        }
    }

    public function testInvalidNavTokenRedirectsToLogin(): void
    {
        $middlewareFile = realpath(__DIR__ . '/../../../subsite-middleware/auth_check.php');
        $entryScript = tempnam(sys_get_temp_dir(), 'subsite_entry_') . '.php';
        file_put_contents($entryScript, "<?php\n\$_SERVER['REQUEST_URI'] = '/subsite/page.php?_nav_token=invalid';\n\$_GET = ['_nav_token' => 'invalid'];\n\$_COOKIE = [];\nrequire '{$middlewareFile}';\n");

        $server = $this->startTempServer($entryScript);
        try {
            $ch = curl_init('http://127.0.0.1:' . $server['port'] . '/subsite/page.php?_nav_token=invalid');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HEADER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $this->assertSame(302, $httpCode);
            $this->assertStringContainsString('Location:', $response);
            $this->assertStringContainsString('login.php', $response);
        } finally {
            $this->stopTempServer($server);
            @unlink($entryScript);
        }
    }

    public function testNoCookieAndNoTokenRedirectsToLogin(): void
    {
        $middlewareFile = realpath(__DIR__ . '/../../../subsite-middleware/auth_check.php');
        $entryScript = tempnam(sys_get_temp_dir(), 'subsite_entry_') . '.php';
        file_put_contents($entryScript, "<?php\n\$_SERVER['REQUEST_URI'] = '/subsite/page.php';\n\$_GET = [];\n\$_COOKIE = [];\nrequire '{$middlewareFile}';\n");

        $server = $this->startTempServer($entryScript);
        try {
            $ch = curl_init('http://127.0.0.1:' . $server['port'] . '/subsite/page.php');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HEADER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $this->assertSame(302, $httpCode);
            $this->assertStringContainsString('Location:', $response);
            $this->assertStringContainsString('login.php', $response);
            $this->assertStringContainsString('redirect=', $response);
        } finally {
            $this->stopTempServer($server);
            @unlink($entryScript);
        }
    }

    public function testNavAuthPhpPathEnvironmentVariable(): void
    {
        $mockAuth = tempnam(sys_get_temp_dir(), 'mock_auth_') . '.php';
        file_put_contents($mockAuth, '<?php define("SESSION_COOKIE_NAME", "nav_session"); function auth_get_current_user() { return ["username"=>"testuser"]; } function auth_nav_login_url() { return "/login.php"; } function auth_set_cookie($t) {} function auth_verify_token($t) { return false; }');

        $middlewareFile = realpath(__DIR__ . '/../../../subsite-middleware/auth_check.php');
        $entryScript = tempnam(sys_get_temp_dir(), 'subsite_env_') . '.php';
        file_put_contents($entryScript, "<?php\nputenv('NAV_AUTH_PHP_PATH={$mockAuth}');\n\$_SERVER['REQUEST_URI'] = '/subsite/';\n\$_GET = [];\n\$_COOKIE = [];\nrequire '{$middlewareFile}';\nif (isset(\$GLOBALS['nav_user']) && (\$GLOBALS['nav_user']['username'] ?? '') === 'testuser') { echo 'USER_SET_OK'; }\n");

        $server = $this->startTempServer($entryScript);
        try {
            $ch = curl_init('http://127.0.0.1:' . $server['port'] . '/subsite/');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $response = curl_exec($ch);
            curl_close($ch);

            $this->assertStringContainsString('USER_SET_OK', $response);
        } finally {
            $this->stopTempServer($server);
            @unlink($entryScript);
            @unlink($mockAuth);
        }
    }
}
