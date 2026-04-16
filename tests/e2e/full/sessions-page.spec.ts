import fs from 'fs/promises';
import path from 'path';
import { expect, test } from '../../helpers/fixtures';
import { attachClientErrorTracking, loginAsDevAdmin } from '../../helpers/auth';

const sessionsFile = path.resolve(__dirname, '../../../data/sessions.json');

test('sessions page lists active sessions and supports filtering', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page);
  const ts = Date.now();

  // seed sessions
  await fs.writeFile(
    sessionsFile,
    JSON.stringify({
      [`jti-list-${ts}`]: {
        jti: `jti-list-${ts}`,
        username: 'qatest',
        ip: '127.0.0.1',
        user_agent: 'E2E Test Agent',
        created_at: new Date().toISOString(),
      },
      [`jti-other-${ts}`]: {
        jti: `jti-other-${ts}`,
        username: 'otheruser',
        ip: '192.168.1.1',
        user_agent: 'Other Agent',
        created_at: new Date().toISOString(),
      },
    }, null, 2),
    'utf8'
  );

  await loginAsDevAdmin(page);
  await page.goto('/admin/sessions.php');

  await expect(page.locator('body')).toContainText('会话管理');
  await expect(page.locator('#sessions-wrap')).toContainText('qatest');
  await expect(page.locator('#sessions-wrap')).toContainText('otheruser');

  // filter by username
  await page.goto('/admin/sessions.php?username=qatest');
  await expect(page.locator('body')).toContainText('qatest');
  await expect(page.locator('#sessions-wrap')).toContainText('E2E Test Agent');

  // sessions_api list
  const listRes = await page.request.get(
    'http://127.0.0.1:58080/admin/sessions_api.php?action=list',
    { headers: { 'X-Requested-With': 'XMLHttpRequest' } }
  );
  expect(listRes.status()).toBe(200);
  const listBody = await listRes.json();
  expect(listBody.ok).toBe(true);
  expect(Array.isArray(listBody.sessions)).toBe(true);
  expect(listBody.sessions.some((s: any) => s.username === 'qatest')).toBe(true);

  // filtered list
  const filteredRes = await page.request.get(
    'http://127.0.0.1:58080/admin/sessions_api.php?action=list&username=otheruser',
    { headers: { 'X-Requested-With': 'XMLHttpRequest' } }
  );
  expect(filteredRes.status()).toBe(200);
  const filteredBody = await filteredRes.json();
  expect(filteredBody.ok).toBe(true);
  expect(Array.isArray(filteredBody.sessions)).toBe(true);
  expect(filteredBody.sessions.every((s: any) => s.username === 'otheruser')).toBe(true);

  await tracker.assertNoClientErrors();
});
