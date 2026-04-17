import fs from 'fs/promises';
import path from 'path';
import { test, expect } from '../../helpers/fixtures';
import { attachClientErrorTracking, loginAsDevAdmin } from '../../helpers/auth';

const configPath = path.resolve(__dirname, '../../../data/config.json');
const apiTokensPath = path.resolve(__dirname, '../../../data/api_tokens.json');

test('settings direct actions cover api token nginx apply backup and proxy params', async ({ page }) => {
  test.setTimeout(120000);
  const tracker = await attachClientErrorTracking(page);
  const ts = Date.now();

  let originalTokens = '{}';
  try {
    originalTokens = await fs.readFile(apiTokensPath, 'utf8');
  } catch {}

  await loginAsDevAdmin(page);
  await page.goto('/admin/settings.php');
  const csrf = await page.locator('input[name="_csrf"]').first().inputValue();

  // generate_api_token
  const genRes = await page.request.post('http://127.0.0.1:58080/admin/settings.php', {
    form: { action: 'generate_api_token', _csrf: csrf, token_name: `DirectToken${ts}` },
    maxRedirects: 0,
  });
  expect(genRes.status()).toBe(302);
  expect(genRes.headers()['location'] || '').toContain('#api-tokens');

  const tokensAfterGen = JSON.parse(await fs.readFile(apiTokensPath, 'utf8'));
  const generatedEntry = Object.entries(tokensAfterGen).find(([, v]) => (v as { name?: string }).name === `DirectToken${ts}`);
  expect(generatedEntry).toBeTruthy();
  const tokenValue = generatedEntry![0];

  // delete_api_token
  const delRes = await page.request.post('http://127.0.0.1:58080/admin/settings.php', {
    form: { action: 'delete_api_token', _csrf: csrf, token: tokenValue },
    maxRedirects: 0,
  });
  expect(delRes.status()).toBe(302);

  const tokensAfterDel = JSON.parse(await fs.readFile(apiTokensPath, 'utf8'));
  expect(tokensAfterDel[tokenValue]).toBeUndefined();

  // manual_backup
  const backupRes = await page.request.post('http://127.0.0.1:58080/admin/settings.php', {
    form: { action: 'manual_backup', _csrf: csrf },
    maxRedirects: 0,
  });
  expect(backupRes.status()).toBe(302);

  // nginx_apply_and_reload (or nginx_apply)
  const nginxRes = await page.request.post('http://127.0.0.1:58080/admin/settings.php', {
    form: { action: 'nginx_apply_and_reload', _csrf: csrf },
    maxRedirects: 0,
  });
  expect(nginxRes.status()).toBe(302);

  // save_proxy_params_mode
  const ppmRes = await page.request.post('http://127.0.0.1:58080/admin/settings.php', {
    form: { action: 'save_proxy_params_mode', _csrf: csrf, proxy_params_mode: 'simple' },
    maxRedirects: 0,
  });
  expect(ppmRes.status()).toBe(302);
  const cfg = JSON.parse(await fs.readFile(configPath, 'utf8'));
  expect(cfg.proxy_params_mode).toBe('simple');

  // save_webhook
  const whRes = await page.request.post('http://127.0.0.1:58080/admin/settings.php', {
    form: {
      action: 'save_webhook',
      _csrf: csrf,
      webhook_enabled: '1',
      webhook_type: 'custom',
      webhook_url: `http://127.0.0.1:9/webhook-${ts}`,
      webhook_tg_chat: '',
      'webhook_events[]': 'FAIL',
    },
    maxRedirects: 0,
  });
  expect(whRes.status()).toBe(302);
  const cfg2 = JSON.parse(await fs.readFile(configPath, 'utf8'));
  expect(cfg2.webhook_enabled).toBe('1');
  expect(cfg2.webhook_url).toBe(`http://127.0.0.1:9/webhook-${ts}`);

  // test_webhook (missing url should report error via flash redirect)
  const testWhRes = await page.request.post('http://127.0.0.1:58080/admin/settings.php', {
    form: { action: 'test_webhook', _csrf: csrf },
    maxRedirects: 0,
  });
  expect(testWhRes.status()).toBe(302);

  // export_sites (attachment download)
  const exportRes = await page.request.post('http://127.0.0.1:58080/admin/settings.php', {
    form: { action: 'export_sites', _csrf: csrf },
  });
  expect(exportRes.status()).toBe(200);
  const cd = exportRes.headers()['content-disposition'] || '';
  expect(cd).toContain('attachment');
  expect(cd).toMatch(/nav_export_.*\.json/);

  await tracker.assertNoClientErrors();
});
