import { test, expect } from '@playwright/test';
import { attachClientErrorTracking, loginAsDevAdmin, logout } from '../../helpers/auth';

async function gotoHydratedDns(page: Parameters<typeof loginAsDevAdmin>[0]) {
  await page.goto('/admin/dns.php');
  const selectedAccount = await page.evaluate(() => {
    return (window as typeof window & { DNS_SELECTED_ACCOUNT?: string }).DNS_SELECTED_ACCOUNT || '';
  });
  expect(selectedAccount).not.toBe('');
  await page.goto(`/admin/dns.php?hydrate=1&account=${encodeURIComponent(selectedAccount)}`, {
    waitUntil: 'domcontentloaded',
    timeout: 45000,
  });
  await expect(page.locator('#dns-zone-select')).toBeVisible();
  const zoneName = await page.locator('#dns-zone-select').inputValue();
  expect(zoneName).not.toBe('');
  return { selectedAccount, zoneName };
}

test('dns account management supports validation verify and deletion flows', async ({ page, browser }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
    ],
  });
  const ts = Date.now();
  const accountName = `Cloudflare 测试 ${ts}`;

  await loginAsDevAdmin(page);
  await page.goto('/admin/dns.php');

  await page.getByRole('button', { name: /添加 DNS 账户/ }).click();
  await page.locator('#acct-name').fill(accountName);
  await page.locator('#acct-provider').selectOption('cloudflare');
  await page.locator('input[name="cred_api_token"]').fill('token-for-e2e');
  await page.locator('#acct-form').getByRole('button', { name: /保存 DNS 账户/ }).click();
  await expect(page).toHaveURL(/admin\/dns\.php/);
  await expect(page.locator('body')).toContainText(accountName);

  await page.getByRole('button', { name: /测试连接/ }).click();
  await expect(page.locator('body')).toContainText(/失败|错误|无法|连接/);

  await page.getByRole('button', { name: /管理 DNS 账号/ }).click();
  const row = page.locator(`.dns-account-row:has-text("${accountName}")`).first();
  await expect(row).toBeVisible();
  page.once('dialog', dialog => dialog.accept());
  await row.locator('form').getByRole('button', { name: '删除' }).click();
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
  await page.goto('/admin/dns.php');

  const nonAjax = await page.request.get('http://127.0.0.1:58080/admin/dns.php?ajax=dns_data');
  expect(nonAjax.status()).toBe(401);
  expect(await nonAjax.json()).toMatchObject({ ok: false });

  await page.getByRole('button', { name: /添加 DNS 账户/ }).click();
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
  const { zoneName } = await gotoHydratedDns(page);

  await page.getByRole('button', { name: /新建记录/ }).click();
  await expect(page.locator('#record-modal')).toHaveClass(/open/);
  await page.locator('#rec-name').fill(hostName);
  await page.locator('#rec-type').selectOption('A');
  await page.locator('#rec-value').fill(createdValue);
  await page.locator('#rec-ttl').fill('600');
  await page.locator('#rec-form').getByRole('button', { name: /保存记录/ }).click();

  await expect(page.locator('body')).toContainText(/记录已创建|创建成功/);
  const createdRow = page.locator(`tr:has(.dns-record-name strong:text-is("${hostName}"))`).first();
  await expect(createdRow).toBeVisible();
  await expect(createdRow).toContainText(createdValue);
  await expect(page.locator('#dns-record-count')).toContainText(/条记录/);

  await createdRow.getByRole('button', { name: '编辑' }).click();
  await expect(page.locator('#record-modal')).toHaveClass(/open/);
  await page.locator('#rec-name').fill(updatedHostName);
  await page.locator('#rec-value').fill(updatedValue);
  await page.locator('#rec-ttl').fill('1200');
  await page.locator('#rec-form').getByRole('button', { name: /保存记录/ }).click();

  await expect(page.locator('body')).toContainText(/记录已更新|更新成功/);
  const updatedRow = page.locator(`tr:has(.dns-record-name strong:text-is("${updatedHostName}"))`).first();
  await expect(updatedRow).toBeVisible();
  await expect(updatedRow).toContainText(updatedValue);
  await expect(page.locator('body')).toContainText(zoneName);

  page.once('dialog', dialog => dialog.accept());
  await updatedRow.getByRole('button', { name: '删除' }).click();
  await expect(page.locator('body')).toContainText(/记录已删除|删除成功/);
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
  await gotoHydratedDns(page);

  for (const [name, value] of [[recordA, '198.51.100.20'], [recordB, '198.51.100.21']] as const) {
    await page.getByRole('button', { name: /新建记录/ }).click();
    await page.locator('#rec-name').fill(name);
    await page.locator('#rec-type').selectOption('A');
    await page.locator('#rec-value').fill(value);
    await page.locator('#rec-form').getByRole('button', { name: /保存记录/ }).click();
    await expect(page.locator('body')).toContainText(/记录已创建|创建成功/);
  }

  const rowA = page.locator(`tr:has(.dns-record-name strong:text-is("${recordA}"))`).first();
  const rowB = page.locator(`tr:has(.dns-record-name strong:text-is("${recordB}"))`).first();
  await expect(rowA).toBeVisible();
  await expect(rowB).toBeVisible();

  await rowA.locator('input.rec-chk').check();
  await rowB.locator('input.rec-chk').check();
  await expect(page.locator('#checked-count')).toContainText(/已选 2 条/);

  page.once('dialog', dialog => dialog.accept());
  await page.getByRole('button', { name: /删除选中/ }).click();

  await expect(page.locator('body')).toContainText(/批量删除|删除成功|已删除/);
  await expect(page.locator(`tr:has(.dns-record-name strong:text-is("${recordA}"))`)).toHaveCount(0);
  await expect(page.locator(`tr:has(.dns-record-name strong:text-is("${recordB}"))`)).toHaveCount(0);

  await tracker.assertNoClientErrors();
});
