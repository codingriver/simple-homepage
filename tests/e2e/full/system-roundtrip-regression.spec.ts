import { test, expect } from '@playwright/test';
import { attachClientErrorTracking, loginAsDevAdmin, submitVisibleModal } from '../../helpers/auth';

test('system roundtrip regression restores groups sites and settings coherently from backup', async ({ page }, testInfo) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
    ],
    ignoredFailedRequests: [/backups\.php\?download=.*:: net::ERR_ABORTED/],
  });
  const ts = Date.now();
  const gid = `roundtrip-system-${ts}`;
  const sid = `roundtrip-site-${ts}`;
  const exportPath = testInfo.outputPath(`system-roundtrip-${ts}.json`);

  await loginAsDevAdmin(page);
  await page.goto('/admin/groups.php');
  await page.getByRole('button', { name: /添加分组/ }).click();
  await page.locator('#fi_id').fill(gid);
  await page.locator('#fi_name').fill(`系统回归分组 ${ts}`);
  await page.locator('#fi_auth').selectOption('0');
  await submitVisibleModal(page);

  await page.goto('/admin/sites.php');
  await page.getByRole('button', { name: /添加站点/ }).click();
  await page.locator('#fi_sid').fill(sid);
  await page.locator('#fi_name').fill(`系统回归站点 ${ts}`);
  await page.locator('#fi_gid').selectOption(gid);
  await page.locator('#fi_type').selectOption('external');
  await page.locator('#fi_url').fill('https://example.com/system-roundtrip');
  await submitVisibleModal(page);

  await page.goto('/admin/settings.php');
  await page.locator('input[name="site_name"]').fill(`系统回归标题 ${ts}`);
  await page.getByRole('button', { name: /保存设置/ }).click();
  await expect(page.locator('body')).toContainText('设置已保存');

  await page.goto('/admin/backups.php');
  await page.getByRole('button', { name: /立即备份/ }).click();
  await expect(page.locator('body')).toContainText('备份已创建');
  const downloadPromise = page.waitForEvent('download');
  await page.locator('table tr').nth(1).getByRole('link', { name: /下载/ }).click();
  const download = await downloadPromise;
  await download.saveAs(exportPath);

  await page.goto('/admin/groups.php');
  const row = page.locator(`tr:has(input[name="gid"][value="${gid}"])`).first();
  page.once('dialog', (dialog) => dialog.accept());
  await row.getByRole('button', { name: '删除' }).click();
  await expect(page.locator(`tr:has(input[name="gid"][value="${gid}"])`)).toHaveCount(0);

  await page.goto('/admin/settings.php');
  await page.locator('#importFile').setInputFiles(exportPath);
  await expect(page.locator('body')).toContainText(/导入成功/);

  await page.goto('/index.php');
  await expect(page).toHaveTitle(new RegExp(`系统回归标题 ${ts}`));
  await expect(page.locator('body')).toContainText(`系统回归分组 ${ts}`);
  await expect(page.locator('body')).toContainText(`系统回归站点 ${ts}`);

  await tracker.assertNoClientErrors();
});
