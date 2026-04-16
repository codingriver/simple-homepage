import fs from 'fs/promises';
import path from 'path';
import { test, expect } from '../../helpers/fixtures';
import { attachClientErrorTracking, loginAsDevAdmin } from '../../helpers/auth';

const sshAuditLogPath = path.resolve(__dirname, '../../../data/logs/ssh_audit.log');

function makeEntry(ts: number, action: string, hostId: string, user: string, keyword: string) {
  return JSON.stringify({
    time: '2026-01-01 10:00:00',
    user,
    role: 'admin',
    action,
    context: { host_id: hostId, keyword, ts },
  }) + '\n';
}

test('ssh audit page filters paginates and exports', async ({ page, browser }) => {
  const tracker = await attachClientErrorTracking(page);
  const ts = Date.now();
  const entries = [
    makeEntry(ts, 'ssh_config_save', 'local', 'admin', `alpha-${ts}`),
    makeEntry(ts, 'ssh_service_restart', 'host-1', 'viewer', `beta-${ts}`),
    makeEntry(ts, 'ssh_config_save', 'host-2', 'admin', `gamma-${ts}`),
    makeEntry(ts, 'ssh_key_deploy', 'local', 'ops', `delta-${ts}`),
  ].join('');

  await fs.mkdir(path.dirname(sshAuditLogPath), { recursive: true });
  await fs.writeFile(sshAuditLogPath, entries, 'utf8');

  await loginAsDevAdmin(page);
  await page.goto('/admin/ssh_audit.php');

  await expect(page.locator('body')).toContainText('SSH 审计');
  await expect(page.locator('tbody tr')).toHaveCount(4);

  await page.locator('input[name="action_name"]').fill('ssh_config_save');
  await page.locator('form[method="GET"] button[type="submit"]').click();
  await expect(page).toHaveURL(/action_name=ssh_config_save/);
  await expect(page.locator('tbody tr')).toHaveCount(2);

  await page.goto('/admin/ssh_audit.php?host_id=local');
  await expect(page.locator('tbody tr')).toHaveCount(2);

  await page.goto('/admin/ssh_audit.php?user_name=admin');
  await expect(page.locator('tbody tr')).toHaveCount(2);

  await page.goto('/admin/ssh_audit.php?keyword=beta');
  await expect(page.locator('tbody tr')).toHaveCount(1);
  await expect(page.locator('tbody')).toContainText('viewer');

  await page.goto('/admin/ssh_audit.php');
  await expect(page.locator('tbody tr')).toHaveCount(4);
  await expect(page.getByRole('link', { name: /上一页/ })).toHaveCount(0);
  await expect(page.getByRole('link', { name: /下一页/ })).toHaveCount(0);

  const manyEntries = Array.from({ length: 55 }).map((_, i) =>
    makeEntry(ts, `action_${i}`, 'local', 'admin', `kw-${i}`)
  ).join('');
  await fs.writeFile(sshAuditLogPath, manyEntries, 'utf8');
  await page.reload();
  await expect(page.locator('tbody tr')).toHaveCount(50);
  await expect(page.getByRole('link', { name: /下一页/ })).toBeVisible();
  await page.getByRole('link', { name: /下一页/ }).click();
  await expect(page).toHaveURL(/page=2/);
  await expect(page.locator('tbody tr')).toHaveCount(5);
  await expect(page.getByRole('link', { name: /上一页/ })).toBeVisible();

  const anonContext = await browser.newContext({ baseURL: 'http://127.0.0.1:58080' });
  const anonPage = await anonContext.newPage();
  const guestExport = await anonPage.request.get('http://127.0.0.1:58080/admin/host_api.php?action=audit_export&limit=1000', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
  });
  expect(guestExport.status()).toBe(403);
  await anonContext.close();

  await tracker.assertNoClientErrors();
});
