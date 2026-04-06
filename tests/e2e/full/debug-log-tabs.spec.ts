import { test, expect } from '@playwright/test';
import { attachClientErrorTracking, loginAsDevAdmin } from '../../helpers/auth';

test('debug log viewer covers tabs line filters and invalid ajax fallback', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
    ],
  });

  await loginAsDevAdmin(page);
  await page.goto('/admin/debug.php');

  await page.locator('#logLines').selectOption('50');
  await page.getByRole('button', { name: /刷新/ }).click();
  await expect(page.locator('#logContent')).not.toContainText('加载中');

  for (const label of ['Nginx 错误日志', 'PHP-FPM 日志', 'DNS 应用日志', 'DNS Python 错误日志']) {
    await page.getByRole('button', { name: label }).click();
    await expect(page.locator('#logContent')).toBeVisible();
  }

  const invalid = await page.request.get('http://127.0.0.1:58080/admin/debug.php?ajax=log&type=invalid&lines=999', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
  });
  expect(invalid.status()).toBe(200);
  expect(await invalid.text()).toBeTruthy();

  await tracker.assertNoClientErrors();
});
