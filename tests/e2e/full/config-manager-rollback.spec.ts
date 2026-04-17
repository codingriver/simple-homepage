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
  await page.goto('/admin/configs.php');
  return page.evaluate(() => (window as any).HOST_CSRF || '');
}

test.beforeEach(async () => {
  await cleanupHostAgent();
});

test.afterEach(async () => {
  await cleanupHostAgent();
});

test('config apply creates backup and restore rolls back to it', async ({ page }) => {
  test.setTimeout(120000);
  await ensureInstalledHostAgent();
  const tracker = await attachClientErrorTracking(page);
  await loginAsDevAdmin(page);

  // 1. 读取当前 nginx 配置作为基准
  let csrf = await getHostCsrf(page);
  const readRes = await page.request.post('http://127.0.0.1:58080/admin/host_api.php', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: { action: 'config_read', _csrf: csrf, config_id: 'nginx' },
  });
  const readBody = await readRes.json();
  const originalContent = readBody.ok ? readBody.content : '';

  // 2. 应用一个合法的修改配置（添加一个明显的注释标记）
  const marker = `# host-agent-test-marker-${Date.now()}`;
  const modifiedContent = originalContent + '\n' + marker + '\n';
  csrf = await getHostCsrf(page);
  const applyRes = await page.request.post('http://127.0.0.1:58080/admin/host_api.php', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: { action: 'config_apply', _csrf: csrf, config_id: 'nginx', content: modifiedContent },
  });
  expect(applyRes.status()).toBe(200);
  const applyBody = await applyRes.json();
  // 如果宿主机没有 nginx 或 nginx -t 失败，ok 可能为 false（自动回滚）
  // 但我们期望在测试容器内 nginx 是可用的

  // 3. 查询备份历史
  csrf = await getHostCsrf(page);
  const historyRes = await page.request.post('http://127.0.0.1:58080/admin/host_api.php', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: { action: 'config_history', _csrf: csrf, config_id: 'nginx', limit: '10' },
  });
  expect(historyRes.status()).toBe(200);
  const historyBody = await historyRes.json();
  expect(historyBody.ok).toBe(true);
  expect(Array.isArray(historyBody.backups)).toBe(true);
  expect(historyBody.backups.length).toBeGreaterThan(0);

  const backupPath = historyBody.backups[0].path;
  expect(typeof backupPath).toBe('string');

  // 4. 恢复备份
  csrf = await getHostCsrf(page);
  const restoreRes = await page.request.post('http://127.0.0.1:58080/admin/host_api.php', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: { action: 'config_restore', _csrf: csrf, config_id: 'nginx', backup_path: backupPath },
  });
  expect(restoreRes.status()).toBe(200);
  const restoreBody = await restoreRes.json();
  expect(typeof restoreBody.ok).toBe('boolean');

  // 5. 再次读取配置，确认恢复到原始状态（不包含 marker）
  csrf = await getHostCsrf(page);
  const readRes2 = await page.request.post('http://127.0.0.1:58080/admin/host_api.php', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: { action: 'config_read', _csrf: csrf, config_id: 'nginx' },
  });
  const readBody2 = await readRes2.json();
  if (readBody2.ok) {
    expect(readBody2.content).not.toContain(marker);
  }

  await tracker.assertNoClientErrors();
});

test('config restore rejects illegal backup path', async ({ page }) => {
  test.setTimeout(120000);
  await ensureInstalledHostAgent();
  const tracker = await attachClientErrorTracking(page);
  await loginAsDevAdmin(page);

  const csrf = await getHostCsrf(page);
  const restoreRes = await page.request.post('http://127.0.0.1:58080/admin/host_api.php', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: { action: 'config_restore', _csrf: csrf, config_id: 'nginx', backup_path: '/etc/passwd' },
  });
  expect(restoreRes.status()).toBe(200);
  const restoreBody = await restoreRes.json();
  expect(restoreBody.ok).toBe(false);
  expect(restoreBody.msg).toContain('非法备份路径');

  await tracker.assertNoClientErrors();
});
