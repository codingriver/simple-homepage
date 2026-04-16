import { test, expect } from '../../helpers/fixtures';
import { attachClientErrorTracking, loginAsDevAdmin } from '../../helpers/auth';

test('admin can create and delete a group via modal ajax', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
    ],
  });
  const gid = `e2e-group-${Date.now()}`;
  const name = `E2E 分组 ${Date.now()}`;

  await loginAsDevAdmin(page);
  await page.goto('/admin/groups.php');
  await page.getByRole('button', { name: /添加分组/ }).click();

  await page.locator('#fi_id').fill(gid);
  await page.locator('#fi_name').fill(name);
  await page.locator('#fi_order').fill('11');
  await page.locator('#fi_vis').selectOption('all');
  await page.locator('#fi_auth').selectOption('0');
  await page.getByRole('button', { name: '保存' }).click({ force: true });

  const row = page.locator(`tr:has(input[name="gid"][value="${gid}"])`).first();
  await expect(row).toContainText(name);
  await expect(row).toContainText('公开');

  page.once('dialog', dialog => dialog.accept());
  await row.getByRole('button', { name: '删除' }).click({ force: true });
  await expect(page.locator(`tr:has(input[name="gid"][value="${gid}"])`)).toHaveCount(0);

  await tracker.assertNoClientErrors();
});
