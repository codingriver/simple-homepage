import { test, expect } from '@playwright/test';
import { attachClientErrorTracking, loginAsDevAdmin } from '../../helpers/auth';

test('scheduled tasks system dispatchers remain view-only while manual tasks stay editable', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
      /Failed to load resource: the server responded with a status of 403 \(Forbidden\)/,
    ],
  });

  await loginAsDevAdmin(page);
  await page.goto('/admin/scheduled_tasks.php');

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
  const manualRow = page.locator('tr:has-text("手动任务")').first();
  await expect(manualRow.getByRole('button', { name: /编辑/ })).toBeVisible();
  await expect(manualRow.getByRole('button', { name: /删除/ })).toBeVisible();

  await tracker.assertNoClientErrors();
});
