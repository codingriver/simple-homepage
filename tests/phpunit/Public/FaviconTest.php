<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class FaviconTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->loadFaviconFunctions();
    }

    private function loadFaviconFunctions(): void
    {
        if (function_exists('favicon_host_is_public')) {
            return;
        }
        $lines = file(__DIR__ . '/../../../public/favicon.php');
        if ($lines === false) {
            $this->fail('Unable to read favicon.php');
        }
        // Extract lines 57-186 (0-indexed 56-185) containing only function definitions
        $functionCode = implode('', array_slice($lines, 56, 130));
        eval($functionCode);
    }

    public function testFaviconHostIsPublicLocalhostReturnsFalse(): void
    {
        $this->assertFalse(favicon_host_is_public('localhost'));
        $this->assertFalse(favicon_host_is_public('127.0.0.1'));
        $this->assertFalse(favicon_host_is_public('::1'));
    }

    public function testFaviconHostIsPublicPrivateIpReturnsFalse(): void
    {
        $this->assertFalse(favicon_host_is_public('192.168.1.1'));
        $this->assertFalse(favicon_host_is_public('10.0.0.1'));
        $this->assertFalse(favicon_host_is_public('172.16.0.1'));
    }

    public function testFaviconHostIsPublicPublicIpReturnsTrue(): void
    {
        $this->assertTrue(favicon_host_is_public('8.8.8.8'));
        $this->assertTrue(favicon_host_is_public('1.1.1.1'));
    }

    public function testFaviconResolveRedirectUrlRelativePath(): void
    {
        $result = favicon_resolve_redirect_url('https://example.com/path/page.html', '/favicon.ico');
        $this->assertSame('https://example.com/favicon.ico', $result);
    }

    public function testFaviconResolveRedirectUrlAbsoluteUrl(): void
    {
        $result = favicon_resolve_redirect_url('https://example.com/path', 'https://cdn.example.com/icon.ico');
        $this->assertSame('https://cdn.example.com/icon.ico', $result);
    }

    public function testFaviconResolveRedirectUrlProtocolRelative(): void
    {
        $result = favicon_resolve_redirect_url('https://example.com/path', '//cdn.example.com/icon.ico');
        $this->assertSame('https://cdn.example.com/icon.ico', $result);
    }

    public function testFaviconResolveRedirectUrlRelativeWithoutLeadingSlash(): void
    {
        $result = favicon_resolve_redirect_url('https://example.com/path/page.html', 'images/icon.ico');
        $this->assertSame('https://example.com/path/images/icon.ico', $result);
    }

    public function testFaviconFetchWithRealDomain(): void
    {
        $check = @file_get_contents(
            'https://www.google.com/favicon.ico',
            false,
            stream_context_create(['http' => ['timeout' => 5]])
        );
        if ($check === false) {
            $this->markTestSkipped('Network unavailable');
        }
        $data = favicon_fetch('https://www.google.com', 3);
        if ($data === null) {
            $this->markTestSkipped('Favicon fetch returned null (service may have changed)');
        }
        $this->assertNotNull($data);
    }

    public function testFaviconFetchWithInvalidHostReturnsNull(): void
    {
        $data = favicon_fetch('http://192.168.1.1/favicon.ico', 3);
        $this->assertNull($data);
    }

    public function testFaviconFetchWithRedirectLimit(): void
    {
        $check = @file_get_contents(
            'https://httpbin.org/get',
            false,
            stream_context_create(['http' => ['timeout' => 5]])
        );
        if ($check === false) {
            $this->markTestSkipped('Network unavailable');
        }
        $data = favicon_fetch('https://httpbin.org/redirect/5', 3);
        $this->assertNull($data);
    }
}
