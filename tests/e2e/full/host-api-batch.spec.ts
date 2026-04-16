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
  await fs.rm(hostAgentStatePath, { force: true }).catch(() => undefined);
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

  // seed a key
  const keySeed = runDockerPhpInline(
    [
      'require "/var/www/nav/admin/shared/ssh_manager_lib.php";',
      '$result = ssh_manager_upsert_key(["name" => "' + keyName + '", "username" => "", "private_key" => "-----BEGIN OPENSSH PRIVATE KEY-----\ntest\n-----END OPENSSH PRIVATE KEY-----", "passphrase" => ""], null);',
      'echo json_encode($result, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);',
    ].join(' ')
  );
  expect(keySeed.code).toBe(0);
  expect(JSON.parse(keySeed.stdout).ok).toBe(true);

  // seed hosts
  const hostSeed = runDockerPhpInline(
    [
      'require "/var/www/nav/admin/shared/ssh_manager_lib.php";',
      '$keys = ssh_manager_list_keys();',
      '$keyId = "";',
      'foreach ($keys as $key) { if (($key["name"] ?? "") === "' + keyName + '") { $keyId = (string)($key["id"] ?? ""); break; } }',
      '$r1 = ssh_manager_upsert_host(["name" => "' + hostA + '", "hostname" => "127.0.0.1", "port" => 1, "username" => "root", "auth_type" => "password", "key_id" => "", "password" => "x", "group_name" => "", "tags" => "", "favorite" => false, "notes" => ""], null);',
      '$r2 = ssh_manager_upsert_host(["name" => "' + hostB + '", "hostname" => "127.0.0.1", "port" => 2, "username" => "root", "auth_type" => "password", "key_id" => "", "password" => "x", "group_name" => "", "tags" => "", "favorite" => false, "notes" => ""], null);',
      'echo json_encode([$r1, $r2], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);',
    ].join(' ')
  );
  expect(hostSeed.code).toBe(0);
  expect(JSON.parse(hostSeed.stdout)[0].ok).toBe(true);

  // get host ids
  const idsResult = runDockerPhpInline(
    [
      'require "/var/www/nav/admin/shared/ssh_manager_lib.php";',
      '$hosts = ssh_manager_list_hosts();',
      '$ids = [];',
      'foreach ($hosts as $host) {',
      '  $name = $host["name"] ?? "";',
      '  if ($name === "' + hostA + '" || $name === "' + hostB + '") {',
      '    $ids[] = $host["id"] ?? "";',
      '  }',
      '}',
      'echo implode(",", $ids);',
    ].join(' ')
  );
  expect(idsResult.code).toBe(0);
  const hostIds = idsResult.stdout.trim().split(',').filter(Boolean);
  expect(hostIds.length).toBe(2);

  await loginAsDevAdmin(page);
  const csrf = await getHostCsrf(page);

  // batch_test_hosts
  const testRes = await page.request.post('http://127.0.0.1:58080/admin/host_api.php', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: { action: 'batch_test_hosts', _csrf: csrf, 'host_ids[]': hostIds.join(',') },
  });
  expect(testRes.status()).toBe(200);
  const testBody = await testRes.json();
  expect(testBody.ok).toBe(true);
  expect(Array.isArray(testBody.data?.results)).toBe(true);
  expect(testBody.data.results.length).toBeGreaterThanOrEqual(1);

  // batch_exec_hosts
  const execRes = await page.request.post('http://127.0.0.1:58080/admin/host_api.php', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: { action: 'batch_exec_hosts', _csrf: csrf, 'host_ids[]': hostIds.join(','), command: 'echo batch-exec' },
  });
  expect(execRes.status()).toBe(200);
  const execBody = await execRes.json();
  expect(execBody.ok).toBe(true);
  expect(Array.isArray(execBody.data?.results)).toBe(true);

  // batch_distribute_key
  const keyQuery = runDockerPhpInline(
    [
      'require "/var/www/nav/admin/shared/ssh_manager_lib.php";',
      '$keys = ssh_manager_list_keys();',
      'foreach ($keys as $key) {',
      '  if (($key["name"] ?? "") === "' + keyName + '") { echo $key["id"] ?? ""; break; }',
      '}',
    ].join(' ')
  );
  expect(keyQuery.code).toBe(0);
  const keyId = keyQuery.stdout.trim();

  const distRes = await page.request.post('http://127.0.0.1:58080/admin/host_api.php', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: { action: 'batch_distribute_key', _csrf: csrf, 'host_ids[]': hostIds.join(','), key_id: keyId, user: 'root' },
  });
  expect(distRes.status()).toBe(200);
  const distBody = await distRes.json();
  expect(distBody.ok).toBe(true);
  expect(Array.isArray(distBody.data?.results)).toBe(true);

  await tracker.assertNoClientErrors();
});
