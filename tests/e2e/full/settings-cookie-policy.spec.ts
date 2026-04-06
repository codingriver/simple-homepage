import { test, expect } from '@playwright/test';
import { attachClientErrorTracking, loginAsDevAdmin } from '../../helpers/auth';

async function readSessionCookie(page: Parameters<typeof attachClientErrorTracking>[0]) {
  const cookies = await page.context().cookies();
  return cookies.find(cookie => cookie.name === 'nav_session');
}

test('cookie policy settings persist and affect session cookie flags', async ({ browser }) => {
  const adminContext = await browser.newContext({ baseURL: 'http://127.0.0.1:58080' });
  const adminPage = await adminContext.newPage();
  const tracker = await attachClientErrorTracking(adminPage, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
    ],
  });

  await loginAsDevAdmin(adminPage);
  await adminPage.goto('/admin/settings.php');

  const originalPolicy = await adminPage.locator('select[name="cookie_secure"]').inputValue();
  const originalDomain = await adminPage.locator('input[name="cookie_domain"]').inputValue();

  await adminPage.locator('select[name="cookie_secure"]').selectOption('on');
  await adminPage.locator('input[name="cookie_domain"]').fill('.example.test');
  await adminPage.getByRole('button', { name: /保存设置/ }).click();
  await expect(adminPage.locator('body')).toContainText('设置已保存');

  await adminPage.reload();
  await expect(adminPage.locator('select[name="cookie_secure"]')).toHaveValue('on');
  await expect(adminPage.locator('input[name="cookie_domain"]')).toHaveValue('.example.test');

  await adminContext.close();

  const ipContext = await browser.newContext({
    baseURL: 'http://127.0.0.1:58080',
  });
  const ipPage = await ipContext.newPage();
  const ipTracker = await attachClientErrorTracking(ipPage, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
    ],
  });

  await ipPage.goto('/login.php');
  await ipPage.locator('input[name="username"]').fill('qatest');
  await ipPage.locator('input[name="password"]').fill('qatest2026');
  await ipPage.getByRole('button', { name: /登\s*录/ }).click();
  await expect(ipPage).toHaveURL(/index\.php|\/$/);

  const ipCookie = await readSessionCookie(ipPage);
  expect(ipCookie).toBeTruthy();
  expect(ipCookie?.secure).toBeFalsy();
  expect(ipCookie?.domain === '127.0.0.1' || ipCookie?.domain === undefined || ipCookie?.domain === '').toBeTruthy();

  await ipContext.close();

  const resetContext = await browser.newContext({ baseURL: 'http://127.0.0.1:58080' });
  const resetPage = await resetContext.newPage();
  await loginAsDevAdmin(resetPage);
  await resetPage.goto('/admin/settings.php');
  await resetPage.locator('select[name="cookie_secure"]').selectOption(originalPolicy);
  await resetPage.locator('input[name="cookie_domain"]').fill(originalDomain);
  await resetPage.getByRole('button', { name: /保存设置/ }).click();
  await expect(resetPage.locator('body')).toContainText('设置已保存');

  await tracker.assertNoClientErrors();
  await ipTracker.assertNoClientErrors();

  await resetContext.close();
});
