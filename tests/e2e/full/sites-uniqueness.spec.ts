import { test, expect } from '../../helpers/fixtures';
import { attachClientErrorTracking, loginAsDevAdmin, submitVisibleModal } from '../../helpers/auth';

test('site uniqueness and proxy validation reject duplicate slug and invalid domain', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
    ],
  });
  const ts = Date.now();
  const gid = `uniq-group-${ts}`;
  const slug = `same-slug-${ts}`;

  await loginAsDevAdmin(page);
  await page.goto('/admin/groups.php');
  await page.getByRole('button', { name: /添加分组/ }).click();
  await page.locator('#fi_id').fill(gid);
  await page.locator('#fi_name').fill(`唯一性分组 ${ts}`);
  await page.locator('#fi_auth').selectOption('0');
  await submitVisibleModal(page);

  await page.goto('/admin/sites.php');
  await page.getByRole('button', { name: /添加站点/ }).click();
  await page.locator('#fi_sid').fill(`uniq-site-1-${ts}`);
  await page.locator('#fi_name').fill('唯一性站点1');
  await page.locator('#fi_gid').selectOption(gid);
  await page.locator('#fi_type').selectOption('proxy');
  await page.locator('#fi_ptarget').fill('http://192.168.1.50:8080');
  await page.locator('#fi_slug').fill(slug);
  await submitVisibleModal(page);

  page.once('dialog', dialog => dialog.accept());
  await page.getByRole('button', { name: /添加站点/ }).click();
  await page.locator('#fi_sid').fill(`uniq-site-2-${ts}`);
  await page.locator('#fi_name').fill('唯一性站点2');
  await page.locator('#fi_gid').selectOption(gid);
  await page.locator('#fi_type').selectOption('proxy');
  await page.locator('#fi_ptarget').fill('http://192.168.1.51:8080');
  await page.locator('#fi_slug').fill(slug);
  await submitVisibleModal(page);

  page.once('dialog', dialog => {
    expect(dialog.message()).toMatch(/域名|子域名|格式|非法/);
    dialog.accept();
  });
  await page.getByRole('button', { name: /添加站点/ }).click();
  await page.locator('#fi_sid').fill(`uniq-site-3-${ts}`);
  await page.locator('#fi_name').fill('非法域名站点');
  await page.locator('#fi_gid').selectOption(gid);
  await page.locator('#fi_type').selectOption('proxy');
  await page.locator('#fi_pmode').selectOption('domain');
  await page.locator('#fi_ptarget').fill('http://192.168.1.52:8080');
  await page.locator('#fi_pdomain').fill('bad domain');
  await submitVisibleModal(page);

  await tracker.assertNoClientErrors();
});
