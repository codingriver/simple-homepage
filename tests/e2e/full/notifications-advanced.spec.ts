import fs from 'fs/promises';
import path from 'path';
import { expect, test } from '../../helpers/fixtures';
import { attachClientErrorTracking, loginAsDevAdmin } from '../../helpers/auth';

const notifyFile = path.resolve(__dirname, '../../../data/notifications.json');

test('notifications page supports channel crud toggle and test', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page);
  const ts = Date.now();
  const channelName = `NotifyChannel${ts}`;

  // clear existing channels
  await fs.writeFile(notifyFile, JSON.stringify({ channels: {} }, null, 2), 'utf8');

  await loginAsDevAdmin(page);
  await page.goto('/admin/notifications.php');

  // create channel
  await page.locator('input[name="name"]').fill(channelName);
  await page.locator('select[name="type"]').selectOption('custom');
  await page.locator('input[name="webhook_url"]').fill(`https://example.com/webhook-${ts}`);
  await page.getByRole('button', { name: '保存通知渠道' }).click();
  await expect(page.locator('body')).toContainText(channelName);

  // toggle channel (disable)
  const csrf = await page.locator('input[name="_csrf"]').first().inputValue();
  const row = page.locator('table tbody tr').filter({ hasText: channelName });
  const channelId = await row.locator('input[name="id"]').first().inputValue();

  const toggleRes = await page.request.post('http://127.0.0.1:58080/admin/notifications.php', {
    form: { action: 'toggle_channel', id: channelId, _csrf: csrf },
  });
  expect(toggleRes.status()).toBe(200);

  await page.goto('/admin/notifications.php');
  await expect(row.locator('.badge-gray')).toContainText('禁用');

  // test channel
  const csrf2 = await page.locator('input[name="_csrf"]').first().inputValue();
  const testRes = await page.request.post('http://127.0.0.1:58080/admin/notifications.php', {
    form: { action: 'test_channel', id: channelId, _csrf: csrf2 },
  });
  expect(testRes.status()).toBe(200);

  // delete channel
  const csrf3 = await page.locator('input[name="_csrf"]').first().inputValue();
  const deleteRes = await page.request.post('http://127.0.0.1:58080/admin/notifications.php', {
    form: { action: 'delete_channel', id: channelId, _csrf: csrf3 },
  });
  expect(deleteRes.status()).toBe(200);

  await page.goto('/admin/notifications.php');
  await expect(page.locator('body')).not.toContainText(channelName);

  await tracker.assertNoClientErrors();
});
