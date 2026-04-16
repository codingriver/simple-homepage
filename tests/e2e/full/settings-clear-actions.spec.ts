import fs from 'fs/promises';
import path from 'path';
import { expect, test } from '../../helpers/fixtures';
import { attachClientErrorTracking, loginAsDevAdmin } from '../../helpers/auth';

const scheduledTasksFile = path.resolve(__dirname, '../../../data/scheduled_tasks.json');
const ddnsTasksFile = path.resolve(__dirname, '../../../data/ddns_tasks.json');

test('settings clear scheduled tasks and clear ddns tasks remove all user tasks', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page);

  const ts = Date.now();

  // seed scheduled tasks
  await fs.writeFile(
    scheduledTasksFile,
    JSON.stringify({
      tasks: {
        [`user-task-${ts}`]: {
          id: `user-task-${ts}`,
          name: 'User Task',
          cron: '0 * * * *',
          command: 'echo test',
          enabled: true,
          type: 'manual',
        },
        [`sys_ddns_dispatcher_${ts}`]: {
          id: `sys_ddns_dispatcher_${ts}`,
          name: 'DDNS Dispatcher',
          cron: '*/5 * * * *',
          command: 'php cli/ddns_sync.php',
          enabled: true,
          type: 'system',
        },
      },
    }, null, 2),
    'utf8'
  );

  // seed ddns tasks
  await fs.writeFile(
    ddnsTasksFile,
    JSON.stringify({
      tasks: {
        [`ddns-task-${ts}`]: {
          id: `ddns-task-${ts}`,
          provider: 'aliyun',
          domain: `test-${ts}.example.com`,
          rr: 'www',
          cron: '*/10 * * * *',
          enabled: true,
        },
      },
    }, null, 2),
    'utf8'
  );

  await loginAsDevAdmin(page);
  await page.goto('/admin/settings.php#advanced');

  // clear scheduled tasks
  page.once('dialog', (dialog) => dialog.accept());
  await page.locator('form').filter({ has: page.locator('input[name="action"][value="clear_scheduled_tasks"]') }).getByRole('button').click();
  await expect(page.locator('.alert-success')).toContainText('已清空');

  const scheduledAfter = JSON.parse(await fs.readFile(scheduledTasksFile, 'utf8').catch(() => '{}'));
  const userTasks = Object.values(scheduledAfter.tasks ?? {}).filter((t: any) => t.type === 'manual');
  expect(userTasks.length).toBe(0);
  // system dispatchers should remain or be rebuilt

  // clear ddns tasks
  await page.goto('/admin/settings.php#advanced');
  page.once('dialog', (dialog) => dialog.accept());
  await page.locator('form').filter({ has: page.locator('input[name="action"][value="clear_ddns_tasks"]') }).getByRole('button').click();
  await expect(page.locator('.alert-success')).toContainText('已清空');

  const ddnsAfter = JSON.parse(await fs.readFile(ddnsTasksFile, 'utf8').catch(() => '{}'));
  expect(Object.keys(ddnsAfter.tasks ?? {}).length).toBe(0);

  await tracker.assertNoClientErrors();
});
