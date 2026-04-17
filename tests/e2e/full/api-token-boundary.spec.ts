import { test, expect } from '../../helpers/fixtures';
import { attachClientErrorTracking, loginAsDevAdmin } from '../../helpers/auth';

test('api token rejects malformed missing and deleted tokens', async ({ page, request }) => {
  const tracker = await attachClientErrorTracking(page);

  // malformed token
  const malformed = await request.get('/api/sites.php?token=not_a_valid_token');
  expect(malformed.status()).toBe(401);

  // missing token on protected endpoint
  const missing = await request.get('/api/sites.php');
  expect(missing.status()).toBe(401);

  // bearer format wrong
  const badBearer = await request.get('/api/sites.php', {
    headers: { Authorization: 'Bearer ' },
  });
  expect(badBearer.status()).toBe(401);

  // deleted token scenario: generate then delete via admin and retry
  await loginAsDevAdmin(page);
  await page.goto('/admin/settings.php#api-tokens');
  await page.locator('input[name="token_name"]').fill('BoundaryToken');
  await page.locator('#api-tokens form').filter({ has: page.locator('input[name="token_name"]') }).getByRole('button').click();

  const tokenEl = page.locator('#newApiToken');
  await expect(tokenEl).toBeVisible();
  const token = await tokenEl.textContent();
  expect(token).toMatch(/^np_[a-f0-9]+$/);

  // use once successfully
  const okRes = await request.get(`/api/sites.php?token=${token}`);
  expect(okRes.status()).toBe(200);

  // delete via UI
  await page.goto('/admin/settings.php#api-tokens');
  page.once('dialog', (dialog) => dialog.accept());
  const deleteForm = page.locator('form').filter({ has: page.locator(`input[value="${token}"]`) });
  await deleteForm.locator('button[type="submit"]').click();
  await page.waitForURL('/admin/settings.php*');

  // after deletion should 401
  const afterDel = await request.get(`/api/sites.php?token=${token}`);
  expect(afterDel.status()).toBe(401);

  await tracker.assertNoClientErrors();
});
