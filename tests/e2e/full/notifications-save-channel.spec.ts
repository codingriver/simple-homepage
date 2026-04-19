import fs from 'fs/promises';
import path from 'path';
import { test, expect } from '../../helpers/fixtures';
import { attachClientErrorTracking, loginAsDevAdmin } from '../../helpers/auth';

const notifyFile = path.resolve(__dirname, '../../../data/notifications.json');

test('notifications save_channel action persists directly via post', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page);
  const ts = Date.now();
  const channelName = `DirectSaveChannel${ts}`;

  await fs.writeFile(notifyFile, JSON.stringify({ channels: {} }, null, 2), 'utf8');

  try {
    await loginAsDevAdmin(page);
    await page.goto('/admin/notifications.php');
    const csrf = await page.locator('input[name="_csrf"]').first().inputValue();

    const saveRes = await page.request.post('http://127.0.0.1:58080/admin/notifications.php', {
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      data: new URLSearchParams({
        action: 'save_channel',
        _csrf: csrf,
        name: channelName,
        type: 'custom',
        webhook_url: `https://example.com/webhook-${ts}`,
        enabled: '1',
        'events[]': 'task_failed',
      }).toString(),
      maxRedirects: 0,
    });
    expect(saveRes.status()).toBe(302);

    const data = JSON.parse(await fs.readFile(notifyFile, 'utf8'));
    const found = Object.values(data.channels ?? {}).some(
      (c) => (c as { name?: string }).name === channelName
    );
    expect(found).toBe(true);
  } finally {
    await fs.writeFile(notifyFile, JSON.stringify({ channels: {} }, null, 2), 'utf8').catch(() => undefined);
  }

  await tracker.assertNoClientErrors();
});
