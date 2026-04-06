import fs from 'fs/promises';
import path from 'path';
import { test, expect } from '@playwright/test';
import { attachClientErrorTracking, loginAsDevAdmin, logout } from '../../helpers/auth';

test('login failure and dev-admin login/logout flow', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
    ],
  });

  await page.goto('/login.php');
  await page.locator('input[name="username"]').fill('qatest');
  await page.locator('input[name="password"]').fill('wrongpass');
  await page.getByRole('button', { name: /登\s*录/ }).click();
  await expect(page.getByText('用户名或密码错误')).toBeVisible();

  await loginAsDevAdmin(page);
  await page.goto('/admin/index.php');
  await expect(page.locator('.topbar-title')).toHaveText('控制台');

  await logout(page);
  await page.goto('/admin/index.php');
  await expect(page).toHaveURL(/login\.php\?redirect=/);

  await tracker.assertNoClientErrors();
});

test('login lockout blocks repeated failures for the same IP and can be reset after verification', async ({ browser }) => {
  const ipLocksPath = path.resolve(__dirname, '../../../data/ip_locks.json');
  const originalIpLocks = await fs.readFile(ipLocksPath, 'utf8');

  const adminContext = await browser.newContext({
    extraHTTPHeaders: { 'X-Real-IP': '10.0.0.11' },
  });
  const lockedContext = await browser.newContext({
    extraHTTPHeaders: { 'X-Real-IP': '10.0.0.22' },
  });

  const adminPage = await adminContext.newPage();
  const lockedPage = await lockedContext.newPage();

  const adminTracker = await attachClientErrorTracking(adminPage, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
    ],
  });
  const lockedTracker = await attachClientErrorTracking(lockedPage, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
    ],
  });

  try {
    await loginAsDevAdmin(adminPage);
    await adminPage.goto('/admin/settings.php');

    const originalLimit = await adminPage.locator('input[name="login_fail_limit"]').inputValue();
    const originalMinutes = await adminPage.locator('input[name="login_lock_minutes"]').inputValue();

    await adminPage.locator('input[name="login_fail_limit"]').fill('2');
    await adminPage.locator('input[name="login_lock_minutes"]').fill('1');
    await adminPage.getByRole('button', { name: /保存设置/ }).click();
    await expect(adminPage.locator('body')).toContainText('设置已保存');

    await lockedPage.goto('/login.php');
    for (let i = 0; i < 2; i += 1) {
      await lockedPage.locator('input[name="username"]').fill('qatest');
      await lockedPage.locator('input[name="password"]').fill('wrongpass');
      await lockedPage.getByRole('button', { name: /登\s*录/ }).click();
    }

    await expect(lockedPage.getByText(/连续登录失败次数过多|IP 已被临时锁定/)).toBeVisible();

    await lockedPage.locator('input[name="username"]').fill('qatest');
    await lockedPage.locator('input[name="password"]').fill('qatest2026');
    await lockedPage.getByRole('button', { name: /登\s*录/ }).click();
    await expect(lockedPage.getByText(/IP 已被临时锁定|连续登录失败次数过多/)).toBeVisible();

    await adminPage.goto('/admin/settings.php');
    await adminPage.locator('input[name="login_fail_limit"]').fill(originalLimit);
    await adminPage.locator('input[name="login_lock_minutes"]').fill(originalMinutes);
    await adminPage.getByRole('button', { name: /保存设置/ }).click();
    await expect(adminPage.locator('body')).toContainText('设置已保存');

    await adminTracker.assertNoClientErrors();
    await lockedTracker.assertNoClientErrors();
  } finally {
    await fs.writeFile(ipLocksPath, originalIpLocks, 'utf8');
    await adminContext.close();
    await lockedContext.close();
  }
});

test('login redirect and remember me keep target navigation across contexts', async ({ page, browser }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
    ],
  });

  await page.goto('/admin/settings.php');
  await expect(page).toHaveURL(/login\.php\?redirect=/);
  const redirectValue = await page.locator('input[name="redirect"]').inputValue();
  expect(redirectValue).toContain('/admin/settings.php');

  await page.locator('input[name="username"]').fill('qatest');
  await page.locator('input[name="password"]').fill('qatest2026');
  await page.locator('input[name="remember_me"]').check();
  await page.getByRole('button', { name: /登\s*录/ }).click();
  await expect(page).toHaveURL(/admin\/settings\.php/);

  const state = await page.context().storageState();
  const rememberContext = await browser.newContext({
    baseURL: 'http://127.0.0.1:58080',
    storageState: state,
  });
  const rememberPage = await rememberContext.newPage();
  await rememberPage.goto('/admin/index.php');
  await expect(rememberPage.locator('.topbar-title')).toHaveText('控制台');
  await rememberContext.close();

  await tracker.assertNoClientErrors();
});
