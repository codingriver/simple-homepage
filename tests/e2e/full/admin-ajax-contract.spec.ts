import { test, expect } from '@playwright/test';
import { attachClientErrorTracking, loginAsDevAdmin } from '../../helpers/auth';

test('admin ajax endpoints return expected payloads for login logs settings and debug', async ({ page, browser }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
    ],
  });

  await loginAsDevAdmin(page);

  const loginLogs = await page.request.get('http://127.0.0.1:58080/admin/login_logs.php', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
  });
  expect(loginLogs.status()).toBe(200);
  expect(await loginLogs.json()).toMatchObject({ ok: true });

  const loginLogsPlain = await page.request.get('http://127.0.0.1:58080/admin/login_logs.php');
  expect(loginLogsPlain.status()).toBe(400);

  const nginxSudo = await page.request.get('http://127.0.0.1:58080/admin/settings_ajax.php?action=nginx_sudo', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
  });
  expect(nginxSudo.status()).toBe(200);
  expect(await nginxSudo.json()).toMatchObject({ ok: true });

  const sitesMeta = await page.request.get('http://127.0.0.1:58080/admin/settings_ajax.php?action=health_sites_meta', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
  });
  expect(sitesMeta.status()).toBe(200);
  expect(await sitesMeta.json()).toMatchObject({ ok: true });

  const hostAgentStatus = await page.request.get('http://127.0.0.1:58080/admin/settings_ajax.php?action=host_agent_status', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
  });
  expect(hostAgentStatus.status()).toBe(200);
  expect(await hostAgentStatus.json()).toMatchObject({ ok: true, docker_socket_mounted: expect.any(Boolean) });

  const settingsPlain = await page.request.get('http://127.0.0.1:58080/admin/settings_ajax.php?action=nginx_sudo');
  expect(settingsPlain.status()).toBe(400);

  const settingsUnknown = await page.request.get('http://127.0.0.1:58080/admin/settings_ajax.php?action=unknown', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
  });
  expect(settingsUnknown.status()).toBe(404);

  const debugLog = await page.request.get('http://127.0.0.1:58080/admin/debug.php?ajax=log&type=dns&lines=20', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
  });
  expect(debugLog.status()).toBe(200);
  expect(await debugLog.text()).toBeTruthy();

  await page.goto('/admin/debug.php');
  const csrf = await page.evaluate(() => (window as any).DEBUG_CSRF as string);
  const clearLog = await page.request.post('http://127.0.0.1:58080/admin/debug.php', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: { ajax: 'clear_log', _csrf: csrf },
  });
  expect(clearLog.status()).toBe(200);
  expect(await clearLog.json()).toMatchObject({ ok: expect.any(Boolean) });

  const anonContext = await browser.newContext({ baseURL: 'http://127.0.0.1:58080' });
  const anonPage = await anonContext.newPage();
  const deniedLoginLogs = await anonPage.request.get('http://127.0.0.1:58080/admin/login_logs.php', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
  });
  expect(deniedLoginLogs.status()).toBe(403);

  const deniedDebug = await anonPage.request.get('http://127.0.0.1:58080/admin/debug.php?ajax=log&type=dns&lines=20', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
  });
  expect(deniedDebug.status()).toBe(401);

  const deniedSettings = await anonPage.request.get('http://127.0.0.1:58080/admin/settings_ajax.php?action=nginx_sudo', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
  });
  expect(deniedSettings.status()).toBe(403);

  const deniedHostAgent = await anonPage.request.get('http://127.0.0.1:58080/admin/settings_ajax.php?action=host_agent_status', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
  });
  expect(deniedHostAgent.status()).toBe(403);
  await anonContext.close();

  await tracker.assertNoClientErrors();
});

test('health check ajax supports status bulk and guarded single checks', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
    ],
  });

  await loginAsDevAdmin(page);
  await page.goto('/admin/settings.php');
  const csrf = await page.locator('input[name="_csrf"]').first().inputValue();

  const statusRes = await page.request.get('http://127.0.0.1:58080/admin/health_check.php?ajax=status');
  expect(statusRes.status()).toBe(200);
  expect(await statusRes.json()).toMatchObject({ ok: true });

  const missingUrl = await page.request.post('http://127.0.0.1:58080/admin/health_check.php', {
    form: { _csrf: csrf, action: 'check_one', url: '' },
  });
  expect(missingUrl.status()).toBe(200);
  expect(await missingUrl.json()).toMatchObject({ ok: false });

  const oneCheck = await page.request.post('http://127.0.0.1:58080/admin/health_check.php', {
    form: { _csrf: csrf, action: 'check_one', url: 'http://127.0.0.1:58080/' },
    timeout: 30000,
  });
  expect(oneCheck.status()).toBe(200);
  expect(await oneCheck.json()).toMatchObject({ ok: expect.any(Boolean) });

  await tracker.assertNoClientErrors();
});
