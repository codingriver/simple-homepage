import fs from 'fs/promises';
import path from 'path';
import { expect, test } from '../../helpers/fixtures';
import { attachClientErrorTracking, loginAsDevAdmin } from '../../helpers/auth';
import { runDockerCommand, runDockerPhpInline } from '../../helpers/cli';

const hostAgentStatePath = path.resolve(__dirname, '../../../data/host_agent.json');
const simulateRootPath = path.resolve(__dirname, '../../../data/host-agent-sim-root');
const hostAgentContainer = process.env.APP_CONTAINER ? `${process.env.APP_CONTAINER}-host-agent` : 'simple-homepage-host-agent';

async function cleanupHostAgent() {
  runDockerCommand(['rm', '-f', hostAgentContainer]);
  await fs.rm(hostAgentStatePath, { force: true }).catch(() => undefined);
  await fs.rm(simulateRootPath, { recursive: true, force: true }).catch(() => undefined);
}

async function ensureInstalledHostAgent() {
  const result = runDockerPhpInline(
    [
      'require "/var/www/nav/admin/shared/host_agent_lib.php";',
      '$result = host_agent_install();',
      'echo json_encode($result, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);',
    ].join(' ')
  );
  expect(result.code).toBe(0);
  expect(JSON.parse(result.stdout).ok).toBe(true);
}

async function getHostCsrf(page: any) {
  await page.goto('/admin/hosts.php');
  return page.evaluate(() => (window as any).HOST_CSRF || (window as any)._csrf || '');
}

test.beforeEach(async () => {
  await cleanupHostAgent();
});

test.afterEach(async () => {
  await cleanupHostAgent();
});

test('host api users and groups actions return expected payloads', async ({ page }) => {
  test.setTimeout(120000);
  await ensureInstalledHostAgent();
  const tracker = await attachClientErrorTracking(page);

  const ts = Date.now();
  const username = `huser${ts}`;
  const groupname = `hgroup${ts}`;

  await loginAsDevAdmin(page);

  // user_list
  const userListRes = await page.request.get(
    'http://127.0.0.1:58080/admin/host_api.php?action=user_list&keyword=',
    { headers: { 'X-Requested-With': 'XMLHttpRequest' } }
  );
  expect(userListRes.status()).toBe(200);
  const userListBody = await userListRes.json();
  expect(userListBody.ok).toBe(true);
  expect(Array.isArray(userListBody.data?.users ?? userListBody.users)).toBe(true);

  // group_list
  const groupListRes = await page.request.get(
    'http://127.0.0.1:58080/admin/host_api.php?action=group_list&keyword=',
    { headers: { 'X-Requested-With': 'XMLHttpRequest' } }
  );
  expect(groupListRes.status()).toBe(200);
  const groupListBody = await groupListRes.json();
  expect(groupListBody.ok).toBe(true);
  expect(Array.isArray(groupListBody.data?.groups ?? groupListBody.groups)).toBe(true);

  // user_save
  let csrf = await getHostCsrf(page);
  const userSaveRes = await page.request.post('http://127.0.0.1:58080/admin/host_api.php', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: { action: 'user_save', _csrf: csrf, username, shell: '/bin/sh', home: `/home/${username}`, groups: '', gecos: 'Test User', password: 'TestPass@test2026' },
  });
  expect(userSaveRes.status()).toBe(200);
  expect(typeof (await userSaveRes.json()).ok).toBe('boolean');

  // user_password
  csrf = await getHostCsrf(page);
  const userPassRes = await page.request.post('http://127.0.0.1:58080/admin/host_api.php', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: { action: 'user_password', _csrf: csrf, username, password: 'NewPass@test2026' },
  });
  expect(userPassRes.status()).toBe(200);
  expect(typeof (await userPassRes.json()).ok).toBe('boolean');

  // user_lock
  csrf = await getHostCsrf(page);
  const userLockRes = await page.request.post('http://127.0.0.1:58080/admin/host_api.php', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: { action: 'user_lock', _csrf: csrf, username, locked: '1' },
  });
  expect(userLockRes.status()).toBe(200);
  expect(typeof (await userLockRes.json()).ok).toBe('boolean');

  // group_save
  csrf = await getHostCsrf(page);
  const groupSaveRes = await page.request.post('http://127.0.0.1:58080/admin/host_api.php', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: { action: 'group_save', _csrf: csrf, groupname, members: username },
  });
  expect(groupSaveRes.status()).toBe(200);
  expect(typeof (await groupSaveRes.json()).ok).toBe('boolean');

  // group_delete
  csrf = await getHostCsrf(page);
  const groupDelRes = await page.request.post('http://127.0.0.1:58080/admin/host_api.php', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: { action: 'group_delete', _csrf: csrf, groupname },
  });
  expect(groupDelRes.status()).toBe(200);
  expect(typeof (await groupDelRes.json()).ok).toBe('boolean');

  // user_delete
  csrf = await getHostCsrf(page);
  const userDelRes = await page.request.post('http://127.0.0.1:58080/admin/host_api.php', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: { action: 'user_delete', _csrf: csrf, username, remove_home: '1' },
  });
  expect(userDelRes.status()).toBe(200);
  expect(typeof (await userDelRes.json()).ok).toBe('boolean');

  await tracker.assertNoClientErrors();
});
