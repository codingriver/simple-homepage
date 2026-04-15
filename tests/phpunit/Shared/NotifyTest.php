<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class NotifyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        @unlink(NOTIFICATIONS_FILE);
        @unlink(NOTIFY_LOG_FILE);
    }

    public function testChannelCrudLifecycle(): void
    {
        $result = notify_channel_upsert([
            'name' => 'TestChannel',
            'type' => 'custom',
            'enabled' => true,
            'events' => ['task_failed'],
            'config' => ['webhook_url' => 'https://example.com/hook'],
        ]);
        $this->assertTrue($result['ok']);
        $id = $result['channel']['id'];

        $data = notify_load_data();
        $found = notify_channel_find($data, $id);
        $this->assertNotNull($found);
        $this->assertSame('TestChannel', $found['name']);

        $toggle = notify_channel_toggle($id);
        $this->assertFalse($toggle);

        $deleted = notify_channel_delete($id);
        $this->assertTrue($deleted);
    }

    public function testChannelValidationRejectsEmptyName(): void
    {
        $result = notify_channel_upsert([
            'name' => '',
            'type' => 'custom',
            'enabled' => true,
            'events' => ['task_failed'],
        ]);
        $this->assertFalse($result['ok']);
    }

    public function testCooldownPreventsRepeatedSends(): void
    {
        $channel = notify_channel_normalize([
            'name' => 'Cool',
            'type' => 'custom',
            'enabled' => true,
            'events' => ['task_failed'],
            'cooldown_seconds' => 300,
        ]);
        $channel['runtime']['last_sent']['task_failed'] = date('Y-m-d H:i:s');
        $this->assertFalse(notify_channel_is_due($channel, 'task_failed'));
    }

    public function testBuildTextAndRequestPayload(): void
    {
        $text = notify_build_text('task_failed', ['task' => 'backup']);
        $this->assertStringContainsString('计划任务失败', $text);
        $this->assertStringContainsString('backup', $text);

        $channel = notify_channel_normalize([
            'name' => 'X',
            'type' => 'custom',
            'config' => ['webhook_url' => 'https://hook.example.com'],
        ]);
        $req = notify_build_request($channel, 'task_failed', ['task' => 'backup']);
        $this->assertSame('https://hook.example.com', $req['url']);
        $this->assertStringContainsString('task_failed', $req['payload']);
    }

    public function testLogWriteCreatesFile(): void
    {
        notify_log_write('hello', ['a' => 1]);
        $this->assertFileExists(NOTIFY_LOG_FILE);
        $content = file_get_contents(NOTIFY_LOG_FILE);
        $this->assertStringContainsString('hello', $content);
    }
}
