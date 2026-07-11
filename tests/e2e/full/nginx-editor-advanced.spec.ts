import fs from 'fs/promises';
import path from 'path';
import { test, expect } from '../../helpers/fixtures';
import { attachClientErrorTracking, loginAsDevAdmin } from '../../helpers/auth';

const nginxMainPath = path.resolve(__dirname, '../../../data/nginx/nginx.conf');

test('runtime config viewer keeps files unchanged', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
      /Failed to load resource: the server responded with a status of 404 \(Not Found\)/,
    ],
  });
  const originalContent = await fs.readFile(nginxMainPath, 'utf8');

  await loginAsDevAdmin(page);
  await page.goto('/admin/nginx.php');

  await page.locator('[data-view-target="main"]').click();
  await expect(page.locator('#nav-ace-editor-modal')).toHaveClass(/open/);

  await expect(page.locator('#nav-ace-toolbar-actions button[data-action="save"]')).toHaveCount(0);
  await expect(page.locator('#nav-ace-toolbar-actions button[data-action="save_reload"]')).toHaveCount(0);
  await expect
    .poll(async () => fs.readFile(nginxMainPath, 'utf8'))
    .toBe(originalContent);

  await tracker.assertNoClientErrors();
});
