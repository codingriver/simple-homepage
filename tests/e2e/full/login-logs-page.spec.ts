import fs from 'fs/promises';
import path from 'path';
import { test, expect } from '../../helpers/fixtures';
import { attachClientErrorTracking, loginAsDevAdmin } from '../../helpers/auth';
import { writeContainerFile } from '../../helpers/cli';

const authLogPath = path.resolve(__dirname, '../../../data/logs/auth.log');
const containerAuthLogPath = '/var/www/nav/data/logs/auth.log';

test('login logs endpoint returns json records and logs center renders auth log with filter', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
    ],
  });

  const ts = Date.now();
  const logUser = `loginlog-${ts}`;
  const originalAuthLog = await fs.readFile(authLogPath, 'utf8').catch(() => '');

  try {
    // Seed auth log with a recognizable entry
    const logLines = [
      `[2026-04-07 10:00:00] SUCCESS    user=${logUser} ip=127.0.0.1 note=page_test`,
      `[2026-04-07 10:01:00] FAIL       user=${logUser}-fail ip=127.0.0.2 note=wrong_password`,
    ].join('\n');
    const nextAuthLog = `${originalAuthLog.replace(/\s*$/, '')}\n${logLines}\n`.replace(/^\n/, '');
    writeContainerFile(containerAuthLogPath, nextAuthLog);
    await fs.writeFile(authLogPath, nextAuthLog, 'utf8').catch(() => undefined);

    await loginAsDevAdmin(page);

    // 1. Direct AJAX to login_logs.php returns JSON with rows
    const ajaxRes = await page.request.get('http://127.0.0.1:58080/admin/login_logs.php', {
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
    });
    expect(ajaxRes.status()).toBe(200);
    const json = (await ajaxRes.json()) as { ok: boolean; total: number; rows: string[]; max: number };
    expect(json).toMatchObject({ ok: true, total: expect.any(Number), rows: expect.any(Array), max: expect.any(Number) });
    expect(json.rows.length).toBeGreaterThan(0);
    expect(json.rows.some((row) => row.includes(logUser))).toBeTruthy();

    // 2. Non-AJAX GET returns 400
    const plainRes = await page.request.get('http://127.0.0.1:58080/admin/login_logs.php');
    expect(plainRes.status()).toBe(400);

    // 3. Logs center page renders and can display auth log
    await page.goto('/admin/logs.php');
    await expect(page.locator('body')).toContainText('日志中心');

    // Wait for sidebar to load and click auth log
    await expect(page.locator('#logsSidebar')).toBeVisible();
    const authLogItem = page.locator('.logs-item', { hasText: '登录认证日志' });
    await expect(authLogItem).toBeVisible();
    await authLogItem.click();

    // Wait for Ace editor to initialize and show content
    await expect(page.locator('#logEditor')).toBeVisible();
    await expect(page.locator('#currentLogTitle')).toContainText('登录认证日志');

    // Verify the seeded log user appears in the editor content
    await expect
      .poll(async () => {
        const text = await page.evaluate(() => {
          const editor = (window as any).ace?.edit('logEditor');
          return editor ? editor.getValue() : '';
        });
        return text;
      })
      .toContain(logUser);

    // 4. Test filter/search
    await page.locator('#logKeyword').fill(logUser);
    // Ace editor content should still contain the filtered line
    await expect
      .poll(async () => {
        const text = await page.evaluate(() => {
          const editor = (window as any).ace?.edit('logEditor');
          return editor ? editor.getValue() : '';
        });
        return text;
      })
      .toContain(logUser);

    // Filter with a non-matching keyword should empty the display
    await page.locator('#logKeyword').fill('nonexistent-xyz-999');
    await expect
      .poll(async () => {
        const text = await page.evaluate(() => {
          const editor = (window as any).ace?.edit('logEditor');
          return editor ? editor.getValue() : '';
        });
        return text.trim();
      })
      .toBe('');

    await tracker.assertNoClientErrors();
  } finally {
    writeContainerFile(containerAuthLogPath, originalAuthLog);
    await fs.writeFile(authLogPath, originalAuthLog, 'utf8').catch(() => undefined);
  }
});
