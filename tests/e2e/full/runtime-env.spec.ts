import { test, expect } from '../../helpers/fixtures';
import { attachClientErrorTracking, clickAdminNav, loginAsDevAdmin } from '../../helpers/auth';

test('runtime environment page shows node manager and detects status', async ({ page }) => {
  const clientErrors = await attachClientErrorTracking(page);
  await loginAsDevAdmin(page);

  await page.route('**/admin/runtime_env_ajax.php?action=current_job', async route => {
    await route.fulfill({ json: { ok: true, data: { job: null } } });
  });

  await clickAdminNav(page, /运行环境/);
  await expect(page.getByRole('heading', { name: 'Node.js' })).toBeVisible();
  await expect(page.getByText('npm registry')).toBeVisible();
  await expect(page.getByText('已安装版本')).toBeVisible();
  await expect(page.locator('#runtime-job-card')).toBeHidden();
  await expect(page.locator('#runtime-job-bar')).toHaveAttribute('style', /width:0%/);

  await page.getByRole('button', { name: '检测环境' }).click();
  await expect(page.locator('#node-status')).toContainText('平台');
  await expect(page.locator('#node-status')).toContainText(/node|未安装/);
  await page.waitForTimeout(300);

  await page.evaluate(() => {
    (window as any).renderJob({
      id: 'e2e-job',
      status: 'running',
      phase: '下载 Node.js',
      percent: 37,
      message: '正在下载：12.0 MB / 32.0 MB',
      downloaded: 12 * 1024 * 1024,
      download_total: 32 * 1024 * 1024,
      log: '[12:00:00] 正在下载 Node.js\n',
    });
  });
  await expect(page.locator('#runtime-job-card')).toBeVisible();
  await expect(page.locator('#runtime-job-title')).toContainText('下载 Node.js');
  await expect(page.locator('#runtime-job-percent')).toHaveText('37%');
  await expect(page.locator('#runtime-job-meta')).toContainText('download=');
  await expect(page.locator('#runtime-log')).toContainText('正在下载 Node.js');

  await clientErrors.assertNoClientErrors();
});

test('runtime environment page restores an active install after returning', async ({ page }) => {
  await loginAsDevAdmin(page);
  await page.route('**/admin/runtime_env_ajax.php?**', async route => {
    const url = new URL(route.request().url());
    const action = url.searchParams.get('action');
    if (action === 'current_job' || action === 'job_status') {
      await route.fulfill({
        json: {
          ok: true,
          data: {
            job: {
              id: 'restored-job',
              status: 'running',
              phase: '下载 Node.js',
              percent: 46,
              message: '正在下载：14.0 MB / 30.0 MB',
              downloaded: 14 * 1024 * 1024,
              download_total: 30 * 1024 * 1024,
              log: '[12:00:00] download continues\n',
            },
          },
        },
      });
      return;
    }
    await route.continue();
  });

  await clickAdminNav(page, /运行环境/);
  await expect(page.locator('#runtime-job-card')).toBeVisible();
  await expect(page.locator('#runtime-job-percent')).toHaveText('46%');
  await expect(page.locator('#runtime-job-title')).toContainText('下载 Node.js');

  await page.goto('/admin/index.php');
  await clickAdminNav(page, /运行环境/);
  await expect(page.locator('#runtime-job-card')).toBeVisible();
  await expect(page.locator('#runtime-job-percent')).toHaveText('46%');
});
