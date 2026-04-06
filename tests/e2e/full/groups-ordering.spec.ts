import { test, expect } from '@playwright/test';
import { attachClientErrorTracking, loginAsDevAdmin, logout, submitVisibleModal } from '../../helpers/auth';

test('groups are shown on homepage according to configured order', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
    ],
  });
  const ts = Date.now();
  const slowId = `group-slow-${ts}`;
  const fastId = `group-fast-${ts}`;
  const slowName = `排序靠后 ${ts}`;
  const fastName = `排序靠前 ${ts}`;

  await loginAsDevAdmin(page);
  await page.goto('/admin/groups.php');

  await page.getByRole('button', { name: /添加分组/ }).click();
  await page.locator('#fi_id').fill(slowId);
  await page.locator('#fi_name').fill(slowName);
  await page.locator('#fi_order').fill('20');
  await page.locator('#fi_vis').selectOption('all');
  await page.locator('#fi_auth').selectOption('0');
  await submitVisibleModal(page);

  await page.getByRole('button', { name: /添加分组/ }).click();
  await page.locator('#fi_id').fill(fastId);
  await page.locator('#fi_name').fill(fastName);
  await page.locator('#fi_order').fill('5');
  await page.locator('#fi_vis').selectOption('all');
  await page.locator('#fi_auth').selectOption('0');
  await submitVisibleModal(page);

  await logout(page);
  await page.goto('/index.php');

  const tabs = page.locator('.nav-bar .na');
  await expect(tabs.filter({ hasText: fastName })).toHaveCount(1);
  await expect(tabs.filter({ hasText: slowName })).toHaveCount(1);

  const tabTexts = await tabs.allTextContents();
  const fastIndex = tabTexts.findIndex(text => text.includes(fastName));
  const slowIndex = tabTexts.findIndex(text => text.includes(slowName));
  expect(fastIndex).toBeGreaterThanOrEqual(0);
  expect(slowIndex).toBeGreaterThanOrEqual(0);
  expect(fastIndex).toBeLessThan(slowIndex);

  await tracker.assertNoClientErrors();
});
