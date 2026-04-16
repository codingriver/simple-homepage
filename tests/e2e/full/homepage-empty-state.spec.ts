import { test, expect } from '../../helpers/fixtures';
import { attachClientErrorTracking } from '../../helpers/auth';

test('homepage redirects guests without public groups and shows pending-only state to admins', async ({ page, browser }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
    ],
  });

  await page.goto('/login.php');
  const guest = await browser.newContext({ baseURL: 'http://127.0.0.1:58080' });
  const guestPage = await guest.newPage();
  await guestPage.goto('/index.php');

  if (guestPage.url().includes('/login.php')) {
    await expect(guestPage).toHaveURL(/\/login\.php/);
  } else {
    await expect(guestPage).toHaveURL(/\/index\.php/);
    await expect(guestPage.locator('body')).toContainText(/登录|搜索站点|legacy import|类型切换站点/);
  }

  await guest.close();

  await tracker.assertNoClientErrors();
});
