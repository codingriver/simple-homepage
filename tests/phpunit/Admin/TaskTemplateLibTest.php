<?php
declare(strict_types=1);

require_once __DIR__ . '/../../phpunit/bootstrap.php';

class TaskTemplateLibTest extends PHPUnit\Framework\TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanDataDir();
        require_once __DIR__ . '/../../../admin/shared/functions.php';
        require_once __DIR__ . '/../../../admin/shared/task_template_lib.php';
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
    }

    public function testDefaultDataContainsTemplates(): void
    {
        $data = task_template_default_data();
        $this->assertSame(1, $data['version']);
        $this->assertGreaterThan(0, count($data['templates']));
    }

    public function testLoadAllReturnsDefaultWhenMissing(): void
    {
        $data = task_template_load_all();
        $this->assertArrayHasKey('templates', $data);
        $this->assertGreaterThan(0, count($data['templates']));
    }

    public function testLoadAllReadsSavedFile(): void
    {
        $custom = ['version' => 1, 'templates' => [['id' => 'custom_tpl', 'name' => 'Custom']]];
        file_put_contents(DATA_DIR . '/task_templates.json', json_encode($custom));
        $data = task_template_load_all();
        $this->assertCount(1, $data['templates']);
        $this->assertSame('custom_tpl', $data['templates'][0]['id']);
    }

    public function testFindReturnsTemplate(): void
    {
        $tpl = task_template_find('tpl_ddns_check');
        $this->assertIsArray($tpl);
        $this->assertSame('DDNS 检查模板', $tpl['name']);
    }

    public function testFindReturnsNullForMissing(): void
    {
        $this->assertNull(task_template_find('nonexistent'));
    }

    public function testRenderCommandReplacesVariables(): void
    {
        $tpl = [
            'command_template' => 'echo {{name}} {{value}}',
            'variables' => [
                ['key' => 'name', 'label' => 'Name'],
                ['key' => 'value', 'label' => 'Value'],
            ],
        ];
        $result = task_template_render_command($tpl, ['name' => 'hello', 'value' => 'world']);
        $this->assertStringContainsString('echo hello world', $result);
    }

    public function testRenderCommandLeavesUnknownPlaceholders(): void
    {
        $tpl = [
            'command_template' => 'echo {{missing}}',
            'variables' => [],
        ];
        $result = task_template_render_command($tpl, []);
        $this->assertStringContainsString('echo {{missing}}', $result);
    }

    public function testValidateVarsReturnsNullWhenAllPresent(): void
    {
        $tpl = [
            'variables' => [
                ['key' => 'name', 'label' => 'Name', 'required' => true],
            ],
        ];
        $this->assertNull(task_template_validate_vars($tpl, ['name' => 'test']));
    }

    public function testValidateVarsReturnsErrorWhenMissing(): void
    {
        $tpl = [
            'variables' => [
                ['key' => 'name', 'label' => 'Name', 'required' => true],
            ],
        ];
        $error = task_template_validate_vars($tpl, []);
        $this->assertNotNull($error);
        $this->assertStringContainsString('Name', $error);
    }

    public function testValidateVarsSkipsNonRequired(): void
    {
        $tpl = [
            'variables' => [
                ['key' => 'name', 'label' => 'Name', 'required' => false],
            ],
        ];
        $this->assertNull(task_template_validate_vars($tpl, []));
    }

    public function testGroupedReturnsCategories(): void
    {
        $grouped = task_template_grouped();
        $this->assertIsArray($grouped);
        // Default templates have categories like 网络, 运维, 备份, 系统
        $this->assertGreaterThan(0, count($grouped));
    }
}
