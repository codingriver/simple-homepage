<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class SessionManagementTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        auth_reset_config_cache();
        @unlink(USERS_FILE);
        @unlink(CONFIG_FILE);
        @unlink(AUTH_SECRET_FILE);
        @unlink(IP_LOCKS_FILE);
        @unlink(AUTH_LOG_FILE);
        @unlink(SESSIONS_FILE);
        @unlink(INSTALLED_FLAG);
        $GLOBALS['_SERVER']['REMOTE_ADDR'] = '127.0.0.1';
    }

    public function testTokenGenerationRegistersSession(): void
    {
        auth_ensure_secret_key();
        $token = auth_generate_token('admin1', 'admin');
        $payload = auth_verify_token($token);
        $this->assertIsArray($payload);

        $list = auth_session_list('admin1');
        $this->assertCount(1, $list);
        $this->assertSame('admin1', $list[0]['username']);
    }

    public function testSessionRevokeInvalidatesToken(): void
    {
        auth_ensure_secret_key();
        $token = auth_generate_token('admin1', 'admin');
        $payload = auth_verify_token($token);
        $this->assertIsArray($payload);

        $jti = $payload['jti'];
        $this->assertTrue(auth_session_revoke($jti));
        $this->assertFalse(auth_session_exists($jti));
        $this->assertFalse(auth_verify_token($token));
    }

    public function testSessionListFiltersByUsername(): void
    {
        auth_ensure_secret_key();
        auth_generate_token('u1', 'user');
        auth_generate_token('u2', 'admin');

        $this->assertCount(1, auth_session_list('u1'));
        $this->assertCount(2, auth_session_list());
    }

    public function testSessionExistsWaitsForLockedWriter(): void
    {
        auth_ensure_secret_key();
        $token = auth_generate_token('admin1', 'admin');
        $payload = auth_verify_token($token);
        $this->assertIsArray($payload);
        $jti = (string)$payload['jti'];
        $sessionsJson = (string)file_get_contents(SESSIONS_FILE);

        $fp = fopen(SESSIONS_FILE, 'c+');
        $this->assertIsResource($fp);
        $this->assertTrue(flock($fp, LOCK_EX));
        ftruncate($fp, 0);
        rewind($fp);

        $root = dirname(__DIR__, 3);
        $childCode = sprintf(
            'define("DATA_DIR", %s); require %s; echo auth_session_exists(%s) ? "1" : "0";',
            var_export(DATA_DIR, true),
            var_export($root . '/shared/auth.php', true),
            var_export($jti, true)
        );
        $process = proc_open(
            [PHP_BINARY, '-r', $childCode],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes
        );
        $this->assertIsResource($process);
        fclose($pipes[0]);

        usleep(200000);
        fwrite($fp, $sessionsJson);
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        $this->assertSame(0, $exitCode, $stderr);
        $this->assertSame('1', $stdout);
    }

    public function testSessionTouchSkipsRecentUpdates(): void
    {
        auth_ensure_secret_key();
        $token = auth_generate_token('admin1', 'admin');
        $payload = auth_verify_token($token);
        $this->assertIsArray($payload);
        $jti = (string)$payload['jti'];

        $before = auth_sessions_read_locked();
        $this->assertArrayHasKey($jti, $before);
        $lastActive = $before[$jti]['last_active'];

        auth_session_touch($jti);

        $after = auth_sessions_read_locked();
        $this->assertSame($lastActive, $after[$jti]['last_active']);
    }
}
