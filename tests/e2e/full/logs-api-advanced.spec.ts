import fs from 'fs/promises';
import path from 'path';
import { expect, test } from '../../helpers/fixtures';
import { attachClientErrorTracking, loginAsDevAdmin } from '../../helpers/auth';

const logsDir = path.resolve(__dirname, '../../../data/logs');

test('logs api supports clear_all and download', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page);
  const ts = Date.now();

  // seed log files
  await fs.mkdir(logsDir, { recursive: true });
  await fs.writeFile(path.join(logsDir, 'dns.log'), `dns log entry ${ts}\n`, 'utf8');
  await fs.writeFile(path.join(logsDir, 'ssh_manager_audit.log'), `ssh audit entry ${ts}\n`, 'utf8');
  await fs.writeFile(path.join(logsDir, 'audit.log'), `audit entry ${ts}\n`, 'utf8');

  await loginAsDevAdmin(page);
  await page.goto('/admin/logs.php');
  const csrf = await page.locator('input[name="_csrf"]').first().inputValue();

  // clear_all
  const clearAllRes = await page.request.post('http://127.0.0.1:58080/admin/logs_api.php?action=clear_all', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: { _csrf: csrf },
  });
  expect(clearAllRes.status()).toBe(200);
  const clearAllBody = await clearAllRes.json();
  expect(clearAllBody.ok).toBe(true);

  // verify clearable logs are empty (ignore logs written by background requests between clear and check)
  const clearableKeys = ['request_timing', 'dns', 'dns_python', 'notifications', 'auth', 'audit'];
  for (const key of clearableKeys) {
    const content = await fs.readFile(path.join(logsDir, `${key}.log`), 'utf8').catch(() => '');
    expect(content.trim()).toBe('');
  }

  // re-seed for download test
  await fs.writeFile(path.join(logsDir, 'dns.log'), `download test ${ts}\n`, 'utf8');

  // download
  const downloadRes = await page.request.get(
    `http://127.0.0.1:58080/admin/logs_api.php?action=download&type=dns`,
    { headers: { 'X-Requested-With': 'XMLHttpRequest' } }
  );
  expect(downloadRes.status()).toBe(200);
  const disp = downloadRes.headers()['content-disposition'] || '';
  expect(disp).toContain('attachment');
  const downloaded = await downloadRes.text();
  expect(downloaded).toContain(`download test ${ts}`);

  await tracker.assertNoClientErrors();
});
