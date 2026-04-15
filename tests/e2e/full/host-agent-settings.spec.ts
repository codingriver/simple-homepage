import fs from 'fs/promises';
import path from 'path';
import { expect, test } from '@playwright/test';
import { attachClientErrorTracking, loginAsDevAdmin } from '../../helpers/auth';
import { runDockerCommand, runDockerPhpInline } from '../../helpers/cli';

const hostAgentStatePath = path.resolve(__dirname, '../../../data/host_agent.json');
const hostAgentContainer = process.env.APP_CONTAINER ? `${process.env.APP_CONTAINER}-host-agent` : 'simple-homepage-host-agent';

async function cleanupHostAgent() {
  runDockerCommand(['rm', '-f', hostAgentContainer]);
  await fs.rm(hostAgentStatePath, { force: true }).catch(() => undefined);
}

test.beforeEach(async () => {
  await cleanupHostAgent();
});

test.afterEach(async () => {
  await cleanupHostAgent();
});

test('host-agent library reports clear hint when docker.sock is unavailable', async () => {
  const result = runDockerPhpInline(
    [
      'putenv("HOST_AGENT_DOCKER_SOCKET=/tmp/host-agent-missing.sock");',
      'require "/var/www/nav/admin/shared/host_agent_lib.php";',
      'echo json_encode(host_agent_status_summary(), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);',
    ].join(' ')
  );
  expect(result.code).toBe(0);
  const payload = JSON.parse(result.stdout);
  expect(payload.docker_socket_mounted).toBe(false);
  expect(payload.message).toContain('未挂载');
  expect(payload.docker_mount_hint).toContain('docker compose');
});

test('settings page can install host-agent in simulate mode and shows remove-mount reminder', async ({ page }) => {
  test.setTimeout(120000);
  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
    ],
  });

  await loginAsDevAdmin(page);
  await page.goto('/admin/settings.php#host-agent');

  await expect(page.locator('#host-agent-mode')).toContainText(/simulate|host/);
  await expect.poll(async () => !(await page.locator('#host-agent-install-btn').isDisabled()), { timeout: 20000 }).toBe(true);
  await expect(page.locator('#host-agent-socket-note')).toContainText('安装完成并确认功能正常后');
  const csrfToken = await page.locator('#host-agent-install-form input[name="_csrf"]').inputValue();
  const installResponse = await page.request.post('http://127.0.0.1:58080/admin/settings.php', {
    form: {
      _csrf: csrfToken,
      action: 'host_agent_install',
    },
  });
  expect(installResponse.status()).toBe(200);
  await page.goto('/admin/settings.php#host-agent');
  await expect(page.locator('#host-agent-status-text')).toContainText('已运行并健康', { timeout: 20000 });
  await expect(page.locator('#host-agent-banner')).toContainText('host-agent 已运行并通过健康检查');

  const inspect = runDockerCommand(['inspect', '-f', '{{.State.Running}}', hostAgentContainer]);
  expect(inspect.code).toBe(0);
  expect(inspect.stdout.trim()).toBe('true');

  await tracker.assertNoClientErrors();
});
