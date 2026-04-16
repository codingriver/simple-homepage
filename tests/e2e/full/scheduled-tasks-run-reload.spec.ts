import fs from 'fs/promises';
import path from 'path';
import { test, expect } from '../../helpers/fixtures';
import { attachClientErrorTracking, loginAsDevAdmin } from '../../helpers/auth';

const scheduledTasksFile = path.resolve(__dirname, '../../../data/scheduled_tasks.json');

test('scheduled tasks run cron reload and log clear actions work end to end', async ({ page }) => {
  test.setTimeout(120000);
  const tracker = await attachClientErrorTracking(page);
  const ts = Date.now();
  const taskId = `e2e-run-reload-${ts}`;

  const tasksPayload = {
    tasks: {
      [taskId]: {
        id: taskId,
        name: `E2E Run Reload ${ts}`,
        schedule: '0 0 1 1 *',
        command: 'echo scheduled-task-run-reload',
        enabled: true,
        created_at: new Date().toISOString(),
      },
    },
  };
  await fs.writeFile(scheduledTasksFile, JSON.stringify(tasksPayload, null, 2), 'utf8');

  // seed a log file for the task
  const logPath = path.resolve(__dirname, `../../../data/logs/tasks/${taskId}.log`);
  await fs.mkdir(path.dirname(logPath), { recursive: true });
  await fs.writeFile(logPath, 'line1\nline2\n', 'utf8');

  try {
    await loginAsDevAdmin(page);
    await page.goto('/admin/scheduled_tasks.php');

    const csrf = await page.locator('input[name="_csrf"]').first().inputValue();

    // task_run
    const runRes = await page.request.post('http://127.0.0.1:58080/admin/scheduled_tasks.php', {
      form: { action: 'task_run', id: taskId, _csrf: csrf },
      maxRedirects: 0,
    });
    expect(runRes.status()).toBe(302);

    // cron_reload
    const reloadRes = await page.request.post('http://127.0.0.1:58080/admin/scheduled_tasks.php', {
      form: { action: 'cron_reload', _csrf: csrf },
      maxRedirects: 0,
    });
    expect(reloadRes.status()).toBe(302);

    // task_log_clear
    const clearRes = await page.request.post('http://127.0.0.1:58080/admin/scheduled_tasks.php', {
      form: { action: 'task_log_clear', id: taskId, _csrf: csrf },
      maxRedirects: 0,
    });
    expect(clearRes.status()).toBe(302);

    const logExists = await fs.access(logPath).then(() => true).catch(() => false);
    expect(logExists).toBe(false);
  } finally {
    const tasks = JSON.parse(await fs.readFile(scheduledTasksFile, 'utf8').catch(() => '{"tasks":{}}'));
    delete tasks.tasks[taskId];
    await fs.writeFile(scheduledTasksFile, JSON.stringify(tasks, null, 2), 'utf8');
    await fs.rm(logPath, { force: true }).catch(() => undefined);
  }

  await tracker.assertNoClientErrors();
});
