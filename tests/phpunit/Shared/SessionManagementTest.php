<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class SessionManagementTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        auth_reset_config_cache();
        @unlink(USERS_FILE);
        @unlink(CONFIG_FILE);
        @unlink(AUTH_SECRET_FILE);
        @unlink(IP_LOCKS_FILE);
        @unlink(AUTH_LOG_FILE);
        @unlink(SESSIONS_FILE);
        @unlink(INSTALLED_FLAG);
        $GLOBALS['_SERVER']['REMOTE_ADDR'] = '127.0.0.1';
    }

    public function testTokenGenerationRegistersSession(): void
    {
        auth_ensure_secret_key();
        $token = auth_generate_token('admin1', 'admin');
        $payload = auth_verify_token($token);
        $this->assertIsArray($payload);

        $list = auth_session_list('admin1');
        $this->assertCount(1, $list);
        $this->assertSame('admin1', $list[0]['username']);
    }

    public function testSessionRevokeInvalidatesToken(): void
    {
        auth_ensure_secret_key();
        $token = auth_generate_token('admin1', 'admin');
        $payload = auth_verify_token($token);
        $this->assertIsArray($payload);

        $jti = $payload['jti'];
        $this->assertTrue(auth_session_revoke($jti));
        $this->assertFalse(auth_session_exists($jti));
        $this->assertFalse(auth_verify_token($token));
    }

    public function testSessionListFiltersByUsername(): void
    {
        auth_ensure_secret_key();
        auth_generate_token('u1', 'user');
        auth_generate_token('u2', 'admin');

        $this->assertCount(1, auth_session_list('u1'));
        $this->assertCount(2, auth_session_list());
    }
}
