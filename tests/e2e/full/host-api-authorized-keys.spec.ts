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

test('host api authorized_keys actions manage keys for local root', async ({ page }) => {
  test.setTimeout(120000);
  await ensureInstalledHostAgent();
  const tracker = await attachClientErrorTracking(page);

  const ts = Date.now();
  const publicKey = `ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIAuthKeyTest${ts} root@test`;

  await loginAsDevAdmin(page);

  // authorized_keys_list (empty initially)
  const listRes = await page.request.get(
    `http://127.0.0.1:58080/admin/host_api.php?action=authorized_keys_list&host_id=local&user=root`,
    { headers: { 'X-Requested-With': 'XMLHttpRequest' } }
  );
  expect(listRes.status()).toBe(200);
  const listBody = await listRes.json();
  expect(listBody.ok).toBe(true);
  expect(Array.isArray(listBody.data?.keys ?? listBody.keys)).toBe(true);

  // authorized_keys_add
  const csrf = await getHostCsrf(page);
  const addRes = await page.request.post('http://127.0.0.1:58080/admin/host_api.php', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: { action: 'authorized_keys_add', _csrf: csrf, host_id: 'local', user: 'root', public_key: publicKey },
  });
  expect(addRes.status()).toBe(200);
  expect((await addRes.json()).ok).toBe(true);

  // verify list contains added key
  const listAfterRes = await page.request.get(
    `http://127.0.0.1:58080/admin/host_api.php?action=authorized_keys_list&host_id=local&user=root`,
    { headers: { 'X-Requested-With': 'XMLHttpRequest' } }
  );
  const listAfterBody = await listAfterRes.json();
  const keys = (listAfterBody.data?.keys ?? listAfterBody.keys) as any[];
  const addedKey = keys.find((k) => (k.key || '').includes(`AuthKeyTest${ts}`));
  expect(addedKey).toBeTruthy();

  // authorized_keys_remove
  const csrf2 = await getHostCsrf(page);
  const removeRes = await page.request.post('http://127.0.0.1:58080/admin/host_api.php', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: { action: 'authorized_keys_remove', _csrf: csrf2, host_id: 'local', user: 'root', line_hash: addedKey.line_hash },
  });
  expect(removeRes.status()).toBe(200);
  expect((await removeRes.json()).ok).toBe(true);

  // verify removed
  const listFinalRes = await page.request.get(
    `http://127.0.0.1:58080/admin/host_api.php?action=authorized_keys_list&host_id=local&user=root`,
    { headers: { 'X-Requested-With': 'XMLHttpRequest' } }
  );
  const listFinalBody = await listFinalRes.json();
  const finalKeys = (listFinalBody.data?.keys ?? listFinalBody.keys) as any[];
  expect(finalKeys.some((k) => (k.key || '').includes(`AuthKeyTest${ts}`))).toBe(false);

  await tracker.assertNoClientErrors();
});
