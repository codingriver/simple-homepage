import { test, expect } from '@playwright/test';
import { attachClientErrorTracking, loginAsDevAdmin } from '../../helpers/auth';

test('scheduled tasks support workdir modes log pagination and copy directory interactions', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
    ],
  });
  const ts = Date.now();
  const taskId = `mode-task-${ts}`;

  await loginAsDevAdmin(page);
  await page.goto('/admin/scheduled_tasks.php');
  await page.getByRole('button', { name: /新建任务/ }).click();

  await expect(page.locator('#fm-working-dir-mode')).toHaveValue('task');
  await expect(page.locator('#fm-workdir-preview')).toContainText('/var/www/nav/data/tasks/');
  await page.locator('#fm-name').fill('模式任务');
  await page.locator('#fm-schedule').fill('*/10 * * * *');
  await expect(page.locator('#fm-workdir-preview')).toContainText('/var/www/nav/data/tasks/');
  await page.locator('#fm-command').fill(Array.from({ length: 35 }, (_, i) => `echo line-${i + 1}`).join('\n'));
  await page.getByRole('button', { name: '💾 保存' }).click();
  await expect(page.locator('body')).toContainText(/已保存并更新 crontab|已保存/);

  const row = page.locator(`tr:has-text("模式任务")`).first();
  const workdirText = await row.locator('td').nth(2).textContent();
  await row.getByRole('button', { name: /立即执行/ }).click();
  await expect(page.locator('body')).toContainText(/执行完成|执行失败/);

  await page.evaluate(() => {
    const original = navigator.clipboard?.writeText?.bind(navigator.clipboard);
    (window as any).__copiedText = '';
    if (navigator.clipboard) {
      navigator.clipboard.writeText = async (text: string) => {
        (window as any).__copiedText = text;
        return Promise.resolve();
      };
    } else {
      (navigator as any).clipboard = { writeText: async (text: string) => { (window as any).__copiedText = text; } };
    }
    (window as any).__restoreClipboard = () => {
      if (original && navigator.clipboard) navigator.clipboard.writeText = original;
    };
  });
  await row.getByRole('button', { name: /复制目录/ }).click();
  const copied = await page.evaluate(() => (window as any).__copiedText || '');
  expect(copied).toContain('/var/www/nav/data/tasks/');
  expect(workdirText || '').toContain('/var/www/nav/data/tasks/');

  await row.getByRole('button', { name: /日志/ }).click();
  await expect(page.locator('#log-modal')).toBeVisible();
  await expect(page.locator('#log-page-label')).toContainText(/第\s+\d+\s*\/\s*\d+\s*页/);
  if (await page.locator('#log-last-btn').isEnabled()) {
    await page.locator('#log-last-btn').click();
  }
  if (await page.locator('#log-prev').isEnabled()) {
    await page.locator('#log-prev').click();
  }
  if (await page.locator('#log-next').isEnabled()) {
    await page.locator('#log-next').click();
  }
  await page.getByRole('button', { name: /清空日志/ }).click();
  await page.waitForTimeout(500);
  await page.keyboard.press('Escape');
  await expect(page.locator('#log-modal')).toBeHidden();

  await page.getByRole('button', { name: /新建任务/ }).click();
  await page.locator('#fm-name').fill('自定义目录任务');
  await page.locator('#fm-schedule').fill('*/20 * * * *');
  await page.locator('#fm-working-dir-mode').selectOption('custom');
  await page.locator('#fm-working-dir').fill('/tmp/nav-custom-task');
  await expect(page.locator('#fm-workdir-preview')).toContainText('/tmp/nav-custom-task');
  await page.getByRole('button', { name: /取消/ }).click();
  await expect(page.locator('#task-modal')).toBeHidden();

  await tracker.assertNoClientErrors();
});
