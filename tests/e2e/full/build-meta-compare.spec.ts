import { test, expect } from '../../helpers/fixtures';
import { attachClientErrorTracking, loginAsDevAdmin } from '../../helpers/auth';

test('build metadata compare hint reacts to mocked GitHub API results', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
    ],
  });

  await page.route('**/admin/debug.php?ajax=github_main_commit', async (route) => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ ok: true, sha: '1234567890abcdef1234567890abcdef12345678' }),
    });
  });

  await loginAsDevAdmin(page);
  await page.goto('/admin/debug.php');
  const raw = await page.locator('#nav-build-info-json').count();
  if (raw === 0) {
    await expect(page.locator('#build-meta')).toBeVisible();
    await tracker.assertNoClientErrors();
    return;
  }

  await page.waitForTimeout(500);
  await expect(page.locator('#gh-compare-hint')).toBeVisible();

  await tracker.assertNoClientErrors();
});
