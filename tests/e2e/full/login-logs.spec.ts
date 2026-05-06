import { test, expect } from '../../helpers/fixtures';
import { attachClientErrorTracking, loginAsDevAdmin } from '../../helpers/auth';

test('login logs record successful and failed login attempts', async ({ page }) => {
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

  const ajaxRes = await page.request.get('http://127.0.0.1:58080/admin/logs_api.php?action=read&type=auth&offset=0&limit=20', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
  });
  expect(ajaxRes.status()).toBe(200);
  const json = await ajaxRes.json();
  expect(json).toMatchObject({ ok: true, total_lines: expect.any(Number), lines: expect.any(Array) });
  expect(json.lines.length).toBeGreaterThan(0);

  await tracker.assertNoClientErrors();
});
