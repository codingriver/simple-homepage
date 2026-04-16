import { test, expect } from '../../helpers/fixtures';
import { attachClientErrorTracking, loginAsDevAdmin, submitVisibleModal } from '../../helpers/auth';

test('pwa manifest and service worker are registered', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
    ],
    ignoredFailedRequests: [
      /401 \(Unauthorized\)/,
    ],
  });

  // 需要登录后才能访问 index.php（空分组时未登录会重定向到登录页）
  await loginAsDevAdmin(page);
  await page.goto('/index.php');

  const manifest = await page.request.get('http://127.0.0.1:58080/manifest.webmanifest');
  expect(manifest.status()).toBe(200);
  const manifestBody = await manifest.json();
  expect(manifestBody.display).toBe('standalone');

  // 等待 SW 激活
  await page.waitForFunction(() => {
    return !!(navigator.serviceWorker && navigator.serviceWorker.controller);
  }, { timeout: 15000 });
  const swUrl = await page.evaluate(() => {
    return navigator.serviceWorker?.controller?.scriptURL || '';
  });
  expect(swUrl).toContain('sw.js');

  await tracker.assertNoClientErrors();
});

test('command palette opens with ctrl+k and filters sites', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page);

  await loginAsDevAdmin(page);

  // Seed a group and a site so the command palette has data to search
  const gid = `cmdk-group-${Date.now()}`;
  await page.goto('/admin/groups.php');
  await page.getByRole('button', { name: /添加分组/ }).click();
  await page.locator('#fi_id').fill(gid);
  await page.locator('#fi_name').fill('TestGroup');
  await page.locator('#fi_icon').fill('🚀');
  await page.locator('#fi_auth').selectOption('0');
  await submitVisibleModal(page);
  await expect(page.locator(`tr:has(input[name="gid"][value="${gid}"])`).first()).toBeVisible();

  await page.goto('/admin/sites.php');
  await page.getByRole('button', { name: /添加站点/ }).first().click();
  await page.locator('#fi_sid').fill(`cmdk-site-${Date.now()}`);
  await page.locator('#fi_name').fill('Nginx Proxy Site');
  await page.locator('#fi_gid').selectOption(gid);
  await page.locator('#fi_type').selectOption('external');
  await page.locator('#fi_url').fill('https://example.com');
  await submitVisibleModal(page);
  await expect(page.locator('td', { hasText: 'Nginx Proxy Site' }).first()).toBeVisible();

  await page.goto('/index.php');
  await page.keyboard.press('Control+k');
  await expect(page.locator('#cmdk-panel')).toBeVisible();

  await page.locator('#cmdk-input').fill('Nginx');
  await expect(page.locator('.cmdk-item').first()).toBeVisible();

  await page.evaluate(() => {
    (document.getElementById('cmdk-overlay') as HTMLElement)?.click();
  });
  await page.waitForTimeout(300);
  await expect(page.locator('#cmdk-panel')).not.toHaveClass('open');

  await tracker.assertNoClientErrors();
});

test('theme and custom css are applied from settings', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredFailedRequests: [
      /GET .*\/favicon\.php\?url=.*:: net::ERR_ABORTED/,
    ],
  });

  await loginAsDevAdmin(page);
  await page.goto('/admin/settings.php');
  const csrf = await page.locator('input[name="_csrf"]').first().inputValue();

  // 直接 POST 保存，避免复杂表单交互
  const saveRes = await page.request.post('http://127.0.0.1:58080/admin/settings.php', {
    form: {
      _csrf: csrf,
      action: 'save_settings',
      site_name: 'Test',
      theme: 'light',
      custom_css: 'body { border: 2px solid rgb(0, 255, 0); }',
      nav_domain: '',
      token_expire_hours: '8',
      remember_me_days: '60',
      login_fail_limit: '5',
      login_lock_minutes: '15',
      cookie_secure: 'off',
      cookie_domain: '',
      ssh_terminal_persist: '1',
      ssh_terminal_idle_minutes: '120',
      card_size: '140',
      card_height: '0',
      card_layout: 'grid',
      card_direction: 'col',
    },
  });
  expect(saveRes.status()).toBe(200);

  await page.goto('/index.php?_t=' + Date.now());
  await expect(page.locator('html')).toHaveAttribute('data-theme', 'light');
  const cssText = await page.evaluate(() => (document.getElementById('nav-custom-css') as HTMLStyleElement)?.textContent || '');
  expect(cssText).toContain('rgb(0, 255, 0)');

  // cleanup: restore dark
  await page.request.post('http://127.0.0.1:58080/admin/settings.php', {
    form: {
      _csrf: csrf,
      action: 'save_settings',
      site_name: 'Test',
      theme: 'dark',
      custom_css: '',
      nav_domain: '',
      token_expire_hours: '8',
      remember_me_days: '60',
      login_fail_limit: '5',
      login_lock_minutes: '15',
      cookie_secure: 'off',
      cookie_domain: '',
      ssh_terminal_persist: '1',
      ssh_terminal_idle_minutes: '120',
      card_size: '140',
      card_height: '0',
      card_layout: 'grid',
      card_direction: 'col',
    },
  });

  await tracker.assertNoClientErrors();
});
