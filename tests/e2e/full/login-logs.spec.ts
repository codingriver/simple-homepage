import { test, expect } from '@playwright/test';
import { attachClientErrorTracking, loginAsDevAdmin } from '../../helpers/auth';

test('login logs record successful and failed login attempts and enforce ajax guard', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
      /Failed to load resource: the server responded with a status of 403 \(Forbidden\)/,
    ],
  });

  await page.goto('/login.php');
  await page.locator('input[name="username"]').fill('admin');
  await page.locator('input[name="password"]').fill('definitely-wrong-password');
  await page.getByRole('button', { name: /登\s*录/ }).click();
  await expect(page.locator('body')).toContainText(/登录|错误|失败/);

  await loginAsDevAdmin(page);

  const ajaxRes = await page.request.get('http://127.0.0.1:58080/admin/login_logs.php', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
  });
  expect(ajaxRes.status()).toBe(200);
  const json = await ajaxRes.json();
  expect(json).toMatchObject({ ok: true, total: expect.any(Number), rows: expect.any(Array), max: expect.any(Number) });
  expect(json.rows.length).toBeGreaterThan(0);

  const plainRes = await page.request.get('http://127.0.0.1:58080/admin/login_logs.php');
  expect(plainRes.status()).toBe(400);

  await tracker.assertNoClientErrors();
});
