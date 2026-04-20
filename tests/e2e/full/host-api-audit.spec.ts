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

test('host api audit and share history actions return expected payloads', async ({ page }) => {
  test.setTimeout(120000);
  await ensureInstalledHostAgent();
  const tracker = await attachClientErrorTracking(page);

  const ts = Date.now();

  // seed audit logs
  runDockerPhpInline(
    [
      '$log = "/var/www/nav/data/logs/share_service_audit.log";',
      '$entry = json_encode(["t" => time(), "action" => "smb_share_save", "service" => "smb", "name" => "audit-smb-' + ts + '"], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) . "\n";',
      'file_put_contents($log, $entry, FILE_APPEND|LOCK_EX);',
      '$sshLog = "/var/www/nav/data/logs/ssh_manager_audit.log";',
      '$sshEntry = json_encode(["t" => time(), "action" => "host_user_save", "username" => "audituser' + ts + '"], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) . "\n";',
      'file_put_contents($sshLog, $sshEntry, FILE_APPEND|LOCK_EX);',
    ].join(' ')
  );

  // seed share history
  runDockerPhpInline(
    [
      'require "/var/www/nav/admin/shared/share_service_lib.php";',
      'share_service_history_write("smb", "save_share", ["service" => "smb", "files" => []], ["name" => "hist-smb-' + ts + '"]);',
    ].join(' ')
  );

  await loginAsDevAdmin(page);

  // share_audit_query
  const shareAuditRes = await page.request.get(
    `http://127.0.0.1:58080/admin/host_api.php?action=share_audit_query&service=smb&limit=50&page=1`,
    { headers: { 'X-Requested-With': 'XMLHttpRequest' } }
  );
  expect(shareAuditRes.status()).toBe(200);
  const shareAuditBody = await shareAuditRes.json();
  expect(shareAuditBody.ok).toBe(true);
  expect(Array.isArray(shareAuditBody.data?.items ?? shareAuditBody.items)).toBe(true);

  // share_audit_export
  const shareExportRes = await page.request.get(
    `http://127.0.0.1:58080/admin/host_api.php?action=share_audit_export&service=smb&limit=50`,
    { headers: { 'X-Requested-With': 'XMLHttpRequest' } }
  );
  expect(shareExportRes.status()).toBe(200);
  const disp = shareExportRes.headers()['content-disposition'] || '';
  expect(disp).toContain('attachment');
  expect(Array.isArray(await shareExportRes.json())).toBe(true);

  // audit_query (host api generic audit)
  const auditRes = await page.request.get(
    `http://127.0.0.1:58080/admin/host_api.php?action=audit_query&limit=50&page=1`,
    { headers: { 'X-Requested-With': 'XMLHttpRequest' } }
  );
  expect(auditRes.status()).toBe(200);
  const auditBody = await auditRes.json();
  expect(auditBody.ok).toBe(true);
  expect(Array.isArray(auditBody.data?.items ?? auditBody.items)).toBe(true);

  // audit_export
  const exportRes = await page.request.get(
    `http://127.0.0.1:58080/admin/host_api.php?action=audit_export&limit=50`,
    { headers: { 'X-Requested-With': 'XMLHttpRequest' } }
  );
  expect(exportRes.status()).toBe(200);
  const exportDisp = exportRes.headers()['content-disposition'] || '';
  expect(exportDisp).toContain('attachment');
  expect(Array.isArray(await exportRes.json())).toBe(true);

  // share_history_list
  const historyRes = await page.request.get(
    `http://127.0.0.1:58080/admin/host_api.php?action=share_history_list&service=smb&limit=50`,
    { headers: { 'X-Requested-With': 'XMLHttpRequest' } }
  );
  expect(historyRes.status()).toBe(200);
  const historyBody = await historyRes.json();
  expect(historyBody.ok).toBe(true);
  expect(Array.isArray(historyBody.data?.items ?? historyBody.items)).toBe(true);

  // share_history_restore
  const csrf = await getHostCsrf(page);
  const restoreRes = await page.request.post('http://127.0.0.1:58080/admin/host_api.php', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: { action: 'share_history_restore', _csrf: csrf, service: 'smb', history_id: '0' },
  });
  expect(restoreRes.status()).toBe(200);
  expect(typeof (await restoreRes.json()).ok).toBe('boolean');

  await tracker.assertNoClientErrors();
});
