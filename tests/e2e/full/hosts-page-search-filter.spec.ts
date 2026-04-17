import fs from 'fs/promises';
import path from 'path';
import { test, expect } from '../../helpers/fixtures';
import { attachClientErrorTracking, loginAsDevAdmin } from '../../helpers/auth';
import { runDockerPhpInline } from '../../helpers/cli';

const sshHostsPath = path.resolve(__dirname, '../../../data/ssh_hosts.json');

test('hosts page filters remote hosts via frontend search and group filter', async ({ page }) => {
  test.setTimeout(120000);
  const tracker = await attachClientErrorTracking(page);
  const ts = Date.now();
  const hostA = `filter-alpha-${ts}`;
  const hostB = `filter-beta-${ts}`;

  const payload = {
    hosts: [
      {
        id: `ha-${ts}`,
        name: hostA,
        hostname: '192.168.1.10',
        port: 22,
        username: 'root',
        auth_type: 'password',
        group_name: 'group-a',
        tags: 'test',
        favorite: false,
        notes: '',
      },
      {
        id: `hb-${ts}`,
        name: hostB,
        hostname: '192.168.1.11',
        port: 22,
        username: 'root',
        auth_type: 'password',
        group_name: 'group-b',
        tags: 'prod',
        favorite: true,
        notes: '',
      },
    ],
  };

  await fs.writeFile(sshHostsPath, JSON.stringify(payload, null, 2), 'utf8');

  try {
    await loginAsDevAdmin(page);
    await page.goto('/admin/hosts.php#remote');

    // search by name
    await page.locator('input#host-search').fill(hostA);
    await page.waitForTimeout(200);
    await expect(page.locator(`tr.remote-host-row:has-text("${hostA}")`)).toBeVisible();
    await expect(page.locator(`tr.remote-host-row:has-text("${hostB}")`)).toHaveCount(0);

    // clear search
    await page.locator('input#host-search').fill('');
    await page.waitForTimeout(200);
    await expect(page.locator(`tr.remote-host-row:has-text("${hostA}")`)).toBeVisible();
    await expect(page.locator(`tr.remote-host-row:has-text("${hostB}")`)).toBeVisible();

    // group filter
    await page.locator('select#host-group-filter').selectOption('group-b');
    await page.waitForTimeout(200);
    await expect(page.locator(`tr.remote-host-row:has-text("${hostB}")`)).toBeVisible();
    await expect(page.locator(`tr.remote-host-row:has-text("${hostA}")`)).toHaveCount(0);
  } finally {
    await fs.writeFile(sshHostsPath, JSON.stringify({ hosts: [] }, null, 2), 'utf8').catch(() => undefined);
  }

  await tracker.assertNoClientErrors();
});
