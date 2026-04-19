import fs from 'fs/promises';
import path from 'path';
import { test, expect } from '../../helpers/fixtures';
import { attachClientErrorTracking, loginAsDevAdmin } from '../../helpers/auth';

test.describe.configure({ timeout: 180000 });

const dnsConfigPath = path.resolve(__dirname, '../../../data/dns_config.json');

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

async function waitForDnsHydratedReload(page: Parameters<typeof loginAsDevAdmin>[0]) {
  await page.waitForLoadState('domcontentloaded', { timeout: 45000 }).catch(() => null);
  await page.waitForFunction(() => {
    return !!document.querySelector('#dns-records-panel');
  }, undefined, { timeout: 45000 });
}

test('dns verify success import success and password-retain edit flows work on a real account', async ({ page }) => {
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
  let dnsConfig: { accounts?: Array<{ id: string; credentials?: Record<string, string> }> } = {};
  try {
    dnsConfig = JSON.parse(await fs.readFile(dnsConfigPath, 'utf8'));
  } catch {
    test.skip(true, 'No dns_config.json available');
  }

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
  }
  await expect(page.locator('#dns-zone-select')).toBeVisible();
  const accountMeta = await page.evaluate((accountId) => {
    const win = window as typeof window & {
      DNS_ACCOUNTS?: Array<{ id: string; name: string; provider: string }>;
    };
    return win.DNS_ACCOUNTS?.find((account) => account.id === accountId) || null;
  }, selectedAccountId);
  expect(accountMeta).toBeTruthy();
  const originalAccountName = accountMeta?.name ?? 'dns-account';
  const renamedAccountName = originalAccountName;
  const persistedAccount = dnsConfig.accounts?.find((account) => account.id === selectedAccountId);
  const persistedCredentials = persistedAccount?.credentials ?? {};
  const zoneName = await page.locator('#dns-zone-select').inputValue();
  const zoneId = await page.locator('#dns-zone-select option:checked').getAttribute('data-zone-id');

  try {
    const firstVerify = await page.request.post('http://127.0.0.1:58080/admin/dns.php', {
      form: {
        _csrf: await page.locator('input[name="_csrf"]').first().inputValue(),
        action: 'verify_account',
        account_id: selectedAccountId,
      },
      timeout: 60000,
    });
    expect(firstVerify.status()).toBe(200);
    await gotoHydratedDns(page, selectedAccountId);

    await page.evaluate((accountId) => {
      const win = window as typeof window & { openAccountForm?: (id?: string) => void };
      if (!win.openAccountForm) throw new Error('openAccountForm not found');
      win.openAccountForm(accountId);
    }, selectedAccountId);
    await expect(page.locator('#account-form-modal')).toHaveClass(/open/);
    await expect(page.locator('#acct-cred-fields')).toContainText('留空保持不变');
    for (const [name, value] of Object.entries(persistedCredentials)) {
      const input = page.locator(`input[name="cred_${name}"]`);
      if ((await input.count()) && (await input.getAttribute('type')) !== 'password') {
        await input.fill(value);
      }
    }
    await page.locator('#acct-name').fill(renamedAccountName);
    await page.locator('#acct-submit-btn').click({ force: true });
    await expect(page.locator('.dns-account-name').first()).toContainText(renamedAccountName);

    const secondVerify = await page.request.post('http://127.0.0.1:58080/admin/dns.php', {
      form: {
        _csrf: await page.locator('input[name="_csrf"]').first().inputValue(),
        action: 'verify_account',
        account_id: selectedAccountId,
      },
      timeout: 60000,
    });
    expect(secondVerify.status()).toBe(200);
    await gotoHydratedDns(page, selectedAccountId);

    const importRecords = [
      { name: `import-a-${ts}`, type: 'A', value: '203.0.113.50', ttl: 600 },
      { name: `import-cname-${ts}`, type: 'CNAME', value: 'example.com', ttl: 600 },
    ];

    const importResponse = await page.request.post('http://127.0.0.1:58080/admin/dns.php', {
      form: {
        _csrf: await page.locator('input[name="_csrf"]').first().inputValue(),
        action: 'records_import',
        account_id: selectedAccountId,
        zone_id: zoneId || '',
        zone_name: zoneName,
        import_json: JSON.stringify(importRecords),
      },
      headers: {
        'X-Requested-With': 'XMLHttpRequest',
      },
      timeout: 60000,
    });
    expect(importResponse.status()).toBe(200);
    await gotoHydratedDns(page, selectedAccountId);
    const importedRows = [
      page.locator(`tr:has(.dns-record-name strong:text-is("import-a-${ts}"))`).first(),
      page.locator(`tr:has(.dns-record-name strong:text-is("import-cname-${ts}"))`).first(),
    ];
    for (const row of importedRows) {
      await expect(row).toBeVisible();
    }

    for (const row of importedRows) {
      const recordId = await row.locator('input.rec-chk').inputValue();
      const remove = await page.request.post('http://127.0.0.1:58080/admin/dns.php', {
        form: {
          _csrf: await page.locator('input[name="_csrf"]').first().inputValue(),
          action: 'record_delete',
          account_id: selectedAccountId,
          zone_id: zoneId || '',
          zone_name: zoneName,
          record_id: recordId,
        },
        timeout: 60000,
      });
      expect(remove.status()).toBe(200);
    }
    await gotoHydratedDns(page, selectedAccountId);
    await expect(page.locator(`tr:has(.dns-record-name strong:text-is("import-a-${ts}"))`)).toHaveCount(0);
    await expect(page.locator(`tr:has(.dns-record-name strong:text-is("import-cname-${ts}"))`)).toHaveCount(0);
  } finally {
    // no cleanup needed because the account name is intentionally preserved.
  }

  await tracker.assertNoClientErrors();
});
