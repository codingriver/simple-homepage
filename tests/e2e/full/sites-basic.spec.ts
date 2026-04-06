import { test, expect } from '@playwright/test';
import { attachClientErrorTracking, loginAsDevAdmin, submitVisibleModal } from '../../helpers/auth';

test('admin can create an external site and a valid proxy site', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
    ],
    ignoredFailedRequests: [
      /GET .*\/admin\/groups\.php :: net::ERR_ABORTED/,
    ],
  });
  const ts = Date.now();
  const groupId = `site-group-${ts}`;
  const groupName = `站点分组 ${ts}`;
  const externalSite = `site-ext-${ts}`;
  const proxySite = `site-proxy-${ts}`;

  await loginAsDevAdmin(page);

  await page.goto('/admin/groups.php');
  await page.getByRole('button', { name: /添加分组/ }).click();
  await page.locator('#fi_id').fill(groupId);
  await page.locator('#fi_name').fill(groupName);
  await page.locator('#fi_auth').selectOption('0');
  await submitVisibleModal(page);
  const groupRow = page.locator(`tr:has(input[name="gid"][value="${groupId}"])`).first();
  await expect(groupRow).toBeVisible();
  await expect(groupRow).toContainText(groupName);

  await page.goto('/admin/sites.php');
  await page.getByRole('button', { name: /添加站点/ }).click();
  await page.locator('#fi_sid').fill(externalSite);
  await page.locator('#fi_name').fill('外链站点');
  await page.locator('#fi_gid').selectOption(groupId);
  await page.locator('#fi_type').selectOption('external');
  await page.locator('#fi_url').fill('https://example.com');
  await submitVisibleModal(page);
  const externalRow = page.locator(`tr:has(input[name="sid"][value="${externalSite}"])`).first();
  await expect(externalRow).toBeVisible();
  await expect(externalRow).toContainText('外链站点');

  await page.getByRole('button', { name: /添加站点/ }).click();
  await page.locator('#fi_sid').fill(proxySite);
  await page.locator('#fi_name').fill('代理站点');
  await page.locator('#fi_gid').selectOption(groupId);
  await page.locator('#fi_type').selectOption('proxy');
  await page.locator('#fi_ptarget').fill('http://192.168.1.100:8080');
  await page.locator('#fi_slug').fill(`app-proxy-${ts}`);
  await submitVisibleModal(page);
  const proxyRow = page.locator(`tr:has(input[name="sid"][value="${proxySite}"])`).first();
  await expect(proxyRow).toBeVisible();
  await expect(proxyRow).toContainText('代理站点');

  await tracker.assertNoClientErrors();
});
