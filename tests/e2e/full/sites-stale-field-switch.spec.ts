import { test, expect } from '@playwright/test';
import { attachClientErrorTracking, loginAsDevAdmin, submitVisibleModal } from '../../helpers/auth';

test('sites type switching does not leak stale fields across proxy and external modes', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
    ],
  });
  const ts = Date.now();
  const gid = `sites-stale-${ts}`;
  const sid = `sites-stale-site-${ts}`;

  await loginAsDevAdmin(page);
  await page.goto('/admin/groups.php');
  await page.getByRole('button', { name: /添加分组/ }).click();
  await page.locator('#fi_id').fill(gid);
  await page.locator('#fi_name').fill(`站点脏字段 ${ts}`);
  await page.locator('#fi_auth').selectOption('0');
  await submitVisibleModal(page);
  await page.waitForURL(/\/admin\/groups\.php/, { timeout: 15000 });

  await page.goto('/admin/sites.php');
  await page.getByRole('button', { name: /添加站点/ }).click();
  await page.locator('#fi_sid').fill(sid);
  await page.locator('#fi_name').fill(`类型切换站点 ${ts}`);
  await page.locator('#fi_gid').selectOption(gid);
  await page.locator('#fi_type').selectOption('proxy');
  await page.locator('#fi_ptarget').fill('http://192.168.1.188:8080');
  await page.locator('#fi_slug').fill(`stale-slug-${ts}`);
  await page.locator('#fi_pdomain').fill(`stale${ts}.example.test`);
  await page.locator('#fi_type').selectOption('external');
  await page.locator('#fi_url').fill('https://example.com/stale-clean');
  await submitVisibleModal(page);

  const row = page.locator(`tr:has(input[name="sid"][value="${sid}"])`).first();
  await expect(row).toContainText('https://example.com/stale-clean');
  await expect(row).not.toContainText('192.168.1.188');

  const editButton = row.getByRole('button', { name: '编辑' });
  await editButton.scrollIntoViewIfNeeded();
  await editButton.click({ force: true });
  await page.locator('#fi_type').selectOption('proxy');
  await page.locator('#fi_ptarget').fill('http://192.168.1.199:9000');
  await page.locator('#fi_pmode').selectOption('path');
  await page.locator('#fi_slug').fill(`stale-clean-${ts}`);
  await submitVisibleModal(page);
  await expect(row).toContainText('http://192.168.1.199:9000');
  await expect(row).not.toContainText('https://example.com/stale-clean');

  await tracker.assertNoClientErrors();
});
