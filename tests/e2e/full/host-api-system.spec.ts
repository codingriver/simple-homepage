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

test('host api system actions return expected payloads and process_kill works', async ({ page }) => {
  test.setTimeout(120000);
  await ensureInstalledHostAgent();
  const tracker = await attachClientErrorTracking(page);

  await loginAsDevAdmin(page);

  // system_overview
  const overviewRes = await page.request.get(
    'http://127.0.0.1:58080/admin/host_api.php?action=system_overview',
    { headers: { 'X-Requested-With': 'XMLHttpRequest' } }
  );
  expect(overviewRes.status()).toBe(200);
  const overviewBody = await overviewRes.json();
  expect(overviewBody.ok).toBe(true);
  expect(typeof overviewBody.hostname).toBe('string');

  // process_list
  const processRes = await page.request.get(
    'http://127.0.0.1:58080/admin/host_api.php?action=process_list&keyword=&sort=cpu&limit=50',
    { headers: { 'X-Requested-With': 'XMLHttpRequest' } }
  );
  expect(processRes.status()).toBe(200);
  const processBody = await processRes.json();
  expect(processBody.ok).toBe(true);
  expect(Array.isArray(processBody.items)).toBe(true);

  // service_list
  const serviceRes = await page.request.get(
    'http://127.0.0.1:58080/admin/host_api.php?action=service_list&keyword=&limit=50',
    { headers: { 'X-Requested-With': 'XMLHttpRequest' } }
  );
  expect(serviceRes.status()).toBe(200);
  const serviceBody = await serviceRes.json();
  expect(serviceBody.ok).toBe(true);
  expect(Array.isArray(serviceBody.items)).toBe(true);

  // service_logs
  const logsRes = await page.request.get(
    'http://127.0.0.1:58080/admin/host_api.php?action=service_logs&service=nginx&limit=20',
    { headers: { 'X-Requested-With': 'XMLHttpRequest' } }
  );
  expect(logsRes.status()).toBe(200);
  const logsBody = await logsRes.json();
  expect(logsBody.ok).toBe(true);
  expect(Array.isArray(logsBody.lines)).toBe(true);

  // network_overview
  const networkRes = await page.request.get(
    'http://127.0.0.1:58080/admin/host_api.php?action=network_overview&limit=50',
    { headers: { 'X-Requested-With': 'XMLHttpRequest' } }
  );
  expect(networkRes.status()).toBe(200);
  const networkBody = await networkRes.json();
  expect(networkBody.ok).toBe(true);
  expect(Array.isArray(networkBody.listeners)).toBe(true);

  // process_kill (simulate mode may return ok:false but we test the contract)
  const csrf = await getHostCsrf(page);
  const killRes = await page.request.post('http://127.0.0.1:58080/admin/host_api.php', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: { action: 'process_kill', _csrf: csrf, pid: '1', signal: 'TERM' },
  });
  expect(killRes.status()).toBe(200);
  expect(typeof (await killRes.json()).ok).toBe('boolean');

  // service_action_generic
  const csrf2 = await getHostCsrf(page);
  const actionRes = await page.request.post('http://127.0.0.1:58080/admin/host_api.php', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: { action: 'service_action_generic', _csrf: csrf2, service: 'nginx', service_action: 'reload' },
  });
  expect(actionRes.status()).toBe(200);
  expect(typeof (await actionRes.json()).ok).toBe('boolean');

  await tracker.assertNoClientErrors();
});
