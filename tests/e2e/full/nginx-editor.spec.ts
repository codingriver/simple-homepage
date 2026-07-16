import { test, expect } from '../../helpers/fixtures';
import { attachClientErrorTracking, loginAsDevAdmin } from '../../helpers/auth';

test('runtime config page opens config files in readonly viewer', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
    ],
  });

  await loginAsDevAdmin(page);
  await page.goto('/admin/nginx.php');

  await expect(page.locator('.topbar-title')).toHaveText('运行配置');
  await expect(page.locator('body')).toContainText('后台不再支持修改 Nginx');

  // 容器内应展示 Nginx、HTTP、PHP-FPM、PHP ini 四个配置目标
  await expect(page.locator('[data-view-target]')).toHaveCount(4);
  await expect(page.locator('[data-edit-target]')).toHaveCount(0);

  await page.locator('[data-view-target="http"]').click();
  await expect(page.locator('#riverops-ace-editor-modal')).toHaveClass(/open/);
  await expect(page.locator('#riverops-ace-title')).toContainText(/HTTP 模块/);
  await expect(page.locator('#riverops-ace-toolbar-actions button[data-action="save"]')).toHaveCount(0);
  await expect(page.locator('#riverops-ace-toolbar-actions button[data-action="save_reload"]')).toHaveCount(0);

  await page.evaluate(() => {
    const editor = (window as Window & { RiverOpsAceEditor?: { close(): void } }).RiverOpsAceEditor;
    if (!editor) throw new Error('RiverOpsAceEditor not found');
    editor.close();
  });
  await expect(page.locator('#riverops-ace-editor-modal')).not.toHaveClass(/open/);

  await tracker.assertNoClientErrors();
});

test('runtime config rejects legacy save and reload posts', async ({ page }) => {
  await loginAsDevAdmin(page);
  await page.goto('/admin/nginx.php');
  const csrf = await page.evaluate(() => {
    const input = document.querySelector<HTMLInputElement>('input[name="_csrf"]');
    return input?.value || (window as Window & { _csrf?: string })._csrf || '';
  });

  const response = await page.request.post('http://127.0.0.1:58080/admin/nginx.php', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: {
      action: 'save_and_reload',
      target: 'main',
      content: 'invalid',
      _csrf: csrf,
    },
  });
  expect(response.status()).toBe(400);
  const body = await response.json();
  expect(body.ok).toBe(false);
  expect(body.msg).toContain('不再支持修改');
});
