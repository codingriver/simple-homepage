import fs from 'fs/promises';
import path from 'path';
import { expect, test } from '../../helpers/fixtures';
import { attachClientErrorTracking, loginAsDevAdmin } from '../../helpers/auth';

const scheduledTasksFile = path.resolve(__dirname, '../../../data/scheduled_tasks.json');

test('task templates page can create a scheduled task from a built-in template', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page);
  const ts = Date.now();
  const taskName = `TemplateTask${ts}`;

  // clear existing scheduled tasks
  await fs.writeFile(scheduledTasksFile, JSON.stringify({ tasks: {} }, null, 2), 'utf8');

  await loginAsDevAdmin(page);
  await page.goto('/admin/task_templates.php');

  await expect(page.locator('body')).toContainText('任务模板中心');

  // find the first template card and fill it
  const firstCard = page.locator('.card').filter({ has: page.locator('input[name="template_id"]') }).first();
  await expect(firstCard).toBeVisible();

  const templateId = await firstCard.locator('input[name="template_id"]').inputValue();
  expect(templateId).toBeTruthy();

  await firstCard.locator('input[name="task_name"]').fill(taskName);
  await firstCard.locator('input[name="schedule"]').fill('*/10 * * * *');

  // fill any required variables
  const varInputs = firstCard.locator('input[name^="vars["]');
  const count = await varInputs.count();
  for (let i = 0; i < count; i++) {
    const input = varInputs.nth(i);
    if (await input.evaluate((el: HTMLInputElement) => el.required)) {
      await input.fill('test-value');
    }
  }

  await firstCard.getByRole('button', { name: '从模板创建任务' }).click();

  // should redirect to scheduled_tasks.php on success
  await expect(page).toHaveURL(/scheduled_tasks\.php/);
  await expect(page.locator('body')).toContainText(taskName);

  // verify persisted
  const tasksAfter = JSON.parse(await fs.readFile(scheduledTasksFile, 'utf8').catch(() => '{}'));
  const found = Object.values(tasksAfter.tasks ?? {}).some((t: any) => (t.name || '') === taskName);
  expect(found).toBe(true);

  await tracker.assertNoClientErrors();
});
