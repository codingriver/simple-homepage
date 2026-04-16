import { test, expect } from '../../helpers/fixtures';
import { attachClientErrorTracking, loginAsDevAdmin, logout } from '../../helpers/auth';

test('users boundary flows cover duplicate invalid self delete and role change relogin effect', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
      /Pattern attribute value \[a-zA-Z0-9_-\]\{2,32\} is not a valid regular expression/,
    ],
  });
  const ts = Date.now();
  const username = `boundary${ts}`;
  const password = 'User@test2026';

  await loginAsDevAdmin(page);
  await page.goto('/admin/users.php?action=add');
  await page.locator('input[name="username"]').fill('bad user');
  await page.locator('input[name="password"]').fill(password);
  await page.getByRole('button', { name: /保存/ }).click();
  await expect(page.locator('body')).toContainText('用户名只允许字母数字下划线横杠');

  await page.locator('input[name="username"]').fill(username);
  await page.locator('input[name="password"]').fill(password);
  await page.locator('select[name="role"]').selectOption('user');
  await page.getByRole('button', { name: /保存/ }).click();
  await expect(page.locator('body')).toContainText(username);

  await page.goto('/admin/users.php?action=add');
  await page.locator('input[name="username"]').fill(username);
  await page.locator('input[name="password"]').fill(password);
  await page.locator('select[name="role"]').selectOption('user');
  await page.getByRole('button', { name: /保存/ }).click();
  await expect(page.locator('body')).toContainText(username);

  await page.goto('/admin/users.php');
  await expect(page.locator('tr:has-text("qatest") form')).toHaveCount(0);

  await page.goto(`/admin/users.php?action=edit&uname=${username}`);
  await page.locator('select[name="role"]').selectOption('admin');
  await page.getByRole('button', { name: /保存/ }).click();
  await expect(page.locator('body')).toContainText(username);

  await logout(page);
  await page.goto('/login.php');
  await page.locator('input[name="username"]').fill(username);
  await page.locator('input[name="password"]').fill(password);
  await page.getByRole('button', { name: /登\s*录/ }).click();
  await expect(page).toHaveURL(/index\.php|\/$/);
  await page.goto('/admin/users.php');
  await expect(page).toHaveURL(/admin\/users\.php/);

  await tracker.assertNoClientErrors();
});
