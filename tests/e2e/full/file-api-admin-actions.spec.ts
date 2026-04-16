import { expect, test } from '../../helpers/fixtures';
import { attachClientErrorTracking, loginAsDevAdmin } from '../../helpers/auth';
import { runDockerPhpInline } from '../../helpers/cli';

async function getCsrf(page: any) {
  await page.goto('/admin/files.php');
  return page.evaluate(() => (window as any)._csrf);
}

test('file api admin-only and audit actions work as expected', async ({ page }) => {
  test.setTimeout(120000);
  const tracker = await attachClientErrorTracking(page);

  const ts = Date.now();
  const baseDir = `/file-api-admin-${ts}`;

  // enable webdav and clear accounts
  runDockerPhpInline(
    [
      '$cfgPath = "/var/www/nav/data/config.json";',
      '$cfg = file_exists($cfgPath) ? (json_decode((string)file_get_contents($cfgPath), true) ?: []) : [];',
      '$cfg["webdav_enabled"] = "1";',
      'file_put_contents($cfgPath, json_encode($cfg, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));',
      '$accounts = "/var/www/nav/data/webdav_accounts.json";',
      'if (file_exists($accounts)) unlink($accounts);',
      '$dir = "/var/www/nav/data/host-agent-sim-root' + baseDir + '";',
      'if (!is_dir($dir)) { mkdir($dir, 0777, true); }',
    ].join(' ')
  );

  // seed an audit event
  runDockerPhpInline(
    [
      '$log = "/var/www/nav/data/logs/ssh_manager_audit.log";',
      '$entry = json_encode(["t" => time(), "action" => "fs_write", "host_id" => "local", "path" => "' + baseDir + '/x.txt", "username" => "qatest"], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) . "\n";',
      'file_put_contents($log, $entry, FILE_APPEND|LOCK_EX);',
    ].join(' ')
  );

  await loginAsDevAdmin(page);
  const csrf = await getCsrf(page);

  // webdav_share_create
  const webdavRes = await page.request.post('http://127.0.0.1:58080/admin/file_api.php?action=webdav_share_create', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: { host_id: 'local', path: baseDir, username: `wduser_${ts}`, password: 'WdavPass@test2026', readonly: '0', _csrf: csrf },
  });
  expect(webdavRes.status()).toBe(200);
  const webdavBody = await webdavRes.json();
  expect(webdavBody.ok).toBe(true);

  // verify account exists
  const accountCheck = runDockerPhpInline(
    [
      '$path = "/var/www/nav/data/webdav_accounts.json";',
      '$data = file_exists($path) ? (json_decode((string)file_get_contents($path), true) ?: []) : [];',
      '$accounts = $data["accounts"] ?? [];',
      '$found = false;',
      'foreach ($accounts as $item) {',
      '  if (($item["username"] ?? "") === "wduser_' + ts + '") { $found = true; break; }',
      '}',
      'echo $found ? "1" : "0";',
    ].join(' ')
  );
  expect(accountCheck.code).toBe(0);
  expect(accountCheck.stdout.trim()).toBe('1');

  // audit_query
  const auditRes = await page.request.get(
    `http://127.0.0.1:58080/admin/file_api.php?action=audit_query&prefix=fs_&host_id=local&keyword=${encodeURIComponent(baseDir)}&limit=50&page=1`,
    { headers: { 'X-Requested-With': 'XMLHttpRequest' } }
  );
  expect(auditRes.status()).toBe(200);
  const auditBody = await auditRes.json();
  expect(auditBody.ok).toBe(true);
  const auditLogs = auditBody.data?.logs ?? auditBody.logs ?? [];
  expect(auditLogs.some((l: any) => (l.path || '').includes(baseDir) || (l.action || '').includes('fs_write'))).toBe(true);

  // audit_export
  const exportRes = await page.request.get(
    `http://127.0.0.1:58080/admin/file_api.php?action=audit_export&prefix=fs_&host_id=local&keyword=${encodeURIComponent(baseDir)}&limit=50`,
    { headers: { 'X-Requested-With': 'XMLHttpRequest' } }
  );
  expect(exportRes.status()).toBe(200);
  const disposition = exportRes.headers()['content-disposition'] || '';
  expect(disposition).toContain('attachment');
  const exported = await exportRes.json();
  expect(Array.isArray(exported)).toBe(true);

  await tracker.assertNoClientErrors();
});
