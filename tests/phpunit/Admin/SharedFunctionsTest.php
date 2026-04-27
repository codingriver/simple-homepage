<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class SharedFunctionsTest extends TestCase
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
        array_map('unlink', glob(BACKUPS_DIR . '/backup_*.json') ?: []);
    }

    public function testStatsReflectSitesAndUsers(): void
    {
        auth_save_user('u1', 'p1', 'admin');
        save_sites(['groups' => [
            ['sites' => [['name' => 'A']]],
            ['sites' => [['name' => 'B'], ['name' => 'C']]],
        ]]);
        $stats = get_stats();
        $this->assertSame(2, $stats['groups']);
        $this->assertSame(3, $stats['sites']);
        $expectedAdmins = auth_dev_mode_enabled() ? 2 : 1;
        $this->assertSame($expectedAdmins, $stats['admins']);
    }

    public function testBackupCreateAndRestoreRoundtrip(): void
    {
        save_sites(['groups' => [['name' => 'G1', 'sites' => []]]]);
        $path = backup_create('manual');
        $this->assertFileExists($path);

        $filename = basename($path);
        $result = backup_restore($filename);
        $this->assertTrue($result);
    }

    public function testBackupDeleteAndCleanup(): void
    {
        $path = backup_create('manual');
        $this->assertTrue(backup_delete(basename($path)));
        $this->assertFileDoesNotExist($path);
    }

    public function testDebugReadLogHandlesMissingAndEmpty(): void
    {
        $missing = debug_read_log('dns', 100);
        $this->assertStringContainsString('日志文件不存在', $missing);

        $path = DATA_DIR . '/logs/request_timing.log';
        file_put_contents($path, '');
        $empty = debug_read_log('request_timing', 100);
        $this->assertSame('（日志为空）', $empty);
        @unlink($path);
    }

    public function testWebhookHttpPostJsonRejectsInvalidUrl(): void
    {
        $result = webhook_http_post_json('not-a-url', '{}');
        $this->assertFalse($result['ok']);
    }

    public function testNginxGenerateProxyConfEmpty(): void
    {
        save_sites(['groups' => []]);
        $conf = nginx_generate_proxy_conf();
        $this->assertStringContainsString('暂无路径前缀代理站点配置', $conf['path_conf']);
    }

    public function testNginxGenerateProxyConfProducesLocationBlock(): void
    {
        save_sites(['groups' => [[
            'sites' => [[
                'type' => 'proxy',
                'proxy_mode' => 'path',
                'slug' => 'demo',
                'name' => 'Demo',
                'proxy_target' => 'http://127.0.0.1:8080',
            ]]
        ]]]);
        $conf = nginx_generate_proxy_conf();
        $this->assertStringContainsString('location /p/demo/', $conf['path_conf']);
    }

    public function testNavReadBuildInfoReturnsNullWhenAbsent(): void
    {
        $this->assertNull(nav_read_build_info());
    }

    public function testTrashMoveCreatesEntry(): void
    {
        $source = DATA_DIR . '/trash_test_source.txt';
        file_put_contents($source, 'hello');
        $result = trash_move('local', $source);
        @unlink($source);

        $this->assertTrue($result['ok'] ?? false, 'trash_move failed: ' . ($result['msg'] ?? ''));
        $this->assertNotEmpty($result['entry_id']);
        $entryDir = TRASH_DIR . '/' . $result['entry_id'];
        $this->assertDirectoryExists($entryDir);
        $this->assertFileExists($entryDir . '/meta.json');
        $this->assertFileExists($entryDir . '/data');
    }

    public function testTrashListReturnsMovedItems(): void
    {
        $source = DATA_DIR . '/trash_test_list.txt';
        file_put_contents($source, 'world');
        $result = trash_move('local', $source, 'admin');
        @unlink($source);

        $items = trash_list();
        $found = false;
        foreach ($items as $item) {
            if (($item['entry_id'] ?? '') === ($result['entry_id'] ?? '')) {
                $found = true;
                $this->assertSame('local', $item['host_id']);
                $this->assertSame($source, $item['original_path']);
                $this->assertSame('admin', $item['operator']);
                break;
            }
        }
        $this->assertTrue($found, 'Trashed item not found in list');
    }

    public function testTrashRestoreBringsFileBack(): void
    {
        $source = DATA_DIR . '/trash_test_restore.txt';
        file_put_contents($source, 'restore me');
        $move = trash_move('local', $source);
        $this->assertTrue($move['ok'] ?? false);

        $result = trash_restore($move['entry_id']);
        @unlink($source);

        $this->assertTrue($result['ok'] ?? false, 'Restore failed: ' . ($result['msg'] ?? ''));
        $this->assertStringContainsString($source, $result['msg'] ?? '');
        $this->assertFileExists($source);
    }

    public function testTrashPermanentDeleteRemovesEverything(): void
    {
        $source = DATA_DIR . '/trash_test_delete.txt';
        file_put_contents($source, 'delete me');
        $move = trash_move('local', $source);
        $this->assertTrue($move['ok'] ?? false);

        $entryId = $move['entry_id'];
        $result = trash_permanent_delete($entryId);
        @unlink($source);

        $this->assertTrue($result['ok'] ?? false, 'Permanent delete failed: ' . ($result['msg'] ?? ''));
        $this->assertDirectoryDoesNotExist(TRASH_DIR . '/' . $entryId);
    }

    public function testTrashAutoCleanRemovesOldEntries(): void
    {
        trash_ensure_dir();
        for ($i = 0; $i < 3; $i++) {
            $entryId = 'trash_old_' . $i;
            $entryDir = TRASH_DIR . '/' . $entryId;
            @mkdir($entryDir, 0750, true);
            $meta = [
                'entry_id' => $entryId,
                'host_id' => 'local',
                'original_path' => '/tmp/old_' . $i,
                'deleted_at' => date('Y-m-d H:i:s', time() - (TRASH_RETENTION_DAYS + 1) * 86400),
                'operator' => '',
            ];
            file_put_contents($entryDir . '/meta.json', json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }

        trash_auto_clean();

        for ($i = 0; $i < 3; $i++) {
            $entryId = 'trash_old_' . $i;
            $this->assertDirectoryDoesNotExist(TRASH_DIR . '/' . $entryId);
        }
    }

    public function testBackupCleanupDoesNotRemoveWhenUnderLimit(): void
    {
        // Create a few backups (under MAX_BACKUPS limit of 20)
        for ($i = 0; $i < 3; $i++) {
            backup_create('manual');
            sleep(1);
        }
        $initialCount = count(backup_list());
        $this->assertGreaterThanOrEqual(3, $initialCount);

        backup_cleanup();

        $afterCount = count(backup_list());
        $this->assertSame($initialCount, $afterCount, 'backup_cleanup should not delete when under limit');
    }

    public function testBackupCleanupRemovesOldBackups(): void
    {
        // Manually create backup files to simulate excess without waiting
        $files = [];
        for ($i = 0; $i < 25; $i++) {
            $file = BACKUPS_DIR . '/backup_' . date('Ymd_His', time() - $i) . '_manual.json';
            file_put_contents($file, json_encode(['test' => $i]));
            $files[] = $file;
        }

        $initialCount = count(backup_list());
        $this->assertGreaterThanOrEqual(25, $initialCount);

        backup_cleanup();

        $afterCount = count(backup_list());
        $this->assertLessThanOrEqual(20, $afterCount);

        // Cleanup remaining test files
        foreach (glob(BACKUPS_DIR . '/backup_*.json') ?: [] as $f) {
            @unlink($f);
        }
    }
}
