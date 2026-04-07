import { test, expect } from '@playwright/test';
import { attachClientErrorTracking, loginAsDevAdmin } from '../../helpers/auth';

test('large dataset ui remains usable with many groups and sites', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
    ],
    ignoredFailedRequests: [/favicon\.php/],
  });
  const ts = Date.now();
  const groups = Array.from({ length: 8 }, (_, i) => ({ id: `large-group-${ts}-${i}`, name: `大数据分组 ${i} ${ts}` }));

  await loginAsDevAdmin(page);
  await page.goto('/admin/groups.php');
  const groupCsrf = await page.locator('input[name="_csrf"]').first().inputValue();
  for (const [index, group] of groups.entries()) {
    const response = await page.request.post('http://127.0.0.1:58080/admin/groups.php', {
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      form: {
        _csrf: groupCsrf,
        action: 'save',
        old_id: '',
        gid: group.id,
        name: group.name,
        icon: '📁',
        order: String(index),
        visible_to: 'all',
        auth_required: '0',
      },
    });
    expect(response.ok()).toBeTruthy();
  }

  await page.goto('/admin/sites.php');
  const siteCsrf = await page.locator('input[name="_csrf"]').first().inputValue();
  let siteCounter = 0;
  for (const group of groups) {
    for (let i = 0; i < 5; i++) {
      const response = await page.request.post('http://127.0.0.1:58080/admin/sites.php', {
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        form: {
          _csrf: siteCsrf,
          action: 'save',
          old_gid: '',
          old_sid: '',
          gid: group.id,
          sid: `large-site-${ts}-${siteCounter}`,
          name: `大数据站点 ${siteCounter} ${ts}`,
          icon: '🔗',
          desc: '',
          order: String(siteCounter),
          type: 'external',
          url: `https://example.com/${ts}/${siteCounter}`,
        },
      });
      expect(response.ok()).toBeTruthy();
      siteCounter++;
    }
  }

  await page.goto('/index.php');
  await expect(page.locator('a.card').filter({ hasText: new RegExp(`大数据站点 .* ${ts}`) })).toHaveCount(siteCounter);
  await page.locator('#searchToggle').click();
  await page.locator('#sq').fill(`大数据站点 1 ${ts}`);
  await expect(page.locator('#searchMeta')).toContainText('找到');
  await expect(page.locator('.nav-bar .na').first()).toBeVisible();

  await tracker.assertNoClientErrors();
});
