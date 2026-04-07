import { test, expect } from '@playwright/test';
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

test('admin can create edit toggle and delete a ddns task from the page', async ({ page }) => {
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
  await page.getByRole('button', { name: /新建任务/ }).click();

  const form = ddnsForm(page);
  await form.name.fill(taskName);
  await form.sourceType.selectOption('local_ipv4');
  await form.domain.fill(domain);
  await form.recordType.selectOption('A');
  await form.ttl.fill('120');
  await form.cron.fill('*/30 * * * *');
  await page.getByRole('button', { name: /^保存$/ }).click();

  const row = page.locator(`tr:has-text("${taskName}")`).first();
  await expect(row).toBeVisible();
  await expect(row).toContainText('local_ipv4');
  await expect(row).toContainText(domain);
  await expect(row).toContainText('启用');

  await row.getByRole('button', { name: /编辑/ }).click();
  await form.name.fill(editedName);
  await form.ttl.fill('600');
  await form.cron.fill('*/10 * * * *');
  await page.getByRole('button', { name: /^保存$/ }).click();

  const editedRow = page.locator(`tr:has-text("${editedName}")`).first();
  await expect(editedRow).toBeVisible();
  await expect(editedRow).toContainText('*/10 * * * *');

  await editedRow.getByRole('button', { name: /禁用/ }).click();
  await expect(editedRow).toContainText('禁用');
  await editedRow.getByRole('button', { name: /启用/ }).click();
  await expect(editedRow).toContainText('启用');

  page.once('dialog', (dialog) => dialog.accept());
  await editedRow.getByRole('button', { name: /删除/ }).click();
  await expect(page.locator(`tr:has-text("${editedName}")`)).toHaveCount(0);

  await tracker.assertNoClientErrors();
});

test('ddns list exposes source label latest value and execution status columns', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [/Failed to load resource: the server responded with a status of 400 \(Bad Request\)/],
  });
  const ts = Date.now();
  const taskName = `DDNS 列表检查 ${ts}`;
  const domain = `ddns-list-${ts}.606077.xyz`;

  await loginAsDevAdmin(page);
  await page.goto('/admin/ddns.php');
  await page.getByRole('button', { name: /新建任务/ }).click();

  const form = ddnsForm(page);
  await form.name.fill(taskName);
  await form.sourceType.selectOption('api4ce_cfip');
  await form.fallbackType.selectOption('cf164746_global');
  await form.domain.fill(domain);
  await form.recordType.selectOption('A');
  await page.getByRole('button', { name: /^保存$/ }).click();

  const row = page.locator(`tr:has-text("${taskName}")`).first();
  await expect(row).toBeVisible();
  await expect(row).toContainText('4ce');
  await expect(row).toContainText('164746');
  await expect(row).toContainText('—');
  await expect(row.locator('td').nth(7)).toContainText(/成功|失败|运行中/);

  await tracker.assertNoClientErrors();
});
