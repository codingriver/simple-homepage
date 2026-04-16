import { test, expect } from '../../helpers/fixtures';
import { attachClientErrorTracking, clickAdminNav, loginAsDevAdmin, submitVisibleModal } from '../../helpers/auth';

test('admin dashboard shows stats and quick actions reflect created data', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
    ],
    ignoredFailedRequests: [
      /GET .*\/admin\/groups\.php :: net::ERR_ABORTED/,
    ],
  });
  const ts = Date.now();
  const gid = `dash-group-${ts}`;
  const sid = `dash-site-${ts}`;

  await loginAsDevAdmin(page);
  await page.goto('/admin/index.php');
  await expect(page.locator('.stat-grid')).toBeVisible();
  await expect(page.locator('.quick-actions')).toBeVisible();

  await clickAdminNav(page, /分组管理/);
  await page.getByRole('button', { name: /添加分组/ }).click();
  await page.locator('#fi_id').fill(gid);
  await page.locator('#fi_name').fill(`控制台分组 ${ts}`);
  await page.locator('#fi_auth').selectOption('0');
  await submitVisibleModal(page);

  await clickAdminNav(page, /站点管理/);
  await page.getByRole('button', { name: /添加站点/ }).click();
  await page.locator('#fi_sid').fill(sid);
  await page.locator('#fi_name').fill('控制台站点');
  await page.locator('#fi_gid').selectOption(gid);
  await page.locator('#fi_type').selectOption('external');
  await page.locator('#fi_url').fill('https://example.com/dashboard');
  await submitVisibleModal(page);

  await page.goto('/admin/index.php');
  await expect(page.locator('.stat-card')).toHaveCount(4);
  await expect(page.locator('body')).toContainText('站点数量');
  await expect(page.locator('body')).toContainText('分组数量');

  await page.locator('.quick-actions').getByRole('link', { name: /系统设置/ }).click();
  await expect(page).toHaveURL(/admin\/settings\.php/);
  await page.goto('/admin/index.php');
  await page.getByRole('link', { name: /查看前台/ }).click();

  await tracker.assertNoClientErrors();
});
