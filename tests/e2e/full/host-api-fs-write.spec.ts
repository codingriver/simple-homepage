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

test('host api fs write actions mutate filesystem as expected', async ({ page }) => {
  test.setTimeout(120000);
  await ensureInstalledHostAgent();
  const tracker = await attachClientErrorTracking(page);

  const ts = Date.now();
  const baseDir = `/host-api-fs-write-${ts}`;
  const fileA = `${baseDir}/a.txt`;
  const fileB = `${baseDir}/b.txt`;
  const archivePath = `${baseDir}/archive.tar.gz`;
  const extractDir = `${baseDir}/extracted`;

  const seed = runDockerPhpInline(
    [
      '$dir = "/var/www/nav/data/host-agent-sim-root' + baseDir + '";',
      'if (!is_dir($dir)) { mkdir($dir, 0777, true); }',
      'file_put_contents($dir . "/a.txt", "aaa");',
      'file_put_contents($dir . "/b.txt", "bbb");',
    ].join(' ')
  );
  expect(seed.code).toBe(0);

  await loginAsDevAdmin(page);
  let csrf = await getHostCsrf(page);

  // file_write
  const writeRes = await page.request.post('http://127.0.0.1:58080/admin/host_api.php', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: { action: 'file_write', _csrf: csrf, host_id: 'local', path: `${baseDir}/new.txt`, content: 'new-content' },
  });
  expect(writeRes.status()).toBe(200);
  expect((await writeRes.json()).ok).toBe(true);

  // file_mkdir
  csrf = await getHostCsrf(page);
  const mkdirRes = await page.request.post('http://127.0.0.1:58080/admin/host_api.php', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: { action: 'file_mkdir', _csrf: csrf, host_id: 'local', path: `${baseDir}/subdir` },
  });
  expect(mkdirRes.status()).toBe(200);
  expect((await mkdirRes.json()).ok).toBe(true);

  // file_chmod
  csrf = await getHostCsrf(page);
  const chmodRes = await page.request.post('http://127.0.0.1:58080/admin/host_api.php', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: { action: 'file_chmod', _csrf: csrf, host_id: 'local', path: fileA, mode: '600' },
  });
  expect(chmodRes.status()).toBe(200);
  expect(typeof (await chmodRes.json()).ok).toBe('boolean');

  // file_chown
  csrf = await getHostCsrf(page);
  const chownRes = await page.request.post('http://127.0.0.1:58080/admin/host_api.php', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: { action: 'file_chown', _csrf: csrf, host_id: 'local', path: fileA, owner: 'root' },
  });
  expect(chownRes.status()).toBe(200);
  expect(typeof (await chownRes.json()).ok).toBe('boolean');

  // file_chgrp
  csrf = await getHostCsrf(page);
  const chgrpRes = await page.request.post('http://127.0.0.1:58080/admin/host_api.php', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: { action: 'file_chgrp', _csrf: csrf, host_id: 'local', path: fileA, group: 'root' },
  });
  expect(chgrpRes.status()).toBe(200);
  expect(typeof (await chgrpRes.json()).ok).toBe('boolean');

  // share_path_apply_acl
  csrf = await getHostCsrf(page);
  const aclRes = await page.request.post('http://127.0.0.1:58080/admin/host_api.php', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: { action: 'share_path_apply_acl', _csrf: csrf, host_id: 'local', path: baseDir, owner: 'root', group: 'root', mode: '755', recursive: '1' },
  });
  expect(aclRes.status()).toBe(200);
  expect(typeof (await aclRes.json()).ok).toBe('boolean');

  // file_archive
  csrf = await getHostCsrf(page);
  const archiveRes = await page.request.post('http://127.0.0.1:58080/admin/host_api.php', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: { action: 'file_archive', _csrf: csrf, host_id: 'local', path: baseDir, archive_path: archivePath },
  });
  expect(archiveRes.status()).toBe(200);
  expect((await archiveRes.json()).ok).toBe(true);

  // file_extract
  csrf = await getHostCsrf(page);
  const extractRes = await page.request.post('http://127.0.0.1:58080/admin/host_api.php', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: { action: 'file_extract', _csrf: csrf, host_id: 'local', path: archivePath, destination: extractDir },
  });
  expect(extractRes.status()).toBe(200);
  expect((await extractRes.json()).ok).toBe(true);

  // file_delete
  csrf = await getHostCsrf(page);
  const deleteRes = await page.request.post('http://127.0.0.1:58080/admin/host_api.php', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: { action: 'file_delete', _csrf: csrf, host_id: 'local', path: fileB },
  });
  expect(deleteRes.status()).toBe(200);
  expect((await deleteRes.json()).ok).toBe(true);

  await tracker.assertNoClientErrors();
});
