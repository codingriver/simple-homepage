import { test, expect } from '../../helpers/fixtures';
import { runDockerPhpInline } from '../../helpers/cli';
import fs from 'fs/promises';
import path from 'path';

const usersFile = path.resolve(__dirname, '../../../data/users.json');

async function createTestUser(role: string, username: string, password: string) {
  const hashResult = runDockerPhpInline(`echo password_hash('${password}', PASSWORD_DEFAULT);`);
  const hash = hashResult.stdout.trim();
  const raw = await fs.readFile(usersFile, 'utf8').catch(() => '{}');
  const users = JSON.parse(raw);
  users[username] = { role, password_hash: hash, created_at: new Date().toISOString() };
  await fs.writeFile(usersFile, JSON.stringify(users, null, 2), 'utf8');
}

async function deleteTestUser(username: string) {
  const raw = await fs.readFile(usersFile, 'utf8').catch(() => '{}');
  const users = JSON.parse(raw);
  delete users[username];
  await fs.writeFile(usersFile, JSON.stringify(users, null, 2), 'utf8');
}

test('debug users.php for host viewer', async ({ page }) => {
  const u = `debugviewer_${Date.now()}`;
  await createTestUser('host_viewer', u, 'Pass12345');
  await page.goto('/login.php');
  await page.locator('input[name="username"]').fill(u);
  await page.locator('input[name="password"]').fill('Pass12345');
  await page.getByRole('button', { name: /登\s*录/ }).click();
  await expect(page).toHaveURL(/index\.php|\/$/, { timeout: 5000 });
  
  const res = await page.request.get('http://127.0.0.1:58080/admin/users.php');
  console.log('status', res.status());
  console.log('url', res.url());
  const body = await res.text();
  console.log('body start:', body.substring(0, 200));
  await deleteTestUser(u);
});
