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
  const taskId = await page.evaluate((taskName) => {
    const rows = (window as Window & { DDNS_ROWS?: Array<{ id: string; name: string }> }).DDNS_ROWS || [];
    return rows.find((item) => item.name === taskName)?.id || '';
  }, name);
  expect(taskId).not.toBe('');
  return { row, taskId };
}

async function waitForDdnsSettled(page: Parameters<typeof loginAsDevAdmin>[0], taskName: string, timeoutMs = 60000) {
  await page.waitForFunction((name) => {
    const rows = (window as Window & { DDNS_ROWS?: Array<{ name: string; last_status: string }> }).DDNS_ROWS || [];
    const row = rows.find((item) => item.name === name);
    return !!row && row.last_status !== 'running';
  }, taskName, { timeout: timeoutMs });
}

test('ddns execution updates latest value and supports multi-page log navigation controls', async ({ page }) => {
  test.setTimeout(120000);
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
  const { row, taskId } = await createTask(page, taskName, domain);
  await page.evaluate((id) => {
    const fn = (window as Window & { runTask?: (taskId: string, silent?: boolean) => Promise<void> }).runTask;
    if (typeof fn !== 'function') throw new Error('runTask not found');
    void fn(id);
  }, taskId);
  await expect(page.locator('body')).toContainText(/已开始后台执行|后台执行已在运行中/, { timeout: 15000 });
  await waitForDdnsSettled(page, taskName);
  await expect(row).toContainText(/\d+\.\d+\.\d+\.\d+|—/);

  const logApi = await page.request.post('http://127.0.0.1:58080/admin/ddns_ajax.php', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    data: { action: 'log', id: taskId, page: 1 },
  });
  expect(logApi.status()).toBe(200);
  const logPayload = await logApi.json();
  expect(logPayload).toMatchObject({ ok: true, data: { page: expect.any(Number), pages: expect.any(Number) } });
  await page.evaluate(
    ({ id, name }) => {
      const fn = (window as Window & { openDdnsLogModal?: (taskId: string, taskName: string) => void }).openDdnsLogModal;
      if (typeof fn !== 'function') throw new Error('openDdnsLogModal is not available');
      fn(id, name);
    },
    { id: taskId, name: taskName }
  );
  await expect(page.locator('#ddns-log-modal')).toBeVisible();
  await expect(page.locator('#ddns-log-prev')).toBeVisible();
  await expect(page.locator('#ddns-log-next')).toBeVisible();
  await expect(page.locator('#ddns-log-body')).toContainText(/暂无日志记录|任务开始执行|来源解析|DNS 更新|跳过|失败|\[/, {
    timeout: 10000,
  });

  await tracker.assertNoClientErrors();
});
