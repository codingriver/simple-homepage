import { test, expect } from '@playwright/test';
import { attachClientErrorTracking } from '../../helpers/auth';

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
