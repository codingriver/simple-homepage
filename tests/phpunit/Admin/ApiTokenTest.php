<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ApiTokenTest extends TestCase
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
        @unlink(API_TOKENS_FILE);
        array_map('unlink', glob(BACKUPS_DIR . '/backup_*.json') ?: []);
    }

    public function testApiTokenGenerateVerifyDelete(): void
    {
        $this->assertFalse(api_token_verify('np_invalid'));

        $token = api_token_generate('Test Token');
        $this->assertMatchesRegularExpression('/^np_[a-f0-9]{64}$/', $token);
        $this->assertTrue(api_token_verify($token));
        $this->assertSame('Test Token', api_token_get_name($token));

        $tokens = api_tokens_load();
        $this->assertArrayHasKey($token, $tokens);

        unset($tokens[$token]);
        api_tokens_save($tokens);
        $this->assertFalse(api_token_verify($token));
    }

    public function testApiTokenMask(): void
    {
        $this->assertSame('short', api_token_mask('short'));
        $masked = api_token_mask('np_1234567890abcdef');
        $this->assertStringContainsString('...', $masked);
    }
}
