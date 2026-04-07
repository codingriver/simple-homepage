import path from 'path';
import { test, expect } from '@playwright/test';
import { attachClientErrorTracking, loginAsDevAdmin } from '../../helpers/auth';

test('settings advanced linkage keeps imported values and proxy mode changes consistent', async ({ page }, testInfo) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
    ],
    ignoredFailedRequests: [/settings_ajax\.php\?action=nginx_sudo/, /POST .*\/admin\/settings\.php :: net::ERR_ABORTED/],
  });
  const exportPath = testInfo.outputPath(`settings-linkage-${Date.now()}.json`);

  await loginAsDevAdmin(page);
  await page.goto('/admin/settings.php');
  await page.locator('input[name="site_name"]').fill('高级联动设置');
  await page.locator('input[name="bg_color"]').fill('#334455');
  await page.getByRole('button', { name: /保存设置/ }).click();
  await expect(page.locator('body')).toContainText('设置已保存');
  await page.locator('label[data-ppm-card="full"]').click();
  await page.getByRole('button', { name: /保存模式/ }).click();
  await expect(page.locator('body')).toContainText(/反代参数模式已/);

  const downloadPromise = page.waitForEvent('download');
  await page.getByRole('button', { name: /导出配置/ }).click();
  const download = await downloadPromise;
  await download.saveAs(exportPath);

  await page.locator('input[name="site_name"]').fill('已变更设置');
  await page.locator('input[name="bg_color"]').fill('#556677');
  await page.getByRole('button', { name: /保存设置/ }).click();
  await expect(page.locator('body')).toContainText('设置已保存');
  await page.locator('label[data-ppm-card="simple"]').click();
  await page.getByRole('button', { name: /保存模式/ }).click();
  await expect(page.locator('body')).toContainText(/反代参数模式已/);

  page.once('dialog', (dialog) => dialog.accept());
  const importNavigation = page.waitForNavigation();
  await page.locator('#importFile').setInputFiles(exportPath);
  await importNavigation;
  await expect(page.locator('.alert.alert-success')).toContainText(/导入成功/);
  await expect(page.locator('input[name="site_name"]')).toHaveValue('高级联动设置');
  await expect(page.locator('input[name="bg_color"]')).toHaveValue('#334455');
  await expect(page.locator('#ppm_full')).toBeChecked();

  await tracker.assertNoClientErrors();
});
