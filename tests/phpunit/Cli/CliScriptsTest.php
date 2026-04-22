<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class CliScriptsTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->projectRoot = realpath(__DIR__ . '/../../..');
        auth_reset_config_cache();
        @unlink(CONFIG_FILE);
        @unlink(SITES_FILE);
        @unlink(USERS_FILE);
    }

    private function runCli(string $script, array $args = []): array
    {
        $php = PHP_BINARY;
        $scriptPath = $this->projectRoot . '/' . $script;
        $dataDir = DATA_DIR;

        $wrapper = tempnam(sys_get_temp_dir(), 'cli_wrapper_') . '.php';
        file_put_contents($wrapper, <<<CODE
<?php
define('DATA_DIR', '{$dataDir}');
require '{$scriptPath}';
CODE
        );

        $argStr = implode(' ', array_map('escapeshellarg', $args));
        $cmd = escapeshellarg($php) . ' ' . escapeshellarg($wrapper) . ($argStr ? ' ' . $argStr : '') . ' 2>&1';

        $cmdWithCode = 'bash -c ' . escapeshellarg($cmd . '; echo "___EXIT_CODE___$?"');
        $fullOutput = shell_exec($cmdWithCode);
        $exitCode = 0;
        $output = $fullOutput;
        if (preg_match('/___EXIT_CODE___(\d+)$/s', $fullOutput, $m)) {
            $exitCode = (int) $m[1];
            $output = preg_replace('/___EXIT_CODE___\d+$/s', '', $fullOutput);
        }

        unlink($wrapper);
        return ['output' => trim($output), 'exitCode' => $exitCode];
    }

    public function testHealthCheckCronForceFlag(): void
    {
        $result = $this->runCli('cli/health_check_cron.php', ['--force']);
        $this->assertStringContainsString('health check finished', $result['output']);
        $this->assertSame(0, $result['exitCode']);
    }

    public function testRunScheduledTaskWithoutIdShowsError(): void
    {
        $result = $this->runCli('cli/run_scheduled_task.php');
        $this->assertSame(1, $result['exitCode']);
        $this->assertStringContainsString('invalid task id', strtolower($result['output']));
    }

    public function testCheckExpiryOutputFormat(): void
    {
        $result = $this->runCli('cli/check_expiry.php');
        $this->assertSame(0, $result['exitCode']);
        $this->assertStringContainsString('expiry scan finished', $result['output']);
    }

    public function testDdnsSyncWithInvalidIdReturnsExitCode1(): void
    {
        $result = $this->runCli('cli/ddns_sync.php', ['nonexistent-id']);
        $this->assertSame(1, $result['exitCode']);
        $this->assertStringContainsString('任务不存在', $result['output']);
    }

    public function testManageUsersList(): void
    {
        auth_save_user('testuser', 'password123', 'admin');
        $result = $this->runCli('manage_users.php', ['list']);
        $this->assertSame(0, $result['exitCode']);
        $this->assertStringContainsString('testuser', $result['output']);
    }
}
