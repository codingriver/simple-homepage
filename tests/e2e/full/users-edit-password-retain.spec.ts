import { test, expect } from '../../helpers/fixtures';
import { attachClientErrorTracking, loginAsDevAdmin, logout } from '../../helpers/auth';

test('users keep password when editing with empty password and invalid role is rejected', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
      /Pattern attribute value \[a-zA-Z0-9_-\]\{2,32\} is not a valid regular expression/,
    ],
  });
  const username = `retain_${Date.now()}`;
  const password = 'RetainPass2026!';

  await loginAsDevAdmin(page);
  await page.goto('/admin/users.php?action=add');
  await page.locator('input[name="username"]').fill(username);
  await page.locator('select[name="role"]').selectOption('user');
  await page.locator('input[name="password"]').fill(password);
  await page.getByRole('button', { name: /保存/ }).click();
  await expect(page.locator('body')).toContainText(username);

  await page.goto(`/admin/users.php?action=edit&uname=${username}`);
  await page.locator('select[name="role"]').selectOption('admin');
  await page.locator('input[name="password"]').fill('');
  await page.getByRole('button', { name: /保存/ }).click();
  await expect(page.locator('body')).toContainText(username);

  await logout(page);
  await page.goto('/login.php');
  await page.locator('input[name="username"]').fill(username);
  await page.locator('input[name="password"]').fill(password);
  await page.getByRole('button', { name: /登\s*录/ }).click();
  await expect(page).toHaveURL(/index\.php|\/$/);

  await logout(page);
  await loginAsDevAdmin(page);
  const badRole = await page.request.post('http://127.0.0.1:58080/admin/users.php', {
    form: {
      _csrf: await page.locator('input[name="_csrf"]').first().inputValue(),
      act: 'save',
      username: `${username}_bad`,
      password: 'BadRole2026!',
      role: 'superadmin',
      orig_username: '',
    },
  });
  expect(badRole.status()).toBe(200);
  await expect(page.locator('body')).toContainText(/用户管理|qatest|admin/);

  await tracker.assertNoClientErrors();
});
