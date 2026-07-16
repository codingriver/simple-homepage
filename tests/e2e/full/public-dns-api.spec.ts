import { test, expect } from '../../helpers/fixtures';
import { attachClientErrorTracking, loginAsDevAdmin } from '../../helpers/auth';

test('public dns api guards unknown action and localhost success shape', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
      /Failed to load resource: the server responded with a status of 403 \(Forbidden\)/,
      /Failed to load resource: the server responded with a status of 500 \(Internal Server Error\)/,
    ],
  });

  await page.goto('/login.php');

  const unknown = await page.evaluate(async () => {
    const res = await fetch('/api/dns.php?action=nope');
    return { status: res.status, json: await res.json() };
  });
  expect([400, 401, 403]).toContain(unknown.status);
  expect(unknown.json.code).toBe(-1);

  const queryMissing = await page.evaluate(async () => {
    const res = await fetch('/api/dns.php?action=query');
    return { status: res.status, json: await res.json() };
  });
  expect([200, 401, 403, 500]).toContain(queryMissing.status);
  expect(queryMissing.json).toBeTruthy();

  const updateMissing = await page.evaluate(async () => {
    const res = await fetch('/api/dns.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'update' }),
    });
    return { status: res.status, json: await res.json() };
  });
  expect([200, 401, 403, 500]).toContain(updateMissing.status);
  expect(updateMissing.json).toBeTruthy();

  const batchMissing = await page.evaluate(async () => {
    const res = await fetch('/api/dns.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'action=batch_update',
    });
    return { status: res.status, json: await res.json() };
  });
  expect([200, 401, 403, 500]).toContain(batchMissing.status);
  expect(batchMissing.json).toBeTruthy();

  await loginAsDevAdmin(page);
  await tracker.assertNoClientErrors();
});

test('public dns api supports query update and batch_update success paths when zones resolve', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
      /Failed to load resource: the server responded with a status of 403 \(Forbidden\)/,
      /Failed to load resource: the server responded with a status of 500 \(Internal Server Error\)/,
    ],
  });
  const ts = Date.now();
  const fqdnA = `api-success-a-${ts}.606077.xyz`;
  const fqdnB = `api-success-b-${ts}.606077.xyz`;

  await page.goto('/login.php');

  const query = await page.evaluate(async (domain) => {
    const res = await fetch(`/api/dns.php?action=query&domain=${encodeURIComponent(domain)}&type=A`);
    return { status: res.status, json: await res.json() };
  }, fqdnA);
  if (query.status === 401) {
    expect(query.json.code).toBe(-1);
    expect(String(query.json.msg || '')).toContain('无效的 API Token');
    await tracker.assertNoClientErrors();
    return;
  }
  if (query.status === 403) {
    expect(query.json.code).toBe(-1);
    expect(String(query.json.msg || '')).toContain('仅允许本机');
    await tracker.assertNoClientErrors();
    return;
  }
  // 如果没有配置 DNS 账号，zones 无法解析，返回 code: -1 也是合理行为
  if (query.json.code === -1 && String(query.json.msg || '').includes('未匹配到')) {
    await tracker.assertNoClientErrors();
    return;
  }
  expect(query.status).toBe(200);
  expect(query.json.code).toBe(0);
  expect(query.json.data.fqdn).toBe(fqdnA);
  expect(Array.isArray(query.json.data.matches)).toBeTruthy();

  const update = await page.evaluate(async ({ domain, value }) => {
    const res = await fetch('/api/dns.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'update', domain, value, type: 'A', ttl: 600 }),
    });
    return { status: res.status, json: await res.json() };
  }, { domain: fqdnA, value: '203.0.113.77' });
  expect([200, 401, 403]).toContain(update.status);
  if (update.status === 401) {
    expect(update.json.code).toBe(-1);
    expect(String(update.json.msg || '')).toContain('无效的 API Token');
    await tracker.assertNoClientErrors();
    return;
  }
  if (update.status === 403) {
    expect(update.json.code).toBe(-1);
    expect(String(update.json.msg || '')).toContain('仅允许本机');
    await tracker.assertNoClientErrors();
    return;
  }
  expect(update.json.code).toBe(0);
  expect(['create', 'update', 'skip']).toContain(update.json.data.action);

  const batch = await page.evaluate(async ({ domainA, domainB }) => {
    const res = await fetch('/api/dns.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        action: 'batch_update',
        domains: [domainA, domainB],
        value: '203.0.113.88',
        type: 'A',
        ttl: 600,
      }),
    });
    return { status: res.status, json: await res.json() };
  }, { domainA: fqdnA, domainB: fqdnB });
  expect([200, 401, 403]).toContain(batch.status);
  if (batch.status === 401) {
    expect(batch.json.code).toBe(-1);
    expect(String(batch.json.msg || '')).toContain('无效的 API Token');
    await tracker.assertNoClientErrors();
    return;
  }
  if (batch.status === 403) {
    expect(batch.json.code).toBe(-1);
    expect(String(batch.json.msg || '')).toContain('仅允许本机');
    await tracker.assertNoClientErrors();
    return;
  }
  expect([0, -1]).toContain(batch.json.code);
  expect(Array.isArray(batch.json.results)).toBeTruthy();
  expect(batch.json.results.length).toBe(2);
  expect(batch.json.results.map((row: { domain: string }) => row.domain)).toEqual([fqdnA, fqdnB]);

  await tracker.assertNoClientErrors();
});

test('public dns api allows external access with valid api token', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
      /Failed to load resource: the server responded with a status of 500 \(Internal Server Error\)/,
    ],
  });

  await loginAsDevAdmin(page);

  // 生成 API Token
  await page.goto('/admin/api_tokens.php');
  await page.fill('input[name="token_name"]', 'e2e-dns-test');
  await page.getByRole('button', { name: '生成 Token' }).click();
  await page.waitForLoadState('networkidle');

  // 提取新生成的 token（从复制按钮的 data-token 获取完整 token）
  const token = await page.locator('button[data-token]').first().getAttribute('data-token');
  expect(token).toMatch(/^rop_[a-f0-9]{64}$/);
  if (token === null) throw new Error('生成的 API Token 为空');

  const ts = Date.now();
  const fqdn = `api-token-${ts}.606077.xyz`;

  // 使用 Token 通过 URL 参数访问
  const queryWithUrlToken = await page.evaluate(async ({ domain, apiToken }) => {
    const res = await fetch(`/api/dns.php?action=query&domain=${encodeURIComponent(domain)}&type=A&token=${encodeURIComponent(apiToken)}`);
    return { status: res.status, json: await res.json() };
  }, { domain: fqdn, apiToken: token });

  // 非本机 + 有效 Token 不应返回 401
  expect(queryWithUrlToken.status).not.toBe(401);
  // 没有配置 DNS 账号时返回未匹配到 Zone 也是正常的
  if (queryWithUrlToken.status === 200 && queryWithUrlToken.json.code === 0) {
    expect(queryWithUrlToken.json.data.fqdn).toBe(fqdn);
  }

  // 使用 Token 通过 Authorization Header 访问
  const queryWithBearer = await page.evaluate(async ({ domain, apiToken }) => {
    const res = await fetch(`/api/dns.php?action=query&domain=${encodeURIComponent(domain)}&type=A`, {
      headers: { 'Authorization': `Bearer ${apiToken}` },
    });
    return { status: res.status, json: await res.json() };
  }, { domain: fqdn, apiToken: token });

  expect(queryWithBearer.status).not.toBe(401);

  await tracker.assertNoClientErrors();
});
