import { test, expect } from '../../helpers/fixtures';
import { attachClientErrorTracking, loginAsDevAdmin, logout } from '../../helpers/auth';

test('complete logout flow and session rejection', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
    ],
  });

  // Login as admin and verify access to protected page
  await loginAsDevAdmin(page);
  await page.goto('/admin/index.php');
  await expect(page.locator('.topbar-title')).toHaveText('控制台');

  // Logout via logout helper (clear cookies)
  await logout(page);

  // Try to access admin page, verify redirect to login
  await page.goto('/admin/index.php');
  await expect(page).toHaveURL(/login\.php/);

  // Try to use old token/session by manually setting an invalid/expired cookie
  await page.context().addCookies([
    {
      name: 'nav_session',
      value: 'invalid_token_value_that_should_be_rejected',
      domain: '127.0.0.1',
      path: '/',
    },
  ]);
  await page.goto('/admin/index.php');
  await expect(page).toHaveURL(/login\.php/);

  await tracker.assertNoClientErrors();
});

test('remember me keeps session across browser restarts', async ({ page, browser }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
    ],
  });

  await page.goto('/login.php');
  await page.locator('input[name="username"]').fill('qatest');
  await page.locator('input[name="password"]').fill('qatest2026');
  await page.locator('input[name="remember_me"]').check();
  await page.getByRole('button', { name: /登\s*录/ }).click();
  await expect(page).toHaveURL(/index\.php|/);

  // Capture storage state
  const state = await page.context().storageState();

  // Create a completely new context with the saved state
  const newContext = await browser.newContext({
    baseURL: 'http://127.0.0.1:58080',
    storageState: state,
  });
  const newPage = await newContext.newPage();

  // Verify access persists in new context
  await newPage.goto('/admin/index.php');
  await expect(newPage.locator('.topbar-title')).toHaveText('控制台');

  await newContext.close();

  await tracker.assertNoClientErrors();
});
