import path from 'path';
import { test, expect } from '../../helpers/fixtures';
import { loginAsDevAdmin } from '../../helpers/auth';
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

test.beforeEach(async () => {
  await cleanupHostAgent();
});

test.afterEach(async () => {
  await cleanupHostAgent();
});

test('debug fetch after create', async ({ page }) => {
  test.setTimeout(120000);
  await ensureInstalledHostAgent();
  await page.waitForTimeout(3000);
  await loginAsDevAdmin(page);
  await page.goto('/admin/hosts.php');

  const csrf = await page.locator('input[name="_csrf"]').first().inputValue();
  const hostA = `filter-alpha-${Date.now()}`;

  const saveA = await page.request.post('http://127.0.0.1:58080/admin/hosts.php', {
    form: {
      action: 'save_remote_host', _csrf: csrf, host_name: hostA,
      hostname: '192.168.1.10', port: '22', username: 'root',
      auth_type: 'password', password: 'TestPass2026',
      group_name: 'group-a', tags: 'test', favorite: '0', notes: '',
    },
    maxRedirects: 0,
  });
  expect(saveA.status()).toBe(302);

  // 使用 page.request.get 获取页面
  const res = await page.request.get('http://127.0.0.1:58080/admin/hosts.php');
  const text = await res.text();
  console.log('REQUEST GET HAS remote-host-search:', text.includes('id="remote-host-search"'));
  console.log('REQUEST GET HAS remote-host-row:', text.includes('remote-host-row'));

  // 使用 page.goto 获取页面
  await page.goto('/admin/hosts.php#remote');
  const html = await page.content();
  console.log('GOTO HAS remote-host-search:', html.includes('id="remote-host-search"'));
  console.log('GOTO HAS remote-host-row:', html.includes('remote-host-row'));
});
