import { test, expect } from '../../helpers/fixtures';
import { attachClientErrorTracking, ensureSetup } from '../../helpers/auth';

test('first install flow works when setup is available', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 404 \(Not Found\)/,
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
    ],
  });
  const didSetup = await ensureSetup(page, 'Playwright Setup');

  if (didSetup) {
    await page.goto('/setup.php');
    await expect(page.locator('body')).toContainText('404 Not Found');
  }

  await tracker.assertNoClientErrors();
});
