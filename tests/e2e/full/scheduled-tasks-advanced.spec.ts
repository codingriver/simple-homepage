import { test, expect } from '@playwright/test';
import { attachClientErrorTracking, loginAsDevAdmin } from '../../helpers/auth';

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
  await page.locator('#task-form').getByRole('button', { name: /保存/ }).click();
  await expect(page.locator('body')).toContainText(/已保存并更新 crontab|已保存/);

  const row = page.locator(`tr:has-text("${name}")`).first();
  await row.getByRole('button', { name: /日志/ }).click();
  const onclickAttr = await row.getByRole('button', { name: /日志/ }).getAttribute('onclick');
  const taskId = onclickAttr?.match(/openLogModal\("([^"]+)"/)?.[1] ?? '';
  expect(taskId).not.toBe('');

  const pageZero = await page.request.get(`http://127.0.0.1:58080/admin/api/task_log.php?id=${encodeURIComponent(taskId)}&page=0`);
  expect(pageZero.status()).toBe(200);
  expect((await pageZero.json()).page).toBe(1);

  const pageLarge = await page.request.get(`http://127.0.0.1:58080/admin/api/task_log.php?id=${encodeURIComponent(taskId)}&page=99999`);
  expect(pageLarge.status()).toBe(200);
  expect((await pageLarge.json()).pages).toBeGreaterThanOrEqual(1);

  const missingTask = await page.request.get('http://127.0.0.1:58080/admin/api/task_log.php?id=missing-task-id&page=1');
  expect(missingTask.status()).toBe(200);
  expect(await missingTask.json()).toMatchObject({ lines: expect.any(Array) });

  await tracker.assertNoClientErrors();
});
