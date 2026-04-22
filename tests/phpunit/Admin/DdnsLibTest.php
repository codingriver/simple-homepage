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
