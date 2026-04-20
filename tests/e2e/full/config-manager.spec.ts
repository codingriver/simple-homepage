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
  let lastError = '';
  for (let attempt = 1; attempt <= 3; attempt++) {
    const result = runDockerPhpInline(
      [
        'require "/var/www/nav/admin/shared/host_agent_lib.php";',
        '$result = host_agent_install();',
        'echo json_encode($result, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);',
      ].join(' ')
    );
    if (result.code === 0) {
      try {
        const payload = JSON.parse(result.stdout);
        if (payload.ok === true) {
          // 安装成功后短暂等待，让容器状态稳定
          await new Promise(r => setTimeout(r, 1000));
          return;
        }
        lastError = JSON.stringify(payload);
      } catch {
        lastError = 'JSON parse error: stdout=' + result.stdout + ', stderr=' + result.stderr;
      }
    } else {
      lastError = 'exit code ' + result.code + ': ' + result.output;
    }
    if (attempt < 3) {
      await new Promise(r => setTimeout(r, 2000));
    }
  }
  throw new Error('ensureInstalledHostAgent failed after 3 attempts: ' + lastError);
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

test('configs page loads and shows config definitions', async ({ page }) => {
  test.setTimeout(120000);
  await ensureInstalledHostAgent();
  const tracker = await attachClientErrorTracking(page);

  await loginAsDevAdmin(page);
  await page.goto('/admin/configs.php');

  // 页面标题
  await expect(page).toHaveTitle(/配置管理/);

  // 页面主体内容存在
  await expect(page.locator('.card-title').first()).toContainText('配置管理');

  // 配置卡片或提示信息至少有一个
  const hasCards = await page.locator('.config-card').count() > 0;
  const hasWarning = await page.locator('.alert-warning').count() > 0;
  expect(hasCards || hasWarning).toBe(true);

  await tracker.assertNoClientErrors();
});

test('config definitions API returns expected structure', async ({ page }) => {
  test.setTimeout(120000);
  await ensureInstalledHostAgent();
  await loginAsDevAdmin(page);

  const res = await page.request.get(
    'http://127.0.0.1:58080/admin/host_api.php?action=config_definitions',
    { headers: { 'X-Requested-With': 'XMLHttpRequest' } }
  );
  expect(res.status()).toBe(200);
  const body = await res.json();
  expect(body.ok).toBe(true);
  expect(Array.isArray(body.definitions)).toBe(true);
  expect(body.definitions.length).toBeGreaterThan(0);

  const def = body.definitions[0];
  expect(typeof def.id).toBe('string');
  expect(typeof def.label).toBe('string');
  expect(typeof def.icon).toBe('string');
  expect(typeof def.format).toBe('string');
  expect(Array.isArray(def.sections)).toBe(true);
});

test('config read API returns expected structure', async ({ page }) => {
  test.setTimeout(120000);
  await ensureInstalledHostAgent();
  await loginAsDevAdmin(page);

  const csrf = await getHostCsrf(page);
  const res = await page.request.post('http://127.0.0.1:58080/admin/host_api.php', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: { action: 'config_read', _csrf: csrf, config_id: 'nginx' },
  });
  expect(res.status()).toBe(200);
  const body = await res.json();
  expect(typeof body.ok).toBe('boolean');
  if (body.ok) {
    expect(typeof body.config_id).toBe('string');
    expect(typeof body.path).toBe('string');
    expect(typeof body.content).toBe('string');
    expect(typeof body.exists).toBe('boolean');
    expect(typeof body.format).toBe('string');
  }
});

test('config validate API returns structure for nginx config', async ({ page }) => {
  test.setTimeout(120000);
  await ensureInstalledHostAgent();
  await loginAsDevAdmin(page);

  const csrf = await getHostCsrf(page);

  // 先读取现有配置
  const readRes = await page.request.post('http://127.0.0.1:58080/admin/host_api.php', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: { action: 'config_read', _csrf: csrf, config_id: 'nginx' },
  });
  const readBody = await readRes.json();
  const content = readBody.ok ? (readBody.content || '') : 'worker_processes auto;\nevents { worker_connections 1024; }\nhttp { server { listen 80; } }';

  const csrf2 = await getHostCsrf(page);
  const res = await page.request.post('http://127.0.0.1:58080/admin/host_api.php', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: { action: 'config_validate', _csrf: csrf2, config_id: 'nginx', content },
  });
  expect(res.status()).toBe(200);
  const body = await res.json();
  expect(typeof body.ok).toBe('boolean');
  expect(typeof body.msg).toBe('string');
});

test('config history API returns expected structure', async ({ page }) => {
  test.setTimeout(120000);
  await ensureInstalledHostAgent();
  await loginAsDevAdmin(page);

  const csrf = await getHostCsrf(page);
  const res = await page.request.post('http://127.0.0.1:58080/admin/host_api.php', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: { action: 'config_history', _csrf: csrf, config_id: 'nginx', limit: '10' },
  });
  expect(res.status()).toBe(200);
  const body = await res.json();
  expect(typeof body.ok).toBe('boolean');
  if (body.ok) {
    expect(Array.isArray(body.backups)).toBe(true);
  }
});

test('navigation menu shows configs link', async ({ page }) => {
  test.setTimeout(60000);
  await loginAsDevAdmin(page);
  await page.goto('/admin/index.php');

  const configsLink = page.locator('a[href="configs.php"]');
  await expect(configsLink).toBeVisible();
  await expect(configsLink).toContainText('配置管理');
});
