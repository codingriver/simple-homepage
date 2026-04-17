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
  await page.goto('/admin/manifests.php');
  return page.evaluate(() => (window as any).HOST_CSRF || '');
}

test.beforeEach(async () => {
  await cleanupHostAgent();
});

test.afterEach(async () => {
  await cleanupHostAgent();
});

test('manifest dry-run returns changes but does not modify system state', async ({ page }) => {
  test.setTimeout(120000);
  await ensureInstalledHostAgent();
  const tracker = await attachClientErrorTracking(page);
  await loginAsDevAdmin(page);

  // 1. 获取当前包管理器信息
  const managerRes = await page.request.get(
    'http://127.0.0.1:58080/admin/host_api.php?action=package_manager',
    { headers: { 'X-Requested-With': 'XMLHttpRequest' } }
  );
  const managerBody = await managerRes.json();
  const manager = managerBody.manager ?? 'unknown';

  // 2. 查询 htop 是否已安装（选择一个不太可能已安装的包）
  const csrf = await getHostCsrf(page);
  const infoRes = await page.request.post('http://127.0.0.1:58080/admin/host_api.php', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: { action: 'package_info', _csrf: csrf, pkg: 'htop' },
  });
  const infoBody = await infoRes.json();
  const wasInstalled = infoBody.ok && infoBody.installed;

  // 3. 构造 manifest：如果 htop 已安装则要求卸载，未安装则要求安装
  // 这样 dry-run 一定会报告有变更
  const desiredState = wasInstalled ? 'absent' : 'installed';
  const manifest = JSON.stringify({
    packages: { htop: { state: desiredState } },
    services: {},
    configs: {},
  });

  const csrf2 = await getHostCsrf(page);
  const dryRunRes = await page.request.post('http://127.0.0.1:58080/admin/host_api.php', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: { action: 'manifest_dry_run', _csrf: csrf2, manifest_json: manifest },
  });
  expect(dryRunRes.status()).toBe(200);
  const dryRunBody = await dryRunRes.json();
  expect(dryRunBody.ok).toBe(true);
  expect(dryRunBody.dry_run).toBe(true);
  expect(Array.isArray(dryRunBody.changes)).toBe(true);
  // dry-run 应该报告有变更
  expect(dryRunBody.changes.length).toBeGreaterThan(0);
  // 所有变更都标记为 dry_run
  for (const change of dryRunBody.changes) {
    expect(change.dry_run).toBe(true);
  }

  // 4. 再次查询 htop 状态，确认 dry-run 没有实际副作用
  const csrf3 = await getHostCsrf(page);
  const afterRes = await page.request.post('http://127.0.0.1:58080/admin/host_api.php', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: { action: 'package_info', _csrf: csrf3, pkg: 'htop' },
  });
  const afterBody = await afterRes.json();
  const stillInstalled = afterBody.ok && afterBody.installed;
  expect(stillInstalled).toBe(wasInstalled);

  await tracker.assertNoClientErrors();
});

test('manifest dry-run with valid manifest reports no changes when state already aligned', async ({ page }) => {
  test.setTimeout(120000);
  await ensureInstalledHostAgent();
  const tracker = await attachClientErrorTracking(page);
  await loginAsDevAdmin(page);

  // 构造一个不包含任何操作的 manifest（空 manifest）
  const manifest = JSON.stringify({
    packages: {},
    services: {},
    configs: {},
  });

  const csrf = await getHostCsrf(page);
  const dryRunRes = await page.request.post('http://127.0.0.1:58080/admin/host_api.php', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: { action: 'manifest_dry_run', _csrf: csrf, manifest_json: manifest },
  });
  expect(dryRunRes.status()).toBe(200);
  const dryRunBody = await dryRunRes.json();
  expect(dryRunBody.ok).toBe(true);
  expect(dryRunBody.dry_run).toBe(true);
  expect(dryRunBody.changes.length).toBe(0);

  await tracker.assertNoClientErrors();
});
