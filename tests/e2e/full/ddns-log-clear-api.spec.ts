import fs from 'fs/promises';
import path from 'path';
import { test, expect } from '../../helpers/fixtures';
import { attachClientErrorTracking, loginAsDevAdmin } from '../../helpers/auth';

const ddnsFile = path.resolve(__dirname, '../../../data/ddns_tasks.json');

test('ddns ajax log_clear action clears task log directly', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page);
  const ts = Date.now();
  const taskId = `ddns-log-clear-${ts}`;
  const logPath = path.resolve(__dirname, `../../../data/logs/ddns_${taskId}.log`);

  const payload = {
    version: 1,
    tasks: [
      {
        id: taskId,
        name: `DDNS Log Clear ${ts}`,
        target: {
          domain: `logclear-${ts}.606077.xyz`,
          rr: 'www',
        },
        source: {
          type: 'local_ipv4',
        },
        schedule: {
          cron: '*/5 * * * *',
        },
        enabled: true,
        created_at: new Date().toISOString(),
      },
    ],
  };
  await fs.writeFile(ddnsFile, JSON.stringify(payload, null, 2), 'utf8');
  await fs.mkdir(path.dirname(logPath), { recursive: true });
  await fs.writeFile(logPath, 'log line 1\nlog line 2\n', 'utf8');

  try {
    await loginAsDevAdmin(page);
    await page.goto('/admin/ddns.php');
    const csrf = await page.locator('input[name="_csrf"]').first().inputValue();

    const res = await page.request.post('http://127.0.0.1:58080/admin/ddns_ajax.php', {
      form: { action: 'log_clear', id: taskId, _csrf: csrf },
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
    });
    expect(res.status()).toBe(200);
    const body = await res.json();
    expect(body.ok).toBe(true);

    const exists = await fs.access(logPath).then(() => true).catch(() => false);
    expect(exists).toBe(false);
  } finally {
    const tasks = JSON.parse(await fs.readFile(ddnsFile, 'utf8').catch(() => '{"tasks":{}}'));
    delete tasks.tasks[taskId];
    await fs.writeFile(ddnsFile, JSON.stringify(tasks, null, 2), 'utf8');
    await fs.rm(logPath, { force: true }).catch(() => undefined);
  }

  await tracker.assertNoClientErrors();
});
