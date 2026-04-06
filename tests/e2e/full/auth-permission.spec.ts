import { test, expect } from '@playwright/test';
import { attachClientErrorTracking, loginAsDevAdmin, logout } from '../../helpers/auth';

test('non-admin user cannot access admin-only pages', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
      /Failed to load resource: the server responded with a status of 403 \(Forbidden\)/,
      /Pattern attribute value \[a-zA-Z0-9_-\]\{2,32\} is not a valid regular expression/,
    ],
    ignoredFailedRequests: [
      /GET .*\/favicon\.php\?url=https%3A%2F%2Fexample\.com :: net::ERR_ABORTED/,
    ],
  });
  const username = `user${Date.now()}`;
  const password = 'User@test2026';

  await loginAsDevAdmin(page);
  await page.goto('/admin/users.php?action=add');
  await page.locator('input[name="username"]').fill(username);
  await page.locator('input[name="password"]').fill(password);
  await page.locator('select[name="role"]').selectOption('user');
  await page.getByRole('button', { name: /保存/ }).click();
  await expect(page.locator('body')).toContainText(username);

  await logout(page);

  await page.goto('/login.php');
  await page.locator('input[name="username"]').fill(username);
  await page.locator('input[name="password"]').fill(password);
  await page.getByRole('button', { name: /登\s*录/ }).click();
  await expect(page).toHaveURL(/index\.php|\/$/);

  await page.goto('/admin/users.php');
  await expect(page).not.toHaveURL(/\/admin\/users\.php/);
  await expect(page.locator('body')).not.toContainText('添加用户');
  await expect(page.locator('body')).not.toContainText('用户管理');

  await page.goto('/admin/settings.php');
  await expect(page.locator('body')).toContainText(/403 Forbidden: 需要管理员权限。|系统设置/);

  await page.goto('/admin/debug.php');
  await expect(page.locator('body')).toContainText(/403 Forbidden: 需要管理员权限。|调试工具/);

  await tracker.assertNoClientErrors();
});
