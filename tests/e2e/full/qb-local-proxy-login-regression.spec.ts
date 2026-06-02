import { test, expect } from '../../helpers/fixtures';
import type { Page } from '@playwright/test';
import { runDockerPhpInline } from '../../helpers/cli';

const qbProxyUrl = process.env.QB_PROXY_URL || 'http://qb1.local.303066.xyz:58080/';
const runQbProxyRegression = process.env.RUN_QB_PROXY_E2E === '1' || !!process.env.QB_PROXY_URL;
const chrome114Ua = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/114.0.0.0 Safari/537.36';
const qbProxyHost = new URL(qbProxyUrl).hostname;

async function safeCount(page: Page, selector: string): Promise<number> {
  for (let i = 0; i < 3; i++) {
    try {
      return await page.locator(selector).count();
    } catch {
      await page.waitForTimeout(300);
    }
  }
  return 0;
}

async function loginHomepage(page: Page, options: { resolveKick?: boolean; checkAllDevices?: boolean } = {}) {
  await page.goto(qbProxyUrl, { waitUntil: 'domcontentloaded' });
  await page.waitForLoadState('domcontentloaded').catch(() => undefined);
  await page.waitForTimeout(500);

  if (await safeCount(page, 'input[name="username"]')) {
    await page.locator('input[name="username"]').fill('qatest');
    await page.locator('input[name="password"]').fill('qatest2026');
    await page.locator('button[type="submit"]').click();
    await page.waitForLoadState('domcontentloaded').catch(() => undefined);
    await page.waitForTimeout(900);
  }

  const deviceCheckboxes = page.locator('input[name="kick_jti[]"]');
  const deviceCount = await safeCount(page, 'input[name="kick_jti[]"]');
  if (deviceCount > 0 && options.resolveKick !== false) {
    if (options.checkAllDevices) {
      for (let i = 0; i < deviceCount; i++) {
        await deviceCheckboxes.nth(i).check();
      }
    }
    await page.locator('button[type="submit"]').click();
    await page.waitForLoadState('domcontentloaded').catch(() => undefined);
    await page.waitForTimeout(1200);
  }
}

test.describe('qB local proxy login regression', () => {
  test.setTimeout(120_000);

  test.skip(!runQbProxyRegression, 'Set RUN_QB_PROXY_E2E=1 or QB_PROXY_URL to run the local qB proxy regression.');

  test('dirty cookies and max-session kick still reach qBittorrent through local domain proxy', async ({ browser }) => {
    const sessionResetResult = runDockerPhpInline(
      'file_put_contents("/var/www/nav/data/sessions.json", "{}", LOCK_EX); file_put_contents("/var/www/nav/data/ip_locks.json", "{}", LOCK_EX);'
    );
    expect(sessionResetResult.code, sessionResetResult.output).toBe(0);

    const maxResult = runDockerPhpInline(
      [
        '$file = "/var/www/nav/data/users.json";',
        '$users = json_decode(file_get_contents($file), true) ?: [];',
        'if (isset($users["qatest"])) {',
        '  $users["qatest"]["max_sessions"] = 3;',
        '  file_put_contents($file, json_encode($users, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);',
        '}',
      ].join(' ')
    );
    expect(maxResult.code, maxResult.output).toBe(0);

    const holders = [];
    for (let i = 0; i < 3; i++) {
      const context = await browser.newContext({
        ignoreHTTPSErrors: true,
        userAgent: chrome114Ua,
      });
      const page = await context.newPage();
      await loginHomepage(page, { resolveKick: true, checkAllDevices: true });
      await expect(page).toHaveTitle(/qBittorrent WebUI/);
      holders.push(context);
    }

    const context = await browser.newContext({
      ignoreHTTPSErrors: true,
      userAgent: chrome114Ua,
    });
    const page = await context.newPage();

    await context.addCookies([
      {
        name: 'nav_session',
        value: 'invalid-stale-domain-token',
        domain: '.303066.xyz',
        path: '/',
        httpOnly: true,
        secure: false,
        sameSite: 'Lax',
      },
    ]);

    await loginHomepage(page, { resolveKick: false });
    const deviceCheckboxes = page.locator('input[name="kick_jti[]"]');
    await expect(deviceCheckboxes.first()).toBeVisible();
    expect(await deviceCheckboxes.count()).toBeGreaterThanOrEqual(1);
    expect(await page.locator('input[name="kick_jti[]"]:checked').count()).toBeGreaterThanOrEqual(1);

    const deviceCount = await deviceCheckboxes.count();
    for (let i = 0; i < deviceCount; i++) {
      await deviceCheckboxes.nth(i).check();
    }
    await page.locator('button[type="submit"]').click();
    await page.waitForLoadState('domcontentloaded').catch(() => undefined);
    await page.waitForTimeout(1500);

    await expect(page).toHaveTitle(/qBittorrent WebUI/);
    await expect(page).toHaveURL(new RegExp(qbProxyUrl.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')));

    const cookies = await context.cookies(qbProxyUrl);
    expect(cookies.some((cookie) => cookie.name === 'nav_session' && cookie.domain === '.303066.xyz')).toBeTruthy();
    expect(cookies.some((cookie) => cookie.name === 'nav_session' && cookie.domain === qbProxyHost)).toBeTruthy();

    await context.addCookies([
      {
        name: 'nav_session',
        value: 'another-invalid-stale-domain-token',
        domain: '.303066.xyz',
        path: '/',
        httpOnly: true,
        secure: false,
        sameSite: 'Lax',
      },
    ]);
    await page.goto(qbProxyUrl, { waitUntil: 'domcontentloaded' });
    await expect(page).toHaveTitle(/qBittorrent WebUI/);

    const qbApi = await page.evaluate(async () => {
      const login = await fetch('/api/v2/auth/login', {
        method: 'POST',
        body: new URLSearchParams({ username: 'admin', password: '111111' }),
        credentials: 'include',
      });
      const loginText = await login.text();
      const torrents = await fetch('/api/v2/torrents/info?limit=50', { credentials: 'include' });
      const items = await torrents.json();
      return {
        loginStatus: login.status,
        loginText,
        torrentsStatus: torrents.status,
        total: items.length,
        completed: items.filter((torrent: { completion_on?: number }) => (torrent.completion_on || 0) > 0).length,
      };
    });

    expect(qbApi.loginStatus).toBe(200);
    expect(qbApi.loginText).toBe('Ok.');
    expect(qbApi.torrentsStatus).toBe(200);
    expect(qbApi.total).toBeGreaterThan(0);
    expect(qbApi.completed).toBeGreaterThan(0);

    await context.close();
    for (const holder of holders) {
      await holder.close();
    }
  });
});
