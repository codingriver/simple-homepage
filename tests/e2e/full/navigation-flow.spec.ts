import { test, expect } from '@playwright/test';
import { attachClientErrorTracking, clickAdminNav, loginAsDevAdmin } from '../../helpers/auth';

test('admin can navigate through core admin pages and return to homepage', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
    ],
  });

  await loginAsDevAdmin(page);

  await page.goto('/admin/index.php');
  await expect(page.locator('.topbar-title')).toHaveText('控制台');

  await clickAdminNav(page, /站点管理/);
  await expect(page).toHaveURL(/admin\/sites\.php/);
  await expect(page.locator('.topbar-title')).toHaveText('站点管理');

  await clickAdminNav(page, /分组管理/);
  await expect(page).toHaveURL(/admin\/groups\.php/);
  await expect(page.locator('.topbar-title')).toHaveText('分组管理');

  await clickAdminNav(page, /系统设置/);
  await expect(page).toHaveURL(/admin\/settings\.php/);
  await expect(page.locator('.topbar-title')).toHaveText('系统设置');

  await clickAdminNav(page, /返回首页/);
  await expect(page).toHaveURL(/index\.php|\/$/);
  await expect(page.locator('body')).toContainText('退出');

  await tracker.assertNoClientErrors();
});
