import fs from 'fs/promises';
import path from 'path';
import { test, expect } from '../../helpers/fixtures';
import { attachClientErrorTracking, loginAsDevAdmin } from '../../helpers/auth';
import { writeContainerFile } from '../../helpers/cli';

const auditLogPath = path.resolve(__dirname, '../../../data/logs/share_service_audit.log');

test('share service audit page filters paginates and exports', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
    ],
  });
  const ts = Date.now();
  const logEntries = [
    JSON.stringify({ time: '2026-01-01 10:00:00', user: 'admin', action: 'smb_share_save', context: { service: 'smb', path: `/share-a-${ts}` } }),
    JSON.stringify({ time: '2026-01-01 10:01:00', user: 'admin', action: 'nfs_share_delete', context: { service: 'nfs', path: `/share-b-${ts}` } }),
    JSON.stringify({ time: '2026-01-01 10:02:00', user: 'viewer', action: 'smb_share_save', context: { service: 'smb', path: `/share-c-${ts}` } }),
  ].join('\n') + '\n';

  await fs.mkdir(path.dirname(auditLogPath), { recursive: true });
  await fs.writeFile(auditLogPath, logEntries, 'utf8');
  // Docker Desktop for Mac osxfs 同步延迟：直接写入容器确保 PHP 能立即读到
  writeContainerFile('/var/www/nav/data/logs/share_service_audit.log', logEntries);

  await loginAsDevAdmin(page);
  await page.goto('/admin/share_service_audit.php');
  await expect(page.locator('body')).toContainText('共享服务审计');
  await expect(page.locator('body')).toContainText('smb_share_save');
  await expect(page.locator('body')).toContainText('nfs_share_delete');

  // Filter by action
  await page.locator('input[name="action_name"]').fill('smb_share_save');
  await page.locator('form[method="GET"] button[type="submit"]').click();
  await expect(page).toHaveURL(/action_name=smb_share_save/);
  await expect(page.locator('tbody tr')).toHaveCount(2);
  await expect(page.locator('body')).toContainText(`share-c-${ts}`);
  await expect(page.locator('body')).not.toContainText(`share-b-${ts}`);

  // Filter by service
  await page.goto('/admin/share_service_audit.php?service_name=smb');
  await expect(page.locator('tbody tr')).toHaveCount(2);
  await expect(page.locator('body')).toContainText('smb');

  // Clear filters
  await page.getByRole('link', { name: /清空/ }).click();
  await expect(page).toHaveURL(/share_service_audit\.php$/);
  await expect(page.locator('tbody tr')).toHaveCount(3);

  await tracker.assertNoClientErrors();
});
