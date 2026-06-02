import { test, expect } from '@playwright/test';
import { attachClientErrorTracking, loginAsDevAdmin, submitVisibleModal } from '../../helpers/auth';
import { restoreLocalFiles, snapshotLocalFiles } from '../../helpers/cli';
import fs from 'fs/promises';
import path from 'path';

test('site credentials are saved in plaintext and included in config export', async ({ page }, testInfo) => {
  const snapshot = await snapshotLocalFiles([
    path.resolve(__dirname, '../../../data/sites.json'),
  ]);
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
    ],
    ignoredFailedRequests: [
      /GET .*\/admin\/backups\.php\?export=1 :: net::ERR_ABORTED/,
    ],
  });
  const ts = Date.now();
  const groupId = `cred-group-${ts}`;
  const siteId = `cred-site-${ts}`;
  const exportPath = testInfo.outputPath(`credentials-${ts}.json`);

  try {
    await loginAsDevAdmin(page);

    await page.goto('/admin/groups.php');
    await page.getByRole('button', { name: /添加分组/ }).click();
    await page.locator('#fi_id').fill(groupId);
    await page.locator('#fi_name').fill(`凭据分组 ${ts}`);
    await page.locator('#fi_auth').selectOption('0');
    await submitVisibleModal(page);

    await page.goto('/admin/sites.php');
    await page.getByRole('button', { name: /添加站点/ }).click();
    await page.locator('#fi_sid').fill(siteId);
    await page.locator('#fi_name').fill('带凭据站点');
    await page.locator('#fi_gid').selectOption(groupId);
    await page.locator('#fi_type').selectOption('external');
    await page.locator('#fi_url').fill('https://example.com');
    await page.locator('#fi_credential_username').fill('plain-user');
    await page.locator('#fi_credential_password').fill('plain-pass-123');
    await page.locator('#fi_credential_note').fill('明文测试凭据');
    await submitVisibleModal(page);

    const row = page.locator(`tr[data-sid="${siteId}"]`).first();
    await expect(row).toBeVisible();
    await expect(row).toContainText('凭据');

    await row.getByRole('button', { name: /编辑/ }).click();
    await expect(page.locator('#modal')).toBeVisible();
    await expect(page.locator('#fi_credential_username')).toHaveValue('plain-user');
    await expect(page.locator('#fi_credential_password')).toHaveValue('plain-pass-123');
    await expect(page.locator('#fi_credential_note')).toHaveValue('明文测试凭据');
    await page.getByRole('button', { name: /取消/ }).click();

    await page.goto('/admin/backups.php');
    const downloadPromise = page.waitForEvent('download');
    await page.getByRole('link', { name: /导出配置/ }).click();
    const download = await downloadPromise;
    await download.saveAs(exportPath);

    const raw = await fs.readFile(exportPath, 'utf8');
    const data = JSON.parse(raw);
    const found = data.sites.groups
      .flatMap((group: any) => group.sites || [])
      .find((site: any) => site.id === siteId);
    expect(found).toMatchObject({
      credential_username: 'plain-user',
      credential_password: 'plain-pass-123',
      credential_note: '明文测试凭据',
    });

    await tracker.assertNoClientErrors();
  } finally {
    await restoreLocalFiles(snapshot);
  }
});
