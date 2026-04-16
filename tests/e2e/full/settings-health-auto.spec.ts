import fs from 'fs/promises';
import path from 'path';
import { test, expect } from '../../helpers/fixtures';
import { attachClientErrorTracking, loginAsDevAdmin } from '../../helpers/auth';

const configPath = path.resolve(__dirname, '../../../data/config.json');

test('settings health auto panel saves interval and enabled state', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page);
  let originalConfig = '{}';
  try {
    originalConfig = await fs.readFile(configPath, 'utf8');
  } catch {}

  try {
    await loginAsDevAdmin(page);
    await page.goto('/admin/settings.php#health');

    const csrf = await page.locator('input[name="_csrf"]').first().inputValue();
    const saveRes = await page.request.post('http://127.0.0.1:58080/admin/settings.php', {
      form: {
        action: 'save_health_auto',
        _csrf: csrf,
        health_auto_enabled: '1',
        health_auto_interval: '10',
      },
      maxRedirects: 0,
    });
    expect(saveRes.status()).toBe(302);
    expect(saveRes.headers()['location'] || '').toContain('settings.php#health');

    const cfg = JSON.parse(await fs.readFile(configPath, 'utf8'));
    expect(cfg.health_auto_enabled).toBe('1');
    expect(cfg.health_auto_interval).toBe(10);

    const saveRes2 = await page.request.post('http://127.0.0.1:58080/admin/settings.php', {
      form: {
        action: 'save_health_auto',
        _csrf: csrf,
        health_auto_enabled: '0',
        health_auto_interval: '5',
      },
      maxRedirects: 0,
    });
    expect(saveRes2.status()).toBe(302);

    const cfg2 = JSON.parse(await fs.readFile(configPath, 'utf8'));
    expect(cfg2.health_auto_enabled).toBe('0');
  } finally {
    await fs.writeFile(configPath, originalConfig, 'utf8').catch(() => undefined);
  }

  await tracker.assertNoClientErrors();
});
