import { test, expect } from '@playwright/test';
import { attachClientErrorTracking, loginAsDevAdmin, submitVisibleModal } from '../../helpers/auth';

test('admin can edit and delete a site', async ({ page }) => {
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
  const groupId = `site-edit-group-${ts}`;
  const siteId = `site-edit-${ts}`;
  const updatedName = `已编辑站点 ${ts}`;
  const updatedUrl = 'https://example.org';

  await loginAsDevAdmin(page);

  await page.goto('/admin/groups.php');
  await page.getByRole('button', { name: /添加分组/ }).click();
  await page.locator('#fi_id').fill(groupId);
  await page.locator('#fi_name').fill(`编辑删除分组 ${ts}`);
  await page.locator('#fi_auth').selectOption('0');
  await submitVisibleModal(page);
  await expect(page.locator(`tr:has(input[name="gid"][value="${groupId}"])`).first()).toBeVisible();

  await page.goto('/admin/sites.php');
  await page.getByRole('button', { name: /添加站点/ }).click();
  await page.locator('#fi_sid').fill(siteId);
  await page.locator('#fi_name').fill('原始站点');
  await page.locator('#fi_gid').selectOption(groupId);
  await page.locator('#fi_type').selectOption('external');
  await page.locator('#fi_url').fill('https://example.com');
  await submitVisibleModal(page);

  const row = page.locator(`tr:has(input[name="sid"][value="${siteId}"])`).first();
  await expect(row).toContainText('原始站点');

  await row.getByRole('button', { name: '编辑' }).click();
  await page.locator('#fi_name').fill(updatedName);
  await page.locator('#fi_url').fill(updatedUrl);
  await submitVisibleModal(page);

  await expect(row).toContainText(updatedName);
  await expect(row).toContainText(updatedUrl);

  page.once('dialog', dialog => dialog.accept());
  await row.locator('xpath=.//form//button[contains(normalize-space(.), "删除")]').click();
  await expect(page.locator(`tr:has(input[name="sid"][value="${siteId}"])`)).toHaveCount(0);

  await tracker.assertNoClientErrors();
});
