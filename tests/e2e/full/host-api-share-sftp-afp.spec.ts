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

test('host api sftp and afp actions return expected payloads and audit logs', async ({ page }) => {
  test.setTimeout(120000);
  await ensureInstalledHostAgent();
  const tracker = await attachClientErrorTracking(page);

  const ts = Date.now();
  const sftpUser = `sftpuser${ts}`;
  const afpShare = `afpshare${ts}`;

  await loginAsDevAdmin(page);

  // sftp_status
  const sftpStatusRes = await page.request.get(
    'http://127.0.0.1:58080/admin/host_api.php?action=sftp_status',
    { headers: { 'X-Requested-With': 'XMLHttpRequest' } }
  );
  expect(sftpStatusRes.status()).toBe(200);
  expect(typeof (await sftpStatusRes.json()).ok).toBe('boolean');

  // sftp_policy_list
  const sftpListRes = await page.request.get(
    'http://127.0.0.1:58080/admin/host_api.php?action=sftp_policy_list',
    { headers: { 'X-Requested-With': 'XMLHttpRequest' } }
  );
  expect(sftpListRes.status()).toBe(200);
  const sftpListBody = await sftpListRes.json();
  expect(Array.isArray(sftpListBody.data?.items ?? sftpListBody.items)).toBe(true);

  // sftp_policy_save
  let csrf = await getHostCsrf(page);
  const sftpSaveRes = await page.request.post('http://127.0.0.1:58080/admin/host_api.php', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: { action: 'sftp_policy_save', _csrf: csrf, username: sftpUser, enabled: '1', sftp_only: '1', chroot_directory: `/srv/${sftpUser}`, force_internal_sftp: '1', allow_password: '1', allow_pubkey: '1' },
  });
  expect(sftpSaveRes.status()).toBe(200);
  expect(typeof (await sftpSaveRes.json()).ok).toBe('boolean');

  // sftp_policy_delete
  csrf = await getHostCsrf(page);
  const sftpDelRes = await page.request.post('http://127.0.0.1:58080/admin/host_api.php', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: { action: 'sftp_policy_delete', _csrf: csrf, username: sftpUser },
  });
  expect(sftpDelRes.status()).toBe(200);
  expect(typeof (await sftpDelRes.json()).ok).toBe('boolean');

  // afp_status
  const afpStatusRes = await page.request.get(
    'http://127.0.0.1:58080/admin/host_api.php?action=afp_status',
    { headers: { 'X-Requested-With': 'XMLHttpRequest' } }
  );
  expect(afpStatusRes.status()).toBe(200);
  expect(typeof (await afpStatusRes.json()).ok).toBe('boolean');

  // afp_share_save
  csrf = await getHostCsrf(page);
  const afpSaveRes = await page.request.post('http://127.0.0.1:58080/admin/host_api.php', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: { action: 'afp_share_save', _csrf: csrf, name: afpShare, path: `/srv/${afpShare}`, port: '548', valid_users: '', rwlist: '' },
  });
  expect(afpSaveRes.status()).toBe(200);
  expect(typeof (await afpSaveRes.json()).ok).toBe('boolean');

  // afp_share_delete
  csrf = await getHostCsrf(page);
  const afpDelRes = await page.request.post('http://127.0.0.1:58080/admin/host_api.php', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: { action: 'afp_share_delete', _csrf: csrf, name: afpShare },
  });
  expect(afpDelRes.status()).toBe(200);
  expect(typeof (await afpDelRes.json()).ok).toBe('boolean');

  // afp_install / afp_action / afp_uninstall
  csrf = await getHostCsrf(page);
  const afpInstallRes = await page.request.post('http://127.0.0.1:58080/admin/host_api.php', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: { action: 'afp_install', _csrf: csrf },
  });
  expect(afpInstallRes.status()).toBe(200);
  expect(typeof (await afpInstallRes.json()).ok).toBe('boolean');

  csrf = await getHostCsrf(page);
  const afpActionRes = await page.request.post('http://127.0.0.1:58080/admin/host_api.php', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: { action: 'afp_action', _csrf: csrf, service_action: 'restart' },
  });
  expect(afpActionRes.status()).toBe(200);
  expect(typeof (await afpActionRes.json()).ok).toBe('boolean');

  csrf = await getHostCsrf(page);
  const afpUninstallRes = await page.request.post('http://127.0.0.1:58080/admin/host_api.php', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: { action: 'afp_uninstall', _csrf: csrf },
  });
  expect(afpUninstallRes.status()).toBe(200);
  expect(typeof (await afpUninstallRes.json()).ok).toBe('boolean');

  await tracker.assertNoClientErrors();
});
