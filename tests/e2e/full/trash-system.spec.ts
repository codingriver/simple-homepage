import { test, expect } from '../../helpers/fixtures';
import { attachClientErrorTracking, loginAsDevAdmin } from '../../helpers/auth';
import { runDockerPhpInline } from '../../helpers/cli';

const containerTrashDir = '/var/www/nav/data/trash';
const simulateRoot = '/var/www/nav/data/host-agent-sim-root';

async function cleanupTrashAndTestFiles(testPathPrefix: string) {
  // Clean up any leftover test files in simulate root
  runDockerPhpInline(
    [
      '$prefix = $argv[1];',
      '$root = "/var/www/nav/data/host-agent-sim-root";',
      'foreach (glob($root . $prefix . "*") as $f) {',
      '  if (is_dir($f)) { exec("rm -rf " . escapeshellarg($f)); }',
      '  else { unlink($f); }',
      '}',
    ].join(' '),
    [testPathPrefix]
  );
  // Clean up trash entries that match our test prefix
  runDockerPhpInline(
    [
      '$trashDir = "/var/www/nav/data/trash";',
      'if (!is_dir($trashDir)) exit(0);',
      'foreach (glob($trashDir . "/*") as $entry) {',
      '  $meta = $entry . "/meta.json";',
      '  if (!is_file($meta)) continue;',
      '  $data = json_decode(file_get_contents($meta), true);',
      '  if (!empty($data["original_path"]) && strpos($data["original_path"], "/trash-test-") !== false) {',
      '    exec("rm -rf " . escapeshellarg($entry));',
      '  }',
      '}',
    ].join(' ')
  );
}

test('trash move list restore and permanent delete workflow', async ({ page }) => {
  test.setTimeout(120000);
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
    ],
  });

  const ts = Date.now();
  const dirPath = `/trash-test-${ts}`;
  const fileName = `trash-file-${ts}.txt`;
  const filePath = `${dirPath}/${fileName}`;

  await cleanupTrashAndTestFiles('/trash-test-');

  // Seed test file in simulate root via host-agent fs write
  const seedResult = runDockerPhpInline(
    [
      '$dir = "/var/www/nav/data/host-agent-sim-root' + dirPath + '";',
      'if (!is_dir($dir)) mkdir($dir, 0777, true);',
      'file_put_contents($dir . "/' + fileName + '", "trash-content-' + ts + '");',
    ].join(' ')
  );
  expect(seedResult.code).toBe(0);

  await loginAsDevAdmin(page);
  await page.goto('/admin/files.php');
  await expect(page.locator('body')).toContainText('文件系统');

  // Navigate to the test directory
  await page.locator('#fm-path').fill(dirPath);
  await page.locator('#fm-path').press('Enter');
  await expect(page.locator('#fm-table tbody')).toContainText(fileName);

  // Delete the file (should go to trash)
  page.once('dialog', (dialog) => dialog.accept());
  await page.locator('#fm-table tbody tr', { hasText: fileName }).locator('button[data-delete]').click();

  // Wait for file to disappear from directory listing
  await expect(page.locator('#fm-table tbody')).not.toContainText(fileName);

  // Verify file no longer exists in original location
  await expect
    .poll(() => {
      const result = runDockerPhpInline(
        ['$p = "/var/www/nav/data/host-agent-sim-root' + filePath + '";', 'echo file_exists($p) ? "1" : "0";'].join(' ')
      );
      expect(result.code).toBe(0);
      return result.stdout.trim();
    })
    .toBe('0');

  // Open trash panel
  await page.getByRole('button', { name: /回收站/ }).click();
  await expect(page.locator('#fm-trash-panel')).toBeVisible();

  // Wait for trash table to show our deleted file
  await expect(page.locator('#fm-trash-table tbody')).toContainText(filePath);

  // Restore the file
  page.once('dialog', (dialog) => dialog.accept());
  await page.locator('#fm-trash-table tbody tr', { hasText: filePath }).locator('button', { hasText: '恢复' }).click();

  // Close trash panel before continuing
  await page.locator('#fm-trash-panel').getByRole('button', { name: '关闭' }).click();
  await expect(page.locator('#fm-trash-panel')).not.toBeVisible();

  // Wait for file to reappear in directory listing
  await page.locator('#fm-path').fill(dirPath);
  await page.locator('#fm-path').press('Enter');
  await expect(page.locator('#fm-table tbody')).toContainText(fileName);

  // Verify file exists again in original location
  await expect
    .poll(() => {
      const result = runDockerPhpInline(
        ['$p = "/var/www/nav/data/host-agent-sim-root' + filePath + '";', 'echo file_exists($p) ? "1" : "0";'].join(' ')
      );
      expect(result.code).toBe(0);
      return result.stdout.trim();
    })
    .toBe('1');

  // Delete another file to test permanent delete
  const file2Name = `trash-perm-${ts}.txt`;
  const file2Path = `${dirPath}/${file2Name}`;
  const seed2 = runDockerPhpInline(
    [
      '$dir = "/var/www/nav/data/host-agent-sim-root' + dirPath + '";',
      'file_put_contents($dir . "/' + file2Name + '", "perm-content-' + ts + '");',
    ].join(' ')
  );
  expect(seed2.code).toBe(0);

  await page.locator('#fm-path').fill(dirPath);
  await page.locator('#fm-path').press('Enter');
  await expect(page.locator('#fm-table tbody')).toContainText(file2Name);

  page.once('dialog', (dialog) => dialog.accept());
  await page.locator('#fm-table tbody tr', { hasText: file2Name }).locator('button[data-delete]').click();
  await expect(page.locator('#fm-table tbody')).not.toContainText(file2Name);

  // Open trash and permanently delete
  await page.getByRole('button', { name: /回收站/ }).click();
  await expect(page.locator('#fm-trash-panel')).toBeVisible();
  await expect(page.locator('#fm-trash-table tbody')).toContainText(file2Path);

  page.once('dialog', (dialog) => dialog.accept());
  await page.locator('#fm-trash-table tbody tr', { hasText: file2Path }).locator('button', { hasText: '永久删除' }).click();

  // Verify trash entry is gone
  await expect(page.locator('#fm-trash-table tbody')).not.toContainText(file2Path);

  // Verify file is completely gone from simulate root
  await expect
    .poll(() => {
      const result = runDockerPhpInline(
        ['$p = "/var/www/nav/data/host-agent-sim-root' + file2Path + '";', 'echo file_exists($p) ? "1" : "0";'].join(' ')
      );
      expect(result.code).toBe(0);
      return result.stdout.trim();
    })
    .toBe('0');

  await cleanupTrashAndTestFiles('/trash-test-');
  await tracker.assertNoClientErrors();
});
