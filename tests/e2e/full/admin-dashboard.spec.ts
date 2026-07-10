import { test, expect } from '../../helpers/fixtures';
import { attachClientErrorTracking, loginAsDevAdmin } from '../../helpers/auth';

test('admin dashboard shows current stats and quick actions', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page);
  await loginAsDevAdmin(page);
  await page.goto('/admin/index.php');
  await expect(page.locator('.stat-grid')).toBeVisible();
  await expect(page.locator('.quick-actions')).toBeVisible();
  await expect(page.locator('.stat-card')).toHaveCount(4);
  await expect(page.locator('body')).toContainText('账户数量');
  await expect(page.locator('body')).toContainText('管理员数量');
  await expect(page.locator('body')).toContainText('备份记录');
  await expect(page.locator('body')).toContainText('域名紧急');

  await page.locator('.quick-actions').getByRole('link', { name: /系统设置/ }).click();
  await expect(page).toHaveURL(/admin\/settings\.php/);

  await tracker.assertNoClientErrors();
});
