import fs from 'fs/promises';
import path from 'path';
import { test, expect } from '../../helpers/fixtures';
import { attachClientErrorTracking, loginAsDevAdmin } from '../../helpers/auth';

const scheduledTasksFile = path.resolve(__dirname, '../../../data/scheduled_tasks.json');

test('task templates can create a scheduled task from built-in health-check template', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
    ],
  });
  const ts = Date.now();
  const taskName = `tmpl_health_${ts}`;

  await loginAsDevAdmin(page);
  await page.goto('/admin/task_templates.php');
  await expect(page.locator('body')).toContainText('任务模板中心');

  const form = page.locator('form:has(input[name="template_id"][value="tpl_health_check"])').first();
  await form.locator('input[name="task_name"]').fill(taskName);
  await form.locator('input[name="schedule"]').fill('*/9 * * * *');
  await form.locator('input[name="vars[target_url]"]').fill('http://127.0.0.1:58080/login.php');
  await form.getByRole('button', { name: /从模板创建任务/ }).click();

  await expect(page).toHaveURL(/admin\/scheduled_tasks\.php/);
  const row = page.locator(`tr[data-task-row]:has-text("${taskName}")`).first();
  await expect(row).toBeVisible();

  const raw = JSON.parse(await fs.readFile(scheduledTasksFile, 'utf8')) as {
    tasks?: Array<{ name?: string; schedule?: string; command?: string }>;
  };
  const task = raw.tasks?.find((item) => item.name === taskName);
  expect(task?.schedule).toBe('*/9 * * * *');
  expect(task?.command || '').toContain("curl -fsS -o /dev/null -w 'HTTP %{http_code}");
  expect(task?.command || '').toContain('http://127.0.0.1:58080/login.php');

  await tracker.assertNoClientErrors();
});
