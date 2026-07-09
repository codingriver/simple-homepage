<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../admin/shared/runtime_env_lib.php';

final class RuntimeEnvLibTest extends TestCase
{
    public function testNormalizeNodeVersion(): void
    {
        $this->assertSame('22.20.0', runtime_env_normalize_node_version('v22.20.0'));
        $this->assertSame('24.18.0', runtime_env_normalize_node_version('24.18.0'));
        $this->assertSame('', runtime_env_normalize_node_version('22'));
        $this->assertSame('', runtime_env_normalize_node_version('../22.20.0'));
    }

    public function testNodeVersionDirStaysUnderRuntimeRoot(): void
    {
        $dir = runtime_env_node_version_dir('22.20.0');
        $this->assertStringStartsWith(RUNTIME_NODE_VERSIONS_DIR, $dir);
        $this->assertStringEndsWith('/22.20.0', str_replace('\\', '/', $dir));
        $this->assertSame('', runtime_env_node_version_dir('../bad'));
    }

    public function testNodePlatformUsesMuslSuffix(): void
    {
        $this->assertStringStartsWith('linux-', runtime_env_node_platform());
        $this->assertStringEndsWith('-musl', runtime_env_node_platform());
    }

    public function testRuntimeJobStateAndLogPayload(): void
    {
        $jobId = 'phpunit-job';
        runtime_env_job_write($jobId, [
            'status' => 'running',
            'phase' => '下载',
            'percent' => 42,
            'message' => '正在下载',
        ]);
        runtime_env_job_append_log($jobId, 'line one');

        $payload = runtime_env_job_public_payload($jobId);
        $this->assertIsArray($payload);
        $this->assertSame('phpunit-job', $payload['id']);
        $this->assertSame('running', $payload['status']);
        $this->assertSame(42, $payload['percent']);
        $this->assertStringContainsString('line one', $payload['log']);
    }
}
