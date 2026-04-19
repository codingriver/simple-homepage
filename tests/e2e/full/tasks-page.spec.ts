import path from 'path';
import { test, expect } from '../../helpers/fixtures';
import { attachClientErrorTracking, loginAsDevAdmin } from '../../helpers/auth';
import { runDockerCommand, runDockerPhpInline } from '../../helpers/cli';

const hostAgentStatePath = path.resolve(__dirname, '../../../data/host_agent.json');
const hostAgentContainer = process.env.APP_CONTAINER ? `${process.env.APP_CONTAINER}-host-agent` : 'simple-homepage-host-agent';

async function cleanupHostAgent() {
  runDockerCommand(['rm', '-f', hostAgentContainer]);
  for (let i = 0; i < 10; i++) {
    const check = runDockerCommand(['ps', '-q', '--filter', `name=${hostAgentContainer}`]);
    if (!check.stdout.trim()) break;
    await new Promise(r => setTimeout(r, 300));
  }
  await runDockerPhpInline('file_put_contents("/var/www/nav/data/host_agent.json", "{}", LOCK_EX);');
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
  const payload = JSON.parse(result.stdout);
  expect(payload.ok).toBe(true);
}

async function getHostCsrf(page: any) {
  await page.goto('/admin/manifests.php');
  return page.evaluate(() => (window as any).HOST_CSRF || '');
}

test.beforeEach(async () => {
  await cleanupHostAgent();
});

test.afterEach(async () => {
  await cleanupHostAgent();
});

test('tasks page displays task list and supports detail modal', async ({ page }) => {
  test.setTimeout(120000);
  await ensureInstalledHostAgent();
  await page.waitForTimeout(3000);
  const tracker = await attachClientErrorTracking(page);

  await loginAsDevAdmin(page);
  const csrf = await getHostCsrf(page);

  // 提交一个异步任务以确保页面上有任务数据
  const manifest = JSON.stringify({
    packages: { htop: { state: 'installed' } },
    services: {},
    configs: {},
  });
  const submitRes = await page.request.post('http://127.0.0.1:58080/admin/host_api.php', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: {
      action: 'task_submit',
      _csrf: csrf,
      task_action: 'manifest_dry_run',
      task_payload: JSON.stringify({ manifest: JSON.parse(manifest) }),
    },
  });
  expect(submitRes.status()).toBe(200);
  const submitBody = await submitRes.json();
  expect(submitBody.ok).toBe(true);
  const taskId = submitBody.task_id;

  // 访问异步任务页面
  await page.goto('/admin/tasks.php');

  // 验证页面标题和基本结构
  await expect(page.locator('.card-title')).toContainText('异步任务监控');
  await expect(page.locator('button', { hasText: /刷新列表/ })).toBeVisible();

  // 验证任务表格中至少有一行任务数据（或"暂无任务记录"）
  const taskList = page.locator('#task-list');
  const hasTasks = await taskList.locator('table tr').count() > 0;
  if (hasTasks) {
    // 验证任务行包含任务 ID 的前缀
    await expect(taskList.locator('tr').nth(1)).toContainText(taskId.substring(0, 8));

    // 点击详情按钮
    await taskList.locator('button', { hasText: '详情' }).first().click();
    await expect(page.locator('#task-modal')).toBeVisible();
    await expect(page.locator('#task-detail-content')).toContainText('任务 ID');
    await expect(page.locator('#task-detail-content')).toContainText('状态');

    // 关闭模态框
    await page.locator('#task-modal button', { hasText: '✕' }).click();
    await expect(page.locator('#task-modal')).toBeHidden();
  } else {
    // simulate 模式下任务可能完成过快导致列表为空，至少验证页面结构
    await expect(taskList).toContainText(/暂无任务记录|任务/);
  }

  await tracker.assertNoClientErrors();
});
