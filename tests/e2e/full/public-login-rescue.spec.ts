import fs from 'fs/promises';
import path from 'path';
import { test, expect } from '../../helpers/fixtures';
import { runDockerCommand } from '../../helpers/cli';

const usersPath = path.resolve(__dirname, '../../../data/users.json');
const installedPath = path.resolve(__dirname, '../../../data/.installed');
const devModeFlagPath = path.resolve(__dirname, '../../../data/.nav_dev_mode');

test('login page shows rescue guidance when installed instance has empty local users data', async () => {
  const originalUsers = await fs.readFile(usersPath, 'utf8');
  const hadInstalledFlag = await fs
    .access(installedPath)
    .then(() => true)
    .catch(() => false);
  const originalInstalled = hadInstalledFlag ? await fs.readFile(installedPath, 'utf8') : '';
  const hadDevModeFlag = await fs
    .access(devModeFlagPath)
    .then(() => true)
    .catch(() => false);
  const originalDevModeFlag = hadDevModeFlag ? await fs.readFile(devModeFlagPath, 'utf8') : '';

  try {
    await fs.writeFile(usersPath, '{}\n', 'utf8');
    await fs.writeFile(installedPath, originalInstalled || 'playwright-installed\n', 'utf8');
    await fs.unlink(devModeFlagPath).catch(() => {});

    const encodedCode = Buffer.from(`
$_SERVER['HTTP_HOST'] = '127.0.0.1:58080';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/login.php';
$_SERVER['SCRIPT_FILENAME'] = '/var/www/nav/public/login.php';
chdir('/var/www/nav/public');
include 'login.php';
        `, 'utf8').toString('base64');
    const result = runDockerCommand([
      'exec',
      '-e',
      'NAV_DEV_MODE=0',
      '-e',
      `NAV_PHP_INLINE=${encodedCode}`,
      'simple-homepage',
      'php',
      '-r',
      'eval(base64_decode(getenv("NAV_PHP_INLINE")));',
    ]);
    expect(result.code, result.output).toBe(0);

    expect(result.stdout).toContain('账户数据异常，无法登录');
    expect(result.stdout).toContain('manage_users.php add admin 新密码');
    expect(result.stdout).toContain('manage_users.php setup');
  } finally {
    await fs.writeFile(usersPath, originalUsers, 'utf8');
    if (hadInstalledFlag) {
      await fs.writeFile(installedPath, originalInstalled, 'utf8');
    } else {
      await fs.unlink(installedPath).catch(() => {});
    }
    if (hadDevModeFlag) {
      await fs.writeFile(devModeFlagPath, originalDevModeFlag, 'utf8');
    }
  }
});
