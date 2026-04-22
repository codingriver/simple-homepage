<?php
declare(strict_types=1);

require_once __DIR__ . '/../../phpunit/bootstrap.php';

class ExpiryLibTest extends PHPUnit\Framework\TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanDataDir();
        @mkdir(DATA_DIR . '/logs', 0777, true);
        require_once __DIR__ . '/../../../admin/shared/functions.php';
        require_once __DIR__ . '/../../../admin/shared/expiry_lib.php';
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

    public function testExpiryDaysLeftReturnsNullForEmptyString(): void
    {
        $this->assertNull(expiry_days_left(''));
        $this->assertNull(expiry_days_left(null));
    }

    public function testExpiryDaysLeftReturnsNullForInvalidDate(): void
    {
        $this->assertNull(expiry_days_left('invalid-date'));
    }

    public function testExpiryDaysLeftCalculatesCorrectly(): void
    {
        $tomorrow = date('Y-m-d', strtotime('+1 day'));
        $this->assertSame(1, expiry_days_left($tomorrow));

        $yesterday = date('Y-m-d', strtotime('-1 day'));
        $this->assertSame(-1, expiry_days_left($yesterday));

        $today = date('Y-m-d');
        $this->assertSame(0, expiry_days_left($today));
    }

    public function testExpiryProbeSslReturnsNullForNonHttps(): void
    {
        $this->assertNull(expiry_probe_ssl_expire_at('http://example.com'));
    }

    public function testExpiryProbeSslReturnsNullForInvalidUrl(): void
    {
        $this->assertNull(expiry_probe_ssl_expire_at('not-a-url'));
    }

    public function testExpiryProbeSslReturnsNullForPrivateIp(): void
    {
        $this->assertNull(expiry_probe_ssl_expire_at('https://127.0.0.1'));
        $this->assertNull(expiry_probe_ssl_expire_at('https://192.168.1.1'));
    }

    public function testExpiryNoticeLevelsReturnsEmptyForNull(): void
    {
        $this->assertSame([], expiry_notice_levels(null));
    }

    public function testExpiryNoticeLevelsReturnsOverdueForNegative(): void
    {
        $this->assertSame(['overdue'], expiry_notice_levels(-1));
    }

    public function testExpiryNoticeLevelsReturnsCorrectLevels(): void
    {
        $this->assertSame(['30', '7', '1'], expiry_notice_levels(0));
        $this->assertSame(['30', '7', '1'], expiry_notice_levels(1));
        $this->assertSame(['30', '7'], expiry_notice_levels(7));
        $this->assertSame(['30'], expiry_notice_levels(30));
        $this->assertSame([], expiry_notice_levels(31));
    }

    public function testExpirySiteRowsReturnsEmptyWhenNoSites(): void
    {
        // No sites.json means no sites
        $this->assertSame([], expiry_site_rows());
    }

    public function testExpirySiteRowsExtractsDomainFromUrl(): void
    {
        file_put_contents(DATA_DIR . '/sites.json', json_encode([
            'groups' => [[
                'id' => 'g1',
                'name' => 'Test Group',
                'sites' => [[
                    'id' => 's1',
                    'name' => 'Test Site',
                    'url' => 'https://example.com/path',
                    'domain_expire_at' => '2025-12-31',
                ]],
            ]],
        ], JSON_PRETTY_PRINT));
        $rows = expiry_site_rows();
        $this->assertCount(1, $rows);
        $this->assertSame('example.com', $rows[0]['domain']);
        $this->assertSame('2025-12-31', $rows[0]['domain_expire_at']);
    }

    public function testExpiryScanAndStoreSavesData(): void
    {
        $scan = expiry_scan_and_store(false);
        $this->assertArrayHasKey('version', $scan);
        $this->assertArrayHasKey('last_scan_at', $scan);
        $this->assertArrayHasKey('rows', $scan);
        $this->assertSame(1, $scan['version']);
        $this->assertFileExists(DATA_DIR . '/expiry_scan.json');
    }

    public function testExpiryLoadScanReturnsDefaultWhenMissing(): void
    {
        $scan = expiry_load_scan();
        $this->assertSame(1, $scan['version']);
        $this->assertSame([], $scan['rows']);
    }

    public function testExpiryLoadScanReadsSavedFile(): void
    {
        $data = ['version' => 1, 'last_scan_at' => '2025-01-01', 'rows' => [['name' => 'Test']]];
        file_put_contents(DATA_DIR . '/expiry_scan.json', json_encode($data));
        $scan = expiry_load_scan();
        $this->assertSame('2025-01-01', $scan['last_scan_at']);
        $this->assertCount(1, $scan['rows']);
    }
}
