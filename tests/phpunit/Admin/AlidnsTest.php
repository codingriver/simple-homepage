<?php
declare(strict_types=1);

require_once __DIR__ . '/../../phpunit/bootstrap.php';

class AlidnsTest extends PHPUnit\Framework\TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../../../admin/shared/alidns.php';
    }

    public function testPercentEncode(): void
    {
        $this->assertSame('hello', alidns_percent_encode('hello'));
        $this->assertSame('%20', alidns_percent_encode(' '));
        $this->assertSame('%2A', alidns_percent_encode('*'));
        $this->assertSame('~', alidns_percent_encode('~'));
        $this->assertSame('%2F', alidns_percent_encode('/'));
    }

    public function testAliyunDnsClientWrapSimpleSuccess(): void
    {
        $client = new AliyunDnsClient('test', 'secret');
        $method = new ReflectionMethod($client, 'wrapSimple');
        $method->setAccessible(true);

        $result = $method->invoke($client, ['ok' => true, 'json' => []], '添加');
        $this->assertTrue($result['ok']);
        $this->assertSame('添加成功', $result['msg']);
    }

    public function testAliyunDnsClientWrapSimpleError(): void
    {
        $client = new AliyunDnsClient('test', 'secret');
        $method = new ReflectionMethod($client, 'wrapSimple');
        $method->setAccessible(true);

        $result = $method->invoke($client, ['ok' => false, 'err' => 'Network error'], '添加');
        $this->assertFalse($result['ok']);
        $this->assertSame('Network error', $result['msg']);
    }

    public function testAliyunDnsClientWrapSimpleApiError(): void
    {
        $client = new AliyunDnsClient('test', 'secret');
        $method = new ReflectionMethod($client, 'wrapSimple');
        $method->setAccessible(true);

        $result = $method->invoke($client, [
            'ok' => true,
            'json' => ['Code' => 'InvalidDomain', 'Message' => 'Bad domain'],
        ], '添加');
        $this->assertFalse($result['ok']);
        $this->assertSame('Bad domain', $result['msg']);
    }

    public function testDescribeDomainRecordsReturnsErrorOnRequestFailure(): void
    {
        $client = new AliyunDnsClient('test', 'secret');
        $result = $client->describeDomainRecords('example.com');
        $this->assertArrayHasKey('ok', $result);
        $this->assertArrayHasKey('records', $result);
        $this->assertArrayHasKey('msg', $result);
    }
}
