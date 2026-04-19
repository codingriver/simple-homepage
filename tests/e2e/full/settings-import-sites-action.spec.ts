import fs from 'fs/promises';
import path from 'path';
import { test, expect } from '../../helpers/fixtures';
import { attachClientErrorTracking, loginAsDevAdmin } from '../../helpers/auth';

const sitesPath = path.resolve(__dirname, '../../../data/sites.json');

test('settings import_sites action imports json via direct multipart post', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page);
  const ts = Date.now();

  const originalSites = await fs.readFile(sitesPath, 'utf8').catch(() => '{"groups":[]}');

  const payload = {
    groups: [
      {
        id: `import-group-${ts}`,
        name: `导入分组 ${ts}`,
        icon: '📦',
        visible_to: 'all',
        auth_required: false,
        order: 0,
        sites: [
          {
            id: `import-site-${ts}`,
            name: '导入站点',
            type: 'external',
            url: 'https://example.com',
            icon: '🌐',
            order: 0,
          },
        ],
      },
    ],
  };

  try {
    await loginAsDevAdmin(page);
    await page.goto('/admin/settings.php');
    const csrf = await page.locator('input[name="_csrf"]').first().inputValue();

    // use page.request.post with multipart to avoid browser fetch redirect:manual returning status 0
    const importRes = await page.request.post('/admin/settings.php', {
      multipart: {
        _csrf: csrf,
        action: 'import_sites',
        import_file: {
          name: 'import.json',
          mimeType: 'application/json',
          buffer: Buffer.from(JSON.stringify(payload)),
        },
      },
      maxRedirects: 0,
    });

    expect(importRes.status()).toBe(302);

    const sitesAfter = JSON.parse(await fs.readFile(sitesPath, 'utf8'));
    const foundGroup = (sitesAfter.groups ?? []).some(
      (g: { id?: string }) => g.id === `import-group-${ts}`
    );
    expect(foundGroup).toBe(true);
  } finally {
    await fs.writeFile(sitesPath, originalSites, 'utf8');
  }

  await tracker.assertNoClientErrors();
});
