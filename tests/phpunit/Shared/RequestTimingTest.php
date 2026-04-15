<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class RequestTimingTest extends TestCase
{
    /**
     * @runInSeparateProcess
     */
    public function testDisabledByDefaultInCli(): void
    {
        @unlink(DATA_DIR . '/logs/request_timing.log');
        putenv('NAV_REQUEST_TIMING');
        putenv('NAV_REQUEST_TIMING_CLI');

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/test';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['SCRIPT_NAME'] = '/test.php';
        require __DIR__ . '/../../../shared/request_timing.php';

        $this->assertFalse(file_exists(DATA_DIR . '/logs/request_timing.log'));
    }

    /**
     * @runInSeparateProcess
     */
    public function testWritesRecvAndDoneWhenEnabled(): void
    {
        @unlink(DATA_DIR . '/logs/request_timing.log');
        putenv('NAV_REQUEST_TIMING_CLI=1');

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/timing-test';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['SCRIPT_NAME'] = '/test.php';
        require __DIR__ . '/../../../shared/request_timing.php';

        $log = DATA_DIR . '/logs/request_timing.log';
        $this->assertFileExists($log);
        $content = file_get_contents($log);
        $this->assertStringContainsString('recv', $content);
        $this->assertStringContainsString('/timing-test', $content);
    }

    /**
     * @runInSeparateProcess
     */
    public function testDisabledByNavRequestTimingZero(): void
    {
        @unlink(DATA_DIR . '/logs/request_timing.log');
        putenv('NAV_REQUEST_TIMING=0');
        putenv('NAV_REQUEST_TIMING_CLI=1');

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/disabled';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['SCRIPT_NAME'] = '/test.php';
        require __DIR__ . '/../../../shared/request_timing.php';

        $this->assertFalse(file_exists(DATA_DIR . '/logs/request_timing.log'));
    }
}
