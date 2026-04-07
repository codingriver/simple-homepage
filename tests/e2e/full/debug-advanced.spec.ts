import { test, expect } from '@playwright/test';
import { attachClientErrorTracking, loginAsDevAdmin } from '../../helpers/auth';

test('debug advanced flows cover display_errors toggles csrf rejection and clear log ajax', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
      /Failed to load resource: the server responded with a status of 403 \(Forbidden\)/,
    ],
  });

  await loginAsDevAdmin(page);
  await page.goto('/admin/debug.php');

  const toggleForm = page.locator('form:has(input[name="action"][value="toggle_display_errors"])');
  page.once('dialog', (dialog) => dialog.accept());
  await toggleForm.getByRole('button').click();
  await expect(page.locator('body')).toContainText(/display_errors 已开启|display_errors 已关闭/);

  const csrfMissing = await page.request.post('http://127.0.0.1:58080/admin/debug.php', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: { ajax: 'clear_log' },
  });
  expect(csrfMissing.status()).toBe(403);

  const csrf = await page.evaluate(() => (window as typeof window & { DEBUG_CSRF?: string }).DEBUG_CSRF || '');
  const clearRes = await page.request.post('http://127.0.0.1:58080/admin/debug.php', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: { ajax: 'clear_log', _csrf: csrf },
  });
  expect(clearRes.status()).toBe(200);
  expect(await clearRes.json()).toMatchObject({ ok: expect.any(Boolean) });

  await tracker.assertNoClientErrors();
});
