import fs from 'fs/promises';
import path from 'path';
import { test, expect } from '../../helpers/fixtures';
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
  const payload = JSON.parse(result.stdout);
  expect(payload.ok).toBe(true);
}

test.beforeEach(async () => {
  await cleanupHostAgent();
});

test.afterEach(async () => {
  await cleanupHostAgent();
});

test('hosts page ssh actions save validate restore backup service toggle and install work', async ({ page }) => {
  test.setTimeout(120000);
  await ensureInstalledHostAgent();
  const tracker = await attachClientErrorTracking(page);

  await loginAsDevAdmin(page);
  await page.goto('/admin/hosts.php');

  const csrf = await page.locator('input[name="_csrf"]').first().inputValue();

  // save_ssh_config
  const newConfig = [
    '# Managed by host-agent',
    'Port 2222',
    'PermitRootLogin no',
    'PasswordAuthentication no',
    'PubkeyAuthentication yes',
    '',
  ].join('\n');
  const saveConfig = await page.request.post('http://127.0.0.1:58080/admin/hosts.php', {
    form: { action: 'save_ssh_config', _csrf: csrf, ssh_config: newConfig },
    maxRedirects: 0,
  });
  expect(saveConfig.status()).toBe(302);

  // validate_ssh_config
  const validateConfig = await page.request.post('http://127.0.0.1:58080/admin/hosts.php', {
    form: { action: 'validate_ssh_config', _csrf: csrf, ssh_config: newConfig },
    maxRedirects: 0,
  });
  expect(validateConfig.status()).toBe(302);

  // restore_ssh_backup
  const restoreBackup = await page.request.post('http://127.0.0.1:58080/admin/hosts.php', {
    form: { action: 'restore_ssh_backup', _csrf: csrf },
    maxRedirects: 0,
  });
  expect(restoreBackup.status()).toBe(302);

  // ssh_service_action (restart)
  const serviceAction = await page.request.post('http://127.0.0.1:58080/admin/hosts.php', {
    form: { action: 'ssh_service_action', _csrf: csrf, service_action: 'restart' },
    maxRedirects: 0,
  });
  expect(serviceAction.status()).toBe(302);

  // ssh_toggle_enable
  const toggleEnable = await page.request.post('http://127.0.0.1:58080/admin/hosts.php', {
    form: { action: 'ssh_toggle_enable', _csrf: csrf, enabled: '1' },
    maxRedirects: 0,
  });
  expect(toggleEnable.status()).toBe(302);

  // ssh_install_service
  const installService = await page.request.post('http://127.0.0.1:58080/admin/hosts.php', {
    form: { action: 'ssh_install_service', _csrf: csrf },
    maxRedirects: 0,
  });
  expect(installService.status()).toBe(302);

  await tracker.assertNoClientErrors();
});
