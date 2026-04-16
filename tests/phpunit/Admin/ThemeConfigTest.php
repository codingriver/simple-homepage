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
        $this->assertSame('dark', $cfg['theme']);
        $this->assertSame('', $cfg['custom_css']);
    }

    public function testSaveAndLoadThemeAndCustomCss(): void
    {
        $cfg = load_config();
        $cfg['theme'] = 'light';
        $cfg['custom_css'] = 'body { background: red; }';
        save_config($cfg);

        auth_reset_config_cache();
        $loaded = load_config();
        $this->assertSame('light', $loaded['theme']);
        $this->assertSame('body { background: red; }', $loaded['custom_css']);
    }
}
