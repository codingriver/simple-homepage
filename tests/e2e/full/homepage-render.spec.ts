import { test, expect } from '../../helpers/fixtures';
import { attachClientErrorTracking, loginAsDevAdmin, logout, submitVisibleModal } from '../../helpers/auth';

test('homepage renders public groups and reflects admin-only visibility after login', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
    ],
    ignoredFailedRequests: [
      /favicon\.php/,
      /GET .*\/admin\/groups\.php :: net::ERR_ABORTED/,
    ],
  });
  const ts = Date.now();
  const publicGroupId = `public-group-${ts}`;
  const adminGroupId = `admin-group-${ts}`;
  const publicName = `公开分组 ${ts}`;
  const adminName = `管理分组 ${ts}`;
  const publicSiteId = `public-site-${ts}`;
  const adminSiteId = `admin-site-${ts}`;

  await loginAsDevAdmin(page);

  await page.goto('/admin/groups.php');
  await page.getByRole('button', { name: /添加分组/ }).click();
  await page.locator('#fi_id').fill(publicGroupId);
  await page.locator('#fi_name').fill(publicName);
  await page.locator('#fi_vis').selectOption('all');
  await page.locator('#fi_auth').selectOption('0');
  await submitVisibleModal(page);

  await page.getByRole('button', { name: /添加分组/ }).click();
  await page.locator('#fi_id').fill(adminGroupId);
  await page.locator('#fi_name').fill(adminName);
  await page.locator('#fi_vis').selectOption('admin');
  await page.locator('#fi_auth').selectOption('1');
  await submitVisibleModal(page);
  await expect(page.locator(`tr:has(input[name="gid"][value="${adminGroupId}"])`).first()).toBeVisible();

  await page.goto('/admin/sites.php');
  await page.getByRole('button', { name: /添加站点/ }).click();
  await page.locator('#fi_sid').fill(publicSiteId);
  await page.locator('#fi_name').fill('公开站点');
  await page.locator('#fi_gid').selectOption(publicGroupId);
  await page.locator('#fi_type').selectOption('external');
  await page.locator('#fi_url').fill('https://example.com/public');
  await submitVisibleModal(page);

  await page.getByRole('button', { name: /添加站点/ }).click();
  await page.locator('#fi_sid').fill(adminSiteId);
  await page.locator('#fi_name').fill('管理员站点');
  await page.locator('#fi_gid').selectOption(adminGroupId);
  await page.locator('#fi_type').selectOption('external');
  await page.locator('#fi_url').fill('https://example.com/admin');
  await submitVisibleModal(page);

  await logout(page);
  await page.goto('/index.php');
  await expect(page.locator('body')).toContainText(publicName);
  await expect(page.locator('body')).toContainText('公开站点');
  await expect(page.locator('body')).not.toContainText(adminName);
  await expect(page.locator('body')).not.toContainText('管理员站点');

  await loginAsDevAdmin(page);
  await page.goto('/index.php');
  await expect(page.locator('body')).toContainText(publicName);
  await expect(page.locator('body')).toContainText(adminName);
  await expect(page.locator('body')).toContainText('公开站点');
  await expect(page.locator('body')).toContainText('管理员站点');

  await tracker.assertNoClientErrors();
});
