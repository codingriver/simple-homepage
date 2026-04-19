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

test('file api read actions return expected payloads', async ({ page }) => {
  test.setTimeout(120000);
  await ensureInstalledHostAgent();
  const tracker = await attachClientErrorTracking(page);

  const ts = Date.now();
  const baseDir = `/file-api-read-${ts}`;
  const filePath = `${baseDir}/searchable.txt`;

  // seed files
  const seed = runDockerPhpInline(
    [
      '$dir = "/var/www/nav/data/host-agent-sim-root' + baseDir + '";',
      'if (!is_dir($dir)) { mkdir($dir, 0777, true); }',
      'file_put_contents($dir . "/searchable.txt", "hello file api read actions");',
      'file_put_contents($dir . "/nested.txt", "nested content");',
    ].join(' ')
  );
  expect(seed.code).toBe(0);

  await loginAsDevAdmin(page);

  // read
  const readRes = await page.request.post('http://127.0.0.1:58080/admin/file_api.php?action=read', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: { host_id: 'local', path: filePath },
  });
  expect(readRes.status()).toBe(200);
  const readBody = await readRes.json();
  expect(readBody.ok).toBe(true);
  expect(readBody.data?.content ?? readBody.content).toContain('hello file api read actions');

  // search
  const searchRes = await page.request.get(
    `http://127.0.0.1:58080/admin/file_api.php?action=search&host_id=local&path=${encodeURIComponent(baseDir)}&keyword=searchable&limit=50`,
    { headers: { 'X-Requested-With': 'XMLHttpRequest' } }
  );
  expect(searchRes.status()).toBe(200);
  const searchBody = await searchRes.json();
  expect(searchBody.ok).toBe(true);
  expect(Array.isArray(searchBody.data?.items ?? searchBody.items)).toBe(true);
  const hits = (searchBody.data?.items ?? searchBody.items) as any[];
  expect(hits.some((h) => (h.path || '').includes('searchable.txt'))).toBe(true);

  // favorites_list (empty at first for this user)
  const favRes = await page.request.get(
    `http://127.0.0.1:58080/admin/file_api.php?action=favorites_list`,
    { headers: { 'X-Requested-With': 'XMLHttpRequest' } }
  );
  expect(favRes.status()).toBe(200);
  const favBody = await favRes.json();
  expect(favBody.ok).toBe(true);
  expect(Array.isArray(favBody.data?.items ?? favBody.items)).toBe(true);

  // recent_list
  const recentRes = await page.request.get(
    `http://127.0.0.1:58080/admin/file_api.php?action=recent_list`,
    { headers: { 'X-Requested-With': 'XMLHttpRequest' } }
  );
  expect(recentRes.status()).toBe(200);
  const recentBody = await recentRes.json();
  expect(recentBody.ok).toBe(true);
  expect(Array.isArray(recentBody.data?.items ?? recentBody.items)).toBe(true);

  // webdav_shares_for_path
  const webdavRes = await page.request.get(
    `http://127.0.0.1:58080/admin/file_api.php?action=webdav_shares_for_path&host_id=local&path=${encodeURIComponent(baseDir)}`,
    { headers: { 'X-Requested-With': 'XMLHttpRequest' } }
  );
  expect(webdavRes.status()).toBe(200);
  const webdavBody = await webdavRes.json();
  expect(webdavBody.ok).toBe(true);
  expect(Array.isArray(webdavBody.data?.items ?? webdavBody.items)).toBe(true);

  await tracker.assertNoClientErrors();
});
