import fs from 'fs/promises';
import path from 'path';
import { expect, test } from '../../helpers/fixtures';
import { attachClientErrorTracking, loginAsDevAdmin } from '../../helpers/auth';
import { runDockerPhpInline } from '../../helpers/cli';

const ddnsTasksFile = path.resolve(__dirname, '../../../data/ddns_tasks.json');
const scheduledTasksFile = path.resolve(__dirname, '../../../data/scheduled_tasks.json');

test('deleting a ddns task cleans up orphan sys_ddns_dispatcher scheduled tasks', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page);
  const ts = Date.now();
  const taskId = `ddns-cleanup-${ts}`;
  const dispatcherId = `sys_ddns_dispatcher_${ts}`;

  // seed DDNS task
  const ddnsData = {
    tasks: {
      [taskId]: {
        id: taskId,
        provider: 'aliyun',
        domain: `cleanup-${ts}.example.com`,
        rr: 'www',
        cron: `*/${(ts % 59) + 1} * * * *`,
        enabled: true,
        created_at: new Date().toISOString(),
      },
    },
  };
  await fs.writeFile(ddnsTasksFile, JSON.stringify(ddnsData, null, 2), 'utf8');

  // seed scheduled task dispatcher
  const scheduledData = {
    tasks: {
      [dispatcherId]: {
        id: dispatcherId,
        name: `DDNS Dispatcher ${ts}`,
        cron: `*/${(ts % 59) + 1} * * * *`,
        command: 'php /var/www/nav/cli/ddns_sync.php',
        enabled: true,
        type: 'system',
        created_at: new Date().toISOString(),
      },
    },
  };
  await fs.writeFile(scheduledTasksFile, JSON.stringify(scheduledData, null, 2), 'utf8');

  await loginAsDevAdmin(page);
  await page.goto('/admin/ddns.php');

  // delete DDNS task via ddns_ajax.php
  const csrf = await page.evaluate(() => (window as any)._csrf || '');
  const deleteRes = await page.request.post('http://127.0.0.1:58080/admin/ddns_ajax.php', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: { action: 'delete', id: taskId, _csrf: csrf },
  });
  expect(deleteRes.status()).toBe(200);
  const deleteBody = await deleteRes.json();
  expect(deleteBody.ok).toBe(true);

  // verify DDNS task removed
  const ddnsAfter = JSON.parse(await fs.readFile(ddnsTasksFile, 'utf8').catch(() => '{}'));
  expect(ddnsAfter.tasks?.[taskId]).toBeUndefined();

  // verify orphan dispatcher removed
  const scheduledAfter = JSON.parse(await fs.readFile(scheduledTasksFile, 'utf8').catch(() => '{}'));
  expect(scheduledAfter.tasks?.[dispatcherId]).toBeUndefined();

  await tracker.assertNoClientErrors();
});
