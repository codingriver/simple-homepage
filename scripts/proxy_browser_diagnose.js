#!/usr/bin/env node
/**
 * Real-browser proxy diagnostics.
 *
 * Usage:
 *   PROXY_DIAG_URLS=https://qb1.303066.xyz/,https://nas.303066.xyz/ \
 *   PROXY_DIAG_HEADED=1 node scripts/proxy_browser_diagnose.js
 */
const fs = require('fs');
const path = require('path');
const { chromium } = require('@playwright/test');

const root = path.resolve(__dirname, '..');
const headed = process.env.PROXY_DIAG_HEADED === '1';
const baseTimeout = Number(process.env.PROXY_DIAG_TIMEOUT || 45000);
const afterLoadWait = Number(process.env.PROXY_DIAG_AFTER_LOAD_MS || 2500);
const username = process.env.PROXY_DIAG_USER || 'qatest';
const password = process.env.PROXY_DIAG_PASS || 'qatest2026';
const navSession = process.env.PROXY_DIAG_NAV_SESSION || '';
const navSessionCookieName = process.env.PROXY_DIAG_SESSION_COOKIE_NAME || 'nav_session';
const phpSession = process.env.PROXY_DIAG_PHP_SESSION || '';
const phpSessionCookieName = process.env.PROXY_DIAG_PHP_SESSION_COOKIE_NAME || 'nav_php_session';
const qbUsername = process.env.PROXY_DIAG_QB_USER || 'admin';
const qbPassword = process.env.PROXY_DIAG_QB_PASS || '111111';

function readSitesData() {
  const sitesPath = path.join(root, 'data', 'sites.json');
  try {
    return JSON.parse(fs.readFileSync(sitesPath, 'utf8'));
  } catch {
    return { groups: [] };
  }
}

function proxyUrlForSite(site) {
  const type = site.type || '';
  if (!['proxy', 'proxy_domain', 'proxy_path'].includes(type)) return '';
  if (type === 'proxy_path' || (type === 'proxy' && site.proxy_mode !== 'domain')) return '';
  const domain = String(site.proxy_domain || '').trim();
  if (!domain) return '';
  const isLocal = domain.includes('.local.');
  const port = process.env.NAV_PORT || '58080';
  return `${isLocal ? 'http' : 'https'}://${domain}${isLocal ? `:${port}` : ''}/`;
}

function credentialForSite(site) {
  return {
    username: String(site.credential_username || ''),
    password: String(site.credential_password || ''),
  };
}

function findSiteForUrl(url, sitesData) {
  let parsed;
  try {
    parsed = new URL(url);
  } catch {
    return null;
  }
  const firstLabel = parsed.hostname.split('.')[0];
  for (const group of sitesData.groups || []) {
    for (const site of group.sites || []) {
      const siteUrl = proxyUrlForSite(site);
      const siteHost = site.proxy_domain || (siteUrl ? new URL(siteUrl).hostname : '');
      if (siteUrl === url || siteHost === parsed.hostname || site.id === firstLabel || `${site.id}-local` === firstLabel) {
        return site;
      }
    }
  }
  return null;
}

function readProxyUrls() {
  const sitesData = readSitesData();
  const fromEnv = (process.env.PROXY_DIAG_URLS || '')
    .split(',')
    .map((s) => s.trim())
    .filter(Boolean);
  if (fromEnv.length) {
    return fromEnv.map((url) => {
      const site = findSiteForUrl(url, sitesData);
      return {
        id: site?.id || new URL(url).hostname.split('.')[0],
        url,
        credentials: site ? credentialForSite(site) : { username: '', password: '' },
      };
    });
  }

  const out = [];
  for (const group of sitesData.groups || []) {
    for (const site of group.sites || []) {
      const url = proxyUrlForSite(site);
      if (!url) continue;
      out.push({
        id: site.id || new URL(url).hostname.split('.')[0],
        url,
        credentials: credentialForSite(site),
      });
    }
  }
  return out;
}

async function safeCount(page, selector) {
  try {
    return await page.locator(selector).count({ timeout: 1000 });
  } catch {
    return 0;
  }
}

async function seedAuthCookies(context, target) {
  const cookies = [];
  if (navSession) {
    cookies.push({
      name: navSessionCookieName,
      value: navSession,
      url: target.url,
      path: '/',
      httpOnly: true,
      sameSite: 'Lax',
    });
  }
  if (phpSession) {
    cookies.push({
      name: phpSessionCookieName,
      value: phpSession,
      url: target.url,
      path: '/',
      httpOnly: true,
      sameSite: 'Lax',
    });
  }
  if (cookies.length) {
    await context.addCookies(cookies);
  }
}

async function loginIfNeeded(page) {
  await page.waitForLoadState('domcontentloaded').catch(() => undefined);
  await page.waitForTimeout(500);
  if (page.url().includes('/login.php') && await safeCount(page, 'input[name="username"]')) {
    await page.locator('input[name="username"]').fill(username);
    await page.locator('input[name="password"]').fill(password);
    await page.locator('button[type="submit"]').click();
    await page.waitForLoadState('domcontentloaded').catch(() => undefined);
    await page.waitForTimeout(1200);
  }

  const deviceCount = await safeCount(page, 'input[name="kick_jti[]"]');
  if (deviceCount > 0) {
    const boxes = page.locator('input[name="kick_jti[]"]');
    for (let i = 0; i < deviceCount; i++) {
      await boxes.nth(i).check().catch(() => undefined);
    }
    await page.locator('button[type="submit"]').click();
    await page.waitForLoadState('domcontentloaded').catch(() => undefined);
    await page.waitForTimeout(1200);
  }
}

function isQbTarget(target) {
  const id = String(target.id || '').toLowerCase();
  const url = String(target.url || '').toLowerCase();
  return id.includes('qb') || url.includes('qb');
}

async function diagnoseQbittorrent(page, credentials) {
  const out = {
    type: 'qbittorrent',
    ok: false,
    login_status: 0,
    maindata_status: 0,
    torrent_count: null,
    completed_count: null,
    error: '',
  };

  try {
    await page.waitForLoadState('domcontentloaded').catch(() => undefined);
    const result = await page.evaluate(async ({ username, password }) => {
      const user = String(username || '');
      const pass = String(password || '');
      if (!user && !pass) {
        return {
          ok: false,
          login_status: 0,
          maindata_status: 0,
          torrent_count: null,
          completed_count: null,
          error: 'qB credential is empty',
        };
      }
      const loginBody = new URLSearchParams();
      loginBody.set('username', user);
      loginBody.set('password', pass);
      const loginResp = await fetch('/api/v2/auth/login', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: loginBody.toString(),
      });
      const loginText = await loginResp.text();
      if (!loginResp.ok || !/^Ok\.?$/i.test(loginText.trim())) {
        return {
          ok: false,
          login_status: loginResp.status,
          maindata_status: 0,
          torrent_count: null,
          completed_count: null,
          error: `qB login failed: HTTP ${loginResp.status} ${loginText.slice(0, 120)}`,
        };
      }

      const dataResp = await fetch('/api/v2/sync/maindata?rid=0', {
        method: 'GET',
        credentials: 'same-origin',
      });
      const text = await dataResp.text();
      if (!dataResp.ok) {
        return {
          ok: false,
          login_status: loginResp.status,
          maindata_status: dataResp.status,
          torrent_count: null,
          completed_count: null,
          error: `qB maindata failed: HTTP ${dataResp.status} ${text.slice(0, 120)}`,
        };
      }

      const data = JSON.parse(text);
      const torrents = data && data.torrents && typeof data.torrents === 'object' ? data.torrents : {};
      const list = Object.values(torrents);
      return {
        ok: true,
        login_status: loginResp.status,
        maindata_status: dataResp.status,
        torrent_count: list.length,
        completed_count: list.filter((torrent) => Number(torrent && torrent.progress) >= 1).length,
        error: '',
      };
    }, {
      username: credentials?.username || qbUsername,
      password: credentials?.password || qbPassword,
    });

    Object.assign(out, result);
  } catch (e) {
    out.error = String(e.message || e).split('\n')[0];
  }
  return out;
}

async function diagnoseOne(context, target) {
  await seedAuthCookies(context, target);
  const page = await context.newPage();
  const requests = new Map();
  const failed = [];
  const consoleErrors = [];

  page.on('console', (msg) => {
    if (msg.type() === 'error') consoleErrors.push(msg.text());
  });
  page.on('pageerror', (err) => consoleErrors.push(`pageerror: ${err.message}`));
  page.on('request', (request) => {
    requests.set(request, {
      url: request.url(),
      type: request.resourceType(),
      start: Date.now(),
      status: null,
      end: null,
      failed: null,
    });
  });
  page.on('response', (response) => {
    const item = requests.get(response.request());
    if (item) item.status = response.status();
  });
  page.on('requestfinished', (request) => {
    const item = requests.get(request);
    if (item) item.end = Date.now();
  });
  page.on('requestfailed', (request) => {
    const item = requests.get(request);
    const error = request.failure()?.errorText || 'failed';
    if (item) {
      item.end = Date.now();
      item.failed = error;
    }
    failed.push({ url: request.url(), type: request.resourceType(), error });
  });

  const start = Date.now();
  let status = null;
  let error = '';
  try {
    const response = await page.goto(target.url, { waitUntil: 'domcontentloaded', timeout: baseTimeout });
    status = response ? response.status() : null;
  } catch (e) {
    error = String(e.message || e).split('\n')[0];
  }
  await loginIfNeeded(page);
  await page.waitForLoadState('load', { timeout: baseTimeout }).catch((e) => {
    if (!error) error = `load timeout: ${String(e.message || e).split('\n')[0]}`;
  });
  await page.waitForTimeout(Number.isFinite(afterLoadWait) ? Math.max(0, afterLoadWait) : 2500);

  const appChecks = [];
  if (isQbTarget(target)) {
    const credentials = target.credentials || {};
    appChecks.push(await diagnoseQbittorrent(page, {
      username: credentials.username || qbUsername,
      password: credentials.password || qbPassword,
    }));
  }

  const now = Date.now();
  const all = [...requests.values()];
  const pending = all
    .filter((item) => !item.end)
    .map((item) => ({ type: item.type, status: item.status, age_ms: now - item.start, url: item.url }))
    .sort((a, b) => b.age_ms - a.age_ms)
    .slice(0, 10);
  const slow = all
    .filter((item) => item.end)
    .map((item) => ({ type: item.type, status: item.status, ms: item.end - item.start, url: item.url, failed: item.failed }))
    .sort((a, b) => b.ms - a.ms)
    .slice(0, 10);

  const result = {
    id: target.id,
    url: target.url,
    status,
    ms: Date.now() - start,
    title: await page.title().catch(() => ''),
    final_url: page.url(),
    error,
    failed: failed.slice(0, 10),
    pending,
    slow,
    console_errors: consoleErrors.slice(0, 10),
    app_checks: appChecks,
  };
  await page.close();
  return result;
}

(async () => {
  const targets = readProxyUrls();
  const browser = await chromium.launch({ headless: !headed, slowMo: headed ? 10 : 0 });
  const context = await browser.newContext({
    ignoreHTTPSErrors: true,
    userAgent: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/114.0.0.0 Safari/537.36',
  });

  const results = [];
  for (const target of targets) {
    results.push(await diagnoseOne(context, target));
  }

  await browser.close();
  console.log(JSON.stringify(results, null, 2));
})().catch((err) => {
  console.error(err);
  process.exit(1);
});
