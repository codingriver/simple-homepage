import fs from 'fs/promises';
import path from 'path';
import { test, expect } from '../../helpers/fixtures';
import { attachClientErrorTracking, loginAsDevAdmin } from '../../helpers/auth';

const webdavAuditLogPath = path.resolve(__dirname, '../../../data/logs/webdav.log');

test('webdav audit page displays logs and supports filtering', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
    ],
  });
  const ts = Date.now();
  const logLines = [
    JSON.stringify({ time: '2026-01-01 10:00:00', user: 'wduser1', action: 'put', context: { path: `/file-a-${ts}.txt`, size: 1024 } }),
    JSON.stringify({ time: '2026-01-01 10:01:00', user: 'wduser2', action: 'delete', context: { path: `/file-b-${ts}.txt` } }),
    JSON.stringify({ time: '2026-01-01 10:02:00', user: 'wduser1', action: 'propfind', context: { path: '/' } }),
  ].join('\n') + '\n';

  await fs.mkdir(path.dirname(webdavAuditLogPath), { recursive: true });
  await fs.writeFile(webdavAuditLogPath, logLines, 'utf8');

  await loginAsDevAdmin(page);
  await page.goto('/admin/webdav_audit.php');
  await expect(page.locator('body')).toContainText('WebDAV 审计');
  await expect(page.locator('tbody tr')).toHaveCount(3);
  await expect(page.locator('body')).toContainText('wduser1');
  await expect(page.locator('body')).toContainText('wduser2');

  // Filter by user
  await page.locator('input[name="log_user"]').fill('wduser1');
  await page.locator('form[method="GET"] button[type="submit"]').click();
  await expect(page).toHaveURL(/log_user=wduser1/);
  await expect(page.locator('tbody tr')).toHaveCount(2);
  await expect(page.locator('body')).toContainText('put');
  await expect(page.locator('body')).not.toContainText('delete');

  // Filter by action
  await page.goto('/admin/webdav_audit.php?action_name=delete');
  await expect(page.locator('tbody tr')).toHaveCount(1);
  await expect(page.locator('body')).toContainText(`file-b-${ts}`);

  // Clear filters
  await page.getByRole('link', { name: /清空/ }).click();
  await expect(page).toHaveURL(/webdav_audit\.php$/);
  await expect(page.locator('tbody tr')).toHaveCount(3);

  await tracker.assertNoClientErrors();
});
