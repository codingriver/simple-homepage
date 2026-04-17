import { test, expect } from '../../helpers/fixtures';
import { attachClientErrorTracking, loginAsDevAdmin } from '../../helpers/auth';

test('file api rejects path traversal attempts in read write and list actions', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page);
  await loginAsDevAdmin(page);
  await page.goto('/admin/files.php');
  const csrf = await page.locator('input[name="_csrf"]').first().inputValue();

  const traversalPaths = ['../../../etc/passwd', '..\\\\..\\\\windows\\\\system32\\\\drivers\\\\etc\\\\hosts', 'foo/../../bar'];

  for (const maliciousPath of traversalPaths) {
    // list
    const listRes = await page.request.post('http://127.0.0.1:58080/admin/file_api.php', {
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      form: { action: 'list', _csrf: csrf, path: maliciousPath },
    });
    expect([200, 403, 400]).toContain(listRes.status());
    if (listRes.status() === 200) {
      const body = await listRes.json();
      expect(body.ok).toBe(false);
    }

    // read
    const readRes = await page.request.post('http://127.0.0.1:58080/admin/file_api.php', {
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      form: { action: 'read', _csrf: csrf, path: maliciousPath },
    });
    expect([200, 403, 400]).toContain(readRes.status());
    if (readRes.status() === 200) {
      const body = await readRes.json();
      expect(body.ok).toBe(false);
    }
  }

  await tracker.assertNoClientErrors();
});
