import { test, expect } from '../../helpers/fixtures';
import { attachClientErrorTracking, loginAsDevAdmin } from '../../helpers/auth';

test('task status api handles empty ids and returns payload structure', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page);
  await loginAsDevAdmin(page);

  const emptyRes = await page.request.get('http://127.0.0.1:58080/admin/api/task_status.php?ids=');
  expect(emptyRes.status()).toBe(200);
  const emptyBody = await emptyRes.json();
  expect(emptyBody).toHaveProperty('server_time');
  expect(emptyBody).toHaveProperty('tasks');

  await tracker.assertNoClientErrors();
});
