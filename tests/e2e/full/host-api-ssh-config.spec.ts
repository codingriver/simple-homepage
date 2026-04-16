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

test('host api ssh config actions mutate config and return expected payloads', async ({ page }) => {
  test.setTimeout(120000);
  await ensureInstalledHostAgent();
  const tracker = await attachClientErrorTracking(page);

  await loginAsDevAdmin(page);
  let csrf = await getHostCsrf(page);

  // ssh_target_status
  const statusRes = await page.request.get(
    'http://127.0.0.1:58080/admin/host_api.php?action=ssh_target_status&host_id=local',
    { headers: { 'X-Requested-With': 'XMLHttpRequest' } }
  );
  expect(statusRes.status()).toBe(200);
  const statusBody = await statusRes.json();
  expect(statusBody.ok).toBe(true);
  expect(statusBody.mode).toBe('simulate');

  // ssh_target_config_read
  const readRes = await page.request.get(
    'http://127.0.0.1:58080/admin/host_api.php?action=ssh_target_config_read&host_id=local',
    { headers: { 'X-Requested-With': 'XMLHttpRequest' } }
  );
  expect(readRes.status()).toBe(200);
  const readBody = await readRes.json();
  expect(readBody.ok).toBe(true);
  expect(readBody.content).toContain('Managed by host-agent');

  // ssh_target_config_save
  const newConfig = ['# Managed by host-agent', 'Port 2222', 'PermitRootLogin no'].join('\n');
  const saveRes = await page.request.post('http://127.0.0.1:58080/admin/host_api.php', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: { action: 'ssh_target_config_save', _csrf: csrf, host_id: 'local', content: newConfig },
  });
  expect(saveRes.status()).toBe(200);
  expect((await saveRes.json()).ok).toBe(true);

  const saved = runDockerPhpInline(
    ['$path = "/var/www/nav/data/host-agent-sim-root/etc/ssh/sshd_config";', 'echo file_exists($path) ? file_get_contents($path) : "";'].join(' ')
  );
  expect(saved.code).toBe(0);
  expect(saved.stdout).toContain('Port 2222');

  // ssh_target_config_validate
  const validateRes = await page.request.post('http://127.0.0.1:58080/admin/host_api.php', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: { action: 'ssh_target_config_validate', _csrf: csrf, host_id: 'local', content: newConfig },
  });
  expect(validateRes.status()).toBe(200);
  expect(typeof (await validateRes.json()).ok).toBe('boolean');

  // ssh_target_structured_save
  csrf = await getHostCsrf(page);
  const structuredRes = await page.request.post('http://127.0.0.1:58080/admin/host_api.php', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: {
      action: 'ssh_target_structured_save',
      _csrf: csrf,
      host_id: 'local',
      ssh_port: '2201',
      listen_address: '0.0.0.0',
      password_auth: '0',
      pubkey_auth: '1',
      permit_root_login: 'no',
      allow_users: 'root',
      allow_groups: 'wheel',
      x11_forwarding: '1',
      max_auth_tries: '4',
      client_alive_interval: '30',
      client_alive_count_max: '5',
    },
  });
  expect(structuredRes.status()).toBe(200);
  expect((await structuredRes.json()).ok).toBe(true);

  const structured = runDockerPhpInline(
    ['$path = "/var/www/nav/data/host-agent-sim-root/etc/ssh/sshd_config";', 'echo file_exists($path) ? file_get_contents($path) : "";'].join(' ')
  );
  expect(structured.code).toBe(0);
  expect(structured.stdout).toContain('Port 2201');
  expect(structured.stdout).toContain('ListenAddress 0.0.0.0');

  // ssh_target_config_apply
  csrf = await getHostCsrf(page);
  const applyRes = await page.request.post('http://127.0.0.1:58080/admin/host_api.php', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: { action: 'ssh_target_config_apply', _csrf: csrf, host_id: 'local', content: newConfig, restart_after_save: '1', rollback_on_failure: '1' },
  });
  expect(applyRes.status()).toBe(200);
  expect(typeof (await applyRes.json()).ok).toBe('boolean');

  // ssh_target_restore_backup
  csrf = await getHostCsrf(page);
  const restoreRes = await page.request.post('http://127.0.0.1:58080/admin/host_api.php', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: { action: 'ssh_target_restore_backup', _csrf: csrf, host_id: 'local' },
  });
  expect(restoreRes.status()).toBe(200);
  expect(typeof (await restoreRes.json()).ok).toBe('boolean');

  // remote_test for a non-existent host should 404
  const remoteTestRes = await page.request.post('http://127.0.0.1:58080/admin/host_api.php', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: { action: 'remote_test', _csrf: csrf, host_id: 'non-existent-host-id' },
  });
  expect(remoteTestRes.status()).toBe(404);

  await tracker.assertNoClientErrors();
});
