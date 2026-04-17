import fs from 'fs/promises';
import path from 'path';
import { createHash } from 'crypto';
import { expect, test } from '../../helpers/fixtures';
import { attachClientErrorTracking, loginAsDevAdmin } from '../../helpers/auth';
import { runDockerPhpInline } from '../../helpers/cli';

const ddnsTasksFile = path.resolve(__dirname, '../../../data/ddns_tasks.json');
const scheduledTasksFile = path.resolve(__dirname, '../../../data/scheduled_tasks.json');

test('deleting a ddns task cleans up orphan sys_ddns_dispatcher scheduled tasks', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page);
  const ts = Date.now();
  const taskId = `ddns-cleanup-${ts}`;

  // seed DDNS task
  const cronExpr = `*/${(ts % 59) + 1} * * * *`;
  const ddnsData = {
    version: 1,
    tasks: [
      {
        id: taskId,
        name: `Cleanup ${ts}`,
        provider: 'aliyun',
        target: {
          domain: `cleanup-${ts}.example.com`,
          rr: 'www',
        },
        source: {
          type: 'local_ipv4',
        },
        schedule: {
          cron: cronExpr,
        },
        enabled: true,
        created_at: new Date().toISOString(),
      },
    ],
  };
  await fs.writeFile(ddnsTasksFile, JSON.stringify(ddnsData, null, 2), 'utf8');

  // seed scheduled task dispatcher with backend-computed ID
  const expectedDispatcherId = 'sys_ddns_dispatcher_' + createHash('sha1').update(cronExpr).digest('hex').slice(0, 12);
  const scheduledData = {
    tasks: {
      [expectedDispatcherId]: {
        id: expectedDispatcherId,
        name: `DDNS Dispatcher ${ts}`,
        schedule: cronExpr,
        command: 'php /var/www/nav/cli/ddns_sync.php',
        enabled: true,
        is_system: true,
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
  expect(scheduledAfter.tasks?.[expectedDispatcherId]).toBeUndefined();

  await tracker.assertNoClientErrors();
});
