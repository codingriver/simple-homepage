<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../admin/shared/file_manager_lib.php';

final class FileManagerLibTest extends TestCase
{
    private string $tmpJson = '';

    protected function setUp(): void
    {
        parent::setUp();
        @unlink(FILE_MANAGER_FAVORITES_FILE);
        @unlink(FILE_MANAGER_RECENT_FILE);
        $this->tmpJson = DATA_DIR . '/test_fm_' . uniqid() . '.json';
        @unlink($this->tmpJson);
    }

    protected function tearDown(): void
    {
        @unlink(FILE_MANAGER_FAVORITES_FILE);
        @unlink(FILE_MANAGER_RECENT_FILE);
        @unlink($this->tmpJson);
        parent::tearDown();
    }

    public function testReadJsonFileMissingReturnsDefault(): void
    {
        $default = ['version' => 1, 'items' => []];
        $result = file_manager_read_json($this->tmpJson, $default);
        $this->assertSame($default, $result);
    }

    public function testReadJsonValidJsonParsed(): void
    {
        file_put_contents($this->tmpJson, json_encode(['foo' => 'bar']));
        $result = file_manager_read_json($this->tmpJson, ['version' => 1]);
        $this->assertSame(['foo' => 'bar'], $result);
    }

    public function testReadJsonInvalidJsonReturnsDefault(): void
    {
        file_put_contents($this->tmpJson, 'not-json');
        $default = ['version' => 1];
        $result = file_manager_read_json($this->tmpJson, $default);
        $this->assertSame($default, $result);
    }

    public function testWriteJsonCreatesFileWithLockEx(): void
    {
        file_manager_write_json($this->tmpJson, ['key' => 'value']);
        $this->assertFileExists($this->tmpJson);
        $data = json_decode(file_get_contents($this->tmpJson), true);
        $this->assertSame(['key' => 'value'], $data);
    }

    public function testFavoritesCrudFlow(): void
    {
        $user = 'admin';

        $save1 = file_manager_save_favorite($user, ['path' => '/etc/nginx', 'name' => 'Nginx']);
        $this->assertTrue($save1['ok']);
        $this->assertSame('admin', $save1['item']['user']);
        $this->assertSame('/etc/nginx', $save1['item']['path']);

        $save2 = file_manager_save_favorite($user, ['path' => '/etc/nginx', 'name' => 'Nginx Updated']);
        $this->assertTrue($save2['ok']);
        $this->assertSame('Nginx Updated', $save2['item']['name']);
        $this->assertSame($save1['item']['id'], $save2['item']['id']);

        $list = file_manager_favorites_list($user);
        $this->assertCount(1, $list);
        $this->assertSame('Nginx Updated', $list[0]['name']);

        $deleted = file_manager_delete_favorite($user, $save1['item']['id']);
        $this->assertTrue($deleted);
        $this->assertCount(0, file_manager_favorites_list($user));

        $this->assertFalse(file_manager_delete_favorite($user, 'nonexistent'));
    }

    public function testRecentDedupAndLimit(): void
    {
        $user = 'admin';
        file_manager_touch_recent($user, 'local', '/path/a');
        file_manager_touch_recent($user, 'local', '/path/b');
        file_manager_touch_recent($user, 'local', '/path/a');
        file_manager_touch_recent($user, 'local', '/path/c');

        $list = file_manager_recent_list($user, 2);
        $this->assertCount(2, $list);
        $this->assertSame('/path/c', $list[0]['path']);
        $this->assertSame('/path/a', $list[1]['path']);
    }

    public function testRecentEmptyReturnsEmpty(): void
    {
        $this->assertSame([], file_manager_recent_list('nobody', 10));
    }
}
