import fs from 'fs/promises';
import path from 'path';
import { test, expect } from '@playwright/test';
import { attachClientErrorTracking, loginAsDevAdmin } from '../../helpers/auth';

const tasksRoot = path.resolve(__dirname, '../../../data/tasks');
const logsRoot = path.resolve(__dirname, '../../../data/logs');

test('admin can save settings and see homepage title update', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
    ],
    ignoredFailedRequests: [
      /GET .*\/admin\/settings_ajax\.php\?action=nginx_sudo :: net::ERR_ABORTED/,
      /GET .*\/admin\/ddns_ajax\.php\?action=list :: net::ERR_ABORTED/,
    ],
  });
  const siteName = `设置验证 ${Date.now()}`;

  await loginAsDevAdmin(page);
  await page.goto('/admin/settings.php');

  await page.locator('input[name="site_name"]').fill(siteName);
  await page.locator('input[name="bg_color"]').fill('#112233');
  await page.locator('select[name="card_layout"]').selectOption('list');
  await page.locator('select[name="card_direction"]').selectOption('row');
  await page.getByRole('button', { name: /保存设置/ }).click();
  await expect(page.locator('body')).toContainText('设置已保存');

  await page.reload();
  await expect(page.locator('input[name="site_name"]')).toHaveValue(siteName);

  await page.goto('/index.php');
  await expect(page).toHaveTitle(new RegExp(siteName));

  await tracker.assertNoClientErrors();
});

test('settings validate long site name invalid bg color and custom card bounds', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
    ],
    ignoredFailedRequests: [
      /GET .*\/admin\/settings_ajax\.php\?action=nginx_sudo :: net::ERR_ABORTED/,
    ],
  });
  const longName = '超长站点名称'.repeat(10);

  await loginAsDevAdmin(page);
  await page.goto('/admin/settings.php');

  await page.locator('input[name="site_name"]').fill(longName);
  await expect(page.locator('input[name="site_name"]')).toHaveValue(longName.slice(0, 60));
  await page.getByRole('button', { name: /保存设置/ }).click();
  await expect(page.locator('body')).toContainText('设置已保存');

  await page.locator('input[name="site_name"]').fill('边界设置验证');
  await page.locator('input[name="bg_color"]').fill('not-a-color');
  await page.getByRole('button', { name: /保存设置/ }).click();
  await expect(page.locator('body')).toContainText('背景色格式无效');

  await page.locator('#card_size_sel').selectOption('custom');
  await page.locator('#card_size_custom').fill('999');
  await expect.poll(async () => page.locator('#card_size_custom').evaluate((el: HTMLInputElement) => el.validity.rangeOverflow)).toBe(true);
  await page.locator('#card_size_custom').fill('600');

  await page.locator('#card_height_sel').selectOption('custom');
  await page.locator('#card_height_custom').fill('999');
  await expect.poll(async () => page.locator('#card_height_custom').evaluate((el: HTMLInputElement) => el.validity.rangeOverflow)).toBe(true);
  await page.locator('#card_height_custom').fill('800');

  await page.locator('input[name="bg_color"]').fill('#223344');
  await page.getByRole('button', { name: /保存设置/ }).click();
  await expect(page.locator('body')).toContainText('设置已保存');
  await page.reload();
  await expect(page.locator('#card_size_sel')).toHaveValue('custom');
  await expect(page.locator('#card_size_custom')).toHaveValue('600');
  await expect(page.locator('#card_height_sel')).toHaveValue('custom');
  await expect(page.locator('#card_height_custom')).toHaveValue('800');

  await tracker.assertNoClientErrors();
});

test('settings background image upload rejects invalid file', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
    ],
    ignoredFailedRequests: [
      /GET .*\/admin\/settings_ajax\.php\?action=nginx_sudo :: net::ERR_ABORTED/,
    ],
  });
  const invalidFile = path.resolve(__dirname, '../../fixtures/import-invalid.json');

  await loginAsDevAdmin(page);
  await page.goto('/admin/settings.php');
  await page.locator('input[name="bg_image"]').setInputFiles(invalidFile);
  await page.getByRole('button', { name: /保存设置/ }).click();
  await expect(page.locator('body')).toContainText(/背景图内容无效|背景图上传失败|背景图大小需在 8MB 以内/);

  await tracker.assertNoClientErrors();
});

test('settings danger actions can clear scheduled tasks and ddns tasks with related logs', async ({ page }) => {
  test.setTimeout(180000);
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
    ],
    ignoredFailedRequests: [
      /GET .*\/admin\/settings_ajax\.php\?action=nginx_sudo :: net::ERR_ABORTED/,
    ],
  });
  const ts = Date.now();
  const scheduledName = `设置清空计划任务 ${ts}`;
  const ddnsName = `设置清空DDNS任务 ${ts}`;
  const ddnsDomain = `settings-clear-${ts}.606077.xyz`;

  await loginAsDevAdmin(page);

  await page.goto('/admin/scheduled_tasks.php');
  await page.getByRole('button', { name: /新建任务/ }).click();
  await page.locator('#fm-name').fill(scheduledName);
  await page.locator('#fm-schedule').fill('*/21 * * * *');
  await page.locator('#fm-command').fill('echo clear-from-settings');
  await page.locator('#task-form').getByRole('button', { name: /保存/ }).click({ force: true });
  await expect(page.locator('body')).toContainText(/已保存并更新 crontab|已保存/);
  const scheduledRow = page.locator(`tr[data-task-row]:has-text("${scheduledName}")`).first();
  const scheduledTaskId = await scheduledRow.locator('form input[name="id"]').first().inputValue();
  const scheduledScriptPath = path.join(tasksRoot, `task_${scheduledTaskId}.sh`);
  const scheduledTaskLogPath = path.join(tasksRoot, `task_${scheduledTaskId}.log`);
  const scheduledLockPath = path.join(logsRoot, `cron_${scheduledTaskId}.lock`);
  await fs.writeFile(scheduledTaskLogPath, 'scheduled-log\n', 'utf8');
  await fs.writeFile(scheduledLockPath, '', 'utf8');

  await page.goto('/admin/ddns.php');
  await page.evaluate(() => {
    const fn = (window as Window & { openDdnsModal?: () => void }).openDdnsModal;
    if (typeof fn !== 'function') throw new Error('openDdnsModal not found');
    fn();
  });
  await expect(page.locator('#ddns-modal')).toBeVisible();
  await page.locator('#fm-name').fill(ddnsName);
  await page.locator('#fm-source-type').selectOption('local_ipv4');
  await page.locator('#fm-domain').fill(ddnsDomain);
  await page.evaluate(() => {
    const fn = (window as Window & { saveTask?: (runAfterSave: boolean) => Promise<void> }).saveTask;
    if (typeof fn !== 'function') throw new Error('saveTask not found');
    void fn(false);
  });
  await expect(page.locator(`tr:has-text("${ddnsName}")`).first()).toBeVisible();
  const ddnsTaskId = await page.evaluate((taskName) => {
    const rows = (window as Window & { DDNS_ROWS?: Array<{ id: string; name: string }> }).DDNS_ROWS || [];
    return rows.find((row) => row.name === taskName)?.id || '';
  }, ddnsName);
  expect(ddnsTaskId).not.toBe('');
  const ddnsTaskLogPath = path.join(logsRoot, `ddns_${ddnsTaskId}.log`);
  const ddnsGlobalLogPath = path.join(logsRoot, 'ddns.log');
  await fs.writeFile(ddnsTaskLogPath, 'ddns-task-log\n', 'utf8');
  await fs.writeFile(ddnsGlobalLogPath, 'ddns-global-log\n', 'utf8');

  await page.goto('/admin/settings.php');
  page.once('dialog', (dialog) => dialog.accept());
  await page.getByRole('button', { name: /清空计划任务/ }).click({ force: true });
  await expect(page.locator('body')).toContainText(/已清空 .*普通计划任务/);
  await expect(fs.access(scheduledScriptPath).then(() => true).catch(() => false)).resolves.toBe(false);
  await expect(fs.access(scheduledTaskLogPath).then(() => true).catch(() => false)).resolves.toBe(false);
  await expect(fs.access(scheduledLockPath).then(() => true).catch(() => false)).resolves.toBe(false);

  await page.goto('/admin/scheduled_tasks.php');
  await expect(page.locator(`tr[data-task-row]:has-text("${scheduledName}")`)).toHaveCount(0);

  await page.goto('/admin/settings.php');
  page.once('dialog', (dialog) => dialog.accept());
  await page.getByRole('button', { name: /清空 DDNS 任务/ }).click({ force: true });
  await expect(page.locator('body')).toContainText(/已清空 .*DDNS 任务/);
  await expect(fs.access(ddnsTaskLogPath).then(() => true).catch(() => false)).resolves.toBe(false);
  await expect(fs.access(ddnsGlobalLogPath).then(() => true).catch(() => false)).resolves.toBe(false);

  await page.goto('/admin/ddns.php');
  await expect(page.locator(`tr:has-text("${ddnsName}")`)).toHaveCount(0);

  await tracker.assertNoClientErrors();
});
