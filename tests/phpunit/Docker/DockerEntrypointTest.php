<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class DockerEntrypointTest extends TestCase
{
    public function testEntrypointScriptExistsAndIsExecutable(): void
    {
        $path = realpath(__DIR__ . '/../../../docker/entrypoint.sh');
        $this->assertFileExists($path);
        $this->assertTrue(is_executable($path), 'entrypoint.sh should be executable');
    }

    public function testRunitServicesReferenceAllProcesses(): void
    {
        $root = realpath(__DIR__ . '/../../../docker/runit');
        $this->assertIsString($root);

        $services = [
            'php-fpm' => '/usr/local/sbin/php-fpm --nodaemonize',
            'nginx' => '/usr/sbin/nginx -g "daemon off;"',
            'cron' => '/usr/sbin/crond -f',
        ];

        foreach ($services as $service => $expectedCommand) {
            $run = $root . '/' . $service . '/run';
            $this->assertFileExists($run);
            $this->assertTrue(is_executable($run), $service . ' run script should be executable');
            $content = file_get_contents($run);
            $this->assertStringContainsString($expectedCommand, $content);
            $this->assertStringNotContainsString('nginx-reload-watcher', $content);
        }
    }

    public function testNginxSiteConfContainsNavPortPlaceholder(): void
    {
        $content = file_get_contents(realpath(__DIR__ . '/../../../nginx-conf/docker-site.conf'));
        $this->assertStringContainsString('NAV_PORT', $content);
    }

    public function testNginxTemplatesAreNotEmpty(): void
    {
        $templates = glob(realpath(__DIR__ . '/../../../nginx-conf') . '/*.conf') ?: [];
        $this->assertNotEmpty($templates);
        foreach ($templates as $t) {
            $this->assertGreaterThan(0, filesize($t), basename($t) . ' should not be empty');
        }
    }
}
