import { test, expect } from '../../helpers/fixtures';
import { attachClientErrorTracking, loginAsDevAdmin, submitVisibleModal } from '../../helpers/auth';

test('backup restore creates auto-before-restore snapshot and retention stays within cap', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
    ],
  });
  const ts = Date.now();
  const gid = `backup-auto-${ts}`;

  await loginAsDevAdmin(page);
  await page.goto('/admin/groups.php', { timeout: 30000 });
  await page.getByRole('button', { name: /添加分组/ }).click();
  await page.locator('#fi_id').fill(gid);
  await page.locator('#fi_name').fill(`恢复前自动备份 ${ts}`);
  await page.locator('#fi_auth').selectOption('0');
  await submitVisibleModal(page);

  await page.goto('/admin/backups.php');
  await page.getByRole('button', { name: /立即备份/ }).click();
  await expect(page.locator('body')).toContainText('备份已创建');
  const beforeRows = await page.locator('table tr').count();
  const restoreRow = page.locator('table tr').nth(1);

  page.once('dialog', (dialog) => dialog.accept());
  await restoreRow.getByRole('button', { name: /恢复/ }).click();
  await expect(page.locator('body')).toContainText(/已恢复备份|自动备份/);
  const afterRows = await page.locator('table tr').count();
  expect(afterRows).toBeGreaterThanOrEqual(beforeRows);
  await expect(page.locator('body')).toContainText(/自动-恢复前/);

  const countLabel = await page.locator('.toolbar').textContent();
  const match = countLabel?.match(/共\s*(\d+)\s*\/\s*(\d+)/);
  expect(match).toBeTruthy();
  if (match) {
    expect(Number(match[1])).toBeLessThanOrEqual(Number(match[2]));
  }

  await tracker.assertNoClientErrors();
});
