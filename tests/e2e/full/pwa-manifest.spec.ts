import { test, expect } from '../../helpers/fixtures';
import { attachClientErrorTracking } from '../../helpers/auth';

test('manifest.webmanifest contains all required fields', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page);

  const response = await page.request.get('http://127.0.0.1:58080/manifest.webmanifest');
  expect(response.status()).toBe(200);
  expect(response.headers()['content-type']).toContain('application/json');

  const manifest = await response.json();
  expect(manifest.name).toBeTruthy();
  expect(manifest.short_name).toBeTruthy();
  expect(manifest.start_url).toBeTruthy();
  expect(manifest.display).toBe('standalone');
  expect(manifest.background_color).toBeTruthy();
  expect(manifest.theme_color).toBeTruthy();
  expect(Array.isArray(manifest.icons)).toBe(true);
  expect(manifest.icons.length).toBeGreaterThan(0);
  expect(manifest.icons[0].src).toBeTruthy();
  expect(manifest.icons[0].sizes).toBeTruthy();
  expect(manifest.icons[0].type).toBeTruthy();

  await tracker.assertNoClientErrors();
});
