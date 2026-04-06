import { test, expect } from '@playwright/test';
import { attachClientErrorTracking, loginAsDevAdmin, logout } from '../../helpers/auth';

test('session is required for admin pages after logout', async ({ page, context }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
    ],
  });

  await loginAsDevAdmin(page);
  await page.goto('/admin/settings.php');
  await expect(page.locator('.topbar-title')).toHaveText('系统设置');

  await logout(page);
  await page.goto('/admin/settings.php');
  await expect(page).toHaveURL(/login\.php\?redirect=/);
  await expect(page.getByText('请登录以继续')).toBeVisible();

  await context.clearCookies();
  await page.goto('/admin/index.php');
  await expect(page).toHaveURL(/login\.php\?redirect=/);

  await tracker.assertNoClientErrors();
});
