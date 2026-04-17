import fs from 'fs/promises';
import path from 'path';
import { test, expect } from '../../helpers/fixtures';
import { attachClientErrorTracking, loginAsDevAdmin } from '../../helpers/auth';
import { runDockerPhpInline } from '../../helpers/cli';

const configPath = path.resolve(__dirname, '../../../data/config.json');
const accountsPath = path.resolve(__dirname, '../../../data/webdav_accounts.json');

test('webdav direct save actions for account and service work via post', async ({ page }) => {
  test.setTimeout(120000);
  const tracker = await attachClientErrorTracking(page);
  const ts = Date.now();
  const username = `directsave_${ts}`;

  // ensure webdav enabled
  runDockerPhpInline(
    [
      '$cfgPath = "/var/www/nav/data/config.json";',
      '$cfg = file_exists($cfgPath) ? (json_decode((string)file_get_contents($cfgPath), true) ?: []) : [];',
      '$cfg["webdav_enabled"] = "1";',
      'file_put_contents($cfgPath, json_encode($cfg, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));',
    ].join(' ')
  );

  // clear accounts
  await fs.writeFile(accountsPath, JSON.stringify({ accounts: [] }, null, 2), 'utf8').catch(() => undefined);

  try {
    await loginAsDevAdmin(page);
    await page.goto('/admin/webdav.php');
    const csrf = await page.locator('input[name="_csrf"]').first().inputValue();

    // save_webdav_service toggle
    const svcRes = await page.request.post('http://127.0.0.1:58080/admin/webdav.php', {
      form: { action: 'save_webdav_service', _csrf: csrf, enabled: '1' },
      maxRedirects: 0,
    });
    expect(svcRes.status()).toBe(302);
    const cfg = JSON.parse(await fs.readFile(configPath, 'utf8'));
    expect(cfg.webdav_enabled).toBe('1');

    // save_webdav_account
    const accRes = await page.request.post('http://127.0.0.1:58080/admin/webdav.php', {
      form: {
        action: 'save_webdav_account',
        _csrf: csrf,
        id: '',
        username,
        password: 'DirectSave@2026',
        root: '/var/www/nav/data',
        notes: 'direct-save-test',
        max_upload_mb: '10',
        quota_mb: '100',
        ip_whitelist: '',
        readonly: '0',
        account_enabled: '1',
      },
      maxRedirects: 0,
    });
    expect(accRes.status()).toBe(302);

    const accData = JSON.parse(await fs.readFile(accountsPath, 'utf8'));
    const found = (accData.accounts ?? []).some(
      (a: { username?: string }) => a.username === username
    );
    expect(found).toBe(true);
  } finally {
    await fs.writeFile(accountsPath, JSON.stringify({ accounts: [] }, null, 2), 'utf8').catch(() => undefined);
  }

  await tracker.assertNoClientErrors();
});
