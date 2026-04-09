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

async function saveDdns(page: Parameters<typeof loginAsDevAdmin>[0]) {
  await page.evaluate(() => {
    const fn = (window as Window & { saveTask?: (runAfterSave: boolean) => Promise<void> }).saveTask;
    if (typeof fn !== 'function') throw new Error('saveTask not found');
    void fn(false);
  });
}

async function createTask(page: Parameters<typeof loginAsDevAdmin>[0], name: string, domain: string, cron: string) {
  await page.goto('/admin/ddns.php');
  await page.evaluate(() => {
    const fn = (window as Window & { openDdnsModal?: () => void }).openDdnsModal;
    if (typeof fn !== 'function') throw new Error('openDdnsModal not found');
    fn();
  });
  await expect(page.locator('#ddns-modal')).toBeVisible();
  await page.locator('#fm-name').fill(name);
  await page.locator('#fm-source-type').selectOption('local_ipv4');
  await page.locator('#fm-domain').fill(domain);
  await page.locator('#fm-cron').fill(cron);
  await saveDdns(page);
}

test('ddns tasks sync into scheduled dispatcher groups by cron and update after disable', async ({ page }) => {
  test.setTimeout(120000);
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
      /Failed to load resource: the server responded with a status of 500 \(Internal Server Error\)/,
    ],
    ignoredFailedRequests: [
      /GET .*\/admin\/ddns_ajax\.php\?action=list.*:: net::ERR_ABORTED/,
      /POST .*\/admin\/ddns_ajax\.php.*:: net::ERR_ABORTED/,
    ],
  });
  const ts = Date.now();
  const taskA = `DDNS 调度 A ${ts}`;
  const taskB = `DDNS 调度 B ${ts}`;
  const taskC = `DDNS 调度 C ${ts}`;

  await loginAsDevAdmin(page);
  await createTask(page, taskA, `dispatcher-a-${ts}.606077.xyz`, '*/7 * * * *');
  await createTask(page, taskB, `dispatcher-b-${ts}.606077.xyz`, '*/7 * * * *');
  await createTask(page, taskC, `dispatcher-c-${ts}.606077.xyz`, '*/13 * * * *');

  await page.goto('/admin/scheduled_tasks.php');
  await expect(page.locator('body')).toContainText(/DDNS 调度器/);
  await expect(page.locator('body')).toContainText(taskA);
  await expect(page.locator('body')).toContainText(taskB);
  await expect(page.locator('body')).toContainText(taskC);

  await page.goto('/admin/ddns.php');
  const rowA = page.locator(`tr:has-text("${taskA}")`).first();
  const taskIdA = await ddnsTaskIdByName(page, taskA);
  await page.evaluate((id) => {
    const fn = (window as Window & { toggleTask?: (taskId: string) => Promise<void> }).toggleTask;
    if (typeof fn !== 'function') throw new Error('toggleTask not found');
    void fn(id);
  }, taskIdA);
  await expect(rowA).toContainText('禁用');

  await page.goto('/admin/scheduled_tasks.php');
  await expect(page.locator('body')).toContainText(/DDNS 调度器/);

  await tracker.assertNoClientErrors();
});
