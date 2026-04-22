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
}
