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

test('host api batch actions execute across selected hosts', async ({ page }) => {
  test.setTimeout(120000);
  await ensureInstalledHostAgent();
  const tracker = await attachClientErrorTracking(page);

  const ts = Date.now();
  const hostA = `batch-host-a-${ts}`;
  const hostB = `batch-host-b-${ts}`;
  const keyName = `batch-key-${ts}`;

  // seed a key and hosts via direct JSON write to avoid Docker Desktop bind mount sync issues
  const hostIdA = 'h_' + 'a'.repeat(16);
  const hostIdB = 'h_' + 'b'.repeat(16);
  const keyId = 'k_' + 'c'.repeat(16);
  const now = new Date().toISOString().replace('T', ' ').slice(0, 19);

  const sshKeysPayload = JSON.stringify({
    version: 1,
    keys: [
      {
        id: keyId,
        name: keyName,
        username: 'root',
        private_key_enc: '',
        passphrase_enc: '',
        created_at: now,
        updated_at: now,
      },
    ],
  });

  const sshHostsPayload = JSON.stringify({
    version: 1,
    hosts: [
      {
        id: hostIdA,
        name: hostA,
        hostname: '127.0.0.1',
        port: 1,
        username: 'root',
        auth_type: 'password',
        key_id: '',
        password_enc: '',
        group_name: '',
        tags: [],
        favorite: false,
        notes: '',
        created_at: now,
        updated_at: now,
      },
      {
        id: hostIdB,
        name: hostB,
        hostname: '127.0.0.1',
        port: 2,
        username: 'root',
        auth_type: 'password',
        key_id: '',
        password_enc: '',
        group_name: '',
        tags: [],
        favorite: false,
        notes: '',
        created_at: now,
        updated_at: now,
      },
    ],
  });

  const writeKeys = runDockerPhpInline(`file_put_contents("/var/www/nav/data/ssh_keys.json", ${JSON.stringify(sshKeysPayload)}, LOCK_EX);`);
  expect(writeKeys.code).toBe(0);
  const writeHosts = runDockerPhpInline(`file_put_contents("/var/www/nav/data/ssh_hosts.json", ${JSON.stringify(sshHostsPayload)}, LOCK_EX);`);
  expect(writeHosts.code).toBe(0);

  const hostIds = [hostIdA, hostIdB];

  await loginAsDevAdmin(page);
  const csrf = await getHostCsrf(page);

  // batch_test_hosts
  const testRes = await page.request.post('http://127.0.0.1:58080/admin/host_api.php', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: { action: 'batch_test_hosts', _csrf: csrf, host_ids: hostIds.join(',') },
  });
  expect(testRes.status()).toBe(200);
  const testBody = await testRes.json();
  expect(testBody.ok).toBe(true);
  expect(Array.isArray(testBody.results)).toBe(true);
  expect(testBody.results.length).toBeGreaterThanOrEqual(1);

  // batch_exec_hosts
  const execRes = await page.request.post('http://127.0.0.1:58080/admin/host_api.php', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: { action: 'batch_exec_hosts', _csrf: csrf, host_ids: hostIds.join(','), command: 'echo batch-exec' },
  });
  expect(execRes.status()).toBe(200);
  const execBody = await execRes.json();
  expect(execBody.ok).toBe(true);
  expect(Array.isArray(execBody.results)).toBe(true);

  // batch_distribute_key
  const distRes = await page.request.post('http://127.0.0.1:58080/admin/host_api.php', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: { action: 'batch_distribute_key', _csrf: csrf, host_ids: hostIds.join(','), key_id: keyId, user: 'root' },
  });
  expect(distRes.status()).toBe(200);
  const distBody = await distRes.json();
  expect(distBody.ok).toBe(true);
  expect(Array.isArray(distBody.results)).toBe(true);

  await tracker.assertNoClientErrors();
});
