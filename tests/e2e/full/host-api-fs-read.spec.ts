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

test.beforeEach(async () => {
  await cleanupHostAgent();
});

test.afterEach(async () => {
  await cleanupHostAgent();
});

test('host api fs read actions return expected payloads', async ({ page }) => {
  test.setTimeout(120000);
  await ensureInstalledHostAgent();
  const tracker = await attachClientErrorTracking(page);

  const ts = Date.now();
  const baseDir = `/host-api-fs-read-${ts}`;
  const filePath = `${baseDir}/sample.txt`;

  const seed = runDockerPhpInline(
    [
      '$dir = "/var/www/nav/data/host-agent-sim-root' + baseDir + '";',
      'if (!is_dir($dir)) { mkdir($dir, 0777, true); }',
      'file_put_contents($dir . "/sample.txt", "host api fs read");',
    ].join(' ')
  );
  expect(seed.code).toBe(0);

  await loginAsDevAdmin(page);

  // file_list
  const listRes = await page.request.get(
    `http://127.0.0.1:58080/admin/host_api.php?action=file_list&host_id=local&path=${encodeURIComponent(baseDir)}`,
    { headers: { 'X-Requested-With': 'XMLHttpRequest' } }
  );
  expect(listRes.status()).toBe(200);
  const listBody = await listRes.json();
  expect(listBody.ok).toBe(true);
  expect(Array.isArray(listBody.data?.items ?? listBody.items)).toBe(true);
  const items = (listBody.data?.items ?? listBody.items) as any[];
  expect(items.some((i) => (i.name || '').includes('sample.txt'))).toBe(true);

  // file_read
  const readRes = await page.request.post('http://127.0.0.1:58080/admin/host_api.php', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: { action: 'file_read', host_id: 'local', path: filePath },
  });
  expect(readRes.status()).toBe(200);
  const readBody = await readRes.json();
  expect(readBody.ok).toBe(true);
  expect(readBody.data?.content ?? readBody.content).toContain('host api fs read');

  // file_stat
  const statRes = await page.request.get(
    `http://127.0.0.1:58080/admin/host_api.php?action=file_stat&host_id=local&path=${encodeURIComponent(filePath)}`,
    { headers: { 'X-Requested-With': 'XMLHttpRequest' } }
  );
  expect(statRes.status()).toBe(200);
  const statBody = await statRes.json();
  expect(statBody.ok).toBe(true);
  expect(statBody.path).toContain('sample.txt');

  // share_path_stat
  const shareStatRes = await page.request.get(
    `http://127.0.0.1:58080/admin/host_api.php?action=share_path_stat&host_id=local&path=${encodeURIComponent(filePath)}`,
    { headers: { 'X-Requested-With': 'XMLHttpRequest' } }
  );
  expect(shareStatRes.status()).toBe(200);
  const shareStatBody = await shareStatRes.json();
  expect(shareStatBody.ok).toBe(true);
  expect(shareStatBody.path).toContain('sample.txt');

  await tracker.assertNoClientErrors();
});
