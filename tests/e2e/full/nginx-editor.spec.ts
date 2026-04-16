import { test, expect } from '../../helpers/fixtures';
import { attachClientErrorTracking, loginAsDevAdmin } from '../../helpers/auth';

test('nginx editor supports tab navigation modal open close syntax test and save', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
    ],
  });

  await loginAsDevAdmin(page);
  await page.goto('/admin/nginx.php');

  await expect(page.locator('.topbar-title')).toHaveText('Nginx 管理');
  await page.getByRole('link', { name: /HTTP 模块/ }).click();
  await expect(page.locator('#editor-target-label')).toContainText(/HTTP 模块/);
  await page.getByRole('link', { name: /反代配置/ }).click();
  await expect(page.locator('#nginx-proxy-subnav')).toBeVisible();
  await page.getByRole('link', { name: /子域名模式/ }).click();
  await expect(page.locator('#editor-target-label')).toContainText(/子域名模式/);
  await page.getByRole('link', { name: /参数模板（精简）/ }).click();
  await expect(page.locator('#editor-target-label')).toContainText(/参数模板（精简模式）/);

  await page.getByRole('button', { name: /打开文本编辑器/ }).click();
  await expect(page.locator('#nginx-editor-modal')).toHaveClass(/open/);
  await expect(page.getByRole('button', { name: /检查语法/ })).toBeVisible();
  await expect(page.getByRole('button', { name: /^保存$/ })).toBeVisible();
  await page.locator('#close-editor-modal-btn').click();
  await expect(page.locator('#nginx-editor-modal')).not.toHaveClass(/open/);

  await tracker.assertNoClientErrors();
});

test('nginx editor handles invalid target fallback and save reload branch', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
    ],
  });

  await loginAsDevAdmin(page);
  await page.goto('/admin/nginx.php?target=invalid-target&tab=proxy');
  await expect(page.locator('#editor-target-label')).toContainText(/主配置/);

  await page.getByRole('button', { name: /打开文本编辑器/ }).click();
  await expect(page.locator('#nginx-editor-modal')).toHaveClass(/open/);
  await expect(page.getByRole('button', { name: /保存并 Reload/ })).toBeVisible();

  await tracker.assertNoClientErrors();
});
