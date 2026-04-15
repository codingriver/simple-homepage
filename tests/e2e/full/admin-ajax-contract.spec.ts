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

  const logsList = await page.request.get('http://127.0.0.1:58080/admin/logs_api.php?action=list', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
  });
  expect(logsList.status()).toBe(200);
  const logsListBody = await logsList.json();
  expect(logsListBody.ok).toBe(true);
  expect(typeof logsListBody.sources).toBe('object');
  expect(logsListBody.sources.dns).toMatchObject({ key: 'dns', label: expect.any(String) });

  const logsRead = await page.request.get('http://127.0.0.1:58080/admin/logs_api.php?action=read&type=dns&offset=0&limit=20', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
  });
  expect(logsRead.status()).toBe(200);
  const logsReadBody = await logsRead.json();
  expect(logsReadBody.ok).toBe(true);
  expect(Array.isArray(logsReadBody.lines)).toBe(true);

  await page.goto('/admin/logs.php');
  const csrf = await page.locator('input[name="_csrf"]').first().inputValue();
  const clearLog = await page.request.post('http://127.0.0.1:58080/admin/logs_api.php?action=clear&type=dns', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: { _csrf: csrf },
  });
  expect(clearLog.status()).toBe(200);
  expect(await clearLog.json()).toMatchObject({ ok: expect.any(Boolean) });

  const anonContext = await browser.newContext({ baseURL: 'http://127.0.0.1:58080' });
  const anonPage = await anonContext.newPage();
  const deniedLoginLogs = await anonPage.request.get('http://127.0.0.1:58080/admin/login_logs.php', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
  });
  expect(deniedLoginLogs.status()).toBe(403);

  const deniedLogs = await anonPage.request.get('http://127.0.0.1:58080/admin/logs_api.php?action=read&type=dns&offset=0&limit=20', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
  });
  expect(deniedLogs.status()).toBe(403);

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
