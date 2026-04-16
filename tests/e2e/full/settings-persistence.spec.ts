import { test, expect } from '../../helpers/fixtures';
import { attachClientErrorTracking, loginAsDevAdmin, logout } from '../../helpers/auth';

test('settings persist after refresh and re-login', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
    ],
    ignoredFailedRequests: [/favicon\.php/],
  });
  const siteName = `持久化设置 ${Date.now()}`;
  const bgColor = '#223344';

  await loginAsDevAdmin(page);
  await page.goto('/admin/settings.php');

  await page.locator('input[name="site_name"]').fill(siteName);
  await page.locator('input[name="bg_color"]').fill(bgColor);
  await page.locator('select[name="card_layout"]').selectOption('large');
  await page.locator('select[name="card_direction"]').selectOption('col-center');
  await page.getByRole('button', { name: /保存设置/ }).click();
  await expect(page.locator('body')).toContainText('设置已保存');

  await page.reload();
  await expect(page.locator('input[name="site_name"]')).toHaveValue(siteName);
  await expect(page.locator('input[name="bg_color"]')).toHaveValue(bgColor);
  await expect(page.locator('select[name="card_layout"]')).toHaveValue('large');
  await expect(page.locator('select[name="card_direction"]')).toHaveValue('col-center');

  await logout(page);
  await loginAsDevAdmin(page);
  await page.goto('/admin/settings.php');
  await expect(page.locator('input[name="site_name"]')).toHaveValue(siteName);
  await expect(page.locator('input[name="bg_color"]')).toHaveValue(bgColor);
  await expect(page.locator('select[name="card_layout"]')).toHaveValue('large');
  await expect(page.locator('select[name="card_direction"]')).toHaveValue('col-center');

  await page.goto('/index.php');
  await expect(page).toHaveTitle(new RegExp(siteName));

  await tracker.assertNoClientErrors();
});
