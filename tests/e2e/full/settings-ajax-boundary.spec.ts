import { test, expect } from '../../helpers/fixtures';
import { attachClientErrorTracking, loginAsDevAdmin, logout } from '../../helpers/auth';

test('settings ajax returns expected structures and rejects unknown actions and guests', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page);

  await loginAsDevAdmin(page);
  const nginxSudo = await page.request.get('http://127.0.0.1:58080/admin/settings_ajax.php?action=nginx_sudo', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
  });
  expect(nginxSudo.status()).toBe(200);
  const nginxBody = await nginxSudo.json();
  expect(typeof nginxBody.ok).toBe('boolean');

  const unknown = await page.request.get('http://127.0.0.1:58080/admin/settings_ajax.php?action=unknown_action', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
  });
  expect(unknown.status()).toBe(404);

  await logout(page);
  const guest = await page.request.get('http://127.0.0.1:58080/admin/settings_ajax.php?action=nginx_sudo', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    maxRedirects: 0,
  });
  expect([302, 401, 403]).toContain(guest.status());

  await tracker.assertNoClientErrors();
});
