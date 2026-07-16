import { test, expect } from '../../helpers/fixtures';
import { attachClientErrorTracking, loginAsDevAdmin } from '../../helpers/auth';
import { runDockerPhpInline } from '../../helpers/cli';

test('auth verify endpoint is not publicly exposed to anonymous browser requests', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
      /Failed to load resource: the server responded with a status of 404 \(Not Found\)/,
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
    ],
  });

  await page.goto('/login.php');
  const result = await page.evaluate(async () => {
    const res = await fetch('/auth/verify.php', { credentials: 'include' });
    return {
      status: res.status,
      user: res.headers.get('x-auth-user'),
      role: res.headers.get('x-auth-role'),
    };
  });

  expect([401, 404]).toContain(result.status);
  expect(result.user).toBeNull();
  expect(result.role).toBeNull();

  await tracker.assertNoClientErrors();
});

test('auth verify endpoint returns authenticated user headers after login', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
      /Failed to load resource: the server responded with a status of 404 \(Not Found\)/,
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
    ],
  });

  await loginAsDevAdmin(page);

  const result = runDockerPhpInline(`
require_once '/var/www/riverops/shared/auth.php';
register_shutdown_function(function () {
    $payload = auth_get_current_user();
    echo json_encode([
        'status' => http_response_code(),
        'headers' => headers_list(),
        'payload_user' => is_array($payload) ? ($payload['username'] ?? '') : '',
        'payload_role' => is_array($payload) ? ($payload['role'] ?? '') : '',
    ], JSON_UNESCAPED_UNICODE);
});
$_COOKIE['riverops_session'] = auth_generate_token('e2e-auth-verify', 'admin', false);
$_SERVER['HTTP_HOST'] = '127.0.0.1:58080';
$_SERVER['REQUEST_METHOD'] = 'GET';
include '/var/www/riverops/public/auth/verify.php';
  `);
  expect(result.code, result.output).toBe(0);
  const payload = JSON.parse(result.stdout.trim()) as {
    status: number;
    headers: string[];
    payload_user: string;
    payload_role: string;
  };
  expect(payload.status).toBe(200);
  expect(payload.payload_user).toBe('e2e-auth-verify');
  expect(payload.payload_role).toBe('admin');

  await tracker.assertNoClientErrors();
});
