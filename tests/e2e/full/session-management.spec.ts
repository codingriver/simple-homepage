import { test, expect } from '../../helpers/fixtures';
import { attachClientErrorTracking, loginAsDevAdmin } from '../../helpers/auth';

test('session management lists and revokes sessions', async ({ page, browser }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 403 \(Forbidden\)/,
    ],
  });

  // 创建第二个上下文/会话来产生多条会话记录
  const ctx2 = await browser.newContext({ baseURL: 'http://127.0.0.1:58080' });
  const page2 = await ctx2.newPage();
  await loginAsDevAdmin(page2);

  await loginAsDevAdmin(page);
  await page.goto('/admin/sessions.php');

  // 至少能看到一条会话
  await expect(page.locator('table tbody tr').first()).toBeVisible();

  // 点击第一个强制下线按钮
  page.once('dialog', dialog => dialog.accept());
  const firstRow = page.locator('table tbody tr').first();
  const [response] = await Promise.all([
    page.waitForResponse((resp) => resp.url().includes('sessions_api.php?action=revoke') && resp.request().method() === 'POST'),
    firstRow.locator('button:has-text("强制下线")').click(),
  ]);
  const respBody = await response.json();
  expect(respBody.ok).toBe(true);
  await expect(page.locator('body')).toContainText('会话已强制下线');

  await ctx2.close();
  await tracker.assertNoClientErrors();
});

test('users page links to filtered sessions', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page);
  await loginAsDevAdmin(page);
  await page.goto('/admin/users.php');

  await page.getByRole('link', { name: /查看会话/ }).first().click();
  await expect(page).toHaveURL(/sessions\.php\?username=/);
  await expect(page.locator('#sessions-wrap')).toBeVisible();

  await tracker.assertNoClientErrors();
});
