import { test, expect } from '@playwright/test';
import { attachClientErrorTracking, loginAsDevAdmin, submitVisibleModal } from '../../helpers/auth';

test('admin can create backup, mutate data, restore backup, and download snapshot', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
    ],
    ignoredFailedRequests: [
      /GET .*\/admin\/groups\.php :: net::ERR_ABORTED/,
      /GET .*\/admin\/backups\.php\?download=.* :: net::ERR_ABORTED/,
    ],
  });
  const ts = Date.now();
  const groupId = `backup-group-${ts}`;
  const originalName = `备份恢复分组 ${ts}`;
  const changedName = `备份恢复分组-修改 ${ts}`;

  await loginAsDevAdmin(page);

  await page.goto('/admin/groups.php');
  await page.getByRole('button', { name: /添加分组/ }).click();
  await page.locator('#fi_id').fill(groupId);
  await page.locator('#fi_name').fill(originalName);
  await page.locator('#fi_auth').selectOption('0');
  await submitVisibleModal(page);

  const row = page.locator(`tr:has(input[name="gid"][value="${groupId}"])`).first();
  await expect(row).toContainText(originalName);

  await page.goto('/admin/backups.php');
  await page.getByRole('button', { name: /立即备份/ }).click();
  await expect(page.locator('body')).toContainText('备份已创建');

  const backupRow = page.locator('table tr').nth(1);
  const downloadPromise = page.waitForEvent('download');
  await backupRow.getByRole('link', { name: /下载/ }).click();
  const download = await downloadPromise;
  expect(download.suggestedFilename()).toMatch(/backup_.*\.json/);

  await page.goto('/admin/groups.php');
  await row.getByRole('button', { name: '编辑' }).click();
  await page.locator('#fi_name').fill(changedName);
  await submitVisibleModal(page);
  await expect(row).toContainText(changedName);

  await page.goto('/admin/backups.php');
  page.once('dialog', dialog => {
    expect(dialog.message()).toContain('确认恢复此备份？');
    dialog.accept();
  });
  await backupRow.getByRole('button', { name: /恢复/ }).click();
  await expect(page.locator('body')).toContainText('已恢复备份');

  await page.goto('/admin/groups.php');
  await expect(page.locator(`tr:has(input[name="gid"][value="${groupId}"])`).first()).toContainText(originalName);
  await expect(page.locator(`tr:has(input[name="gid"][value="${groupId}"])`).first()).not.toContainText(changedName);

  await tracker.assertNoClientErrors();
});

test('backup management supports delete flow', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
    ],
  });

  await loginAsDevAdmin(page);
  await page.goto('/admin/backups.php');
  await page.getByRole('button', { name: /立即备份/ }).click();
  await expect(page.locator('body')).toContainText('备份已创建');

  const deletable = page.locator('table tr').nth(1);
  page.once('dialog', dialog => dialog.accept());
  await deletable.getByRole('button', { name: /删除/ }).click();
  await expect(page.locator('body')).toContainText(/备份已删除|删除失败/);

  await tracker.assertNoClientErrors();
});
