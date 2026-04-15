import { test, expect } from '@playwright/test';
import { attachClientErrorTracking, loginAsDevAdmin, submitVisibleModal } from '../../helpers/auth';

test('admin can export config, mutate data, and restore it via import', async ({ page }, testInfo) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
    ],
    ignoredFailedRequests: [
      /POST .*\/admin\/settings\.php :: net::ERR_ABORTED/,
      /GET .*\/favicon\.php\?url=.* :: net::ERR_ABORTED/,
    ],
  });
  const ts = Date.now();
  const groupId = `roundtrip-group-${ts}`;
  const groupName = `回滚分组 ${ts}`;
  const exportPath = testInfo.outputPath(`roundtrip-${ts}.json`);

  await loginAsDevAdmin(page);

  await page.goto('/admin/groups.php');
  await page.getByRole('button', { name: /添加分组/ }).click();
  await page.locator('#fi_id').fill(groupId);
  await page.locator('#fi_name').fill(groupName);
  await page.locator('#fi_auth').selectOption('0');
  await submitVisibleModal(page);

  const groupRow = page.locator(`tr:has(input[name="gid"][value="${groupId}"])`).first();
  await expect(groupRow).toContainText(groupName);

  await page.goto('/admin/settings.php');
  const downloadPromise = page.waitForEvent('download');
  await page.getByRole('button', { name: /导出配置/ }).click();
  const download = await downloadPromise;
  await download.saveAs(exportPath);

  await page.goto('/admin/groups.php');
  page.once('dialog', dialog => dialog.accept());
  await Promise.all([
    page.waitForURL(/\/admin\/groups\.php/),
    groupRow.locator('form').evaluate((form) => {
      (form as HTMLFormElement).requestSubmit();
    }),
  ]);
  await expect(page.locator(`tr:has(input[name="gid"][value="${groupId}"])`)).toHaveCount(0);

  await page.goto('/admin/settings.php');
  page.once('dialog', dialog => dialog.accept());
  await page.locator('#importFile').setInputFiles(exportPath);
  await expect(page.locator('body')).toContainText('导入成功');

  await page.goto('/admin/groups.php');
  await expect(page.locator(`tr:has(input[name="gid"][value="${groupId}"])`).first()).toContainText(groupName);

  await tracker.assertNoClientErrors();
});
