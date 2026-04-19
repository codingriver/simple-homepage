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
  await runDockerPhpInline('file_put_contents("/var/www/nav/data/host_agent.json", "{}", LOCK_EX);');
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

test('host api async actions return expected payloads', async ({ page }) => {
  test.setTimeout(120000);
  await ensureInstalledHostAgent();
  const tracker = await attachClientErrorTracking(page);

  const ts = Date.now();
  const moduleName = `asyncmod${ts}`;

  await loginAsDevAdmin(page);

  // async_status
  const statusRes = await page.request.get(
    'http://127.0.0.1:58080/admin/host_api.php?action=async_status',
    { headers: { 'X-Requested-With': 'XMLHttpRequest' } }
  );
  expect(statusRes.status()).toBe(200);
  expect(typeof (await statusRes.json()).ok).toBe('boolean');

  // async_module_save
  let csrf = await getHostCsrf(page);
  const saveRes = await page.request.post('http://127.0.0.1:58080/admin/host_api.php', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: { action: 'async_module_save', _csrf: csrf, name: moduleName, path: `/srv/${moduleName}`, options: 'rw' },
  });
  expect(saveRes.status()).toBe(200);
  expect(typeof (await saveRes.json()).ok).toBe('boolean');

  // async_module_delete
  csrf = await getHostCsrf(page);
  const delRes = await page.request.post('http://127.0.0.1:58080/admin/host_api.php', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: { action: 'async_module_delete', _csrf: csrf, name: moduleName },
  });
  expect(delRes.status()).toBe(200);
  expect(typeof (await delRes.json()).ok).toBe('boolean');

  // async_install / async_action / async_uninstall
  csrf = await getHostCsrf(page);
  const installRes = await page.request.post('http://127.0.0.1:58080/admin/host_api.php', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: { action: 'async_install', _csrf: csrf },
  });
  expect(installRes.status()).toBe(200);
  expect(typeof (await installRes.json()).ok).toBe('boolean');

  csrf = await getHostCsrf(page);
  const actionRes = await page.request.post('http://127.0.0.1:58080/admin/host_api.php', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: { action: 'async_action', _csrf: csrf, service_action: 'restart' },
  });
  expect(actionRes.status()).toBe(200);
  expect(typeof (await actionRes.json()).ok).toBe('boolean');

  csrf = await getHostCsrf(page);
  const uninstallRes = await page.request.post('http://127.0.0.1:58080/admin/host_api.php', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: { action: 'async_uninstall', _csrf: csrf },
  });
  expect(uninstallRes.status()).toBe(200);
  expect(typeof (await uninstallRes.json()).ok).toBe('boolean');

  await tracker.assertNoClientErrors();
});
