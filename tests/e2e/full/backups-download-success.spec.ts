import fs from 'fs/promises';
import path from 'path';
import { expect, test } from '../../helpers/fixtures';
import { attachClientErrorTracking, loginAsDevAdmin } from '../../helpers/auth';

const backupsDir = path.resolve(__dirname, '../../../data/backups');

test('admin can download a valid backup file', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page);
  const ts = Date.now();
  const filename = `backup_test_download_${ts}.json`;
  const content = JSON.stringify({ test: true, ts }, null, 2);

  await fs.mkdir(backupsDir, { recursive: true });
  await fs.writeFile(path.join(backupsDir, filename), content, 'utf8');

  await loginAsDevAdmin(page);

  const downloadRes = await page.request.get(
    `http://127.0.0.1:58080/admin/backups.php?download=${encodeURIComponent(filename)}`
  );
  expect(downloadRes.status()).toBe(200);
  const disp = downloadRes.headers()['content-disposition'] || '';
  expect(disp).toContain('attachment');
  const body = await downloadRes.text();
  expect(body).toContain(`"test": true`);
  expect(body).toContain(`${ts}`);

  await tracker.assertNoClientErrors();
});
