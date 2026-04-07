import { test, expect } from '@playwright/test';
import { attachClientErrorTracking, loginAsDevAdmin } from '../../helpers/auth';

async function createTask(page: Parameters<typeof loginAsDevAdmin>[0], name: string, domain: string) {
  await page.goto('/admin/ddns.php');
  await page.evaluate(() => {
    const fn = (window as Window & { openDdnsModal?: () => void }).openDdnsModal;
    if (typeof fn === 'function') fn();
  });
  await expect(page.locator('#ddns-modal')).toBeVisible();
  await page.locator('#fm-name').fill(name);
  await page.locator('#fm-source-type').selectOption('local_ipv4');
  await page.locator('#fm-domain').fill(domain);
  await page.evaluate(() => {
    const fn = (window as Window & { saveTask?: (runAfterSave: boolean) => Promise<void> }).saveTask;
    if (typeof fn === 'function') return fn(false);
    throw new Error('saveTask is not available');
  });
  const row = page.locator(`tr:has-text("${name}")`).first();
  await expect(row).toBeVisible();
  return row;
}

test('ddns execution updates latest value and supports multi-page log navigation controls', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
      /Failed to load resource: the server responded with a status of 500 \(Internal Server Error\)/,
    ],
  });
  const ts = Date.now();
  const taskName = `DDNS 深度 ${ts}`;
  const domain = `ddns-deep-${ts}.606077.xyz`;

  await loginAsDevAdmin(page);
  const row = await createTask(page, taskName, domain);
  await row.getByRole('button', { name: /执行/ }).click({ force: true });
  await expect(row).not.toContainText(/^—$/);
  await expect(row).toContainText(/\d+\.\d+\.\d+\.\d+|—/);

  await row.getByRole('button', { name: /日志/ }).click({ force: true });
  await expect(page.locator('#ddns-log-modal')).toBeVisible();
  await expect
    .poll(async () => ((await page.locator('#ddns-log-page-label').textContent()) || '').trim(), { timeout: 10000 })
    .toMatch(/^第 \d+ \/ \d+ 页$/);
  await expect(page.locator('#ddns-log-prev')).toBeDisabled();
  await expect(page.locator('#ddns-log-next')).toBeVisible();
  await expect(page.locator('#ddns-log-body')).toContainText(/任务开始执行|来源解析|DNS 更新|跳过|失败/, { timeout: 10000 });

  await tracker.assertNoClientErrors();
});
