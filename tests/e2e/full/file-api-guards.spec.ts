import { test, expect } from '../../helpers/fixtures';
import { attachClientErrorTracking, loginAsDevAdmin } from '../../helpers/auth';

test('file api enforces ajax header and admin permission', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page);

  const guestNoAjax = await page.request.get('http://127.0.0.1:58080/admin/file_api.php?action=list');
  expect(guestNoAjax.status()).toBe(400);

  const guestAjax = await page.request.get('http://127.0.0.1:58080/admin/file_api.php?action=list', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
  });
  expect(guestAjax.status()).toBe(403);

  await loginAsDevAdmin(page);

  const adminList = await page.request.get('http://127.0.0.1:58080/admin/file_api.php?action=list&path=/', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
  });
  expect(adminList.status()).toBe(200);
  expect(typeof (await adminList.json()).ok).toBe('boolean');

  const adminRead = await page.request.get('http://127.0.0.1:58080/admin/file_api.php?action=stat&path=/', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
  });
  expect(adminRead.status()).toBe(200);
  expect(typeof (await adminRead.json()).ok).toBe('boolean');

  const adminUnknown = await page.request.get('http://127.0.0.1:58080/admin/file_api.php?action=unknown', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
  });
  expect(adminUnknown.status()).toBe(404);

  await tracker.assertNoClientErrors();
});
