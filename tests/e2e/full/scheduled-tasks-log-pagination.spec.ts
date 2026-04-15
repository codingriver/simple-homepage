import fs from 'fs/promises';
import path from 'path';
import { test, expect } from '@playwright/test';
import { attachClientErrorTracking, loginAsDevAdmin } from '../../helpers/auth';

const tasksRootPath = path.resolve(__dirname, '../../../data/tasks');

async function waitForTaskLogLines(page: Parameters<typeof loginAsDevAdmin>[0], taskId: string, timeoutMs = 15000) {
  const deadline = Date.now() + timeoutMs;
  while (Date.now() < deadline) {
    const res = await page.request.get(`/admin/api/task_log.php?id=${encodeURIComponent(taskId)}&page=1`);
    if (res.ok()) {
      const json = await res.json();
      if (Array.isArray(json.lines) && json.lines.length > 0) {
        return json;
      }
    }
    await page.waitForTimeout(500);
  }
  throw new Error('task log did not receive lines in time');
}

test('scheduled tasks use fixed workdir with log pagination and copy directory interactions', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
    ],
  });
  const ts = Date.now();
  const taskName = `模式任务 ${ts}`;

  await loginAsDevAdmin(page);
  await page.goto('/admin/scheduled_tasks.php');
  await page.getByRole('button', { name: /新建任务/ }).click();

  await expect(page.locator('#fm-working-dir-mode')).toHaveCount(0);
  await expect(page.locator('#fm-working-dir')).toHaveCount(0);
  await expect(page.locator('#fm-workdir-preview')).toHaveCount(0);
  await expect(page.locator('#fm-script-filename')).toHaveCount(0);
  await expect(page.locator('#fm-script-path')).toHaveCount(0);
  await expect(page.locator('#fm-log-filename')).toHaveCount(0);
  await expect(page.locator('#fm-log-path')).toHaveCount(0);
  await page.locator('#fm-name').fill(taskName);
  await page.locator('#fm-schedule').fill('*/10 * * * *');
  await page.locator('#fm-command').fill(Array.from({ length: 35 }, (_, i) => `echo line-${i + 1}`).join('\n'));
  await page.getByRole('button', { name: '💾 保存' }).click({ force: true });
  await expect(page.locator('body')).toContainText(/已保存并更新 crontab|已保存/);

  const row = page.locator('tr', { hasText: taskName }).last();
  const onclickAttr = await row.getByRole('button', { name: /日志/ }).getAttribute('onclick');
  const taskId = onclickAttr?.match(/openLogModal\("([^"]+)"/)?.[1] ?? '';
  expect(taskId).not.toBe('');
  await expect
    .poll(async () => {
      try {
        const stat = await fs.stat(tasksRootPath);
        return stat.isDirectory();
      } catch {
        return false;
      }
    }, { timeout: 10000 })
    .toBe(true);
  const workdirText = await row.locator('td').nth(2).textContent();
  await row.getByRole('button', { name: /立即执行/ }).click({ force: true });
  await expect(page.locator('body')).toContainText(/已开始后台执行|后台执行已在运行中/);
  await waitForTaskLogLines(page, taskId);

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
  await page.evaluate((path) => {
    const fn = (window as Window & { copyTaskWorkdir?: (taskPath: string) => void }).copyTaskWorkdir;
    if (typeof fn !== 'function') throw new Error('copyTaskWorkdir not found');
    fn(path);
  }, '/var/www/nav/data/tasks');
  const copied = await page.evaluate(() => (window as any).__copiedText || '');
  expect(copied).toBe('/var/www/nav/data/tasks');
  expect(workdirText || '').toContain('/var/www/nav/data/tasks');

  await row.getByRole('button', { name: /日志/ }).click({ force: true });
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
  await page.getByRole('button', { name: /清空日志/ }).click({ force: true });
  await page.waitForTimeout(500);
  await page.keyboard.press('Escape');
  await expect(page.locator('#log-modal')).toBeHidden();

  await page.getByRole('button', { name: /新建任务/ }).click();
  await expect(page.locator('#fm-workdir-preview')).toHaveCount(0);
  await expect(page.locator('#fm-script-filename')).toHaveCount(0);
  await expect(page.locator('#fm-script-path')).toHaveCount(0);
  await expect(page.locator('#fm-log-filename')).toHaveCount(0);
  await expect(page.locator('#fm-log-path')).toHaveCount(0);
  await page.getByRole('button', { name: /取消/ }).click({ force: true });
  await expect(page.locator('#task-modal')).toBeHidden();

  await tracker.assertNoClientErrors();
});
