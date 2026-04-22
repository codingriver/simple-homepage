<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class NotifyRuntimeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        @unlink(NOTIFICATIONS_FILE);
        @unlink(NOTIFY_LOG_FILE);
    }

    public function testNotifySendChannelWithInvalidUrl(): void
    {
        $channel = notify_channel_normalize([
            'name' => 'Bad',
            'type' => 'custom',
            'enabled' => true,
            'events' => ['task_failed'],
            'config' => ['webhook_url' => ''],
        ]);
        $result = notify_send_channel($channel, 'task_failed', ['task' => 'x']);
        $this->assertFalse($result['ok']);
    }

    public function testEventFilteringSkipsNonMatchingEvent(): void
    {
        notify_channel_upsert([
            'name' => 'OnlyFails',
            'type' => 'custom',
            'enabled' => true,
            'events' => ['task_failed'],
            'config' => ['webhook_url' => 'https://example.com/hook'],
        ]);
        notify_event('task_succeeded', ['task' => 'x']);
        $this->assertFileDoesNotExist(NOTIFY_LOG_FILE);
    }

    public function testCooldownSkipsRepeatedSend(): void
    {
        notify_channel_upsert([
            'name' => 'Cool',
            'type' => 'custom',
            'enabled' => true,
            'events' => ['task_failed'],
            'cooldown_seconds' => 3600,
            'config' => ['webhook_url' => 'https://example.com/hook'],
        ]);
        // First call marks last_sent regardless of HTTP result
        notify_event('task_failed', ['task' => 'x']);
        // Second call should be skipped due to cooldown
        notify_event('task_failed', ['task' => 'x']);
        $this->assertFileExists(NOTIFY_LOG_FILE);
        $log = file_get_contents(NOTIFY_LOG_FILE);
        $this->assertStringContainsString('skipped by cooldown', $log);
    }

    public function testCooldownAllowsAfterExpiration(): void
    {
        $channel = notify_channel_normalize([
            'name' => 'Cool',
            'type' => 'custom',
            'enabled' => true,
            'events' => ['task_failed'],
            'cooldown_seconds' => 1,
        ]);
        $channel['runtime']['last_sent']['task_failed'] = date('Y-m-d H:i:s', time() - 2);
        $this->assertTrue(notify_channel_is_due($channel, 'task_failed'));
    }

    public function testPayloadTelegram(): void
    {
        $channel = notify_channel_normalize([
            'name' => 'TG',
            'type' => 'telegram',
            'config' => ['bot_token' => 'abc123', 'chat_id' => '123456'],
        ]);
        $req = notify_build_request($channel, 'task_failed', ['task' => 'backup']);
        $this->assertStringContainsString('api.telegram.org/botabc123/sendMessage', $req['url']);
        $payload = json_decode($req['payload'], true);
        $this->assertSame('123456', $payload['chat_id'] ?? '');
        $this->assertStringContainsString('backup', $payload['text'] ?? '');
    }

    public function testPayloadFeishu(): void
    {
        $channel = notify_channel_normalize([
            'name' => 'FS',
            'type' => 'feishu',
            'config' => ['webhook_url' => 'https://open.feishu.cn/hook'],
        ]);
        $req = notify_build_request($channel, 'task_failed', ['task' => 'backup']);
        $this->assertSame('https://open.feishu.cn/hook', $req['url']);
        $payload = json_decode($req['payload'], true);
        $this->assertSame('text', $payload['msg_type'] ?? '');
        $this->assertStringContainsString('backup', $payload['content']['text'] ?? '');
    }

    public function testPayloadDingtalk(): void
    {
        $channel = notify_channel_normalize([
            'name' => 'DT',
            'type' => 'dingtalk',
            'config' => ['webhook_url' => 'https://oapi.dingtalk.com/robot'],
        ]);
        $req = notify_build_request($channel, 'task_succeeded', []);
        $payload = json_decode($req['payload'], true);
        $this->assertSame('text', $payload['msgtype'] ?? '');
        $this->assertStringContainsString('计划任务成功', $payload['text']['content'] ?? '');
    }

    public function testPayloadWecom(): void
    {
        $channel = notify_channel_normalize([
            'name' => 'WC',
            'type' => 'wecom',
            'config' => ['webhook_url' => 'https://qyapi.weixin.qq.com'],
        ]);
        $req = notify_build_request($channel, 'login_abnormal', []);
        $payload = json_decode($req['payload'], true);
        $this->assertSame('text', $payload['msgtype'] ?? '');
        $this->assertStringContainsString('登录异常', $payload['text']['content'] ?? '');
    }

    public function testPayloadCustom(): void
    {
        $channel = notify_channel_normalize([
            'name' => 'Custom',
            'type' => 'custom',
            'config' => ['webhook_url' => 'https://hook.example.com'],
        ]);
        $req = notify_build_request($channel, 'ddns_failed', ['domain' => 'example.com']);
        $this->assertSame('https://hook.example.com', $req['url']);
        $payload = json_decode($req['payload'], true);
        $this->assertSame('ddns_failed', $payload['event'] ?? '');
        $this->assertSame('example.com', $payload['payload']['domain'] ?? '');
    }
}
