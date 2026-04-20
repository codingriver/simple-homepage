import { test, expect } from '../../helpers/fixtures';
import { attachClientErrorTracking, loginAsDevAdmin, submitVisibleModal } from '../../helpers/auth';

test('admin can download nginx config and save proxy params mode', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
    ],
    ignoredFailedRequests: [
      /POST .*\/admin\/settings\.php :: net::ERR_ABORTED/,
    ],
  });

  await loginAsDevAdmin(page);
  await page.goto('/admin/settings.php#nginx');

  await page.locator('label[data-ppm-card="full"]').click();
  await page.getByRole('button', { name: /保存模式/ }).click();
  await expect(page.locator('body')).toContainText(/反代参数模式已/);
  await expect(page.locator('#ppm_full')).toBeChecked();

  const downloadPromise = page.waitForEvent('download');
  await page.locator('#nginx form:has(input[name="action"][value="gen_nginx"]) button').click();
  const download = await downloadPromise;
  expect(download.suggestedFilename()).toMatch(/nav_proxy_.*\.conf/);

  await tracker.assertNoClientErrors();
});

test('nginx settings show pending reload warning and keep feedback visible after apply action', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
    ],
    ignoredFailedRequests: [
      /GET .*\/admin\/groups\.php :: net::ERR_ABORTED/,
    ],
  });
  const ts = Date.now();
  const groupId = `nginx-group-${ts}`;
  const siteId = `nginx-proxy-${ts}`;

  await loginAsDevAdmin(page);

  await page.goto('/admin/groups.php');
  await page.getByRole('button', { name: /添加分组/ }).click();
  await page.locator('#fi_id').fill(groupId);
  await page.locator('#fi_name').fill(`Nginx 分组 ${ts}`);
  await page.locator('#fi_auth').selectOption('0');
  await submitVisibleModal(page);

  await page.goto('/admin/sites.php');
  await page.getByRole('button', { name: /添加站点/ }).click();
  await page.locator('#fi_sid').fill(siteId);
  await page.locator('#fi_name').fill('Nginx 代理站点');
  await page.locator('#fi_gid').selectOption(groupId);
  await page.locator('#fi_type').selectOption('proxy');
  await page.locator('#fi_ptarget').fill('http://192.168.1.130:8080');
  await page.locator('#fi_slug').fill(`nginx-site-${ts}`);
  await submitVisibleModal(page);

  await page.goto('/admin/settings.php#nginx');
  await page.locator('label[data-ppm-card="simple"]').click();
  await page.getByRole('button', { name: /保存模式/ }).click();
  // 页面上可能有多个 .alert-warn（Flash 消息 + JS 注入的警告），使用 filter 精确定位
  await expect(page.locator('.alert-warn').filter({ hasText: /需要重新生成配置并 Reload Nginx 才能生效/ })).toBeVisible();
  await expect(page.locator('body')).toContainText(/生成配置并 Reload Nginx/);

  await page.getByRole('button', { name: /仅生成配置文件/ }).click();
  await expect(page.locator('body')).toContainText(/反代配置已写入|Reload Nginx/);

  await page.getByRole('button', { name: /生成配置并 Reload Nginx/ }).first().click();
  await expect(page.locator('body')).toContainText(/Reload Nginx|失败|成功|写入/);

  await tracker.assertNoClientErrors();
});
