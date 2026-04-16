import { test, expect } from '../../helpers/fixtures';
import { loginAsDevAdmin, clickAdminNav } from '../../helpers/auth';

test('debug admin dashboard group add', async ({ page }) => {
  await loginAsDevAdmin(page);
  await page.goto('/admin/index.php');
  await clickAdminNav(page, /分组管理/);
  await page.waitForURL(/admin\/groups\.php/, { timeout: 5000 });
  await page.getByRole('button', { name: /添加分组/ }).click();
  await expect(page.locator('#modal')).toBeVisible({ timeout: 5000 });
  await page.locator('#fi_id').fill('test-group-123');
});
