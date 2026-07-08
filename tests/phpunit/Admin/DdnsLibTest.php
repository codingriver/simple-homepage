<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../admin/shared/ddns_lib.php';

final class DdnsLibTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        @unlink(DDNS_TASKS_FILE);
        @unlink(DATA_DIR . '/logs/ddns.log');
        array_map('unlink', glob(DATA_DIR . '/logs/ddns_*.log') ?: []);
    }

    protected function tearDown(): void
    {
        @unlink(DDNS_TASKS_FILE);
        @unlink(DATA_DIR . '/logs/ddns.log');
        array_map('unlink', glob(DATA_DIR . '/logs/ddns_*.log') ?: []);
        parent::tearDown();
    }

    public function testNormalizeTaskFillsDefaultsAndFiltersInvalid(): void
    {
        $input = [
            'name' => 'Test',
            'source' => ['type' => 'bad_type', 'line' => 'bad_line', 'pick_strategy' => 'bad'],
            'target' => ['record_type' => 'MX', 'ttl' => -5],
            'schedule' => ['cron' => ''],
        ];
        $task = ddns_normalize_task($input);
        $this->assertSame('local_ipv4', $task['source']['type']);
        $this->assertSame('CT', $task['source']['line']);
        $this->assertSame('best_score', $task['source']['pick_strategy']);
        $this->assertSame('A', $task['target']['record_type']);
        $this->assertSame(120, $task['target']['ttl']);
        $this->assertSame('', $task['schedule']['cron']);
    }

    public function testNormalizeTaskKeepsWetestSourceAndFallback(): void
    {
        $task = ddns_normalize_task([
            'name' => 'Wetest',
            'source' => [
                'type' => 'wetest_cfip',
                'line' => 'CU',
                'fallback_type' => 'api4ce_cfip',
            ],
        ]);

        $this->assertSame('wetest_cfip', $task['source']['type']);
        $this->assertSame('CU', $task['source']['line']);
        $this->assertSame('api4ce_cfip', $task['source']['fallback_type']);
    }

    public function testParseWetestHtmlFiltersLineAndIpv4(): void
    {
        $html = <<<'HTML'
<table>
  <tr>
    <td data-label="线路名称">中国电信</td>
    <td data-label="优选地址">1.1.1.1</td>
    <td data-label="数据中心">SJC</td>
  </tr>
  <tr>
    <td data-label="线路名称">中国联通</td>
    <td data-label="优选地址">2.2.2.2</td>
    <td data-label="数据中心">LAX</td>
  </tr>
</table>
HTML;

        $rows = ddns_parse_wetest_cfip_html($html, 'CT', 'A');

        $this->assertCount(1, $rows);
        $this->assertSame('1.1.1.1', $rows[0]['ip']);
        $this->assertSame('SJC', $rows[0]['colo']);
        $this->assertSame('CT', $rows[0]['line']);
    }

    public function testParseWetestHtmlSupportsIpv6ForAaaa(): void
    {
        $html = <<<'HTML'
<table>
  <tr>
    <td data-label="线路名称">移动</td>
    <td data-label="优选地址">2606:4700:4700::1111</td>
    <td data-label="数据中心">HKG</td>
  </tr>
</table>
HTML;

        $rows = ddns_parse_wetest_cfip_html($html, 'CM', 'AAAA');

        $this->assertCount(1, $rows);
        $this->assertSame('2606:4700:4700::1111', $rows[0]['ip']);
        $this->assertSame('HKG', $rows[0]['colo']);
    }

    public function testTaskLogPageEmptyLog(): void
    {
        $page = ddns_task_log_page('nonexistent_id', 1);
        $this->assertSame([], $page['lines']);
        $this->assertSame(0, $page['total']);
        $this->assertSame(1, $page['page']);
        $this->assertSame(0, $page['pages']);
    }

    public function testTaskLogPagePaginationAndOutOfBounds(): void
    {
        $id = 'ddns_test_pag';
        $logFile = ddns_task_log_file($id);
        $lines = [];
        for ($i = 1; $i <= 250; $i++) {
            $lines[] = 'Line ' . $i;
        }
        file_put_contents($logFile, implode("\n", $lines) . "\n");

        $page1 = ddns_task_log_page($id, 1);
        $this->assertSame(250, $page1['total']);
        $this->assertSame(3, $page1['pages']);
        $this->assertSame(1, $page1['page']);
        $this->assertCount(100, $page1['lines']);
        $this->assertSame('Line 250', $page1['lines'][0]);

        $page99 = ddns_task_log_page($id, 99);
        $this->assertSame(3, $page99['page']);

        @unlink($logFile);
    }

    public function testTailLinesWithSkipFromEnd(): void
    {
        $tmpFile = DATA_DIR . '/logs/ddns_tail_test.log';
        $content = implode("\n", range(1, 50));
        file_put_contents($tmpFile, $content);

        $tail = ddns_tail_lines($tmpFile, 5, 10);
        $this->assertCount(5, $tail);
        $this->assertSame('36', $tail[0]);
        $this->assertSame('40', $tail[4]);

        @unlink($tmpFile);
    }

    public function testLoadAndSaveTasksPersistence(): void
    {
        $data = [
            'version' => 1,
            'tasks' => [
                ['id' => 't1', 'name' => 'Task 1'],
            ],
        ];
        ddns_save_tasks($data);
        $loaded = ddns_load_tasks();
        $this->assertSame(1, $loaded['version']);
        $this->assertCount(1, $loaded['tasks']);
        $this->assertSame('t1', $loaded['tasks'][0]['id']);
    }

    public function testLoadTasksMissingReturnsDefault(): void
    {
        $loaded = ddns_load_tasks();
        $this->assertSame(['version' => 1, 'tasks' => []], $loaded);
    }
}
