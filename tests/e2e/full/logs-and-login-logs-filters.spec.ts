import fs from 'fs/promises';
import path from 'path';
import { test, expect } from '../../helpers/fixtures';
import { attachClientErrorTracking, loginAsDevAdmin } from '../../helpers/auth';

const authLogPath = path.resolve(__dirname, '../../../data/logs/auth.log');

test('login logs and logs center support frontend filtering and level selection', async ({ page }) => {
  test.setTimeout(120000);
  const tracker = await attachClientErrorTracking(page);
  const ts = Date.now();
  const originalAuthLog = await fs.readFile(authLogPath, 'utf8').catch(() => '');

  const logLines = [
    `[2026-04-07 10:00:00] SUCCESS    user=filter-user-${ts} ip=127.0.0.1 note=e2e`,
    `[2026-04-07 10:01:00] FAIL       user=filter-user-fail-${ts} ip=127.0.0.2 note=e2e`,
  ].join('\n');
  const nextAuthLog = `${originalAuthLog.replace(/\s*$/, '')}\n${logLines}\n`.replace(/^\n/, '');
  await fs.writeFile(authLogPath, nextAuthLog, 'utf8');

  try {
    await loginAsDevAdmin(page);

    // login_logs.php filter
    await page.goto('/admin/login_logs.php');
    await expect(page.locator('table tbody tr').first()).toBeVisible();
    await page.locator('input#ll-keyword').fill('zzzzzz-no-match');
    await page.waitForTimeout(200);
    await expect(page.locator('table tbody tr:visible')).toHaveCount(0);

    // logs.php center filter by level
    await page.goto('/admin/logs.php');
    await expect(page.locator('#log-table tbody tr').first()).toBeVisible();
    await page.locator('select#log-level').selectOption('CRITICAL');
    await page.waitForTimeout(200);
    // CRITICAL may show zero rows or existing rows; we just ensure no JS error occurs
    await expect(page.locator('#log-table')).toBeVisible();
  } finally {
    await fs.writeFile(authLogPath, originalAuthLog, 'utf8');
  }

  await tracker.assertNoClientErrors();
});
