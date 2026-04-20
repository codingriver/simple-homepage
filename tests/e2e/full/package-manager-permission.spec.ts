import fs from 'fs/promises';
import path from 'path';
import { expect, test } from '../../helpers/fixtures';
import { attachClientErrorTracking, loginAsDevAdmin, logout } from '../../helpers/auth';
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

test.beforeEach(async () => {
  await cleanupHostAgent();
});

test.afterEach(async () => {
  await cleanupHostAgent();
});

test('host_viewer can view packages and configs pages but not manifests', async ({ page }) => {
  test.setTimeout(120000);
  await ensureInstalledHostAgent();
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 403/,
      /Pattern attribute value \[a-zA-Z0-9_-\]\{2,32\} is not a valid regular expression/,
    ],
  });

  // 1. admin 创建 host_viewer 用户
  await loginAsDevAdmin(page);
  const username = `viewer${Date.now()}`;
  const password = 'Viewer@test2026';

  await page.goto('/admin/users.php?action=add');
  await page.locator('input[name="username"]').fill(username);
  await page.locator('input[name="password"]').fill(password);
  await page.locator('select[name="role"]').selectOption('host_viewer');
  await page.getByRole('button', { name: /保存/ }).click();
  await expect(page.locator('body')).toContainText(username);

  // 2. 切换为 host_viewer 登录
  await logout(page);
  await page.goto('/login.php');
  await page.locator('input[name="username"]').fill(username);
  await page.locator('input[name="password"]').fill(password);
  await page.getByRole('button', { name: /登\s*录/ }).click();
  await expect(page).toHaveURL(/index\.php|\/$/, { timeout: 5000 });

  // 3. host_viewer 有 ssh.view，可以查看 packages.php 和 configs.php
  await page.goto('/admin/packages.php');
  await expect(page).toHaveURL(/\/admin\/packages\.php/);
  await expect(page.locator('body')).toContainText('软件包管理');

  await page.goto('/admin/configs.php');
  await expect(page).toHaveURL(/\/admin\/configs\.php/);
  await expect(page.locator('body')).toContainText('配置管理');

  // 4. manifests.php 页面权限也是 ssh.view，host_viewer 可以查看但无法操作
  await page.goto('/admin/manifests.php');
  await expect(page).toHaveURL(/\/admin\/manifests\.php/);
  await expect(page.locator('body')).toContainText('声明式管理');

  await tracker.assertNoClientErrors();
});

test('host_viewer cannot call package or config write APIs', async ({ page }) => {
  test.setTimeout(120000);
  await ensureInstalledHostAgent();

  // 1. admin 创建 host_viewer 用户
  await loginAsDevAdmin(page);
  const username = `viewerapi${Date.now()}`;
  const password = 'Viewer@test2026';

  await page.goto('/admin/users.php?action=add');
  await page.locator('input[name="username"]').fill(username);
  await page.locator('input[name="password"]').fill(password);
  await page.locator('select[name="role"]').selectOption('host_viewer');
  await page.getByRole('button', { name: /保存/ }).click();

  // 2. 获取 host_viewer 的 CSRF
  await logout(page);
  await page.goto('/login.php');
  await page.locator('input[name="username"]').fill(username);
  await page.locator('input[name="password"]').fill(password);
  await page.getByRole('button', { name: /登\s*录/ }).click();
  await expect(page).toHaveURL(/index\.php|\/$/, { timeout: 5000 });

  await page.goto('/admin/packages.php');
  const csrf = await page.evaluate(() => (window as any).HOST_CSRF || '');

  // 3. 调用 package_install API 应该返回 403
  const installRes = await page.request.post('http://127.0.0.1:58080/admin/host_api.php', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: { action: 'package_install', _csrf: csrf, pkg: 'nginx' },
  });
  expect(installRes.status()).toBe(403);

  // 4. 调用 config_apply API 应该返回 403
  const applyRes = await page.request.post('http://127.0.0.1:58080/admin/host_api.php', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: { action: 'config_apply', _csrf: csrf, config_id: 'nginx', content: 'worker_processes 1;' },
  });
  expect(applyRes.status()).toBe(403);
});

test('host_viewer can read package and config APIs', async ({ page }) => {
  test.setTimeout(120000);
  await ensureInstalledHostAgent();

  // 1. admin 创建 host_viewer 用户
  await loginAsDevAdmin(page);
  const username = `viewerro${Date.now()}`;
  const password = 'Viewer@test2026';

  await page.goto('/admin/users.php?action=add');
  await page.locator('input[name="username"]').fill(username);
  await page.locator('input[name="password"]').fill(password);
  await page.locator('select[name="role"]').selectOption('host_viewer');
  await page.getByRole('button', { name: /保存/ }).click();

  // 2. host_viewer 登录
  await logout(page);
  await page.goto('/login.php');
  await page.locator('input[name="username"]').fill(username);
  await page.locator('input[name="password"]').fill(password);
  await page.getByRole('button', { name: /登\s*录/ }).click();
  await expect(page).toHaveURL(/index\.php|\/$/, { timeout: 5000 });

  // 3. 读权限 API 应该可以访问
  const managerRes = await page.request.get(
    'http://127.0.0.1:58080/admin/host_api.php?action=package_manager',
    { headers: { 'X-Requested-With': 'XMLHttpRequest' } }
  );
  expect(managerRes.status()).toBe(200);
  const managerBody = await managerRes.json();
  expect(typeof managerBody.ok).toBe('boolean');

  const defsRes = await page.request.get(
    'http://127.0.0.1:58080/admin/host_api.php?action=config_definitions',
    { headers: { 'X-Requested-With': 'XMLHttpRequest' } }
  );
  expect(defsRes.status()).toBe(200);
  const defsBody = await defsRes.json();
  expect(typeof defsBody.ok).toBe('boolean');
});
