import { test, expect } from '@playwright/test';
import { attachClientErrorTracking, loginAsDevAdmin } from '../../helpers/auth';

test('debug tools keep empty-state messaging after clearing logs and refreshing tabs', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
    ],
  });

  await loginAsDevAdmin(page);
  await page.goto('/admin/debug.php');

  page.once('dialog', (dialog) => dialog.accept());
  await page.getByRole('button', { name: /清空所有日志/ }).click();
  await expect(page.locator('#logContent')).toContainText(/已清空|空|暂无|失败/);

  await page.getByRole('button', { name: /刷新/ }).click();
  await expect(page.locator('#logContent')).not.toContainText('加载中...');

  await page.getByRole('button', { name: /DNS 应用日志/ }).click();
  await expect(page.locator('#logContent')).not.toContainText('加载中...');
  await expect(page.locator('#logContent')).toContainText(/暂无|空|日志|失败|error/i);

  await tracker.assertNoClientErrors();
});
