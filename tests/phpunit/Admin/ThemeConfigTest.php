<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ThemeConfigTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        auth_reset_config_cache();
        @unlink(CONFIG_FILE);
    }

    public function testDefaultConfigIncludesThemeAndCustomCss(): void
    {
        $cfg = auth_get_config();
        $this->assertArrayHasKey('theme', $cfg);
        $this->assertArrayHasKey('custom_css', $cfg);
        $this->assertArrayHasKey('nginx_access_log_enabled', $cfg);
        $this->assertSame('dark', $cfg['theme']);
        $this->assertSame('', $cfg['custom_css']);
        $this->assertSame('0', $cfg['nginx_access_log_enabled']);
    }

    public function testSaveAndLoadThemeAndCustomCss(): void
    {
        $cfg = load_config();
        $cfg['theme'] = 'light';
        $cfg['custom_css'] = 'body { background: red; }';
        $cfg['nginx_access_log_enabled'] = '1';
        save_config($cfg);

        auth_reset_config_cache();
        $loaded = load_config();
        $this->assertSame('light', $loaded['theme']);
        $this->assertSame('body { background: red; }', $loaded['custom_css']);
        $this->assertSame('1', $loaded['nginx_access_log_enabled']);
    }

    public function testRetiredConfigIsRemovedOnLoadAndSave(): void
    {
        file_put_contents(CONFIG_FILE, json_encode([
            'site_name' => 'RiverOps',
            'webdav_enabled' => '1',
            'card_layout' => 'grid',
            'proxy_params_mode' => 'full',
            'nginx_last_applied' => 123,
            'nginx_last_applied_proxy_state' => ['sites' => ['legacy' => []]],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $loaded = load_config();
        $this->assertArrayNotHasKey('proxy_params_mode', $loaded);
        $this->assertArrayNotHasKey('nginx_last_applied', $loaded);
        $this->assertArrayNotHasKey('nginx_last_applied_proxy_state', $loaded);
        $this->assertArrayNotHasKey('webdav_enabled', $loaded);
        $this->assertArrayNotHasKey('card_layout', $loaded);

        save_config($loaded);
        $saved = json_decode((string) file_get_contents(CONFIG_FILE), true);
        $this->assertArrayNotHasKey('proxy_params_mode', $saved);
        $this->assertArrayNotHasKey('nginx_last_applied', $saved);
        $this->assertArrayNotHasKey('nginx_last_applied_proxy_state', $saved);
        $this->assertArrayNotHasKey('webdav_enabled', $saved);
        $this->assertArrayNotHasKey('card_layout', $saved);
    }
}
