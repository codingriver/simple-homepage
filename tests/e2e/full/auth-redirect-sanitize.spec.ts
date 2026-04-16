import { test, expect } from '../../helpers/fixtures';
import { attachClientErrorTracking } from '../../helpers/auth';

test('login redirect sanitizes external absolute urls down to safe local path', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
    ],
  });

  await page.goto('/login.php?redirect=https://evil.example.com/phish');
  await expect(page.locator('input[name="redirect"]')).toHaveValue('/phish');

  await page.locator('input[name="username"]').fill('qatest');
  await page.locator('input[name="password"]').fill('qatest2026');
  await page.getByRole('button', { name: /登\s*录/ }).click();
  await expect(page).toHaveURL(/\/phish$|index\.php|\/$/);

  await tracker.assertNoClientErrors();
});
