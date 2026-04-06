import { test, expect } from '@playwright/test';
import { attachClientErrorTracking, loginAsDevAdmin, submitVisibleModal } from '../../helpers/auth';

test('frontend and backend stay in sync after creating content and changing settings', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
    ],
    ignoredFailedRequests: [
      /GET .*\/admin\/groups\.php :: net::ERR_ABORTED/,
      /GET .*\/admin\/settings_ajax\.php\?action=nginx_sudo :: net::ERR_ABORTED/,
    ],
  });
  const ts = Date.now();
  const gid = `sync-group-${ts}`;
  const sid = `sync-site-${ts}`;
  const title = `同步标题 ${ts}`;

  await loginAsDevAdmin(page);

  await page.goto('/admin/groups.php');
  await page.getByRole('button', { name: /添加分组/ }).click();
  await page.locator('#fi_id').fill(gid);
  await page.locator('#fi_name').fill(`同步分组 ${ts}`);
  await page.locator('#fi_auth').selectOption('0');
  await submitVisibleModal(page);

  await page.goto('/admin/sites.php');
  await page.getByRole('button', { name: /添加站点/ }).click();
  await page.locator('#fi_sid').fill(sid);
  await page.locator('#fi_name').fill('同步站点');
  await page.locator('#fi_gid').selectOption(gid);
  await page.locator('#fi_type').selectOption('external');
  await page.locator('#fi_url').fill('https://example.com/sync');
  await submitVisibleModal(page);

  await page.goto('/admin/settings.php');
  await page.locator('input[name="site_name"]').fill(title);
  await page.getByRole('button', { name: /保存设置/ }).click();
  await expect(page.locator('body')).toContainText('设置已保存');

  await page.goto('/index.php');
  await expect(page).toHaveTitle(new RegExp(title));
  await expect(page.locator('body')).toContainText(`同步分组 ${ts}`);
  await expect(page.locator('body')).toContainText('同步站点');

  await page.goto('/admin/groups.php');
  const groupRow = page.locator(`tr:has(input[name="gid"][value="${gid}"])`).first();
  page.once('dialog', dialog => dialog.accept());
  await groupRow.getByRole('button', { name: '删除' }).click({ force: true });

  await page.goto('/index.php');
  await expect(page.locator('body')).not.toContainText('同步站点');

  await tracker.assertNoClientErrors();
});
