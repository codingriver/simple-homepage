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

  // 容器内应展示 Nginx、HTTP、PHP-FPM、PHP ini 四个配置目标
  await expect(page.locator('[data-edit-target]')).toHaveCount(4);

  // 点击「HTTP 模块」的编辑按钮
  await page.locator('[data-edit-target="http"]').click();
  await expect(page.locator('#nav-ace-editor-modal')).toHaveClass(/open/);
  await expect(page.locator('#nav-ace-title')).toContainText(/HTTP 模块/);

  // 关闭弹窗
  await page.evaluate(() => {
    const editor = (window as Window & { NavAceEditor?: { close(): void } }).NavAceEditor;
    if (!editor) throw new Error('NavAceEditor not found');
    editor.close();
  });
  await expect(page.locator('#nav-ace-editor-modal')).not.toHaveClass(/open/);

  // 点击主配置并验证可编辑操作
  await page.locator('[data-edit-target="main"]').click({ force: true });
  await expect(page.locator('#nav-ace-editor-modal')).toHaveClass(/open/);
  await expect(page.locator('#nav-ace-title')).toContainText(/主配置/);
  await expect(page.locator('#nav-ace-toolbar-actions button[data-action="syntax"]')).toBeVisible();
  await expect(page.locator('#nav-ace-toolbar-actions button[data-action="save"]')).toBeVisible();

  // 关闭弹窗
  await page.evaluate(() => {
    const editor = (window as Window & { NavAceEditor?: { close(): void } }).NavAceEditor;
    if (!editor) throw new Error('NavAceEditor not found');
    editor.close();
  });
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
