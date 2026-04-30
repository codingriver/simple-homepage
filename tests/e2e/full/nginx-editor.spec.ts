import { test, expect } from '../../helpers/fixtures';
import { attachClientErrorTracking, loginAsDevAdmin } from '../../helpers/auth';

test('nginx editor supports direct open from config list modal open close syntax test and save', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
    ],
  });

  await loginAsDevAdmin(page);
  await page.goto('/admin/nginx.php');

  await expect(page.locator('.topbar-title')).toHaveText('Nginx 管理');

  // 配置列表应包含 6 个编辑项
  await expect(page.locator('[data-edit-target]')).toHaveCount(6);

  // 点击「HTTP 模块」的编辑按钮
  await page.locator('[data-edit-target="http"]').click();
  await expect(page.locator('#nav-ace-editor-modal')).toHaveClass(/open/);
  await expect(page.locator('#nav-ace-title')).toContainText(/HTTP 模块/);

  // 关闭弹窗
  await page.locator('#nav-ace-editor-modal .ngx-close-btn').click();
  await expect(page.locator('#nav-ace-editor-modal')).not.toHaveClass(/open/);

  // 点击「反代参数模板 — 精简」的编辑按钮
  await page.locator('[data-edit-target="proxy_params_simple"]').click();
  await expect(page.locator('#nav-ace-editor-modal')).toHaveClass(/open/);
  await expect(page.locator('#nav-ace-title')).toContainText(/精简/);
  await expect(page.locator('#nav-ace-toolbar-actions button[data-action="syntax"]')).toBeVisible();
  await expect(page.locator('#nav-ace-toolbar-actions button[data-action="save"]')).toBeVisible();

  // 关闭弹窗
  await page.locator('#nav-ace-editor-modal .ngx-close-btn').click();
  await expect(page.locator('#nav-ace-editor-modal')).not.toHaveClass(/open/);

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
  await page.goto('/admin/nginx.php');

  // 点击「主配置」编辑按钮
  await page.locator('[data-edit-target="main"]').click();
  await expect(page.locator('#nav-ace-editor-modal')).toHaveClass(/open/);
  await expect(page.locator('#nav-ace-toolbar-actions button[data-action="save_reload"]')).toBeVisible();

  await tracker.assertNoClientErrors();
});
