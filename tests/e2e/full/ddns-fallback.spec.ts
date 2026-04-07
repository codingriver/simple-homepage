import { test, expect } from '@playwright/test';
import { attachClientErrorTracking, loginAsDevAdmin } from '../../helpers/auth';

test('ddns fallback task shows combined source label and structured test result', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
      /Failed to load resource: the server responded with a status of 500 \(Internal Server Error\)/,
    ],
  });
  const ts = Date.now();
  const taskName = `DDNS 回退 ${ts}`;
  const domain = `ddns-fallback-${ts}.606077.xyz`;

  await loginAsDevAdmin(page);
  await page.goto('/admin/ddns.php');
  await page.getByRole('button', { name: /新建任务/ }).click();
  await page.locator('#fm-name').fill(taskName);
  await page.locator('#fm-source-type').selectOption('api4ce_cfip');
  await page.locator('#fm-fallback-type').selectOption('cf164746_global');
  await page.locator('#fm-domain').fill(domain);
  await page.getByRole('button', { name: /^保存$/ }).click();

  const row = page.locator(`tr:has-text("${taskName}")`).first();
  await expect(row).toContainText(/4ce/);
  await expect(row).toContainText(/164746/);

  await row.getByRole('button', { name: /编辑/ }).click();
  await page.getByRole('button', { name: /测试来源/ }).click();
  await expect(page.locator('#fm-test-result')).toContainText(/状态：成功|状态：失败/);
  await expect(page.locator('#fm-test-result')).toContainText(/4ce|164746|回退|失败/);

  await tracker.assertNoClientErrors();
});
