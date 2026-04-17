import fs from 'fs/promises';
import path from 'path';
import { expect, test } from '../../helpers/fixtures';
import { attachClientErrorTracking, loginAsDevAdmin } from '../../helpers/auth';
import { runDockerPhpInline } from '../../helpers/cli';

const sitesFile = path.resolve(__dirname, '../../../data/sites.json');
const healthCacheFile = path.resolve(__dirname, '../../../data/health_cache.json');
const nginxDir = path.resolve(__dirname, '../../../data/nginx');
const faviconDir = path.resolve(__dirname, '../../../data/favicon_cache');

test('deleting a site cleans up nginx config favicon and health cache', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page);
  const ts = Date.now();
  const gid = `cleanup-g-${ts}`;
  const sid = `cleanup-s-${ts}`;
  const siteUrl = `https://example.com?cleanup=${ts}`;
  const proxyTarget = `http://192.168.1.${(ts % 250) + 1}:8080`;

  // seed site data
  const sites = {
    groups: [
      {
        id: gid,
        name: `Cleanup Group ${ts}`,
        icon: '',
        visibility: 'all',
        order: 0,
        sites: [
          {
            id: sid,
            name: `Cleanup Site ${ts}`,
            icon: '',
            type: 'proxy',
            url: siteUrl,
            proxy_target: proxyTarget,
            tags: '',
            env: '',
            asset_type: '',
            badge: '',
            status: 'active',
            order: 0,
            gid,
          },
        ],
      },
    ],
  };
  await fs.writeFile(sitesFile, JSON.stringify(sites, null, 2), 'utf8');

  // seed nginx config
  await fs.mkdir(nginxDir, { recursive: true });
  await fs.writeFile(path.join(nginxDir, `${sid}.conf`), `server { listen 80; }`, 'utf8');

  // seed favicon cache
  await fs.mkdir(faviconDir, { recursive: true });
  await fs.writeFile(path.join(faviconDir, `${sid}.png`), Buffer.from([0, 1, 2]), 'utf8');

  // seed health cache
  const healthCache = {
    [proxyTarget]: { status: 'up', checked_at: new Date().toISOString() },
  };
  await fs.writeFile(healthCacheFile, JSON.stringify(healthCache, null, 2), 'utf8');

  await loginAsDevAdmin(page);
  await page.goto('/admin/sites.php');

  // delete the site via API
  const csrf = await page.locator('input[name="_csrf"]').first().inputValue();
  const deleteRes = await page.request.post('http://127.0.0.1:58080/admin/sites.php', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: { action: 'delete', gid, sid, _csrf: csrf },
  });
  expect(deleteRes.status()).toBe(200);
  const deleteBody = await deleteRes.json();
  expect(deleteBody.ok).toBe(true);

  // verify nginx config removed
  const nginxExists = await fs.access(path.join(nginxDir, `${sid}.conf`)).then(() => true).catch(() => false);
  expect(nginxExists).toBe(false);

  // verify favicon removed
  const faviconExists = await fs.access(path.join(faviconDir, `${sid}.png`)).then(() => true).catch(() => false);
  expect(faviconExists).toBe(false);

  // verify health cache entry removed
  const healthAfter = JSON.parse(await fs.readFile(healthCacheFile, 'utf8').catch(() => '{}'));
  expect(healthAfter[proxyTarget]).toBeUndefined();

  await tracker.assertNoClientErrors();
});

test('deleting a group cleans up child sites nginx configs and caches', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page);
  const ts = Date.now();
  const gid = `cleanup-g2-${ts}`;
  const sid = `cleanup-s2-${ts}`;
  const siteUrl = `https://example.com?cleanup2=${ts}`;
  const proxyTarget = `http://192.168.2.${(ts % 250) + 1}:8080`;

  // seed site data
  const sites = {
    groups: [
      {
        id: gid,
        name: `Cleanup2 Group ${ts}`,
        icon: '',
        visibility: 'all',
        order: 0,
        sites: [
          {
            id: sid,
            name: `Cleanup2 Site ${ts}`,
            icon: '',
            type: 'proxy',
            url: siteUrl,
            proxy_target: proxyTarget,
            tags: '',
            env: '',
            asset_type: '',
            badge: '',
            status: 'active',
            order: 0,
            gid,
          },
        ],
      },
    ],
  };
  await fs.writeFile(sitesFile, JSON.stringify(sites, null, 2), 'utf8');

  // seed nginx config and favicon
  await fs.mkdir(nginxDir, { recursive: true });
  await fs.writeFile(path.join(nginxDir, `${sid}.conf`), `server { listen 80; }`, 'utf8');
  await fs.mkdir(faviconDir, { recursive: true });
  await fs.writeFile(path.join(faviconDir, `${sid}.png`), Buffer.from([0, 1, 2]), 'utf8');

  // seed health cache
  const healthCache = {
    [proxyTarget]: { status: 'up', checked_at: new Date().toISOString() },
  };
  await fs.writeFile(healthCacheFile, JSON.stringify(healthCache, null, 2), 'utf8');

  await loginAsDevAdmin(page);

  // delete the group via API
  await page.goto('/admin/groups.php');
  const csrf = await page.locator('input[name="_csrf"]').first().inputValue();
  const deleteRes = await page.request.post('http://127.0.0.1:58080/admin/groups.php', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: { action: 'delete', gid, _csrf: csrf },
  });
  expect(deleteRes.status()).toBe(200);
  const deleteBody = await deleteRes.json();
  expect(deleteBody.ok).toBe(true);

  // verify nginx config removed
  const nginxExists = await fs.access(path.join(nginxDir, `${sid}.conf`)).then(() => true).catch(() => false);
  expect(nginxExists).toBe(false);

  // verify favicon removed
  const faviconExists = await fs.access(path.join(faviconDir, `${sid}.png`)).then(() => true).catch(() => false);
  expect(faviconExists).toBe(false);

  // verify health cache entry removed
  const healthAfter = JSON.parse(await fs.readFile(healthCacheFile, 'utf8').catch(() => '{}'));
  expect(healthAfter[proxyTarget]).toBeUndefined();

  await tracker.assertNoClientErrors();
});
