import fs from 'fs/promises';
import path from 'path';
import { test, expect } from '../../helpers/fixtures';
import { attachClientErrorTracking, loginAsDevAdmin } from '../../helpers/auth';

const sitesPath = path.resolve(__dirname, '../../../data/sites.json');
const authLogPath = path.resolve(__dirname, '../../../data/logs/auth.log');
const healthCachePath = path.resolve(__dirname, '../../../data/health_cache.json');
const baseUrl = 'http://127.0.0.1:58080';

test('settings health panel and login logs panel load real rows through UI interactions', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
    ],
  });
  const originalSites = await fs.readFile(sitesPath, 'utf8').catch(() => '{}');
  const originalAuthLog = await fs.readFile(authLogPath, 'utf8').catch(() => '');
  const hadHealthCache = await fs
    .access(healthCachePath)
    .then(() => true)
    .catch(() => false);
  const originalHealthCache = await fs.readFile(healthCachePath, 'utf8').catch(() => '');
  const ts = Date.now();
  const logUser = `settings-log-${ts}`;
  const siteName = `健康检测站点 ${ts}`;

  try {
    const sites = JSON.parse(originalSites) as {
      groups?: Array<Record<string, unknown> & { sites?: Array<Record<string, unknown>> }>;
    };
    const groups = Array.isArray(sites.groups) ? sites.groups : [];
    groups.unshift({
      id: `health-group-${ts}`,
      name: `健康分组 ${ts}`,
      icon: '🩺',
      visible_to: 'all',
      auth_required: false,
      order: -1,
      sites: [
        {
          id: `health-site-${ts}`,
          name: siteName,
          type: 'external',
          url: `${baseUrl}/index.php`,
          icon: '🌐',
          desc: 'health e2e',
          order: 0,
        },
      ],
    });
    await fs.writeFile(sitesPath, JSON.stringify({ ...sites, groups }, null, 4), 'utf8');

    const logLines = [
      `[2026-04-07 10:00:00] SUCCESS    user=${logUser} ip=127.0.0.1 note=via_settings_test`,
      `[2026-04-07 10:01:00] FAIL       user=${logUser}-fail ip=127.0.0.2 note=wrong_password`,
    ].join('\n');
    const nextAuthLog = `${originalAuthLog.replace(/\s*$/, '')}\n${logLines}\n`.replace(/^\n/, '');
    await fs.writeFile(authLogPath, nextAuthLog, 'utf8');
    await fs.writeFile(
      healthCachePath,
      JSON.stringify(
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
      ),
      'utf8'
    );

    await loginAsDevAdmin(page);

    const loginLogsRes = await page.request.get('http://127.0.0.1:58080/admin/login_logs.php', {
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
    });
    expect(loginLogsRes.status()).toBe(200);
    const logsPayload = (await loginLogsRes.json()) as { ok: boolean; total: number; rows: string[] };
    expect(logsPayload.ok).toBe(true);
    expect(logsPayload.total).toBeGreaterThan(0);
    expect(logsPayload.rows.some((row) => row.includes(logUser))).toBeTruthy();

    await page.goto('/admin/settings.php#health');
    await page.locator('#health').scrollIntoViewIfNeeded();
    const healthCacheResponse = page.waitForResponse((response) => response.url().includes('/admin/health_check.php?ajax=status') && response.request().method() === 'GET', { timeout: 30000 });
    await page.getByRole('button', { name: /刷新缓存状态/ }).click({ force: true });
    const healthCachePayload = (await (await healthCacheResponse).json()) as {
      ok: boolean;
      data: Record<string, unknown>;
    };
    expect(healthCachePayload.ok).toBe(true);
    expect(Object.keys(healthCachePayload.data)).toContain(`${baseUrl}/index.php`);
    await expect(page.locator('#health_results')).toBeVisible();
    await expect(page.locator('#health_table')).toContainText(siteName);
    await expect(page.locator('#health_table')).toContainText(/在线|离线/);
    await expect(page.locator('#health_last_check')).toContainText('上次刷新：');

    await tracker.assertNoClientErrors();
  } finally {
    await fs.writeFile(sitesPath, originalSites, 'utf8');
    await fs.writeFile(authLogPath, originalAuthLog, 'utf8');
    if (hadHealthCache) {
      await fs.writeFile(healthCachePath, originalHealthCache, 'utf8');
    } else {
      await fs.rm(healthCachePath, { force: true });
    }
  }
});
