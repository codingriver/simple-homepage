import { test, expect } from '@playwright/test';
import { attachClientErrorTracking, loginAsDevAdmin, submitVisibleModal } from '../../helpers/auth';

test('groups support icon visibility matrix and modal close patterns', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
    ],
  });
  const ts = Date.now();
  const gid = `emoji-group-${ts}`;

  await loginAsDevAdmin(page);
  await page.goto('/admin/groups.php');
  await page.getByRole('button', { name: /添加分组/ }).click();
  await expect(page.locator('#modal')).toBeVisible();
  await page.getByRole('button', { name: /取消/ }).click({ force: true });
  await expect(page.locator('#modal')).toBeHidden();

  await page.getByRole('button', { name: /添加分组/ }).click();
  await page.locator('#fi_id').fill(gid);
  await page.locator('#fi_name').fill(`表情分组 ${ts}`);
  await page.locator('#fi_icon').fill('🚀');
  await page.locator('#fi_vis').selectOption('all');
  await page.locator('#fi_auth').selectOption('0');
  await submitVisibleModal(page);

  await page.goto('/index.php');
  await expect(page.locator('.na', { hasText: `表情分组 ${ts}` })).toContainText('🚀');

  await tracker.assertNoClientErrors();
});
