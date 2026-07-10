import { test, expect } from '../../helpers/fixtures';
import { attachClientErrorTracking, loginAsDevAdmin } from '../../helpers/auth';
import { runDockerPhpInline } from '../../helpers/cli';

async function readSessionCookie(page: Parameters<typeof attachClientErrorTracking>[0]) {
  const cookies = await page.context().cookies();
  return cookies.find(cookie => cookie.name === 'nav_session');
}

test('cookie policy settings persist and affect session cookie flags', async ({ browser }) => {
  const adminContext = await browser.newContext({ baseURL: 'http://127.0.0.1:58080' });
  const adminPage = await adminContext.newPage();
  const tracker = await attachClientErrorTracking(adminPage, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
    ],
    ignoredFailedRequests: [
      /GET .*\/admin\/settings_ajax\.php\?action=nginx_sudo :: net::ERR_ABORTED/,
      /GET .*\/admin\/index\.php :: net::ERR_ABORTED/,
    ],
  });

  await loginAsDevAdmin(adminPage);
  await adminPage.goto('/admin/settings.php');

  const originalPolicy = await adminPage.locator('select[name="cookie_secure"]').inputValue();
  const originalDomain = await adminPage.locator('input[name="cookie_domain"]').inputValue();

  await adminPage.locator('select[name="cookie_secure"]').selectOption('on');
  await adminPage.locator('input[name="cookie_domain"]').fill('.example.test');
  await adminPage.getByRole('button', { name: /保存设置/ }).click();
  await expect(adminPage.locator('body')).toContainText('设置已保存');

  await adminPage.reload();
  await expect(adminPage.locator('select[name="cookie_secure"]')).toHaveValue('on');
  await expect(adminPage.locator('input[name="cookie_domain"]')).toHaveValue('.example.test');

  await adminContext.close();

  const ipContext = await browser.newContext({
    baseURL: 'http://127.0.0.1:58080',
  });
  const ipPage = await ipContext.newPage();
  const ipTracker = await attachClientErrorTracking(ipPage, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
    ],
    ignoredFailedRequests: [
      /GET .*\/admin\/settings_ajax\.php\?action=nginx_sudo :: net::ERR_ABORTED/,
      /GET .*\/admin\/index\.php :: net::ERR_ABORTED/,
    ],
  });

  await ipPage.goto('/login.php');
  await ipPage.locator('input[name="username"]').fill('qatest');
  await ipPage.locator('input[name="password"]').fill('qatest2026');
  await ipPage.getByRole('button', { name: /登\s*录/ }).click();
  await expect(ipPage).toHaveURL(/index\.php|\/$/);

  const ipCookie = await readSessionCookie(ipPage);
  expect(ipCookie).toBeTruthy();
  expect(ipCookie?.secure).toBeFalsy();
  expect(ipCookie?.domain === '127.0.0.1' || ipCookie?.domain === undefined || ipCookie?.domain === '').toBeTruthy();

  await ipContext.close();

  const restore = runDockerPhpInline(
    [
      '$file="/var/www/nav/data/config.json";',
      '$cfg=file_exists($file)?(json_decode(file_get_contents($file), true)?:[]):[];',
      '$cfg["cookie_secure"]=$argv[1];',
      '$cfg["cookie_domain"]=$argv[2];',
      'file_put_contents($file, json_encode($cfg, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES), LOCK_EX);',
    ].join(' '),
    [originalPolicy, originalDomain]
  );
  expect(restore.code, restore.output).toBe(0);

  await tracker.assertNoClientErrors();
  await ipTracker.assertNoClientErrors();

});
