import { expect, test } from '../../helpers/fixtures';
import { attachClientErrorTracking, loginAsDevAdmin } from '../../helpers/auth';
import { runDockerCommand, runDockerPhpInline } from '../../helpers/cli';

function cleanupDockerArtifact(name: string, type: 'container' | 'volume' | 'network') {
  if (type === 'container') runDockerCommand(['rm', '-f', name]);
  if (type === 'volume') runDockerCommand(['volume', 'rm', '-f', name]);
  if (type === 'network') runDockerCommand(['network', 'rm', name]);
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
}

test('docker api read actions return expected payloads', async ({ page }) => {
  test.setTimeout(120000);
  await ensureInstalledHostAgent();
  const tracker = await attachClientErrorTracking(page);

  const suffix = Date.now();
  const containerName = `docker-api-actions-${suffix}`;
  const volumeName = `docker-api-actions-vol-${suffix}`;
  const networkName = `docker-api-actions-net-${suffix}`;

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
    'echo docker-api-log-line && sleep 300',
  ]);
  expect(runResult.code).toBe(0);
  const containerId = runResult.stdout.trim();

  try {
    await loginAsDevAdmin(page);

    // container_logs
    const logsRes = await page.request.get(
      `http://127.0.0.1:58080/admin/docker_api.php?action=container_logs&id=${containerId}&tail=10`,
      { headers: { 'X-Requested-With': 'XMLHttpRequest' } }
    );
    expect(logsRes.status()).toBe(200);
    const logsBody = await logsRes.json();
    expect(logsBody.ok).toBe(true);
    expect(Array.isArray(logsBody.lines)).toBe(true);
    expect(logsBody.lines.join('\n')).toContain('docker-api-log-line');

    // container_inspect
    const inspectRes = await page.request.get(
      `http://127.0.0.1:58080/admin/docker_api.php?action=container_inspect&id=${containerId}`,
      { headers: { 'X-Requested-With': 'XMLHttpRequest' } }
    );
    expect(inspectRes.status()).toBe(200);
    const inspectBody = await inspectRes.json();
    expect(inspectBody.ok).toBe(true);
    expect(typeof inspectBody.item).toBe('object');
    expect(inspectBody.item.Name).toContain(containerName);

    // container_stats
    const statsRes = await page.request.get(
      `http://127.0.0.1:58080/admin/docker_api.php?action=container_stats&id=${containerId}`,
      { headers: { 'X-Requested-With': 'XMLHttpRequest' } }
    );
    expect(statsRes.status()).toBe(200);
    const statsBody = await statsRes.json();
    expect(statsBody.ok).toBe(true);
    expect(typeof statsBody.item).toBe('object');

    // images
    const imagesRes = await page.request.get(
      `http://127.0.0.1:58080/admin/docker_api.php?action=images`,
      { headers: { 'X-Requested-With': 'XMLHttpRequest' } }
    );
    expect(imagesRes.status()).toBe(200);
    const imagesBody = await imagesRes.json();
    expect(imagesBody.ok).toBe(true);
    expect(Array.isArray(imagesBody.items)).toBe(true);
    const busyboxImage = imagesBody.items.find((img: any) =>
      JSON.stringify(img).includes('busybox')
    );
    expect(busyboxImage).toBeTruthy();

    // volumes
    const volumesRes = await page.request.get(
      `http://127.0.0.1:58080/admin/docker_api.php?action=volumes`,
      { headers: { 'X-Requested-With': 'XMLHttpRequest' } }
    );
    expect(volumesRes.status()).toBe(200);
    const volumesBody = await volumesRes.json();
    expect(volumesBody.ok).toBe(true);
    expect(Array.isArray(volumesBody.items)).toBe(true);
    const foundVolume = volumesBody.items.find((v: any) =>
      (v.Name || v.name || '').includes(volumeName)
    );
    expect(foundVolume).toBeTruthy();

    // networks
    const networksRes = await page.request.get(
      `http://127.0.0.1:58080/admin/docker_api.php?action=networks`,
      { headers: { 'X-Requested-With': 'XMLHttpRequest' } }
    );
    expect(networksRes.status()).toBe(200);
    const networksBody = await networksRes.json();
    expect(networksBody.ok).toBe(true);
    expect(Array.isArray(networksBody.items)).toBe(true);
    const foundNetwork = networksBody.items.find((n: any) =>
      (n.Name || n.name || '').includes(networkName)
    );
    expect(foundNetwork).toBeTruthy();

    await tracker.assertNoClientErrors();
  } finally {
    cleanupDockerArtifact(containerName, 'container');
    cleanupDockerArtifact(volumeName, 'volume');
    cleanupDockerArtifact(networkName, 'network');
  }
});
