import path from 'path';
import { test, expect } from '../../helpers/fixtures';
import { attachClientErrorTracking, loginAsDevAdmin } from '../../helpers/auth';
import { runDockerCommand, runDockerPhpInline } from '../../helpers/cli';

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
  let lastError = '';
  for (let attempt = 1; attempt <= 3; attempt++) {
    const result = runDockerPhpInline(
      [
        'require "/var/www/nav/admin/shared/host_agent_lib.php";',
        '$result = host_agent_install();',
        'echo json_encode($result, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);',
      ].join(' ')
    );
    if (result.code === 0) {
      try {
        const payload = JSON.parse(result.stdout);
        if (payload.ok === true) {
          // 安装成功后短暂等待，让容器状态稳定
          await new Promise(r => setTimeout(r, 1000));
          return;
        }
        lastError = JSON.stringify(payload);
      } catch {
        lastError = 'JSON parse error: stdout=' + result.stdout + ', stderr=' + result.stderr;
      }
    } else {
      lastError = 'exit code ' + result.code + ': ' + result.output;
    }
    if (attempt < 3) {
      await new Promise(r => setTimeout(r, 2000));
    }
  }
  throw new Error('ensureInstalledHostAgent failed after 3 attempts: ' + lastError);
}

test.beforeEach(async () => {
  await cleanupHostAgent();
});

test.afterEach(async () => {
  await cleanupHostAgent();
});

test('hosts page filters remote hosts via frontend search and group filter', async ({ page }) => {
  test.setTimeout(120000);
  await ensureInstalledHostAgent();
  await page.waitForTimeout(3000);
  const tracker = await attachClientErrorTracking(page);
  const ts = Date.now();
  const hostA = `filter-alpha-${ts}`;
  const hostB = `filter-beta-${ts}`;
  const hostIdA = 'h_' + 'a'.repeat(16);
  const hostIdB = 'h_' + 'b'.repeat(16);
  const now = new Date().toISOString().replace('T', ' ').slice(0, 19);

  // 通过容器内 PHP CLI 直接写入 ssh_hosts.json，避免 Docker Desktop bind mount 同步问题
  const hostsPayload = JSON.stringify({
    version: 1,
    hosts: [
      {
        id: hostIdA,
        name: hostA,
        hostname: '192.168.1.10',
        port: 22,
        username: 'root',
        auth_type: 'password',
        key_id: '',
        password_enc: '',
        group_name: 'group-a',
        tags: ['test'],
        favorite: false,
        notes: '',
        created_at: now,
        updated_at: now,
      },
      {
        id: hostIdB,
        name: hostB,
        hostname: '192.168.1.11',
        port: 22,
        username: 'root',
        auth_type: 'password',
        key_id: '',
        password_enc: '',
        group_name: 'group-b',
        tags: ['prod'],
        favorite: true,
        notes: '',
        created_at: now,
        updated_at: now,
      },
    ],
  });

  const writeResult = runDockerPhpInline(
    `file_put_contents("/var/www/nav/data/ssh_hosts.json", ${JSON.stringify(hostsPayload)}, LOCK_EX);`
  );
  expect(writeResult.code).toBe(0);

  await loginAsDevAdmin(page);
  await page.goto('/admin/hosts.php#remote');

  // 等待页面至少渲染出一个 remote-host-row
  await expect(page.locator('tr.remote-host-row').first()).toBeVisible();

  // search by name
  await page.locator('input#remote-host-search').fill(hostA);
  await expect(page.locator(`tr.remote-host-row:has-text("${hostA}")`)).toBeVisible();
  await expect(page.locator(`tr.remote-host-row:has-text("${hostB}")`)).toBeHidden();

  // clear search
  await page.locator('input#remote-host-search').fill('');
  await page.evaluate(() => { (window as any).filterRemoteHosts && (window as any).filterRemoteHosts(); });
  await expect(page.locator(`tr.remote-host-row:has-text("${hostA}")`)).toBeVisible();
  await expect(page.locator(`tr.remote-host-row:has-text("${hostB}")`)).toBeVisible();

  // group filter
  await page.locator('select#remote-host-group-filter').selectOption('group-b');
  await page.evaluate(() => { (window as any).filterRemoteHosts && (window as any).filterRemoteHosts(); });
  await expect(page.locator(`tr.remote-host-row:has-text("${hostB}")`)).toBeVisible();
  await expect(page.locator(`tr.remote-host-row:has-text("${hostA}")`)).toBeHidden();

  // favorite-only filter
  await page.locator('select#remote-host-group-filter').selectOption('');
  await page.evaluate(() => { (window as any).filterRemoteHosts && (window as any).filterRemoteHosts(); });
  await page.locator('input#remote-host-favorite-only').check({ force: true });
  await page.evaluate(() => { (window as any).filterRemoteHosts && (window as any).filterRemoteHosts(); });
  await expect(page.locator(`tr.remote-host-row:has-text("${hostB}")`)).toBeVisible();
  await expect(page.locator(`tr.remote-host-row:has-text("${hostA}")`)).toBeHidden();

  // uncheck favorite-only
  await page.locator('input#remote-host-favorite-only').uncheck();
  await page.evaluate(() => { (window as any).filterRemoteHosts && (window as any).filterRemoteHosts(); });
  await expect(page.locator(`tr.remote-host-row:has-text("${hostA}")`)).toBeVisible();
  await expect(page.locator(`tr.remote-host-row:has-text("${hostB}")`)).toBeVisible();

  await tracker.assertNoClientErrors();
});
