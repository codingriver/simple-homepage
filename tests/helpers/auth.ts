import { expect, Page } from '@playwright/test';

async function isMobileViewport(page: Page) {
  return page.viewportSize()?.width !== undefined && page.viewportSize()!.width <= 768;
}

async function isSidebarVisible(page: Page) {
  return page.locator('#sidebar').evaluate((el) => {
    const rect = el.getBoundingClientRect();
    const style = getComputedStyle(el);
    return style.visibility !== 'hidden' && style.display !== 'none' && rect.right > 0 && rect.width > 0;
  });
}

export async function ensureAdminSidebarOpen(page: Page) {
  if (!(await isMobileViewport(page))) return;
  const toggle = page.locator('#sidebarToggle');
  if (!(await toggle.isVisible())) return;
  if (await isSidebarVisible(page)) return;
  await toggle.click({ force: true });
  await page.waitForTimeout(150);
}

export async function clickAdminNav(page: Page, name: RegExp | string) {
  await ensureAdminSidebarOpen(page);
  const link = page.locator('#sidebar').getByRole('link', { name });
  await expect(link).toBeVisible();
  await link.scrollIntoViewIfNeeded();
  try {
    await link.click({ force: true });
  } catch {
    await link.evaluate((el) => {
      if (el instanceof HTMLAnchorElement) {
        el.click();
        return;
      }
      (el as HTMLElement).click();
    });
  }
}

export async function submitVisibleModal(page: Page) {
  const modal = page.locator('#modal');
  await expect(modal).toBeVisible();
  const submit = modal.locator('button[type="submit"]').first();
  await submit.scrollIntoViewIfNeeded();
  await submit.click({ force: true });
  await page.waitForTimeout(200);
}

export async function attachClientErrorTracking(
  page: Page,
  options?: { ignoredMessages?: RegExp[]; ignoredFailedRequests?: RegExp[] }
) {
  const jsErrors: string[] = [];
  const failedRequests: string[] = [];
  const ignoredMessages = options?.ignoredMessages ?? [];
  const ignoredFailedRequests = options?.ignoredFailedRequests ?? [];

  page.on('pageerror', (error) => {
    jsErrors.push(error.message);
  });

  page.on('console', (msg) => {
    if (msg.type() === 'error') {
      const text = msg.text();
      if (ignoredMessages.some((pattern) => pattern.test(text))) return;
      jsErrors.push(text);
    }
  });

  page.on('requestfailed', (request) => {
    const text = `${request.method()} ${request.url()} :: ${request.failure()?.errorText || 'failed'}`;
    if (ignoredFailedRequests.some((pattern) => pattern.test(text))) return;
    failedRequests.push(text);
  });

  return {
    assertNoClientErrors: async () => {
      expect(jsErrors, `Client errors detected:\n${jsErrors.join('\n')}`).toEqual([]);
      expect(failedRequests, `Failed requests detected:\n${failedRequests.join('\n')}`).toEqual([]);
    },
  };
}

export async function loginAsDevAdmin(page: Page) {
  await page.goto('/login.php');

  if (!page.url().includes('/login.php')) {
    return;
  }

  const usernameInput = page.locator('input[name="username"]');
  const passwordInput = page.locator('input[name="password"]');
  await expect(usernameInput).toBeVisible();
  await expect(passwordInput).toBeVisible();

  const candidates = [
    { username: 'qatest', password: 'qatest2026' },
    { username: 'admin', password: 'Admin@test2026' },
  ];

  for (const candidate of candidates) {
    await usernameInput.fill(candidate.username);
    await passwordInput.fill(candidate.password);
    await page.getByRole('button', { name: /登\s*录/ }).click();

    try {
      await expect(page).toHaveURL(/index\.php|\/$/, { timeout: 5000 });
      return;
    } catch {
      if (!page.url().includes('/login.php')) {
        throw new Error(`登录后进入了意外页面: ${page.url()}`);
      }
    }
  }

  throw new Error('无法使用测试管理员账号登录（已尝试 qatest 和 admin）');
}

export async function logout(page: Page) {
  await ensureAdminSidebarOpen(page);
  const candidates = [
    page.locator('#sidebar').locator('form[action="/logout.php"] button'),
    page.locator('#sidebar').locator('form[action="logout.php"] button'),
    page.locator('form[action="/logout.php"] button').first(),
    page.locator('form[action="logout.php"] button').first(),
    page.getByRole('button', { name: /退出登录/ }).first(),
  ];

  let logoutButton = candidates[0];
  for (const candidate of candidates) {
    if (await candidate.count()) {
      logoutButton = candidate;
      break;
    }
  }

  await expect(logoutButton).toBeVisible();
  await logoutButton.scrollIntoViewIfNeeded();
  await logoutButton.evaluate((el) => {
    if (el instanceof HTMLButtonElement) {
      el.click();
      return;
    }
    (el as HTMLElement).click();
  });
  await expect(page).toHaveURL(/login\.php/);
}

export async function ensureSetup(page: Page, siteName = 'E2E 导航站') {
  await page.goto('/setup.php');
  if ((await page.locator('body').textContent())?.includes('404 Not Found')) {
    return false;
  }

  await expect(page.getByText('首次安装向导')).toBeVisible();
  await page.locator('input[name="username"]').fill('admin');
  await page.locator('input[name="password"]').fill('Admin@test2026');
  await page.locator('input[name="password2"]').fill('Admin@test2026');
  await page.locator('input[name="site_name"]').fill(siteName);
  await page.locator('input[name="nav_domain"]').fill('nav.test.local');
  await page.getByRole('button', { name: /开始使用/ }).click();
  await expect(page.getByRole('link', { name: /前往登录/ })).toBeVisible();
  return true;
}
