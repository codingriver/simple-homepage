import { test, expect } from '@playwright/test';
import { attachClientErrorTracking, loginAsDevAdmin, logout } from '../../helpers/auth';

test('admin endpoints consistently reject guest and allow authenticated admin access', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
      /Failed to load resource: the server responded with a status of 403 \(Forbidden\)/,
    ],
  });

  const guestDdns = await page.request.get('http://127.0.0.1:58080/admin/ddns_ajax.php?action=list', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
  });
  expect(guestDdns.status()).toBe(403);

  const guestHealth = await page.request.get('http://127.0.0.1:58080/admin/health_check.php?ajax=status');
  expect(guestHealth.status()).toBe(401);

  const guestLoginLogs = await page.request.get('http://127.0.0.1:58080/admin/login_logs.php', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
  });
  expect(guestLoginLogs.status()).toBe(403);

  const guestDebug = await page.request.get('http://127.0.0.1:58080/admin/debug.php?ajax=log&type=dns&lines=20', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
  });
  expect(guestDebug.status()).toBe(401);

  const guestTaskLog = await page.request.get('http://127.0.0.1:58080/admin/api/task_log.php?id=missing&page=1');
  expect(guestTaskLog.status()).toBe(403);

  await loginAsDevAdmin(page);

  const adminDdns = await page.request.get('http://127.0.0.1:58080/admin/ddns_ajax.php?action=list', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
  });
  expect(adminDdns.status()).toBe(200);
  expect(await adminDdns.json()).toMatchObject({ ok: true });

  const adminHealth = await page.request.get('http://127.0.0.1:58080/admin/health_check.php?ajax=status');
  expect(adminHealth.status()).toBe(200);
  expect(await adminHealth.json()).toMatchObject({ ok: true });

  const adminLoginLogs = await page.request.get('http://127.0.0.1:58080/admin/login_logs.php', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
  });
  expect(adminLoginLogs.status()).toBe(200);
  expect(await adminLoginLogs.json()).toMatchObject({ ok: true });

  const adminDebug = await page.request.get('http://127.0.0.1:58080/admin/debug.php?ajax=log&type=dns&lines=20', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
  });
  expect(adminDebug.status()).toBe(200);
  expect(await adminDebug.text()).toBeTruthy();

  await logout(page);
  await tracker.assertNoClientErrors();
});
