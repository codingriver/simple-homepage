import { test, expect } from '@playwright/test';
import { attachClientErrorTracking, loginAsDevAdmin, submitVisibleModal } from '../../helpers/auth';

test('large dataset ui remains usable with many groups and sites', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
    ],
    ignoredFailedRequests: [/favicon\.php/],
  });
  const ts = Date.now();
  const groups = Array.from({ length: 8 }, (_, i) => ({ id: `large-group-${ts}-${i}`, name: `大数据分组 ${i} ${ts}` }));

  await loginAsDevAdmin(page);
  await page.goto('/admin/groups.php');
  for (const [index, group] of groups.entries()) {
    await page.getByRole('button', { name: /添加分组/ }).click();
    await page.locator('#fi_id').fill(group.id);
    await page.locator('#fi_name').fill(group.name);
    await page.locator('#fi_order').fill(String(index));
    await page.locator('#fi_auth').selectOption('0');
    await submitVisibleModal(page);
  }

  await page.goto('/admin/sites.php');
  let siteCounter = 0;
  for (const group of groups) {
    for (let i = 0; i < 5; i++) {
      await page.getByRole('button', { name: /添加站点/ }).click();
      await page.locator('#fi_sid').fill(`large-site-${ts}-${siteCounter}`);
      await page.locator('#fi_name').fill(`大数据站点 ${siteCounter}`);
      await page.locator('#fi_gid').selectOption(group.id);
      await page.locator('#fi_type').selectOption('external');
      await page.locator('#fi_url').fill(`https://example.com/${ts}/${siteCounter}`);
      await submitVisibleModal(page);
      siteCounter++;
    }
  }

  await page.goto('/index.php');
  await expect(page.locator('.card')).toHaveCount(siteCounter);
  await page.locator('#searchToggle').click();
  await page.locator('#sq').fill('大数据站点 1');
  await expect(page.locator('#searchMeta')).toContainText('找到');
  await expect(page.locator('.nav-bar .na').first()).toBeVisible();

  await tracker.assertNoClientErrors();
});
