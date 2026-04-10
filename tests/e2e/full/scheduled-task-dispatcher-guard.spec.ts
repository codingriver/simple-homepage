import { test, expect } from '@playwright/test';
import { attachClientErrorTracking, loginAsDevAdmin } from '../../helpers/auth';

async function openDdnsDispatcherTab(page: Parameters<typeof loginAsDevAdmin>[0]) {
  await page.getByRole('tab', { name: /DDNS 调度器/ }).click();
  await expect(page.locator('#scheduled-tab-panel-ddns')).toBeVisible();
}

async function submitSystemTaskAction(
  page: Parameters<typeof loginAsDevAdmin>[0],
  action: 'task_toggle' | 'task_delete' | 'task_save',
  id: string
) {
  const csrf = await page.locator('input[name="_csrf"]').first().inputValue();
  await page.evaluate(
    ({ csrfToken, nextAction, taskId }) => {
      const form = document.createElement('form');
      form.method = 'POST';
      form.action = '/admin/scheduled_tasks.php';

      const fields: Record<string, string> = {
        _csrf: csrfToken,
        action: nextAction,
        id: taskId,
      };

      if (nextAction === 'task_save') {
        fields.name = 'System Dispatcher Override';
        fields.schedule = '*/30 * * * *';
        fields.command = 'echo should-not-save';
      }

      Object.entries(fields).forEach(([name, value]) => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = name;
        input.value = value;
        form.appendChild(input);
      });

      document.body.appendChild(form);
      form.submit();
    },
    { csrfToken: csrf, nextAction: action, taskId: id }
  );
}

test('scheduled tasks system dispatchers remain view-only while manual tasks stay editable', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
      /Failed to load resource: the server responded with a status of 403 \(Forbidden\)/,
    ],
  });

  await loginAsDevAdmin(page);
  await page.goto('/admin/scheduled_tasks.php');
  await openDdnsDispatcherTab(page);

  const systemRow = page.locator('tr:has-text("DDNS 调度器")').first();
  if (await systemRow.count()) {
    await expect(systemRow.getByRole('button', { name: /系统维护|自动维护/ }).first()).toBeDisabled();
    await expect(systemRow.locator('text=系统任务')).toBeVisible();
  }

  await page.getByRole('button', { name: /新建任务/ }).click();
  await page.locator('#fm-name').fill(`手动任务 ${Date.now()}`);
  await page.locator('#fm-schedule').fill('*/20 * * * *');
  await page.locator('#fm-command').fill('echo dispatcher-guard');
  await page.locator('#task-form').getByRole('button', { name: /保存/ }).click();
  await expect(page.locator('body')).toContainText(/已保存并更新 crontab|已保存/);
  await expect(page.locator('#scheduled-tab-panel-tasks')).toBeVisible();
  const manualRow = page.locator('tr:has-text("手动任务")').first();
  await expect(manualRow.getByRole('button', { name: /编辑/ })).toBeVisible();
  await expect(manualRow.getByRole('button', { name: /删除/ })).toBeVisible();

  await tracker.assertNoClientErrors();
});

test('scheduled tasks reject direct save delete and toggle posts for DDNS dispatcher ids', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
      /Failed to load resource: the server responded with a status of 403 \(Forbidden\)/,
    ],
  });

  await loginAsDevAdmin(page);
  await page.goto('/admin/scheduled_tasks.php');
  await openDdnsDispatcherTab(page);

  const systemRow = page.locator('tr:has-text("DDNS 调度器")').first();
  await expect(systemRow).toBeVisible();
  const dispatcherId = await systemRow.locator('input[name="id"]').first().inputValue();
  expect(dispatcherId).toMatch(/^sys_ddns_dispatcher_/);

  await submitSystemTaskAction(page, 'task_toggle', dispatcherId);
  await expect(page.locator('body')).toContainText('DDNS 调度器由系统自动维护，不能手动启停');

  await submitSystemTaskAction(page, 'task_delete', dispatcherId);
  await expect(page.locator('body')).toContainText('DDNS 调度器由系统自动维护，不能手动删除');

  await submitSystemTaskAction(page, 'task_save', dispatcherId);
  await expect(page.locator('body')).toContainText('DDNS 调度器由系统自动维护，不能手动编辑');

  await tracker.assertNoClientErrors();
});
