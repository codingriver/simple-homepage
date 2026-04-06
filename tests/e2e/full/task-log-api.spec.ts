import { test, expect } from '@playwright/test';
import { attachClientErrorTracking, loginAsDevAdmin } from '../../helpers/auth';

test('task log api enforces auth and returns paged payload after a task run', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 403 \(Forbidden\)/,
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
    ],
  });
  const id = `tasklog-${Date.now()}`;

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
  await page.locator('#fm-name').fill('日志接口任务');
  await page.locator('#fm-schedule').fill('*/30 * * * *');
  await page.locator('#fm-command').fill('echo api-log-line-1\necho api-log-line-2');
  await page.getByRole('button', { name: '💾 保存' }).click();
  await expect(page.locator('body')).toContainText(/已保存并更新 crontab|已保存/);

  const row = page.locator('tr', { hasText: '日志接口任务' }).first();
  const taskId = await row.locator('form input[name="id"]').first().inputValue();
  await row.getByRole('button', { name: /立即执行/ }).click();
  await expect(page.locator('body')).toContainText(/执行完成|执行失败/);

  const missingId = await page.evaluate(async () => {
    const res = await fetch('/admin/api/task_log.php?page=1', { credentials: 'include' });
    return { status: res.status, json: await res.json() };
  });
  expect(missingId.status).toBe(400);
  expect(missingId.json.error).toBe('missing id');

  const ok = await page.evaluate(async (taskId) => {
    const res = await fetch(`/admin/api/task_log.php?id=${taskId}&page=1`, { credentials: 'include' });
    return { status: res.status, json: await res.json() };
  }, taskId);
  expect(ok.status).toBe(200);
  expect(Array.isArray(ok.json.lines)).toBeTruthy();
  expect(ok.json.page).toBe(1);
  expect(ok.json.pages).toBeGreaterThanOrEqual(1);

  await tracker.assertNoClientErrors();
});
