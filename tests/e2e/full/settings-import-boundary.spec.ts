import fs from 'fs/promises';
import path from 'path';
import { test, expect } from '../../helpers/fixtures';
import { attachClientErrorTracking, loginAsDevAdmin } from '../../helpers/auth';

test('settings import rejects oversized files larger than 4MB', async ({ page }, testInfo) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
    ],
    ignoredFailedRequests: [/POST .*\/admin\/settings\.php :: net::ERR_ABORTED/],
  });

  const oversizedFile = testInfo.outputPath('oversized-import.json');
  // Create a file slightly larger than 4MB
  const padding = 'x'.repeat(5 * 1024 * 1024);
  await fs.writeFile(oversizedFile, `{"padding":"${padding}"}`, 'utf8');

  await loginAsDevAdmin(page);
  await page.goto('/admin/settings.php');

  await page.locator('#importFile').setInputFiles(oversizedFile);
  await expect(page.locator('body')).toContainText(/文件过大|不应超过 4MB/);

  await tracker.assertNoClientErrors();
});
