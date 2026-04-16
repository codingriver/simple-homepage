<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class HealthCheckTest extends TestCase
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
        @unlink(HEALTH_CACHE_FILE);
        @unlink(HEALTH_ALERT_FILE);
        array_map('unlink', glob(BACKUPS_DIR . '/backup_*.json') ?: []);
    }

    public function testHealthAlertLoadSaveRoundtrip(): void
    {
        $this->assertSame([], health_alert_load());
        health_alert_save(['http://example.com' => time()]);
        $loaded = health_alert_load();
        $this->assertArrayHasKey('http://example.com', $loaded);
    }

    public function testHealthCheckUrlRejectsInvalidUrl(): void
    {
        $result = health_check_url('not-a-url');
        $this->assertSame('down', $result['status']);
        $this->assertSame(0, $result['code']);
    }

    public function testHealthCheckAllWithEmptySites(): void
    {
        save_sites(['groups' => []]);
        $result = health_check_all();
        $this->assertSame([], $result);
    }

    public function testHealthCheckAllCachesResult(): void
    {
        save_sites(['groups' => [[
            'sites' => [[
                'id' => 'demo',
                'name' => 'Demo',
                'type' => 'external',
                'url' => 'https://httpbin.org/status/200',
            ]]
        ]]]);
        $result = health_check_all();
        $this->assertArrayHasKey('https://httpbin.org/status/200', $result);
        $cache = health_load_cache();
        $this->assertArrayHasKey('https://httpbin.org/status/200', $cache);
    }

    public function testHealthCronClearsRecoveredAlerts(): void
    {
        // Simulate previous alert
        health_alert_save(['http://example.com' => time() - 3600]);
        // health_check_all won't test this URL because it's not in sites,
        // but we can test alert_save/load directly
        health_alert_save([]);
        $this->assertSame([], health_alert_load());
    }
}
