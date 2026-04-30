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

    public function testSupervisordConfReferencesAllProcesses(): void
    {
        $content = file_get_contents(realpath(__DIR__ . '/../../../docker/supervisord.conf'));
        $this->assertStringContainsString('php-fpm', $content);
        $this->assertStringContainsString('nginx', $content);
        $this->assertStringContainsString('nginx-reload-watcher', $content);
        $this->assertStringContainsString('cron', $content);
    }

    public function testNginxSiteConfContainsNavPortPlaceholder(): void
    {
        $content = file_get_contents(realpath(__DIR__ . '/../../../nginx-conf/docker-site.conf'));
        $this->assertStringContainsString('NAV_PORT', $content);
    }

    public function testNginxTemplateFilesExist(): void
    {
        $templates = [
            'proxy-params-full.conf',
            'proxy-params-simple.conf',
            'proxy-template-domain.conf',
            'proxy-template-path.conf',
        ];
        foreach ($templates as $t) {
            $this->assertFileExists(realpath(__DIR__ . "/../../../nginx-conf/{$t}"), "Template {$t} should exist");
        }
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
