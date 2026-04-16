import { test, expect } from '../../helpers/fixtures';
import { attachClientErrorTracking, loginAsDevAdmin } from '../../helpers/auth';

test('admin sees bookmarklet and can use bookmarklet callback to prefill site form', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page);
  await loginAsDevAdmin(page);

  await page.goto('/admin/sites.php');

  // Verify bookmarklet link exists and has valid javascript: href
  const bmLink = page.locator('#bookmarkletLink');
  await expect(bmLink).toBeVisible();
  const href = await bmLink.getAttribute('href');
  expect(href).toMatch(/^javascript:/);

  // Verify bookmarklet code input is populated
  const bmCode = page.locator('#bookmarkletCode');
  await expect(bmCode).toBeVisible();
  const code = await bmCode.inputValue();
  expect(code).toContain('bookmarklet=1');

  // Simulate bookmarklet callback by navigating with query params
  await page.goto('/admin/sites.php?bookmarklet=1&title=Example%20Site&url=https%3A%2F%2Fexample.com');

  // Modal should be open with prefilled values
  await expect(page.locator('#modal')).toBeVisible();
  await expect(page.locator('#fi_name')).toHaveValue('Example Site');
  await expect(page.locator('#fi_url')).toHaveValue('https://example.com');
  await expect(page.locator('#fi_type')).toHaveValue('external');

  await tracker.assertNoClientErrors();
});
