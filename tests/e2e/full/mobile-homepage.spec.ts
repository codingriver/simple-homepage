import { test, expect } from '@playwright/test';
import {
  attachClientErrorTracking,
  clickAdminNav,
  ensureAdminSidebarOpen,
  loginAsDevAdmin,
  submitVisibleModal,
} from '../../helpers/auth';

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
  const secondGroupId = `mobile-group-extra-${ts}`;
  const siteId = `mobile-site-${ts}`;
  const secondSiteId = `mobile-site-extra-${ts}`;
  const groupName = `移动分组 ${ts}`;
  const secondGroupName = `移动切换分组 ${ts}`;
  const siteName = `移动站点 ${ts}`;
  const secondSiteName = `移动次站点 ${ts}`;

  await loginAsDevAdmin(page);

  await page.goto('/admin/groups.php');
  await page.getByRole('button', { name: /添加分组/ }).click();
  await page.locator('#fi_id').fill(groupId);
  await page.locator('#fi_name').fill(groupName);
  await page.locator('#fi_auth').selectOption('0');
  await submitVisibleModal(page);
  await expect(page.locator(`tr:has(input[name="gid"][value="${groupId}"])`).first()).toBeVisible();

  await page.getByRole('button', { name: /添加分组/ }).click();
  await page.locator('#fi_id').fill(secondGroupId);
  await page.locator('#fi_name').fill(secondGroupName);
  await page.locator('#fi_auth').selectOption('0');
  await submitVisibleModal(page);
  await expect(page.locator(`tr:has(input[name="gid"][value="${secondGroupId}"])`).first()).toBeVisible();

  await page.goto('/admin/sites.php');
  await page.getByRole('button', { name: /添加站点/ }).click();
  await page.locator('#fi_sid').fill(siteId);
  await page.locator('#fi_name').fill(siteName);
  await page.locator('#fi_gid').selectOption(groupId);
  await page.locator('#fi_type').selectOption('external');
  await page.locator('#fi_url').fill('https://example.com/mobile');
  await page.locator('#fi_desc').fill('移动端布局校验');
  await submitVisibleModal(page);

  await page.getByRole('button', { name: /添加站点/ }).click();
  await page.locator('#fi_sid').fill(secondSiteId);
  await page.locator('#fi_name').fill(secondSiteName);
  await page.locator('#fi_gid').selectOption(secondGroupId);
  await page.locator('#fi_type').selectOption('external');
  await page.locator('#fi_url').fill('https://example.com/mobile-second');
  await page.locator('#fi_desc').fill('移动端切换测试');
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

  await page.locator('#sq').fill('no-such-mobile-keyword');
  await expect(page.locator('#searchMeta')).toContainText('没有找到匹配结果');

  await page.locator('#sq').fill('');
  await expect(page.locator('#searchMeta')).toContainText('输入关键词');
  await page.locator('#searchClose').click();
  await expect(page.locator('body')).not.toHaveClass(/search-open/);
  await expect(page.locator('#searchPanel')).toBeHidden();

  await page.locator(`.na[data-tab="g-${secondGroupId}"]`).click();
  await expect(page.locator(`#g-${secondGroupId}`)).toHaveClass(/active/);
  await expect(page.locator('.card', { hasText: secondSiteName }).first()).toBeVisible();

  const [popup] = await Promise.all([
    page.waitForEvent('popup'),
    page.locator('.card', { hasText: secondSiteName }).first().click(),
  ]);
  await expect(popup).toHaveURL(/example\.com\/mobile-second/);
  await popup.close();

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

test('mobile admin flows keep sidebar navigation modal forms and long settings form usable', async ({ page, isMobile }) => {
  test.skip(!isMobile, 'This spec is intended for the mobile project.');

  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
    ],
    ignoredFailedRequests: [
      /favicon\.php/,
      /GET .*\/admin\/settings_ajax\.php\?action=nginx_sudo :: net::ERR_ABORTED/,
    ],
  });
  const ts = Date.now();
  const groupId = `mobile-admin-group-${ts}`;
  const siteId = `mobile-admin-site-${ts}`;
  const siteName = `移动后台站点 ${ts}`;
  const siteTitle = `移动设置标题 ${ts}`;

  await loginAsDevAdmin(page);
  await page.goto('/admin/index.php');

  await clickAdminNav(page, /分组管理/);
  await expect(page).toHaveURL(/admin\/groups\.php/);
  await page.getByRole('button', { name: /添加分组/ }).click();
  await expect(page.locator('#modal')).toBeVisible();
  await page.locator('#fi_id').fill(groupId);
  await page.locator('#fi_name').fill(`移动后台分组 ${ts}`);
  await page.locator('#fi_auth').selectOption('0');
  await submitVisibleModal(page);
  const groupRow = page.locator(`tr:has(input[name="gid"][value="${groupId}"])`).first();
  await expect(groupRow).toBeVisible();

  await clickAdminNav(page, /站点管理/);
  await expect(page).toHaveURL(/admin\/sites\.php/);
  await page.getByRole('button', { name: /添加站点/ }).click();
  await expect(page.locator('#modal')).toBeVisible();
  await page.locator('#fi_sid').fill(siteId);
  await page.locator('#fi_name').fill(siteName);
  await page.locator('#fi_gid').selectOption(groupId);
  await page.locator('#fi_type').selectOption('proxy');
  await expect(page.locator('#proxy_fields')).toBeVisible();
  await page.locator('#fi_ptarget').fill('http://192.168.1.88:8080');
  await page.locator('#fi_slug').fill(`mobile-proxy-${ts}`);
  await page.locator('#fi_type').selectOption('external');
  await expect(page.locator('#proxy_fields')).toBeHidden();
  await page.locator('#fi_url').fill('https://example.com/mobile-admin');
  await page.locator('#fi_desc').fill('移动后台表单滚动校验');
  await submitVisibleModal(page);
  const siteRow = page.locator(`tr:has(input[name="sid"][value="${siteId}"])`).first();
  await expect(siteRow).toBeVisible();
  await expect(siteRow).toContainText(siteName);

  await clickAdminNav(page, /系统设置/);
  await expect(page).toHaveURL(/admin\/settings\.php/);
  await page.locator('input[name="site_name"]').fill(siteTitle);
  await page.locator('input[name="bg_color"]').fill('#203040');
  await page.locator('select[name="card_layout"]').selectOption('list');
  await page.locator('select[name="card_direction"]').selectOption('row');
  const saveButton = page.getByRole('button', { name: /保存设置/ });
  await saveButton.scrollIntoViewIfNeeded();
  await saveButton.click();
  await expect(page.locator('body')).toContainText('设置已保存');
  await page.reload();
  await expect(page.locator('input[name="site_name"]')).toHaveValue(siteTitle);

  await clickAdminNav(page, /控制台/);
  await expect(page).toHaveURL(/admin\/index\.php/);
  await ensureAdminSidebarOpen(page);
  await expect(page.locator('.quick-actions')).toBeVisible();

  await tracker.assertNoClientErrors();
});

