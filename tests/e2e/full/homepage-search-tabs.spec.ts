import { test, expect } from '@playwright/test';
import { attachClientErrorTracking, loginAsDevAdmin, submitVisibleModal } from '../../helpers/auth';

test('homepage search tabs and internal links work with created groups and sites', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
      /Failed to load resource: the server responded with a status of 404 \(Not Found\)/,
    ],
    ignoredFailedRequests: [/favicon\.php/],
  });
  const ts = Date.now();
  const gid1 = `search-group-${ts}`;
  const gid2 = `search-group-b-${ts}`;
  const internalId = `internal-site-${ts}`;

  await loginAsDevAdmin(page);
  await page.goto('/admin/groups.php');
  await page.getByRole('button', { name: /添加分组/ }).click();
  await page.locator('#fi_id').fill(gid1);
  await page.locator('#fi_name').fill(`搜索分组 ${ts}`);
  await page.locator('#fi_auth').selectOption('0');
  await submitVisibleModal(page);

  await page.getByRole('button', { name: /添加分组/ }).click();
  await page.locator('#fi_id').fill(gid2);
  await page.locator('#fi_name').fill(`第二分组 ${ts}`);
  await page.locator('#fi_auth').selectOption('0');
  await submitVisibleModal(page);

  await page.goto('/admin/sites.php');
  await page.getByRole('button', { name: /添加站点/ }).click();
  await page.locator('#fi_sid').fill(internalId);
  await page.locator('#fi_name').fill('内网站点');
  await page.locator('#fi_gid').selectOption(gid1);
  await page.locator('#fi_type').selectOption('internal');
  await page.locator('#fi_url').fill('http://127.0.0.1:58080/login.php');
  await page.locator('#fi_desc').fill('搜索描述关键字');
  await submitVisibleModal(page);

  await page.goto('/index.php');
  await page.locator('#searchToggle').click();
  await expect(page.locator('#searchPanel')).toBeVisible();
  await page.locator('#sq').fill('搜索描述关键字');
  await expect(page.locator('#searchMeta')).toContainText('找到');
  await expect(page.locator('.card:has-text("内网站点") .group-chip')).toContainText(`搜索分组 ${ts}`);

  await page.locator('.na', { hasText: `第二分组 ${ts}` }).click();
  await expect(page.locator(`#g-${gid2}`)).toHaveClass(/active/);

  const href = await page.locator('.card:has-text("内网站点")').first().getAttribute('href');
  expect(href).toContain('/login.php');
  expect(href).toContain('_nav_token=');

  await page.locator('#searchClose').click();
  await expect(page.locator('#searchPanel')).toBeHidden();

  await tracker.assertNoClientErrors();
});
