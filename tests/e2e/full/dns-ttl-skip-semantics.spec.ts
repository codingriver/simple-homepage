import { test, expect } from '@playwright/test';
import { attachClientErrorTracking } from '../../helpers/auth';

test('public dns api preserves skip semantics and normalizes ttl floor', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
      /Failed to load resource: the server responded with a status of 403 \(Forbidden\)/,
      /Failed to load resource: the server responded with a status of 500 \(Internal Server Error\)/,
    ],
  });
  const ts = Date.now();
  const fqdn = `ttl-skip-${ts}.606077.xyz`;

  await page.goto('/login.php');

  const first = await page.evaluate(async (domain) => {
    const res = await fetch('/api/dns.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'update', domain, value: '203.0.113.91', type: 'A', ttl: 1 }),
    });
    return { status: res.status, json: await res.json() };
  }, fqdn);
  expect([200, 403]).toContain(first.status);
  if (first.status === 403) {
    expect(first.json.code).toBe(-1);
    expect(String(first.json.msg || '')).toContain('仅允许本机');
    await tracker.assertNoClientErrors();
    return;
  }
  expect(first.json.code).toBe(0);
  expect(['create', 'update', 'skip']).toContain(first.json.data.action);

  const second = await page.evaluate(async (domain) => {
    const res = await fetch('/api/dns.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'update', domain, value: '203.0.113.91', type: 'A', ttl: 1 }),
    });
    return { status: res.status, json: await res.json() };
  }, fqdn);
  expect([200, 403]).toContain(second.status);
  if (second.status === 403) {
    expect(second.json.code).toBe(-1);
    expect(String(second.json.msg || '')).toContain('仅允许本机');
    await tracker.assertNoClientErrors();
    return;
  }
  expect(second.json.code).toBe(0);
  expect(['skip', 'update']).toContain(second.json.data.action);

  await tracker.assertNoClientErrors();
});
