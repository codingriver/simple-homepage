import { test, expect } from '../../helpers/fixtures';
import { attachClientErrorTracking, loginAsDevAdmin } from '../../helpers/auth';

async function currentCsrf(page: Parameters<typeof loginAsDevAdmin>[0]) {
  return page.locator('input[name="_csrf"]').first().inputValue();
}

test('csrf guards reject admin mutations without valid token', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
      /Failed to load resource: the server responded with a status of 403 \(Forbidden\)/,
    ],
  });

  await loginAsDevAdmin(page);

  const groupRes = await page.request.post('http://127.0.0.1:58080/admin/groups.php', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: { action: 'save', gid: `csrf-group-${Date.now()}`, name: 'CSRF 分组' },
  });
  expect(groupRes.status()).toBe(403);

  const siteRes = await page.request.post('http://127.0.0.1:58080/admin/sites.php', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: { action: 'save', gid: 'missing', sid: `csrf-site-${Date.now()}`, name: 'CSRF 站点', type: 'external', url: 'https://example.com' },
  });
  expect(siteRes.status()).toBe(403);

  const settingsRes = await page.request.post('http://127.0.0.1:58080/admin/settings.php', {
    form: { action: 'save_settings', site_name: 'csrf-blocked' },
  });
  expect(settingsRes.status()).toBe(403);

  const backupRes = await page.request.post('http://127.0.0.1:58080/admin/backups.php', {
    form: { action: 'create' },
  });
  expect(backupRes.status()).toBe(403);

  await page.goto('/admin/logs.php');
  const logsRes = await page.request.post('http://127.0.0.1:58080/admin/logs_api.php?action=clear&type=dns', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: {},
  });
  expect(logsRes.status()).toBe(403);

  await page.goto('/admin/settings.php');
  const csrf = await currentCsrf(page);
  const healthRes = await page.request.post('http://127.0.0.1:58080/admin/health_check.php', {
    form: { action: 'check_all', _csrf: `${csrf}-bad` },
  });
  expect(healthRes.status()).toBe(403);

  await tracker.assertNoClientErrors();
});
