import { test, expect } from '@playwright/test';
import { attachClientErrorTracking, loginAsDevAdmin, submitVisibleModal } from '../../helpers/auth';

test('admin sees validation errors when creating invalid proxy site', async ({ page }) => {
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
  const groupId = `site-validate-group-${ts}`;

  await loginAsDevAdmin(page);

  await page.goto('/admin/groups.php');
  await page.getByRole('button', { name: /添加分组/ }).click();
  await page.locator('#fi_id').fill(groupId);
  await page.locator('#fi_name').fill(`校验分组 ${ts}`);
  await page.locator('#fi_auth').selectOption('0');
  await submitVisibleModal(page);
  await expect(page.locator(`tr:has(input[name="gid"][value="${groupId}"])`).first()).toBeVisible();

  await page.goto('/admin/sites.php');
  page.once('dialog', dialog => {
    expect(dialog.message()).toContain('代理目标必须是 RFC1918 内网IPv4地址');
    dialog.accept();
  });

  await page.getByRole('button', { name: /添加站点/ }).click();
  await page.locator('#fi_sid').fill(`site-invalid-${ts}`);
  await page.locator('#fi_name').fill('非法代理站点');
  await page.locator('#fi_gid').selectOption(groupId);
  await page.locator('#fi_type').selectOption('proxy');
  await page.locator('#fi_ptarget').fill('https://example.com');
  await submitVisibleModal(page);

  await expect(page.locator(`tr:has(input[name="sid"][value="site-invalid-${ts}"])`)).toHaveCount(0);
  await tracker.assertNoClientErrors();
});

test('admin sees boundary validation for invalid site id empty name and missing group', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
    ],
  });

  await loginAsDevAdmin(page);
  await page.goto('/admin/sites.php');

  page.once('dialog', dialog => {
    expect(dialog.message()).toContain('站点ID只允许小写字母数字下划线横杠');
    dialog.accept();
  });
  await page.getByRole('button', { name: /添加站点/ }).click();
  await page.locator('#fi_sid').fill('Bad Site');
  await page.locator('#fi_name').fill('非法ID站点');
  await page.locator('#fi_type').selectOption('external');
  await page.locator('#fi_url').fill('https://example.com');
  await submitVisibleModal(page);
  await expect(page.locator('tr:has-text("非法ID站点")')).toHaveCount(0);

  page.once('dialog', dialog => {
    expect(dialog.message()).toContain('名称不能为空');
    dialog.accept();
  });
  await page.getByRole('button', { name: /添加站点/ }).click();
  await page.locator('#fi_sid').fill(`site-empty-name-${Date.now()}`);
  await page.locator('#fi_name').fill('');
  await page.locator('#fi_type').selectOption('external');
  await page.locator('#fi_url').fill('https://example.com');
  await submitVisibleModal(page);

  page.once('dialog', dialog => {
    expect(dialog.message()).toContain('请选择所属分组');
    dialog.accept();
  });
  await page.getByRole('button', { name: /添加站点/ }).click();
  await page.locator('#fi_sid').fill(`site-no-group-${Date.now()}`);
  await page.locator('#fi_name').fill('未选分组站点');
  await page.locator('#fi_gid').evaluate((select: HTMLSelectElement) => {
    select.innerHTML = '<option value="">(未选择)</option>';
    select.value = '';
  });
  await page.locator('#fi_type').selectOption('external');
  await page.locator('#fi_url').fill('https://example.com');
  await submitVisibleModal(page);

  page.once('dialog', dialog => dialog.accept());
  await page.getByRole('button', { name: /添加站点/ }).click();
  await page.locator('#fi_sid').fill(`site-slug-empty-${Date.now()}`);
  await page.locator('#fi_name').fill('空 slug 代理');
  await page.locator('#fi_type').selectOption('proxy');
  await page.locator('#fi_pmode').selectOption('path');
  await page.locator('#fi_ptarget').fill('http://192.168.1.88:8080');
  await page.locator('#fi_slug').fill('');
  await submitVisibleModal(page);

  await tracker.assertNoClientErrors();
});
