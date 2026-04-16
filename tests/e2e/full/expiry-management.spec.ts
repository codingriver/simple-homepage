import { test, expect } from '../../helpers/fixtures';
import { attachClientErrorTracking, loginAsDevAdmin, submitVisibleModal } from '../../helpers/auth';

test('expiry management can scan sites and show manual domain and ssl expiry data', async ({ page }) => {
  test.setTimeout(120000);
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
    ],
  });
  const ts = Date.now();
  const gid = `expiry-group-${ts}`;
  const siteId = `expiry-site-${ts}`;
  const siteName = `到期站点 ${ts}`;

  await loginAsDevAdmin(page);

  await page.goto('/admin/groups.php');
  await page.getByRole('button', { name: /添加分组/ }).click();
  await page.locator('#fi_id').fill(gid);
  await page.locator('#fi_name').fill(`到期分组 ${ts}`);
  await page.locator('#fi_auth').selectOption('0');
  await submitVisibleModal(page);

  await page.goto('/admin/sites.php');
  await page.getByRole('button', { name: /添加站点/ }).click();
  await page.locator('#fi_sid').fill(siteId);
  await page.locator('#fi_name').fill(siteName);
  await page.locator('#fi_gid').selectOption(gid);
  await page.locator('#fi_type').selectOption('external');
  await page.locator('#fi_url').fill('https://example.com/expiry-check');
  await page.locator('#fi_domain_expire_at').fill('2026-04-20');
  await page.locator('#fi_ssl_expire_at').fill('2026-04-18');
  await page.locator('#fi_renew_url').fill('https://example.com/renew');
  await submitVisibleModal(page);

  await page.goto('/admin/expiry.php');
  await expect(page.locator('body')).toContainText('到期管理');
  const csrf = await page.locator('input[name="_csrf"]').first().inputValue();
  const runScan = await page.request.post('http://127.0.0.1:58080/admin/expiry.php', {
    form: {
      _csrf: csrf,
      action: 'run_scan',
    },
    timeout: 60000,
  });
  expect(runScan.status()).toBe(200);
  await page.goto('/admin/expiry.php');
  const row = page.locator(`tr:has-text("${siteName}")`).first();
  await expect(row).toBeVisible();
  await expect(row).toContainText('2026-04-20');
  await expect(row).toContainText('2026-04-18');
  await expect(row.getByRole('link', { name: /打开续费说明/ })).toHaveAttribute('href', 'https://example.com/renew');

  await tracker.assertNoClientErrors();
});
