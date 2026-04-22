<?php
declare(strict_types=1);

require_once __DIR__ . '/../../phpunit/bootstrap.php';

class ShareServiceLibTest extends PHPUnit\Framework\TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanDataDir();
        @mkdir(DATA_DIR . '/logs', 0777, true);
        // auth_get_current_user() is required by share_service_lib.php
        require_once __DIR__ . '/../../../shared/auth.php';
        require_once __DIR__ . '/../../../admin/shared/share_service_lib.php';
    }

    protected function tearDown(): void
    {
        $this->cleanDataDir();
        parent::tearDown();
    }

    private function cleanDataDir(): void
    {
        array_map('unlink', glob(DATA_DIR . '/*.json') ?: []);
        array_map('unlink', glob(DATA_DIR . '/logs/*') ?: []);
        array_map('unlink', glob(DATA_DIR . '/share_service_history/*') ?: []);
        @rmdir(DATA_DIR . '/share_service_history');
    }

    public function testSupportedMapContainsExpectedServices(): void
    {
        $map = share_service_supported_map();
        $this->assertArrayHasKey('sftp', $map);
        $this->assertArrayHasKey('smb', $map);
        $this->assertArrayHasKey('ftp', $map);
        $this->assertArrayHasKey('nfs', $map);
    }

    public function testLabelReturnsKnownLabel(): void
    {
        $this->assertSame('SFTP', share_service_label('sftp'));
        $this->assertSame('SMB', share_service_label('smb'));
    }

    public function testLabelReturnsUppercaseForUnknown(): void
    {
        $this->assertSame('UNKNOWN', share_service_label('unknown'));
    }

    public function testAuditCreatesLogEntry(): void
    {
        share_service_audit('test_action', ['service' => 'sftp']);
        $this->assertFileExists(DATA_DIR . '/logs/share_service_audit.log');
        $content = file_get_contents(DATA_DIR . '/logs/share_service_audit.log');
        $this->assertStringContainsString('test_action', $content);
        $this->assertStringContainsString('sftp', $content);
    }

    public function testAuditTailReturnsEntries(): void
    {
        share_service_audit('action1', ['service' => 'sftp']);
        share_service_audit('action2', ['service' => 'smb']);
        $entries = share_service_audit_tail(10);
        $this->assertCount(2, $entries);
        $this->assertSame('action2', $entries[0]['action']);
    }

    public function testAuditTailReturnsEmptyWhenNoLog(): void
    {
        $this->assertSame([], share_service_audit_tail(10));
    }

    public function testAuditQueryFiltersByAction(): void
    {
        share_service_audit('action1', ['service' => 'sftp']);
        share_service_audit('action2', ['service' => 'smb']);
        $result = share_service_audit_query(['action' => 'action1']);
        $this->assertCount(1, $result['items']);
        $this->assertSame('action1', $result['items'][0]['action']);
    }

    public function testAuditQueryFiltersByService(): void
    {
        share_service_audit('action1', ['service' => 'sftp']);
        share_service_audit('action2', ['service' => 'smb']);
        $result = share_service_audit_query(['service' => 'smb']);
        $this->assertCount(1, $result['items']);
        $this->assertSame('action2', $result['items'][0]['action']);
    }

    public function testAuditQueryFiltersByKeyword(): void
    {
        share_service_audit('test_action', ['service' => 'sftp']);
        share_service_audit('other_thing', ['service' => 'smb']);
        $result = share_service_audit_query(['keyword' => 'test']);
        $this->assertCount(1, $result['items']);
        $this->assertSame('test_action', $result['items'][0]['action']);
    }

    public function testAuditQueryPaginates(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            share_service_audit('action' . $i, ['service' => 'sftp']);
        }
        $result = share_service_audit_query(['limit' => 2, 'page' => 1]);
        $this->assertCount(2, $result['items']);
        $this->assertSame(5, $result['total']);
        $this->assertTrue($result['has_next']);
    }

    public function testHistoryWriteCreatesFile(): void
    {
        $result = share_service_history_write('sftp', 'update', ['config' => 'test']);
        $this->assertArrayHasKey('id', $result);
        $this->assertStringStartsWith('sshare_', $result['id']);
        $this->assertFileExists(DATA_DIR . '/share_service_history/' . $result['id'] . '.json');
    }

    public function testHistoryListReturnsItems(): void
    {
        share_service_history_write('sftp', 'update', ['config' => 'a']);
        share_service_history_write('smb', 'update', ['config' => 'b']);
        $items = share_service_history_list();
        $this->assertCount(2, $items);
    }

    public function testHistoryListFiltersByService(): void
    {
        share_service_history_write('sftp', 'update', ['config' => 'a']);
        share_service_history_write('smb', 'update', ['config' => 'b']);
        $items = share_service_history_list(['service' => 'sftp']);
        $this->assertCount(1, $items);
        $this->assertSame('sftp', $items[0]['service']);
    }

    public function testHistoryFindReturnsNullForMissing(): void
    {
        $this->assertNull(share_service_history_find('nonexistent'));
    }

    public function testHistoryFindReturnsEntry(): void
    {
        $written = share_service_history_write('sftp', 'update', ['config' => 'test']);
        $found = share_service_history_find($written['id']);
        $this->assertIsArray($found);
        $this->assertSame($written['id'], $found['id']);
    }

    public function testAuditExportJsonReturnsString(): void
    {
        share_service_audit('action1', ['service' => 'sftp']);
        $json = share_service_audit_export_json([]);
        $data = json_decode($json, true);
        $this->assertIsArray($data);
        $this->assertCount(1, $data);
    }
}
