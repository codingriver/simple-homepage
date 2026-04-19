import fs from 'fs/promises';
import path from 'path';
import { test, expect } from '../../helpers/fixtures';
import { attachClientErrorTracking, loginAsDevAdmin } from '../../helpers/auth';

const scheduledTasksFile = path.resolve(__dirname, '../../../data/scheduled_tasks.json');

test('task templates create_task_from_template action works directly via post', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page);
  const ts = Date.now();
  const taskName = `DirectTemplateTask${ts}`;

  await fs.writeFile(scheduledTasksFile, JSON.stringify({ tasks: {} }, null, 2), 'utf8');

  try {
    await loginAsDevAdmin(page);
    await page.goto('/admin/task_templates.php');

    // use a template without required variables
    const firstTemplateId = 'tpl_nginx_reload';

    const csrf = await page.locator('input[name="_csrf"]').first().inputValue();

    const saveRes = await page.request.post('http://127.0.0.1:58080/admin/task_templates.php', {
      form: {
        action: 'create_task_from_template',
        _csrf: csrf,
        template_id: firstTemplateId,
        task_name: taskName,
        schedule: '*/15 * * * *',
      },
      maxRedirects: 0,
    });
    expect(saveRes.status()).toBe(302);
    expect(saveRes.headers()['location'] || '').toContain('scheduled_tasks.php');

    const tasks = JSON.parse(await fs.readFile(scheduledTasksFile, 'utf8'));
    const found = Object.values(tasks.tasks ?? {}).some(
      (t) => (t as { name?: string }).name === taskName
    );
    expect(found).toBe(true);
  } finally {
    const tasks = JSON.parse(await fs.readFile(scheduledTasksFile, 'utf8').catch(() => '{"tasks":{}}'));
    Object.keys(tasks.tasks ?? {}).forEach((k) => {
      if ((tasks.tasks[k] as { name?: string }).name?.startsWith('DirectTemplateTask')) {
        delete tasks.tasks[k];
      }
    });
    await fs.writeFile(scheduledTasksFile, JSON.stringify(tasks, null, 2), 'utf8');
  }

  await tracker.assertNoClientErrors();
});
