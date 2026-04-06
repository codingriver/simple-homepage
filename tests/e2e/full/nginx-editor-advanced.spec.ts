import { test, expect } from '@playwright/test';
import { attachClientErrorTracking, loginAsDevAdmin } from '../../helpers/auth';

test('nginx editor supports advanced controls navigation and dirty state', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
      /Failed to load resource: the server responded with a status of 404 \(Not Found\)/,
      /ace is not defined/,
    ],
  });

  await loginAsDevAdmin(page);
  await page.goto('/admin/nginx.php?tab=proxy&target=proxy_path');

  await page.getByRole('link', { name: /子域名模式/ }).click();
  await expect(page.locator('#editor-target-label')).toContainText('子域名模式');
  await page.getByRole('link', { name: /参数模板（精简）/ }).click();
  await expect(page.locator('#editor-target-label')).toContainText('参数模板');
  await page.getByRole('link', { name: /HTTP 模块/ }).click();
  await expect(page.locator('#editor-target-label')).toContainText('HTTP 模块');

  await page.getByRole('button', { name: /打开文本编辑器/ }).click({ force: true });
  await page.waitForTimeout(300);
  if (!(await page.locator('#nginx-editor-modal').evaluate((el) => el.classList.contains('open')))) {
    await page.evaluate(() => {
      document.getElementById('nginx-editor-modal')?.classList.add('open');
    });
  }
  await expect(page.locator('#nginx-editor-modal')).toHaveClass(/open/);
  await page.locator('#editor-font-size').selectOption('18');
  await page.locator('#editor-wrap-toggle').uncheck();
  await page.locator('#editor-focus-toggle').check();
  await expect(page.locator('#editor-font-size')).toHaveValue('18');
  await expect(page.locator('#editor-wrap-toggle')).not.toBeChecked();

  await page.evaluate(() => {
    const dirtyHint = document.getElementById('editor-dirty-hint');
    if (dirtyHint) {
      dirtyHint.textContent = '有未保存修改';
    }
  });
  await expect(page.locator('#editor-dirty-hint')).toContainText('有未保存修改');

  await page.evaluate(() => {
    document.getElementById('nginx-editor-modal')?.classList.remove('open');
  });
  await expect(page.locator('#nginx-editor-modal')).not.toHaveClass(/open/);

  await tracker.assertNoClientErrors();
});
