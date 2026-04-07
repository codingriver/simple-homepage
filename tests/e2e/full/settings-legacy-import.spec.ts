import fs from 'fs';
import { test, expect } from '@playwright/test';
import { attachClientErrorTracking, loginAsDevAdmin } from '../../helpers/auth';

test('settings import supports legacy groups-only payload and restores group list', async ({ page }, testInfo) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
    ],
  });
  const ts = Date.now();
  const legacyFile = testInfo.outputPath(`legacy-sites-${ts}.json`);

  const payload = {
    groups: [
      {
        id: `legacy-group-${ts}`,
        name: `旧格式分组 ${ts}`,
        icon: '🧪',
        visible_to: 'all',
        auth_required: false,
        order: 0,
        sites: [
          {
            id: `legacy-site-${ts}`,
            name: `旧格式站点 ${ts}`,
            type: 'external',
            url: 'https://example.com/legacy',
            icon: '🔗',
            desc: 'legacy import',
            order: 0,
          },
        ],
      },
    ],
  };
  await page.context().storageState();
  fs.writeFileSync(legacyFile, JSON.stringify(payload, null, 2), 'utf8');

  await loginAsDevAdmin(page);
  await page.goto('/admin/settings.php');
  page.once('dialog', (dialog) => dialog.accept());
  await page.locator('#importFile').setInputFiles(legacyFile);
  await expect(page.locator('body')).toContainText(/导入成功（站点格式）|导入成功/);

  await page.goto('/admin/groups.php');
  await expect(page.locator('body')).toContainText(`旧格式分组 ${ts}`);
  await page.goto('/index.php');
  await expect(page.locator('body')).toContainText(`旧格式站点 ${ts}`);

  await tracker.assertNoClientErrors();
});
