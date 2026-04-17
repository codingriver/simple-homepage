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

test('config apply with invalid nginx config fails validation and auto-rollback', async ({ page }) => {
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

  // 2. 构造一个明显非法的 nginx 配置（语法错误）
  const invalidContent = 'this is definitely not valid nginx config { { { broken';
  csrf = await getHostCsrf(page);
  const applyRes = await page.request.post('http://127.0.0.1:58080/admin/host_api.php', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: { action: 'config_apply', _csrf: csrf, config_id: 'nginx', content: invalidContent },
  });
  expect(applyRes.status()).toBe(200);
  const applyBody = await applyRes.json();

  // 3. 校验应该失败并自动回滚
  expect(applyBody.ok).toBe(false);
  expect(applyBody.msg).toContain('回滚');

  // 4. 再次读取配置，确认内容恢复到原始状态
  csrf = await getHostCsrf(page);
  const readRes2 = await page.request.post('http://127.0.0.1:58080/admin/host_api.php', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: { action: 'config_read', _csrf: csrf, config_id: 'nginx' },
  });
  const readBody2 = await readRes2.json();
  if (readBody2.ok) {
    expect(readBody2.content).toBe(originalContent);
  }

  await tracker.assertNoClientErrors();
});

test('config validate-only mode does not persist changes', async ({ page }) => {
  test.setTimeout(120000);
  await ensureInstalledHostAgent();
  const tracker = await attachClientErrorTracking(page);
  await loginAsDevAdmin(page);

  // 1. 读取当前 nginx 配置
  let csrf = await getHostCsrf(page);
  const readRes = await page.request.post('http://127.0.0.1:58080/admin/host_api.php', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: { action: 'config_read', _csrf: csrf, config_id: 'nginx' },
  });
  const readBody = await readRes.json();
  const originalContent = readBody.ok ? readBody.content : '';

  // 2. 使用 validate_only=1 应用一个合法但不同的配置
  const marker = `# validate-only-test-${Date.now()}`;
  const modifiedContent = originalContent + '\n' + marker + '\n';
  csrf = await getHostCsrf(page);
  const applyRes = await page.request.post('http://127.0.0.1:58080/admin/host_api.php', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: { action: 'config_apply', _csrf: csrf, config_id: 'nginx', content: modifiedContent, validate_only: '1' },
  });
  expect(applyRes.status()).toBe(200);
  const applyBody = await applyRes.json();
  // validate_only 模式下：写入→校验→回滚，所以最终文件应保持不变
  // 但如果 nginx -t 不可用，可能返回 ok=false
  // 如果可用且配置合法，应返回 ok=true "配置校验通过"

  // 3. 再次读取，确认文件内容没有变化（validate_only 应回滚）
  csrf = await getHostCsrf(page);
  const readRes2 = await page.request.post('http://127.0.0.1:58080/admin/host_api.php', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: { action: 'config_read', _csrf: csrf, config_id: 'nginx' },
  });
  const readBody2 = await readRes2.json();
  if (readBody2.ok) {
    expect(readBody2.content).toBe(originalContent);
    expect(readBody2.content).not.toContain(marker);
  }

  await tracker.assertNoClientErrors();
});
