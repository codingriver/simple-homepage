import path from 'path';
import { test, expect } from '@playwright/test';
import { attachClientErrorTracking, loginAsDevAdmin } from '../../helpers/auth';

test('admin can save settings and see homepage title update', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
    ],
    ignoredFailedRequests: [
      /GET .*\/admin\/settings_ajax\.php\?action=nginx_sudo :: net::ERR_ABORTED/,
    ],
  });
  const siteName = `设置验证 ${Date.now()}`;

  await loginAsDevAdmin(page);
  await page.goto('/admin/settings.php');

  await page.locator('input[name="site_name"]').fill(siteName);
  await page.locator('input[name="bg_color"]').fill('#112233');
  await page.locator('select[name="card_layout"]').selectOption('list');
  await page.locator('select[name="card_direction"]').selectOption('row');
  await page.getByRole('button', { name: /保存设置/ }).click();
  await expect(page.locator('body')).toContainText('设置已保存');

  await page.reload();
  await expect(page.locator('input[name="site_name"]')).toHaveValue(siteName);

  await page.goto('/index.php');
  await expect(page).toHaveTitle(new RegExp(siteName));

  await tracker.assertNoClientErrors();
});

test('settings validate long site name invalid bg color and custom card bounds', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
    ],
    ignoredFailedRequests: [
      /GET .*\/admin\/settings_ajax\.php\?action=nginx_sudo :: net::ERR_ABORTED/,
    ],
  });
  const longName = '超长站点名称'.repeat(10);

  await loginAsDevAdmin(page);
  await page.goto('/admin/settings.php');

  await page.locator('input[name="site_name"]').fill(longName);
  await expect(page.locator('input[name="site_name"]')).toHaveValue(longName.slice(0, 60));
  await page.getByRole('button', { name: /保存设置/ }).click();
  await expect(page.locator('body')).toContainText('设置已保存');

  await page.locator('input[name="site_name"]').fill('边界设置验证');
  await page.locator('input[name="bg_color"]').fill('not-a-color');
  await page.getByRole('button', { name: /保存设置/ }).click();
  await expect(page.locator('body')).toContainText('背景色格式无效');

  await page.locator('#card_size_sel').selectOption('custom');
  await page.locator('#card_size_custom').fill('999');
  await expect.poll(async () => page.locator('#card_size_custom').evaluate((el: HTMLInputElement) => el.validity.rangeOverflow)).toBe(true);
  await page.locator('#card_size_custom').fill('600');

  await page.locator('#card_height_sel').selectOption('custom');
  await page.locator('#card_height_custom').fill('999');
  await expect.poll(async () => page.locator('#card_height_custom').evaluate((el: HTMLInputElement) => el.validity.rangeOverflow)).toBe(true);
  await page.locator('#card_height_custom').fill('800');

  await page.locator('input[name="bg_color"]').fill('#223344');
  await page.getByRole('button', { name: /保存设置/ }).click();
  await expect(page.locator('body')).toContainText('设置已保存');
  await page.reload();
  await expect(page.locator('#card_size_sel')).toHaveValue('custom');
  await expect(page.locator('#card_size_custom')).toHaveValue('600');
  await expect(page.locator('#card_height_sel')).toHaveValue('custom');
  await expect(page.locator('#card_height_custom')).toHaveValue('800');

  await tracker.assertNoClientErrors();
});

test('settings background image upload rejects invalid file', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
    ],
    ignoredFailedRequests: [
      /GET .*\/admin\/settings_ajax\.php\?action=nginx_sudo :: net::ERR_ABORTED/,
    ],
  });
  const invalidFile = path.resolve(__dirname, '../../fixtures/import-invalid.json');

  await loginAsDevAdmin(page);
  await page.goto('/admin/settings.php');
  await page.locator('input[name="bg_image"]').setInputFiles(invalidFile);
  await page.getByRole('button', { name: /保存设置/ }).click();
  await expect(page.locator('body')).toContainText(/背景图内容无效|背景图上传失败|背景图大小需在 8MB 以内/);

  await tracker.assertNoClientErrors();
});
