import { test, expect } from '../../helpers/fixtures';
import { attachClientErrorTracking, loginAsDevAdmin } from '../../helpers/auth';

test('settings rejects overlong site name', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
    ],
    ignoredFailedRequests: [/POST .*\/admin\/settings\.php :: net::ERR_ABORTED/],
  });

  await loginAsDevAdmin(page);
  await page.goto('/admin/settings.php');

  const csrf = await page.locator('input[name="_csrf"]').first().inputValue();
  const response = await page.request.post('/admin/settings.php', {
    form: {
      _csrf: csrf,
      action: 'save_settings',
      site_name: 'x'.repeat(61),
    },
  });
  expect(response.status()).toBe(200);
  expect(await response.text()).toContain('站点名称不能超过 60 个字符');

  await tracker.assertNoClientErrors();
});
