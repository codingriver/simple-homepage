import { test, expect } from '@playwright/test';
import { attachClientErrorTracking, loginAsDevAdmin, submitVisibleModal } from '../../helpers/auth';

test('homepage supports asset tags favorites pinned filters and recent visits', async ({ page, context }) => {
  test.setTimeout(180000);
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
      /Failed to load resource: the server responded with a status of 404 \(Not Found\)/,
    ],
    ignoredFailedRequests: [/favicon\.php/],
  });
  const ts = Date.now();
  const gid = `asset-group-${ts}`;
  const siteId = `asset-site-${ts}`;
  const siteName = `资产站点 ${ts}`;

  await loginAsDevAdmin(page);

  await page.goto('/admin/groups.php');
  await page.getByRole('button', { name: /添加分组/ }).click();
  await page.locator('#fi_id').fill(gid);
  await page.locator('#fi_name').fill(`资产分组 ${ts}`);
  await page.locator('#fi_auth').selectOption('0');
  await submitVisibleModal(page);

  await page.goto('/admin/sites.php');
  await page.getByRole('button', { name: /添加站点/ }).click();
  await page.locator('#fi_sid').fill(siteId);
  await page.locator('#fi_name').fill(siteName);
  await page.locator('#fi_gid').selectOption(gid);
  await page.locator('#fi_type').selectOption('internal');
  await page.locator('#fi_url').fill('http://127.0.0.1:58080/login.php');
  await page.locator('#fi_desc').fill('资产筛选验证');
  await page.locator('#fi_tags').fill('监控, Docker');
  await page.locator('#fi_asset_type').selectOption('dashboard');
  await page.locator('#fi_env').selectOption('prod');
  await page.locator('#fi_status_badge').selectOption('beta');
  await page.locator('#fi_owner').fill('qa');
  await page.locator('#fi_notes').fill('这是一个资产筛选测试站点');
  await page.locator('#fi_favorite').check({ force: true });
  await page.locator('#fi_pinned').check({ force: true });
  await submitVisibleModal(page);

  await page.goto('/index.php');
  await expect(page.locator('#favoritesSection')).toContainText(siteName);
  await expect(page.locator('#pinnedSection')).toContainText(siteName);

  await page.locator('#filterTag').selectOption('监控');
  await expect(page.locator(`.sec.active .card:has-text("${siteName}")`).first()).toBeVisible();
  await page.locator('#filterEnv').selectOption('prod');
  await page.locator('#filterAssetType').selectOption('dashboard');
  await page.locator('#filterStatusBadge').selectOption('beta');
  await page.getByRole('button', { name: /仅看收藏/ }).click();
  await page.getByRole('button', { name: /仅看常用/ }).click();
  await expect(page.locator(`.sec.active .card:has-text("${siteName}")`).first()).toBeVisible();

  const [popup] = await Promise.all([
    context.waitForEvent('page'),
    page.locator(`.sec.active .card:has-text("${siteName}")`).first().click(),
  ]);
  await popup.close();

  await page.reload();
  await expect(page.locator('#recentSection')).toBeVisible();
  await expect(page.locator('#recentSection')).toContainText(siteName);

  await tracker.assertNoClientErrors();
});
