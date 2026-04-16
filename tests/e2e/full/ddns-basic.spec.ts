import { test, expect } from '../../helpers/fixtures';
import { attachClientErrorTracking, loginAsDevAdmin } from '../../helpers/auth';

function ddnsForm(page: Parameters<typeof loginAsDevAdmin>[0]) {
  return {
    name: page.locator('#fm-name'),
    enabled: page.locator('#fm-enabled'),
    sourceType: page.locator('#fm-source-type'),
    fallbackType: page.locator('#fm-fallback-type'),
    domain: page.locator('#fm-domain'),
    recordType: page.locator('#fm-record-type'),
    ttl: page.locator('#fm-ttl'),
    cron: page.locator('#fm-cron'),
  };
}

async function ddnsTaskIdByName(page: Parameters<typeof loginAsDevAdmin>[0], name: string) {
  const taskId = await page.evaluate((taskName) => {
    const rows = (window as Window & { DDNS_ROWS?: Array<{ id: string; name: string }> }).DDNS_ROWS || [];
    return rows.find((row) => row.name === taskName)?.id || '';
  }, name);
  expect(taskId).not.toBe('');
  return taskId;
}

async function triggerDdnsSave(page: Parameters<typeof loginAsDevAdmin>[0], runAfterSave = false) {
  await page.evaluate((shouldRun) => {
    const fn = (window as Window & { saveTask?: (runAfterSave: boolean) => Promise<void> }).saveTask;
    if (typeof fn !== 'function') throw new Error('saveTask not found');
    void fn(shouldRun);
  }, runAfterSave);
}

async function openDdnsModalByScript(page: Parameters<typeof loginAsDevAdmin>[0]) {
  await page.evaluate(() => {
    const fn = (window as Window & { openDdnsModal?: () => void }).openDdnsModal;
    if (typeof fn !== 'function') throw new Error('openDdnsModal not found');
    fn();
  });
  await expect(page.locator('#ddns-modal')).toBeVisible();
}

test('admin can create edit toggle and delete a ddns task from the page', async ({ page }) => {
  test.setTimeout(120000);
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
      /Failed to load resource: the server responded with a status of 403 \(Forbidden\)/,
    ],
  });
  const ts = Date.now();
  const taskName = `DDNS 任务 ${ts}`;
  const editedName = `DDNS 任务已编辑 ${ts}`;
  const domain = `ddns-basic-${ts}.606077.xyz`;

  await loginAsDevAdmin(page);
  await page.goto('/admin/ddns.php');
  await openDdnsModalByScript(page);

  const form = ddnsForm(page);
  await form.name.fill(taskName);
  await form.sourceType.selectOption('local_ipv4');
  await form.domain.fill(domain);
  await form.recordType.selectOption('A');
  await form.ttl.fill('120');
  await form.cron.fill('*/30 * * * *');
  await triggerDdnsSave(page);

  const row = page.locator(`tr:has-text("${taskName}")`).first();
  await expect(row).toBeVisible();
  await expect(row).toContainText('local_ipv4');
  await expect(row).toContainText(domain);
  await expect(row).toContainText('启用');

  const taskId = await ddnsTaskIdByName(page, taskName);
  await page.evaluate((id) => {
    const fn = (window as Window & { openDdnsModal?: (taskId: string) => void }).openDdnsModal;
    if (typeof fn !== 'function') throw new Error('openDdnsModal not found');
    fn(id);
  }, taskId);
  await form.name.fill(editedName);
  await form.ttl.fill('600');
  await form.cron.fill('*/10 * * * *');
  await triggerDdnsSave(page);

  let editedRow = page.locator(`tr:has-text("${editedName}")`).first();
  await expect(editedRow).toBeVisible();
  await expect(editedRow).toContainText('*/10 * * * *');

  await page.evaluate((id) => {
    const fn = (window as Window & { toggleTask?: (taskId: string) => Promise<void> }).toggleTask;
    if (typeof fn !== 'function') throw new Error('toggleTask not found');
    void fn(id);
  }, taskId);
  editedRow = page.locator(`tr:has-text("${editedName}")`).first();
  await expect(editedRow).toContainText('禁用');
  await page.evaluate((id) => {
    const fn = (window as Window & { toggleTask?: (taskId: string) => Promise<void> }).toggleTask;
    if (typeof fn !== 'function') throw new Error('toggleTask not found');
    void fn(id);
  }, taskId);
  editedRow = page.locator(`tr:has-text("${editedName}")`).first();
  await expect(editedRow).toContainText('启用');

  page.once('dialog', (dialog) => dialog.accept());
  await page.evaluate(({ id, name }) => {
    const fn = (window as Window & { deleteTask?: (taskId: string, taskName: string) => Promise<void> }).deleteTask;
    if (typeof fn !== 'function') throw new Error('deleteTask not found');
    void fn(id, name);
  }, { id: taskId, name: editedName });
  await expect(page.locator(`tr:has-text("${editedName}")`)).toHaveCount(0);

  await tracker.assertNoClientErrors();
});

test('ddns list exposes source label latest value and execution status columns', async ({ page }) => {
  test.setTimeout(120000);
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [/Failed to load resource: the server responded with a status of 400 \(Bad Request\)/],
  });
  const ts = Date.now();
  const taskName = `DDNS 列表检查 ${ts}`;
  const domain = `ddns-list-${ts}.606077.xyz`;

  await loginAsDevAdmin(page);
  await page.goto('/admin/ddns.php');
  await openDdnsModalByScript(page);

  const form = ddnsForm(page);
  await form.name.fill(taskName);
  await form.sourceType.selectOption('api4ce_cfip');
  await form.fallbackType.selectOption('cf164746_global');
  await form.domain.fill(domain);
  await form.recordType.selectOption('A');
  await triggerDdnsSave(page);

  const row = page.locator(`tr:has-text("${taskName}")`).first();
  await expect(row).toBeVisible();
  await expect(row).toContainText('4ce');
  await expect(row).toContainText('164746');
  await expect(row).toContainText('—');
  await expect(row.locator('td').nth(7)).toContainText(/成功|失败|运行中/);

  await tracker.assertNoClientErrors();
});
