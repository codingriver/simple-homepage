import fs from 'fs/promises';
import path from 'path';
import { test, expect } from '@playwright/test';
import { attachClientErrorTracking, loginAsDevAdmin } from '../../helpers/auth';

const tasksRoot = path.resolve(__dirname, '../../../data/tasks');

async function waitForTaskLogLines(page: Parameters<typeof loginAsDevAdmin>[0], taskId: string, timeoutMs = 30000) {
  const deadline = Date.now() + timeoutMs;
  while (Date.now() < deadline) {
    const res = await page.evaluate(async (id) => {
      const response = await fetch(`/admin/api/task_log.php?id=${id}&page=1`, { credentials: 'include' });
      return { status: response.status, json: await response.json() };
    }, taskId);
    if (res.status === 200 && Array.isArray(res.json.lines) && res.json.lines.length > 0) {
      return res;
    }
    await page.waitForTimeout(500);
  }
  throw new Error('task log did not receive lines in time');
}

test('task log api enforces auth and returns paged payload after a task run', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 403 \(Forbidden\)/,
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
    ],
  });
  const id = `tasklog-${Date.now()}`;
  const taskName = `日志接口任务 ${Date.now()}`;

  await page.goto('/login.php');
  const denied = await page.evaluate(async () => {
    const res = await fetch('/admin/api/task_log.php?id=demo&page=1', { credentials: 'include' });
    return { status: res.status, json: await res.json() };
  });
  expect(denied.status).toBe(403);
  expect(denied.json.error).toBe('forbidden');

  await loginAsDevAdmin(page);
  await page.goto('/admin/scheduled_tasks.php');
  await page.getByRole('button', { name: /新建任务/ }).click();
  await page.locator('#fm-name').fill(taskName);
  await page.locator('#fm-schedule').fill('*/30 * * * *');
  await page.locator('#fm-command').fill('echo api-log-line-1\necho api-log-line-2');
  await page.getByRole('button', { name: '💾 保存' }).click();
  await expect(page.locator('body')).toContainText(/已保存并更新 crontab|已保存/);

  const row = page.locator('tr', { hasText: taskName }).last();
  const taskId = await row.locator('form input[name="id"]').first().inputValue();
  await row.getByRole('button', { name: /编辑/ }).click();
  const taskLogFilename = (await page.locator('#fm-log-filename').textContent())?.trim() || '';
  expect(taskLogFilename).toBeTruthy();
  await page.getByRole('button', { name: /取消/ }).click();
  await row.getByRole('button', { name: /立即执行/ }).click();
  await expect(page.locator('body')).toContainText(/已开始后台执行|后台执行已在运行中/);

  const missingId = await page.evaluate(async () => {
    const res = await fetch('/admin/api/task_log.php?page=1', { credentials: 'include' });
    return { status: res.status, json: await res.json() };
  });
  expect(missingId.status).toBe(400);
  expect(missingId.json.error).toBe('missing id');

  const ok = await waitForTaskLogLines(page, taskId);
  expect(ok.status).toBe(200);
  expect(Array.isArray(ok.json.lines)).toBeTruthy();
  expect(ok.json.lines.length).toBeGreaterThan(0);
  expect(ok.json.page).toBe(1);
  expect(ok.json.pages).toBeGreaterThanOrEqual(1);
  await expect
    .poll(async () => {
      try {
        return await fs.readFile(path.join(tasksRoot, taskLogFilename), 'utf8');
      } catch {
        return '';
      }
    }, { timeout: 10000 })
    .toContain('api-log-line-1');

  await tracker.assertNoClientErrors();
});
