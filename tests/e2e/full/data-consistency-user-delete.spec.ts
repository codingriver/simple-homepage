import fs from 'fs/promises';
import path from 'path';
import { expect, test } from '../../helpers/fixtures';
import { attachClientErrorTracking, loginAsDevAdmin } from '../../helpers/auth';
import { runDockerPhpInline } from '../../helpers/cli';

const usersFile = path.resolve(__dirname, '../../../data/users.json');
const sessionsFile = path.resolve(__dirname, '../../../data/sessions.json');

test('deleting a user invalidates their active sessions', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page);

  const ts = Date.now();
  const username = `consistency_user_${ts}`;
  const password = 'ConsistPass@test2026';

  // create user directly
  const hashResult = runDockerPhpInline(`echo password_hash('${password}', PASSWORD_DEFAULT);`);
  expect(hashResult.code).toBe(0);
  const hash = hashResult.stdout.trim();
  const raw = await fs.readFile(usersFile, 'utf8').catch(() => '{}');
  const users = JSON.parse(raw);
  users[username] = {
    role: 'host_viewer',
    password_hash: hash,
    created_at: new Date().toISOString(),
  };
  await fs.writeFile(usersFile, JSON.stringify(users, null, 2), 'utf8');

  // login as the user to create a session
  await page.goto('/login.php');
  await page.locator('input[name="username"]').fill(username);
  await page.locator('input[name="password"]').fill(password);
  await page.getByRole('button', { name: /登\s*录/ }).click();
  await expect(page).toHaveURL(/index\.php|\/$/);

  // verify session exists
  const sessionsBefore = await fs.readFile(sessionsFile, 'utf8').catch(() => '{}');
  const sessionsBeforeObj = JSON.parse(sessionsBefore);
  const userTokens = Object.entries(sessionsBeforeObj).filter(([, v]: [string, any]) => v.username === username);
  expect(userTokens.length).toBeGreaterThan(0);

  // delete user via admin
  await loginAsDevAdmin(page);
  await page.goto('/admin/users.php');

  const csrf = await page.locator('input[name="_csrf"]').first().inputValue();
  const deleteRes = await page.request.post('http://127.0.0.1:58080/admin/users.php', {
    form: { action: 'delete', username, _csrf: csrf },
  });
  expect(deleteRes.status()).toBe(200);

  // verify user removed from users.json
  const usersAfter = JSON.parse(await fs.readFile(usersFile, 'utf8').catch(() => '{}'));
  expect(usersAfter[username]).toBeUndefined();

  // verify sessions for this user are cleared
  const sessionsAfter = await fs.readFile(sessionsFile, 'utf8').catch(() => '{}');
  const sessionsAfterObj = JSON.parse(sessionsAfter);
  const remainingTokens = Object.entries(sessionsAfterObj).filter(([, v]: [string, any]) => v.username === username);
  expect(remainingTokens.length).toBe(0);

  await tracker.assertNoClientErrors();
});
