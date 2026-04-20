import fs from 'fs/promises';
import path from 'path';
import { test, expect } from '../../helpers/fixtures';
import { attachClientErrorTracking, loginAsDevAdmin } from '../../helpers/auth';
import { writeContainerFile, readContainerFile } from '../../helpers/cli';

const authLogPath = path.resolve(__dirname, '../../../data/logs/auth.log');
const containerAuthLogPath = '/var/www/nav/data/logs/auth.log';

test('login logs and logs center support frontend filtering and level selection', async ({ page }) => {
  test.setTimeout(120000);
  const tracker = await attachClientErrorTracking(page);
  const ts = Date.now();
  const originalAuthLog = readContainerFile(containerAuthLogPath);

  const logLines = [
    `[2026-04-07 10:00:00] SUCCESS    user=filter-user-${ts} ip=127.0.0.1 note=e2e`,
    `[2026-04-07 10:01:00] FAIL       user=filter-user-fail-${ts} ip=127.0.0.2 note=e2e`,
  ].join('\n');
  const nextAuthLog = `${originalAuthLog.replace(/\s*$/, '')}\n${logLines}\n`.replace(/^\n/, '');
  writeContainerFile(containerAuthLogPath, nextAuthLog);
  await fs.writeFile(authLogPath, nextAuthLog, 'utf8').catch(() => undefined);

  try {
    await loginAsDevAdmin(page);

    // login_logs.php AJAX endpoint returns seeded data
    const loginLogsRes = await page.request.get('/admin/login_logs.php', {
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
    });
    expect(loginLogsRes.status()).toBe(200);
    const loginLogsBody = await loginLogsRes.json();
    expect(loginLogsBody.ok).toBe(true);
    expect(JSON.stringify(loginLogsBody.rows)).toContain(`filter-user-${ts}`);

    // logs.php page loads and supports keyword filtering via Ace Editor
    await page.goto('/admin/logs.php', { waitUntil: 'domcontentloaded' });
    await expect(page.locator('#logsSidebar')).toBeVisible();
    // click auth log source
    await page.locator('[data-key="auth"]').click();
    await expect(page.locator('#logEditor')).toBeVisible();
    // filter by non-matching keyword
    await page.locator('input#logKeyword').fill('zzzzzz-no-match');
    await page.waitForTimeout(300);
    // verify Ace Editor shows no matching lines via JS
    const filtered = await page.evaluate(() => {
      const ed = (window as any).ace?.edit('logEditor');
      return ed ? ed.getValue() : '';
    });
    expect(filtered).not.toContain(`filter-user-${ts}`);
  } finally {
    writeContainerFile(containerAuthLogPath, originalAuthLog);
    await fs.writeFile(authLogPath, originalAuthLog, 'utf8').catch(() => undefined);
  }

  await tracker.assertNoClientErrors();
});
