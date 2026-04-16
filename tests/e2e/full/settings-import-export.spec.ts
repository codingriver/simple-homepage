import path from 'path';
import { test, expect } from '../../helpers/fixtures';
import { attachClientErrorTracking, loginAsDevAdmin } from '../../helpers/auth';

test('admin can export config and validate import guardrails', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
    ],
    ignoredFailedRequests: [
      /POST .*\/admin\/settings\.php :: net::ERR_ABORTED/,
    ],
  });
  const validImport = path.resolve(__dirname, '../../fixtures/import-valid.json');
  const invalidImport = path.resolve(__dirname, '../../fixtures/import-invalid.json');

  await loginAsDevAdmin(page);
  await page.goto('/admin/settings.php');

  const downloadPromise = page.waitForEvent('download');
  await page.getByRole('button', { name: /导出配置/ }).click();
  const download = await downloadPromise;
  expect(download.suggestedFilename()).toMatch(/nav_export_.*\.json/);

  await page.locator('#importFile').setInputFiles(invalidImport);
  await expect(page.locator('body')).toContainText(/JSON 格式解析错误|无法识别的配置格式/);

  page.once('dialog', dialog => dialog.accept());
  await page.locator('#importFile').setInputFiles(validImport);
  await expect(page.locator('.alert.alert-success')).toContainText('导入成功（站点格式）');

  await page.goto('/admin/groups.php');
  const importedRow = page.locator('tr', { hasText: '导入分组' }).first();
  await expect(importedRow).toBeVisible();

  await tracker.assertNoClientErrors();
});

test('settings import rejects empty and oversized files on client side', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
    ],
  });

  await loginAsDevAdmin(page);
  await page.goto('/admin/settings.php');

  await page.setInputFiles('#importFile', {
    name: 'empty.json',
    mimeType: 'application/json',
    buffer: Buffer.from(''),
  });
  await expect(page.locator('body')).toContainText(/JSON 格式解析错误|文件读取失败|无法识别/);

  await page.setInputFiles('#importFile', {
    name: 'large.json',
    mimeType: 'application/json',
    buffer: Buffer.alloc(4 * 1024 * 1024 + 16, 'a'),
  });
  await expect(page.locator('body')).toContainText('文件过大，配置文件不应超过 4MB');

  await tracker.assertNoClientErrors();
});
