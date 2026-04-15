<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class AuthTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        auth_reset_config_cache();
        // Ensure clean state
        @unlink(USERS_FILE);
        @unlink(CONFIG_FILE);
        @unlink(AUTH_SECRET_FILE);
        @unlink(IP_LOCKS_FILE);
        @unlink(AUTH_LOG_FILE);
        @unlink(AUTH_DEV_MODE_FLAG_FILE);
        @unlink(INSTALLED_FLAG);
    }

    public function testTokenRoundtrip(): void
    {
        auth_ensure_secret_key();
        $token = auth_generate_token('admin', 'admin');
        $payload = auth_verify_token($token);
        $this->assertIsArray($payload);
        $this->assertSame('admin', $payload['username']);
        $this->assertSame('admin', $payload['role']);
    }

    public function testTokenTamperedFails(): void
    {
        auth_ensure_secret_key();
        $token = auth_generate_token('admin', 'admin');
        $tampered = substr($token, 0, -5) . 'xxxxx';
        $this->assertFalse(auth_verify_token($tampered));
    }

    public function testTokenExpiredFails(): void
    {
        auth_ensure_secret_key();
        $token = auth_generate_token('admin', 'admin');
        // Manually backdate payload
        $parts = explode('.', $token);
        $data = json_decode(base64_decode($parts[0]), true);
        $data['exp'] = time() - 10;
        $newData = base64_encode(json_encode($data));
        $newToken = $newData . '.' . hash_hmac('sha256', $newData, auth_secret_key());
        $this->assertFalse(auth_verify_token($newToken));
    }

    public function testPasswordHashVerification(): void
    {
        auth_save_user('alice', 'Secret123', 'user');
        $this->assertIsArray(auth_check_password('alice', 'Secret123'));
        $this->assertFalse(auth_check_password('alice', 'wrong'));
    }

    public function testUserLifecycle(): void
    {
        auth_save_user('bob', 'Pass1234', 'admin');
        $users = auth_load_users();
        $this->assertArrayHasKey('bob', $users);
        $this->assertSame('admin', $users['bob']['role']);

        $users = auth_load_users();
        unset($users['bob']);
        auth_write_users($users);
        $this->assertArrayNotHasKey('bob', auth_load_users());
    }

    public function testDevModeVirtualUser(): void
    {
        @unlink(USERS_FILE);
        putenv('NAV_DEV_MODE=1');
        $users = auth_load_users();
        $this->assertArrayHasKey('qatest', $users);
        putenv('NAV_DEV_MODE=');
        @unlink(AUTH_DEV_MODE_FLAG_FILE);
    }

    public function testSanitizeRedirectRelative(): void
    {
        $this->assertSame('/admin/', auth_sanitize_redirect('/admin/'));
        $this->assertSame('', auth_sanitize_redirect('//evil.com'));
        $this->assertSame('/', auth_sanitize_redirect('https://evil.com'));
    }

    public function testGetClientIp(): void
    {
        $_SERVER['HTTP_X_REAL_IP'] = '1.2.3.4';
        $this->assertSame('1.2.3.4', get_client_ip());
        unset($_SERVER['HTTP_X_REAL_IP']);

        $_SERVER['HTTP_X_FORWARDED_FOR'] = '5.6.7.8, 192.168.1.1';
        $this->assertSame('5.6.7.8', get_client_ip());
        unset($_SERVER['HTTP_X_FORWARDED_FOR']);
    }

    public function testIpLockAtomicityAndExpiration(): void
    {
        $ip = '192.168.99.99';
        $this->assertFalse(ip_is_locked($ip));

        ip_record_fail($ip);
        ip_record_fail($ip);
        ip_record_fail($ip);
        ip_record_fail($ip);
        ip_record_fail($ip);
        $this->assertTrue(ip_is_locked($ip));

        ip_reset_fails($ip);
        $this->assertFalse(ip_is_locked($ip));
    }

    public function testBootstrapInitialAdmin(): void
    {
        @unlink(INSTALLED_FLAG);
        @unlink(USERS_FILE);
        $json = json_encode(['ADMIN' => 'root', 'PASSWORD' => 'toor', 'NAME' => 'Test', 'DOMAIN' => 'example.com']);
        file_put_contents(DATA_DIR . '/.initial_admin.json', $json);
        auth_bootstrap_initial_admin_if_needed();
        $this->assertTrue(file_exists(INSTALLED_FLAG));
        $this->assertIsArray(auth_check_password('root', 'toor'));
        @unlink(DATA_DIR . '/.initial_admin.json');
    }
}
