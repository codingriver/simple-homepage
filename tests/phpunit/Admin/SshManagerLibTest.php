<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../admin/shared/ssh_manager_lib.php';

final class SshManagerLibTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        @unlink(SSH_HOSTS_FILE);
        @unlink(SSH_KEYS_FILE);
        @unlink(SSH_AUDIT_LOG);
    }

    protected function tearDown(): void
    {
        @unlink(SSH_HOSTS_FILE);
        @unlink(SSH_KEYS_FILE);
        @unlink(SSH_AUDIT_LOG);
        parent::tearDown();
    }

    public function testHostRuntimeSpecFormatting(): void
    {
        $host = [
            'name' => 'Server1',
            'hostname' => '192.168.1.1',
            'port' => 2222,
            'username' => 'admin',
            'auth_type' => 'password',
            'password_enc' => ssh_manager_encrypt_secret('secret123'),
        ];
        $spec = ssh_manager_host_runtime_spec($host);
        $this->assertSame('remote', $spec['type']);
        $this->assertSame('Server1', $spec['name']);
        $this->assertSame('192.168.1.1', $spec['hostname']);
        $this->assertSame(2222, $spec['port']);
        $this->assertSame('admin', $spec['username']);
        $this->assertSame('password', $spec['auth_type']);
        $this->assertSame('secret123', $spec['password']);
    }

    public function testHostRuntimeSpecKeyAuthMissingKeyReturnsEmpty(): void
    {
        $host = [
            'name' => 'Server2',
            'hostname' => '10.0.0.1',
            'port' => 22,
            'username' => 'root',
            'auth_type' => 'key',
            'key_id' => 'nonexistent',
        ];
        $spec = ssh_manager_host_runtime_spec($host);
        $this->assertSame([], $spec);
    }

    public function testUpsertHostPortValidation(): void
    {
        $result = ssh_manager_upsert_host([
            'name' => 'Test',
            'hostname' => '1.2.3.4',
            'username' => 'root',
            'auth_type' => 'password',
            'password' => 'pass',
            'port' => 99999,
        ]);
        $this->assertTrue($result['ok']);
        $this->assertSame(65535, $result['host']['port']);

        $result2 = ssh_manager_upsert_host([
            'name' => 'Test2',
            'hostname' => '1.2.3.5',
            'username' => 'root',
            'auth_type' => 'password',
            'password' => 'pass',
            'port' => 0,
        ]);
        $this->assertTrue($result2['ok']);
        $this->assertSame(1, $result2['host']['port']);
    }

    public function testUpsertHostUsernameValidation(): void
    {
        $result = ssh_manager_upsert_host([
            'name' => 'Test',
            'hostname' => '1.2.3.4',
            'username' => '',
            'auth_type' => 'password',
            'password' => 'pass',
        ]);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('用户名', $result['msg']);
    }

    public function testPublicKeyFromPrivate(): void
    {
        if (!shell_exec('which ssh-keygen 2>/dev/null')) {
            $this->markTestSkipped('ssh-keygen not available');
        }
        $tmpKey = sys_get_temp_dir() . '/test_key_' . uniqid();
        @exec('ssh-keygen -t ed25519 -f ' . escapeshellarg($tmpKey) . ' -N "" -C "test" 2>/dev/null');
        if (!is_file($tmpKey)) {
            $this->markTestSkipped('Failed to generate test key');
        }
        $privateKey = file_get_contents($tmpKey);
        $result = ssh_manager_public_key_from_private($privateKey);
        @unlink($tmpKey);
        @unlink($tmpKey . '.pub');
        $this->assertTrue($result['ok']);
        $this->assertNotEmpty($result['public_key']);
        $this->assertStringStartsWith('ssh-', $result['public_key']);
    }
}
