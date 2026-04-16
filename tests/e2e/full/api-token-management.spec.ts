import { test, expect } from '../../helpers/fixtures';
import { attachClientErrorTracking, loginAsDevAdmin } from '../../helpers/auth';

test('admin can generate and delete API token, and use it to fetch sites', async ({ page, request }) => {
  const tracker = await attachClientErrorTracking(page);
  await loginAsDevAdmin(page);

  await page.goto('/admin/settings.php#api-tokens');

  // Generate a token
  await page.locator('input[name="token_name"]').fill('E2E Test Token');
  await page.locator('#api-tokens form').filter({ has: page.locator('input[name="token_name"]') }).getByRole('button').click();

  // Should see the newly generated token
  const tokenEl = page.locator('#newApiToken');
  await expect(tokenEl).toBeVisible();
  const token = await tokenEl.textContent();
  expect(token).toMatch(/^np_[a-f0-9]+$/);

  // Use token via query param
  const respParam = await request.get(`/api/sites.php?token=${token}`);
  expect(respParam.status()).toBe(200);
  const dataParam = await respParam.json();
  expect(dataParam.ok).toBe(true);
  expect(Array.isArray(dataParam.groups)).toBe(true);

  // Use token via Bearer header
  const respHeader = await request.get('/api/sites.php', {
    headers: { 'Authorization': `Bearer ${token}` },
  });
  expect(respHeader.status()).toBe(200);
  const dataHeader = await respHeader.json();
  expect(dataHeader.ok).toBe(true);

  // Invalid token should return 401
  const respInvalid = await request.get('/api/sites.php?token=invalid');
  expect(respInvalid.status()).toBe(401);

  // Delete the token
  await page.goto('/admin/settings.php#api-tokens');
  page.once('dialog', dialog => dialog.accept());
  const deleteForm = page.locator('form').filter({ has: page.locator(`input[value="${token}"]`) });
  await deleteForm.locator('button[type="submit"]').click();
  await page.waitForURL('/admin/settings.php*');

  // After deletion, token should be rejected
  const respAfterDelete = await request.get(`/api/sites.php?token=${token}`);
  expect(respAfterDelete.status()).toBe(401);

  await tracker.assertNoClientErrors();
});
