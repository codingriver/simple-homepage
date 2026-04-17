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

test('manifests page loads and shows editor', async ({ page }) => {
  test.setTimeout(120000);
  await ensureInstalledHostAgent();
  const tracker = await attachClientErrorTracking(page);

  await loginAsDevAdmin(page);
  await page.goto('/admin/manifests.php');

  await expect(page).toHaveTitle(/声明式管理/);
  await expect(page.locator('#manifest-editor')).toBeVisible();
  await expect(page.locator('#manifest-result')).toBeVisible();

  await tracker.assertNoClientErrors();
});

test('manifest validate API accepts valid manifest', async ({ page }) => {
  test.setTimeout(120000);
  await ensureInstalledHostAgent();
  await loginAsDevAdmin(page);

  const csrf = await getHostCsrf(page);
  const manifest = JSON.stringify({
    packages: { nginx: { state: 'installed' } },
    services: { nginx: { state: 'running', enabled: true } },
  });

  const res = await page.request.post('http://127.0.0.1:58080/admin/host_api.php', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: { action: 'manifest_validate', _csrf: csrf, manifest_json: manifest },
  });
  expect(res.status()).toBe(200);
  const body = await res.json();
  expect(body.ok).toBe(true);
  expect(Array.isArray(body.errors)).toBe(true);
  expect(body.errors.length).toBe(0);
});

test('manifest validate API rejects invalid manifest', async ({ page }) => {
  test.setTimeout(120000);
  await ensureInstalledHostAgent();
  await loginAsDevAdmin(page);

  const csrf = await getHostCsrf(page);
  const manifest = JSON.stringify({
    packages: { nginx: { state: 'invalid_state' } },
  });

  const res = await page.request.post('http://127.0.0.1:58080/admin/host_api.php', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: { action: 'manifest_validate', _csrf: csrf, manifest_json: manifest },
  });
  expect(res.status()).toBe(200);
  const body = await res.json();
  expect(body.ok).toBe(false);
  expect(Array.isArray(body.errors)).toBe(true);
  expect(body.errors.length).toBeGreaterThan(0);
});

test('manifest dry-run API returns expected structure', async ({ page }) => {
  test.setTimeout(120000);
  await ensureInstalledHostAgent();
  await loginAsDevAdmin(page);

  const csrf = await getHostCsrf(page);
  const manifest = JSON.stringify({
    packages: { htop: { state: 'installed' } },
    services: {},
    configs: {},
  });

  const res = await page.request.post('http://127.0.0.1:58080/admin/host_api.php', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: { action: 'manifest_dry_run', _csrf: csrf, manifest_json: manifest },
  });
  expect(res.status()).toBe(200);
  const body = await res.json();
  expect(typeof body.ok).toBe('boolean');
  expect(typeof body.dry_run).toBe('boolean');
  expect(body.dry_run).toBe(true);
  expect(Array.isArray(body.changes)).toBe(true);
});

test('manifest apply API returns expected structure', async ({ page }) => {
  test.setTimeout(120000);
  await ensureInstalledHostAgent();
  await loginAsDevAdmin(page);

  const csrf = await getHostCsrf(page);
  const manifest = JSON.stringify({
    packages: { htop: { state: 'installed' } },
    services: {},
    configs: {},
  });

  const res = await page.request.post('http://127.0.0.1:58080/admin/host_api.php', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: { action: 'manifest_apply', _csrf: csrf, manifest_json: manifest },
  });
  expect(res.status()).toBe(200);
  const body = await res.json();
  expect(typeof body.ok).toBe('boolean');
  expect(typeof body.changed).toBe('boolean');
  expect(Array.isArray(body.changes)).toBe(true);
  expect(Array.isArray(body.errors)).toBe(true);
});

test('navigation menu shows manifests link', async ({ page }) => {
  test.setTimeout(60000);
  await loginAsDevAdmin(page);
  await page.goto('/admin/index.php');

  const manifestsLink = page.locator('a[href="manifests.php"]');
  await expect(manifestsLink).toBeVisible();
  await expect(manifestsLink).toContainText('声明式管理');
});
