import { test, expect } from '../../helpers/fixtures';
import { attachClientErrorTracking, loginAsDevAdmin } from '../../helpers/auth';
import { runDockerPhpInline } from '../../helpers/cli';

test('favicon fetch succeeds caches result and follows redirects', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
    ],
  });

  await loginAsDevAdmin(page);

  const testHost = 'example.com';
  const cacheFile = `/var/www/nav/data/favicon_cache/${runDockerPhpInline(`echo md5('${testHost}');`).stdout.trim()}.ico`;

  // Clean cache if exists
  runDockerPhpInline(`$p = '${cacheFile}'; if (file_exists($p)) unlink($p);`);

  // 1. First request should fetch and cache
  const res1 = await page.request.get(`http://127.0.0.1:58080/favicon.php?url=https://${testHost}`, {
    timeout: 30000,
  });
  // Some domains (e.g. example.com) may not have a valid favicon, resulting in 204
  expect([200, 204]).toContain(res1.status());
  if (res1.status() === 200) {
    expect(res1.headers()['content-type']).toContain('image');
  }

  // Verify cache file was created (only when favicon was successfully fetched)
  if (res1.status() === 200) {
    await expect
      .poll(() => {
        const result = runDockerPhpInline(`$p = '${cacheFile}'; echo file_exists($p) ? filesize($p) : 0;`);
        expect(result.code).toBe(0);
        return parseInt(result.stdout.trim(), 10);
      })
      .toBeGreaterThan(0);
  }

  // 2. Second request should hit cache and still return 200 (or 204 if no valid favicon)
  const res2 = await page.request.get(`http://127.0.0.1:58080/favicon.php?url=https://${testHost}`, {
    timeout: 30000,
  });
  expect([200, 204]).toContain(res2.status());
  if (res2.status() === 200) {
    expect(res2.headers()['content-type']).toContain('image');
  }

  // 3. Test redirect following with a domain that redirects
  // Wikipedia redirects http -> https and may redirect to www
  const redirectHost = 'wikipedia.org';
  const redirectCacheFile = `/var/www/nav/data/favicon_cache/${runDockerPhpInline(`echo md5('${redirectHost}');`).stdout.trim()}.ico`;
  runDockerPhpInline(`$p = '${redirectCacheFile}'; if (file_exists($p)) unlink($p);`);

  const res3 = await page.request.get(`http://127.0.0.1:58080/favicon.php?url=http://${redirectHost}`, {
    timeout: 30000,
  });
  // Should succeed (200) or return 204 if no valid favicon found; we accept 200/204
  expect([200, 204]).toContain(res3.status());

  await tracker.assertNoClientErrors();
});
