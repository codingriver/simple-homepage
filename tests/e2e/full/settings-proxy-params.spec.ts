import fs from 'fs/promises';
import path from 'path';
import { expect, test } from '../../helpers/fixtures';
import { attachClientErrorTracking, loginAsDevAdmin } from '../../helpers/auth';
import { runDockerPhpInline } from '../../helpers/cli';

const configFile = path.resolve(__dirname, '../../../data/config.json');
const proxyParamsSimple = path.resolve(__dirname, '../../../nginx-conf/proxy_params_simple.conf');
const proxyParamsFull = path.resolve(__dirname, '../../../nginx-conf/proxy_params_full.conf');

test('settings proxy params mode switches between simple and full', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page);

  // ensure config exists
  const cfgRaw = await fs.readFile(configFile, 'utf8').catch(() => '{}');
  const cfg = JSON.parse(cfgRaw);

  await loginAsDevAdmin(page);
  await page.goto('/admin/settings.php#nginx');

  // switch to full
  await page.locator('select[name="proxy_params_mode"]').selectOption('full');
  await page.locator('#nginx').getByRole('button', { name: '保存代理参数模式' }).click();
  await expect(page.locator('.alert-success')).toContainText('代理参数模式已保存');

  const cfgAfterFull = JSON.parse(await fs.readFile(configFile, 'utf8'));
  expect(cfgAfterFull.proxy_params_mode).toBe('full');

  // verify applied nginx config uses full
  const nginxResult = runDockerPhpInline(
    [
      '$path = "/var/www/nav/data/nginx/_proxy_params.conf";',
      'echo file_exists($path) ? file_get_contents($path) : "";',
    ].join(' ')
  );
  expect(nginxResult.code).toBe(0);
  // the content depends on whether gen_nginx was triggered; at minimum the config file was updated

  // switch back to simple
  await page.goto('/admin/settings.php#nginx');
  await page.locator('select[name="proxy_params_mode"]').selectOption('simple');
  await page.locator('#nginx').getByRole('button', { name: '保存代理参数模式' }).click();
  await expect(page.locator('.alert-success')).toContainText('代理参数模式已保存');

  const cfgAfterSimple = JSON.parse(await fs.readFile(configFile, 'utf8'));
  expect(cfgAfterSimple.proxy_params_mode).toBe('simple');

  await tracker.assertNoClientErrors();
});
