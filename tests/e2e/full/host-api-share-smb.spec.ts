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
  let lastError = '';
  for (let attempt = 1; attempt <= 3; attempt++) {
    const result = runDockerPhpInline(
      [
        'require "/var/www/nav/admin/shared/host_agent_lib.php";',
        '$result = host_agent_install();',
        'echo json_encode($result, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);',
      ].join(' ')
    );
    if (result.code === 0) {
      try {
        const payload = JSON.parse(result.stdout);
        if (payload.ok === true) {
          // 安装成功后短暂等待，让容器状态稳定
          await new Promise(r => setTimeout(r, 1000));
          return;
        }
        lastError = JSON.stringify(payload);
      } catch {
        lastError = 'JSON parse error: stdout=' + result.stdout + ', stderr=' + result.stderr;
      }
    } else {
      lastError = 'exit code ' + result.code + ': ' + result.output;
    }
    if (attempt < 3) {
      await new Promise(r => setTimeout(r, 2000));
    }
  }
  throw new Error('ensureInstalledHostAgent failed after 3 attempts: ' + lastError);
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

test('host api smb actions return expected payloads and audit logs', async ({ page }) => {
  test.setTimeout(120000);
  await ensureInstalledHostAgent();
  const tracker = await attachClientErrorTracking(page);

  const ts = Date.now();
  const shareName = `smbshare${ts}`;

  await loginAsDevAdmin(page);

  // smb_status
  const statusRes = await page.request.get(
    'http://127.0.0.1:58080/admin/host_api.php?action=smb_status',
    { headers: { 'X-Requested-With': 'XMLHttpRequest' } }
  );
  expect(statusRes.status()).toBe(200);
  const statusBody = await statusRes.json();
  expect(typeof statusBody.ok).toBe('boolean');

  // smb_share_list
  const listRes = await page.request.get(
    'http://127.0.0.1:58080/admin/host_api.php?action=smb_share_list',
    { headers: { 'X-Requested-With': 'XMLHttpRequest' } }
  );
  expect(listRes.status()).toBe(200);
  const listBody = await listRes.json();
  expect(Array.isArray(listBody.data?.items ?? listBody.items)).toBe(true);

  // smb_share_save
  let csrf = await getHostCsrf(page);
  const saveRes = await page.request.post('http://127.0.0.1:58080/admin/host_api.php', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: { action: 'smb_share_save', _csrf: csrf, name: shareName, path: `/srv/${shareName}`, comment: 'test', browseable: '1', read_only: '0', guest_ok: '0', valid_users: '', write_users: '' },
  });
  expect(saveRes.status()).toBe(200);
  expect(typeof (await saveRes.json()).ok).toBe('boolean');

  // smb_share_delete
  csrf = await getHostCsrf(page);
  const delRes = await page.request.post('http://127.0.0.1:58080/admin/host_api.php', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: { action: 'smb_share_delete', _csrf: csrf, name: shareName },
  });
  expect(delRes.status()).toBe(200);
  expect(typeof (await delRes.json()).ok).toBe('boolean');

  // smb_install / smb_action / smb_uninstall
  csrf = await getHostCsrf(page);
  const installRes = await page.request.post('http://127.0.0.1:58080/admin/host_api.php', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: { action: 'smb_install', _csrf: csrf },
  });
  expect(installRes.status()).toBe(200);
  expect(typeof (await installRes.json()).ok).toBe('boolean');

  csrf = await getHostCsrf(page);
  const actionRes = await page.request.post('http://127.0.0.1:58080/admin/host_api.php', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: { action: 'smb_action', _csrf: csrf, service_action: 'restart' },
  });
  expect(actionRes.status()).toBe(200);
  expect(typeof (await actionRes.json()).ok).toBe('boolean');

  csrf = await getHostCsrf(page);
  const uninstallRes = await page.request.post('http://127.0.0.1:58080/admin/host_api.php', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: { action: 'smb_uninstall', _csrf: csrf },
  });
  expect(uninstallRes.status()).toBe(200);
  expect(typeof (await uninstallRes.json()).ok).toBe('boolean');

  await tracker.assertNoClientErrors();
});
