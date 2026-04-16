import { test, expect } from '../../helpers/fixtures';
import { attachClientErrorTracking, loginAsDevAdmin, logout } from '../../helpers/auth';

test('debug tools page supports display errors toggle and clear cookie', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
      /Failed to load resource: the server responded with a status of 502 \(Bad Gateway\)/,
    ],
    ignoredFailedRequests: [
      /GET .*\/favicon\.php\?url=.*:: net::ERR_ABORTED/,
    ],
  });

  await loginAsDevAdmin(page);
  await page.goto('/admin/debug.php');

  await expect(page.locator('#build-meta')).toBeVisible();
  await page.getByRole('button', { name: /display_errors/i }).click().catch(() => {});

  const toggleForm = page.locator('form:has(input[name="action"][value="toggle_display_errors"])');
  page.once('dialog', dialog => dialog.accept());
  await toggleForm.getByRole('button').click();
  await expect(page.locator('body')).toContainText(/display_errors 已开启|display_errors 已关闭/);

  page.once('dialog', dialog => dialog.accept());
  await page.getByRole('button', { name: /清除当前 Cookie/ }).click();
  await expect(page).toHaveURL(/login\.php/);
  await expect(page.locator('input[name="username"]')).toBeVisible();

  await tracker.assertNoClientErrors();
});

test('logs api access is denied after logout', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 403 \(Forbidden\)/,
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
    ],
  });

  await loginAsDevAdmin(page);
  await logout(page);

  const denied = await page.request.get('http://127.0.0.1:58080/admin/logs_api.php?action=read&type=dns&offset=0&limit=20', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
  });
  expect(denied.status()).toBe(403);
  expect(await denied.text()).toContain('未登录');

  await tracker.assertNoClientErrors();
});
