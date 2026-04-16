import { test, expect } from '../../helpers/fixtures';
import { attachClientErrorTracking, loginAsDevAdmin, logout, submitVisibleModal } from '../../helpers/auth';

test('homepage advanced interactions cover search reset no-result tab switch and guest visibility differences', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
      /Failed to load resource: the server responded with a status of 404 \(Not Found\)/,
    ],
    ignoredFailedRequests: [/favicon\.php/],
  });
  const ts = Date.now();
  const publicId = `home-adv-public-${ts}`;
  const adminId = `home-adv-admin-${ts}`;

  await loginAsDevAdmin(page);
  await page.goto('/admin/groups.php');
  for (const [gid, name, vis, auth] of [
    [publicId, `首页公开 ${ts}`, 'all', '0'],
    [adminId, `首页管理 ${ts}`, 'admin', '1'],
  ] as const) {
    await page.getByRole('button', { name: /添加分组/ }).click();
    await page.locator('#fi_id').fill(gid);
    await page.locator('#fi_name').fill(name);
    await page.locator('#fi_vis').selectOption(vis);
    await page.locator('#fi_auth').selectOption(auth);
    await submitVisibleModal(page);
  }

  await page.goto('/admin/sites.php');
  await page.getByRole('button', { name: /添加站点/ }).click();
  await page.locator('#fi_sid').fill(`home-adv-site-${ts}`);
  await page.locator('#fi_name').fill(`首页交互站点 ${ts}`);
  await page.locator('#fi_gid').selectOption(publicId);
  await page.locator('#fi_type').selectOption('external');
  await page.locator('#fi_url').fill('https://example.com/adv-home');
  await page.locator('#fi_desc').fill('高级交互关键字');
  await submitVisibleModal(page);

  await logout(page);
  await page.goto('/index.php');
  await page.locator('#searchToggle').click();
  await page.locator('#sq').fill('高级交互关键字');
  await expect(page.locator('#searchMeta')).toContainText('找到');
  await page.locator('#sq').fill('no-such-keyword-xyz');
  await expect(page.locator('#searchMeta')).toContainText(/没有找到匹配结果|0/);
  await page.locator('#searchClose').click();
  await expect(page.locator('#searchPanel')).toBeHidden();
  await expect(page.locator('body')).toContainText(`首页公开 ${ts}`);
  await expect(page.locator('body')).not.toContainText(`首页管理 ${ts}`);

  await loginAsDevAdmin(page);
  await page.goto('/index.php');
  await expect(page.locator('body')).toContainText(`首页管理 ${ts}`);

  await tracker.assertNoClientErrors();
});
