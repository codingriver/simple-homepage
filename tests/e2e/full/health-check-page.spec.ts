import fs from 'fs/promises';
import path from 'path';
import { test, expect } from '../../helpers/fixtures';
import { attachClientErrorTracking, loginAsDevAdmin } from '../../helpers/auth';
import { writeContainerFile } from '../../helpers/cli';

const sitesPath = path.resolve(__dirname, '../../../data/sites.json');
const healthCachePath = path.resolve(__dirname, '../../../data/health_cache.json');
const containerSitesPath = '/var/www/nav/data/sites.json';
const containerHealthCachePath = '/var/www/nav/data/health_cache.json';
const baseUrl = 'http://127.0.0.1:58080';

test('health check page renders status table and supports immediate check', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
    ],
  });

  const ts = Date.now();
  const siteName = `健康页站点 ${ts}`;
  const originalSites = await fs.readFile(sitesPath, 'utf8').catch(() => '{}');
  const hadHealthCache = await fs.access(healthCachePath).then(() => true).catch(() => false);
  const originalHealthCache = await fs.readFile(healthCachePath, 'utf8').catch(() => '');

  try {
    const sites = JSON.parse(originalSites) as {
      groups?: Array<Record<string, unknown> & { sites?: Array<Record<string, unknown>> }>;
    };
    const groups = Array.isArray(sites.groups) ? sites.groups : [];
    groups.unshift({
      id: `health-page-group-${ts}`,
      name: `健康分组 ${ts}`,
      icon: '🩺',
      visible_to: 'all',
      auth_required: false,
      order: -1,
      sites: [
        {
          id: `health-page-site-${ts}`,
          name: siteName,
          type: 'external',
          url: `${baseUrl}/index.php`,
          icon: '🌐',
          desc: 'health page e2e',
          order: 0,
        },
      ],
    });
    const sitesJson = JSON.stringify({ ...sites, groups }, null, 4);
    writeContainerFile(containerSitesPath, sitesJson);
    await fs.writeFile(sitesPath, sitesJson, 'utf8').catch(() => undefined);

    await loginAsDevAdmin(page);
    await page.goto('/admin/settings.php#health');

    // Verify health section renders
    await expect(page.locator('#health')).toBeVisible();
    await expect(page.getByRole('button', { name: /立即检测所有站点/ })).toBeVisible();
    await expect(page.getByRole('button', { name: /刷新缓存状态/ })).toBeVisible();

    // Seed a cached health status so the table renders immediately
    const healthCacheJson = JSON.stringify(
      {
        [`${baseUrl}/index.php`]: {
          status: 'up',
          code: 200,
          ms: 12,
          checked_at: Math.floor(Date.now() / 1000),
        },
      },
      null,
      4
    );
    writeContainerFile(containerHealthCachePath, healthCacheJson);
    await fs.writeFile(healthCachePath, healthCacheJson, 'utf8').catch(() => undefined);

    // Click refresh cached status
    const healthResponsePromise = page.waitForResponse(
      (response) => response.url().includes('/admin/health_check.php?ajax=status') && response.request().method() === 'GET',
      { timeout: 30000 }
    );
    await page.getByRole('button', { name: /刷新缓存状态/ }).click({ force: true });
    const healthResponse = await healthResponsePromise;
    const healthPayload = (await healthResponse.json()) as { ok: boolean; data: Record<string, unknown> };
    expect(healthPayload.ok).toBe(true);
    expect(Object.keys(healthPayload.data)).toContain(`${baseUrl}/index.php`);

    // Verify results table/grid is visible and contains site info
    await expect(page.locator('#health_results')).toBeVisible();
    await expect(page.locator('#health_table')).toContainText(siteName);
    await expect(page.locator('#health_table')).toContainText(/在线|up|200/);

    // Click immediate check and wait for results
    const checkPromise = page.waitForResponse(
      (response) =>
        response.url().includes('/admin/health_check.php') &&
        response.request().method() === 'POST',
      { timeout: 60000 }
    );
    await page.getByRole('button', { name: /立即检测所有站点/ }).click({ force: true });
    const checkResponse = await checkPromise;
    expect(checkResponse.status()).toBe(200);
    const checkPayload = (await checkResponse.json()) as { ok: boolean; checked?: number };
    expect(checkPayload.ok).toBe(true);

    await tracker.assertNoClientErrors();
  } finally {
    writeContainerFile(containerSitesPath, originalSites);
    await fs.writeFile(sitesPath, originalSites, 'utf8').catch(() => undefined);
    if (hadHealthCache) {
      writeContainerFile(containerHealthCachePath, originalHealthCache);
      await fs.writeFile(healthCachePath, originalHealthCache, 'utf8').catch(() => undefined);
    } else {
      writeContainerFile(containerHealthCachePath, '{}');
      await fs.rm(healthCachePath, { force: true }).catch(() => undefined);
    }
  }
});
