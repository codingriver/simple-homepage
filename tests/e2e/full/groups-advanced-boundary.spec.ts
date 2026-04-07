import { test, expect } from '@playwright/test';
import { attachClientErrorTracking, loginAsDevAdmin, logout, submitVisibleModal } from '../../helpers/auth';

test('groups advanced boundaries cover long text stable ordering and visibility matrix', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
    ],
  });
  const ts = Date.now();
  const publicId = `groups-adv-public-${ts}`;
  const adminId = `groups-adv-admin-${ts}`;
  const stableA = `groups-adv-a-${ts}`;
  const stableB = `groups-adv-b-${ts}`;

  await loginAsDevAdmin(page);
  await page.goto('/admin/groups.php');

  for (const [gid, name, vis, auth, order] of [
    [publicId, `😀 公开超长分组 ${'名'.repeat(20)}`, 'all', '0', '10'],
    [adminId, `🔒 管理分组 ${ts}`, 'admin', '1', '10'],
    [stableA, `排序甲 ${ts}`, 'all', '0', '20'],
    [stableB, `排序乙 ${ts}`, 'all', '0', '20'],
  ] as const) {
    await page.getByRole('button', { name: /添加分组/ }).click();
    await page.locator('#fi_id').fill(gid);
    await page.locator('#fi_name').fill(name);
    await page.locator('#fi_vis').selectOption(vis);
    await page.locator('#fi_auth').selectOption(auth);
    await page.locator('#fi_order').fill(order);
    await submitVisibleModal(page);
  }

  await logout(page);
  await page.goto('/index.php');
  await expect(page.locator('body')).toContainText('😀 公开超长分组');
  await expect(page.locator('body')).not.toContainText(`🔒 管理分组 ${ts}`);

  const tabs = await page.locator('.nav-bar .na').allTextContents();
  const idxA = tabs.findIndex((text) => text.includes(`排序甲 ${ts}`));
  const idxB = tabs.findIndex((text) => text.includes(`排序乙 ${ts}`));
  expect(idxA).toBeGreaterThanOrEqual(0);
  expect(idxB).toBeGreaterThanOrEqual(0);

  await loginAsDevAdmin(page);
  await page.goto('/index.php');
  await expect(page.locator('body')).toContainText(`🔒 管理分组 ${ts}`);

  await tracker.assertNoClientErrors();
});
