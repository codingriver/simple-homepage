import fs from 'fs/promises';
import path from 'path';
import { test, expect } from '../../helpers/fixtures';
import { attachClientErrorTracking, loginAsDevAdmin } from '../../helpers/auth';

const auditLogPath = path.resolve(__dirname, '../../../data/logs/audit.log');
const notifyLogPath = path.resolve(__dirname, '../../../data/logs/notifications.log');

test('logs center loads sources filters downloads and clears app logs', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
    ],
    ignoredFailedRequests: [
      /GET .*\/admin\/logs_api\.php\?action=download.* :: net::ERR_ABORTED/,
    ],
  });
  const ts = Date.now();
  const auditLines = `audit-line-1-${ts}\naudit-line-2-${ts}\n`;
  const notifyLines = `notify-info-${ts}\nnotify-warn-${ts}\n`;

  await fs.mkdir(path.dirname(auditLogPath), { recursive: true });
  await fs.writeFile(auditLogPath, auditLines, 'utf8');
  await fs.writeFile(notifyLogPath, notifyLines, 'utf8');

  await loginAsDevAdmin(page);
  await page.goto('/admin/logs.php');

  // Sidebar renders app logs
  await expect(page.locator('#appLogs').locator('.logs-item').first()).toBeVisible();
  const notifyItem = page.locator('#appLogs .logs-item').filter({ hasText: /通知日志/ });
  await expect(notifyItem).toBeVisible();

  // Select notifications log
  await notifyItem.click();
  await page.waitForFunction(() => typeof (window as typeof window & { ace?: { edit: (id: string) => unknown } }).ace?.edit === 'function');
  await page.waitForTimeout(300);

  const editorContains = async (text: string) => {
    return await page.evaluate((t) => {
      const ed = (window as typeof window & { ace?: { edit: (id: string) => { getValue: () => string } } }).ace?.edit('logEditor');
      return ed ? ed.getValue().includes(t) : false;
    }, text);
  };
  await expect.poll(() => editorContains(`notify-info-${ts}`)).toBe(true);

  // Filter keyword
  await page.locator('#logKeyword').fill('notify-warn');
  await page.waitForTimeout(300);
  const filteredValue = await page.evaluate(() => {
    const ed = (window as typeof window & { ace?: { edit: (id: string) => { getValue: () => string } } }).ace?.edit('logEditor');
    return ed ? ed.getValue() : '';
  });
  expect(filteredValue).toContain(`notify-warn-${ts}`);
  expect(filteredValue).not.toContain(`notify-info-${ts}`);

  // Clear filter
  await page.locator('#logKeyword').fill('');
  await page.waitForTimeout(200);

  // Change limit and refresh
  await page.locator('#logLimit').selectOption('100');
  await page.getByRole('button', { name: /刷新/ }).click();
  await page.waitForTimeout(300);
  const postRefresh = await page.evaluate(() => {
    const ed = (window as typeof window & { ace?: { edit: (id: string) => { getValue: () => string } } }).ace?.edit('logEditor');
    return ed ? ed.getValue() : '';
  });
  expect(postRefresh).toContain(`notify-info-${ts}`);

  // Download current log
  const downloadPromise = page.waitForEvent('download');
  await page.locator('#btnDownload').click();
  const download = await downloadPromise;
  expect(download.suggestedFilename()).toContain('notifications_log_');

  // Switch to audit log
  const auditItem = page.locator('#appLogs .logs-item').filter({ hasText: /操作审计日志/ });
  await auditItem.click();
  await page.waitForTimeout(300);
  await expect.poll(() => editorContains(`audit-line-1-${ts}`)).toBe(true);

  // Clear audit log
  page.once('dialog', (dialog) => dialog.accept());
  await page.locator('#btnClear').click();
  await page.waitForTimeout(400);
  const clearedValue = await page.evaluate(() => {
    const ed = (window as typeof window & { ace?: { edit: (id: string) => { getValue: () => string } } }).ace?.edit('logEditor');
    return ed ? ed.getValue() : '';
  });
  expect(clearedValue.trim()).toBe('');

  // Auth guard on API (guest context)
  const guestContext = await page.context().browser()?.newContext() ?? null;
  if (guestContext) {
    const guestPage = await guestContext.newPage();
    const guestResp = await guestPage.request.get('http://127.0.0.1:58080/admin/logs_api.php?action=list', {
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
    });
    expect(guestResp.status()).toBe(403);
    await guestContext.close();
  }

  await tracker.assertNoClientErrors();
});
