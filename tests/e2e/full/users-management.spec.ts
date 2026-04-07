import { test, expect } from '@playwright/test';
import { attachClientErrorTracking, loginAsDevAdmin, logout } from '../../helpers/auth';

test('admin can create edit and delete users with role changes', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
      /Pattern attribute value \[a-zA-Z0-9_-\]\{2,32\} is not a valid regular expression/,
    ],
  });
  const ts = Date.now();
  const username = `user${ts}`;
  const renamed = `userx${ts}`;
  const adminUser = `adminx${ts}`;
  const password = 'User@test2026';

  await loginAsDevAdmin(page);
  await page.goto('/admin/users.php?action=add');

  await page.locator('input[name="username"]').fill(username);
  await page.locator('input[name="password"]').fill(password);
  await page.locator('select[name="role"]').selectOption('user');
  await page.getByRole('button', { name: /保存/ }).click();
  await expect(page.locator('body')).toContainText(username);
  await expect(page.locator('body')).toContainText('user');

  await page.goto(`/admin/users.php?action=edit&uname=${username}`);
  await page.locator('input[name="username"]').fill(renamed);
  await page.locator('select[name="role"]').selectOption('admin');
  await page.locator('input[name="password"]').fill('');
  await page.getByRole('button', { name: /保存/ }).click();
  await expect(page.locator('body')).toContainText(renamed);
  await expect(page.locator('body')).toContainText('admin');

  await page.goto('/admin/users.php?action=add');
  await page.locator('input[name="username"]').fill(adminUser);
  await page.locator('input[name="password"]').fill(password);
  await page.locator('select[name="role"]').selectOption('admin');
  await page.getByRole('button', { name: /保存/ }).click();
  await expect(page.locator('body')).toContainText(adminUser);

  const firstDeleteForm = page.locator(`tr:has-text("${renamed}") form`).first();
  page.once('dialog', dialog => dialog.accept());
  await firstDeleteForm.getByRole('button', { name: '删除' }).click();
  await expect(page.locator('body')).not.toContainText(renamed);

  const secondDeleteForm = page.locator(`tr:has-text("${adminUser}") form`).first();
  page.once('dialog', dialog => dialog.accept());
  await secondDeleteForm.getByRole('button', { name: '删除' }).click();
  await expect(page.locator('body')).not.toContainText(adminUser);

  await tracker.assertNoClientErrors();
});

test('user management enforces validation self-protection and non-admin restrictions', async ({ page, browser }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
      /Pattern attribute value \[a-zA-Z0-9_-\]\{2,32\} is not a valid regular expression/,
    ],
  });
  const ts = Date.now();
  const restrictedUser = `plain${ts}`;
  const password = 'User@test2026';

  await loginAsDevAdmin(page);
  await page.goto('/admin/users.php?action=add');
  const csrf = await page.locator('input[name="_csrf"]').first().inputValue();

  const invalidName = await page.request.post('http://127.0.0.1:58080/admin/users.php', {
    form: {
      _csrf: csrf,
      act: 'save',
      username: 'bad user',
      password,
      role: 'user',
      orig_username: '',
    },
  });
  expect(invalidName.status()).toBe(200);
  expect(await invalidName.text()).toContain('用户名只允许字母数字下划线横杠');

  const missingPassword = await page.request.post('http://127.0.0.1:58080/admin/users.php', {
    form: {
      _csrf: csrf,
      act: 'save',
      username: `nopass${ts}`,
      password: '',
      role: 'user',
      orig_username: '',
    },
  });
  expect(missingPassword.status()).toBe(200);
  expect(await missingPassword.text()).toContain('新用户必须设置密码');
  await page.goto('/admin/users.php?action=add');

  await page.locator('input[name="username"]').fill(restrictedUser);
  await page.locator('input[name="password"]').fill(password);
  await page.locator('select[name="role"]').selectOption('user');
  await page.getByRole('button', { name: /保存/ }).click();
  await expect(page.locator('body')).toContainText(restrictedUser);

  await expect(page.locator('body')).not.toContainText('不能删除当前登录的自己');
  await page.goto('/admin/users.php');
  await expect(page.locator('body')).toContainText('qatest');

  const selfDeleteForm = page.locator('tr:has-text("qatest") form').first();
  await expect(selfDeleteForm).toHaveCount(0);

  await logout(page);
  await page.goto('/login.php');
  await page.locator('input[name="username"]').fill(restrictedUser);
  await page.locator('input[name="password"]').fill(password);
  await page.getByRole('button', { name: /登\s*录/ }).click();
  await expect(page).toHaveURL(/index\.php|\/$/);

  await page.goto('/admin/users.php');
  await expect(page).not.toHaveURL(/admin\/users\.php/);

  const anonContext = await browser.newContext({ baseURL: 'http://127.0.0.1:58080' });
  const anonPage = await anonContext.newPage();
  await anonPage.goto('/admin/users.php');
  await expect(anonPage).toHaveURL(/login\.php/);
  await anonContext.close();

  await tracker.assertNoClientErrors();
});
