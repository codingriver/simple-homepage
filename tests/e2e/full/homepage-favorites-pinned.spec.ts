import { test, expect } from '../../helpers/fixtures';
import { attachClientErrorTracking, loginAsDevAdmin, submitVisibleModal } from '../../helpers/auth';

test('homepage favorites pinned tab persistence and search empty state', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
      /Failed to load resource: the server responded with a status of 404 \(Not Found\)/,
    ],
    ignoredFailedRequests: [/favicon\.php/],
  });
  const ts = Date.now();
  const gid = `fav-group-${ts}`;
  const favSid = `fav-site-${ts}`;
  const pinSid = `pin-site-${ts}`;
  const normalSid = `normal-site-${ts}`;

  await loginAsDevAdmin(page);

  // Create group
  await page.goto('/admin/groups.php');
  await page.getByRole('button', { name: /添加分组/ }).click();
  await page.locator('#fi_id').fill(gid);
  await page.locator('#fi_name').fill(`收藏分组 ${ts}`);
  await page.locator('#fi_auth').selectOption('0');
  await submitVisibleModal(page);

  // Create favorite site
  await page.goto('/admin/sites.php');
  await page.getByRole('button', { name: /添加站点/ }).click();
  await page.locator('#fi_sid').fill(favSid);
  await page.locator('#fi_name').fill(`收藏站点 ${ts}`);
  await page.locator('#fi_gid').selectOption(gid);
  await page.locator('#fi_type').selectOption('external');
  await page.locator('#fi_url').fill('https://example.com/fav');
  await page.locator('#fi_favorite').check();
  await submitVisibleModal(page);

  // Create pinned site
  await page.getByRole('button', { name: /添加站点/ }).click();
  await page.locator('#fi_sid').fill(pinSid);
  await page.locator('#fi_name').fill(`常用站点 ${ts}`);
  await page.locator('#fi_gid').selectOption(gid);
  await page.locator('#fi_type').selectOption('external');
  await page.locator('#fi_url').fill('https://example.com/pin');
  await page.locator('#fi_pinned').check();
  await submitVisibleModal(page);

  // Create normal site
  await page.getByRole('button', { name: /添加站点/ }).click();
  await page.locator('#fi_sid').fill(normalSid);
  await page.locator('#fi_name').fill(`普通站点 ${ts}`);
  await page.locator('#fi_gid').selectOption(gid);
  await page.locator('#fi_type').selectOption('external');
  await page.locator('#fi_url').fill('https://example.com/normal');
  await submitVisibleModal(page);

  // Visit homepage and verify favorites section
  await page.goto('/index.php');
  await expect(page.locator('#favoritesSection')).toBeVisible();
  await expect(page.locator('#favoritesSection')).toContainText(`收藏站点 ${ts}`);
  await expect(page.locator('#pinnedSection')).toBeVisible();
  await expect(page.locator('#pinnedSection')).toContainText(`常用站点 ${ts}`);

  // Unfavorite via admin
  await page.goto('/admin/sites.php');
  const favRow = page.locator(`tr:has-text("收藏站点 ${ts}")`).first();
  await favRow.locator('button', { hasText: /编辑/ }).click();
  await expect(page.locator('#modal')).toBeVisible();
  await page.locator('#fi_favorite').uncheck();
  await submitVisibleModal(page);

  // Unpin via admin
  const pinRow = page.locator(`tr:has-text("常用站点 ${ts}")`).first();
  await pinRow.locator('button', { hasText: /编辑/ }).click();
  await expect(page.locator('#modal')).toBeVisible();
  await page.locator('#fi_pinned').uncheck();
  await submitVisibleModal(page);

  // Verify removal on homepage
  await page.goto('/index.php');
  await expect(page.locator('#favoritesSection')).toHaveCount(0);
  await expect(page.locator('#pinnedSection')).toHaveCount(0);

  // Tab persistence: switch to a tab, refresh, verify same tab
  await page.goto('/index.php');
  // If there are multiple tabs, click the second one
  const tabs = page.locator('.na[data-tab]');
  if (await tabs.count() > 1) {
    const secondTab = tabs.nth(1);
    const tabId = await secondTab.getAttribute('data-tab');
    await secondTab.click();
    await expect(page.locator(`#${tabId}`)).toHaveClass(/active/);
    await page.reload();
    await expect(page.locator(`#${tabId}`)).toHaveClass(/active/);
  }

  // Search empty state
  await page.locator('#searchToggle').click();
  await expect(page.locator('#searchPanel')).toBeVisible();
  await page.locator('#sq').fill('no-such-keyword-xyz-99999');
  await expect(page.locator('#searchMeta')).toContainText(/没有找到匹配结果|0/);

  await tracker.assertNoClientErrors();
});
