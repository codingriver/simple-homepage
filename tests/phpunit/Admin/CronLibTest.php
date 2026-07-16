<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../admin/shared/cron_lib.php';

final class CronLibTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    public function testNormalizeWorkdirModeAlwaysReturnsTask(): void
    {
        $this->assertSame('task', task_normalize_workdir_mode('custom'));
        $this->assertSame('task', task_normalize_workdir_mode(''));
        $this->assertSame('task', task_normalize_workdir_mode(null));
    }

    public function testResolveWorkdirReturnsDefaultWorkdir(): void
    {
        $dir = task_resolve_workdir(['id' => 't1']);
        $this->assertSame(TASKS_WORKDIR_ROOT, $dir);
    }

    public function testNormalizeScriptContentsAddsTrailingNewline(): void
    {
        $script = task_normalize_script_contents('echo hello');
        $this->assertSame("echo hello\n", $script);
    }

    public function testNormalizeScriptContentsHandlesLineEndings(): void
    {
        $script = task_normalize_script_contents("line1\r\nline2\rline3\n");
        $this->assertSame("line1\nline2\nline3\n", $script);
    }

    public function testNormalizeScriptContentsHandlesDoubledBlankLines(): void
    {
        $input = "a\n\nb\n\nc\n\nd";
        $result = task_normalize_script_contents($input);
        $this->assertSame("a\nb\nc\nd\n", $result);
    }

    public function testRuntimeDefaults(): void
    {
        $this->assertSame('shell', task_normalize_runtime(''));
        $this->assertSame('nodejs', task_normalize_runtime('nodejs'));
        $this->assertSame('shell', task_normalize_runtime('unknown'));
        $this->assertSame('main.mjs', task_runtime_default_filename('nodejs'));
        $this->assertSame('main.py', task_runtime_default_filename('python'));
    }

    public function testRuntimeFromTaskIgnoresExecutionStateArray(): void
    {
        $task = [
            'runtime' => [
                'running' => false,
                'started_at' => '',
                'pid' => 123,
            ],
        ];

        $this->assertSame('shell', task_runtime_from_task($task));
        $this->assertSame('run.sh', task_resolve_script_filename(['id' => '1'] + $task));
    }

    public function testRuntimeFromTaskSupportsLegacyStringAndPrefersRuntimeType(): void
    {
        $this->assertSame('python', task_runtime_from_task(['runtime' => 'python']));
        $this->assertSame('nodejs', task_runtime_from_task([
            'runtime_type' => 'nodejs',
            'runtime' => ['running' => true],
        ]));
    }

    public function testNumericTaskRuntimeScriptFilenames(): void
    {
        $this->assertSame('run.sh', task_resolve_script_filename(['id' => '1', 'runtime_type' => 'shell']));
        $this->assertSame('main.php', task_resolve_script_filename(['id' => '1', 'runtime_type' => 'php']));
        $this->assertSame('main.py', task_resolve_script_filename(['id' => '1', 'runtime_type' => 'python']));
        $this->assertSame('main.mjs', task_resolve_script_filename(['id' => '1', 'runtime_type' => 'nodejs']));
    }

    public function testRetiredSystemTasksAreFilteredWithoutRemovingActiveTasks(): void
    {
        $result = scheduled_tasks_filter_retired([
            'tasks' => [
                ['id' => '1', 'name' => 'manual'],
                ['id' => 'sys_favicon_sync', 'name' => 'retired'],
                ['id' => DOMAIN_EXPIRY_SYNC_TASK_ID, 'name' => 'active system'],
            ],
        ]);

        $this->assertSame(['1', DOMAIN_EXPIRY_SYNC_TASK_ID], array_column($result['data']['tasks'], 'id'));
        $this->assertSame(['sys_favicon_sync'], array_column($result['removed'], 'id'));
    }

    public function testPruneRetiredSystemTasksPersistsDataAndDeletesArtifacts(): void
    {
        @mkdir(TASKS_WORKDIR_ROOT, 0777, true);
        file_put_contents(SCHEDULED_TASKS_FILE, json_encode([
            'tasks' => [
                ['id' => '1', 'name' => 'manual'],
                [
                    'id' => 'sys_favicon_sync',
                    'name' => 'retired',
                    'command' => '/usr/local/bin/php /var/www/riverops/cli/favicon_sync.php',
                ],
            ],
        ]));
        file_put_contents(task_script_file('sys_favicon_sync'), "echo retired\n");
        file_put_contents(task_log_path_from_filename(task_default_log_filename('sys_favicon_sync')), "failed\n");
        @mkdir(dirname(task_lock_file('sys_favicon_sync')), 0777, true);
        file_put_contents(task_lock_file('sys_favicon_sync'), '123');

        $result = scheduled_tasks_prune_retired();
        $saved = load_scheduled_tasks();

        $this->assertSame(1, $result['removed']);
        $this->assertSame(['1'], array_column($saved['tasks'], 'id'));
        $this->assertFileDoesNotExist(task_script_file('sys_favicon_sync'));
        $this->assertFileDoesNotExist(task_log_path_from_filename(task_default_log_filename('sys_favicon_sync')));
        $this->assertFileDoesNotExist(task_lock_file('sys_favicon_sync'));
    }
}
