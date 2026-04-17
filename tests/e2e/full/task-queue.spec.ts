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

test('task submit and status API returns expected structure', async ({ page }) => {
  test.setTimeout(120000);
  await ensureInstalledHostAgent();
  const tracker = await attachClientErrorTracking(page);
  await loginAsDevAdmin(page);

  const csrf = await getHostCsrf(page);
  const manifest = JSON.stringify({
    packages: { htop: { state: 'installed' } },
    services: {},
    configs: {},
  });

  // 1. 提交异步任务
  const submitRes = await page.request.post('http://127.0.0.1:58080/admin/host_api.php', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: { action: 'task_submit', _csrf: csrf, task_action: 'manifest_dry_run', task_payload: JSON.stringify({ manifest: JSON.parse(manifest) }) },
  });
  expect(submitRes.status()).toBe(200);
  const submitBody = await submitRes.json();
  expect(submitBody.ok).toBe(true);
  expect(typeof submitBody.task_id).toBe('string');
  expect(submitBody.status).toMatch(/pending|running|completed/);

  const taskId = submitBody.task_id;

  // 2. 查询任务状态
  const statusRes = await page.request.get(
    `http://127.0.0.1:58080/admin/host_api.php?action=task_status&task_id=${encodeURIComponent(taskId)}`,
    { headers: { 'X-Requested-With': 'XMLHttpRequest' } }
  );
  expect(statusRes.status()).toBe(200);
  const statusBody = await statusRes.json();
  expect(statusBody.ok).toBe(true);
  expect(statusBody.task_id).toBe(taskId);
  expect(typeof statusBody.status).toBe('string');

  // 3. 列出任务
  const listRes = await page.request.get(
    'http://127.0.0.1:58080/admin/host_api.php?action=task_list',
    { headers: { 'X-Requested-With': 'XMLHttpRequest' } }
  );
  expect(listRes.status()).toBe(200);
  const listBody = await listRes.json();
  expect(listBody.ok).toBe(true);
  expect(Array.isArray(listBody.tasks)).toBe(true);

  await tracker.assertNoClientErrors();
});

test('task submit with unknown action returns error', async ({ page }) => {
  test.setTimeout(120000);
  await ensureInstalledHostAgent();
  const tracker = await attachClientErrorTracking(page);
  await loginAsDevAdmin(page);

  const csrf = await getHostCsrf(page);
  const submitRes = await page.request.post('http://127.0.0.1:58080/admin/host_api.php', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: { action: 'task_submit', _csrf: csrf, task_action: 'unknown_action', task_payload: '{}' },
  });
  expect(submitRes.status()).toBe(200);
  const submitBody = await submitRes.json();
  expect(submitBody.ok).toBe(true);
  // 同步降级模式下，execute_action 会返回错误
  expect(submitBody.status).toBe('completed');
  expect(submitBody.result.ok).toBe(false);

  await tracker.assertNoClientErrors();
});

test('task status for non-existent task returns error', async ({ page }) => {
  test.setTimeout(120000);
  await ensureInstalledHostAgent();
  await loginAsDevAdmin(page);

  const statusRes = await page.request.get(
    'http://127.0.0.1:58080/admin/host_api.php?action=task_status&task_id=task_nonexistent123',
    { headers: { 'X-Requested-With': 'XMLHttpRequest' } }
  );
  expect(statusRes.status()).toBe(200);
  const statusBody = await statusRes.json();
  expect(statusBody.ok).toBe(false);
});
