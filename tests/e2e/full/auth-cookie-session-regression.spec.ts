import { test, expect } from '../../helpers/fixtures';
import type { Page } from '@playwright/test';
import { attachClientErrorTracking } from '../../helpers/auth';
import { readContainerFile, restoreContainerFiles, runDockerPhpInline, snapshotContainerFiles } from '../../helpers/cli';

async function loginQatest(page: Page, redirectPath = '/index.php') {
  await page.goto(`/login.php?redirect=${encodeURIComponent(redirectPath)}`);
  await page.locator('input[name="username"]').fill('qatest');
  await page.locator('input[name="password"]').fill('qatest2026');
  await page.getByRole('button', { name: /登\s*录/ }).click();
  await page.waitForLoadState('domcontentloaded').catch(() => undefined);
}

test('login uses complete bridge before protected redirect and survives stale invalid cookie', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
    ],
  });

  await page.context().addCookies([
    {
      name: 'riverops_session',
      value: 'stale-invalid-cookie',
      domain: '127.0.0.1',
      path: '/',
      httpOnly: true,
      secure: false,
      sameSite: 'Lax',
    },
  ]);

  const completeResponse = page.waitForResponse((response) =>
    response.url().includes('/login.php?complete=1') && response.status() === 200
  );

  await loginQatest(page, '/admin/settings.php');
  await completeResponse;
  await expect(page).toHaveURL(/\/admin\/settings\.php/);
  await expect(page.locator('.topbar-title')).toHaveText('系统设置');

  const cookies = await page.context().cookies();
  expect(cookies.some((cookie) => cookie.name === 'riverops_session')).toBeTruthy();

  await tracker.assertNoClientErrors();
});

test('max-session page allows multi-select kick and falls back to oldest when none selected', async ({ browser }) => {
  const usersSnapshot = await snapshotContainerFiles(['/var/www/riverops/data/users.json']);
  const holders = [];
  let context;
  try {
    const setMaxResult = runDockerPhpInline(
      [
        '$file = "/var/www/riverops/data/users.json";',
        '$users = file_exists($file) ? (json_decode(file_get_contents($file), true) ?: []) : [];',
        '$users["qatest"] = $users["qatest"] ?? ["password_hash" => password_hash("qatest2026", PASSWORD_BCRYPT), "role" => "admin"];',
        '$users["qatest"]["password_hash"] = password_hash("qatest2026", PASSWORD_BCRYPT);',
        '$users["qatest"]["role"] = "admin";',
        '$users["qatest"]["permissions"] = ["*"];',
        '$users["qatest"]["max_sessions"] = 3;',
        'file_put_contents($file, json_encode($users, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);',
      ].join(' ')
    );
    expect(setMaxResult.code, setMaxResult.output).toBe(0);

    for (let i = 0; i < 3; i++) {
      const holderContext = await browser.newContext({ baseURL: 'http://127.0.0.1:58080' });
      const holderPage = await holderContext.newPage();
      await loginQatest(holderPage);
      await expect(holderPage).toHaveURL(/index\.php|\/$/);
      holders.push(holderContext);
    }

    context = await browser.newContext({ baseURL: 'http://127.0.0.1:58080' });
    const page = await context.newPage();
    await loginQatest(page);

    const checkboxes = page.locator('input[name="kick_jti[]"]');
    await expect(checkboxes.first()).toBeVisible();
    expect(await checkboxes.count()).toBeGreaterThanOrEqual(3);
    expect(await page.locator('input[name="kick_jti[]"]:checked').count()).toBeGreaterThanOrEqual(1);

    for (let i = 0; i < await checkboxes.count(); i++) {
      await checkboxes.nth(i).uncheck();
    }
    await page.getByRole('button', { name: /下线所选设备并登录/ }).click();
    await page.waitForLoadState('domcontentloaded').catch(() => undefined);
    await page.waitForTimeout(800);

    // 未勾选时由 kick_oldest=1 兜底踢掉最旧设备；如果仍然超限，再全选剩余设备完成登录。
    if (await checkboxes.count()) {
      for (let i = 0; i < await checkboxes.count(); i++) {
        await checkboxes.nth(i).check();
      }
      await page.getByRole('button', { name: /下线所选设备并登录/ }).click();
      await page.waitForLoadState('domcontentloaded').catch(() => undefined);
      await page.waitForTimeout(800);
    }

    await expect(page).toHaveURL(/index\.php|\/$/);
    await expect(page.locator('body')).not.toContainText('最大同时在线设备数');
  } finally {
    if (context) {
      await context.close();
    }
    for (const holder of holders) {
      await holder.close();
    }
    await restoreContainerFiles(usersSnapshot);
  }
});

test('internal auth verification denial writes reason to auth log', async () => {
  const result = runDockerPhpInline(
    [
      '$_SERVER["HTTP_COOKIE"] = "riverops_session=malformed-token";',
      '$_SERVER["HTTP_HOST"] = "127.0.0.1:58080";',
      '$_SERVER["REQUEST_URI"] = "/admin/index.php";',
      '$_SERVER["REMOTE_ADDR"] = "127.0.0.1";',
      'require "/var/www/riverops/public/auth/verify.php";',
    ].join(' ')
  );
  expect(result.code, result.output).toBe(0);
  const log = readContainerFile('/var/www/riverops/data/logs/auth.log');
  expect(log).toContain('AUTH_DENY');
  expect(log).toContain('reason=malformed');
});

test('revoked server session cookie can recover through fresh login then survives refresh and new tab', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
    ],
  });

  await loginQatest(page, '/admin/index.php');
  await expect(page).toHaveURL(/\/admin\/index\.php/);
  await expect(page.locator('.topbar-title')).toHaveText('控制台');

  const revokeResult = runDockerPhpInline('file_put_contents("/var/www/riverops/data/sessions.json", "{}", LOCK_EX);');
  expect(revokeResult.code, revokeResult.output).toBe(0);

  await page.goto('/admin/index.php');
  await expect(page).toHaveURL(/login\.php\?redirect=/);

  await page.locator('input[name="username"]').fill('qatest');
  await page.locator('input[name="password"]').fill('qatest2026');
  await page.getByRole('button', { name: /登\s*录/ }).click();
  await page.waitForLoadState('domcontentloaded').catch(() => undefined);
  await expect(page).toHaveURL(/\/admin\/index\.php/);

  await page.reload({ waitUntil: 'domcontentloaded' });
  await expect(page.locator('.topbar-title')).toHaveText('控制台');

  const newTab = await page.context().newPage();
  await newTab.goto('/admin/settings.php');
  await expect(newTab.locator('.topbar-title')).toHaveText('系统设置');
  await newTab.close();

  await tracker.assertNoClientErrors();
});
