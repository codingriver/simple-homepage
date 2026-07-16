import fs from 'fs/promises';
import path from 'path';
import { test, expect } from '../../helpers/fixtures';
import { attachClientErrorTracking, loginAsDevAdmin } from '../../helpers/auth';
import { writeContainerFile } from '../../helpers/cli';

const auditLogPath = path.resolve(__dirname, '../../../data/logs/audit.log');
const containerAuditLogPath = '/var/www/riverops/data/logs/audit.log';

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

  // 容器内写入避免 Docker Desktop bind-mount 同步延迟
  writeContainerFile(containerAuditLogPath, auditLines);
  await fs.mkdir(path.dirname(auditLogPath), { recursive: true }).catch(() => undefined);
  await fs.writeFile(auditLogPath, auditLines, 'utf8').catch(() => undefined);
  // 给 osxfs 额外同步时间
  await page.waitForTimeout(1500);

  await loginAsDevAdmin(page);
  await page.goto('/admin/logs.php', { waitUntil: 'domcontentloaded' });

  // Sidebar renders app logs
  await expect(page.locator('#appLogs').locator('.logs-item').first()).toBeVisible();
  const auditItem = page.locator('#appLogs .logs-item').filter({ hasText: /操作审计日志/ });
  await expect(auditItem).toBeVisible();

  // Select audit log
  await auditItem.click();
  // Ace Editor 从 CDN 加载在部分网络环境下较慢，给予充足等待时间
  await page.waitForFunction(
    () => typeof (window as typeof window & { ace?: { edit: (id: string) => unknown } }).ace?.edit === 'function',
    undefined,
    { timeout: 30000 }
  );
  const editorContains = async (text: string) => {
    return await page.locator('#logPreview').textContent().then((value) => (value || '').includes(text));
  };
  await expect.poll(() => editorContains(`audit-line-1-${ts}`), { timeout: 15000 }).toBe(true);

  // Filter keyword
  await page.locator('#logKeyword').fill('audit-line-2');
  await page.waitForTimeout(300);
  const filteredValue = await page.locator('#logPreview').textContent();
  expect(filteredValue).toContain(`audit-line-2-${ts}`);
  expect(filteredValue).not.toContain(`audit-line-1-${ts}`);

  // Clear filter
  await page.locator('#logKeyword').fill('');
  await page.waitForTimeout(200);

  // Change limit and refresh
  await page.locator('#logLimit').selectOption('100');
  await page.getByRole('button', { name: /刷新/ }).click();
  await page.waitForTimeout(300);
  const postRefresh = await page.locator('#logPreview').textContent();
  expect(postRefresh).toContain(`audit-line-1-${ts}`);

  // Download current log
  const downloadPromise = page.waitForEvent('download');
  await page.locator('#btnDownload').click();
  const download = await downloadPromise;
  expect(download.suggestedFilename()).toContain('audit_log_');

  // Clear audit log
  await page.locator('#btnClear').click();
  await expect(page.locator('#riverops-confirm-modal')).toBeVisible();
  await page.locator('#riverops-confirm-ok').click();
  await page.waitForTimeout(400);
  await expect.poll(() => page.locator('#logPreview').textContent()).toMatch(/^\s*$|暂无内容/);

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
