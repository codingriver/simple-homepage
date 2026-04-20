import fs from 'fs/promises';
import path from 'path';
import { expect, test } from '../../helpers/fixtures';
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

function cleanupDockerArtifact(name: string, type: 'container' | 'volume' | 'network') {
  if (type === 'container') runDockerCommand(['rm', '-f', name]);
  if (type === 'volume') runDockerCommand(['volume', 'rm', '-f', name]);
  if (type === 'network') runDockerCommand(['network', 'rm', name]);
}

test.beforeEach(async () => {
  await cleanupHostAgent();
});

test.afterEach(async () => {
  await cleanupHostAgent();
});

test('docker hosts page manages containers and displays images volumes and networks', async ({ page }) => {
  test.setTimeout(180000);
  await ensureInstalledHostAgent();

  const tracker = await attachClientErrorTracking(page, {
    ignoredMessages: [
      /Failed to load resource: the server responded with a status of 401 \(Unauthorized\)/,
      /Failed to load resource: the server responded with a status of 400 \(Bad Request\)/,
    ],
  });

  const suffix = Date.now();
  const containerName = `docker-hosts-spec-${suffix}`;
  const volumeName = `docker-hosts-vol-${suffix}`;
  const networkName = `docker-hosts-net-${suffix}`;

  cleanupDockerArtifact(containerName, 'container');
  cleanupDockerArtifact(volumeName, 'volume');
  cleanupDockerArtifact(networkName, 'network');

  const volumeCreate = runDockerCommand(['volume', 'create', volumeName]);
  expect(volumeCreate.code).toBe(0);

  const networkCreate = runDockerCommand(['network', 'create', networkName]);
  expect(networkCreate.code).toBe(0);

  const runResult = runDockerCommand([
    'run',
    '-d',
    '--name',
    containerName,
    '--network',
    networkName,
    '-v',
    `${volumeName}:/data`,
    'busybox:1.36',
    'sh',
    '-lc',
    'echo docker-hosts-spec-log && sleep 300',
  ]);
  expect(runResult.code).toBe(0);

  try {
    await loginAsDevAdmin(page);
    await page.goto('/admin/docker_hosts.php');

    await expect(page.locator('body')).toContainText('Docker 宿主管理');
    // 等待加载指示器消失（host-agent 刚启动时 Docker 信息可能需要额外时间）
    await expect.poll(() => page.locator('#docker-summary').textContent().then(t => !t?.includes('加载中')), { timeout: 30000 }).toBe(true);
    await expect(page.locator('#docker-summary')).toContainText('Docker 版本');

    await page.locator('#docker-container-keyword').fill(containerName);
    await expect(page.locator('#docker-containers-tbody')).toContainText(containerName, { timeout: 30000 });
    const row = page.locator(`#docker-containers-tbody tr[data-container-id]`).filter({ hasText: containerName }).first();
    await expect(row).toContainText('busybox:1.36');

    await page.evaluate((id) => {
      const fn = (window as Window & { dockerShowLogs?: (value: string) => Promise<void> }).dockerShowLogs;
      if (typeof fn !== 'function') throw new Error('dockerShowLogs not found');
      return fn(id);
    }, runResult.stdout.trim());
    await expect(page.locator('#docker-modal')).toBeVisible();
    await expect(page.locator('#docker-modal-body')).toContainText('docker-hosts-spec-log');
    await page.evaluate(() => {
      const fn = (window as Window & { dockerCloseModal?: () => void }).dockerCloseModal;
      if (typeof fn !== 'function') throw new Error('dockerCloseModal not found');
      fn();
    });

    await page.evaluate((id) => {
      const fn = (window as Window & { dockerShowStats?: (value: string) => Promise<void> }).dockerShowStats;
      if (typeof fn !== 'function') throw new Error('dockerShowStats not found');
      return fn(id);
    }, runResult.stdout.trim());
    await expect(page.locator('#docker-modal-body')).toContainText('cpu_percent');
    await page.evaluate(() => {
      const fn = (window as Window & { dockerCloseModal?: () => void }).dockerCloseModal;
      if (typeof fn !== 'function') throw new Error('dockerCloseModal not found');
      fn();
    });

    await page.evaluate((id) => {
      const fn = (window as Window & { dockerContainerAction?: (value: string, action: string) => Promise<void> }).dockerContainerAction;
      if (typeof fn !== 'function') throw new Error('dockerContainerAction not found');
      return fn(id, 'stop');
    }, runResult.stdout.trim());
    await expect
      .poll(() => runDockerCommand(['inspect', '-f', '{{.State.Status}}', containerName]).stdout.trim())
      .toBe('exited');

    await page.locator('#docker-container-keyword').fill(containerName);
    await expect(page.locator('#docker-containers-tbody')).toContainText(containerName, { timeout: 30000 });
    await page.evaluate((id) => {
      const fn = (window as Window & { dockerContainerAction?: (value: string, action: string) => Promise<void> }).dockerContainerAction;
      if (typeof fn !== 'function') throw new Error('dockerContainerAction not found');
      return fn(id, 'start');
    }, runResult.stdout.trim());
    await expect
      .poll(() => runDockerCommand(['inspect', '-f', '{{.State.Status}}', containerName]).stdout.trim())
      .toBe('running');

    await page.locator('[data-tab="images"]').click();
    await expect(page.locator('#docker-images-tbody')).toContainText('busybox:1.36', { timeout: 30000 });

    await page.locator('[data-tab="volumes"]').click();
    await expect(page.locator('#docker-volumes-tbody')).toContainText(volumeName, { timeout: 30000 });

    await page.locator('[data-tab="networks"]').click();
    await expect(page.locator('#docker-networks-tbody')).toContainText(networkName, { timeout: 30000 });

    await page.locator('[data-tab="containers"]').click();
    await page.locator('#docker-container-keyword').fill(containerName);
    await page.evaluate(() => {
      (window as Window & { confirm?: (message?: string) => boolean }).confirm = () => true;
    });
    await page.evaluate((id) => {
      const fn = (window as Window & { dockerDeleteContainer?: (value: string) => Promise<void> }).dockerDeleteContainer;
      if (typeof fn !== 'function') throw new Error('dockerDeleteContainer not found');
      return fn(id);
    }, runResult.stdout.trim());
    await expect
      .poll(() => runDockerCommand(['inspect', '-f', '{{.State.Status}}', containerName]).code !== 0)
      .toBe(true);
    await expect(page.locator('#docker-containers-tbody')).not.toContainText(containerName, { timeout: 30000 });

    await tracker.assertNoClientErrors();
  } finally {
    cleanupDockerArtifact(containerName, 'container');
    cleanupDockerArtifact(volumeName, 'volume');
    cleanupDockerArtifact(networkName, 'network');
  }
});
