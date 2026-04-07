import { test, expect } from '@playwright/test';
import { attachClientErrorTracking, loginAsDevAdmin, logout } from '../../helpers/auth';

test('users keep at least one admin when applicable and renamed usernames replace old login identity', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
      /Pattern attribute value \[a-zA-Z0-9_-\]\{2,32\} is not a valid regular expression/,
    ],
  });
  const ts = Date.now();
  const secondAdmin = `keeper${ts}`;
  const renamed = `keeperx${ts}`;
  const password = 'User@test2026';

  await loginAsDevAdmin(page);

  await page.goto('/admin/users.php');
  const adminCountBefore = await page.locator('tbody tr').filter({ hasText: 'admin' }).count();

  await page.goto('/admin/users.php?action=edit&uname=qatest');
  await page.locator('select[name="role"]').selectOption('user');
  await page.getByRole('button', { name: /保存/ }).click();
  if (adminCountBefore <= 1) {
    await expect(page.locator('body')).toContainText('至少保留一个管理员账户');
  } else {
    await expect(page.locator('body')).toContainText(/已保存|用户 'qatest' 已保存/);
  }

  await page.goto('/admin/users.php?action=add');
  await page.locator('input[name="username"]').fill(secondAdmin);
  await page.locator('input[name="password"]').fill(password);
  await page.locator('select[name="role"]').selectOption('admin');
  await page.getByRole('button', { name: /保存/ }).click();
  await expect(page.locator('body')).toContainText(secondAdmin);

  await page.goto(`/admin/users.php?action=edit&uname=${secondAdmin}`);
  await page.locator('input[name="username"]').fill(renamed);
  await page.locator('input[name="password"]').fill('');
  await page.getByRole('button', { name: /保存/ }).click();
  await expect(page.locator('body')).toContainText(renamed);
  await expect(page.locator('body')).not.toContainText(secondAdmin);

  await logout(page);
  await page.goto('/login.php');
  await page.locator('input[name="username"]').fill(secondAdmin);
  await page.locator('input[name="password"]').fill(password);
  await page.getByRole('button', { name: /登\s*录/ }).click();
  await expect(page.locator('body')).toContainText(/密码错误|用户名或密码错误|登录失败/);

  await page.locator('input[name="username"]').fill(renamed);
  await page.locator('input[name="password"]').fill(password);
  await page.getByRole('button', { name: /登\s*录/ }).click();
  await expect(page).toHaveURL(/index\.php|\/$/);

  await tracker.assertNoClientErrors();
});
