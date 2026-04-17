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

    // use page.evaluate to perform a real multipart upload via fetch
    const result = await page.evaluate(
      async ({ csrfToken, jsonContent }) => {
        const blob = new Blob([jsonContent], { type: 'application/json' });
        const form = new FormData();
        form.append('_csrf', csrfToken);
        form.append('action', 'import_sites');
        form.append('import_file', blob, 'import.json');
        const res = await fetch('/admin/settings.php', {
          method: 'POST',
          body: form,
          credentials: 'include',
          redirect: 'manual',
        });
        return { status: res.status, location: res.headers.get('location') || '' };
      },
      { csrfToken: csrf, jsonContent: JSON.stringify(payload) }
    );

    expect(result.status).toBe(302);

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
