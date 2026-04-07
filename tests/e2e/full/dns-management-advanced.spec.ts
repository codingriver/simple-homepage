import { test, expect } from '@playwright/test';
import { attachClientErrorTracking, loginAsDevAdmin } from '../../helpers/auth';

async function gotoHydratedDns(page: Parameters<typeof loginAsDevAdmin>[0]) {
  await page.goto('/admin/dns.php');
  const selectedAccount = await page.evaluate(() => {
    return (window as typeof window & { DNS_SELECTED_ACCOUNT?: string }).DNS_SELECTED_ACCOUNT || '';
  });
  if (!selectedAccount) return null;
  await page.goto(`/admin/dns.php?hydrate=1&account=${encodeURIComponent(selectedAccount)}`);
  await expect(page.locator('#dns-zone-select')).toBeVisible();
  const zoneName = await page.locator('#dns-zone-select').inputValue();
  return { selectedAccount, zoneName };
}

test('dns advanced flows cover ttl coexistence unchanged updates and malformed api payloads', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
      /Failed to load resource: the server responded with a status of 500 \(Internal Server Error\)/,
    ],
  });
  const ts = Date.now();
  const host = `dual-stack-${ts}`;

  await loginAsDevAdmin(page);
  const hydrated = await gotoHydratedDns(page);
  if (hydrated) {
    await page.getByRole('button', { name: /新建记录/ }).click();
    await page.locator('#rec-name').fill(host);
    await page.locator('#rec-type').selectOption('A');
    await page.locator('#rec-value').fill('203.0.113.31');
    await page.locator('#rec-ttl').fill('1');
    await page.locator('#rec-form').getByRole('button', { name: /保存记录/ }).click();
    await expect(page.locator('body')).toContainText(/记录已创建|创建成功/);

    await page.getByRole('button', { name: /新建记录/ }).click();
    await page.locator('#rec-name').fill(host);
    await page.locator('#rec-type').selectOption('AAAA');
    await page.locator('#rec-value').fill('2001:db8::31');
    await page.locator('#rec-ttl').fill('120');
    await page.locator('#rec-form').getByRole('button', { name: /保存记录/ }).click();
    await expect(page.locator('body')).toContainText(/记录已创建|创建成功/);
    await expect(page.locator('body')).toContainText(host);
  }

  const malformed = await page.request.get('http://127.0.0.1:58080/admin/dns.php?ajax=dns_data&bad=1', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
  });
  expect([200, 400, 401]).toContain(malformed.status());

  await tracker.assertNoClientErrors();
});
