import fs from 'fs/promises';
import path from 'path';
import { test, expect } from '../../helpers/fixtures';
import { attachClientErrorTracking } from '../../helpers/auth';
import { runDockerPhpInline } from '../../helpers/cli';

const usersFile = path.resolve(__dirname, '../../../data/users.json');

async function createTestUser(role: string, username: string, password: string) {
  const hashResult = runDockerPhpInline(`echo password_hash('${password}', PASSWORD_DEFAULT);`);
  if (hashResult.code !== 0) throw new Error('密码哈希生成失败');
  const hash = hashResult.stdout.trim();
  const raw = await fs.readFile(usersFile, 'utf8').catch(() => '{}');
  const users = JSON.parse(raw);
  users[username] = {
    role,
    password_hash: hash,
    created_at: new Date().toISOString(),
  };
  await fs.writeFile(usersFile, JSON.stringify(users, null, 2), 'utf8');
}

async function deleteTestUser(username: string) {
  const raw = await fs.readFile(usersFile, 'utf8').catch(() => '{}');
  const users = JSON.parse(raw);
  delete users[username];
  await fs.writeFile(usersFile, JSON.stringify(users, null, 2), 'utf8');
}

async function loginAsUser(page: any, username: string, password: string) {
  await page.goto('/login.php');
  await page.locator('input[name="username"]').fill(username);
  await page.locator('input[name="password"]').fill(password);
  await page.getByRole('button', { name: /登\s*录/ }).click();
  await expect(page).toHaveURL(/index\.php|\/$/, { timeout: 5000 });
}

test.describe('permission matrix for host viewer and host admin', () => {
  const viewerUser = `hostviewer_${Date.now()}`;
  const adminUser = `hostadmin_${Date.now()}`;
  const viewerPass = 'ViewerPass123';
  const adminPass = 'AdminPass456';

  test.beforeAll(async () => {
    await createTestUser('host_viewer', viewerUser, viewerPass);
    await createTestUser('host_admin', adminUser, adminPass);
  });

  test.afterAll(async () => {
    await deleteTestUser(viewerUser);
    await deleteTestUser(adminUser);
  });

  test('host viewer can read hosts files docker runtime and audit pages', async ({ page }) => {
    const tracker = await attachClientErrorTracking(page);
    await loginAsUser(page, viewerUser, viewerPass);

    for (const url of ['/admin/hosts.php', '/admin/files.php', '/admin/docker_hosts.php', '/admin/host_runtime.php', '/admin/ssh_audit.php', '/admin/file_audit.php', '/admin/share_service_audit.php']) {
      const res = await page.request.get(`http://127.0.0.1:58080${url}`);
      expect(res.status(), `expected 200 for ${url}`).toBe(200);
    }

    // Pages protected by header.php return 403 for non-admins
    for (const url of ['/admin/sites.php', '/admin/settings.php', '/admin/ddns.php']) {
      const res = await page.request.get(`http://127.0.0.1:58080${url}`);
      expect(res.status(), `expected 403 for ${url}`).toBe(403);
    }

    // users.php has its own guard that redirects to login.php (Playwright follows to index.php for logged-in users)
    const usersRes = await page.request.get('http://127.0.0.1:58080/admin/users.php');
    expect(usersRes.url(), 'expected redirect for /admin/users.php').toMatch(/(login\.php|index\.php)$/);

    await tracker.assertNoClientErrors();
  });

  test('host viewer gets 403 on write apis and 200 on read apis', async ({ page }) => {
    const tracker = await attachClientErrorTracking(page);
    await loginAsUser(page, viewerUser, viewerPass);

    const readApis = [
      { url: '/admin/file_api.php?action=list&path=/', ajax: true },
      { url: '/admin/file_api.php?action=stat&path=/', ajax: true },
      { url: '/admin/docker_api.php?action=summary', ajax: true },
      { url: '/admin/docker_api.php?action=containers', ajax: true },
      { url: '/admin/host_api.php?action=ssh_target_status&host_id=local', ajax: true },
      { url: '/admin/host_api.php?action=ssh_target_config_read&host_id=local', ajax: true },
      { url: '/admin/host_api.php?action=system_overview&host_id=local', ajax: true },
      { url: '/admin/host_api.php?action=network_overview&host_id=local', ajax: true },
      // terminal_list requires ssh.terminal permission which host_viewer lacks
      // { url: '/admin/host_api.php?action=terminal_list', ajax: true },
    ];

    for (const api of readApis) {
      const headers: Record<string, string> = api.ajax ? { 'X-Requested-With': 'XMLHttpRequest' } : {};
      const res = await page.request.get(`http://127.0.0.1:58080${api.url}`, { headers });
      expect(res.status(), `expected 200 for ${api.url}`).toBe(200);
    }

    const writeApis = [
      { url: '/admin/file_api.php?action=write', method: 'POST', ajax: true },
      { url: '/admin/file_api.php?action=mkdir', method: 'POST', ajax: true },
      { url: '/admin/file_api.php?action=delete', method: 'POST', ajax: true },
      { url: '/admin/file_api.php?action=chmod', method: 'POST', ajax: true },
      { url: '/admin/docker_api.php?action=container_action', method: 'POST', ajax: true },
      { url: '/admin/docker_api.php?action=container_delete', method: 'POST', ajax: true },
      { url: '/admin/host_api.php?action=ssh_target_config_save', method: 'POST', ajax: true },
      { url: '/admin/host_api.php?action=ssh_target_service_action', method: 'POST', ajax: true },
      { url: '/admin/host_api.php?action=process_kill', method: 'POST', ajax: true },
      { url: '/admin/host_api.php?action=batch_test_hosts', method: 'POST', ajax: true },
      { url: '/admin/host_api.php?action=terminal_open', method: 'POST', ajax: true },
      { url: '/admin/host_api.php?action=authorized_keys_add', method: 'POST', ajax: true },
    ];

    for (const api of writeApis) {
      const headers: Record<string, string> = api.ajax ? { 'X-Requested-With': 'XMLHttpRequest' } : {};
      const fn = api.method === 'POST' ? page.request.post.bind(page.request) : page.request.get.bind(page.request);
      const res = await fn(`http://127.0.0.1:58080${api.url}`, { headers });
      expect(res.status(), `expected 403 for ${api.url}`).toBe(403);
    }

    await tracker.assertNoClientErrors();
  });

  test('host admin can read and write host related apis', async ({ page }) => {
    const tracker = await attachClientErrorTracking(page);
    await loginAsUser(page, adminUser, adminPass);

    for (const url of ['/admin/hosts.php', '/admin/files.php', '/admin/docker_hosts.php', '/admin/host_runtime.php']) {
      const res = await page.request.get(`http://127.0.0.1:58080${url}`);
      expect(res.status(), `expected 200 for ${url}`).toBe(200);
    }

    const adminWriteApis = [
      { url: '/admin/file_api.php?action=list&path=/', ajax: true },
      { url: '/admin/docker_api.php?action=summary', ajax: true },
      { url: '/admin/host_api.php?action=ssh_target_status&host_id=local', ajax: true },
    ];

    for (const api of adminWriteApis) {
      const headers: Record<string, string> = api.ajax ? { 'X-Requested-With': 'XMLHttpRequest' } : {};
      const res = await page.request.get(`http://127.0.0.1:58080${api.url}`, { headers });
      expect(res.status(), `expected 200 for ${api.url}`).toBe(200);
    }

    // host_admin is also non-admin; pages protected by header.php return 403
    for (const url of ['/admin/sites.php', '/admin/settings.php']) {
      const res = await page.request.get(`http://127.0.0.1:58080${url}`);
      expect(res.status(), `expected 403 for ${url}`).toBe(403);
    }

    // users.php redirects to login.php then index.php for logged-in non-admins
    const usersResAdmin = await page.request.get('http://127.0.0.1:58080/admin/users.php');
    expect(usersResAdmin.url(), 'expected redirect for /admin/users.php').toMatch(/(login\.php|index\.php)$/);

    await tracker.assertNoClientErrors();
  });
});
