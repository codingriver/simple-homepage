import fs from 'fs/promises';
import path from 'path';
import { test, expect } from '../../helpers/fixtures';
import { attachClientErrorTracking, loginAsDevAdmin } from '../../helpers/auth';

const webdavAccountsPath = path.resolve(__dirname, '../../../data/webdav_accounts.json');
const webdavLogPath = path.resolve(__dirname, '../../../data/logs/webdav.log');
const configPath = path.resolve(__dirname, '../../../data/config.json');

test('webdav shares overview displays aggregates and supports filtering', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page);
  const ts = Date.now();

  const accounts = {
    version: 1,
    accounts: [
      {
        id: `wd1-${ts}`,
        username: `alice-${ts}`,
        password_hash: 'hash1',
        root: '/data/share-a',
        readonly: false,
        enabled: true,
        notes: 'test-a',
      },
      {
        id: `wd2-${ts}`,
        username: `bob-${ts}`,
        password_hash: 'hash2',
        root: '/data/share-a',
        readonly: true,
        enabled: false,
        notes: 'test-b',
      },
      {
        id: `wd3-${ts}`,
        username: `carol-${ts}`,
        password_hash: 'hash3',
        root: '/data/share-b',
        readonly: false,
        enabled: true,
        notes: 'test-c',
      },
    ],
  };

  const logEntries = [
    JSON.stringify({ time: '2026-01-01 10:00:00', user: `alice-${ts}`, action: 'put', context: { path: '/f1.txt' } }) + '\n',
    JSON.stringify({ time: '2026-01-01 10:01:00', user: `carol-${ts}`, action: 'get', context: { path: '/f2.txt' } }) + '\n',
  ].join('');

  await fs.mkdir(path.dirname(webdavAccountsPath), { recursive: true });
  await fs.writeFile(webdavAccountsPath, JSON.stringify(accounts, null, 2), 'utf8');
  await fs.mkdir(path.dirname(webdavLogPath), { recursive: true });
  await fs.writeFile(webdavLogPath, logEntries, 'utf8');

  const configRaw = await fs.readFile(configPath, 'utf8').catch(() => '{}');
  const config = JSON.parse(configRaw);
  config.webdav_enabled = '1';
  await fs.writeFile(configPath, JSON.stringify(config, null, 2), 'utf8');

  await loginAsDevAdmin(page);
  await page.goto('/admin/webdav_shares.php');

  await expect(page.locator('body')).toContainText('WebDAV 共享总览');
  await expect(page.locator('tbody tr')).toHaveCount(2);

  await expect(page.locator('tbody')).toContainText('/data/share-a');
  await expect(page.locator('tbody')).toContainText('/data/share-b');
  await expect(page.locator('tbody')).toContainText(`alice-${ts}`);
  await expect(page.locator('tbody')).toContainText(`bob-${ts}`);

  await expect(page.locator('tbody')).toContainText('账号 2');
  await expect(page.locator('tbody')).toContainText('启用 1');
  await expect(page.locator('tbody')).toContainText('只读 1');

  await page.locator('#webdav-share-filter').fill(`carol-${ts}`);
  const shareA = page.locator('.webdav-share-row').filter({ hasText: '/data/share-a' });
  const shareB = page.locator('.webdav-share-row').filter({ hasText: '/data/share-b' });
  await expect(shareA).toBeHidden();
  await expect(shareB).toBeVisible();

  await page.locator('#webdav-share-filter').fill('share-a');
  await expect(shareA).toBeVisible();
  await expect(shareB).toBeHidden();

  await page.locator('#webdav-share-filter').fill('');
  await page.evaluate(() => {
    const el = document.getElementById('webdav-share-filter') as HTMLInputElement | null;
    if (el) el.value = '';
    el?.dispatchEvent(new Event('input'));
  });
  await expect(shareA).toBeVisible();
  await expect(shareB).toBeVisible();

  const openDir = page.locator('tbody tr').filter({ hasText: '/data/share-a' }).getByRole('link', { name: /打开目录/ });
  await openDir.click();
  await expect(page).toHaveURL(/files\.php.*host_id=local/);
  await expect(page).toHaveURL(/path=%2Fdata%2Fshare-a/);

  await tracker.assertNoClientErrors();
});
