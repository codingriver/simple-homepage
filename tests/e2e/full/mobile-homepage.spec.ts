import { test, expect } from '@playwright/test';
import { attachClientErrorTracking, ensureAdminSidebarOpen, loginAsDevAdmin, submitVisibleModal } from '../../helpers/auth';

test('homepage and admin dashboard remain usable on mobile viewport', async ({ page, isMobile }) => {
  test.skip(!isMobile, 'This spec is intended for the mobile project.');

  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
    ],
    ignoredFailedRequests: [
      /favicon\.php/,
    ],
  });
  const ts = Date.now();
  const groupId = `mobile-group-${ts}`;
  const siteId = `mobile-site-${ts}`;
  const groupName = `移动分组 ${ts}`;
  const siteName = `移动站点 ${ts}`;

  await loginAsDevAdmin(page);

  await page.goto('/admin/groups.php');
  await page.getByRole('button', { name: /添加分组/ }).click();
  await page.locator('#fi_id').fill(groupId);
  await page.locator('#fi_name').fill(groupName);
  await page.locator('#fi_auth').selectOption('0');
  await submitVisibleModal(page);
  await expect(page.locator(`tr:has(input[name="gid"][value="${groupId}"])`).first()).toBeVisible();

  await page.goto('/admin/sites.php');
  await page.getByRole('button', { name: /添加站点/ }).click();
  await page.locator('#fi_sid').fill(siteId);
  await page.locator('#fi_name').fill(siteName);
  await page.locator('#fi_gid').selectOption(groupId);
  await page.locator('#fi_type').selectOption('external');
  await page.locator('#fi_url').fill('https://example.com/mobile');
  await page.locator('#fi_desc').fill('移动端布局校验');
  await submitVisibleModal(page);

  await page.goto('/index.php');
  await expect(page.locator('header')).toBeVisible();
  await expect(page.locator('.nav-bar')).toBeVisible();
  await expect(page.locator('#searchPanel')).toBeHidden();

  const card = page.locator('.card', { hasText: siteName }).first();
  await expect(card).toBeVisible();
  await expect(card).toHaveAttribute('href', 'https://example.com/mobile');
  await expect(card.locator('.cd')).toContainText('移动端布局校验');

  await page.locator('#searchToggle').click();
  await expect(page.locator('body')).toHaveClass(/search-open/);
  await expect(page.locator('#searchPanel')).toBeVisible();
  await page.locator('#sq').fill(siteName);
  await expect(page.locator('#searchMeta')).toContainText('找到');
  await expect(card.locator('.group-chip')).toContainText(groupName);

  await page.locator('#searchClose').click();
  await expect(page.locator('body')).not.toHaveClass(/search-open/);
  await expect(page.locator('#searchPanel')).toBeHidden();

  await page.goto('/admin/index.php');
  await ensureAdminSidebarOpen(page);
  await expect(page.locator('.stat-grid')).toBeVisible();
  await expect(page.locator('.quick-actions')).toBeVisible();

  const quickActions = page.locator('.quick-actions .quick-action');
  await expect(quickActions).toHaveCount(5);

  const firstBox = await quickActions.nth(0).boundingBox();
  const lastBox = await quickActions.nth(4).boundingBox();
  expect(firstBox).not.toBeNull();
  expect(lastBox).not.toBeNull();
  expect(lastBox!.width).toBeGreaterThan(firstBox!.width * 1.5);

  await tracker.assertNoClientErrors();
});
