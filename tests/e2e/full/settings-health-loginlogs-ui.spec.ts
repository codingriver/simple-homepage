import { test, expect } from '@playwright/test';
import { attachClientErrorTracking, loginAsDevAdmin } from '../../helpers/auth';

test('settings health panel and login logs panel load through UI interactions', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
    ],
  });

  await loginAsDevAdmin(page);
  await page.goto('/admin/settings.php#logs');
  await expect(page.locator('#logs_lazy_state')).toContainText(/自动加载|加载/);
  await page.locator('#logs').scrollIntoViewIfNeeded();
  await page.waitForTimeout(1200);
  await expect(page.locator('#logs_total_label')).not.toHaveText('');

  await page.locator('#health').scrollIntoViewIfNeeded();
  await page.getByRole('button', { name: /刷新缓存状态/ }).click();
  await expect(page.locator('#health_empty, #health_results')).toBeVisible();

  await page.getByRole('button', { name: /立即检测所有站点/ }).click();
  await expect(page.locator('#health_empty, #health_results')).toBeVisible();

  await tracker.assertNoClientErrors();
});
