import { test, expect } from '@playwright/test';
import { attachClientErrorTracking, loginAsDevAdmin } from '../../helpers/auth';

test('settings persist all core fields and support background upload clear and health metadata', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
    ],
    ignoredFailedRequests: [/settings_ajax\.php\?action=nginx_sudo/],
  });
  const onePixelPng = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9l9j0AAAAASUVORK5CYII=', 'base64');
  const siteName = `全字段设置 ${Date.now()}`;

  await loginAsDevAdmin(page);
  await page.goto('/admin/settings.php');

  await page.locator('input[name="site_name"]').fill(siteName);
  await page.locator('input[name="nav_domain"]').fill('nav.example.test');
  await page.locator('input[name="token_expire_hours"]').fill('12');
  await page.locator('input[name="remember_me_days"]').fill('33');
  await page.locator('input[name="login_fail_limit"]').fill('7');
  await page.locator('input[name="login_lock_minutes"]').fill('19');
  await page.locator('select[name="cookie_secure"]').selectOption('auto');
  await page.locator('input[name="cookie_domain"]').fill('');
  await page.locator('select[name="card_layout"]').selectOption('compact');
  await page.locator('select[name="card_direction"]').selectOption('row');
  await page.locator('input[name="card_size_custom"]').fill('180');
  await page.locator('input[name="card_height_custom"]').fill('120');
  await page.locator('input[name="bg_color"]').fill('#112233');
  await page.locator('input[name="bg_image"]').setInputFiles({ name: 'bg.png', mimeType: 'image/png', buffer: onePixelPng });
  await page.getByRole('button', { name: /保存设置/ }).click();
  await expect(page.locator('body')).toContainText('设置已保存');

  await page.reload();
  await expect(page.locator('input[name="site_name"]')).toHaveValue(siteName);
  await expect(page.locator('input[name="nav_domain"]')).toHaveValue('nav.example.test');
  await expect(page.locator('input[name="token_expire_hours"]')).toHaveValue('12');
  await expect(page.locator('input[name="remember_me_days"]')).toHaveValue('33');
  await expect(page.locator('input[name="login_fail_limit"]')).toHaveValue('7');
  await expect(page.locator('input[name="login_lock_minutes"]')).toHaveValue('19');
  await expect(page.locator('select[name="cookie_secure"]')).toHaveValue('auto');
  await expect(page.locator('select[name="card_layout"]')).toHaveValue('compact');
  await expect(page.locator('select[name="card_direction"]')).toHaveValue('row');

  const ajax = await page.request.get('http://127.0.0.1:58080/admin/settings_ajax.php?action=health_sites_meta', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
  });
  expect(ajax.status()).toBe(200);
  const json = await ajax.json();
  expect(json.ok).toBeTruthy();
  expect(Array.isArray(json.sites)).toBeTruthy();

  await page.locator('input[name="clear_bg_image"]').check();
  await page.getByRole('button', { name: /保存设置/ }).click();
  await expect(page.locator('body')).toContainText('设置已保存');

  await tracker.assertNoClientErrors();
});
