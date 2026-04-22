import { test, expect } from '../../helpers/fixtures';
import { attachClientErrorTracking, loginAsDevAdmin } from '../../helpers/auth';

test('theme switching and custom css persist across refreshes on admin and public pages', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
    ],
    ignoredFailedRequests: [
      /GET .*\/admin\/settings_ajax\.php\?action=nginx_sudo :: net::ERR_ABORTED/,
      /GET .*\/favicon\.php\?url=.*:: net::ERR_ABORTED/,
    ],
  });

  await loginAsDevAdmin(page);
  await page.goto('/admin/settings.php');
  const csrf = await page.locator('input[name="_csrf"]').first().inputValue();

  // Switch to light theme
  const saveLight = await page.request.post('http://127.0.0.1:58080/admin/settings.php', {
    form: {
      _csrf: csrf,
      action: 'save_settings',
      site_name: 'ThemeTest',
      theme: 'light',
      custom_css: 'body { border: 3px solid rgb(255, 0, 0); }',
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
  expect(saveLight.status()).toBe(200);

  // Verify theme on public page
  await page.goto('/index.php?_t=' + Date.now());
  await expect(page.locator('html')).toHaveAttribute('data-theme', 'light');
  const publicCss = await page.evaluate(() => (document.getElementById('nav-custom-css') as HTMLStyleElement)?.textContent || '');
  expect(publicCss).toContain('rgb(255, 0, 0)');

  // Refresh and verify persistence on public page
  await page.reload();
  await expect(page.locator('html')).toHaveAttribute('data-theme', 'light');

  // Verify theme on admin page
  await page.goto('/admin/settings.php?_t=' + Date.now());
  // admin header doesn't use data-theme, but settings form should show light selected
  await expect(page.locator('select[name="theme"]')).toHaveValue('light');

  // Switch to dark theme
  const saveDark = await page.request.post('http://127.0.0.1:58080/admin/settings.php', {
    form: {
      _csrf: csrf,
      action: 'save_settings',
      site_name: 'ThemeTest',
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
  expect(saveDark.status()).toBe(200);

  // Verify dark theme persists
  await page.goto('/index.php?_t=' + Date.now());
  await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');
  const publicCssDark = await page.evaluate(() => (document.getElementById('nav-custom-css') as HTMLStyleElement)?.textContent || '');
  expect(publicCssDark).not.toContain('rgb(255, 0, 0)');

  await tracker.assertNoClientErrors();
});
