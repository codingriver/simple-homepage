import { test, expect } from '../../helpers/fixtures';
import { loginAsDevAdmin, clickAdminNav } from '../../helpers/auth';

test('admin dashboard navigation opens the logs center', async ({ page }) => {
  await loginAsDevAdmin(page);
  await page.goto('/admin/index.php');
  await clickAdminNav(page, /日志中心/);
  await page.waitForURL(/admin\/logs\.php/, { timeout: 5000 });
  await expect(page.locator('body')).toContainText('日志中心');
});
