import { test, expect } from '@playwright/test';
import { attachClientErrorTracking, loginAsDevAdmin } from '../../helpers/auth';

async function ddnsTaskIdByName(page: Parameters<typeof loginAsDevAdmin>[0], name: string) {
  const taskId = await page.evaluate((taskName) => {
    const rows = (window as Window & { DDNS_ROWS?: Array<{ id: string; name: string }> }).DDNS_ROWS || [];
    return rows.find((row) => row.name === taskName)?.id || '';
  }, name);
  expect(taskId).not.toBe('');
  return taskId;
}

async function triggerDdnsSave(page: Parameters<typeof loginAsDevAdmin>[0], runAfterSave = false) {
  await page.evaluate(async (shouldRun) => {
    const fn = (window as Window & { saveTask?: (runAfterSave: boolean) => Promise<void> }).saveTask;
    if (typeof fn !== 'function') throw new Error('saveTask not found');
    await fn(shouldRun);
  }, runAfterSave);
}

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
  await page.evaluate(() => {
    const fn = (window as Window & { openDdnsModal?: () => void }).openDdnsModal;
    if (typeof fn !== 'function') throw new Error('openDdnsModal not found');
    fn();
  });
  await expect(page.locator('#ddns-modal')).toBeVisible();
  await page.locator('#fm-name').fill(taskName);
  await page.locator('#fm-source-type').selectOption('api4ce_cfip');
  await page.locator('#fm-fallback-type').selectOption('cf164746_global');
  await page.locator('#fm-domain').fill(domain);
  await triggerDdnsSave(page);

  const row = page.locator(`tr:has-text("${taskName}")`).first();
  await expect(row).toBeVisible();
  await expect(row).toContainText(/4ce/);
  await expect(row).toContainText(/164746/);

  const taskId = await ddnsTaskIdByName(page, taskName);
  await page.evaluate((id) => {
    const fn = (window as Window & { openDdnsModal?: (taskId: string) => void }).openDdnsModal;
    if (typeof fn !== 'function') throw new Error('openDdnsModal not found');
    fn(id);
  }, taskId);
  await page.evaluate(async () => {
    const fn = (window as Window & { testSource?: () => Promise<void> }).testSource;
    if (typeof fn !== 'function') throw new Error('testSource not found');
    await fn();
  });
  await expect(page.locator('#fm-test-result')).toContainText(/状态：成功|状态：失败/, { timeout: 5_000 });
  await expect(page.locator('#fm-test-result')).toContainText(/4ce|164746|回退|失败/, { timeout: 5_000 });

  await tracker.assertNoClientErrors();
});
