import { test, expect } from '../../helpers/fixtures';
import { attachClientErrorTracking, loginAsDevAdmin } from '../../helpers/auth';

test('debug page clear cookie action redirects to login', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page);
  await loginAsDevAdmin(page);
  await page.goto('/admin/debug.php');

  const csrf = await page.locator('input[name="_csrf"]').first().inputValue();
  const response = await page.request.post('http://127.0.0.1:58080/admin/debug.php', {
    form: { action: 'clear_cookie', _csrf: csrf },
    maxRedirects: 0,
  });
  expect(response.status()).toBe(302);
  expect(response.headers()['location'] || '').toContain('login.php');

  await tracker.assertNoClientErrors();
});
