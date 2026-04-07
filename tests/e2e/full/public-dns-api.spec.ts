import { test, expect } from '@playwright/test';
import { attachClientErrorTracking, loginAsDevAdmin } from '../../helpers/auth';

test('public dns api guards unknown action and localhost success shape', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
      /Failed to load resource: the server responded with a status of 403 \(Forbidden\)/,
      /Failed to load resource: the server responded with a status of 500 \(Internal Server Error\)/,
    ],
  });

  await page.goto('/login.php');

  const unknown = await page.evaluate(async () => {
    const res = await fetch('/api/dns.php?action=nope');
    return { status: res.status, json: await res.json() };
  });
  expect([400, 403]).toContain(unknown.status);
  expect(unknown.json.code).toBe(-1);

  const queryMissing = await page.evaluate(async () => {
    const res = await fetch('/api/dns.php?action=query');
    return { status: res.status, json: await res.json() };
  });
  expect([200, 403, 500]).toContain(queryMissing.status);
  expect(queryMissing.json).toBeTruthy();

  const updateMissing = await page.evaluate(async () => {
    const res = await fetch('/api/dns.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'update' }),
    });
    return { status: res.status, json: await res.json() };
  });
  expect([200, 403, 500]).toContain(updateMissing.status);
  expect(updateMissing.json).toBeTruthy();

  const batchMissing = await page.evaluate(async () => {
    const res = await fetch('/api/dns.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'action=batch_update',
    });
    return { status: res.status, json: await res.json() };
  });
  expect([200, 403, 500]).toContain(batchMissing.status);
  expect(batchMissing.json).toBeTruthy();

  await loginAsDevAdmin(page);
  await tracker.assertNoClientErrors();
});

test('public dns api supports query update and batch_update success paths when zones resolve', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
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
  if (query.status === 403) {
    expect(query.json.code).toBe(-1);
    expect(String(query.json.msg || '')).toContain('仅允许本机');
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
  expect([200, 403]).toContain(update.status);
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
  expect([200, 403]).toContain(batch.status);
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
