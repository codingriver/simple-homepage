import { test, expect } from '../../helpers/fixtures';
import { attachClientErrorTracking } from '../../helpers/auth';

test('debug ip endpoints return plain text with ip details', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page);

  const res1 = await page.request.get('http://127.0.0.1:58080/debug_ip.php');
  expect(res1.status()).toBe(200);
  expect(res1.headers()['content-type']).toContain('text/plain');
  const text1 = await res1.text();
  expect(text1).toContain('HTTP_X_REAL_IP:');
  expect(text1).toContain('REMOTE_ADDR:');
  expect(text1).toContain('webdav_client_ip:');

  const res2 = await page.request.get('http://127.0.0.1:58080/debug_ip2.php');
  expect(res2.status()).toBe(200);
  expect(res2.headers()['content-type']).toContain('text/plain');
  const text2 = await res2.text();
  expect(text2).toContain('HTTP_X_FORWARDED_FOR:');
  expect(text2).toContain('HTTP_X_REAL_IP:');
  expect(text2).toContain('REMOTE_ADDR:');

  await tracker.assertNoClientErrors();
});
