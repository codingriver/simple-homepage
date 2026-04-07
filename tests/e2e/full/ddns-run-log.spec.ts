import { test, expect } from '@playwright/test';
import { attachClientErrorTracking, loginAsDevAdmin } from '../../helpers/auth';

async function createDdnsTask(page: Parameters<typeof loginAsDevAdmin>[0], name: string, domain: string) {
  await page.goto('/admin/ddns.php');
  await page.getByRole('button', { name: /新建任务/ }).click();
  await page.locator('#fm-name').fill(name);
  await page.locator('#fm-source-type').selectOption('local_ipv4');
  await page.locator('#fm-domain').fill(domain);
  await page.getByRole('button', { name: /^保存$/ }).click();
  return page.locator(`tr:has-text("${name}")`).first();
}

test('ddns run and log modal support execution pagination search and clear', async ({ page }) => {
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
  const row = await createDdnsTask(page, taskName, domain);
  await row.getByRole('button', { name: /执行/ }).click();
  await expect(page.locator('body')).toContainText(/执行完成|执行失败|跳过/);

  await row.getByRole('button', { name: /日志/ }).click();
  await expect(page.locator('#ddns-log-modal')).toBeVisible();
  await expect(page.locator('#ddns-log-info')).toContainText(/共 .* 行|最新日志优先显示/);

  await page.locator('#ddns-log-search').fill('success');
  await expect(page.locator('#ddns-log-body')).toContainText(/暂无日志记录|当前页没有匹配|success|成功/);
  await page.getByRole('button', { name: /清空搜索/ }).click();

  page.once('dialog', (dialog) => dialog.accept());
  await page.getByRole('button', { name: /清空日志/ }).click();
  await expect(page.locator('#ddns-log-body')).toContainText(/暂无日志记录|当前页没有匹配/);

  await tracker.assertNoClientErrors();
});

test('ddns test source shows structured result feedback', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
      /Failed to load resource: the server responded with a status of 500 \(Internal Server Error\)/,
    ],
  });

  await loginAsDevAdmin(page);
  await page.goto('/admin/ddns.php');
  await page.getByRole('button', { name: /新建任务/ }).click();
  await page.locator('#fm-name').fill('DDNS 来源测试');
  await page.locator('#fm-domain').fill(`ddns-source-${Date.now()}.606077.xyz`);
  await page.locator('#fm-source-type').selectOption('api4ce_cfip');
  await page.getByRole('button', { name: /测试来源/ }).click();
  await expect(page.locator('#fm-test-result')).toContainText(/状态：成功|状态：失败/);

  await tracker.assertNoClientErrors();
});
