import fs from 'fs/promises';
import path from 'path';
import { expect, test } from '@playwright/test';
import { attachClientErrorTracking, loginAsDevAdmin } from '../../helpers/auth';
import { runDockerCommand, runDockerPhpInline } from '../../helpers/cli';

const hostAgentStatePath = path.resolve(__dirname, '../../../data/host_agent.json');
const simulateRootPath = path.resolve(__dirname, '../../../data/host-agent-sim-root');
const hostAgentContainer = process.env.APP_CONTAINER ? `${process.env.APP_CONTAINER}-host-agent` : 'simple-homepage-host-agent';

async function cleanupHostAgent() {
  runDockerCommand(['rm', '-f', hostAgentContainer]);
  await fs.rm(hostAgentStatePath, { force: true }).catch(() => undefined);
  await fs.rm(simulateRootPath, { recursive: true, force: true }).catch(() => undefined);
}

async function ensureInstalledHostAgent() {
  const result = runDockerPhpInline(
    [
      'require "/var/www/nav/admin/shared/host_agent_lib.php";',
      '$result = host_agent_install();',
      'echo json_encode($result, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);',
    ].join(' ')
  );
  expect(result.code).toBe(0);
  expect(JSON.parse(result.stdout).ok).toBe(true);
}

test.beforeEach(async () => {
  await cleanupHostAgent();
});

test.afterEach(async () => {
  await cleanupHostAgent();
});

test('files page supports independent file management workflow', async ({ page }) => {
  test.setTimeout(180000);
  await ensureInstalledHostAgent();
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
    ],
  });

  const ts = Date.now();
  const dirPath = `/files-${ts}`;
  const filePath = `${dirPath}/hello.txt`;
  const archivePath = `${dirPath}.tar.gz`;
  const extractDir = `/files-extract-${ts}`;

  await loginAsDevAdmin(page);
  await page.goto('/admin/files.php');

  await expect(page.locator('body')).toContainText('文件系统');
  await expect(page.locator('#fm-host-select')).toHaveValue('local');

  page.once('dialog', (dialog) => dialog.accept(dirPath));
  await page.getByRole('button', { name: '新建目录' }).click();
  await expect
    .poll(() => {
      const result = runDockerPhpInline(
        ['$path = "/var/www/nav/data/host-agent-sim-root' + dirPath + '";', 'echo is_dir($path) ? "1" : "0";'].join(' ')
      );
      expect(result.code).toBe(0);
      return result.stdout.trim();
    })
    .toBe('1');

  page.once('dialog', (dialog) => dialog.accept(filePath));
  await page.getByRole('button', { name: '新建文件' }).click();
  await expect(page.locator('#fm-edit-path')).toHaveValue(filePath);
  await expect(page.locator('#fm-editor-meta')).toContainText('文本文件已读取');
  await page.locator('#fm-editor').fill(`hello-files-${ts}`);
  await page.getByRole('button', { name: '保存文件' }).click({ force: true });
  await expect
    .poll(() => {
      const result = runDockerPhpInline(
        ['$path = "/var/www/nav/data/host-agent-sim-root' + filePath + '";', 'echo file_exists($path) ? file_get_contents($path) : "";'].join(' ')
      );
      expect(result.code).toBe(0);
      return result.stdout;
    }, { timeout: 15000 })
    .toContain(`hello-files-${ts}`);

  await page.locator('#fm-path').fill(dirPath);
  await page.locator('#fm-path').press('Enter');
  page.once('dialog', (dialog) => dialog.accept('测试收藏目录'));
  await page.getByRole('button', { name: '收藏当前目录' }).click();
  await expect(page.locator('#fm-favorites')).toContainText('测试收藏目录');

  await page.locator('#fm-edit-path').fill(filePath);
  await page.getByRole('button', { name: '重新读取' }).click({ force: true });
  page.once('dialog', (dialog) => dialog.accept('600'));
  await page.getByRole('button', { name: 'chmod' }).click();
  await expect(page.locator('#fm-stat-meta')).toContainText('权限 0600');

  page.once('dialog', (dialog) => dialog.accept(archivePath));
  await page.getByRole('button', { name: '压缩' }).click({ force: true });
  await expect
    .poll(() => {
      const result = runDockerPhpInline(
        ['$path = "/var/www/nav/data/host-agent-sim-root' + archivePath + '";', 'echo file_exists($path) ? "1" : "0";'].join(' ')
      );
      expect(result.code).toBe(0);
      return result.stdout.trim();
    })
    .toBe('1');

  await page.locator('#fm-edit-path').fill(archivePath);
  page.once('dialog', (dialog) => dialog.accept(extractDir));
  await page.getByRole('button', { name: '解压' }).click({ force: true });
  await expect
    .poll(() => {
      const result = runDockerPhpInline(
        ['$path = "/var/www/nav/data/host-agent-sim-root' + extractDir + '";', 'echo is_dir($path) ? "1" : "0";'].join(' ')
      );
      expect(result.code).toBe(0);
      return result.stdout.trim();
    }, { timeout: 15000 })
    .toBe('1');

  await tracker.assertNoClientErrors();
});

test('files page advanced workflow covers rename clipboard batch search preview and audit page', async ({ page }) => {
  test.setTimeout(180000);
  await ensureInstalledHostAgent();
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
    ],
  });

  const ts = Date.now();
  const baseDir = `/adv-files-${ts}`;
  const fileA = `${baseDir}/a.txt`;
  const fileB = `${baseDir}/b.json`;
  const renamedA = `${baseDir}/renamed-a.txt`;
  const pastedB = `${baseDir}/copy-b.json`;
  const imagePath = `${baseDir}/preview.png`;
  const imageBuffer = Buffer.from(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wn9C7QAAAAASUVORK5CYII=',
    'base64'
  );

  await loginAsDevAdmin(page);
  await page.goto('/admin/files.php');

  page.once('dialog', (dialog) => dialog.accept(baseDir));
  await page.getByRole('button', { name: '新建目录' }).click();

  page.once('dialog', (dialog) => dialog.accept(fileA));
  await page.getByRole('button', { name: '新建文件' }).click();
  await expect(page.locator('#fm-edit-path')).toHaveValue(fileA);
  await expect(page.locator('#fm-editor-meta')).toContainText('文本文件已读取');
  await page.locator('#fm-editor').fill('aaa');
  await page.getByRole('button', { name: '保存文件' }).click({ force: true });

  page.once('dialog', (dialog) => dialog.accept(fileB));
  await page.getByRole('button', { name: '新建文件' }).click();
  await expect(page.locator('#fm-edit-path')).toHaveValue(fileB);
  await expect(page.locator('#fm-editor-meta')).toContainText('文本文件已读取');
  await page.locator('#fm-editor').fill('{"name":"demo","enabled":true}');
  await page.getByRole('button', { name: '保存文件' }).click({ force: true });
  await expect
    .poll(() => {
      const result = runDockerPhpInline(
        ['$path = "/var/www/nav/data/host-agent-sim-root' + fileB + '";', 'echo file_exists($path) ? file_get_contents($path) : "";'].join(' ')
      );
      expect(result.code).toBe(0);
      return result.stdout;
    })
    .toContain('"enabled":true');

  const imageSeed = runDockerPhpInline(
    [
      '$path = "/var/www/nav/data/host-agent-sim-root' + imagePath + '";',
      '$dir = dirname($path);',
      'if (!is_dir($dir)) { mkdir($dir, 0777, true); }',
      'file_put_contents($path, base64_decode("' + imageBuffer.toString('base64') + '", true));',
    ].join(' ')
  );
  expect(imageSeed.code).toBe(0);
  await expect
    .poll(() => {
      const result = runDockerPhpInline(
        ['$path = "/var/www/nav/data/host-agent-sim-root' + imagePath + '";', 'echo file_exists($path) ? "1" : "0";'].join(' ')
      );
      expect(result.code).toBe(0);
      return result.stdout.trim();
    })
    .toBe('1');

  await page.locator('#fm-path').fill(baseDir);
  await page.locator('#fm-path').press('Enter');

  await page.locator('.fm-item-check[value="' + fileA + '"]').check();
  page.once('dialog', (dialog) => dialog.accept(renamedA));
  await page.locator('.card').filter({ has: page.locator('#fm-table') }).getByRole('button', { name: '重命名' }).first().click();
  await expect
    .poll(() => {
      const result = runDockerPhpInline(
        ['$path = "/var/www/nav/data/host-agent-sim-root' + renamedA + '";', 'echo file_exists($path) ? "1" : "0";'].join(' ')
      );
      expect(result.code).toBe(0);
      return result.stdout.trim();
    })
    .toBe('1');

  await page.locator('.fm-item-check[value="' + fileB + '"]').check();
  await page.locator('.card').filter({ has: page.locator('#fm-table') }).getByRole('button', { name: '复制' }).click();
  await page.locator('.card').filter({ has: page.locator('#fm-table') }).getByRole('button', { name: '粘贴' }).click({ force: true });
  await expect
    .poll(() => {
      const result = runDockerPhpInline(
        ['$path = "/var/www/nav/data/host-agent-sim-root' + pastedB + '";', 'echo file_exists($path) ? "1" : "0";'].join(' ')
      );
      expect(result.code).toBe(0);
      return result.stdout.trim();
    })
    .toBe('1');

  await page.locator('.fm-item-check[value="' + renamedA + '"]').check();
  await page.locator('.fm-item-check[value="' + pastedB + '"]').check();
  page.once('dialog', (dialog) => dialog.accept('640'));
  await page.locator('.card').filter({ has: page.locator('#fm-table') }).getByRole('button', { name: 'chmod' }).click();

  await page.locator('#fm-search').fill('preview');
  await page.locator('#fm-search').press('Enter');
  await expect(page.locator('#fm-table tbody')).toContainText('preview.png');

  await page.locator('#fm-edit-path').fill(fileB);
  await page.getByRole('button', { name: '重新读取' }).click({ force: true });
  await expect
    .poll(() => {
      const result = runDockerPhpInline(
        ['$path = "/var/www/nav/data/host-agent-sim-root' + fileB + '";', 'echo file_exists($path) ? file_get_contents($path) : "";'].join(' ')
      );
      expect(result.code).toBe(0);
      return result.stdout;
    })
    .toContain('"enabled":true');
  await expect(page.locator('#fm-preview-config')).toContainText('"enabled": true');

  await page.locator('#fm-edit-path').fill(imagePath);
  await page.getByRole('button', { name: '重新读取' }).click({ force: true });
  await expect(page.locator('#fm-preview-image')).toBeVisible();

  await page.goto('/admin/file_audit.php');
  await expect(page.locator('body')).toContainText('文件审计');
  await expect(page.locator('body')).toContainText('fs_rename');
  await expect(page.locator('body')).toContainText('fs_copy');

  const downloadPromise = page.waitForEvent('download');
  await page.getByRole('button', { name: '导出日志' }).click({ force: true });
  const download = await downloadPromise;
  const downloadPath = await download.path();
  expect(downloadPath).not.toBeNull();
  const exported = await fs.readFile(downloadPath!, 'utf8');
  expect(exported).toContain('fs_rename');
  expect(exported).toContain('fs_copy');

  await tracker.assertNoClientErrors();
});

test('files page can create webdav share from current local directory', async ({ page }) => {
  test.setTimeout(180000);
  await ensureInstalledHostAgent();

  const ts = Date.now();
  const baseDir = `/webdav-share-${ts}`;

  runDockerPhpInline(
    [
      '$cfgPath = "/var/www/nav/data/config.json";',
      '$cfg = file_exists($cfgPath) ? (json_decode((string)file_get_contents($cfgPath), true) ?: []) : [];',
      '$cfg["webdav_enabled"] = "1";',
      'file_put_contents($cfgPath, json_encode($cfg, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));',
      '$accounts = "/var/www/nav/data/webdav_accounts.json";',
      'if (file_exists($accounts)) unlink($accounts);',
    ].join(' ')
  );

  await loginAsDevAdmin(page);
  await page.goto('/admin/files.php');

  page.once('dialog', (dialog) => dialog.accept(baseDir));
  await page.getByRole('button', { name: '新建目录' }).click();
  await page.locator('#fm-path').fill(baseDir);
  await page.locator('#fm-path').press('Enter');

  const shareDialogAnswers = [`share_user_${ts}`, 'SharePass@test2026'];
  let shareDialogIndex = 0;
  page.on('dialog', async (dialog) => {
    if (dialog.type() === 'prompt') {
      const answer = shareDialogAnswers[shareDialogIndex] || '';
      shareDialogIndex += 1;
      await dialog.accept(answer);
      return;
    }
    if (dialog.type() === 'confirm') {
      await dialog.dismiss();
      return;
    }
    await dialog.dismiss();
  });
  await page.getByRole('button', { name: '创建 WebDAV 共享' }).click();

  await expect(page.locator('#fm-webdav-shares')).toContainText(`share_user_${ts}`);
  await expect(page.locator('#fm-webdav-shares')).toContainText('当前目录就是共享根目录');

  await page.locator('#fm-path').fill('/');
  await page.locator('#fm-path').press('Enter');
  await expect(page.locator('#fm-webdav-shares')).toContainText(`share_user_${ts}`);
  await expect(page.locator('#fm-webdav-shares')).toContainText('当前目录下包含共享子目录');

  await expect
    .poll(() => {
      const result = runDockerPhpInline(
        [
          '$path = "/var/www/nav/data/webdav_accounts.json";',
          'echo file_exists($path) ? file_get_contents($path) : "";',
        ].join(' ')
      );
      expect(result.code).toBe(0);
      return result.stdout;
    })
    .toContain(`share_user_${ts}`);

  const accountCheck = runDockerPhpInline(
    [
      '$path = "/var/www/nav/data/webdav_accounts.json";',
      '$data = file_exists($path) ? (json_decode((string)file_get_contents($path), true) ?: []) : [];',
      '$accounts = $data["accounts"] ?? [];',
      'foreach ($accounts as $item) {',
      '  if (($item["username"] ?? "") === "share_user_' + ts + '") {',
      '    echo json_encode($item, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);',
      '    exit;',
      '  }',
      '}',
    ].join(' ')
  );
  expect(accountCheck.code).toBe(0);
  expect(accountCheck.stdout).toContain(baseDir);
  page.removeAllListeners('dialog');
});
