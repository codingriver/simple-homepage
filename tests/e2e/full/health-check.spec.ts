import { test, expect } from '../../helpers/fixtures';
import { attachClientErrorTracking, loginAsDevAdmin } from '../../helpers/auth';

test('health check supports cached status bulk check and guarded single url check', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
    ],
  });

  await loginAsDevAdmin(page);
  await page.goto('/admin/settings.php');
  const csrf = await page.locator('input[name="_csrf"]').first().inputValue();

  const statusRes = await page.request.get('http://127.0.0.1:58080/admin/health_check.php?ajax=status');
  expect(statusRes.status()).toBe(200);
  expect(await statusRes.json()).toMatchObject({ ok: true });

  const missingUrlRes = await page.request.post('http://127.0.0.1:58080/admin/health_check.php', {
    form: { action: 'check_one', _csrf: csrf, url: '' },
  });
  expect(missingUrlRes.status()).toBe(200);
  expect(await missingUrlRes.json()).toMatchObject({ ok: false });

  const singleRes = await page.request.post('http://127.0.0.1:58080/admin/health_check.php', {
    form: { action: 'check_one', _csrf: csrf, url: 'http://127.0.0.1:58080/' },
    timeout: 30000,
  });
  expect(singleRes.status()).toBe(200);
  expect(await singleRes.json()).toMatchObject({ ok: expect.any(Boolean) });

  await tracker.assertNoClientErrors();
});
