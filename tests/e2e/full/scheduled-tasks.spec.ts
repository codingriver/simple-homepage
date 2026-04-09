import { test, expect } from '@playwright/test';
import { attachClientErrorTracking, loginAsDevAdmin } from '../../helpers/auth';

async function disableNativeTaskFormValidation(page: Parameters<typeof loginAsDevAdmin>[0]) {
  await page.evaluate(() => {
    const form = document.getElementById('task-form') as HTMLFormElement | null;
    if (form) form.noValidate = true;
  });
}

test('scheduled tasks support create edit toggle run log clear and delete', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
    ],
  });
  const ts = Date.now();
  const name = `任务 ${ts}`;
  const editedName = `任务已编辑 ${ts}`;

  await loginAsDevAdmin(page);
  await page.goto('/admin/scheduled_tasks.php');
  await page.getByRole('button', { name: /新建任务/ }).click();

  await page.locator('#fm-name').fill(name);
  await page.locator('#fm-schedule').fill('*/5 * * * *');
  await expect(page.locator('#fm-workdir-preview')).toContainText('/var/www/nav/data/tasks/');
  await page.locator('#fm-command').fill('echo from-task');
  await page.locator('#task-form').getByRole('button', { name: /保存/ }).click();
  await expect(page.locator('body')).toContainText(/已保存并更新 crontab|crontab/);
  const row = page.locator(`tr:has-text("${name}")`).first();
  await expect(row).toBeVisible();
  await expect(row).toContainText('启用');

  await row.getByRole('button', { name: /编辑/ }).click();
  await page.locator('#fm-name').fill(editedName);
  await page.locator('#fm-command').fill('echo edited-task');
  await page.locator('#task-form').getByRole('button', { name: /保存/ }).click();
  await expect(page.locator(`tr:has-text("${editedName}")`).first()).toBeVisible();

  const editedRow = page.locator(`tr:has-text("${editedName}")`).first();
  await editedRow.getByRole('button', { name: /禁用/ }).click();
  await expect(page.locator('body')).toContainText('已禁用');
  await editedRow.getByRole('button', { name: /启用/ }).click();
  await expect(page.locator('body')).toContainText('已启用');

  await editedRow.getByRole('button', { name: /立即执行/ }).click();
  await expect(page.locator('body')).toContainText(/执行完成|执行失败/);

  await editedRow.getByRole('button', { name: /日志/ }).click();
  await expect(page.locator('#log-modal')).toBeVisible();
  const logButtonOnclick = await editedRow.getByRole('button', { name: /日志/ }).getAttribute('onclick');
  const taskId = logButtonOnclick?.match(/openLogModal\("([^"]+)"/)?.[1] ?? '';
  expect(taskId).not.toBe('');
  page.once('dialog', dialog => dialog.accept());
  await page.locator('#log-modal').getByRole('button', { name: /清空日志/ }).click();
  await page.waitForURL(/\/admin\/scheduled_tasks\.php/);
  const clearedLog = await page.request.get(`/admin/api/task_log.php?id=${encodeURIComponent(taskId)}&page=1`);
  expect(clearedLog.ok()).toBeTruthy();
  const clearedLogJson = await clearedLog.json();
  expect(clearedLogJson.lines ?? []).toHaveLength(0);

  page.once('dialog', dialog => dialog.accept());
  await page.locator(`tr:has-text("${editedName}")`).first().getByRole('button', { name: /删除/ }).click();
  await expect(page.locator(`tr:has-text("${editedName}")`)).toHaveCount(0);

  await tracker.assertNoClientErrors();
});

test('scheduled tasks validate invalid fields and support crontab reload', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
    ],
  });
  const ts = Date.now();

  await loginAsDevAdmin(page);
  await page.goto('/admin/scheduled_tasks.php');
  await page.getByRole('button', { name: /新建任务/ }).click();
  await disableNativeTaskFormValidation(page);

  await page.evaluate(() => {
    const input = document.getElementById('fm-id') as HTMLInputElement | null;
    if (input) input.value = 'bad id';
  });
  await page.locator('#fm-name').fill(`非法任务 ${ts}`);
  await page.locator('#fm-schedule').fill('*/5 * * * *');
  await page.locator('#fm-command').fill('echo invalid-id');
  await page.locator('#task-form').getByRole('button', { name: /保存/ }).click();
  await expect(page.locator('body')).toContainText('任务 ID 仅允许字母数字、下划线、短横线');

  await page.getByRole('button', { name: /新建任务/ }).click();
  await disableNativeTaskFormValidation(page);
  await page.evaluate(() => {
    const input = document.getElementById('fm-id') as HTMLInputElement | null;
    if (input) input.value = '';
  });
  await page.locator('#fm-name').fill('');
  await page.locator('#fm-schedule').fill('*/5 * * * *');
  await page.locator('#fm-command').fill('echo empty-name');
  await page.locator('#task-form').getByRole('button', { name: /保存/ }).click();
  await expect(page.locator('body')).toContainText('请填写任务名称');

  await page.getByRole('button', { name: /新建任务/ }).click();
  await disableNativeTaskFormValidation(page);
  await page.locator('#fm-name').fill(`非法 cron ${ts}`);
  await page.locator('#fm-schedule').fill('* * *');
  await page.locator('#fm-command').fill('echo invalid-cron');
  await page.locator('#task-form').getByRole('button', { name: /保存/ }).click();
  await expect(page.locator('body')).toContainText('Cron 表达式无效');

  await page.getByRole('button', { name: /新建任务/ }).click();
  await expect(page.locator('#fm-workdir-preview')).toContainText('/var/www/nav/data/tasks/');
  await expect(page.locator('#fm-working-dir-mode')).toHaveCount(0);
  await expect(page.locator('#fm-working-dir')).toHaveCount(0);
  await page.getByRole('button', { name: /取消/ }).click();

  await page.getByRole('button', { name: /重新安装 crontab/ }).click();
  await expect(page.locator('body')).toContainText(/已重新安装 crontab|crontab/);

  await tracker.assertNoClientErrors();
});
