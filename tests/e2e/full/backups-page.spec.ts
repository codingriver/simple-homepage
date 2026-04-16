import { test, expect } from '../../helpers/fixtures';
import { attachClientErrorTracking, loginAsDevAdmin } from '../../helpers/auth';

test('backup page supports confirm cancel trigger badges and invalid restore failure message', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
    ],
  });

  await loginAsDevAdmin(page);
  await page.goto('/admin/backups.php');
  await page.getByRole('button', { name: /立即备份/ }).click();
  await expect(page.locator('body')).toContainText('备份已创建');
  await expect(page.locator('.badge').first()).toBeVisible();

  const firstRow = page.locator('table tr').nth(1);

  page.once('dialog', (dialog) => dialog.dismiss());
  await firstRow.getByRole('button', { name: /恢复/ }).click({ force: true });
  await expect(page.locator('body')).not.toContainText('已恢复备份');

  page.once('dialog', (dialog) => dialog.dismiss());
  await firstRow.getByRole('button', { name: /删除/ }).click({ force: true });
  await expect(page.locator('table tr').nth(1)).toBeVisible();

  const csrf = await page.locator('input[name="_csrf"]').first().inputValue();
  const invalidRestore = await page.request.post('http://127.0.0.1:58080/admin/backups.php', {
    form: { action: 'restore', filename: 'backup_19990101_000000_invalid.json', _csrf: csrf },
  });
  expect(invalidRestore.status()).toBe(200);

  await page.goto('/admin/backups.php');
  await expect(page.locator('body')).toContainText(/恢复失败|文件不存在或格式无效|备份/);

  await tracker.assertNoClientErrors();
});
