<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../admin/shared/backup_webdav_lib.php';

final class BackupWebdavLibTest extends TestCase
{
    private static $serverProcess = null;
    private static string $serverUrl = '';
    private static string $routerFile = '';
    private static string $serverStorage = '';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$serverStorage = sys_get_temp_dir() . '/riverops-webdav-server-' . bin2hex(random_bytes(4));
        mkdir(self::$serverStorage, 0777, true);
        self::$routerFile = tempnam(sys_get_temp_dir(), 'riverops_webdav_router_');
        $storage = var_export(self::$serverStorage, true);
        file_put_contents(self::$routerFile, <<<'PHP'
<?php
$root = __STORAGE__;
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$urlPath = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
$segments = array_values(array_filter(explode('/', $urlPath), static fn($v) => $v !== '' && $v !== '.' && $v !== '..'));
$path = $root . ($segments ? '/' . implode('/', $segments) : '');

if ($method === 'MKCOL') {
    if (is_dir($path)) { http_response_code(405); return; }
    if (!is_dir(dirname($path))) { http_response_code(409); return; }
    mkdir($path, 0777, true); http_response_code(201); return;
}
if ($method === 'PUT') {
    if (!is_dir(dirname($path))) { http_response_code(409); return; }
    file_put_contents($path, file_get_contents('php://input')); http_response_code(201); return;
}
if ($method === 'MOVE') {
    $destination = $_SERVER['HTTP_DESTINATION'] ?? '';
    $destinationPath = rawurldecode(parse_url($destination, PHP_URL_PATH) ?: '');
    $destinationSegments = array_values(array_filter(explode('/', $destinationPath), static fn($v) => $v !== '' && $v !== '.' && $v !== '..'));
    $target = $root . ($destinationSegments ? '/' . implode('/', $destinationSegments) : '');
    if (!is_file($path)) { http_response_code(404); return; }
    if (is_file($target)) unlink($target);
    rename($path, $target); http_response_code(201); return;
}
if ($method === 'DELETE') {
    if (is_file($path)) { unlink($path); http_response_code(204); return; }
    if (is_dir($path)) { rmdir($path); http_response_code(204); return; }
    http_response_code(404); return;
}
if ($method === 'HEAD' || $method === 'GET') {
    if (!is_file($path)) { http_response_code(404); return; }
    header('Content-Length: ' . filesize($path));
    header('ETag: "' . md5_file($path) . '"');
    if ($method === 'GET') readfile($path);
    return;
}
if ($method === 'PROPFIND') {
    if (!is_dir($path)) { http_response_code(404); return; }
    http_response_code(207);
    header('Content-Type: application/xml; charset=utf-8');
    $hrefBase = rtrim($urlPath, '/');
    echo '<?xml version="1.0" encoding="utf-8"?><d:multistatus xmlns:d="DAV:">';
    echo '<d:response><d:href>' . htmlspecialchars($hrefBase . '/', ENT_XML1) . '</d:href><d:propstat><d:prop><d:resourcetype><d:collection/></d:resourcetype></d:prop></d:propstat></d:response>';
    foreach (scandir($path) ?: [] as $name) {
        if ($name === '.' || $name === '..' || !is_file($path . '/' . $name)) continue;
        $file = $path . '/' . $name;
        echo '<d:response><d:href>' . htmlspecialchars($hrefBase . '/' . rawurlencode($name), ENT_XML1) . '</d:href><d:propstat><d:prop>';
        echo '<d:getcontentlength>' . filesize($file) . '</d:getcontentlength>';
        echo '<d:getlastmodified>' . gmdate(DATE_RFC7231, filemtime($file)) . '</d:getlastmodified>';
        echo '<d:getetag>"' . md5_file($file) . '"</d:getetag><d:resourcetype/>';
        echo '</d:prop></d:propstat></d:response>';
    }
    echo '</d:multistatus>';
    return;
}
http_response_code(405);
PHP
        );
        $router = str_replace('__STORAGE__', $storage, file_get_contents(self::$routerFile));
        file_put_contents(self::$routerFile, $router);

        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if (!is_resource($server)) {
            throw new RuntimeException('无法预留 WebDAV 测试端口：' . $errstr);
        }
        $name = stream_socket_get_name($server, false);
        fclose($server);
        if (!preg_match('/:(\d+)$/', (string)$name, $matches)) {
            throw new RuntimeException('无法获取 WebDAV 测试端口');
        }
        $port = (int)$matches[1];
        self::$serverUrl = 'http://127.0.0.1:' . $port;
        $command = escapeshellarg(PHP_BINARY) . ' -S 127.0.0.1:' . $port . ' ' . escapeshellarg(self::$routerFile);
        self::$serverProcess = proc_open($command, [
            0 => ['pipe', 'r'],
            1 => ['file', sys_get_temp_dir() . '/riverops-webdav-test.out.log', 'a'],
            2 => ['file', sys_get_temp_dir() . '/riverops-webdav-test.err.log', 'a'],
        ], $pipes);
        if (!is_resource(self::$serverProcess)) {
            throw new RuntimeException('无法启动 WebDAV 测试服务');
        }
        if (isset($pipes[0]) && is_resource($pipes[0])) fclose($pipes[0]);
        for ($i = 0; $i < 50; $i++) {
            $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
            if (is_resource($socket)) { fclose($socket); return; }
            usleep(100000);
        }
        throw new RuntimeException('WebDAV 测试服务启动超时');
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$serverProcess)) {
            proc_terminate(self::$serverProcess);
            proc_close(self::$serverProcess);
        }
        if (self::$routerFile !== '') @unlink(self::$routerFile);
        if (is_dir(self::$serverStorage)) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(self::$serverStorage, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($iterator as $file) {
                $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
            }
            @rmdir(self::$serverStorage);
        }
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();
        auth_reset_config_cache();
        @unlink(CONFIG_FILE);
        @unlink(BACKUP_WEBDAV_CONFIG_FILE);
        foreach (glob(BACKUP_WEBDAV_JOBS_DIR . '/*') ?: [] as $file) {
            @unlink($file);
        }
        foreach (glob(BACKUP_WEBDAV_TMP_DIR . '/*') ?: [] as $file) {
            @unlink($file);
        }
        foreach (glob(BACKUPS_DIR . '/backup_*.json') ?: [] as $file) {
            @unlink($file);
        }
    }

    public function testDefaultConfigurationMatchesManualTrustedTargetPolicy(): void
    {
        $config = backup_webdav_load_config();

        $this->assertFalse($config['enabled']);
        $this->assertFalse($config['ssrf_protection']);
        $this->assertFalse($config['tls_enabled']);
        $this->assertFalse($config['auth_enabled']);
        $this->assertSame(10, $config['remote_retention']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{12}$/', $config['instance_id']);
        $this->assertFileExists(BACKUP_WEBDAV_CONFIG_FILE);
    }

    public function testSaveConfigurationPreservesBlankPasswordAndForcesRetention(): void
    {
        $first = backup_webdav_save_config([
            'enabled' => true,
            'base_url' => 'http://dav.example.test/root/',
            'remote_dir' => '/RiverOps/Home',
            'auth_enabled' => true,
            'username' => 'riverops',
            'password' => 'secret',
            'remote_retention' => 999,
        ]);
        $this->assertTrue($first['ok'], $first['msg']);

        $second = backup_webdav_save_config([
            'enabled' => true,
            'base_url' => 'http://dav.example.test/root',
            'remote_dir' => '/RiverOps/Home',
            'auth_enabled' => true,
            'username' => 'riverops',
            'password' => '',
            'remote_retention' => 1,
        ]);
        $this->assertTrue($second['ok'], $second['msg']);

        $saved = backup_webdav_load_config();
        $this->assertSame('secret', $saved['password']);
        $this->assertSame(10, $saved['remote_retention']);
        $this->assertSame('/RiverOps/Home', $saved['remote_dir']);
        $this->assertTrue(backup_webdav_public_config($saved)['password_set']);
        $this->assertSame('', backup_webdav_public_config($saved)['password']);
    }

    public function testTlsSwitchControlsSavedScheme(): void
    {
        $result = backup_webdav_save_config([
            'enabled' => true,
            'base_url' => 'http://dav.example.test:8443/root',
            'tls_enabled' => true,
        ]);

        $this->assertTrue($result['ok'], $result['msg']);
        $this->assertSame('https://dav.example.test:8443/root', backup_webdav_load_config()['base_url']);
    }

    public function testRejectsCredentialsInsideUrlAndUnsafeDirectorySegments(): void
    {
        $this->assertFalse(backup_webdav_validate_base_url('http://user:pass@example.test/dav')['ok']);
        $this->assertFalse(backup_webdav_validate_base_url('http://example.test/dav?token=secret')['ok']);

        $this->expectException(InvalidArgumentException::class);
        backup_webdav_normalize_remote_dir('/RiverOps/../secret');
    }

    public function testSsrfProtectionIsOptionalAndDisabledPolicyAllowsPrivateTarget(): void
    {
        $off = backup_webdav_validate_request_target('http://127.0.0.1:8080/dav', false);
        $on = backup_webdav_validate_request_target('http://127.0.0.1:8080/dav', true);

        $this->assertTrue($off['ok']);
        $this->assertFalse($on['ok']);
        $this->assertStringContainsString('非公网地址', $on['msg']);
    }

    public function testParsesDavMultistatusAndFiltersCurrentInstance(): void
    {
        $config = backup_webdav_normalize_config([
            'instance_id' => 'abcdef123456',
            'base_url' => 'http://dav.example.test',
        ]);
        $xml = <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<d:multistatus xmlns:d="DAV:">
  <d:response>
    <d:href>/dav/RiverOps/riverops_abcdef123456_backup_20260716_031700_manual.json</d:href>
    <d:propstat><d:prop>
      <d:getcontentlength>1234</d:getcontentlength>
      <d:getlastmodified>Thu, 16 Jul 2026 03:17:00 GMT</d:getlastmodified>
      <d:getetag>"etag-1"</d:getetag>
      <d:resourcetype/>
    </d:prop></d:propstat>
  </d:response>
</d:multistatus>
XML;

        $rows = backup_webdav_parse_multistatus($xml);
        $this->assertCount(1, $rows);
        $item = backup_webdav_remote_item_from_row($config, $rows[0]);
        $this->assertNotNull($item);
        $this->assertSame(1234, $item['size']);
        $this->assertSame('manual', $item['trigger']);
        $this->assertSame('etag-1', $item['etag']);

        $other = $rows[0];
        $other['href'] = '/dav/RiverOps/riverops_000000000000_backup_20260716_031700_manual.json';
        $this->assertNull(backup_webdav_remote_item_from_row($config, $other));
    }

    public function testRetentionOnlyReturnsItemsBeyondNewestTen(): void
    {
        $items = [];
        for ($i = 0; $i < 12; $i++) {
            $items[] = [
                'filename' => 'backup_' . $i . '.json',
                'created_at' => date('Y-m-d H:i:s', 1_700_000_000 + $i),
            ];
        }

        $delete = backup_webdav_retention_candidates($items);
        $this->assertCount(2, $delete);
        $this->assertSame('backup_1.json', $delete[0]['filename']);
        $this->assertSame('backup_0.json', $delete[1]['filename']);
    }

    public function testValidatesPlainJsonBackupWithoutEncryptionEnvelope(): void
    {
        backup_webdav_ensure_dirs();
        $file = BACKUP_WEBDAV_TMP_DIR . '/plain.json';
        file_put_contents($file, json_encode([
            'created_at' => '2026-07-16 03:17:00',
            'trigger' => 'manual',
            'config' => ['site_name' => 'RiverOps'],
            'dns_config' => ['access_key_secret' => 'plain-sensitive-value'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $result = backup_webdav_validate_local_backup($file);
        $this->assertTrue($result['ok'], $result['msg']);
        $this->assertSame('plain-sensitive-value', $result['data']['dns_config']['access_key_secret']);
    }

    public function testRealWebdavRoundtripAndRemoteRetention(): void
    {
        $config = backup_webdav_normalize_config([
            'instance_id' => 'abcdef123456',
            'enabled' => true,
            'base_url' => self::$serverUrl,
            'remote_dir' => '/RiverOps',
            'request_timeout' => 30,
        ]);

        $test = backup_webdav_test_connection($config);
        $this->assertTrue($test['ok'], $test['msg']);

        for ($i = 0; $i < 12; $i++) {
            $filename = 'backup_202607' . str_pad((string)($i + 1), 2, '0', STR_PAD_LEFT) . '_031700_manual.json';
            file_put_contents(BACKUPS_DIR . '/' . $filename, json_encode([
                'created_at' => '2026-07-' . str_pad((string)($i + 1), 2, '0', STR_PAD_LEFT) . ' 03:17:00',
                'trigger' => 'manual',
                'config' => ['site_name' => 'RiverOps ' . $i],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $upload = backup_webdav_upload_local($filename, $config);
            $this->assertTrue($upload['ok'], $upload['msg']);
        }

        $list = backup_webdav_list_remote($config);
        $this->assertTrue($list['ok'], $list['msg']);
        $this->assertCount(10, $list['data']['items']);
        $this->assertStringContainsString('20260712', $list['data']['items'][0]['filename']);
        $this->assertStringContainsString('20260703', $list['data']['items'][9]['filename']);

        $remoteFilename = $list['data']['items'][0]['filename'];
        $download = backup_webdav_download_remote($remoteFilename, $config);
        $this->assertTrue($download['ok'], $download['msg']);
        $this->assertFileExists($download['data']['tmp_path']);
        @unlink($download['data']['tmp_path']);

        $delete = backup_webdav_delete_remote($remoteFilename, $config);
        $this->assertTrue($delete['ok'], $delete['msg']);
        $afterDelete = backup_webdav_list_remote($config);
        $this->assertCount(9, $afterDelete['data']['items']);
    }

    public function testRestoreJobDownloadsValidatesAndUsesLocalRestoreFlow(): void
    {
        $saved = backup_webdav_save_config([
            'instance_id' => 'abcdef123456',
            'enabled' => true,
            'base_url' => self::$serverUrl,
            'remote_dir' => '/RestoreJob',
            'request_timeout' => 30,
        ]);
        $this->assertTrue($saved['ok'], $saved['msg']);

        $filename = 'backup_20260716_040000_manual.json';
        file_put_contents(BACKUPS_DIR . '/' . $filename, json_encode([
            'created_at' => '2026-07-16 04:00:00',
            'trigger' => 'manual',
            'config' => ['site_name' => '来自 WebDAV 的配置'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $upload = backup_webdav_upload_local($filename);
        $this->assertTrue($upload['ok'], $upload['msg']);

        save_config(['site_name' => '恢复前配置']);
        $jobId = 'webdav_restore_test';
        backup_webdav_job_write($jobId, [
            'action' => 'restore_remote',
            'params' => ['filename' => $upload['data']['remote_filename']],
            'operator' => 'phpunit',
            'status' => 'queued',
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        $result = backup_webdav_run_job($jobId);

        $this->assertSame('success', $result['status']);
        $restored = json_decode((string)file_get_contents(CONFIG_FILE), true);
        $this->assertSame('来自 WebDAV 的配置', $restored['site_name']);
        $this->assertNotEmpty(glob(BACKUPS_DIR . '/backup_*_auto_before_restore.json') ?: []);
        $downloaded = glob(BACKUPS_DIR . '/backup_*_webdav_restore*.json') ?: [];
        $this->assertNotEmpty($downloaded);
        $downloadedPayload = json_decode((string)file_get_contents($downloaded[0]), true);
        $this->assertSame('webdav_restore', $downloadedPayload['trigger']);
        $this->assertSame('2026-07-16 04:00:00', $downloadedPayload['remote_created_at']);
    }

    public function testManualJobCanLaunchDetachedAndReachSuccess(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('后台脱离进程由 Linux 容器负责');
        }
        $saved = backup_webdav_save_config([
            'instance_id' => 'abcdef123456',
            'enabled' => true,
            'base_url' => self::$serverUrl,
            'remote_dir' => '/DetachedJob',
            'request_timeout' => 30,
        ]);
        $this->assertTrue($saved['ok'], $saved['msg']);

        $started = backup_webdav_start_job('test_connection', [], 'phpunit');
        $this->assertTrue($started['ok'], $started['msg']);
        $jobId = $started['data']['job_id'];
        $job = null;
        for ($i = 0; $i < 50; $i++) {
            usleep(100000);
            $job = backup_webdav_job_public_payload($jobId);
            if ($job !== null && !in_array($job['status'], ['queued', 'running'], true)) {
                break;
            }
        }

        $this->assertNotNull($job);
        $this->assertSame('success', $job['status'], $job['message'] ?? 'WebDAV 后台任务失败');
    }
}
