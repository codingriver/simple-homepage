import { test, expect } from '@playwright/test';
import { attachClientErrorTracking, loginAsDevAdmin, submitVisibleModal } from '../../helpers/auth';

test('admin can create proxy sites in path and domain modes', async ({ page }) => {
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
  const groupId = `proxy-group-${ts}`;
  const pathSiteId = `proxy-path-${ts}`;
  const domainSiteId = `proxy-domain-${ts}`;
  const pathSlug = `path-app-${ts}`;
  const domainHost = `app${ts}.example.test`;

  await loginAsDevAdmin(page);

  await page.goto('/admin/groups.php');
  await page.getByRole('button', { name: /添加分组/ }).click();
  await page.locator('#fi_id').fill(groupId);
  await page.locator('#fi_name').fill(`代理模式分组 ${ts}`);
  await page.locator('#fi_auth').selectOption('0');
  await submitVisibleModal(page);
  await expect(page.locator(`tr:has(input[name="gid"][value="${groupId}"])`).first()).toBeVisible();

  await page.goto('/admin/sites.php');
  await page.getByRole('button', { name: /添加站点/ }).click();
  await page.locator('#fi_sid').fill(pathSiteId);
  await page.locator('#fi_name').fill('路径代理站点');
  await page.locator('#fi_gid').selectOption(groupId);
  await page.locator('#fi_type').selectOption('proxy');
  await page.locator('#fi_pmode').selectOption('path');
  await page.locator('#fi_ptarget').fill('http://192.168.1.100:8080');
  await page.locator('#fi_slug').fill(pathSlug);
  await submitVisibleModal(page);

  const pathRow = page.locator(`tr:has(input[name="sid"][value="${pathSiteId}"])`).first();
  await expect(pathRow).toContainText('路径代理站点');
  await expect(pathRow).toContainText('http://192.168.1.100:8080');

  await page.getByRole('button', { name: /添加站点/ }).click();
  await page.locator('#fi_sid').fill(domainSiteId);
  await page.locator('#fi_name').fill('域名代理站点');
  await page.locator('#fi_gid').selectOption(groupId);
  await page.locator('#fi_type').selectOption('proxy');
  await page.locator('#fi_pmode').selectOption('domain');
  await page.locator('#fi_ptarget').fill('http://192.168.1.101:9090');
  await page.locator('#fi_slug').fill('unused-slug');
  await page.locator('#fi_pdomain').fill(domainHost);
  await submitVisibleModal(page);

  const domainRow = page.locator(`tr:has(input[name="sid"][value="${domainSiteId}"])`).first();
  await expect(domainRow).toContainText('域名代理站点');
  await expect(domainRow).toContainText('http://192.168.1.101:9090');

  await page.goto('/index.php');
  const pathCard = page.locator(`a.card[href="/p/${pathSlug}/"]`).first();
  const domainCard = page.locator(`a.card[href="https://${domainHost}/"]`).first();
  await expect(pathCard).toContainText('路径代理站点');
  await expect(domainCard).toContainText('域名代理站点');

  await tracker.assertNoClientErrors();
});

test('proxy mode switch updates fields and shows pending reload notice', async ({ page }) => {
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
  const groupId = `proxy-link-group-${ts}`;
  const siteId = `proxy-link-site-${ts}`;
  const domainHost = `pending${ts}.example.test`;

  await loginAsDevAdmin(page);

  await page.goto('/admin/groups.php');
  await page.getByRole('button', { name: /添加分组/ }).click();
  await page.locator('#fi_id').fill(groupId);
  await page.locator('#fi_name').fill(`代理联动分组 ${ts}`);
  await page.locator('#fi_auth').selectOption('0');
  await submitVisibleModal(page);

  await page.goto('/admin/sites.php');
  await page.getByRole('button', { name: /添加站点/ }).click();
  await page.locator('#fi_sid').fill(siteId);
  await page.locator('#fi_name').fill('待生效代理站点');
  await page.locator('#fi_gid').selectOption(groupId);
  await page.locator('#fi_type').selectOption('proxy');
  await page.locator('#fi_pmode').selectOption('path');
  await page.locator('#fi_ptarget').fill('http://192.168.1.120:8080');
  await page.locator('#fi_slug').fill(`proxy-link-${ts}`);
  await submitVisibleModal(page);

  const row = page.locator(`tr:has(input[name="sid"][value="${siteId}"])`).first();
  await row.getByRole('button', { name: '编辑' }).click();
  await page.locator('#fi_pmode').selectOption('domain');
  await page.locator('#fi_pdomain').fill(domainHost);
  await page.locator('#fi_slug').fill('still-present');
  await submitVisibleModal(page);

  await page.goto('/admin/settings.php#nginx');
  await expect(page.locator('#proxy-pending-bar')).toBeVisible();
  await expect(page.locator('#proxy-pending-bar')).toContainText(/尚未生效|未在 Nginx 生效/);
  await expect(page.locator('body')).toContainText(/Reload Nginx|生成配置并 Reload Nginx/);

  await tracker.assertNoClientErrors();
});
