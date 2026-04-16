import fs from 'fs/promises';
import path from 'path';
import { test, expect } from '../../helpers/fixtures';
import { attachClientErrorTracking, loginAsDevAdmin } from '../../helpers/auth';

const scheduledTasksFile = path.resolve(__dirname, '../../../data/scheduled_tasks.json');

test('scheduled tasks advanced cases normalize invalid pages and keep system dispatchers guarded', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
      /Failed to load resource: the server responded with a status of 403 \(Forbidden\)/,
    ],
  });
  const ts = Date.now();
  const name = `高级任务 ${ts}`;

  await loginAsDevAdmin(page);
  await page.goto('/admin/scheduled_tasks.php');
  await page.getByRole('button', { name: /新建任务/ }).click();
  await page.locator('#fm-name').fill(name);
  await page.locator('#fm-schedule').fill('*/12 * * * *');
  await page.locator('#fm-command').fill('echo scheduled-advanced');
  await page.locator('#task-form').getByRole('button', { name: /保存/ }).click({ force: true });
  await expect(page.locator('body')).toContainText(/已保存并更新 crontab|已保存/);

  const row = page.locator(`tr:has-text("${name}")`).first();
  const onclickAttr = await row.getByRole('button', { name: /日志/ }).getAttribute('onclick');
  const taskId = onclickAttr?.match(/openLogModal\("([^"]+)"/)?.[1] ?? '';
  expect(taskId).not.toBe('');
  await page.evaluate(
    ({ id, taskName }) => {
      const fn = (window as Window & { openLogModal?: (taskId: string, taskName: string) => void }).openLogModal;
      if (typeof fn !== 'function') throw new Error('openLogModal not found');
      fn(id, taskName);
    },
    { id: taskId, taskName: name }
  );

  const pageZero = await page.request.get(`http://127.0.0.1:58080/admin/api/task_log.php?id=${encodeURIComponent(taskId)}&page=0`);
  expect(pageZero.status()).toBe(200);
  expect((await pageZero.json()).page).toBe(1);

  const pageLarge = await page.request.get(`http://127.0.0.1:58080/admin/api/task_log.php?id=${encodeURIComponent(taskId)}&page=99999`);
  expect(pageLarge.status()).toBe(200);
  const pageLargePayload = await pageLarge.json();
  expect(pageLargePayload.page).toBeGreaterThanOrEqual(1);
  expect(pageLargePayload.pages).toBeGreaterThanOrEqual(0);

  const missingTask = await page.request.get('http://127.0.0.1:58080/admin/api/task_log.php?id=missing-task-id&page=1');
  expect(missingTask.status()).toBe(200);
  expect(await missingTask.json()).toMatchObject({ lines: expect.any(Array) });

  await tracker.assertNoClientErrors();
});

test('scheduled tasks default to newest-first order by creation time', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
      /Failed to load resource: the server responded with a status of 403 \(Forbidden\)/,
    ],
  });
  const ts = Date.now();
  const olderName = `排序旧任务 ${ts}`;
  const newerName = `排序新任务 ${ts}`;

  await loginAsDevAdmin(page);
  await page.goto('/admin/scheduled_tasks.php');

  await page.getByRole('button', { name: /新建任务/ }).click();
  await page.locator('#fm-name').fill(olderName);
  await page.locator('#fm-schedule').fill('*/17 * * * *');
  await page.locator('#fm-command').fill('echo older-task');
  await page.locator('#task-form').getByRole('button', { name: /保存/ }).click({ force: true });
  await expect(page.locator('body')).toContainText(/已保存并更新 crontab|已保存/);

  await page.getByRole('button', { name: /新建任务/ }).click();
  await page.locator('#fm-name').fill(newerName);
  await page.locator('#fm-schedule').fill('*/19 * * * *');
  await page.locator('#fm-command').fill('echo newer-task');
  await page.locator('#task-form').getByRole('button', { name: /保存/ }).click({ force: true });
  await expect(page.locator('body')).toContainText(/已保存并更新 crontab|已保存/);

  const firstRowText = await page.locator('#scheduled-tab-panel-tasks tbody tr').first().textContent();
  expect(firstRowText || '').toContain(newerName);
  expect(firstRowText || '').not.toContain(olderName);

  await tracker.assertNoClientErrors();
});

test('scheduled tasks refresh running state per row without full page reload', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
      /Failed to load resource: the server responded with a status of 403 \(Forbidden\)/,
    ],
  });
  const ts = Date.now();
  const name = `状态轮询任务 ${ts}`;

  await loginAsDevAdmin(page);
  await page.goto('/admin/scheduled_tasks.php');
  await page.getByRole('button', { name: /新建任务/ }).click();
  await page.locator('#fm-name').fill(name);
  await page.locator('#fm-schedule').fill('*/11 * * * *');
  await page.locator('#fm-command').fill('echo polling-start\nsleep 3\necho polling-end');
  await page.locator('#task-form').getByRole('button', { name: /保存/ }).click({ force: true });
  await expect(page.locator('body')).toContainText(/已保存并更新 crontab|已保存/);

  const rowSelector = `tr[data-task-row]:has-text("${name}")`;
  const row = page.locator(rowSelector).first();
  const taskId = await row.locator('form input[name="id"]').first().inputValue();

  await row.locator('[data-task-run-btn]').click({ force: true });
  await expect(page.locator('body')).toContainText(/已开始后台执行|后台执行已在运行中/);

  const reloadedRow = page.locator(rowSelector).first();
  const runBtn = reloadedRow.locator('[data-task-run-btn]');
  const lastRunCell = reloadedRow.locator('[data-task-last-run]');
  const exitCell = reloadedRow.locator('[data-task-exit]');

  await expect(runBtn).toHaveText('运行中', { timeout: 4000 });
  await expect(runBtn).toBeDisabled();
  await expect(runBtn).toHaveText('▶▶ 立即执行', { timeout: 12000 });
  await expect(runBtn).toBeEnabled();
  await expect(lastRunCell).not.toHaveText('—', { timeout: 4000 });
  await expect(exitCell).toContainText('0', { timeout: 4000 });

  const statusResponse = await page.request.get(`http://127.0.0.1:58080/admin/api/task_status.php?ids=${encodeURIComponent(taskId)}`);
  expect(statusResponse.status()).toBe(200);
  const statusPayload = await statusResponse.json();
  expect(statusPayload.tasks?.[taskId]?.running).toBe(false);

  await tracker.assertNoClientErrors();
});

test('scheduled tasks auto-correct stale running state when no active lock exists', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
      /Failed to load resource: the server responded with a status of 403 \(Forbidden\)/,
    ],
  });
  const ts = Date.now();
  const name = `脏状态任务 ${ts}`;

  await loginAsDevAdmin(page);
  await page.goto('/admin/scheduled_tasks.php');
  await page.getByRole('button', { name: /新建任务/ }).click();
  await page.locator('#fm-name').fill(name);
  await page.locator('#fm-schedule').fill('*/13 * * * *');
  await page.locator('#fm-command').fill('echo stale-running-fix');
  await page.locator('#task-form').getByRole('button', { name: /保存/ }).click({ force: true });
  await expect(page.locator('body')).toContainText(/已保存并更新 crontab|已保存/);

  const row = page.locator(`tr[data-task-row]:has-text("${name}")`).first();
  const taskId = await row.locator('form input[name="id"]').first().inputValue();

  const payload = JSON.parse(await fs.readFile(scheduledTasksFile, 'utf8')) as { tasks: Array<Record<string, any>> };
  const task = payload.tasks.find((item) => item.id === taskId);
  if (!task) throw new Error(`task ${taskId} not found`);
  task.runtime = { ...(task.runtime || {}), running: true, started_at: '2026-04-10 10:00:00' };
  task.last_run = '';
  task.last_code = null;
  await fs.writeFile(scheduledTasksFile, JSON.stringify(payload, null, 2), 'utf8');

  const statusResponse = await page.request.get(`http://127.0.0.1:58080/admin/api/task_status.php?ids=${encodeURIComponent(taskId)}`);
  expect(statusResponse.status()).toBe(200);
  const statusPayload = await statusResponse.json();
  expect(statusPayload.tasks?.[taskId]?.running).toBe(false);
  expect(statusPayload.tasks?.[taskId]?.started_at).toBe('');

  await expect(row.locator('[data-task-run-btn]')).toHaveText('▶▶ 立即执行', { timeout: 4000 });

  const refreshedPayload = JSON.parse(await fs.readFile(scheduledTasksFile, 'utf8')) as { tasks: Array<Record<string, any>> };
  const refreshedTask = refreshedPayload.tasks.find((item) => item.id === taskId);
  expect(refreshedTask?.runtime?.running).toBe(false);
  expect(refreshedTask?.runtime?.started_at).toBe('');

  await tracker.assertNoClientErrors();
});
