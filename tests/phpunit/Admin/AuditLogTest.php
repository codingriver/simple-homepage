<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class AuditLogTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        auth_reset_config_cache();
        @unlink(USERS_FILE);
        @unlink(SITES_FILE);
        @unlink(CONFIG_FILE);
        @unlink(IP_LOCKS_FILE);
        @unlink(AUTH_LOG_FILE);
        @unlink(AUTH_SECRET_FILE);
        @unlink(AUDIT_LOG_FILE);
        @unlink(INSTALLED_FLAG);
        @unlink(DATA_DIR . '/.initial_admin.json');
        $GLOBALS['_SERVER']['REMOTE_ADDR'] = '127.0.0.1';
    }

    private function loginAs(string $username, string $role = 'admin'): void
    {
        auth_save_user($username, 'pass', $role);
        auth_ensure_secret_key();
        $token = auth_generate_token($username, $role);
        $_COOKIE[SESSION_COOKIE_NAME] = $token;
    }

    public function testAuditLogWritesJsonLine(): void
    {
        $this->loginAs('admin1');
        $GLOBALS['_SERVER']['REMOTE_ADDR'] = '192.168.1.2';
        audit_log('site_save', ['sid' => 's1', 'name' => 'Test']);

        $this->assertFileExists(AUDIT_LOG_FILE);
        $lines = array_filter(file(AUDIT_LOG_FILE, FILE_IGNORE_NEW_LINES));
        $this->assertCount(1, $lines);
        $data = json_decode($lines[0], true);
        $this->assertSame('site_save', $data['action']);
        $this->assertSame('admin1', $data['user']);
        $this->assertSame('192.168.1.2', $data['ip']);
        $this->assertSame('s1', $data['context']['sid']);
    }

    public function testAuditLogAppendsMultipleLines(): void
    {
        audit_log('group_delete', ['gid' => 'g1']);
        audit_log('user_save', ['username' => 'u1']);

        $lines = array_filter(file(AUDIT_LOG_FILE, FILE_IGNORE_NEW_LINES));
        $this->assertCount(2, $lines);
        $actions = array_map(fn($l) => json_decode($l, true)['action'] ?? '', $lines);
        $this->assertSame(['group_delete', 'user_save'], $actions);
    }

    public function testAuditLogReadThroughDebugReadLog(): void
    {
        audit_log('backup_create', ['trigger' => 'manual']);
        $content = debug_read_log('audit', 100);
        $this->assertStringContainsString('backup_create', $content);
        $this->assertStringContainsString('manual', $content);
    }
}
