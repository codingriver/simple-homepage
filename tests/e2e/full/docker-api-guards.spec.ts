import { test, expect } from '../../helpers/fixtures';
import { attachClientErrorTracking, loginAsDevAdmin } from '../../helpers/auth';

test('docker api enforces ajax header and admin permission', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page);

  const guestNoAjax = await page.request.get('http://127.0.0.1:58080/admin/docker_api.php?action=summary');
  expect(guestNoAjax.status()).toBe(400);

  const guestAjax = await page.request.get('http://127.0.0.1:58080/admin/docker_api.php?action=summary', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
  });
  expect(guestAjax.status()).toBe(403);

  await loginAsDevAdmin(page);

  const adminSummary = await page.request.get('http://127.0.0.1:58080/admin/docker_api.php?action=summary', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
  });
  expect(adminSummary.status()).toBe(200);
  const body = await adminSummary.json();
  expect(typeof body.ok).toBe('boolean');

  const adminContainers = await page.request.get('http://127.0.0.1:58080/admin/docker_api.php?action=containers', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
  });
  expect(adminContainers.status()).toBe(200);
  expect(typeof (await adminContainers.json()).ok).toBe('boolean');

  const adminUnknown = await page.request.get('http://127.0.0.1:58080/admin/docker_api.php?action=unknown_action', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
  });
  expect(adminUnknown.status()).toBe(404);

  await tracker.assertNoClientErrors();
});
