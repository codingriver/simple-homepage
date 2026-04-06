import { test, expect } from '@playwright/test';
import { attachClientErrorTracking } from '../../helpers/auth';

test('setup validates input or stays sealed after installation', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [/Failed to load resource: the server responded with a status of 404 \(Not Found\)/],
  });

  await page.goto('/setup.php');
  const body = await page.locator('body').textContent();
  if ((body || '').includes('404 Not Found')) {
    await expect(page.locator('body')).toContainText('404 Not Found');
    await tracker.assertNoClientErrors();
    return;
  }

  await expect(page.getByText('首次安装向导')).toBeVisible();
  await page.getByRole('button', { name: /开始使用/ }).click();
  await expect(page.locator('.errs')).toContainText(/用户名|密码|站点名称/);

  await page.locator('input[name="username"]').fill('a');
  await page.locator('input[name="password"]').fill('short');
  await page.locator('input[name="password2"]').fill('different');
  await page.locator('input[name="site_name"]').fill('');
  await page.getByRole('button', { name: /开始使用/ }).click();
  await expect(page.locator('.errs')).toBeVisible();

  await tracker.assertNoClientErrors();
});
