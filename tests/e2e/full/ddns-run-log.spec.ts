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

async function createDdnsTask(page: Parameters<typeof loginAsDevAdmin>[0], name: string, domain: string) {
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
  await page.evaluate(() => {
    const fn = (window as Window & { saveTask?: (runAfterSave: boolean) => Promise<void> }).saveTask;
    if (typeof fn !== 'function') throw new Error('saveTask not found');
    void fn(false);
  });
  const row = page.locator(`tr:has-text("${name}")`).first();
  await expect(row).toBeVisible();
  return ddnsTaskIdByName(page, name);
}

async function waitForDdnsSettled(page: Parameters<typeof loginAsDevAdmin>[0], taskName: string, timeoutMs = 60000) {
  await page.waitForFunction((name) => {
    const rows = (window as Window & { DDNS_ROWS?: Array<{ name: string; last_status: string }> }).DDNS_ROWS || [];
    const row = rows.find((item) => item.name === name);
    return !!row && row.last_status !== 'running';
  }, taskName, { timeout: timeoutMs });
}

test('ddns run and log modal support execution pagination search and clear', async ({ page }) => {
  test.setTimeout(120000);
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
      /Failed to load resource: the server responded with a status of 403 \(Forbidden\)/,
      /Failed to load resource: the server responded with a status of 500 \(Internal Server Error\)/,
    ],
  });
  const ts = Date.now();
  const taskName = `DDNS 日志 ${ts}`;
  const domain = `ddns-log-${ts}.606077.xyz`;

  await loginAsDevAdmin(page);
  const taskId = await createDdnsTask(page, taskName, domain);
  await page.evaluate((id) => {
    const fn = (window as Window & { runTask?: (taskId: string, silent?: boolean) => Promise<void> }).runTask;
    if (typeof fn !== 'function') throw new Error('runTask not found');
    void fn(id);
  }, taskId);
  await expect(page.locator('body')).toContainText(/已开始后台执行|后台执行已在运行中/);
  await waitForDdnsSettled(page, taskName);

  const logResponse = page.waitForResponse(
    (response) =>
      response.url().includes('/admin/ddns_ajax.php') &&
      response.request().method() === 'POST' &&
      (response.request().postData() || '').includes('"action":"log"')
  );
  await page.evaluate(
    ({ id, name }) => {
      const fn = (window as Window & { openDdnsLogModal?: (taskId: string, taskName: string) => void }).openDdnsLogModal;
      if (typeof fn !== 'function') throw new Error('openDdnsLogModal not found');
      fn(id, name);
    },
    { id: taskId, name: taskName }
  );
  await logResponse;
  await expect(page.locator('#ddns-log-modal')).toBeVisible();
  await expect(page.locator('#ddns-log-body')).toContainText(/暂无日志记录|成功|失败|跳过|\[/);

  await page.locator('#ddns-log-search').fill('success');
  await expect(page.locator('#ddns-log-body')).toContainText(/暂无日志记录|当前页没有匹配|success|成功/);
  await page.evaluate(() => {
    const fn = (window as Window & { clearDdnsLogSearch?: () => void }).clearDdnsLogSearch;
    if (typeof fn !== 'function') throw new Error('clearDdnsLogSearch not found');
    fn();
  });

  page.once('dialog', (dialog) => dialog.accept());
  await page.evaluate(() => {
    const fn = (window as Window & { clearCurrentDdnsLog?: () => Promise<void> }).clearCurrentDdnsLog;
    if (typeof fn !== 'function') throw new Error('clearCurrentDdnsLog not found');
    void fn();
  });
  await expect(page.locator('#ddns-log-body')).toContainText(/暂无日志记录|当前页没有匹配/);

  await tracker.assertNoClientErrors();
});

test('ddns test source shows structured result feedback', async ({ page }) => {
  test.setTimeout(120000);
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
      /Failed to load resource: the server responded with a status of 500 \(Internal Server Error\)/,
    ],
  });

  await loginAsDevAdmin(page);
  await page.goto('/admin/ddns.php');
  await page.evaluate(() => {
    const fn = (window as Window & { openDdnsModal?: () => void }).openDdnsModal;
    if (typeof fn !== 'function') throw new Error('openDdnsModal not found');
    fn();
  });
  await expect(page.locator('#ddns-modal')).toBeVisible();
  await page.locator('#fm-name').fill('DDNS 来源测试');
  await page.locator('#fm-domain').fill(`ddns-source-${Date.now()}.606077.xyz`);
  await page.locator('#fm-source-type').selectOption('api4ce_cfip');
  const sourceTestResponse = page.waitForResponse(
    (response) =>
      response.url().includes('/admin/ddns_ajax.php') &&
      response.request().method() === 'POST' &&
      (response.request().postData() || '').includes('"action":"test_source"'),
    { timeout: 20000 }
  );
  await page.evaluate(() => {
    const fn = (window as Window & { testSource?: () => Promise<void> }).testSource;
    if (typeof fn !== 'function') throw new Error('testSource not found');
    void fn();
  });
  await sourceTestResponse;
  await expect(page.locator('#fm-test-result')).toContainText(/状态：成功|状态：失败/, { timeout: 20000 });

  await tracker.assertNoClientErrors();
});
