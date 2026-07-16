import { test, expect } from '../../helpers/fixtures';
import { attachClientErrorTracking, loginAsDevAdmin } from '../../helpers/auth';

test('admin dashboard stat cards reflect created data and quick actions work', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
      /Pattern attribute value \[a-zA-Z0-9_-\]\{2,32\} is not a valid regular expression/,
    ],
    ignoredFailedRequests: [
      /GET .*\/admin\/settings_ajax\.php\?action=nginx_sudo :: net::ERR_ABORTED/,
    ],
  });
  const ts = Date.now();
  const regularUser = `regular_${ts}`;

  await loginAsDevAdmin(page);

  // Get baseline stats
  await page.goto('/admin/index.php');
  await expect(page.locator('.stat-grid')).toBeVisible();
  const baseUserCount = parseInt(await page.locator('.stat-card:has-text("账户数量") .stat-val').textContent() || '0', 10);
  const baseAdminCount = parseInt(await page.locator('.stat-card:has-text("管理员数量") .stat-val').textContent() || '0', 10);
  const baseBackupCount = parseInt(await page.locator('.stat-card:has-text("备份记录") .stat-val').textContent() || '0', 10);

  // Create a backup
  await page.goto('/admin/backups.php');
  const baselineFiles = new Set(
    await page.locator('input[name="filename"]').evaluateAll((inputs) =>
      inputs.map((input) => (input as HTMLInputElement).value)
    )
  );
  await page.getByRole('button', { name: /立即备份/ }).click();
  await expect(page.locator('body')).toContainText('备份已创建');
  const backupFilesAfterCreate = await page.locator('input[name="filename"]').evaluateAll((inputs) =>
    inputs.map((input) => (input as HTMLInputElement).value)
  );
  const createdBackup = backupFilesAfterCreate.find((filename) => !baselineFiles.has(filename)) ?? backupFilesAfterCreate[0];

  // Verify backup stats increased
  await page.goto('/admin/index.php');
  await expect(page.locator('.stat-card:has-text("备份记录") .stat-val')).toHaveText(String(baseBackupCount + 1));

  // Create a regular user
  await page.goto('/admin/users.php?action=add');
  await page.locator('input[name="username"]').fill(regularUser);
  await page.locator('input[name="password"]').fill('Regular@test2026');
  await page.locator('select[name="role"]').selectOption('user');
  await page.getByRole('button', { name: /保存/ }).click();
  await expect(page.locator('body')).toContainText('已保存');

  await page.goto('/admin/index.php');
  await expect(page.locator('.stat-card:has-text("账户数量") .stat-val')).toHaveText(String(baseUserCount + 1));
  await expect(page.locator('.stat-card:has-text("管理员数量") .stat-val')).toHaveText(String(baseAdminCount));

  // Delete backup and verify count decreased
  await page.goto('/admin/backups.php');
  const backupRow = page.locator(`tr:has(input[name="filename"][value="${createdBackup}"])`).first();
  await expect(backupRow).toBeVisible();
  await backupRow.getByRole('button', { name: /删除/ }).click();
  await expect(page.locator('#riverops-confirm-modal')).toBeVisible();
  await page.locator('#riverops-confirm-ok').click();
  await page.waitForLoadState('domcontentloaded');

  await page.goto('/admin/index.php');
  await expect(page.locator('.stat-card:has-text("备份记录") .stat-val')).toHaveText(String(baseBackupCount));

  // Test quick-action links
  await page.goto('/admin/index.php');
  await expect(page.locator('.quick-actions')).toBeVisible();

  await page.locator('.quick-actions').getByRole('link', { name: /系统设置/ }).click();
  await expect(page).toHaveURL(/admin\/settings\.php/);

  await page.goto('/admin/index.php');
  await page.locator('.quick-actions').getByRole('link', { name: /备份管理/ }).click();
  await expect(page).toHaveURL(/admin\/backups\.php/);

  await page.goto('/admin/index.php');
  await page.locator('.quick-actions').getByRole('link', { name: /计划任务/ }).click();
  await expect(page).toHaveURL(/admin\/scheduled_tasks\.php/);

  // Test non-admin user cannot access dashboard
  const regularContext = await page.context().browser()?.newContext();
  if (!regularContext) throw new Error('Failed to create new browser context');
  const regularPage = await regularContext.newPage();
  await regularPage.goto('/login.php');
  await regularPage.locator('input[name="username"]').fill(regularUser);
  await regularPage.locator('input[name="password"]').fill('Regular@test2026');
  await regularPage.getByRole('button', { name: /登\s*录/ }).click();
  await expect(regularPage).toHaveURL(/index\.php|/);

  await regularPage.goto('/admin/index.php');
  await expect(regularPage.locator('body')).toContainText(/403 Forbidden: 需要管理员权限。|控制台/);

  await regularContext.close();

  await tracker.assertNoClientErrors();
});
