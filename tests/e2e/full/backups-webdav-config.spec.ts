import { test, expect } from '../../helpers/fixtures';
import { attachClientErrorTracking, loginAsDevAdmin } from '../../helpers/auth';

test('webdav backup configuration defaults to manual trusted-target policy and persists', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page);
  await loginAsDevAdmin(page);
  await page.goto('/admin/backups.php');

  await expect(page.locator('#webdav-settings')).toContainText('不会自动或定时备份');
  await expect(page.locator('#webdavSsrf')).not.toBeChecked();
  await expect(page.locator('#webdavTlsEnabled')).not.toBeChecked();
  await expect(page.locator('#webdavAuthEnabled')).not.toBeChecked();
  await expect(page.locator('input[type="number"][readonly]')).toHaveValue('10');

  await page.locator('#webdavEnabled').check();
  await page.locator('#webdavName').fill('测试 WebDAV');
  await page.locator('#webdavBaseUrl').fill('http://127.0.0.1:58080/dav');
  await page.locator('#webdavRemoteDir').fill('/RiverOps/E2E');
  const [saveResponse] = await Promise.all([
    page.waitForResponse((response) => response.url().includes('/admin/backups_ajax.php') && response.request().method() === 'POST'),
    page.getByRole('button', { name: '保存 WebDAV 配置' }).click(),
  ]);
  expect(saveResponse.status()).toBe(200);

  await page.reload();
  await expect(page.locator('#webdavEnabled')).toBeChecked();
  await expect(page.locator('#webdavName')).toHaveValue('测试 WebDAV');
  await expect(page.locator('#webdavBaseUrl')).toHaveValue('http://127.0.0.1:58080/dav');
  await expect(page.locator('#webdavRemoteDir')).toHaveValue('/RiverOps/E2E');
  await expect(page.locator('#webdavSsrf')).not.toBeChecked();
  await expect(page.locator('#webdavTlsEnabled')).not.toBeChecked();
  await expect(page.locator('#webdavAuthEnabled')).not.toBeChecked();

  const response = await page.request.get('/admin/backups_ajax.php?action=config', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
  });
  expect(response.status()).toBe(200);
  const payload = await response.json();
  expect(payload.data.config.remote_retention).toBe(10);
  expect(payload.data.config.password).toBe('');

  await tracker.assertNoClientErrors();
});

test('webdav backup ajax enforces xhr and guest authentication boundaries', async ({ page, browser }) => {
  await loginAsDevAdmin(page);

  const nonAjax = await page.request.get('/admin/backups_ajax.php?action=config');
  expect(nonAjax.status()).toBe(400);

  const guestContext = await browser.newContext();
  try {
    const guestPage = await guestContext.newPage();
    const guest = await guestPage.request.get('http://127.0.0.1:58080/admin/backups_ajax.php?action=config', {
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
    });
    expect(guest.status()).toBe(401);
    expect((await guest.json()).ok).toBe(false);
  } finally {
    await guestContext.close();
  }
});
