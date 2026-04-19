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

test('host api terminal actions manage sessions', async ({ page }) => {
  test.setTimeout(120000);
  await ensureInstalledHostAgent();
  const tracker = await attachClientErrorTracking(page);

  const ts = Date.now();

  await loginAsDevAdmin(page);

  // terminal_open
  let csrf = await getHostCsrf(page);
  const openRes = await page.request.post('http://127.0.0.1:58080/admin/host_api.php', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: { action: 'terminal_open', _csrf: csrf, host_id: 'local', cols: '80', rows: '24', persist: '1' },
  });
  expect(openRes.status()).toBe(200);
  const openBody = await openRes.json();
  expect(openBody.ok).toBe(true);
  const sessionId = openBody.data?.id ?? openBody.id;
  expect(sessionId).toBeTruthy();

  // terminal_list
  const listRes = await page.request.get(
    'http://127.0.0.1:58080/admin/host_api.php?action=terminal_list',
    { headers: { 'X-Requested-With': 'XMLHttpRequest' } }
  );
  expect(listRes.status()).toBe(200);
  const listBody = await listRes.json();
  expect(listBody.ok).toBe(true);
  expect(Array.isArray(listBody.sessions)).toBe(true);
  expect(listBody.sessions.some((s: any) => (s.id || '') === sessionId)).toBe(true);

  // terminal_write
  csrf = await getHostCsrf(page);
  const writeRes = await page.request.post('http://127.0.0.1:58080/admin/host_api.php', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: { action: 'terminal_write', _csrf: csrf, id: sessionId, data: `echo terminal-api-${ts}\n` },
  });
  expect(writeRes.status()).toBe(200);
  expect((await writeRes.json()).ok).toBe(true);

  // terminal_read
  const readRes = await page.request.get(
    `http://127.0.0.1:58080/admin/host_api.php?action=terminal_read&id=${encodeURIComponent(sessionId)}`,
    { headers: { 'X-Requested-With': 'XMLHttpRequest' } }
  );
  expect(readRes.status()).toBe(200);
  const readBody = await readRes.json();
  expect(readBody.ok).toBe(true);
  expect(typeof readBody.output).toBe('string');

  // terminal_close
  csrf = await getHostCsrf(page);
  const closeRes = await page.request.post('http://127.0.0.1:58080/admin/host_api.php', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: { action: 'terminal_close', _csrf: csrf, id: sessionId },
  });
  expect(closeRes.status()).toBe(200);
  expect((await closeRes.json()).ok).toBe(true);

  await tracker.assertNoClientErrors();
});
