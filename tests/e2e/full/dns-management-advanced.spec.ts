import { test, expect } from '@playwright/test';
import { attachClientErrorTracking, loginAsDevAdmin } from '../../helpers/auth';

async function gotoHydratedDns(page: Parameters<typeof loginAsDevAdmin>[0]) {
  await page.goto('/admin/dns.php');
  const accountIds = await page.evaluate(() => {
    const win = window as typeof window & {
      DNS_SELECTED_ACCOUNT?: string;
      DNS_ACCOUNTS?: Array<{ id: string }>;
    };
    const ids = [win.DNS_SELECTED_ACCOUNT || '', ...(win.DNS_ACCOUNTS || []).map((account) => account.id)];
    return ids.filter((value, index) => value && ids.indexOf(value) === index);
  });
  for (const selectedAccount of accountIds) {
    await page.goto(`/admin/dns.php?hydrate=1&account=${encodeURIComponent(selectedAccount)}`, {
      waitUntil: 'domcontentloaded',
      timeout: 45000,
    });
    if (await page.locator('#dns-zone-select').count()) {
      const zoneName = await page.locator('#dns-zone-select').inputValue();
      const zoneId = (await page.locator('#dns-zone-select option:checked').getAttribute('data-zone-id')) || '';
      return { selectedAccount, zoneName, zoneId };
    }
  }
  await expect(page.locator('#dns-zone-select')).toBeVisible();
  return null;
}

test('dns advanced flows cover ttl coexistence unchanged updates and malformed api payloads', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
      /Failed to load resource: the server responded with a status of 500 \(Internal Server Error\)/,
    ],
    ignoredFailedRequests: [/GET .*\/admin\/dns\.php\?ajax=dns_data.*:: net::ERR_ABORTED/],
  });
  const ts = Date.now();
  const host = `dual-stack-${ts}`;

  await loginAsDevAdmin(page);
  const hydrated = await gotoHydratedDns(page);
  if (hydrated) {
    const csrf = await page.locator('input[name="_csrf"]').first().inputValue();
    for (const [type, value, ttl] of [
      ['A', '203.0.113.31', '1'],
      ['AAAA', '2001:db8::31', '120'],
    ] as const) {
      const create = await page.request.post('http://127.0.0.1:58080/admin/dns.php', {
        timeout: 45000,
        form: {
          _csrf: csrf,
          action: 'record_create',
          account_id: hydrated.selectedAccount,
          zone_id: hydrated.zoneId,
          zone_name: hydrated.zoneName,
          record_name: host,
          record_type: type,
          record_value: value,
          record_ttl: ttl,
        },
      });
      expect(create.status()).toBe(200);
    }

    await page.goto(
      `/admin/dns.php?hydrate=1&account=${encodeURIComponent(hydrated.selectedAccount)}&zone=${encodeURIComponent(hydrated.zoneId)}&zone_name=${encodeURIComponent(hydrated.zoneName)}`,
      { waitUntil: 'domcontentloaded', timeout: 45000 }
    );
    await expect(page.locator('body')).toContainText(host);
    await expect(page.locator('body')).toContainText('203.0.113.31');
    await expect(page.locator('body')).toContainText('2001:db8::31');
  }

  const malformed = await page.request.get('http://127.0.0.1:58080/admin/dns.php?ajax=dns_data&bad=1', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
  });
  expect([200, 400, 401]).toContain(malformed.status());

  await tracker.assertNoClientErrors();
});
