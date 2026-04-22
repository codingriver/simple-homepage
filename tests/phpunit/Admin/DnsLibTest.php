<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../admin/shared/dns_lib.php';

final class DnsLibTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        @unlink(DNS_CONFIG_FILE);
    }

    protected function tearDown(): void
    {
        @unlink(DNS_CONFIG_FILE);
        parent::tearDown();
    }

    public function testNormalizeConfigFillsDefaultsAndFiltersInvalid(): void
    {
        $raw = [
            'version' => 2,
            'accounts' => [
                ['provider' => 'aliyun', 'name' => 'Ali'],
                ['provider' => 'invalid_provider', 'name' => 'Bad'],
            ],
            'ui' => ['selected_account_id' => 'acc1'],
        ];
        $cfg = dns_normalize_config($raw);
        $this->assertSame(2, $cfg['version']);
        $this->assertCount(1, $cfg['accounts']);
        $this->assertSame('aliyun', $cfg['accounts'][0]['provider']);
        $this->assertNotEmpty($cfg['accounts'][0]['id']);
        $this->assertSame('', $cfg['ui']['selected_zone_id']);
    }

    public function testMigrateLegacyConfig(): void
    {
        $legacy = [
            'access_key_id' => 'AK123',
            'access_key_secret' => 'SK456',
            'domain_name' => 'example.com',
            'last_sync_at' => '2024-01-01 00:00:00',
        ];
        $cfg = dns_migrate_legacy_config($legacy);
        $this->assertSame(2, $cfg['version']);
        $this->assertCount(1, $cfg['accounts']);
        $this->assertSame('dns_legacy_aliyun', $cfg['accounts'][0]['id']);
        $this->assertSame('Aliyun DNS（迁移）', $cfg['accounts'][0]['name']);
        $this->assertSame('example.com', $cfg['ui']['selected_zone_name']);
    }

    public function testMigrateLegacyConfigEmptyReturnsDefaults(): void
    {
        $cfg = dns_migrate_legacy_config([]);
        $this->assertSame(dns_config_defaults(), $cfg);
    }

    public function testMaskSecret(): void
    {
        $this->assertSame('', dns_mask_secret(''));
        $this->assertSame('****', dns_mask_secret('abcd'));
        $this->assertSame('********', dns_mask_secret('abcdefgh'));
        $this->assertSame('abcd****ijkl', dns_mask_secret('abcdefghijkl'));
    }

    public function testMakeAccountId(): void
    {
        $id1 = dns_make_account_id();
        $id2 = dns_make_account_id();
        $this->assertNotSame($id1, $id2);
        $this->assertStringStartsWith('dns_', $id1);
        $this->assertMatchesRegularExpression('/^dns_[a-f0-9]{16}$/', $id1);
    }
}
