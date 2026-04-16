import { test, expect } from '../../helpers/fixtures';
import { attachClientErrorTracking, loginAsDevAdmin } from '../../helpers/auth';

test('debug advanced flows cover display_errors toggles and logs api csrf rejection', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
      /Failed to load resource: the server responded with a status of 403 \(Forbidden\)/,
    ],
  });

  await loginAsDevAdmin(page);
  await page.goto('/admin/debug.php');

  const toggleForm = page.locator('form:has(input[name="action"][value="toggle_display_errors"])');
  page.once('dialog', (dialog) => dialog.accept());
  await toggleForm.getByRole('button').click();
  await expect(page.locator('body')).toContainText(/display_errors 已开启|display_errors 已关闭/);

  const csrfMissing = await page.request.post('http://127.0.0.1:58080/admin/logs_api.php?action=clear&type=dns', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: {},
  });
  expect(csrfMissing.status()).toBe(403);

  await page.goto('/admin/logs.php');
  const csrf = await page.locator('input[name="_csrf"]').first().inputValue();
  const clearRes = await page.request.post('http://127.0.0.1:58080/admin/logs_api.php?action=clear&type=dns', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: { _csrf: csrf },
  });
  expect(clearRes.status()).toBe(200);
  expect(await clearRes.json()).toMatchObject({ ok: expect.any(Boolean) });

  await tracker.assertNoClientErrors();
});
