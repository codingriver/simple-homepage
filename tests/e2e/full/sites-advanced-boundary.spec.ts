import { test, expect } from '../../helpers/fixtures';
import { attachClientErrorTracking, loginAsDevAdmin, submitVisibleModal } from '../../helpers/auth';

test('sites advanced boundary flows cover mode switch long values and group cascade consistency', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
    ],
    ignoredFailedRequests: [/favicon\.php/],
  });
  const ts = Date.now();
  const gid = `sites-adv-${ts}`;
  const sid = `sites-adv-site-${ts}`;
  const longName = `站点边界 ${'长'.repeat(30)} ${ts}`;

  await loginAsDevAdmin(page);
  await page.goto('/admin/groups.php');
  await page.getByRole('button', { name: /添加分组/ }).click();
  await page.locator('#fi_id').fill(gid);
  await page.locator('#fi_name').fill(`站点高级分组 ${ts}`);
  await page.locator('#fi_auth').selectOption('0');
  await submitVisibleModal(page);

  await page.goto('/admin/sites.php');
  await page.getByRole('button', { name: /添加站点/ }).click();
  await page.locator('#fi_sid').fill(sid);
  await page.locator('#fi_name').fill(longName);
  await page.locator('#fi_gid').selectOption(gid);
  await page.locator('#fi_type').selectOption('proxy');
  await page.locator('#fi_pmode').selectOption('path');
  await page.locator('#fi_ptarget').fill('http://192.168.1.150:8080');
  await page.locator('#fi_slug').fill(`sites-adv-slug-${ts}`);
  await submitVisibleModal(page);

  const row = page.locator(`tr:has(input[name="sid"][value="${sid}"])`).first();
  await expect(row).toContainText(longName);
  await row.getByRole('button', { name: '编辑' }).click({ force: true });
  await page.locator('#fi_pmode').selectOption('domain', { force: true });
  await page.locator('#fi_pdomain').fill(`app${ts}.example.test`);
  await submitVisibleModal(page);
  await expect(row).toContainText('http://192.168.1.150:8080');

  await page.goto('/admin/groups.php');
  const groupRow = page.locator(`tr:has(input[name="gid"][value="${gid}"])`).first();
  page.once('dialog', (dialog) => dialog.accept());
  await groupRow.getByRole('button', { name: '删除' }).click({ force: true });

  await page.goto('/admin/sites.php');
  await expect(page.locator(`tr:has(input[name="sid"][value="${sid}"])`)).toHaveCount(0);

  await tracker.assertNoClientErrors();
});
