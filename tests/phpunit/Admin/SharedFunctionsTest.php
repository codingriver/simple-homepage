<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class SharedFunctionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        auth_reset_config_cache();
        @unlink(SITES_FILE);
        @unlink(CONFIG_FILE);
        @unlink(USERS_FILE);
        @unlink(IP_LOCKS_FILE);
        @unlink(AUTH_LOG_FILE);
        @unlink(AUTH_SECRET_FILE);
        @unlink(INSTALLED_FLAG);
        @unlink(AUTH_DEV_MODE_FLAG_FILE);
        @unlink(DATA_DIR . '/.initial_admin.json');
        array_map('unlink', glob(BACKUPS_DIR . '/backup_*.json') ?: []);
    }

    public function testStatsReflectSitesAndUsers(): void
    {
        auth_save_user('u1', 'p1', 'admin');
        save_sites(['groups' => [
            ['sites' => [['name' => 'A']]],
            ['sites' => [['name' => 'B'], ['name' => 'C']]],
        ]]);
        $stats = get_stats();
        $this->assertSame(2, $stats['groups']);
        $this->assertSame(3, $stats['sites']);
        $expectedAdmins = auth_dev_mode_enabled() ? 2 : 1;
        $this->assertSame($expectedAdmins, $stats['admins']);
    }

    public function testBackupCreateAndRestoreRoundtrip(): void
    {
        save_sites(['groups' => [['name' => 'G1', 'sites' => []]]]);
        $path = backup_create('manual');
        $this->assertFileExists($path);

        $filename = basename($path);
        $result = backup_restore($filename);
        $this->assertTrue($result);
    }

    public function testBackupDeleteAndCleanup(): void
    {
        $path = backup_create('manual');
        $this->assertTrue(backup_delete(basename($path)));
        $this->assertFileDoesNotExist($path);
    }

    public function testDebugReadLogHandlesMissingAndEmpty(): void
    {
        $missing = debug_read_log('dns', 100);
        $this->assertStringContainsString('日志文件不存在', $missing);

        $path = DATA_DIR . '/logs/request_timing.log';
        file_put_contents($path, '');
        $empty = debug_read_log('request_timing', 100);
        $this->assertSame('（日志为空）', $empty);
        @unlink($path);
    }

    public function testWebhookHttpPostJsonRejectsInvalidUrl(): void
    {
        $result = webhook_http_post_json('not-a-url', '{}');
        $this->assertFalse($result['ok']);
    }

    public function testNginxGenerateProxyConfEmpty(): void
    {
        save_sites(['groups' => []]);
        $conf = nginx_generate_proxy_conf();
        $this->assertStringContainsString('暂无路径前缀代理站点配置', $conf['path_conf']);
    }

    public function testNginxGenerateProxyConfProducesLocationBlock(): void
    {
        save_sites(['groups' => [[
            'sites' => [[
                'type' => 'proxy',
                'proxy_mode' => 'path',
                'slug' => 'demo',
                'name' => 'Demo',
                'proxy_target' => 'http://127.0.0.1:8080',
            ]]
        ]]]);
        $conf = nginx_generate_proxy_conf();
        $this->assertStringContainsString('location /p/demo/', $conf['path_conf']);
    }

    public function testNavReadBuildInfoReturnsNullWhenAbsent(): void
    {
        $this->assertNull(nav_read_build_info());
    }
}
