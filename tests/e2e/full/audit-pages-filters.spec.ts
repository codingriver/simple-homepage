import fs from 'fs/promises';
import path from 'path';
import { test, expect } from '../../helpers/fixtures';
import { attachClientErrorTracking, loginAsDevAdmin } from '../../helpers/auth';
import { runDockerPhpInline } from '../../helpers/cli';

const sshAuditLogPath = path.resolve(__dirname, '../../../data/logs/ssh_audit.log');
const shareServiceAuditLogPath = path.resolve(__dirname, '../../../data/logs/share_service_audit.log');
const webdavLogPath = path.resolve(__dirname, '../../../data/logs/webdav.log');
const sshHostsPath = path.resolve(__dirname, '../../../data/ssh_hosts.json');

async function seedSshAuditLog() {
  const entries = [
    { time: '2026-01-01 10:00:00', user: 'admin', role: 'admin', action: 'ssh_config_save', context: { host_id: 'local' } },
    { time: '2026-01-01 10:01:00', user: 'admin', role: 'admin', action: 'remote_host_upsert', context: { host_id: 'host1' } },
  ];
  await fs.mkdir(path.dirname(sshAuditLogPath), { recursive: true });
  await fs.writeFile(sshAuditLogPath, entries.map((e) => JSON.stringify(e)).join('\n') + '\n', 'utf8');
}

async function seedShareServiceAuditLog() {
  const entries = [
    { time: '2026-01-01 11:00:00', user: 'admin', role: 'admin', action: 'smb_share_save', context: { service: 'smb', path: '/srv/share' } },
    { time: '2026-01-01 11:01:00', user: 'admin', role: 'admin', action: 'nfs_export_save', context: { service: 'nfs', path: '/srv/nfs' } },
  ];
  await fs.mkdir(path.dirname(shareServiceAuditLogPath), { recursive: true });
  await fs.writeFile(shareServiceAuditLogPath, entries.map((e) => JSON.stringify(e)).join('\n') + '\n', 'utf8');
}

async function seedWebdavLog() {
  const entries = [
    { time: '2026-01-01 12:00:00', user: 'wduser1', action: 'put', context: { path: '/f1.txt', size: 1024 } },
    { time: '2026-01-01 12:01:00', user: 'wduser2', action: 'delete', context: { path: '/f2.txt' } },
  ];
  await fs.mkdir(path.dirname(webdavLogPath), { recursive: true });
  await fs.writeFile(webdavLogPath, entries.map((e) => JSON.stringify(e)).join('\n') + '\n', 'utf8');
}

test('audit pages support filtering by service action user and keyword', async ({ page }) => {
  test.setTimeout(120000);
  const tracker = await attachClientErrorTracking(page);

  await loginAsDevAdmin(page);

  // ssh_audit.php — server-side GET form filtering
  await seedSshAuditLog();
  await page.goto('/admin/ssh_audit.php');
  await expect(page.locator('.card table tbody tr').first()).toBeVisible();
  await page.locator('.card input[name="keyword"]').fill('zzzzzz-no-match');
  await page.getByRole('button', { name: /筛选/ }).click();
  await expect(page).toHaveURL(/keyword=zzzzzz-no-match/);
  await expect(page.locator('body')).toContainText('暂无符合条件的 SSH 审计日志');

  // share_service_audit.php — server-side GET form filtering
  await seedShareServiceAuditLog();
  await page.goto('/admin/share_service_audit.php');
  await expect(page.locator('.card table tbody tr').first()).toBeVisible();
  await page.locator('.card input[name="keyword"]').fill('zzzzzz-no-match');
  await page.getByRole('button', { name: /筛选/ }).click();
  await expect(page).toHaveURL(/keyword=zzzzzz-no-match/);
  await expect(page.locator('body')).toContainText('暂无符合条件的共享服务审计记录');

  // webdav_audit.php — server-side GET form filtering
  await seedWebdavLog();
  await page.goto('/admin/webdav_audit.php');
  await expect(page.locator('.card table tbody tr').first()).toBeVisible();
  await page.locator('.card input[name="keyword"]').fill('zzzzzz-no-match');
  await page.getByRole('button', { name: /筛选/ }).click();
  await expect(page).toHaveURL(/keyword=zzzzzz-no-match/);
  await expect(page.locator('body')).toContainText('暂无符合条件的审计日志');

  // file_audit.php — client-side JS filtering only
  const remoteHostId = 'h_e2e_remote_audit';
  const remoteHostName = 'e2e-remote-audit-host';
  const seedHostResult = runDockerPhpInline(
    `require "/var/www/nav/admin/shared/ssh_manager_lib.php";
    $record = [
      "id" => "${remoteHostId}",
      "name" => "${remoteHostName}",
      "hostname" => "192.168.1.99",
      "port" => 22,
      "username" => "root",
      "auth_type" => "password",
      "key_id" => "",
      "password_enc" => "",
      "group_name" => "",
      "tags" => [],
      "favorite" => false,
      "notes" => "",
      "created_at" => "2026-01-01 00:00:00",
      "updated_at" => "2026-01-01 00:00:00",
    ];
    $data = ssh_manager_load_hosts();
    $data["hosts"] = [$record];
    ssh_manager_save_hosts($data);
    echo "ok";`
  );
  expect(seedHostResult.code).toBe(0);

  const fileEntries = [
    { time: '2026-01-01 13:00:00', user: 'admin', role: 'admin', action: 'fs_write', context: { host_id: 'local', path: '/tmp/test.txt' } },
    { time: '2026-01-01 13:01:00', user: 'admin', role: 'admin', action: 'fs_delete', context: { host_id: remoteHostId, path: '/tmp/test2.txt' } },
  ];
  await fs.writeFile(sshAuditLogPath, fileEntries.map((e) => JSON.stringify(e)).join('\n') + '\n', 'utf8');
  await page.goto('/admin/file_audit.php');
  await expect(page.locator('table tbody tr').first()).toBeVisible();

  // keyword filter
  await page.locator('input#fa-keyword-filter').fill('zzzzzz-no-match');
  await page.waitForTimeout(200);
  await expect(page.locator('table tbody tr.fa-row:visible')).toHaveCount(0);

  // clear keyword
  await page.locator('input#fa-keyword-filter').fill('');
  await page.waitForTimeout(200);
  await expect(page.locator('table tbody tr.fa-row:visible')).toHaveCount(2);

  // host filter — select remote host
  await page.locator('select#fa-host-filter').selectOption(remoteHostId);
  await page.waitForTimeout(200);
  await expect(page.locator('table tbody tr.fa-row:visible')).toHaveCount(1);
  await expect(page.locator('table tbody tr.fa-row:visible')).toContainText('fs_delete');

  // host filter — select local
  await page.locator('select#fa-host-filter').selectOption('local');
  await page.waitForTimeout(200);
  await expect(page.locator('table tbody tr.fa-row:visible')).toHaveCount(1);
  await expect(page.locator('table tbody tr.fa-row:visible')).toContainText('fs_write');

  // host filter — all hosts
  await page.locator('select#fa-host-filter').selectOption('');
  await page.waitForTimeout(200);
  await expect(page.locator('table tbody tr.fa-row:visible')).toHaveCount(2);

  await tracker.assertNoClientErrors();
});
