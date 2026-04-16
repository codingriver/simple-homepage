import { test, expect } from '../../helpers/fixtures';
import { attachClientErrorTracking, loginAsDevAdmin, logout } from '../../helpers/auth';

test.describe.configure({ timeout: 180000 });

async function gotoHydratedDns(page: Parameters<typeof loginAsDevAdmin>[0]) {
  await page.goto('/admin/dns.php', { waitUntil: 'domcontentloaded', timeout: 90000 });
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
      timeout: 90000,
    });
    if (await page.locator('#dns-zone-select').count()) {
      const zoneName = await page.locator('#dns-zone-select').inputValue();
      const zoneId = (await page.locator('#dns-zone-select option:checked').getAttribute('data-zone-id')) || '';
      expect(zoneName).not.toBe('');
      return { selectedAccount, zoneName, zoneId };
    }
  }
  return null;
}

test('dns account management supports validation verify and deletion flows', async ({ page, browser }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
    ],
    ignoredFailedRequests: [
      /GET .*\/admin\/dns\.php\?ajax=dns_data.*:: net::ERR_ABORTED/,
    ],
  });
  const ts = Date.now();
  const accountName = `Cloudflare 测试 ${ts}`;

  await loginAsDevAdmin(page);
  await page.goto('/admin/dns.php', { waitUntil: 'domcontentloaded', timeout: 45000 });
  const csrf = await page.locator('input[name="_csrf"]').first().inputValue();

  const saveAccount = await page.request.post('http://127.0.0.1:58080/admin/dns.php', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: {
      _csrf: csrf,
      action: 'save_account',
      id: '',
      provider: 'cloudflare',
      name: accountName,
      cred_api_token: 'token-for-e2e',
    },
  });
  expect(saveAccount.status()).toBe(200);
  const savePayload = await saveAccount.json();
  expect(savePayload).toMatchObject({ ok: true, account_id: expect.any(String), redirect: expect.any(String) });
  await page.goto(`/admin/${String(savePayload.redirect).replace(/^dns\.php/, 'dns.php')}`, {
    waitUntil: 'domcontentloaded',
    timeout: 45000,
  });
  await expect(page.locator('body')).toContainText(accountName);

  const verifyResponse = await page.request.post('http://127.0.0.1:58080/admin/dns.php', {
    form: {
      _csrf: await page.locator('input[name="_csrf"]').first().inputValue(),
      action: 'verify_account',
      account_id: String(savePayload.account_id),
    },
    timeout: 60000,
  });
  expect(verifyResponse.status()).toBe(200);
  expect(await verifyResponse.text()).toMatch(/失败|错误|无法|连接|未读取到可见域名|连接测试通过/);

  const deleteAccount = await page.request.post('http://127.0.0.1:58080/admin/dns.php', {
    form: {
      _csrf: await page.locator('input[name="_csrf"]').first().inputValue(),
      action: 'delete_account',
      account_id: String(savePayload.account_id),
    },
    timeout: 60000,
  });
  expect(deleteAccount.status()).toBe(200);
  await page.goto('/admin/dns.php', { waitUntil: 'domcontentloaded', timeout: 45000 });
  await expect(page.locator('body')).not.toContainText(accountName);

  const unauthContext = await browser.newContext({ baseURL: 'http://127.0.0.1:58080' });
  const unauthPage = await unauthContext.newPage();
  await unauthPage.goto('/admin/dns.php');
  await expect(unauthPage).toHaveURL(/login\.php/);
  await unauthContext.close();

  await tracker.assertNoClientErrors();
});

test('dns page enforces async and import guardrails', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
    ],
    ignoredFailedRequests: [
      /GET .*\/admin\/dns\.php\?ajax=dns_data.*:: net::ERR_ABORTED/,
    ],
  });

  await loginAsDevAdmin(page);
  await page.goto('/admin/dns.php', { waitUntil: 'domcontentloaded', timeout: 45000 });

  const nonAjax = await page.request.get('http://127.0.0.1:58080/admin/dns.php?ajax=dns_data');
  expect(nonAjax.status()).toBe(401);
  expect(await nonAjax.json()).toMatchObject({ ok: false });

  await page.evaluate(() => {
    const fn = (window as Window & { openAccountForm?: () => void }).openAccountForm;
    if (typeof fn !== 'function') throw new Error('openAccountForm not found');
    fn();
  });
  await expect(page.locator('#account-form-modal')).toHaveClass(/open/);
  await page.locator('#acct-provider').selectOption('cloudflare');
  await page.locator('#acct-form').getByRole('button', { name: /保存 DNS 账户/ }).click();
  await expect(page.locator('#acct-form-feedback')).toContainText(/请输入|必填|缺少|API Token/);

  await page.locator('#acct-provider').selectOption({ label: 'Aliyun DNS' }).catch(async () => {
    await page.locator('#acct-provider').selectOption('aliyun');
  });
  await page.locator('#acct-form').getByRole('button', { name: /取消/ }).click();

  await page.goto('/admin/dns.php');
  const importButton = page.getByRole('button', { name: /批量导入/ });
  const emptyState = page.locator('#dns-main-card, body');
  await Promise.race([
    importButton.waitFor({ state: 'visible', timeout: 8000 }).catch(() => null),
    expect(emptyState).toContainText(/尚未配置账号|请先选择账号|请选择域名/, { timeout: 8000 }).catch(() => null),
  ]);

  if (await importButton.isVisible()) {
    await importButton.click();
    await page.locator('textarea[name="import_json"]').fill('{bad json');
    await page.locator('#import-modal').getByRole('button', { name: /开始导入/ }).click();
    await expect(page.locator('body')).toContainText(/JSON|格式|失败/);
  } else {
    await expect(page.locator('body')).toContainText(/尚未配置账号|请先选择账号|请选择域名/);
  }

  await logout(page);
  const denied = await page.request.get('http://127.0.0.1:58080/admin/dns.php?ajax=dns_data', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
  });
  expect(denied.status()).toBe(401);
  expect(await denied.json()).toMatchObject({ ok: false });

  await tracker.assertNoClientErrors();
});

test('dns hydrated zone switch and record CRUD lifecycle works end-to-end', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
      /Failed to load resource: the server responded with a status of 500 \(Internal Server Error\)/,
    ],
    ignoredFailedRequests: [
      /GET .*\/admin\/dns\.php\?ajax=dns_data.*:: net::ERR_ABORTED/,
    ],
  });
  const ts = Date.now();
  const hostName = `e2e-a-${ts}`;
  const updatedHostName = `e2e-b-${ts}`;
  const createdValue = '203.0.113.10';
  const updatedValue = '203.0.113.11';

  await loginAsDevAdmin(page);
  const hydrated = await gotoHydratedDns(page);
  if (!hydrated) {
    test.skip(true, 'No hydratable DNS account available');
  }
  const { selectedAccount, zoneName, zoneId } = hydrated;
  const csrf = await page.locator('input[name="_csrf"]').first().inputValue();

  const create = await page.request.post('http://127.0.0.1:58080/admin/dns.php', {
    form: {
      _csrf: csrf,
      action: 'record_create',
      account_id: selectedAccount,
      zone_id: zoneId,
      zone_name: zoneName,
      record_name: hostName,
      record_type: 'A',
      record_value: createdValue,
      record_ttl: '600',
    },
  });
  expect(create.status()).toBe(200);
  await page.goto(
    `/admin/dns.php?hydrate=1&account=${encodeURIComponent(selectedAccount)}&zone=${encodeURIComponent(zoneId)}&zone_name=${encodeURIComponent(zoneName)}`,
    { waitUntil: 'domcontentloaded', timeout: 45000 }
  );
  const createdRow = page.locator(`tr:has(.dns-record-name strong:text-is("${hostName}"))`).first();
  await expect(createdRow).toBeVisible();
  await expect(createdRow).toContainText(createdValue);
  await expect(page.locator('#dns-record-count')).toContainText(/条记录/);

  const recordId = await createdRow.locator('input.rec-chk').inputValue();
  const update = await page.request.post('http://127.0.0.1:58080/admin/dns.php', {
    form: {
      _csrf: csrf,
      action: 'record_update',
      account_id: selectedAccount,
      zone_id: zoneId,
      zone_name: zoneName,
      record_id: recordId,
      record_old_type: 'A',
      record_name: updatedHostName,
      record_type: 'A',
      record_value: updatedValue,
      record_ttl: '1200',
    },
  });
  expect(update.status()).toBe(200);
  await page.goto(
    `/admin/dns.php?hydrate=1&account=${encodeURIComponent(selectedAccount)}&zone=${encodeURIComponent(zoneId)}&zone_name=${encodeURIComponent(zoneName)}`,
    { waitUntil: 'domcontentloaded', timeout: 45000 }
  );
  const updatedRow = page.locator(`tr:has(.dns-record-name strong:text-is("${updatedHostName}"))`).first();
  await expect(updatedRow).toBeVisible();
  await expect(updatedRow).toContainText(updatedValue);
  await expect(page.locator('body')).toContainText(zoneName);

  const deleteRecord = await page.request.post('http://127.0.0.1:58080/admin/dns.php', {
    form: {
      _csrf: csrf,
      action: 'record_delete',
      account_id: selectedAccount,
      zone_id: zoneId,
      zone_name: zoneName,
      record_id: await updatedRow.locator('input.rec-chk').inputValue(),
    },
  });
  expect(deleteRecord.status()).toBe(200);
  await page.goto(
    `/admin/dns.php?hydrate=1&account=${encodeURIComponent(selectedAccount)}&zone=${encodeURIComponent(zoneId)}&zone_name=${encodeURIComponent(zoneName)}`,
    { waitUntil: 'domcontentloaded', timeout: 45000 }
  );
  await expect(page.locator(`tr:has(.dns-record-name strong:text-is("${updatedHostName}"))`)).toHaveCount(0);

  await tracker.assertNoClientErrors();
});

test('dns batch delete removes multiple selected records', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
      /Failed to load resource: the server responded with a status of 500 \(Internal Server Error\)/,
    ],
    ignoredFailedRequests: [
      /GET .*\/admin\/dns\.php\?ajax=dns_data.*:: net::ERR_ABORTED/,
    ],
  });
  const ts = Date.now();
  const recordA = `batch-a-${ts}`;
  const recordB = `batch-b-${ts}`;

  await loginAsDevAdmin(page);
  const hydrated = await gotoHydratedDns(page);
  if (!hydrated) {
    test.skip(true, 'No hydratable DNS account available');
  }
  const { selectedAccount, zoneId, zoneName } = hydrated;
  const csrf = await page.locator('input[name="_csrf"]').first().inputValue();

  for (const [name, value] of [[recordA, '198.51.100.20'], [recordB, '198.51.100.21']] as const) {
    const create = await page.request.post('http://127.0.0.1:58080/admin/dns.php', {
      form: {
        _csrf: csrf,
        action: 'record_create',
        account_id: selectedAccount,
        zone_id: zoneId,
        zone_name: zoneName,
        record_name: name,
        record_type: 'A',
        record_value: value,
        record_ttl: '600',
      },
      timeout: 60000,
    });
    expect(create.status()).toBe(200);
  }
  await page.goto(
    `/admin/dns.php?hydrate=1&account=${encodeURIComponent(selectedAccount)}&zone=${encodeURIComponent(zoneId)}&zone_name=${encodeURIComponent(zoneName)}`,
    { waitUntil: 'domcontentloaded', timeout: 45000 }
  );

  const rowA = page.locator(`tr:has(.dns-record-name strong:text-is("${recordA}"))`).first();
  const rowB = page.locator(`tr:has(.dns-record-name strong:text-is("${recordB}"))`).first();
  await expect(rowA).toBeVisible();
  await expect(rowB).toBeVisible();

  await rowA.locator('input.rec-chk').check();
  await rowB.locator('input.rec-chk').check();
  const deleteReload = page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 45000 });
  await page.evaluate(() => {
    window.confirm = () => true;
    const form = document.getElementById('batch-delete-form') as HTMLFormElement | null;
    if (!form) throw new Error('batch-delete-form not found');
    form.requestSubmit();
  });
  await deleteReload;
  await expect(page.locator(`tr:has(.dns-record-name strong:text-is("${recordA}"))`)).toHaveCount(0);
  await expect(page.locator(`tr:has(.dns-record-name strong:text-is("${recordB}"))`)).toHaveCount(0);

  await tracker.assertNoClientErrors();
});
