import { test, expect } from '../../helpers/fixtures';
import { attachClientErrorTracking, loginAsDevAdmin } from '../../helpers/auth';
import { runDockerPhpInline } from '../../helpers/cli';

test('webdav account advanced actions clone toggle reset and delete work', async ({ page }) => {
  test.setTimeout(120000);
  const tracker = await attachClientErrorTracking(page);
  const ts = Date.now();
  const userMain = `webdavact_${ts}`;

  runDockerPhpInline(
    [
      '$cfgPath = "/var/www/nav/data/config.json";',
      '$cfg = file_exists($cfgPath) ? (json_decode((string)file_get_contents($cfgPath), true) ?: []) : [];',
      '$cfg["webdav_enabled"] = "1";',
      'file_put_contents($cfgPath, json_encode($cfg, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));',
    ].join(' ')
  );

  await loginAsDevAdmin(page);
  await page.goto('/admin/webdav.php');

  // create account
  await page.locator('input[name="username"]').fill(userMain);
  await page.locator('input[name="password"]').fill('Webdav@test2026');
  await page.locator('input[name="root"]').fill('/var/www/nav/data');
  await page.getByRole('button', { name: '保存 WebDAV 账号' }).click({ force: true });
  await expect(page.locator('body')).toContainText('WebDAV 账号已保存');

  const csrf = await page.locator('input[name="_csrf"]').first().inputValue();

  // toggle disable
  const row = page.locator('table tbody tr').filter({ hasText: userMain });
  const accountId = await row.locator('input[name="id"]').first().inputValue();

  const toggleRes = await page.request.post('http://127.0.0.1:58080/admin/webdav.php', {
    form: { action: 'toggle_webdav_account', id: accountId, _csrf: csrf },
    maxRedirects: 0,
  });
  expect(toggleRes.status()).toBe(302);

  await page.goto('/admin/webdav.php');
  await expect(page.locator('body')).toContainText('已禁用');

  // reset password
  const csrf2 = await page.locator('input[name="_csrf"]').first().inputValue();
  const resetRes = await page.request.post('http://127.0.0.1:58080/admin/webdav.php', {
    form: { action: 'reset_webdav_password', id: accountId, new_password: 'Reset@2026', _csrf: csrf2 },
    maxRedirects: 0,
  });
  expect(resetRes.status()).toBe(302);

  await page.goto('/admin/webdav.php');
  await expect(page.locator('body')).toContainText('WebDAV 账号已保存');

  // clone account
  const csrf3 = await page.locator('input[name="_csrf"]').first().inputValue();
  const cloneRes = await page.request.post('http://127.0.0.1:58080/admin/webdav.php', {
    form: { action: 'clone_webdav_account', id: accountId, _csrf: csrf3 },
    maxRedirects: 0,
  });
  expect(cloneRes.status()).toBe(302);

  await page.goto('/admin/webdav.php');
  await expect(page.locator('body')).toContainText(`${userMain}_copy`);

  // delete cloned
  const clonedRow = page.locator('.card:has-text("账号列表") table tbody tr').filter({ hasText: `${userMain}_copy` });
  const clonedId = await clonedRow.locator('input[name="id"]').first().inputValue();
  const csrf4 = await page.locator('input[name="_csrf"]').first().inputValue();
  const deleteRes = await page.request.post('http://127.0.0.1:58080/admin/webdav.php', {
    form: { action: 'delete_webdav_account', id: clonedId, _csrf: csrf4 },
    maxRedirects: 0,
  });
  expect(deleteRes.status()).toBe(302);

  await page.goto('/admin/webdav.php');
  await expect(page.locator('.card:has-text("账号列表") table tbody tr').filter({ hasText: `${userMain}_copy` })).toHaveCount(0);

  // delete original
  const csrf5 = await page.locator('input[name="_csrf"]').first().inputValue();
  const deleteOrigRes = await page.request.post('http://127.0.0.1:58080/admin/webdav.php', {
    form: { action: 'delete_webdav_account', id: accountId, _csrf: csrf5 },
    maxRedirects: 0,
  });
  expect(deleteOrigRes.status()).toBe(302);

  await tracker.assertNoClientErrors();
});
