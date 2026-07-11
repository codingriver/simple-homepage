import { test, expect } from '../../helpers/fixtures';
import { attachClientErrorTracking, loginAsDevAdmin, logout } from '../../helpers/auth';

test('settings persist after refresh and re-login', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
    ],
  });
  const siteName = `持久化设置 ${Date.now()}`;

  await loginAsDevAdmin(page);
  await page.goto('/admin/settings.php');

  await page.locator('input[name="site_name"]').fill(siteName);
  await page.locator('input[name="token_expire_hours"]').fill('9');
  await page.locator('select[name="nginx_access_log_enabled"]').selectOption('0');
  await page.locator('select[name="theme"]').selectOption('auto');
  await page.getByRole('button', { name: /保存设置/ }).click();
  await expect(page.locator('body')).toContainText('设置已保存');

  await page.reload();
  await expect(page.locator('input[name="site_name"]')).toHaveValue(siteName);
  await expect(page.locator('input[name="token_expire_hours"]')).toHaveValue('9');
  await expect(page.locator('select[name="nginx_access_log_enabled"]')).toHaveValue('0');
  await expect(page.locator('select[name="theme"]')).toHaveValue('auto');

  await logout(page);
  await loginAsDevAdmin(page);
  await page.goto('/admin/settings.php');
  await expect(page.locator('input[name="site_name"]')).toHaveValue(siteName);
  await expect(page.locator('input[name="token_expire_hours"]')).toHaveValue('9');
  await expect(page.locator('select[name="nginx_access_log_enabled"]')).toHaveValue('0');
  await expect(page.locator('select[name="theme"]')).toHaveValue('auto');

  await page.goto('/index.php');
  await expect(page).toHaveURL(/admin\/index\.php/);

  await tracker.assertNoClientErrors();
});
