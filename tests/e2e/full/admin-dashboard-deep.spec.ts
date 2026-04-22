import { test, expect } from '../../helpers/fixtures';
import { attachClientErrorTracking, clickAdminNav, loginAsDevAdmin, submitVisibleModal } from '../../helpers/auth';

test('admin dashboard stat cards reflect created data and quick actions work', async ({ page }) => {
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
  const gid = `dash-group-${ts}`;
  const sid = `dash-site-${ts}`;
  const regularUser = `regular_${ts}`;

  await loginAsDevAdmin(page);

  // Get baseline stats
  await page.goto('/admin/index.php');
  await expect(page.locator('.stat-grid')).toBeVisible();
  const baseSiteCount = parseInt(await page.locator('.stat-card:has-text("站点数量") .stat-val').textContent() || '0', 10);
  const baseGroupCount = parseInt(await page.locator('.stat-card:has-text("分组数量") .stat-val').textContent() || '0', 10);
  const baseUserCount = parseInt(await page.locator('.stat-card:has-text("账户数量") .stat-val').textContent() || '0', 10);
  const baseBackupCount = parseInt(await page.locator('.stat-card:has-text("备份记录") .stat-val').textContent() || '0', 10);

  // Create a group
  await clickAdminNav(page, /分组管理/);
  await page.getByRole('button', { name: /添加分组/ }).click();
  await page.locator('#fi_id').fill(gid);
  await page.locator('#fi_name').fill(`仪表分组 ${ts}`);
  await page.locator('#fi_auth').selectOption('0');
  await submitVisibleModal(page);

  // Create a site
  await clickAdminNav(page, /站点管理/);
  await page.getByRole('button', { name: /添加站点/ }).click();
  await page.locator('#fi_sid').fill(sid);
  await page.locator('#fi_name').fill(`仪表站点 ${ts}`);
  await page.locator('#fi_gid').selectOption(gid);
  await page.locator('#fi_type').selectOption('external');
  await page.locator('#fi_url').fill('https://example.com/dash');
  await submitVisibleModal(page);

  // Create a backup
  await page.goto('/admin/backups.php');
  await page.getByRole('button', { name: /立即备份/ }).click();
  await expect(page.locator('body')).toContainText('备份已创建');

  // Verify stats increased
  await page.goto('/admin/index.php');
  await expect(page.locator('.stat-card:has-text("站点数量") .stat-val')).toHaveText(String(baseSiteCount + 1));
  await expect(page.locator('.stat-card:has-text("分组数量") .stat-val')).toHaveText(String(baseGroupCount + 1));
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

  // Delete backup and verify count decreased
  await page.goto('/admin/backups.php');
  const backupRow = page.locator('table tbody tr').first();
  const backupFile = await backupRow.locator('td').first().textContent() || '';
  // Extract filename from the row if possible, otherwise just delete the first one
  await backupRow.locator('form[action="backups.php"] button', { hasText: /删除/ }).click();
  await page.once('dialog', (dialog) => dialog.accept());

  await page.goto('/admin/index.php');
  await expect(page.locator('.stat-card:has-text("备份记录") .stat-val')).toHaveText(String(baseBackupCount));

  // Test quick-action links
  await page.goto('/admin/index.php');
  await expect(page.locator('.quick-actions')).toBeVisible();

  await page.locator('.quick-actions').getByRole('link', { name: /添加站点/ }).click();
  await expect(page).toHaveURL(/admin\/sites\.php/);

  await page.goto('/admin/index.php');
  await page.locator('.quick-actions').getByRole('link', { name: /添加分组/ }).click();
  await expect(page).toHaveURL(/admin\/groups\.php/);

  await page.goto('/admin/index.php');
  await page.locator('.quick-actions').getByRole('link', { name: /系统设置/ }).click();
  await expect(page).toHaveURL(/admin\/settings\.php/);

  await page.goto('/admin/index.php');
  await page.locator('.quick-actions').getByRole('link', { name: /备份管理/ }).click();
  await expect(page).toHaveURL(/admin\/backups\.php/);

  await page.goto('/admin/index.php');
  const viewFrontLink = page.locator('.quick-actions').getByRole('link', { name: /查看前台/ });
  await expect(viewFrontLink).toHaveAttribute('href', '/index.php');

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
  await expect(regularPage).toHaveURL(/login\.php/);

  await regularContext.close();

  await tracker.assertNoClientErrors();
});
