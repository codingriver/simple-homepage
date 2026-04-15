import { test, expect } from '@playwright/test';
import { attachClientErrorTracking, loginAsDevAdmin, submitVisibleModal } from '../../helpers/auth';

test('admin sees validation feedback for invalid duplicate and long-name groups', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
    ],
    ignoredFailedRequests: [
      /GET .*\/admin\/groups\.php :: net::ERR_ABORTED/,
      /GET .*\/favicon\.php\?url=https%3A%2F%2Fexample\.com :: net::ERR_ABORTED/,
    ],
  });
  const gid = `dup-group-${Date.now()}`;

  await loginAsDevAdmin(page);
  await page.goto('/admin/groups.php');

  page.once('dialog', dialog => {
    expect(dialog.message()).toContain('ID 只允许小写字母/数字/下划线/横杠，名称不能为空');
    dialog.accept();
  });
  await page.getByRole('button', { name: /添加分组/ }).click();
  await page.locator('#fi_id').fill('Bad Group');
  await page.locator('#fi_name').fill('');
  await page.getByRole('button', { name: '保存' }).click({ force: true });

  await page.locator('#fi_id').fill(gid);
  await page.locator('#fi_name').fill('初始分组');
  await page.locator('#fi_vis').selectOption('all');
  await page.locator('#fi_auth').selectOption('0');
  await page.locator('#fi_order').fill('999');
  await page.getByRole('button', { name: '保存' }).click({ force: true });

  const row = page.locator(`tr:has(input[name="gid"][value="${gid}"])`).first();
  await expect(row).toContainText('初始分组');
  await expect(row).toContainText('公开');

  await page.getByRole('button', { name: /添加分组/ }).click();
  await page.locator('#fi_id').fill(gid);
  await page.locator('#fi_name').fill('覆盖后的分组');
  await page.locator('#fi_vis').selectOption('admin');
  await page.locator('#fi_auth').selectOption('1');
  await page.getByRole('button', { name: '保存' }).click({ force: true });

  await expect(page.locator(`tr:has(input[name="gid"][value="${gid}"])`)).toHaveCount(1);
  await expect(row).toContainText('覆盖后的分组');
  await expect(row).toContainText('需登录');
  await expect(row).toContainText('仅Admin');

  await page.getByRole('button', { name: /添加分组/ }).click();
  await page.locator('#fi_id').fill(`long-name-${Date.now()}`);
  await page.locator('#fi_name').fill('超长分组名称'.repeat(20));
  await page.getByRole('button', { name: '保存' }).click({ force: true });

  await tracker.assertNoClientErrors();
});

test('deleting a group removes its linked site from admin and homepage', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
    ],
    ignoredFailedRequests: [
      /GET .*\/admin\/groups\.php :: net::ERR_ABORTED/,
      /GET .*\/favicon\.php\?url=https%3A%2F%2Fexample\.com :: net::ERR_ABORTED/,
    ],
  });
  const ts = Date.now();
  const gid = `linked-group-${ts}`;
  const sid = `linked-site-${ts}`;

  await loginAsDevAdmin(page);

  await page.goto('/admin/groups.php');
  await page.getByRole('button', { name: /添加分组/ }).click();
  await page.locator('#fi_id').fill(gid);
  await page.locator('#fi_name').fill(`关联分组 ${ts}`);
  await page.locator('#fi_auth').selectOption('0');
  await submitVisibleModal(page);
  await expect(page.locator(`tr:has(input[name="gid"][value="${gid}"])`).first()).toBeVisible();

  await page.goto('/admin/sites.php');
  await page.getByRole('button', { name: /添加站点/ }).click();
  await page.locator('#fi_sid').fill(sid);
  await page.locator('#fi_name').fill('关联站点');
  await page.locator('#fi_gid').selectOption(gid);
  await page.locator('#fi_type').selectOption('external');
  await page.locator('#fi_url').fill('https://example.com');
  await submitVisibleModal(page);
  await expect(page.locator(`tr:has(input[name="sid"][value="${sid}"])`).first()).toBeVisible();

  await page.goto('/index.php');
  await expect(page.locator('body')).toContainText('关联站点');

  await page.goto('/admin/groups.php');
  const groupRow = page.locator(`tr:has(input[name="gid"][value="${gid}"])`).first();
  page.once('dialog', dialog => dialog.accept());
  await Promise.all([
    page.waitForURL(/\/admin\/groups\.php/),
    groupRow.locator('form').evaluate((form) => {
      (form as HTMLFormElement).requestSubmit();
    }),
  ]);
  await expect(page.locator(`tr:has(input[name="gid"][value="${gid}"])`)).toHaveCount(0);

  await page.goto('/admin/sites.php');
  await expect(page.locator(`tr:has(input[name="sid"][value="${sid}"])`)).toHaveCount(0);

  await page.goto('/index.php');
  await expect(page.locator('body')).not.toContainText('关联站点');

  await tracker.assertNoClientErrors();
});
