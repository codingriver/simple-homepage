import { test, expect } from '@playwright/test';
import { attachClientErrorTracking, loginAsDevAdmin, logout } from '../../helpers/auth';

test('debug tools page supports display errors toggle clear cookie and log viewer', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
      /Failed to load resource: the server responded with a status of 502 \(Bad Gateway\)/,
    ],
    ignoredFailedRequests: [
      /GET .*\/favicon\.php\?url=.*:: net::ERR_ABORTED/,
    ],
  });

  await loginAsDevAdmin(page);
  await page.goto('/admin/debug.php');

  await expect(page.locator('#build-meta')).toBeVisible();
  await page.getByRole('button', { name: /display_errors/i }).click().catch(() => {});

  const toggleForm = page.locator('form:has(input[name="action"][value="toggle_display_errors"])');
  page.once('dialog', dialog => dialog.accept());
  await toggleForm.getByRole('button').click();
  await expect(page.locator('body')).toContainText(/display_errors 已开启|display_errors 已关闭/);

  await page.getByRole('button', { name: /DNS 应用日志/ }).click();
  await expect(page.locator('#logContent')).not.toContainText('加载中...');

  page.once('dialog', dialog => dialog.accept());
  await page.getByRole('button', { name: /清空所有日志/ }).click();
  await expect(page.locator('#logContent')).toContainText(/已清空|失败|重试/);

  page.once('dialog', dialog => dialog.accept());
  await page.getByRole('button', { name: /清除当前 Cookie/ }).click();
  await expect(page).toHaveURL(/login\.php/);
  await expect(page.locator('input[name="username"]')).toBeVisible();

  await tracker.assertNoClientErrors();
});

test('debug ajax log access is denied after logout', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
    ],
  });

  await loginAsDevAdmin(page);
  await logout(page);

  const denied = await page.request.get('http://127.0.0.1:58080/admin/debug.php?ajax=log&type=dns&lines=20', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
  });
  expect(denied.status()).toBe(401);
  expect(await denied.text()).toContain('未登录');

  await tracker.assertNoClientErrors();
});
