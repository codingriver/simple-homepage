import { test, expect } from '../../helpers/fixtures';
import { attachClientErrorTracking, loginAsDevAdmin } from '../../helpers/auth';

test('backups download guards reject invalid and missing filenames while badges remain visible', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
      /Failed to load resource: the server responded with a status of 404 \(Not Found\)/,
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
    ],
  });

  await loginAsDevAdmin(page);
  await page.goto('/admin/backups.php');
  const body = await page.locator('body').textContent();
  if ((body || '').includes('暂无备份记录')) {
    await page.getByRole('button', { name: /立即备份/ }).click();
    await expect(page.locator('body')).toContainText('备份已创建');
  }

  await expect(page.locator('.badge').first()).toBeVisible();

  const invalid = await page.request.get('http://127.0.0.1:58080/admin/backups.php?download=../../etc/passwd');
  expect(invalid.status()).toBe(400);
  expect(await invalid.text()).toContain('Invalid filename');

  const missing = await page.request.get('http://127.0.0.1:58080/admin/backups.php?download=backup_19990101_000000_missing.json');
  expect(missing.status()).toBe(404);
  expect(await missing.text()).toContain('Not found');

  await tracker.assertNoClientErrors();
});
