import { expect, test } from '../../helpers/fixtures';
import { loginAsDevAdmin } from '../../helpers/auth';
import { runDockerCommand, runDockerPhpInline } from '../../helpers/cli';

async function cleanupHostAgent() {
  runDockerCommand(['rm', '-f', 'simple-homepage-host-agent']);
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
  console.log('install result:', result.stdout, 'code:', result.code);
  expect(result.code).toBe(0);
  expect(JSON.parse(result.stdout).ok).toBe(true);
}

test('debug system_overview', async ({ page }) => {
  await cleanupHostAgent();
  await ensureInstalledHostAgent();
  await loginAsDevAdmin(page);
  
  const overviewRes = await page.request.get(
    'http://127.0.0.1:58080/admin/host_api.php?action=system_overview',
    { headers: { 'X-Requested-With': 'XMLHttpRequest' } }
  );
  console.log('status:', overviewRes.status());
  const text = await overviewRes.text();
  console.log('response:', text);
});
