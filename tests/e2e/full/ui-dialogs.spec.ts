import path from 'path';
import { test, expect } from '@playwright/test';
import { attachClientErrorTracking, loginAsDevAdmin, submitVisibleModal } from '../../helpers/auth';

test('confirm dialogs work for group deletion and config import', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
    ],
  });
  const gid = `dialog-group-${Date.now()}`;
  const validImport = path.resolve(__dirname, '../../fixtures/import-valid.json');

  await loginAsDevAdmin(page);

  await page.goto('/admin/groups.php');
  await page.getByRole('button', { name: /添加分组/ }).click();
  await page.locator('#fi_id').fill(gid);
  await page.locator('#fi_name').fill('弹窗验证分组');
  await page.locator('#fi_auth').selectOption('0');
  await submitVisibleModal(page);

  const row = page.locator(`tr:has(input[name="gid"][value="${gid}"])`).first();
  await expect(row).toContainText('弹窗验证分组');

  page.once('dialog', dialog => {
    expect(dialog.type()).toBe('confirm');
    expect(dialog.message()).toContain('确认删除该分组及其所有站点？');
    dialog.dismiss();
  });
  await row.locator('xpath=.//form//button[contains(normalize-space(.), "删除")]').click({ force: true });
  await expect(page.locator(`tr:has(input[name="gid"][value="${gid}"])`)).toHaveCount(1);

  page.once('dialog', dialog => {
    expect(dialog.type()).toBe('confirm');
    expect(dialog.message()).toContain('确认导入？');
    dialog.accept();
  });
  await page.goto('/admin/settings.php');
  await page.locator('#importFile').setInputFiles(validImport);
  await expect(page.locator('body')).toContainText('导入成功');

  await tracker.assertNoClientErrors();
});

test('modal backdrop close and backup dialogs behave as expected', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
    ],
  });

  await loginAsDevAdmin(page);

  await page.goto('/admin/groups.php');
  await page.getByRole('button', { name: /添加分组/ }).click();
  await expect(page.locator('#modal')).toBeVisible();
  await page.locator('#modal').click({ position: { x: 10, y: 10 } });
  await expect(page.locator('#modal')).toBeHidden();

  await page.goto('/admin/backups.php');
  const bodyText = await page.locator('body').textContent();
  if ((bodyText || '').includes('暂无备份记录')) {
    await page.getByRole('button', { name: /立即备份/ }).click();
    await expect(page.locator('body')).toContainText('备份已创建');
  }

  const firstRow = page.locator('table tr').nth(1);
  page.once('dialog', dialog => {
    expect(dialog.type()).toBe('confirm');
    expect(dialog.message()).toContain('确认删除此备份？');
    dialog.dismiss();
  });
  await firstRow.getByRole('button', { name: '删除' }).click();
  await expect(page.locator('body')).not.toContainText('备份已删除');

  page.once('dialog', dialog => {
    expect(dialog.type()).toBe('confirm');
    expect(dialog.message()).toContain('确认恢复此备份？');
    dialog.dismiss();
  });
  await firstRow.getByRole('button', { name: /恢复/ }).click();
  await expect(page.locator('body')).not.toContainText('已恢复备份');

  await tracker.assertNoClientErrors();
});
