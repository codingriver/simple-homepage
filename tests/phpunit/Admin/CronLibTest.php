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
}
