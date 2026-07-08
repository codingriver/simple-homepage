import { test, expect } from '../../helpers/fixtures';
import { attachClientErrorTracking, loginAsDevAdmin } from '../../helpers/auth';
import fs from 'fs/promises';
import path from 'path';
import { spawnSync } from 'child_process';

test('domain expiry platform credential modal supports provider-specific fields', async ({ page }) => {
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
    ],
  });
  const usersPath = path.resolve(__dirname, '../../../data/users.json');
  const domainExpiryPath = path.resolve(__dirname, '../../../data/domain_expiry.json');
  const originalUsers = await fs.readFile(usersPath, 'utf8').catch(() => '{}');
  const originalDomainExpiry = await fs.readFile(domainExpiryPath, 'utf8').catch(() => '');

  try {
    const hashResult = spawnSync('php', ['-r', 'echo password_hash($argv[1], PASSWORD_BCRYPT);', 'qatest2026'], {
      encoding: 'utf8',
      cwd: path.resolve(__dirname, '../../..'),
    });
    expect(hashResult.status, `${hashResult.stdout}${hashResult.stderr}`).toBe(0);
    const passwordHash = `${hashResult.stdout}${hashResult.stderr}`.match(/\$2y\$\d{2}\$[./A-Za-z0-9]{53}/)?.[0];
    expect(passwordHash, `${hashResult.stdout}${hashResult.stderr}`).toBeTruthy();
    const users = originalUsers.trim() ? JSON.parse(originalUsers) : {};
    users.qatest = {
      password_hash: passwordHash,
      role: 'admin',
      permissions: ['*'],
      max_sessions: 3,
      created_at: '2026-07-07 00:00:00',
      updated_at: '2026-07-07 00:00:00',
    };
    await fs.writeFile(usersPath, JSON.stringify(users, null, 2));
    await page.waitForTimeout(300);

    await loginAsDevAdmin(page);
    await page.getByRole('link', { name: /域名有效期/ }).first().click();
    await expect(page).toHaveURL(/domain_expiry\.php/);

    await page.getByRole('button', { name: '官方平台秘钥' }).click();
    const modal = page.locator('#platform-config-modal');
    await expect(modal).toBeVisible();
    await expect(modal).toContainText('官方平台秘钥配置');
    await expect(modal).toContainText('DigitalPlat');
    await expect(modal).toContainText('DNSHE');
    await expect(modal.locator('label', { hasText: 'Bearer Token' })).toBeVisible();
    await expect(modal.locator('label', { hasText: 'API Key' })).toBeVisible();
    await expect(modal.locator('label', { hasText: 'API Secret' })).toBeVisible();
    await expect(modal.locator('input[data-field="token"]').first()).toHaveAttribute('type', 'text');

    await modal.locator('input[data-field="token"]').first().fill('dp_live_visible_plaintext_test');
    await modal.getByRole('button', { name: '保存' }).click();
    await expect(modal.locator('input[data-field="token"]').first()).toHaveValue('dp_live_visible_plaintext_test');

    const rows = modal.locator('.platform-config-row');
    const rowCountBeforeAdd = await rows.count();
    await modal.getByRole('button', { name: '添加一行' }).click();
    await expect(rows).toHaveCount(rowCountBeforeAdd + 1);
    const lastRow = rows.last();
    await lastRow.locator('select.platform-provider').selectOption('dnshe');
    await expect(lastRow.locator('label', { hasText: 'API Key' })).toBeVisible();
    await expect(lastRow.locator('label', { hasText: 'API Secret' })).toBeVisible();
    await expect(lastRow.locator('input[data-field="api_key"]')).toBeVisible();
    await expect(lastRow.locator('input[data-field="api_secret"]')).toBeVisible();

    await page.keyboard.press('Escape');
    await expect(modal).toBeHidden();

    await tracker.assertNoClientErrors();
  } finally {
    await fs.writeFile(usersPath, originalUsers);
    if (originalDomainExpiry === '') {
      await fs.rm(domainExpiryPath, { force: true });
    } else {
      await fs.writeFile(domainExpiryPath, originalDomainExpiry);
    }
  }
});
