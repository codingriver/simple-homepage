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

test('host api ssh service actions control service state', async ({ page }) => {
  test.setTimeout(120000);
  await ensureInstalledHostAgent();
  const tracker = await attachClientErrorTracking(page);

  await loginAsDevAdmin(page);
  let csrf = await getHostCsrf(page);

  // ssh_target_service_action stop
  const stopRes = await page.request.post('http://127.0.0.1:58080/admin/host_api.php', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: { action: 'ssh_target_service_action', _csrf: csrf, host_id: 'local', service_action: 'stop' },
  });
  expect(stopRes.status()).toBe(200);
  expect((await stopRes.json()).ok).toBe(true);

  let state = runDockerPhpInline(
    ['$path = "/var/www/nav/data/host-agent-sim-root/var/lib/host-agent/ssh_service_state.json";', 'echo file_exists($path) ? file_get_contents($path) : "{}";'].join(' ')
  );
  expect(state.code).toBe(0);
  expect(JSON.parse(state.stdout).running).toBe(false);

  // ssh_target_service_action start
  csrf = await getHostCsrf(page);
  const startRes = await page.request.post('http://127.0.0.1:58080/admin/host_api.php', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: { action: 'ssh_target_service_action', _csrf: csrf, host_id: 'local', service_action: 'start' },
  });
  expect(startRes.status()).toBe(200);
  expect((await startRes.json()).ok).toBe(true);

  state = runDockerPhpInline(
    ['$path = "/var/www/nav/data/host-agent-sim-root/var/lib/host-agent/ssh_service_state.json";', 'echo file_exists($path) ? file_get_contents($path) : "{}";'].join(' ')
  );
  expect(state.code).toBe(0);
  expect(JSON.parse(state.stdout).running).toBe(true);

  // ssh_target_toggle_enable
  csrf = await getHostCsrf(page);
  const toggleRes = await page.request.post('http://127.0.0.1:58080/admin/host_api.php', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: { action: 'ssh_target_toggle_enable', _csrf: csrf, host_id: 'local', enabled: '0' },
  });
  expect(toggleRes.status()).toBe(200);
  expect(typeof (await toggleRes.json()).ok).toBe('boolean');

  // ssh_target_install_service
  csrf = await getHostCsrf(page);
  const installRes = await page.request.post('http://127.0.0.1:58080/admin/host_api.php', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: { action: 'ssh_target_install_service', _csrf: csrf, host_id: 'local' },
  });
  expect(installRes.status()).toBe(200);
  expect(typeof (await installRes.json()).ok).toBe('boolean');

  await tracker.assertNoClientErrors();
});
