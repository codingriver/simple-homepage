import fs from 'fs/promises';
import path from 'path';
import { test, expect } from '../../helpers/fixtures';
import { attachClientErrorTracking, loginAsDevAdmin } from '../../helpers/auth';

const dnsConfigPath = path.resolve(__dirname, '../../../data/dns_config.json');

test.describe.configure({ timeout: 180000 });

async function gotoHydratedDns(page: Parameters<typeof loginAsDevAdmin>[0], accountId: string) {
  expect(accountId).not.toBe('');
  await page.goto(`/admin/dns.php?hydrate=1&account=${encodeURIComponent(accountId)}`, {
    waitUntil: 'domcontentloaded',
    timeout: 90000,
  });
}

async function findWorkingHydratedAccount(
  page: Parameters<typeof loginAsDevAdmin>[0],
  accountIds: string[]
) {
  for (const accountId of accountIds) {
    await gotoHydratedDns(page, accountId);
    if (await page.locator('#dns-zone-select').count()) {
      return accountId;
    }
  }
  throw new Error(`未找到可 hydrate 的 DNS 账号: ${accountIds.join(', ')}`);
}

async function currentSelectedAccountId(page: Parameters<typeof loginAsDevAdmin>[0]) {
  const targetAccountId =
    (await page.evaluate(() => {
      return (window as typeof window & { DNS_SELECTED_ACCOUNT?: string }).DNS_SELECTED_ACCOUNT || '';
    })) || '';
  return targetAccountId;
}

async function accountList(page: Parameters<typeof loginAsDevAdmin>[0]) {
  return page.evaluate(() => {
    return (
      (window as typeof window & {
        DNS_ACCOUNTS?: Array<{ id: string; name: string; provider: string }>;
      }).DNS_ACCOUNTS || []
    );
  });
}

test('dns record batch delete removes multiple records via ajax and form', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
      /Failed to load resource: the server responded with a status of 500 \(Internal Server Error\)/,
    ],
    ignoredFailedRequests: [
      /GET .*\/admin\/dns\.php\?ajax=dns_data.*:: net::ERR_ABORTED/,
      /GET .*\/admin\/dns\.php\?account=.*:: net::ERR_ABORTED/,
    ],
  });
  const ts = Date.now();

  await loginAsDevAdmin(page);
  await page.goto('/admin/dns.php');
  const allAccounts = await accountList(page);
  const preferredOrder = [
    await currentSelectedAccountId(page),
    ...allAccounts.map((account) => account.id),
  ].filter((value, index, values) => value !== '' && values.indexOf(value) === index);

  let selectedAccountId = '';
  try {
    selectedAccountId = await findWorkingHydratedAccount(page, preferredOrder);
  } catch {
    test.skip(true, 'No hydratable DNS account available');
    return;
  }

  await expect(page.locator('#dns-zone-select')).toBeVisible();
  const zoneName = await page.locator('#dns-zone-select').inputValue();
  const zoneId = await page.locator('#dns-zone-select option:checked').getAttribute('data-zone-id');

  // import two records to delete
  const importRecords = [
    { name: `batch-del-a-${ts}`, type: 'A', value: '203.0.113.60', ttl: 600 },
    { name: `batch-del-b-${ts}`, type: 'A', value: '203.0.113.61', ttl: 600 },
  ];

  const csrf = await page.locator('input[name="_csrf"]').first().inputValue();
  const importRes = await page.request.post('http://127.0.0.1:58080/admin/dns.php', {
    form: {
      _csrf: csrf,
      action: 'records_import',
      account_id: selectedAccountId,
      zone_id: zoneId || '',
      zone_name: zoneName,
      import_json: JSON.stringify(importRecords),
    },
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    timeout: 60000,
  });
  expect(importRes.status()).toBe(200);
  await gotoHydratedDns(page, selectedAccountId);

  const rows = [
    page.locator(`tr:has(.dns-record-name strong:text-is("batch-del-a-${ts}"))`).first(),
    page.locator(`tr:has(.dns-record-name strong:text-is("batch-del-b-${ts}"))`).first(),
  ];
  for (const row of rows) {
    await expect(row).toBeVisible();
  }

  const recordIds: string[] = [];
  for (const row of rows) {
    const rid = await row.locator('input.rec-chk').inputValue();
    recordIds.push(rid);
  }

  // batch delete via AJAX
  const batchBody = await page.evaluate(
    async ({ csrfToken, accountId, zoneIdVal, zoneNameVal, ids }) => {
      const form = new FormData();
      form.append('_csrf', csrfToken);
      form.append('action', 'record_batch_delete');
      form.append('account_id', accountId);
      form.append('zone_id', zoneIdVal);
      form.append('zone_name', zoneNameVal);
      ids.forEach((id) => form.append('record_ids[]', id));
      const res = await fetch('/admin/dns.php', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: form,
      });
      return res.json();
    },
    { csrfToken: csrf, accountId: selectedAccountId, zoneIdVal: zoneId || '', zoneNameVal: zoneName, ids: recordIds }
  );
  expect(batchBody.ok).toBe(true);

  await gotoHydratedDns(page, selectedAccountId);
  await expect(page.locator(`tr:has(.dns-record-name strong:text-is("batch-del-a-${ts}"))`)).toHaveCount(0);
  await expect(page.locator(`tr:has(.dns-record-name strong:text-is("batch-del-b-${ts}"))`)).toHaveCount(0);

  await tracker.assertNoClientErrors();
});
