import { test, expect } from '../../helpers/fixtures';
import { attachClientErrorTracking, loginAsDevAdmin } from '../../helpers/auth';

test('docker hosts page switches tabs and filters containers via frontend', async ({ page }) => {
  test.setTimeout(120000);
  const tracker = await attachClientErrorTracking(page);

  await loginAsDevAdmin(page);
  await page.goto('/admin/docker_hosts.php');

  // wait for at least one tab button
  await expect(page.locator('button.docker-tab-btn').first()).toBeVisible();

  // switch to images tab
  await page.locator('button.docker-tab-btn[data-tab="images"]').click();
  await expect(page.locator('#docker-tab-images')).toBeVisible();

  // switch to volumes tab
  await page.locator('button.docker-tab-btn[data-tab="volumes"]').click();
  await expect(page.locator('#docker-tab-volumes')).toBeVisible();

  // switch to networks tab
  await page.locator('button.docker-tab-btn[data-tab="networks"]').click();
  await expect(page.locator('#docker-tab-networks')).toBeVisible();

  // back to containers and use keyword filter (frontend)
  await page.locator('button.docker-tab-btn[data-tab="containers"]').click();
  await expect(page.locator('#docker-tab-containers')).toBeVisible();
  await page.locator('#docker-container-keyword').fill('zzzzzz-not-exist');
  // give JS a tick to filter
  await page.waitForTimeout(200);
  const visibleRows = page.locator('#docker-containers-tbody tr:visible');
  await expect(visibleRows).toHaveCount(0);

  await tracker.assertNoClientErrors();
});
