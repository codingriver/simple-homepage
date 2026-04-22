import { test, expect } from '../../helpers/fixtures';
import { attachClientErrorTracking, loginAsDevAdmin } from '../../helpers/auth';

test('service worker serves cached assets and page fallback when offline', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
    ],
  });

  await loginAsDevAdmin(page);
  await page.goto('/index.php');

  // Verify SW is registered and controlling the page
  await page.waitForFunction(() => {
    return !!(navigator.serviceWorker && navigator.serviceWorker.controller);
  }, { timeout: 15000 });
  const swUrl = await page.evaluate(() => navigator.serviceWorker?.controller?.scriptURL || '');
  expect(swUrl).toContain('sw.js');

  // Pre-cache the homepage and login page so offline reload works
  await page.evaluate(async () => {
    const cache = await caches.open('nav-cache-v1');
    await cache.add('/');
    await cache.add('/index.php');
  });

  // Also visit login.php to ensure login.css gets cached by the SW
  await page.goto('/login.php');
  await expect(page.locator('body')).toContainText('登录');

  // Go offline
  await page.context().setOffline(true);

  // Reload homepage while offline — should fallback to cached version
  await page.goto('/index.php');
  // Verify the page loaded (at least body is present and no total crash)
  await expect(page.locator('body')).toBeVisible();

  // Try loading a pre-cached .js file via fetch (goes through SW)
  const jsStatus = await page.evaluate(async () => {
    try {
      const res = await fetch('/gesture-guard.js');
      return { status: res.status, text: await res.text() };
    } catch (e) {
      return { status: 0, text: '' };
    }
  });
  expect(jsStatus.status).toBe(200);
  expect(jsStatus.text).toContain('Gesture');

  // Try loading a .css file that was cached on first visit
  const cssStatus = await page.evaluate(async () => {
    try {
      const res = await fetch('/login.css');
      return { status: res.status, type: res.headers.get('content-type') || '' };
    } catch (e) {
      return { status: 0, type: '' };
    }
  });
  expect(cssStatus.status).toBe(200);
  expect(cssStatus.type).toContain('css');

  // Go back online
  await page.context().setOffline(false);

  // Verify normal behavior resumes
  await page.goto('/index.php');
  await expect(page.locator('body')).toBeVisible();

  const onlineRes = await page.request.get('http://127.0.0.1:58080/gesture-guard.js');
  expect(onlineRes.status()).toBe(200);

  await tracker.assertNoClientErrors();
});

test('service worker cleans up old caches on activate', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page);

  await loginAsDevAdmin(page);
  await page.goto('/index.php');

  // Wait for SW to be controlling
  await page.waitForFunction(() => {
    return !!(navigator.serviceWorker && navigator.serviceWorker.controller);
  }, { timeout: 15000 });

  // Inject an old cache to simulate previous version
  await page.evaluate(async () => {
    await caches.open('nav-cache-v0-old');
    await caches.open('nav-cache-v1');
  });

  // Unregister current SW and re-register with a different URL to force new activate
  await page.evaluate(async () => {
    const reg = await navigator.serviceWorker.ready;
    await reg.unregister();
    await new Promise(r => setTimeout(r, 300));
    const newReg = await navigator.serviceWorker.register('/sw.js?cleanup=' + Date.now());
    await new Promise(r => setTimeout(r, 500));
    // Wait for the new SW to be controlling
    if (newReg.installing) {
      await new Promise(resolve => {
        newReg.installing.addEventListener('statechange', function wait() {
          if (newReg.installing.state === 'activated') {
            newReg.installing.removeEventListener('statechange', wait);
            resolve(undefined);
          }
        });
      });
    }
  });

  // Reload so the new SW controls the page
  await page.reload();

  // Verify only nav-cache-v1 remains (old caches cleaned up)
  const remainingCaches = await page.evaluate(async () => {
    return await caches.keys();
  });
  expect(remainingCaches).not.toContain('nav-cache-v0-old');
  expect(remainingCaches).toContain('nav-cache-v1');

  await tracker.assertNoClientErrors();
});
