<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class SharedFunctionsTest extends TestCase
{
    private static function normalizeNginxSnapshot(string $conf): string
    {
        $conf = str_replace("\r\n", "\n", $conf);
        $conf = str_replace(DATA_DIR, '{{DATA_DIR}}', $conf);
        $conf = preg_replace('/^# 生成时间：.*$/m', '# 生成时间：{{GENERATED_AT}}', $conf) ?? $conf;
        return rtrim($conf) . "\n";
    }

    protected function setUp(): void
    {
        parent::setUp();
        auth_reset_config_cache();
        @mkdir(DATA_DIR . '/nginx-runtime/conf.d', 0777, true);
        @mkdir(DATA_DIR . '/nginx-runtime/http.d', 0777, true);
        putenv('NAV_NGINX_CONF_D_DIR=' . DATA_DIR . '/nginx-runtime/conf.d');
        putenv('NAV_NGINX_HTTP_D_DIR=' . DATA_DIR . '/nginx-runtime/http.d');
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

    protected function tearDown(): void
    {
        putenv('NAV_NGINX_CONF_D_DIR');
        putenv('NAV_NGINX_HTTP_D_DIR');
        parent::tearDown();
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

    public function testSiteCredentialsAreBackedUpAndStrippedFromPublicPayload(): void
    {
        $sites = ['groups' => [[
            'id' => 'g1',
            'name' => 'G1',
            'sites' => [[
                'id' => 'qb1',
                'name' => 'qB',
                'type' => 'proxy_domain',
                'proxy_domain' => 'qb1.303066.xyz',
                'proxy_target' => 'http://192.168.2.2:9097',
                'credential_username' => 'admin',
                'credential_password' => '111111',
                'credential_note' => '测试账号',
            ]],
        ]]];
        save_sites($sites);

        $payload = backup_collect_payload('export');
        $site = $payload['sites']['groups'][0]['sites'][0];
        $this->assertSame('admin', $site['credential_username']);
        $this->assertSame('111111', $site['credential_password']);
        $this->assertSame('测试账号', $site['credential_note']);

        $public = sites_strip_credentials($payload['sites']);
        $publicSite = $public['groups'][0]['sites'][0];
        $this->assertArrayNotHasKey('credential_username', $publicSite);
        $this->assertArrayNotHasKey('credential_password', $publicSite);
        $this->assertArrayNotHasKey('credential_note', $publicSite);
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

    public function testNginxProxyConfDefaultPathsUseDataDirectory(): void
    {
        putenv('NAV_NGINX_CONF_D_DIR');
        putenv('NAV_NGINX_HTTP_D_DIR');

        $base = rtrim(realpath(DATA_DIR) ?: DATA_DIR, '/');
        $this->assertSame($base . '/nginx/conf.d/nav-proxy.conf', nginx_proxy_conf_path());
        $this->assertSame($base . '/nginx/http.d/nav-proxy-domains.conf', nginx_domain_proxy_conf_path());

        putenv('NAV_NGINX_CONF_D_DIR=' . DATA_DIR . '/nginx-runtime/conf.d');
        putenv('NAV_NGINX_HTTP_D_DIR=' . DATA_DIR . '/nginx-runtime/http.d');
    }

    public function testNginxGenerateProxyConfDomainSnapshotCoversLoginRedirectAndQbHeaders(): void
    {
        save_config([
            'nav_domain' => 'nav.303066.xyz',
            'proxy_params_mode' => 'simple',
        ]);
        save_sites(['groups' => [[
            'sites' => [
                [
                    'id' => 'normal-domain',
                    'type' => 'proxy',
                    'proxy_mode' => 'domain',
                    'proxy_domain' => 'app.local.303066.xyz',
                    'name' => 'Normal Domain',
                    'proxy_target' => 'http://192.168.2.2:10086',
                ],
                [
                    'id' => 'qb-domain',
                    'type' => 'proxy',
                    'proxy_mode' => 'domain',
                    'proxy_domain' => 'qb1.local.303066.xyz',
                    'name' => 'qB Local',
                    'proxy_target' => 'http://192.168.2.2:9097',
                ],
            ],
        ]]]);

        $conf = nginx_generate_proxy_conf();
        $this->assertTrue($conf['ok'], $conf['msg']);
        $domain = $conf['domain_conf'];
        $snapshot = self::normalizeNginxSnapshot($domain);
        $expected = file_get_contents(__DIR__ . '/../../fixtures/nginx-domain-proxy-snapshot.conf');
        $this->assertSame($expected, $snapshot);

        $this->assertStringContainsString('server_name app.local.303066.xyz;', $domain);
        $this->assertStringContainsString('server_name qb1.local.303066.xyz;', $domain);
        $this->assertStringContainsString('location = /login.php', $domain);
        $this->assertStringContainsString('auth_request off;', $domain);
        $this->assertStringContainsString('auth_request /auth/verify;', $domain);
        $this->assertStringContainsString('absolute_redirect off;', $domain);
        $this->assertStringContainsString('return 302 /login.php?redirect=$nav_forwarded_proto://$http_host$request_uri;', $domain);

        $this->assertStringContainsString('proxy_set_header Host $proxy_host;', $domain);
        $this->assertStringContainsString('proxy_set_header Origin "";', $domain);
        $this->assertStringContainsString('proxy_set_header Referer "";', $domain);
        $this->assertStringContainsString('proxy_read_timeout 1800s;', $domain);
        $this->assertStringNotContainsString('https://$host$request_uri', $domain);
    }

    public function testNginxProxyProfilesGenerateTargetedDirectives(): void
    {
        save_config([
            'nav_domain' => 'nav.303066.xyz',
            'proxy_params_mode' => 'simple',
        ]);
        save_sites(['groups' => [[
            'sites' => [
                [
                    'id' => 'custom-qb',
                    'type' => 'proxy_domain',
                    'proxy_domain' => 'custom-qb.local.303066.xyz',
                    'name' => 'Custom qB',
                    'proxy_target' => 'http://192.168.2.2:19097',
                    'proxy_profile' => 'qbittorrent',
                ],
                [
                    'id' => 'spa-app',
                    'type' => 'proxy_domain',
                    'proxy_domain' => 'spa.local.303066.xyz',
                    'name' => 'SPA App',
                    'proxy_target' => 'http://192.168.2.2:3000',
                    'proxy_profile' => 'spa',
                ],
            ],
        ]]]);

        $conf = nginx_generate_proxy_conf();
        $this->assertTrue($conf['ok'], $conf['msg']);
        $domain = $conf['domain_conf'];

        $this->assertStringContainsString('server_name custom-qb.local.303066.xyz;', $domain);
        $this->assertStringContainsString('proxy_set_header Host $proxy_host;', $domain);
        $this->assertStringContainsString('proxy_set_header Origin "";', $domain);
        $this->assertStringContainsString('proxy_set_header Referer "";', $domain);

        $this->assertStringContainsString('server_name spa.local.303066.xyz;', $domain);
        $this->assertStringContainsString('location ~* ^/(assets|static|scripts|fonts|img|css|js)/', $domain);
        $this->assertStringContainsString('add_header Cache-Control "private, max-age=3600" always;', $domain);

        $state = nginx_current_proxy_state();
        $this->assertSame('qbittorrent', $state['sites']['custom-qb']['proxy_profile']);
        $this->assertSame('spa', $state['sites']['spa-app']['proxy_profile']);
    }

    public function testProxyDiagnoseBuildsUrlsAndDetectsHardcodedAddresses(): void
    {
        putenv('NAV_PORT=58080');
        $external = [
            'id' => 'demo',
            'type' => 'proxy_domain',
            'proxy_domain' => 'demo.303066.xyz',
            'proxy_target' => 'http://192.168.2.2:3000',
        ];
        $local = $external;
        $local['proxy_domain'] = 'demo.local.303066.xyz';

        $this->assertSame('https://demo.303066.xyz/', proxy_diagnose_site_url($external));
        $this->assertSame('http://demo.local.303066.xyz:58080/', proxy_diagnose_site_url($local));

        $issues = [];
        proxy_diagnose_analyze_response('target', [
            'status' => 200,
            'error' => '',
            'location' => '',
            'final_url' => 'http://192.168.2.2:3000/',
            'body_sample' => '<script>fetch("http://127.0.0.1:9090/version")</script>',
        ], $issues);

        $this->assertTrue(array_reduce(
            $issues,
            static fn(bool $carry, array $issue): bool => $carry || ($issue['code'] ?? '') === 'target_hardcoded_loopback',
            false
        ));

        $resources = proxy_diagnose_extract_resource_urls(
            '<script src="/assets/app.js"></script><link href="css/app.css" rel="stylesheet">',
            'http://192.168.2.2:3000/login'
        );
        $this->assertSame([
            'http://192.168.2.2:3000/assets/app.js',
            'http://192.168.2.2:3000/css/app.css',
        ], $resources);
    }

    public function testProxyBrowserDiagnoseBuildsSafeCommandsAndParsesOutput(): void
    {
        $urls = [
            'https://demo.303066.xyz/',
            'javascript:alert(1)',
            'http://demo.local.303066.xyz:58080/',
        ];

        $this->assertSame([
            'https://demo.303066.xyz/',
            'http://demo.local.303066.xyz:58080/',
        ], proxy_browser_diagnose_normalize_urls($urls));

        $hostCommand = proxy_browser_diagnose_host_command(['https://demo.303066.xyz/'], true);
        $this->assertStringContainsString('PROXY_DIAG_URLS=', $hostCommand);
        $this->assertStringContainsString('PROXY_DIAG_HEADED=', $hostCommand);
        $this->assertStringContainsString('node scripts/proxy_browser_diagnose.js', $hostCommand);

        $runCommand = proxy_browser_diagnose_command(['https://demo.303066.xyz/'], [
            'nav_session' => 'token with spaces',
            'timeout_ms' => 3000,
        ]);
        $this->assertStringContainsString('PROXY_DIAG_NAV_SESSION=', $runCommand);
        $this->assertStringContainsString('PROXY_DIAG_TIMEOUT=', $runCommand);
        $this->assertStringContainsString('node ', $runCommand);

        $parsed = proxy_browser_diagnose_parse_output("notice\n[{\"id\":\"demo\",\"failed\":[]}]\nwarning");
        $this->assertSame('demo', $parsed[0]['id'] ?? null);
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
