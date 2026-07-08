<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../admin/shared/domain_expiry_lib.php';

final class DomainExpiryLibTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        @unlink(DOMAIN_EXPIRY_FILE);
        @unlink(DOMAIN_EXPIRY_RDAP_BOOTSTRAP_FILE);
        @unlink(DATA_DIR . '/dns_config.json');
        @unlink(DATA_DIR . '/dns_zones_cache.json');
        @unlink(DATA_DIR . '/ddns_tasks.json');
    }

    protected function tearDown(): void
    {
        @unlink(DOMAIN_EXPIRY_FILE);
        @unlink(DOMAIN_EXPIRY_RDAP_BOOTSTRAP_FILE);
        @unlink(DATA_DIR . '/dns_config.json');
        @unlink(DATA_DIR . '/dns_zones_cache.json');
        @unlink(DATA_DIR . '/ddns_tasks.json');
        parent::tearDown();
    }

    public function testNormalizeAndValidateDomain(): void
    {
        $this->assertSame('example.com', domain_expiry_normalize_domain(' *.Example.COM. '));
        $this->assertTrue(domain_expiry_is_valid_domain('example.com'));
        $this->assertTrue(domain_expiry_is_valid_domain('xn--fsqu00a.xn--0zwm56d'));
        $this->assertFalse(domain_expiry_is_valid_domain('https://example.com'));
        $this->assertFalse(domain_expiry_is_valid_domain('example'));
    }

    public function testRegisteredDomainGuessHandlesCommonTwoLevelSuffix(): void
    {
        $this->assertSame('example.com', domain_expiry_registered_domain_guess('www.example.com'));
        $this->assertSame('example.com.cn', domain_expiry_registered_domain_guess('api.home.example.com.cn'));
    }

    public function testStatusFromDate(): void
    {
        $now = strtotime('2026-07-06 12:00:00');
        $this->assertSame(['status' => 'expired', 'days_left' => -1], domain_expiry_status_from_date('2026-07-05', $now));
        $this->assertSame(['status' => 'critical', 'days_left' => 7], domain_expiry_status_from_date('2026-07-13', $now));
        $this->assertSame(['status' => 'warning', 'days_left' => 30], domain_expiry_status_from_date('2026-08-05', $now));
        $this->assertSame(['status' => 'notice', 'days_left' => 90], domain_expiry_status_from_date('2026-10-04', $now));
        $this->assertSame(['status' => 'ok', 'days_left' => 91], domain_expiry_status_from_date('2026-10-05', $now));
    }

    public function testParseRdapExpirationAndRegistrar(): void
    {
        $parsed = domain_expiry_parse_rdap([
            'events' => [
                ['eventAction' => 'registration', 'eventDate' => '2020-01-01T00:00:00Z'],
                ['eventAction' => 'expiration', 'eventDate' => '2027-08-12T00:00:00Z'],
            ],
            'entities' => [
                [
                    'roles' => ['registrar'],
                    'vcardArray' => ['vcard', [
                        ['fn', [], 'text', 'Example Registrar'],
                    ]],
                ],
            ],
        ]);
        $this->assertTrue($parsed['ok']);
        $this->assertSame('2027-08-12', $parsed['expires_at']);
        $this->assertSame('Example Registrar', $parsed['registrar']);
    }

    public function testParseWhoisExpirationAndRegistrar(): void
    {
        $parsed = domain_expiry_parse_whois(<<<'WHOIS'
Domain Name: qzz.io
Registry Expiry Date: 2029-05-04T08:14:01Z
Registrar: Gandi SAS
WHOIS);

        $this->assertTrue($parsed['ok']);
        $this->assertSame('2029-05-04', $parsed['expires_at']);
        $this->assertSame('Gandi SAS', $parsed['registrar']);
    }

    public function testParseWhoisAlternativeExpirationLabel(): void
    {
        $parsed = domain_expiry_parse_whois(<<<'WHOIS'
Domain Name: cc.cd
Registrar Registration Expiration Date: 2024-06-30T00:00:00.0Z
Registrar: SCPT
WHOIS);

        $this->assertTrue($parsed['ok']);
        $this->assertSame('2024-06-30', $parsed['expires_at']);
        $this->assertSame('SCPT', $parsed['registrar']);
    }

    public function testParseIanaWhoisServerDoesNotBleedIntoNextLine(): void
    {
        $this->assertSame('', domain_expiry_parse_iana_whois_server("whois:        \nstatus: ACTIVE\n"));
        $this->assertSame('whois.nic.io', domain_expiry_parse_iana_whois_server("whois:        whois.nic.io\nstatus: ACTIVE\n"));
    }

    public function testManualDomainsPersistAndRowsIncludeSource(): void
    {
        $added = domain_expiry_add_manual('Example.com');
        $this->assertTrue($added['ok']);

        $rows = domain_expiry_rows();
        $this->assertCount(1, $rows);
        $this->assertSame('example.com', $rows[0]['domain']);
        $this->assertSame('手动', $rows[0]['source']);
        $this->assertSame('unknown', $rows[0]['status']);
    }

    public function testRefreshDomainUsesRdapBootstrapAndFetcher(): void
    {
        file_put_contents(DOMAIN_EXPIRY_RDAP_BOOTSTRAP_FILE, json_encode([
            'updated_at' => time(),
            'data' => [
                'services' => [
                    [['com'], ['https://rdap.example.test/']],
                ],
            ],
        ]));

        domain_expiry_add_manual('example.com');
        $result = domain_expiry_refresh_domain('example.com', true, static function(string $url): array {
            return [
                'ok' => true,
                'status' => 200,
                'json' => [
                    'events' => [
                        ['eventAction' => 'expiration', 'eventDate' => '2028-01-01T00:00:00Z'],
                    ],
                ],
                'body' => '{}',
                'msg' => '',
            ];
        });

        $this->assertTrue($result['ok']);
        $this->assertSame('2028-01-01', $result['record']['expires_at']);
        $this->assertSame('rdap', $result['record']['source']);
    }

    public function testDomainSourcesIncludeCachedDnsZones(): void
    {
        file_put_contents(DATA_DIR . '/dns_config.json', json_encode([
            'version' => 2,
            'accounts' => [
                ['id' => 'dns_test', 'provider' => 'cloudflare', 'name' => 'cf', 'credentials' => []],
            ],
            'ui' => [
                'selected_account_id' => 'dns_test',
                'selected_zone_id' => 'z1',
                'selected_zone_name' => 'codingriver.qzz.io',
            ],
        ]));
        file_put_contents(DATA_DIR . '/dns_zones_cache.json', json_encode([
            'updated_at' => time(),
            'zones' => [
                ['account_id' => 'dns_test', 'provider' => 'cloudflare', 'zone' => ['name' => '303066.xyz']],
                ['account_id' => 'dns_test', 'provider' => 'cloudflare', 'zone' => ['name' => 'codingriver.eu.org']],
                ['account_id' => 'dns_test', 'provider' => 'cloudflare', 'zone' => ['name' => 'codingriver.qzz.io']],
            ],
        ]));

        $domains = domain_expiry_collect_domains();

        $this->assertContains('303066.xyz', $domains);
        $this->assertContains('codingriver.eu.org', $domains);
        $this->assertContains('codingriver.qzz.io', $domains);
    }

    public function testRefreshSubZoneDoesNotFallBackToParentDomain(): void
    {
        file_put_contents(DOMAIN_EXPIRY_RDAP_BOOTSTRAP_FILE, json_encode([
            'updated_at' => time(),
            'data' => [
                'services' => [
                    [['io'], ['https://rdap.example.test/']],
                ],
            ],
        ]));

        domain_expiry_add_manual('codingriver.qzz.io');
        $seen = [];
        $result = domain_expiry_refresh_domain('codingriver.qzz.io', true, static function(string $url) use (&$seen): array {
            $seen[] = $url;
            return ['ok' => false, 'status' => 404, 'json' => null, 'body' => '', 'msg' => 'not found'];
        });

        $this->assertFalse($result['ok']);
        $this->assertNotEmpty($seen);
        $this->assertStringContainsString('/domain/codingriver.qzz.io', $seen[0]);
        foreach ($seen as $url) {
            $this->assertStringNotContainsString('/domain/qzz.io', $url);
        }
    }

    public function testRefreshMarksGenericOnlyRdapAsUnsupported(): void
    {
        file_put_contents(DOMAIN_EXPIRY_RDAP_BOOTSTRAP_FILE, json_encode([
            'updated_at' => time(),
            'data' => ['services' => []],
        ]));

        domain_expiry_add_manual('codingriver.qzz.io');
        $result = domain_expiry_refresh_domain('codingriver.qzz.io', true, static function(string $url): array {
            return ['ok' => false, 'status' => 404, 'json' => null, 'body' => '<html></html>', 'msg' => '返回不是有效 JSON'];
        });

        $this->assertFalse($result['ok']);
        $this->assertSame('unsupported', $result['record']['status']);
        $this->assertSame('RDAP 未支持', $result['record']['status_label']);
        $this->assertSame('codingriver.qzz.io', $result['record']['rdap_domain']);
    }

    public function testUnsupportedRefreshClearsStaleExpiry(): void
    {
        file_put_contents(DOMAIN_EXPIRY_RDAP_BOOTSTRAP_FILE, json_encode([
            'updated_at' => time(),
            'data' => ['services' => []],
        ]));
        domain_expiry_add_manual('codingriver.qzz.io');
        $data = domain_expiry_load();
        $data['records']['codingriver.qzz.io'] = [
            'domain' => 'codingriver.qzz.io',
            'rdap_domain' => 'qzz.io',
            'expires_at' => '2029-05-04',
            'days_left' => 1032,
            'status' => 'ok',
            'registrar' => 'Gandi SAS',
            'checked_at' => '2026-07-07 00:00:00',
            'error' => '',
        ];
        domain_expiry_save($data);

        $result = domain_expiry_refresh_domain('codingriver.qzz.io', true, static function(string $url): array {
            return ['ok' => false, 'status' => 404, 'json' => null, 'body' => '<html></html>', 'msg' => '返回不是有效 JSON'];
        });

        $this->assertFalse($result['ok']);
        $this->assertSame('unsupported', $result['record']['status']);
        $this->assertSame('', $result['record']['expires_at']);
        $this->assertNull($result['record']['days_left']);
        $this->assertSame('', $result['record']['registrar']);
    }

    public function testPlatformConfigSaveReturnsPlaintextAndKeepsExistingSecrets(): void
    {
        $saved = domain_expiry_save_platform_configs([
            ['provider' => 'digitalplat', 'enabled' => true, 'token' => 'Bearer dp_live_abcdefghijklmnopqrstuvwxyz'],
            ['provider' => 'dnshe', 'enabled' => true, 'api_key' => 'key_1234567890', 'api_secret' => 'secret_1234567890'],
        ]);

        $this->assertTrue($saved['ok']);
        $public = domain_expiry_platform_configs_public();
        $this->assertCount(2, $public);
        $this->assertSame('digitalplat', $public[0]['provider']);
        $this->assertSame('dp_live_abcdefghijklmnopqrstuvwxyz', $public[0]['token']);
        $this->assertTrue($public[0]['has_token']);
        $this->assertStringStartsWith('dp_l', $public[0]['token_masked']);
        $this->assertStringEndsWith('wxyz', $public[0]['token_masked']);
        $this->assertSame('dnshe', $public[1]['provider']);
        $this->assertSame('key_1234567890', $public[1]['api_key']);
        $this->assertSame('secret_1234567890', $public[1]['api_secret']);

        domain_expiry_save_platform_configs([
            ['provider' => 'digitalplat', 'enabled' => true, 'token' => ''],
        ]);

        $config = domain_expiry_platform_config('digitalplat');
        $this->assertIsArray($config);
        $this->assertSame('dp_live_abcdefghijklmnopqrstuvwxyz', $config['token']);
    }

    public function testParseDigitalplatExpiresAtCompactDate(): void
    {
        $parsed = domain_expiry_parse_digitalplat_domain([
            'domain' => 'codingriver.qzz.io',
            'expires_at' => '20270610',
            'status' => 'ok',
            'registrar' => 'DigitalPlat Registrar',
        ], 'codingriver.qzz.io');

        $this->assertTrue($parsed['ok']);
        $this->assertSame('2027-06-10', $parsed['expires_at']);
        $this->assertSame('digitalplat', $parsed['source']);
        $this->assertSame('DigitalPlat Registrar', $parsed['registrar']);
    }

    public function testParseDnsheOfficialSubdomainResponse(): void
    {
        $item = domain_expiry_find_dnshe_domain([
            'success' => true,
            'subdomains' => [
                [
                    'subdomain' => 'codingriver',
                    'rootdomain' => 'cc.cd',
                    'full_domain' => 'codingriver.cc.cd',
                    'status' => 'Registered',
                    'expires_at' => '2027-06-10 11:02:16',
                    'never_expires' => 0,
                ],
            ],
        ], 'codingriver.cc.cd');

        $this->assertIsArray($item);
        $parsed = domain_expiry_parse_dnshe_domain($item, 'codingriver.cc.cd');
        $this->assertTrue($parsed['ok']);
        $this->assertSame('2027-06-10', $parsed['expires_at']);
        $this->assertSame('dnshe', $parsed['source']);
        $this->assertSame('DNSHE', $parsed['registrar']);
    }

    public function testPlatformHttpErrorDetectsCloudflareChallenge(): void
    {
        $msg = domain_expiry_platform_http_error_message('DigitalPlat', [
            'status' => 403,
            'headers' => ['cf-mitigated' => 'challenge'],
            'body' => '<html><h1>Security Check</h1></html>',
            'msg' => 'HTTP 403 返回不是有效 JSON',
        ]);

        $this->assertStringContainsString('Cloudflare', $msg);
        $this->assertStringContainsString('容器出口', $msg);
        $this->assertStringNotContainsString('Token / API Key 无效', $msg);
    }

    public function testRefreshKnownPublicNamespaceWithoutTokenRequestsPlatformConfig(): void
    {
        domain_expiry_add_manual('codingriver.qzz.io');
        $result = domain_expiry_refresh_domain('codingriver.qzz.io', true);

        $this->assertFalse($result['ok']);
        $this->assertSame('unsupported', $result['record']['status']);
        $this->assertSame('codingriver.qzz.io', $result['record']['rdap_domain']);
        $this->assertStringContainsString('DigitalPlat', $result['record']['error']);
        $this->assertStringContainsString('Bearer Token', $result['record']['error']);
    }
}
