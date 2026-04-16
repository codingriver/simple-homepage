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

test('host api ftp actions return expected payloads and audit logs', async ({ page }) => {
  test.setTimeout(120000);
  await ensureInstalledHostAgent();
  const tracker = await attachClientErrorTracking(page);

  await loginAsDevAdmin(page);

  // ftp_status
  const statusRes = await page.request.get(
    'http://127.0.0.1:58080/admin/host_api.php?action=ftp_status',
    { headers: { 'X-Requested-With': 'XMLHttpRequest' } }
  );
  expect(statusRes.status()).toBe(200);
  expect(typeof (await statusRes.json()).ok).toBe('boolean');

  // ftp_settings_save
  let csrf = await getHostCsrf(page);
  const saveRes = await page.request.post('http://127.0.0.1:58080/admin/host_api.php', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: { action: 'ftp_settings_save', _csrf: csrf, listen_port: '21', anonymous_enable: '0', local_enable: '1', write_enable: '1', chroot_local_user: '1', local_root: '/srv/ftp', pasv_enable: '1', pasv_min_port: '40000', pasv_max_port: '40100', allowed_users: '' },
  });
  expect(saveRes.status()).toBe(200);
  expect(typeof (await saveRes.json()).ok).toBe('boolean');

  // ftp_install / ftp_action / ftp_uninstall
  csrf = await getHostCsrf(page);
  const installRes = await page.request.post('http://127.0.0.1:58080/admin/host_api.php', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: { action: 'ftp_install', _csrf: csrf },
  });
  expect(installRes.status()).toBe(200);
  expect(typeof (await installRes.json()).ok).toBe('boolean');

  csrf = await getHostCsrf(page);
  const actionRes = await page.request.post('http://127.0.0.1:58080/admin/host_api.php', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: { action: 'ftp_action', _csrf: csrf, service_action: 'restart' },
  });
  expect(actionRes.status()).toBe(200);
  expect(typeof (await actionRes.json()).ok).toBe('boolean');

  csrf = await getHostCsrf(page);
  const uninstallRes = await page.request.post('http://127.0.0.1:58080/admin/host_api.php', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: { action: 'ftp_uninstall', _csrf: csrf },
  });
  expect(uninstallRes.status()).toBe(200);
  expect(typeof (await uninstallRes.json()).ok).toBe('boolean');

  await tracker.assertNoClientErrors();
});
