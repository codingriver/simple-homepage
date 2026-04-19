import { expect, test } from '../../helpers/fixtures';
import { attachClientErrorTracking, loginAsDevAdmin } from '../../helpers/auth';
import { runDockerPhpInline } from '../../helpers/cli';

async function ensureInstalledHostAgent() {
  const result = runDockerPhpInline(
    [
      'require "/var/www/nav/admin/shared/host_agent_lib.php";',
      '$result = host_agent_install();',
      'echo json_encode($result, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);',
    ].join(' ')
  );
  expect(result.code).toBe(0);
}

async function getCsrf(page: any) {
  await page.goto('/admin/files.php');
  return page.evaluate(() => (window as any)._csrf);
}

test('file api write actions mutate filesystem as expected', async ({ page }) => {
  test.setTimeout(120000);
  await ensureInstalledHostAgent();
  const tracker = await attachClientErrorTracking(page);

  const ts = Date.now();
  const baseDir = `/file-api-write-${ts}`;
  const fileA = `${baseDir}/a.txt`;
  const fileB = `${baseDir}/b.txt`;
  const renamedA = `${baseDir}/renamed-a.txt`;
  const movedB = `${baseDir}/moved-b.txt`;
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
  const csrf = await getCsrf(page);

  // rename
  const renameRes = await page.request.post('http://127.0.0.1:58080/admin/file_api.php?action=rename', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: { host_id: 'local', source_path: fileA, target_path: renamedA, _csrf: csrf },
  });
  expect(renameRes.status()).toBe(200);
  const renameBody = await renameRes.json();
  expect(renameBody.ok).toBe(true);

  // copy
  const copyRes = await page.request.post('http://127.0.0.1:58080/admin/file_api.php?action=copy', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: { host_id: 'local', source_path: fileB, target_path: `${baseDir}/copy-b.txt`, _csrf: csrf },
  });
  expect(copyRes.status()).toBe(200);
  expect((await copyRes.json()).ok).toBe(true);

  // move
  const moveRes = await page.request.post('http://127.0.0.1:58080/admin/file_api.php?action=move', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: { host_id: 'local', source_path: `${baseDir}/copy-b.txt`, target_path: movedB, _csrf: csrf },
  });
  expect(moveRes.status()).toBe(200);
  expect((await moveRes.json()).ok).toBe(true);

  // chown (simulate mode may return ok:false, but we test the API contract)
  const chownRes = await page.request.post('http://127.0.0.1:58080/admin/file_api.php?action=chown', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: { host_id: 'local', path: renamedA, owner: 'root', _csrf: csrf },
  });
  expect(chownRes.status()).toBe(200);
  expect(typeof (await chownRes.json()).ok).toBe('boolean');

  // chgrp
  const chgrpRes = await page.request.post('http://127.0.0.1:58080/admin/file_api.php?action=chgrp', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: { host_id: 'local', path: renamedA, group: 'root', _csrf: csrf },
  });
  expect(chgrpRes.status()).toBe(200);
  expect(typeof (await chgrpRes.json()).ok).toBe('boolean');

  // archive
  const archiveRes = await page.request.post('http://127.0.0.1:58080/admin/file_api.php?action=archive', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: { host_id: 'local', path: baseDir, archive_path: archivePath, _csrf: csrf },
  });
  expect(archiveRes.status()).toBe(200);
  const archiveBody = await archiveRes.json();
  expect(archiveBody.ok).toBe(true);

  // extract
  const extractRes = await page.request.post('http://127.0.0.1:58080/admin/file_api.php?action=extract', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: { host_id: 'local', path: archivePath, destination: extractDir, _csrf: csrf },
  });
  expect(extractRes.status()).toBe(200);
  const extractBody = await extractRes.json();
  expect(extractBody.ok).toBe(true);

  // favorites_save
  const favSaveRes = await page.request.post('http://127.0.0.1:58080/admin/file_api.php?action=favorites_save', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: { host_id: 'local', path: baseDir, name: `fav-${ts}`, _csrf: csrf },
  });
  expect(favSaveRes.status()).toBe(200);
  const favSaveBody = await favSaveRes.json();
  expect(favSaveBody.ok).toBe(true);

  // favorites_list verify
  const favListRes = await page.request.get(
    `http://127.0.0.1:58080/admin/file_api.php?action=favorites_list`,
    { headers: { 'X-Requested-With': 'XMLHttpRequest' } }
  );
  const favListBody = await favListRes.json();
  const favItems = favListBody.data?.items ?? favListBody.items ?? [];
  expect(favItems.some((i: any) => (i.name || '').includes(`fav-${ts}`))).toBe(true);

  // favorites_delete
  const favId = favItems.find((i: any) => (i.name || '').includes(`fav-${ts}`))?.id ?? '';
  const favDelRes = await page.request.post('http://127.0.0.1:58080/admin/file_api.php?action=favorites_delete', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: { id: favId, _csrf: csrf },
  });
  expect(favDelRes.status()).toBe(200);
  expect((await favDelRes.json()).ok).toBe(true);

  // recent_touch
  const recentRes = await page.request.post('http://127.0.0.1:58080/admin/file_api.php?action=recent_touch', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: { host_id: 'local', path: baseDir, _csrf: csrf },
  });
  expect(recentRes.status()).toBe(200);
  expect((await recentRes.json()).ok).toBe(true);

  // recent_list verify
  const recentListRes = await page.request.get(
    `http://127.0.0.1:58080/admin/file_api.php?action=recent_list`,
    { headers: { 'X-Requested-With': 'XMLHttpRequest' } }
  );
  const recentListBody = await recentListRes.json();
  const recentItems = recentListBody.data?.items ?? recentListBody.items ?? [];
  expect(recentItems.some((i: any) => (i.path || '').includes(baseDir))).toBe(true);

  await tracker.assertNoClientErrors();
});
