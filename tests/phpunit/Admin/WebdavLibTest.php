<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../admin/shared/webdav_lib.php';

final class WebdavLibTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        @unlink(WEBDAV_ACCOUNTS_FILE);
    }

    protected function tearDown(): void
    {
        @unlink(WEBDAV_ACCOUNTS_FILE);
        parent::tearDown();
    }

    public function testAccountUsernameValid(): void
    {
        $this->assertTrue(webdav_account_username_valid('user_1'));
        $this->assertTrue(webdav_account_username_valid('User.Name-2'));
        $this->assertFalse(webdav_account_username_valid('ab'));
        $this->assertFalse(webdav_account_username_valid('user@example'));
        $this->assertFalse(webdav_account_username_valid(str_repeat('a', 33)));
    }

    public function testNormalizePath(): void
    {
        $this->assertSame('/', webdav_normalize_path(''));
        $this->assertSame('/', webdav_normalize_path('/'));
        $this->assertSame('/foo/bar', webdav_normalize_path('/foo//bar/'));
        $this->assertSame('/foo/bar', webdav_normalize_path('foo/bar'));
        $this->assertSame('/foo/bar', webdav_normalize_path('/foo/bar/'));
    }

    public function testAccountUsageBytesForNestedDirs(): void
    {
        $root = DATA_DIR . '/webdav_usage_test_' . uniqid();
        @mkdir($root . '/sub1/sub2', 0777, true);
        file_put_contents($root . '/file1.txt', 'hello');
        file_put_contents($root . '/sub1/file2.txt', 'world!');
        file_put_contents($root . '/sub1/sub2/file3.txt', '!!!');

        $account = ['root' => $root];
        $usage = webdav_account_usage_bytes($account);
        $expected = strlen('hello') + strlen('world!') + strlen('!!!');
        $this->assertSame($expected, $usage);

        webdav_delete_tree($root);
    }

    public function testLocalPathRelation(): void
    {
        $this->assertSame('exact', webdav_local_path_relation('/data', '/data'));
        $this->assertSame('inside', webdav_local_path_relation('/data/foo', '/data'));
        $this->assertSame('child', webdav_local_path_relation('/data', '/data/foo'));
        $this->assertSame('', webdav_local_path_relation('/other', '/data'));
        $this->assertSame('child', webdav_local_path_relation('/', '/data'));
        $this->assertSame('inside', webdav_local_path_relation('/foo', '/'));
        $this->assertSame('child', webdav_local_path_relation('/', '/foo'));
    }
}
