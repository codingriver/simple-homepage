import { test, expect } from '@playwright/test';
import { attachClientErrorTracking, loginAsDevAdmin } from '../../helpers/auth';

async function openDdns(page: Parameters<typeof loginAsDevAdmin>[0]) {
  await loginAsDevAdmin(page);
  await page.goto('/admin/ddns.php');
  await page.getByRole('button', { name: /新建任务/ }).click();
}

test('ddns save validates required fields and invalid cron or domain', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [/Failed to load resource: the server responded with a status of 400 \(Bad Request\)/],
  });

  await openDdns(page);
  await page.getByRole('button', { name: /^保存$/ }).click();
  await expect(page.locator('#fm-test-result')).toContainText(/请填写任务名称|请填写目标域名|保存失败/);

  await page.locator('#fm-name').fill('非法域名任务');
  await page.locator('#fm-domain').fill('bad domain');
  await page.getByRole('button', { name: /^保存$/ }).click();
  await expect(page.locator('#fm-test-result')).toContainText(/目标域名格式不正确|保存失败/);

  await page.locator('#fm-domain').fill('valid-domain.606077.xyz');
  await page.locator('#fm-cron').fill('* * *');
  await page.getByRole('button', { name: /^保存$/ }).click();
  await expect(page.locator('#fm-test-result')).toContainText(/Cron 表达式无效|保存失败/);

  await tracker.assertNoClientErrors();
});

test('ddns source toggle updates line fallback and hint visibility correctly', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [/Failed to load resource: the server responded with a status of 400 \(Bad Request\)/],
  });

  await openDdns(page);

  await page.locator('#fm-source-type').selectOption('local_ipv4');
  await expect(page.locator('.line-only').first()).toBeHidden();
  await expect(page.locator('#fm-source-hint')).toContainText('公网 IPv4');

  await page.locator('#fm-source-type').selectOption('api4ce_cfip');
  await expect(page.locator('.line-only').first()).toBeVisible();
  await expect(page.locator('#fm-source-hint')).toContainText('4ce');

  await page.locator('#fm-source-type').selectOption('cf164746_global');
  await expect(page.locator('.line-only').first()).toBeHidden();
  await expect(page.locator('#fm-source-hint')).toContainText('164746');

  await page.locator('#fm-source-type').selectOption('uouin_cfip');
  const selfOption = page.locator('#fm-fallback-type option[value="uouin_cfip"]');
  await expect(selfOption).toBeDisabled();
  await page.locator('#fm-fallback-type').selectOption('cf164746_global');
  await expect(page.locator('#fm-fallback-type')).toHaveValue('cf164746_global');

  await tracker.assertNoClientErrors();
});
