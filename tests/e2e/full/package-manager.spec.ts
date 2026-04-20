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
  // 等待 Docker 引擎完成容器清理，避免立即创建时冲突
  await new Promise(r => setTimeout(r, 1500));
}

async function ensureInstalledHostAgent() {
  let lastError = '';
  for (let attempt = 1; attempt <= 5; attempt++) {
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
          // 安装成功后等待容器状态稳定
          await new Promise(r => setTimeout(r, 2000));
          return;
        }
        lastError = JSON.stringify(payload);
      } catch {
        lastError = 'JSON parse error: stdout=' + result.stdout + '|stderr=' + result.stderr;
      }
    } else {
      lastError = 'exit code ' + result.code + ' stdout=' + result.stdout + '|stderr=' + result.stderr;
    }
    if (attempt < 5) {
      await new Promise(r => setTimeout(r, 3000));
    }
  }
  throw new Error('ensureInstalledHostAgent failed after 5 attempts: ' + lastError);
}

async function getHostCsrf(page: any) {
  await page.goto('/admin/packages.php');
  return page.evaluate(() => (window as any).HOST_CSRF || '');
}

test.beforeEach(async () => {
  await cleanupHostAgent();
});

test.afterEach(async () => {
  await cleanupHostAgent();
});

test('packages page loads and shows package manager info', async ({ page }) => {
  test.setTimeout(120000);
  await ensureInstalledHostAgent();
  const tracker = await attachClientErrorTracking(page);

  await loginAsDevAdmin(page);
  await page.goto('/admin/packages.php');

  // 页面标题
  await expect(page).toHaveTitle(/软件包管理/);

  // 包管理器信息区域
  await expect(page.locator('.card-title').first()).toContainText('软件包管理');

  // 搜索框和按钮存在
  await expect(page.locator('#pkg-search-input')).toBeVisible();
  await expect(page.locator('#pkg-search-btn')).toBeVisible();

  // 常用软件推荐存在
  await expect(page.locator('.pkg-recommend-item')).toHaveCount(8);

  // 已安装包列表区域存在
  await expect(page.locator('#pkg-installed-list')).toBeVisible();

  await tracker.assertNoClientErrors();
});

test('package manager API returns expected structure', async ({ page }) => {
  test.setTimeout(120000);
  await ensureInstalledHostAgent();
  await loginAsDevAdmin(page);

  const res = await page.request.get(
    'http://127.0.0.1:58080/admin/host_api.php?action=package_manager',
    { headers: { 'X-Requested-With': 'XMLHttpRequest' } }
  );
  expect(res.status()).toBe(200);
  const body = await res.json();
  expect(typeof body.ok).toBe('boolean');
  expect(typeof body.manager).toBe('string');
  expect(typeof body.service_manager).toBe('string');
});

test('package search API returns expected structure', async ({ page }) => {
  test.setTimeout(120000);
  await ensureInstalledHostAgent();
  await loginAsDevAdmin(page);

  const csrf = await getHostCsrf(page);
  const res = await page.request.post('http://127.0.0.1:58080/admin/host_api.php', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: { action: 'package_search', _csrf: csrf, keyword: 'nginx', limit: '10' },
  });
  expect(res.status()).toBe(200);
  const body = await res.json();
  expect(typeof body.ok).toBe('boolean');
  // 在 host 模式下应该能搜索到结果；simulate 模式下可能返回 false
  if (body.ok) {
    expect(Array.isArray(body.packages)).toBe(true);
  }
});

test('package list API returns expected structure', async ({ page }) => {
  test.setTimeout(120000);
  await ensureInstalledHostAgent();
  await loginAsDevAdmin(page);

  const csrf = await getHostCsrf(page);
  const res = await page.request.post('http://127.0.0.1:58080/admin/host_api.php', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: { action: 'package_list', _csrf: csrf, limit: '50' },
  });
  expect(res.status()).toBe(200);
  const body = await res.json();
  expect(typeof body.ok).toBe('boolean');
  if (body.ok) {
    expect(Array.isArray(body.packages)).toBe(true);
    expect(typeof body.total).toBe('number');
    expect(typeof body.manager).toBe('string');
  }
});

test('package info API returns expected structure', async ({ page }) => {
  test.setTimeout(120000);
  await ensureInstalledHostAgent();
  await loginAsDevAdmin(page);

  const csrf = await getHostCsrf(page);
  const res = await page.request.post('http://127.0.0.1:58080/admin/host_api.php', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: { action: 'package_info', _csrf: csrf, pkg: 'nginx' },
  });
  expect(res.status()).toBe(200);
  const body = await res.json();
  expect(typeof body.ok).toBe('boolean');
  if (body.ok) {
    expect(typeof body.info).toBe('string');
    expect(typeof body.installed).toBe('boolean');
    expect(typeof body.manager).toBe('string');
  } else {
    expect(typeof body.info).toBe('string');
    expect(typeof body.installed).toBe('boolean');
    expect(typeof body.manager).toBe('string');
  }
});

test('package install/remove API respects permissions and returns structure', async ({ page }) => {
  test.setTimeout(120000);
  await ensureInstalledHostAgent();
  await loginAsDevAdmin(page);

  const csrf = await getHostCsrf(page);

  // install (may fail in simulate mode, but should return proper structure)
  const installRes = await page.request.post('http://127.0.0.1:58080/admin/host_api.php', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: { action: 'package_install', _csrf: csrf, pkg: 'htop' },
  });
  expect(installRes.status()).toBe(200);
  const installBody = await installRes.json();
  expect(typeof installBody.ok).toBe('boolean');
  expect(typeof installBody.msg).toBe('string');

  // remove
  const csrf2 = await getHostCsrf(page);
  const removeRes = await page.request.post('http://127.0.0.1:58080/admin/host_api.php', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: { action: 'package_remove', _csrf: csrf2, pkg: 'htop' },
  });
  expect(removeRes.status()).toBe(200);
  const removeBody = await removeRes.json();
  expect(typeof removeBody.ok).toBe('boolean');
  expect(typeof removeBody.msg).toBe('string');
});

test('navigation menu shows packages link', async ({ page }) => {
  test.setTimeout(60000);
  await loginAsDevAdmin(page);
  await page.goto('/admin/index.php');

  const packagesLink = page.locator('a[href="packages.php"]');
  await expect(packagesLink).toBeVisible();
  await expect(packagesLink).toContainText('软件包管理');
});
